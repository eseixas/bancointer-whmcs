<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/helper.php';

final class SettlementTest extends TestCase
{
    public function testCanonicalTransIdPrefersEndToEndId(): void
    {
        self::assertSame(
            "E2E123",
            BancoInterHelper::canonicalTransId("E2E123", "txid-1", "codigo-1", "nosso-1")
        );
    }

    public function testCanonicalTransIdFallsBackToTxidThenCodigo(): void
    {
        self::assertSame("txid-1", BancoInterHelper::canonicalTransId("", "txid-1", "codigo-1", null));
        self::assertSame("codigo-1", BancoInterHelper::canonicalTransId(null, "  ", "codigo-1", "nosso"));
        self::assertSame("nosso", BancoInterHelper::canonicalTransId(null, null, "", "nosso"));
        self::assertSame("", BancoInterHelper::canonicalTransId(null, "", null, null));
    }

    public function testRefundIdIsDeterministicAndBounded(): void
    {
        $first = BancoInterHelper::refundId(42, "E2E999");
        $second = BancoInterHelper::refundId(42, "E2E999");
        $other = BancoInterHelper::refundId(42, "E2E000");

        self::assertSame($first, $second);
        self::assertNotSame($first, $other);
        self::assertLessThanOrEqual(35, strlen($first));
        self::assertStringStartsWith("whmcs42", $first);
    }

    public function testAmountFromAcceptsBrazilianAndDotDecimals(): void
    {
        self::assertSame(105.5, BancoInterHelper::amountFrom(["valorPago" => "105,50"]));
        self::assertSame(105.5, BancoInterHelper::amountFrom(["valorTotalRecebimento" => "105.50"]));
        self::assertSame(100.0, BancoInterHelper::amountFrom(["pix" => ["valor" => "100"]]));
        self::assertNull(BancoInterHelper::amountFrom(["foo" => "bar"]));
    }

    public function testAmountFromReadsCobrancaV3WrappedReceipt(): void
    {
        $payload = [
            "cobranca" => [
                "situacao" => "RECEBIDO",
                "valorTotalRecebido" => "20.00",
            ],
            "boleto" => ["nossoNumero" => "N"],
            "pix" => ["txid" => "T"],
        ];

        self::assertSame(20.0, BancoInterHelper::amountFrom($payload));
        self::assertSame(20.0, BancoInterHelper::parsePaymentBreakdown($payload)["total"]);
        self::assertSame(34.25, BancoInterHelper::amountFrom([
            "situacao" => "RECEBIDO",
            "valorTotalRecebido" => "34.25",
        ]));
    }

    public function testExtractWebhookEventsFlattensPixBatch(): void
    {
        $payload = [
            "pix" => [
                ["txid" => "a", "endToEndId" => "e2e-a"],
                ["txid" => "b", "endToEndId" => "e2e-b"],
            ],
        ];

        $events = BancoInterHelper::extractWebhookEvents($payload);
        self::assertCount(2, $events);
        self::assertSame("a", $events[0]["txid"]);
        self::assertSame("e2e-b", $events[1]["endToEndId"]);
    }

    public function testIsLocallyPaid(): void
    {
        self::assertTrue(BancoInterHelper::isLocallyPaid((object) ["status" => "RECEBIDO"]));
        self::assertTrue(BancoInterHelper::isLocallyPaid((object) ["status" => "PENDING", "paid_at" => "2026-01-01 00:00:00"]));
        self::assertTrue(BancoInterHelper::isLocallyPaid((object) ["status" => "PENDING", "paid_amount" => 10.5]));
        self::assertFalse(BancoInterHelper::isLocallyPaid((object) ["status" => "A_RECEBER"]));
        self::assertFalse(BancoInterHelper::isLocallyPaid(null));
    }

    public function testCompletedRefundStatus(): void
    {
        self::assertTrue(BancoInterHelper::isCompletedRefundStatus("DEVOLVIDO"));
        self::assertFalse(BancoInterHelper::isCompletedRefundStatus("EM_PROCESSAMENTO"));
        self::assertFalse(BancoInterHelper::isCompletedRefundStatus(null));
    }
}
