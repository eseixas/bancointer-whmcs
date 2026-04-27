<?php
/**
 * Banco Inter webhook receiver.
 *
 * The bank pushes an array of settlement events to this endpoint. Each
 * event carries the codigoSolicitacao (or nossoNumero) used to look up
 * the local transaction. Paid events trigger WHMCS addInvoicePayment().
 *
 * Responses must be 2xx within a few seconds or Banco Inter retries,
 * so we acknowledge fast and defer nothing — but all processing fits
 * well inside the 10s budget with a single DB round-trip per event.
 */

use WHMCS\Database\Capsule;

require_once __DIR__ . "/../../../init.php";
require_once __DIR__ . "/../../../includes/gatewayfunctions.php";
require_once __DIR__ . "/../../../includes/invoicefunctions.php";
require_once __DIR__ . "/../seixastec_bancointer.php";

$gatewayParams = getGatewayVariables("seixastec_bancointer");
if (!$gatewayParams["type"]) {
    http_response_code(503);
    exit("Banco Inter gateway disabled.");
}

if (strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "")) !== "POST") {
    http_response_code(405);
    exit("Method Not Allowed");
}

$configuredSecret = trim((string) ($gatewayParams["webhook_secret"] ?? ""));
if ($configuredSecret === "") {
    // Fail closed: sem secret configurado não podemos autenticar o Inter.
    BancoInterHelper::log("webhook.rejected_missing_secret", [
        "remote_ip" => $_SERVER["REMOTE_ADDR"] ?? null,
    ], "webhook_secret não configurado");
    http_response_code(503);
    exit("Webhook secret not configured.");
}
$providedSecret = (string) ($_GET["token"] ?? "");
if (!hash_equals($configuredSecret, $providedSecret)) {
    http_response_code(403);
    exit("Forbidden");
}

$rawBody = file_get_contents("php://input");
BancoInterHelper::log("webhook.received", [
    "remote_ip" => $_SERVER["REMOTE_ADDR"] ?? null,
    "headers" => array_filter([
        "x-forwarded-for" => $_SERVER["HTTP_X_FORWARDED_FOR"] ?? null,
        "user-agent" => $_SERVER["HTTP_USER_AGENT"] ?? null,
    ]),
    "body" => json_decode((string) $rawBody, true),
], ["received" => strlen((string) $rawBody)]);

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    http_response_code(400);
    exit("Invalid JSON payload.");
}

// Banco Inter sometimes sends a single event, sometimes a batch array.
$events = isset($payload[0]) && is_array($payload[0]) ? $payload : [$payload];
$api = seixastec_bancointer_buildApi($gatewayParams);

$processed = 0;
foreach ($events as $event) {
    try {
        if (seixastec_bancointer_handleEvent($event, $gatewayParams, $api)) {
            $processed++;
        }
    } catch (Throwable $e) {
        BancoInterHelper::log("webhook.error", $event, $e->getMessage());
    }
}

http_response_code(200);
header("Content-Type: application/json");
echo json_encode(["processed" => $processed, "received" => count($events)]);

/**
 * Route a single webhook event. Returns true when a payment was registered.
 */
function seixastec_bancointer_handleEvent(array $event, array $gatewayParams, BancoInterAPI $api): bool
{
    $codigo = $event["codigoSolicitacao"] ?? ($event["codigoTransacao"] ?? null);
    $nossoNumero = $event["nossoNumero"] ?? ($event["boleto"]["nossoNumero"] ?? null);
    $txid = $event["txid"] ?? ($event["pix"]["txid"] ?? null);
    $e2e = $event["endToEndId"] ?? ($event["pix"]["endToEndId"] ?? null);

    $situacao = strtoupper((string) ($event["situacao"] ?? $event["status"] ?? ""));
    $tx = null;
    if ($codigo) {
        $tx = BancoInterHelper::findByCodigoSolicitacao($codigo);
    }
    if (!$tx && $nossoNumero) {
        $tx = BancoInterHelper::findByTxid($nossoNumero);
    }
    if (!$tx && $txid) {
        $tx = BancoInterHelper::findByTxid($txid);
    }

    if (!$tx) {
        BancoInterHelper::log("webhook.unmatched", $event, "no local transaction found");
        return false;
    }

    // Keep the latest remote state persisted even for non-terminal updates.
    BancoInterHelper::saveTransaction([
        "invoice_id" => (int) $tx->invoice_id,
        "codigo_solicitacao" => $tx->codigo_solicitacao,
        "status" => $situacao ?: $tx->status,
        "txid" => $txid ?: $tx->txid,
        "e2e_id" => $e2e ?: $tx->e2e_id,
        "raw_response" => json_encode($event, JSON_UNESCAPED_UNICODE),
    ]);

    if (!BancoInterHelper::isPaidStatus($situacao)) {
        return false;
    }

    $invoiceId = (int) $tx->invoice_id;
    $remote = null;
    if (!empty($tx->codigo_solicitacao)) {
        $remote = $api->getCollection($tx->codigo_solicitacao);
    }

    $remoteStatus = strtoupper((string) ($remote["situacao"] ?? ""));
    if ($remote && !BancoInterHelper::isPaidStatus($remoteStatus)) {
        BancoInterHelper::log("webhook.rejected_status", $event, [
            "local_status" => $situacao,
            "remote_status" => $remoteStatus,
        ]);
        return false;
    }

    $amount = (float) ($remote["valorTotalRecebimento"]
        ?? $remote["valorPago"]
        ?? $remote["pix"]["valor"]
        ?? $event["valorTotalRecebimento"]
        ?? $event["valorPago"]
        ?? $event["pix"]["valor"]
        ?? $tx->amount);

    if ($amount <= 0) {
        BancoInterHelper::log("webhook.rejected_amount", $event, "paid amount missing or zero");
        return false;
    }

    if ($tx->amount !== null && abs($amount - (float) $tx->amount) > 0.01) {
        BancoInterHelper::log("webhook.rejected_amount_mismatch", $event, [
            "expected" => (float) $tx->amount,
            "received" => $amount,
        ]);
        return false;
    }

    $fee = (float) ($event["valorTarifa"] ?? 0);
    $transId = $e2e
        ?: ($txid
        ?: ($remote["pix"]["endToEndId"] ?? ($remote["pix"]["txid"] ?? ($codigo ?: $nossoNumero))));

    $existing = checkCbTransID($transId);
    if ($existing) {
        // Duplicate delivery — acknowledge without re-crediting the invoice.
        return false;
    }

    $checkInvoice = checkCbInvoiceID($invoiceId, $gatewayParams["name"]);
    if (!$checkInvoice) {
        return false;
    }

    addInvoicePayment($invoiceId, $transId, $amount, $fee, $gatewayParams["paymentmethod"]);
    logTransaction($gatewayParams["name"], $event, "Successful");
    BancoInterHelper::markPaid((int) $tx->id, $amount, $event["dataHoraPagamento"] ?? null);

    return true;
}
