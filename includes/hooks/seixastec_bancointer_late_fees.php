<?php
/**
 * Suppress WHMCS-native late fees for Banco Inter invoices.
 *
 * Multa/juros are configured on the boleto (Banco Inter API) and reconciled
 * into the WHMCS ledger only when the webhook confirms payment.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once __DIR__ . "/../../modules/gateways/seixastec_bancointer.php";

add_hook("AddInvoiceLateFee", 1, function (array $vars) {
    $invoiceId = (int) ($vars["invoiceid"] ?? 0);
    if ($invoiceId <= 0 || !BancoInterHelper::isBancoInterInvoice($invoiceId)) {
        return;
    }

    try {
        BancoInterHelper::removeWhmcsLateFeeEntries($invoiceId);
    } catch (Throwable $e) {
        BancoInterHelper::log("late_fee.suppress_failed", ["invoice_id" => $invoiceId], $e->getMessage());
    }
});

add_hook("DailyCronJob", 2, function () {
    try {
        $cleaned = BancoInterHelper::cleanupWhmcsLateFeesBatch(50);
        if ($cleaned > 0) {
            BancoInterHelper::log("late_fee.batch_cleanup", [], ["cleaned" => $cleaned]);
        }
    } catch (Throwable $e) {
        BancoInterHelper::log("late_fee.batch_cleanup_failed", [], $e->getMessage());
    }
});