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

require_once __DIR__ . "/../../modules/gateways/seixastec_bancointer/seixastec_bancointer.php";

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
    $attachAlways = !empty($params["attach_pdf_always"]) && $params["attach_pdf_always"] !== "off";

    // Gera a cobrança quando:
    //   - método é Banco Inter e auto_generate está on (semântica original); OU
    //   - attach_pdf_always está on (precisa ter cobrança para anexar PDF nos e-mails).
    if (!(($isBancoInter && $autoGenerate) || $attachAlways)) {
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
 * DailyCronJob — cancels stale cobranças after the configured "dias_baixa"
 * window so the bank does not keep expired boletos active. Runs once per day
 * during the WHMCS cron pass.
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
        ->where("due_date", "<=", $cutoff)
        ->limit(50)
        ->get();

    if ($candidates->isEmpty()) {
        return;
    }

    $api = seixastec_bancointer_buildApi($params);

    foreach ($candidates as $tx) {
        try {
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
