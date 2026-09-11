<?php
/**
 * InvoiceCreation hook — auto-mint a Banco Inter cobrança whenever the
 * configured gateway matches and "auto_generate" is on.
 *
 * The WHMCS duedate drives dataVencimento (req #4 of the plan). The
 * baixa automática, multa, juros and desconto rules all resolve from
 * the gateway settings via BancoInterHelper::buildChargeOptions().
 */

use WHMCS\Database\Capsule;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . "/../../modules/gateways/seixastec_bancointer.php";

add_hook("InvoiceCreation", 1, function (array $vars) {
    $invoiceId = (int) ($vars["invoiceid"] ?? 0);
    if ($invoiceId <= 0) {
        return;
    }

    $invoice = Capsule::table("tblinvoices")->where("id", $invoiceId)->first();
    if (!$invoice) {
        return;
    }

    $params = seixastec_bancointer_loadParams();
    if (!$params) {
        return;
    }

    $isBancoInter = strtolower((string) $invoice->paymentmethod) === "seixastec_bancointer";
    $autoGenerate = !empty($params["auto_generate"]) && $params["auto_generate"] !== "off";
    if (!$isBancoInter || !$autoGenerate) {
        return;
    }

    if ((float) $invoice->total <= 0) {
        return;
    }

    try {
        seixastec_bancointer_generateForInvoice(
            (int) $invoice->id,
            (int) $invoice->userid,
            (float) $invoice->total,
            (string) $invoice->duedate,
            $params
        );
    } catch (Throwable $e) {
        BancoInterHelper::log("hook.auto_generate", ["invoiceid" => $invoiceId], $e->getMessage());
        logActivity("Banco Inter: falha ao gerar cobrança para fatura #{$invoiceId} — " . $e->getMessage());
    }
});

/**
 * DailyCronJob — reconcile missed webhooks, then cancel cobrancas past
 * the "dias_baixa" window. Overdue / oldest due dates are polled first.
 */
add_hook("DailyCronJob", 1, function () {
    $params = seixastec_bancointer_loadParams();
    if (!$params) {
        return;
    }

    $diasBaixa = max(1, (int) ($params["dias_baixa"] ?? 15));
    $cutoff = date("Y-m-d", strtotime("-{$diasBaixa} days"));

    $candidates = Capsule::table(BancoInterHelper::TABLE)
        ->whereNotIn("status", array_merge(BancoInterHelper::TERMINAL_PAID_STATUSES, BancoInterHelper::TERMINAL_CANCELLED_STATUSES))
        ->whereNotNull("codigo_solicitacao")
        ->orderBy("due_date", "asc")
        ->limit(50)
        ->get();

    if ($candidates->isEmpty()) {
        return;
    }

    $api = seixastec_bancointer_buildApi($params);

    foreach ($candidates as $tx) {
        try {
            $live = $api->getCollection($tx->codigo_solicitacao);
            $liveStatus = strtoupper((string) ($live["situacao"] ?? ""));

            if (BancoInterHelper::isPaidStatus($liveStatus)) {
                $result = seixastec_bancointer_settleFromRemote(
                    $tx,
                    $params,
                    $api,
                    BancoInterHelper::GATEWAY_MODULE,
                    [],
                    $live
                );
                BancoInterHelper::log("hook.cron_settle", [
                    "invoice_id" => (int) $tx->invoice_id,
                    "codigo_solicitacao" => $tx->codigo_solicitacao,
                    "result" => $result,
                ], $liveStatus);
                continue;
            }

            if ($liveStatus !== "") {
                BancoInterHelper::saveTransaction([
                    "invoice_id" => (int) $tx->invoice_id,
                    "codigo_solicitacao" => $tx->codigo_solicitacao,
                    "status" => $liveStatus,
                ]);
            }

            if (in_array($liveStatus, BancoInterHelper::TERMINAL_CANCELLED_STATUSES, true)) {
                continue;
            }

            $dueDate = (string) ($tx->due_date ?? "");
            if ($dueDate === "" || $dueDate > $cutoff) {
                continue;
            }

            $api->cancelCollection($tx->codigo_solicitacao, "APEDIDODOCLIENTE");
            BancoInterHelper::saveTransaction([
                "invoice_id" => (int) $tx->invoice_id,
                "codigo_solicitacao" => $tx->codigo_solicitacao,
                "status" => "CANCELLED",
            ]);
        } catch (Throwable $e) {
            BancoInterHelper::log("hook.auto_cancel", ["codigo" => $tx->codigo_solicitacao], $e->getMessage());
        }
    }
});
