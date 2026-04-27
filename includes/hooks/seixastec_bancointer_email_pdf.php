<?php
/**
 * EmailPreSend hook — attach the Banco Inter boleto PDF to "Invoice Created"
 * (and reminder) emails. The PDF is fetched fresh from the API in-memory and
 * pushed into the outbound message via $abortsend = false + attachments.
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

    if (strtolower((string) $invoice->paymentmethod) !== "seixastec_bancointer") {
        return;
    }

    $tx = BancoInterHelper::findByInvoice($relid);
    if (!$tx || empty($tx->codigo_solicitacao)) {
        return;
    }

    $params = seixastec_bancointer_loadParams();
    if (!$params) {
        return;
    }

    try {
        $pdfBytes = seixastec_bancointer_buildApi($params)->getCollectionPdf($tx->codigo_solicitacao);

        // WHMCS 9+ expects an array of ["data" => ..., "filename" => ...] or filesystem paths.
        // In-memory attachment via a temp file ensures compatibility with older mailers too.
        $tempPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . "seixastec_bancointer_fatura_{$relid}_" . bin2hex(random_bytes(4)) . ".pdf";
        $written = @file_put_contents($tempPath, $pdfBytes, LOCK_EX);
        if ($written === false || !is_readable($tempPath)) {
            @unlink($tempPath);
            throw new RuntimeException("Falha ao gravar PDF temporário para anexo.");
        }

        return [
            "attachments" => [
                [
                    "filename" => "Boleto_Fatura_{$relid}.pdf",
                    "filepath" => $tempPath,
                ],
            ],
        ];
    } catch (Throwable $e) {
        BancoInterHelper::log("hook.email_pdf", ["invoiceid" => $relid, "message" => $messageName], $e->getMessage());
    }
});

/**
 * Companion AfterEmailSent hook — clean the temporary PDF file once the
 * message has been delivered. Keeps sys_get_temp_dir tidy even on long-running
 * PHP workers.
 */
add_hook("EmailSent", 1, function (array $vars) {
    $pattern = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . "seixastec_bancointer_fatura_" . (int) ($vars["relid"] ?? 0) . "_*.pdf";
    foreach (glob($pattern) ?: [] as $file) {
        @unlink($file);
    }
});
