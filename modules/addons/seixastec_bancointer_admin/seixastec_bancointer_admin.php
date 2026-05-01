<?php
/**
 * WHMCS Addon wrapper for the Banco Inter gateway admin panel.
 *
 * Payment gateways do not get a persistent WHMCS admin menu entry by default.
 * This addon exposes the existing gateway panel under the native Addons menu.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

require_once ROOTDIR . "/modules/gateways/seixastec_bancointer.php";

function seixastec_bancointer_admin_config(): array
{
    return [
        "name" => "Banco Inter Boleto e PIX",
        "description" => "Atalho administrativo para configuracoes, webhook, extrato, metricas e logs do gateway Banco Inter.",
        "version" => "1.4.1",
        "author" => "Seixas Tecnologia",
        "language" => "portuguese-br",
        "fields" => [],
    ];
}

function seixastec_bancointer_admin_activate(): array
{
    try {
        BancoInterHelper::ensureSchema();
        return [
            "status" => "success",
            "description" => "Atalho Banco Inter ativado. Acesse pelo menu Addons.",
        ];
    } catch (Throwable $e) {
        return [
            "status" => "error",
            "description" => "Falha ao ativar atalho Banco Inter: " . $e->getMessage(),
        ];
    }
}

function seixastec_bancointer_admin_deactivate(): array
{
    return [
        "status" => "success",
        "description" => "Atalho Banco Inter desativado. Nenhuma configuracao do gateway foi removida.",
    ];
}

function seixastec_bancointer_admin_output(array $vars): void
{
    $systemUrl = BancoInterHelper::systemUrl();
    $panelUrl = rtrim($systemUrl, "/") . "/modules/gateways/seixastec_bancointer/admin.php?view=webhook";
    $safePanelUrl = htmlspecialchars($panelUrl, ENT_QUOTES);

    echo <<<HTML
<div style="margin:0 0 16px;padding:14px 16px;background:#fff5d8;border:1px solid #f0d58a;border-radius:4px;color:#8a6a00;line-height:1.6">
    <strong>Banco Inter Boleto e PIX</strong><br>
    Use este atalho para gerenciar configuracoes, webhook, extrato, metricas e logs do gateway.
</div>
<p>
    <a class="btn btn-primary" href="{$safePanelUrl}" target="_blank" rel="noopener">Abrir Painel em Nova Aba</a>
</p>
<iframe src="{$safePanelUrl}" style="width:100%;min-height:1800px;border:1px solid #dcdcdc;border-radius:4px;background:#fff"></iframe>
HTML;
}
