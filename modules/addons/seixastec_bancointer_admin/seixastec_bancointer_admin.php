<?php
/**
 * WHMCS Addon Module wrapper for the Seixas Tecnologia Banco Inter admin panel.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

function seixastec_bancointer_admin_config(): array
{
    return [
        "name" => "Seixastec Bancointer Admin",
        "description" => "Painel administrativo do gateway Banco Inter Boleto e PIX.",
        "version" => "1.2.0",
        "author" => "Seixas Tecnologia",
    ];
}

function seixastec_bancointer_admin_activate(): array
{
    return ["status" => "success", "description" => "Addon ativado com sucesso."];
}

function seixastec_bancointer_admin_deactivate(): array
{
    return ["status" => "success", "description" => "Addon desativado com sucesso."];
}

function seixastec_bancointer_admin_output(array $vars): void
{
    $systemUrl = rtrim((string) Capsule::table("tblconfiguration")->where("setting", "SystemURL")->value("value"), "/");
    $panelUrl = $systemUrl . "/modules/gateways/seixastec_bancointer/admin.php?view=license";

    echo '<div style="padding:16px">';
    echo '<h2 style="margin-top:0">Banco Inter Boleto e PIX</h2>';
    echo '<p style="margin-bottom:16px">Painel administrativo centralizado da integração Seixas Tecnologia.</p>';
    echo '<p><a href="' . htmlspecialchars($panelUrl, ENT_QUOTES) . '" target="_blank" class="btn btn-primary">Abrir em nova aba</a></p>';
    echo '<iframe src="' . htmlspecialchars($panelUrl, ENT_QUOTES) . '" style="width:100%;min-height:1100px;border:1px solid #dcdcdc;border-radius:4px;background:#fff"></iframe>';
    echo '</div>';
}
