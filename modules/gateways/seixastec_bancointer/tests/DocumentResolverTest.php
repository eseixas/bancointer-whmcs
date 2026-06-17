<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/helper.php';

final class DocumentResolverTest extends TestCase
{
    /**
     * @dataProvider customFieldIdProvider
     */
    public function testNormalizeCustomFieldId(?string $raw, ?int $expected): void
    {
        self::assertSame($expected, BancoInterHelper::normalizeCustomFieldId($raw));
    }

    public static function customFieldIdProvider(): array
    {
        return [
            'empty' => [null, null],
            'blank' => ['', null],
            'zero' => ['0', null],
            'numeric id' => ['1', 1],
            'legacy val=label' => ['1=[1] CPF/CNPJ', 1],
            'legacy bracket label' => ['[1] CPF/CNPJ', 1],
            'legacy label only' => ['1=CPF/CNPJ', 1],
            'garbage' => ['abc', null],
        ];
    }

    public function testOnlyDigitsStripsCnpjFormatting(): void
    {
        self::assertSame('20521321000149', BancoInterHelper::onlyDigits('20.521.321/0001-49'));
    }

    public function testClassifyDocumentCnpj(): void
    {
        self::assertSame('JURIDICA', BancoInterHelper::classifyDocument('20521321000149'));
    }

    public function testClassifyDocumentCpf(): void
    {
        self::assertSame('FISICA', BancoInterHelper::classifyDocument('12345678901'));
    }
}