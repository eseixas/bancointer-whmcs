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
$events = seixastec_bancointer_extractEvents($payload);
$api = seixastec_bancointer_buildApi($gatewayParams);

$processed = 0;
foreach ($events as $event) {
    try {
        if (seixastec_bancointer_handleEvent($event, $gatewayParams, $api, $gatewayModule)) {
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
function seixastec_bancointer_handleEvent(array $event, array $gatewayParams, BancoInterAPI $api, string $gatewayModule): bool
{
    $codigo = seixastec_bancointer_firstValue($event, [
        "codigoSolicitacao",
        "codigoTransacao",
        "cobranca.codigoSolicitacao",
    ]);
    $nossoNumero = seixastec_bancointer_firstValue($event, [
        "nossoNumero",
        "boleto.nossoNumero",
        "cobranca.boleto.nossoNumero",
    ]);
    $txid = seixastec_bancointer_firstValue($event, [
        "txid",
        "txId",
        "tx_id",
        "pix.txid",
        "pix.txId",
        "pix.tx_id",
    ]);
    $e2e = seixastec_bancointer_firstValue($event, [
        "endToEndId",
        "endToEndID",
        "e2eId",
        "e2e_id",
        "pix.endToEndId",
        "pix.endToEndID",
        "pix.e2eId",
        "pix.e2e_id",
    ]);
    $seuNumero = seixastec_bancointer_firstValue($event, [
        "seuNumero",
        "seu_numero",
        "cobranca.seuNumero",
    ]);

    $situacao = strtoupper((string) seixastec_bancointer_firstValue($event, [
        "situacao",
        "status",
        "cobranca.situacao",
        "pix.status",
    ]));

    if (!$codigo && !$nossoNumero && !$txid && !$e2e && !$seuNumero) {
        BancoInterHelper::log("webhook.missing_identifier", $event, "payload sem codigoSolicitacao, nossoNumero, txid, endToEndId ou seuNumero");
        return false;
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

    $invoiceId = (int) $tx->invoice_id;
    $remote = null;
    if (!empty($tx->codigo_solicitacao)) {
        try {
            $remote = $api->getCollection($tx->codigo_solicitacao);
        } catch (Throwable $e) {
            BancoInterHelper::log("webhook.api_verify_failed", $event, $e->getMessage());
        }
    }

    $eventAmount = seixastec_bancointer_amountFrom($event);
    $remoteStatus = $remote !== null ? strtoupper((string) ($remote["situacao"] ?? "")) : "";
    $eventSaysPaid = BancoInterHelper::isPaidStatus($situacao)
        || seixastec_bancointer_hasPaidTimestamp($event)
        || (($e2e || $txid) && $eventAmount !== null && $eventAmount > 0);
    $remoteSaysPaid = BancoInterHelper::isPaidStatus($remoteStatus);

    if ($remote !== null) {
        if ($remoteStatus !== "" && in_array($remoteStatus, BancoInterHelper::TERMINAL_CANCELLED_STATUSES, true)) {
            BancoInterHelper::log("webhook.rejected_status", $event, [
                "local_status" => $situacao,
                "remote_status" => $remoteStatus,
            ]);
            return false;
        }
    }

    if (!$eventSaysPaid && !$remoteSaysPaid) {
        BancoInterHelper::log("webhook.status_not_paid", $event, [
            "local_status" => $situacao,
            "remote_status" => $remoteStatus,
            "has_paid_timestamp" => seixastec_bancointer_hasPaidTimestamp($event),
            "event_amount" => $eventAmount,
        ]);
        return false;
    }

    $remoteAmount = is_array($remote) ? seixastec_bancointer_amountFrom($remote) : null;
    $amount = (float) ($remoteAmount ?? $eventAmount ?? $tx->amount);

    if ($amount <= 0) {
        BancoInterHelper::log("webhook.rejected_amount", $event, "paid amount missing or zero");
        return false;
    }

    // Aceita qualquer valor >= nominal (multa/juros aumentam o recebimento em pagamentos atrasados).
    // Rejeita apenas se o valor recebido for menor que o nominal (pagamento parcial).
    if ($tx->amount !== null && $amount < (float) $tx->amount - 0.01) {
        BancoInterHelper::log("webhook.rejected_amount_mismatch", $event, [
            "expected" => (float) $tx->amount,
            "received" => $amount,
        ]);
        return false;
    }

    $fee = (float) (seixastec_bancointer_firstValue($event, ["valorTarifa", "tarifa", "pix.valorTarifa"]) ?? 0);
    $transId = $e2e
        ?: ($txid
        ?: ($remote["pix"]["endToEndId"] ?? ($remote["pix"]["txid"] ?? ($codigo ?: ($nossoNumero ?: (string) $tx->codigo_solicitacao)))));

    $existing = checkCbTransID($transId);
    if ($existing) {
        // Duplicate delivery — acknowledge without re-crediting the invoice.
        BancoInterHelper::log("webhook.duplicate_transaction", $event, ["trans_id" => $transId]);
        return false;
    }

    $checkInvoice = checkCbInvoiceID($invoiceId, $gatewayParams["name"]);
    if (!$checkInvoice) {
        BancoInterHelper::log("webhook.rejected_invoice", $event, ["invoice_id" => $invoiceId]);
        return false;
    }

    addInvoicePayment($invoiceId, $transId, $amount, $fee, $gatewayModule);
    logTransaction($gatewayParams["name"], $event, "Successful");
    BancoInterHelper::markPaid((int) $tx->id, $amount, seixastec_bancointer_paidAt($event));

    return true;
}

function seixastec_bancointer_extractEvents(array $payload): array
{
    if (isset($payload[0]) && is_array($payload[0])) {
        return $payload;
    }

    foreach (["pix", "eventos", "cobrancas", "items"] as $key) {
        if (!empty($payload[$key]) && is_array($payload[$key])) {
            $items = isset($payload[$key][0]) ? $payload[$key] : [$payload[$key]];
            return array_map(function ($item) use ($payload, $key) {
                return is_array($item) ? array_merge($payload, [$key => $item], $item) : $payload;
            }, $items);
        }
    }

    return [$payload];
}

function seixastec_bancointer_firstValue(array $payload, array $paths)
{
    foreach ($paths as $path) {
        $value = $payload;
        foreach (explode(".", $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                $value = null;
                break;
            }
            $value = $value[$segment];
        }
        if ($value !== null && $value !== "") {
            return is_scalar($value) ? $value : null;
        }
    }

    return null;
}

function seixastec_bancointer_amountFrom(array $payload): ?float
{
    $value = seixastec_bancointer_firstValue($payload, [
        "valorTotalRecebimento",
        "valorPago",
        "valorRecebido",
        "valor",
        "amount",
        "pix.valor",
        "pix.valorPago",
        "pix.amount",
    ]);

    if ($value === null) {
        return null;
    }

    $normalized = trim((string) $value);
    $normalized = preg_replace("/\s+/", "", $normalized);
    if (strpos($normalized, ",") !== false) {
        $normalized = str_replace(".", "", $normalized);
        $normalized = str_replace(",", ".", $normalized);
    } else {
        $normalized = str_replace(",", "", $normalized);
    }

    return is_numeric($normalized) ? (float) $normalized : null;
}

function seixastec_bancointer_hasPaidTimestamp(array $payload): bool
{
    return seixastec_bancointer_paidAt($payload) !== null;
}

function seixastec_bancointer_paidAt(array $payload): ?string
{
    $paidAt = seixastec_bancointer_firstValue($payload, [
        "dataHoraPagamento",
        "dataPagamento",
        "paidAt",
        "pix.dataHoraPagamento",
        "pix.dataPagamento",
        "pix.paidAt",
    ]);

    return $paidAt !== null ? (string) $paidAt : null;
}
