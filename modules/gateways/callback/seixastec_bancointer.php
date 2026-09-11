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

$gatewayModule = "seixastec_bancointer";
$gatewayParams = seixastec_bancointer_loadParams();
if (!$gatewayParams) {
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
    BancoInterHelper::log("webhook.rejected_invalid_token", [
        "remote_ip" => $_SERVER["REMOTE_ADDR"] ?? null,
        "has_token" => $providedSecret !== "",
    ], "token mismatch");
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

// Banco Inter may send a single cobrança event, a batch, or Pix entries under "pix".
$events = BancoInterHelper::extractWebhookEvents($payload);
$api = seixastec_bancointer_buildApi($gatewayParams);

$processed = 0;
$needsRetry = false;
foreach ($events as $event) {
    try {
        $result = seixastec_bancointer_handleEvent($event, $gatewayParams, $api, $gatewayModule);
        if ($result === BancoInterHelper::SETTLE_APPLIED) {
            $processed++;
        } elseif ($result === BancoInterHelper::SETTLE_RETRY) {
            $needsRetry = true;
        }
    } catch (Throwable $e) {
        BancoInterHelper::log("webhook.error", $event, $e->getMessage());
        $needsRetry = true;
    }
}

http_response_code($needsRetry ? 503 : 200);
header("Content-Type: application/json");
echo json_encode(["processed" => $processed, "received" => count($events), "retry" => $needsRetry]);

/**
 * Route a single webhook event.
 *
 * @return int BancoInterHelper::SETTLE_APPLIED|SETTLE_IGNORED|SETTLE_RETRY
 */
function seixastec_bancointer_handleEvent(array $event, array $gatewayParams, BancoInterAPI $api, string $gatewayModule): int
{
    $codigo = BancoInterHelper::firstValue($event, [
        "codigoSolicitacao",
        "codigoTransacao",
        "cobranca.codigoSolicitacao",
    ]);
    $nossoNumero = BancoInterHelper::firstValue($event, [
        "nossoNumero",
        "boleto.nossoNumero",
        "cobranca.boleto.nossoNumero",
    ]);
    $txid = BancoInterHelper::firstValue($event, [
        "txid",
        "txId",
        "tx_id",
        "pix.txid",
        "pix.txId",
        "pix.tx_id",
    ]);
    $e2e = BancoInterHelper::firstValue($event, [
        "endToEndId",
        "endToEndID",
        "e2eId",
        "e2e_id",
        "pix.endToEndId",
        "pix.endToEndID",
        "pix.e2eId",
        "pix.e2e_id",
    ]);
    $seuNumero = BancoInterHelper::firstValue($event, [
        "seuNumero",
        "seu_numero",
        "cobranca.seuNumero",
    ]);

    $situacao = strtoupper((string) BancoInterHelper::firstValue($event, [
        "situacao",
        "status",
        "cobranca.situacao",
        "pix.status",
    ]));

    if (!$codigo && !$nossoNumero && !$txid && !$e2e && !$seuNumero) {
        BancoInterHelper::log("webhook.missing_identifier", $event, "payload sem codigoSolicitacao, nossoNumero, txid, endToEndId ou seuNumero");
        return BancoInterHelper::SETTLE_IGNORED;
    }

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
    if (!$tx && $e2e) {
        $tx = BancoInterHelper::findByTxid($e2e);
    }
    if (!$tx && $seuNumero && ctype_digit((string) $seuNumero)) {
        $tx = BancoInterHelper::findByInvoice((int) $seuNumero);
    }

    if (!$tx) {
        BancoInterHelper::log("webhook.unmatched", $event, [
            "reason" => "no local transaction found",
            "codigo_solicitacao" => $codigo,
            "nosso_numero" => $nossoNumero,
            "txid" => $txid,
            "e2e_id" => $e2e,
            "seu_numero" => $seuNumero,
        ]);
        return BancoInterHelper::SETTLE_IGNORED;
    }

    if (BancoInterHelper::isLocallyPaid($tx)) {
        BancoInterHelper::log("webhook.already_paid", $event, [
            "invoice_id" => (int) $tx->invoice_id,
            "status" => $tx->status ?? null,
        ]);
        return BancoInterHelper::SETTLE_IGNORED;
    }

    $statusUpdate = $situacao;
    if ($statusUpdate !== "" && BancoInterHelper::isPaidStatus($statusUpdate)) {
        // Do not persist a paid status from the webhook body; settleFromRemote
        // confirms RECEBIDO via GET /cobrancas before crediting the invoice.
        $statusUpdate = (string) ($tx->status ?? "");
    }

    BancoInterHelper::saveTransaction([
        "invoice_id" => (int) $tx->invoice_id,
        "codigo_solicitacao" => $tx->codigo_solicitacao,
        "status" => $statusUpdate !== "" ? $statusUpdate : $tx->status,
        "txid" => $txid ?: $tx->txid,
        "e2e_id" => $e2e ?: $tx->e2e_id,
        "raw_response" => json_encode($event, JSON_UNESCAPED_UNICODE),
    ]);

    $tx = BancoInterHelper::findByInvoice((int) $tx->invoice_id) ?: $tx;

    return seixastec_bancointer_settleFromRemote($tx, $gatewayParams, $api, $gatewayModule, $event);
}
