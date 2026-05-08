<?php
/**
 * EmailPreSend hook — attach the Banco Inter boleto PDF to invoice creation
 * and reminder emails when the invoice payment method is this gateway.
 */

use WHMCS\Database\Capsule;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . "/../../modules/gateways/seixastec_bancointer.php";

add_hook("EmailPreSend", 1, function (array $vars) {
    $messageName = (string) ($vars["messagename"] ?? "");

    static $allowed = [
        "Invoice Created",
        "Invoice Payment Reminder",
        "First Payment Reminder",
        "Second Payment Reminder",
        "Third Payment Reminder",
        "Overdue Invoice Notification",
    ];
    if (!in_array($messageName, $allowed, true)) {
        return;
    }

    $relid = (int) ($vars["relid"] ?? 0);
    if ($relid <= 0) {
        return;
    }

    $invoice = Capsule::table("tblinvoices")->where("id", $relid)->first();
    if (!$invoice) {
        return;
    }

    $params = seixastec_bancointer_loadParams();
    if (!$params) {
        return;
    }

    if (strtolower((string) $invoice->paymentmethod) !== "seixastec_bancointer") {
        return;
    }

    $tx = BancoInterHelper::findByInvoice($relid);

    // If the invoice was created before automatic generation, mint it now.
    if ((!$tx || empty($tx->codigo_solicitacao)) && (float) $invoice->total > 0) {
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
            BancoInterHelper::log("hook.email_pdf.generate", ["invoiceid" => $relid], $e->getMessage());
            return;
        }
    }

    if (!$tx || empty($tx->codigo_solicitacao)) {
        return;
    }

    try {
        $pdfBytes = seixastec_bancointer_buildApi($params)->getCollectionPdf($tx->codigo_solicitacao);

        return [
            "attachments" => [
                [
                    "filename" => "Boleto_Fatura_{$relid}.pdf",
                    "data" => $pdfBytes,
                ],
            ],
        ];
    } catch (Throwable $e) {
        BancoInterHelper::log("hook.email_pdf", ["invoiceid" => $relid, "message" => $messageName], $e->getMessage());
    }
});
