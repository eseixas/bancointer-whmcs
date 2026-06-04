<?php
/**
 * EmailPreSend hook — attach the Banco Inter boleto PDF to invoice-related
 * emails when the invoice payment method is this gateway.
 *
 * Template classification is done by case-insensitive substring matching so
 * that renamed or localised templates (e.g. "Notificação de Fatura Vencida")
 * are handled automatically without needing to enumerate every variant.
 *
 * Attachment rules:
 *   invoice_created — attach; generate cobrança on-the-fly if not yet emitted.
 *   reminder        — attach if cobrança is active (not paid / not cancelled).
 *   overdue         — attach if cobrança is active (not paid / not cancelled).
 *   (anything else) — no attachment.
 *
 * When a cobrança is already PAID or CANCELLED the hook exits early with a
 * structured log entry so operators can diagnose missing attachments via
 * WHMCS → Utilities → Logs → Gateway Log.
 */

use WHMCS\Database\Capsule;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . "/../../modules/gateways/seixastec_bancointer.php";

/**
 * Classify an email template name into one of four buckets:
 *   "invoice_created" | "reminder" | "overdue" | null
 *
 * Matching is case-insensitive and based on substrings so that custom
 * template names that keep the key word still work.
 */
function seixastec_bancointer_classifyEmail(string $messageName): ?string
{
    $lower = strtolower($messageName);

    // Order matters: check "overdue" before generic "invoice" patterns.
    if (
        str_contains($lower, "overdue") ||
        str_contains($lower, "vencid") ||
        str_contains($lower, "past due")
    ) {
        return "overdue";
    }

    if (str_contains($lower, "reminder")) {
        return "reminder";
    }

    if (
        str_contains($lower, "modified") ||
        str_contains($lower, "alterad") ||
        str_contains($lower, "modificad")
    ) {
        return "invoice_modified";
    }

    if (
        (str_contains($lower, "invoice") && str_contains($lower, "creat")) ||
        (str_contains($lower, "fatura") && str_contains($lower, "cri"))
    ) {
        return "invoice_created";
    }

    return null;
}

add_hook("EmailPreSend", 1, function (array $vars) {
    $messageName = (string) ($vars["messagename"] ?? "");
    $classification = seixastec_bancointer_classifyEmail($messageName);

    // Not an email type we care about — exit silently (no log noise).
    if ($classification === null) {
        return;
    }

    $relid = (int) ($vars["relid"] ?? 0);
    if ($relid <= 0) {
        return;
    }

    $invoice = Capsule::table("tblinvoices")->where("id", $relid)->first();
    if (!$invoice) {
        BancoInterHelper::log(
            "hook.email_pdf",
            ["invoiceid" => $relid, "messagename" => $messageName, "classification" => $classification],
            "skipped_no_invoice"
        );
        return;
    }

    if (strtolower((string) $invoice->paymentmethod) !== "seixastec_bancointer") {
        BancoInterHelper::log(
            "hook.email_pdf",
            ["invoiceid" => $relid, "messagename" => $messageName, "classification" => $classification, "paymentmethod" => $invoice->paymentmethod],
            "skipped_other_gateway"
        );
        return;
    }

    $params = seixastec_bancointer_loadParams();
    if (!$params) {
        BancoInterHelper::log(
            "hook.email_pdf",
            ["invoiceid" => $relid, "messagename" => $messageName, "classification" => $classification],
            "skipped_no_params"
        );
        return;
    }

    $tx = BancoInterHelper::findByInvoice($relid);
    $txStatus = $tx ? strtoupper((string) ($tx->status ?? "")) : "NONE";

    // Cobrança already paid — no point attaching a boleto.
    if ($tx && BancoInterHelper::isPaidStatus($txStatus)) {
        BancoInterHelper::log(
            "hook.email_pdf",
            ["invoiceid" => $relid, "messagename" => $messageName, "classification" => $classification, "tx_status" => $txStatus],
            "skipped_paid"
        );
        return;
    }

    // Cobrança cancelled — do not attach a void boleto.
    if ($tx && in_array($txStatus, BancoInterHelper::TERMINAL_CANCELLED_STATUSES, true)) {
        BancoInterHelper::log(
            "hook.email_pdf",
            ["invoiceid" => $relid, "messagename" => $messageName, "classification" => $classification, "tx_status" => $txStatus],
            "skipped_cancelled"
        );
        return;
    }

    // No active cobrança exists yet.
    if (!$tx || empty($tx->codigo_solicitacao)) {
        // Only invoice_created and invoice_modified emails trigger on-the-fly generation.
        // For reminders and overdue we never create a new cobrança mid-email.
        if ($classification !== "invoice_created" && $classification !== "invoice_modified") {
            BancoInterHelper::log(
                "hook.email_pdf",
                ["invoiceid" => $relid, "messagename" => $messageName, "classification" => $classification],
                "skipped_no_active_tx"
            );
            return;
        }

        if ((float) $invoice->total <= 0) {
            return;
        }

        try {
            $row = seixastec_bancointer_generateForInvoice(
                (int) $invoice->id,
                (int) $invoice->userid,
                (float) $invoice->total,
                (string) $invoice->duedate,
                $params
            );
            $tx = (object) $row;
        } catch (Throwable $e) {
            BancoInterHelper::log(
                "hook.email_pdf",
                ["invoiceid" => $relid, "messagename" => $messageName, "classification" => $classification],
                "mint_failed: " . $e->getMessage()
            );
            return;
        }
    }

    if (!$tx || empty($tx->codigo_solicitacao)) {
        return;
    }

    try {
        $pdfBytes = seixastec_bancointer_buildApi($params)->getCollectionPdf($tx->codigo_solicitacao);

        BancoInterHelper::log(
            "hook.email_pdf",
            ["invoiceid" => $relid, "messagename" => $messageName, "classification" => $classification, "tx_status" => $txStatus, "codigo_solicitacao" => $tx->codigo_solicitacao],
            "attached"
        );

        return [
            "attachments" => [
                [
                    "filename" => "Boleto_Fatura_{$relid}.pdf",
                    "data" => $pdfBytes,
                ],
            ],
        ];
    } catch (Throwable $e) {
        BancoInterHelper::log(
            "hook.email_pdf",
            ["invoiceid" => $relid, "messagename" => $messageName, "classification" => $classification, "tx_status" => $txStatus],
            "api_error: " . $e->getMessage()
        );
    }
});
