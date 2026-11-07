<?php

/**
 * Lightweight runner for environments without PHPUnit.
 * php tests/run.php
 */

declare(strict_types=1);

require_once __DIR__ . "/bootstrap.php";
require_once dirname(__DIR__) . "/helper.php";

$failed = 0;

function bi_assert_same($expected, $actual, string $message): void
{
    global $failed;
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL {$message}: expected " . var_export($expected, true)
            . " got " . var_export($actual, true) . PHP_EOL);
        $failed++;
        return;
    }
    echo "ok {$message}" . PHP_EOL;
}

function bi_assert_true(bool $condition, string $message): void
{
    bi_assert_same(true, $condition, $message);
}

bi_assert_same("E2E123", BancoInterHelper::canonicalTransId("E2E123", "txid-1", "codigo-1", "nosso-1"), "canonical prefers e2e");
bi_assert_same("txid-1", BancoInterHelper::canonicalTransId("", "txid-1", "codigo-1", null), "canonical falls back to txid");
bi_assert_same("codigo-1", BancoInterHelper::canonicalTransId(null, "  ", "codigo-1", "nosso"), "canonical falls back to codigo");

$first = BancoInterHelper::refundId(42, "E2E999");
$second = BancoInterHelper::refundId(42, "E2E999");
$other = BancoInterHelper::refundId(42, "E2E000");
bi_assert_same($first, $second, "refund id deterministic");
bi_assert_true($first !== $other, "refund id changes with e2e");
bi_assert_true(strlen($first) <= 35, "refund id max 35 chars");

bi_assert_same(105.5, BancoInterHelper::amountFrom(["valorPago" => "105,50"]), "amount br decimal");
bi_assert_same(105.5, BancoInterHelper::amountFrom(["valorTotalRecebimento" => "105.50"]), "amount dot decimal");
$wrapped = ["cobranca" => ["situacao" => "RECEBIDO", "valorTotalRecebido" => "20.00"], "pix" => ["txid" => "T"]];
bi_assert_same(20.0, BancoInterHelper::amountFrom($wrapped), "amount from cobranca.valorTotalRecebido");
bi_assert_same(20.0, BancoInterHelper::parsePaymentBreakdown($wrapped)["total"], "breakdown total from wrapped receipt");

$events = BancoInterHelper::extractWebhookEvents([
    "pix" => [
        ["txid" => "a"],
        ["txid" => "b"],
    ],
]);
bi_assert_same(2, count($events), "extract pix batch count");
bi_assert_same("a", $events[0]["txid"] ?? null, "extract pix first txid");

// Juros de mora / pagamento a mais (fatura #112: 82,50 + 1,98 de juros, total ficou 82,50)
bi_assert_same(84.48, BancoInterHelper::invoiceTotalFromParts(["0.00", "82.50", "1.98"]), "total from items includes juros");
bi_assert_same(84.48, BancoInterHelper::invoiceTotalFromParts([82.5, 1.98], 0.0, 0.0), "total from items numeric");
bi_assert_same(21.0, BancoInterHelper::invoiceTotalFromParts([20.0], 0.6, 0.4), "total from items includes taxes");
bi_assert_same(1.98, BancoInterHelper::residualOverpayment(84.48, 82.50, 0.0, 0.0), "residual overpayment = juros");
bi_assert_same(0.0, BancoInterHelper::residualOverpayment(84.48, 82.50, 0.0, 1.98), "no residual when juros explained");
bi_assert_same(0.0, BancoInterHelper::residualOverpayment(20.0, 20.0, 0.0, 0.0), "no residual on exact payment");
bi_assert_same(0.0, BancoInterHelper::residualOverpayment(19.0, 20.0, 0.0, 0.0), "underpayment is not residual");
bi_assert_same(0.41, BancoInterHelper::residualOverpayment(20.0, 19.59, 0.0, 0.0), "residual with credit applied (fatura #118)");
bi_assert_true(BancoInterHelper::isLatePayment("2026-11-03T15:20:02", "2026-10-22"), "late payment detected");
bi_assert_true(!BancoInterHelper::isLatePayment("2026-10-22T23:59:00", "2026-10-22"), "payment on due date is not late");
bi_assert_true(!BancoInterHelper::isLatePayment(null, "2026-10-22"), "missing paid date is not late");
bi_assert_true(!BancoInterHelper::isLatePayment("2026-11-03", "0000-00-00"), "missing due date is not late");

bi_assert_same(1, BancoInterHelper::normalizeCustomFieldId("1=[1] CPF/CNPJ"), "legacy custom field id");
bi_assert_same("20521321000149", BancoInterHelper::onlyDigits("20.521.321/0001-49"), "cnpj digits");
bi_assert_true(BancoInterHelper::isLocallyPaid((object) ["status" => "RECEBIDO"]), "locally paid RECEBIDO");
bi_assert_true(!BancoInterHelper::isLocallyPaid((object) ["status" => "A_RECEBER"]), "not paid A_RECEBER");

if ($failed > 0) {
    fwrite(STDERR, "{$failed} assertion(s) failed" . PHP_EOL);
    exit(1);
}

echo "all tests passed" . PHP_EOL;
