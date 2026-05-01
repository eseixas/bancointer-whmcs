<?php
/**
 * WHMCS Payment Gateway — Banco Inter Boleto e PIX.
 *
 * Files co-located under modules/gateways/seixastec_bancointer/:
 *   BancoInterAPI.php — API client (OAuth2 + mTLS, cobrança v3, webhook).
 *   helper.php        — shared DB, logging and formatting utilities.
 *   generate.php      — client-area handler to mint a new cobrança on-demand.
 *   tools.php         — admin-area helper for webhook register/status/delete.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

require_once __DIR__ . "/seixastec_bancointer/helper.php";
require_once __DIR__ . "/seixastec_bancointer/BancoInterAPI.php";

function seixastec_bancointer_MetaData(): array
{
    return [
        "DisplayName" => "Banco Inter Boleto e PIX",
        "APIVersion" => "1.2",
        "DisableLocalCreditCardInput" => true,
        "TokenisedStorage" => false,
    ];
}

function seixastec_bancointer_config(): array
{
    BancoInterHelper::ensureSchema();

    $customFieldOptions = ["" => "— usar Tax ID do cliente —"];
    try {
        $rows = Capsule::table("tblcustomfields")
            ->where("type", "client")
            ->orderBy("fieldname")
            ->get(["id", "fieldname"]);
        foreach ($rows as $row) {
            $customFieldOptions[$row->id] = sprintf("[%d] %s", $row->id, $row->fieldname);
        }
    } catch (Throwable $e) {
        // Ignore reduced-schema environments during bootstrap.
    }

    $systemUrl = BancoInterHelper::systemUrl();
    $adminUrl = $systemUrl ? ($systemUrl . "/modules/gateways/seixastec_bancointer/admin.php?view=config") : "";
    $adminHtml = $adminUrl
        ? sprintf(
            '<div style="line-height:1.8">' .
              '<div style="margin:12px 0 18px;padding:14px 16px;background:#fff5d8;border:1px solid #f0d58a;border-radius:4px;color:#8a6a00">' .
              '<strong>INFO:</strong> o painel abaixo centraliza configurações, webhook, extrato, métricas e logs.' .
              '</div>' .
              '<div style="margin:0 0 16px">' .
              '<a class="btn btn-primary" href="%s" target="_blank">Abrir Painel Administrativo em Nova Aba</a>' .
              '</div>' .
              '<iframe src="%s" style="width:100%%;min-height:1800px;border:1px solid #dcdcdc;border-radius:4px;background:#fff"></iframe>' .
            '</div>',
            htmlspecialchars($adminUrl, ENT_QUOTES),
            htmlspecialchars($adminUrl, ENT_QUOTES)
        )
        : "<em>Configure System URL em WHMCS ➔ Configurações Gerais.</em>";

    return [
        "FriendlyName" => [
            "Type" => "System",
            "Value" => "Banco Inter Boleto e PIX",
        ],
        "client_id" => [
            "FriendlyName" => "Client ID",
            "Type" => "text",
            "Size" => "60",
            "Description" => "Client ID emitido pelo Banco Inter.",
        ],
        "client_secret" => [
            "FriendlyName" => "Client Secret",
            "Type" => "password",
            "Size" => "60",
            "Description" => "Client Secret correspondente.",
        ],
        "conta_corrente" => [
            "FriendlyName" => "Conta Corrente",
            "Type" => "text",
            "Size" => "20",
            "Description" => "Opcional — obrigatório quando a aplicação tem múltiplas contas autorizadas.",
        ],
        "cert_path" => [
            "FriendlyName" => "Caminho do Certificado (.crt/.pem)",
            "Type" => "text",
            "Size" => "90",
            "Description" => "Caminho absoluto do certificado fora do public_html.",
        ],
        "key_path" => [
            "FriendlyName" => "Caminho da Chave Privada (.key)",
            "Type" => "text",
            "Size" => "90",
            "Description" => "Caminho absoluto da chave privada do certificado.",
        ],
        "auto_generate" => [
            "FriendlyName" => "Gerar Boleto/PIX automaticamente",
            "Type" => "yesno",
            "Description" => "Emite a cobrança assim que a fatura é criada.",
        ],
        "attach_pdf_always" => [
            "FriendlyName" => "Anexar boleto em todas as faturas",
            "Type" => "yesno",
            "Description" => "Quando ativado, anexa o PDF do boleto a TODAS as faturas (não apenas as com método Banco Inter), gerando a cobrança automaticamente se necessário.",
        ],
        "dias_baixa" => [
            "FriendlyName" => "Dias para Baixa Automática",
            "Type" => "text",
            "Size" => "5",
            "Default" => "15",
            "Description" => "Dias após o vencimento para cancelamento automático no banco.",
        ],
        "multa_pct" => [
            "FriendlyName" => "Multa (%)",
            "Type" => "text",
            "Size" => "6",
            "Default" => "2",
            "Description" => "Percentual de multa aplicado após o vencimento.",
        ],
        "juros_pct" => [
            "FriendlyName" => "Juros ao Mês (%)",
            "Type" => "text",
            "Size" => "6",
            "Default" => "1",
            "Description" => "Taxa mensal de juros moratórios.",
        ],
        "desconto_pct" => [
            "FriendlyName" => "Desconto para pagamento antecipado (%)",
            "Type" => "text",
            "Size" => "6",
            "Default" => "0",
            "Description" => "Percentual de desconto concedido para pagamento antecipado.",
        ],
        "desconto_fixo" => [
            "FriendlyName" => "Desconto fixo (R$)",
            "Type" => "text",
            "Size" => "10",
            "Default" => "0",
            "Description" => "Alternativa ao percentual para desconto antecipado.",
        ],
        "desconto_dias" => [
            "FriendlyName" => "Dias antes do vencimento p/ desconto",
            "Type" => "text",
            "Size" => "4",
            "Default" => "0",
            "Description" => "Janela de antecipação para o desconto.",
        ],
        "cpf_cnpj_field" => [
            "FriendlyName" => "Campo Customizado de CPF/CNPJ",
            "Type" => "dropdown",
            "Options" => $customFieldOptions,
            "Description" => "Se vazio, usa o Tax ID padrão do cliente.",
        ],
        "admin_panel_embed" => [
            "FriendlyName" => "Painel Administrativo",
            "Type" => "System",
            "Value" => $adminHtml,
        ],
    ];
}

/**
 * Invoice-view payment block. Returns HTML appended beneath the invoice total.
 * Renders PIX QR + boleto info when a cobrança already exists, otherwise
 * offers a button to mint one on demand.
 */
function seixastec_bancointer_link(array $params): string
{
    $invoiceId = (int) $params["invoiceid"];
    $tx = BancoInterHelper::findActiveByInvoice($invoiceId) ?: BancoInterHelper::findByInvoice($invoiceId);

    if (!$tx || in_array(strtoupper((string) $tx->status), BancoInterHelper::TERMINAL_CANCELLED_STATUSES, true)) {
        if (!empty($params["auto_generate"]) && $params["auto_generate"] === "on") {
            try {
                $userId = (int) $params["clientdetails"]["userid"];
                $amount = (float) $params["amount"];
                // Tentamos obter dueDate real pela API interna do WHMCS ou no fallback
                $dueDate = !empty($params["duedate"]) ? $params["duedate"] : date("Y-m-d");
                
                $row = seixastec_bancointer_generateForInvoice($invoiceId, $userId, $amount, $dueDate, $params);
                $tx = (object) $row;
            } catch (Throwable $e) {
                BancoInterHelper::log("invoice.auto_generate_failed", ["invoiceid" => $invoiceId], $e->getMessage());
                $generateUrl = rtrim($params["systemurl"], "/") . "/modules/gateways/seixastec_bancointer/generate.php";
                $csrfToken = htmlspecialchars(BancoInterHelper::issueCsrfToken("client_generate_invoice"), ENT_QUOTES);
                return <<<HTML
<div class="bancointer-pay" style="border:1px solid #e6e6e6;border-radius:8px;padding:16px;margin-top:16px;background:#fff8f8;border-color:#ffcccc">
    <h4 style="margin-top:0;color:#d9534f">Falha ao gerar cobrança automática</h4>
    <p style="color:#d9534f">Nao foi possivel gerar a cobranca Banco Inter neste momento.</p>
    <form method="post" action="{$generateUrl}">
        <input type="hidden" name="invoiceid" value="{$invoiceId}">
        <input type="hidden" name="csrf_token" value="{$csrfToken}">
        <button type="submit" class="btn btn-danger">Tentar Novamente</button>
    </form>
</div>
HTML;
            }
        }
        
        // Se ainda não gerou (auto_generate desativado), exibe o botão
        if (!$tx || in_array(strtoupper((string) $tx->status), BancoInterHelper::TERMINAL_CANCELLED_STATUSES, true)) {
            $generateUrl = rtrim($params["systemurl"], "/") . "/modules/gateways/seixastec_bancointer/generate.php";
            $csrfToken = htmlspecialchars(BancoInterHelper::issueCsrfToken("client_generate_invoice"), ENT_QUOTES);
            return <<<HTML
<div class="bancointer-pay" style="border:1px solid #e6e6e6;border-radius:8px;padding:16px;margin-top:16px">
    <h4 style="margin-top:0">Pagar com Banco Inter Boleto e PIX</h4>
    <p>Clique no botão abaixo para gerar o boleto com PIX embutido.</p>
    <form method="post" action="{$generateUrl}">
        <input type="hidden" name="invoiceid" value="{$invoiceId}">
        <input type="hidden" name="csrf_token" value="{$csrfToken}">
        <button type="submit" class="btn btn-primary">Gerar Boleto + PIX</button>
    </form>
</div>
HTML;
        }
    }

    $tx = seixastec_bancointer_refreshCollectionIfNeeded($tx, $params);

    $linhaDigitavelRaw = trim((string) ($tx->linha_digitavel ?? ""));
    $pixCopyRaw = (string) ($tx->pix_copia_cola ?? "");
    $hasPix = $pixCopyRaw !== "" || !empty($tx->pix_qrcode_base64);
    $hasLinhaDigitavel = $linhaDigitavelRaw !== "";

    if (!empty($tx->pix_qrcode_base64)) {
        $qrMime = (strpos((string) $tx->pix_qrcode_base64, 'PHN2Z') === 0 || strpos((string) $tx->pix_qrcode_base64, 'PD94') === 0)
            ? 'image/svg+xml' : 'image/png';
        $qr = '<img alt="PIX QR Code" style="max-width:240px" src="data:' . $qrMime . ';base64,' . htmlspecialchars((string) $tx->pix_qrcode_base64, ENT_QUOTES) . '">';
    } elseif ($pixCopyRaw !== "") {
        // Renderiza QR inline para evitar dependência de sessão em requisição separada.
        try {
            $qrImage = seixastec_bancointer_renderPixQr($pixCopyRaw, 240);
            $isSvg = strpos($qrImage, '<svg') !== false || strpos($qrImage, '<?xml') === 0;
            $qrMime = $isSvg ? 'image/svg+xml' : 'image/png';
            $qrEncoded = base64_encode($qrImage);
            $qr = '<img alt="PIX QR Code" style="max-width:240px" src="data:' . $qrMime . ';base64,' . $qrEncoded . '">';
            // Persiste para evitar reprocessamento nas próximas visitas.
            if (!empty($tx->codigo_solicitacao)) {
                BancoInterHelper::saveTransaction([
                    "invoice_id" => (int) $tx->invoice_id,
                    "codigo_solicitacao" => (string) $tx->codigo_solicitacao,
                    "pix_qrcode_base64" => $qrEncoded,
                ]);
            }
        } catch (Throwable $e) {
            BancoInterHelper::log("link.qr_render_failed", ["invoiceid" => $invoiceId], $e->getMessage());
            $qr = "";
        }
    } else {
        $qr = "";
    }

    $pdfUrl = rtrim($params["systemurl"], "/")
        . "/modules/gateways/seixastec_bancointer/generate.php?action=pdf&invoiceid=" . $invoiceId;

    $status = htmlspecialchars((string) ($tx->status ?? "PENDING"), ENT_QUOTES);
    $due = !empty($tx->due_date) ? date("d/m/Y", strtotime((string) $tx->due_date)) : "—";

    if (!$hasPix && !$hasLinhaDigitavel) {
        return <<<HTML
<div class="bancointer-pay" style="border:1px solid #e6e6e6;border-radius:8px;padding:16px;margin-top:16px">
    <h4 style="margin-top:0">Banco Inter Boleto e PIX</h4>
    <p><strong>Status:</strong> {$status} · <strong>Vencimento:</strong> {$due}</p>
    <div style="padding:12px 14px;border:1px solid #f0d58a;background:#fff8dc;border-radius:4px;color:#8a6a00">
        Cobrança emitida no Banco Inter. Os dados de pagamento ainda estão em processamento; recarregue a fatura em instantes.
    </div>
</div>
HTML;
    }

    $qrBlock = $qr !== ""
        ? '<div style="display:flex;justify-content:center">' . $qr . '</div>'
        : "";

    $pixAttr = htmlspecialchars($pixCopyRaw, ENT_QUOTES);
    $pixButton = $pixCopyRaw !== ""
        ? <<<HTML
        <button type="button" class="bancointer-copy-pix"
                data-pix="{$pixAttr}"
                style="width:260px;padding:10px 14px;border:none;border-radius:4px;background:#1e63c0;color:#fff;font-weight:600;cursor:pointer;font-size:14px">Copiar PIX Copia e Cola</button>
        <div class="bancointer-copy-status" style="font-size:12px;color:#2d6b36;min-height:16px"></div>
HTML
        : "";

    $linhaDigitavel = htmlspecialchars($linhaDigitavelRaw, ENT_QUOTES);
    $linhaBlock = $hasLinhaDigitavel
        ? <<<HTML
        <div style="width:100%;max-width:420px">
            <label style="display:block;text-align:center;margin-bottom:4px">Linha digitável</label>
            <input type="text" readonly onclick="this.select()" value="{$linhaDigitavel}" style="width:100%;font-family:monospace;text-align:center">
        </div>
HTML
        : "";

    $copyScript = $pixCopyRaw !== ""
        ? <<<HTML
<script>
(function(){
    document.querySelectorAll(".bancointer-copy-pix").forEach(function(btn){
        btn.addEventListener("click", function(){
            var pix = btn.getAttribute("data-pix") || "";
            var status = btn.parentNode.querySelector(".bancointer-copy-status");
            var done = function(ok){
                if(status){ status.textContent = ok ? "Código copiado!" : "Falha ao copiar — selecione manualmente."; }
                setTimeout(function(){ if(status) status.textContent = ""; }, 4000);
            };
            if(navigator.clipboard && navigator.clipboard.writeText){
                navigator.clipboard.writeText(pix).then(function(){done(true);}, function(){done(false);});
            } else {
                var ta = document.createElement("textarea");
                ta.value = pix; ta.style.position="fixed"; ta.style.opacity="0";
                document.body.appendChild(ta); ta.select();
                try { done(document.execCommand("copy")); } catch(e){ done(false); }
                document.body.removeChild(ta);
            }
        });
    });
})();
</script>
HTML
        : "";

    return <<<HTML
<div class="bancointer-pay" style="border:1px solid #e6e6e6;border-radius:8px;padding:16px;margin-top:16px">
    <h4 style="margin-top:0">Banco Inter Boleto e PIX</h4>
    <p><strong>Status:</strong> {$status} · <strong>Vencimento:</strong> {$due}</p>

    <div style="display:flex;flex-direction:column;align-items:center;gap:14px">
        {$qrBlock}
{$pixButton}
{$linhaBlock}
        <a class="btn btn-primary" href="{$pdfUrl}" target="_blank">Baixar Boleto (PDF)</a>
    </div>
</div>
{$copyScript}
HTML;
}

/* -------------------------------------------------- shared helpers
 * Exposed as top-level functions because WHMCS loads gateway modules with
 * require_once; every caller (generate.php, tools.php, callback, hooks)
 * pulls this file and gets the helpers below without risking redeclaration.
 */

/**
 * Load gateway config. Usa getGatewayVariables() (função oficial do WHMCS) que
 * descriptografa campos "password" automaticamente, em vez de ler
 * tblpaymentgateways cru via Capsule — cru retornaria ciphertext.
 */
function seixastec_bancointer_loadParams(): ?array
{
    if (!function_exists("getGatewayVariables")) {
        require_once ROOTDIR . "/includes/gatewayfunctions.php";
    }

    $params = getGatewayVariables("seixastec_bancointer");
    if (empty($params) || empty($params["type"])) {
        return null;
    }

    if (empty($params["webhook_secret"])) {
        $params["webhook_secret"] = BancoInterHelper::ensureWebhookSecret("seixastec_bancointer");
    }

    if (empty($params["systemurl"])) {
        $params["systemurl"] = BancoInterHelper::systemUrl();
    }

    return $params;
}

/** Factory — constructs an API client from the gateway params array. */
function seixastec_bancointer_buildApi(array $params): BancoInterAPI
{
    return new BancoInterAPI([
        "client_id" => $params["client_id"] ?? "",
        "client_secret" => $params["client_secret"] ?? "",
        "cert_path" => $params["cert_path"] ?? "",
        "key_path" => $params["key_path"] ?? "",
        "conta_corrente" => $params["conta_corrente"] ?? null,
    ]);
}

/**
 * Emit a new cobrança and persist the response. Shared by the client-area
 * generate endpoint and the InvoiceCreation hook.
 */
function seixastec_bancointer_generateForInvoice(int $invoiceId, int $userId, float $amount, string $dueDate, array $params): array
{
    if ($amount < 2.5) {
        throw new RuntimeException(sprintf(
            "Banco Inter exige cobrança mínima de R\$ 2,50 (fatura #%d tem R\$ %s).",
            $invoiceId,
            number_format($amount, 2, ",", ".")
        ));
    }

    $dueDate = $dueDate && $dueDate !== "0000-00-00"
        ? date("Y-m-d", strtotime($dueDate))
        : date("Y-m-d", strtotime("+3 days"));

    $existing = BancoInterHelper::findActiveByInvoice($invoiceId);
    if ($existing && BancoInterHelper::isReusableStatus($existing->status)) {
        $sameAmount = $existing->amount !== null && abs((float) $existing->amount - round($amount, 2)) <= 0.01;
        $sameDueDate = (string) $existing->due_date === $dueDate;
        if ($sameAmount && $sameDueDate) {
            return (array) $existing;
        }

        try {
            seixastec_bancointer_buildApi($params)->cancelCollection((string) $existing->codigo_solicitacao, "APEDIDODOCLIENTE");
        } catch (Throwable $e) {
            BancoInterHelper::log("generate.cancel_previous_failed", [
                "invoice_id" => $invoiceId,
                "codigo_solicitacao" => $existing->codigo_solicitacao,
            ], $e->getMessage());
        }

        BancoInterHelper::saveTransaction([
            "id" => (int) $existing->id,
            "invoice_id" => $invoiceId,
            "codigo_solicitacao" => $existing->codigo_solicitacao,
            "status" => "CANCELLED",
        ]);
    }

    $client = Capsule::table("tblclients")->where("id", $userId)->first();
    if (!$client) {
        throw new RuntimeException("Cliente {$userId} não encontrado.");
    }

    $customFieldId = $params["cpf_cnpj_field"] ?? null;
    $customFieldValid = !empty($customFieldId) && ctype_digit((string) $customFieldId);
    $digits = BancoInterHelper::resolveClientDocument($userId, $customFieldId);
    if ($digits === "" || (strlen($digits) !== 11 && strlen($digits) !== 14)) {
        $source = $customFieldValid
            ? "custom field #{$customFieldId} (+ fallback tblclients.tax_id)"
            : "tblclients.tax_id (cpf_cnpj_field ignorado: '" . mb_substr((string) $customFieldId, 0, 20) . "...')";
        throw new RuntimeException(sprintf(
            "CPF/CNPJ do cliente %d inválido ou vazio (fonte: %s, %d dígitos obtidos; esperado 11 ou 14).",
            $userId,
            $source,
            strlen($digits)
        ));
    }

    $tipoPessoa = BancoInterHelper::classifyDocument($digits);

    $diasBaixa = max(1, (int) ($params["dias_baixa"] ?? 15));

    $payload = [
        "seuNumero" => (string) $invoiceId,
        "valorNominal" => round($amount, 2),
        "dataVencimento" => $dueDate,
        "numDiasAgenda" => $diasBaixa,
        "pagador" => array_filter([
            "cpfCnpj" => $digits,
            "tipoPessoa" => $tipoPessoa,
            "nome" => mb_substr(trim(($client->firstname ?? "") . " " . ($client->lastname ?? "")) ?: (string) ($client->companyname ?? ""), 0, 100),
            "endereco" => mb_substr((string) ($client->address1 ?? "") ?: "Nao informado", 0, 100),
            "numero" => "S/N",
            "bairro" => mb_substr((string) ($client->address2 ?? "") ?: "Centro", 0, 60),
            "cidade" => mb_substr((string) ($client->city ?? ""), 0, 60),
            "uf" => strtoupper(mb_substr((string) ($client->state ?? ""), 0, 2)),
            "cep" => BancoInterHelper::onlyDigits($client->postcode ?? ""),
            "email" => mb_substr((string) ($client->email ?? ""), 0, 60),
        ], fn($v) => $v !== "" && $v !== null),
    ];

    $chargeOpts = BancoInterHelper::buildChargeOptions(array_merge($params, ["due_date" => $dueDate]));
    if ($chargeOpts) {
        $payload = array_merge($payload, $chargeOpts);
    }

    $response = seixastec_bancointer_buildApi($params)->createCollection($payload);

    if (function_exists("logTransaction")) {
        logTransaction($params["paymentmethod"] ?? "seixastec_bancointer", array_merge($payload, ["RESPONSE" => $response]), "Cobrança Gerada: " . ($response["codigoSolicitacao"] ?? "Desconhecido"));
    }

    $row = seixastec_bancointer_collectionRowFromResponse($invoiceId, $response, [
        "seu_numero" => (string) $invoiceId,
        "amount" => round($amount, 2),
        "due_date" => $dueDate,
        "raw_request" => json_encode($payload, JSON_UNESCAPED_UNICODE),
    ]);

    BancoInterHelper::saveTransaction($row);

    return $row;
}

function seixastec_bancointer_collectionRowFromResponse(int $invoiceId, array $response, array $extra = []): array
{
    $row = [
        "invoice_id" => $invoiceId,
    ];

    $fieldMap = [
        "codigo_solicitacao" => $response["codigoSolicitacao"] ?? null,
        "nosso_numero" => $response["boleto"]["nossoNumero"] ?? null,
        "txid" => $response["pix"]["txid"] ?? null,
        "pix_copia_cola" => $response["pix"]["pixCopiaECola"] ?? null,
        "pix_qrcode_base64" => seixastec_bancointer_extractQrBase64($response),
        "linha_digitavel" => $response["boleto"]["linhaDigitavel"] ?? null,
        "codigo_barras" => $response["boleto"]["codigoBarras"] ?? null,
    ];

    foreach ($fieldMap as $field => $value) {
        if ($value !== null && $value !== "") {
            $row[$field] = $value;
        }
    }

    if (!empty($response["situacao"])) {
        $row["status"] = strtoupper((string) $response["situacao"]);
    } elseif (!isset($extra["status"])) {
        $row["status"] = "PENDING";
    }

    $row["raw_response"] = json_encode($response, JSON_UNESCAPED_UNICODE);

    return array_merge($row, $extra);
}

function seixastec_bancointer_transactionNeedsRefresh(?object $tx): bool
{
    if (!$tx || empty($tx->codigo_solicitacao)) {
        return false;
    }

    $hasLinhaDigitavel = trim((string) ($tx->linha_digitavel ?? "")) !== "";
    $hasPix = trim((string) ($tx->pix_copia_cola ?? "")) !== "" || trim((string) ($tx->pix_qrcode_base64 ?? "")) !== "";

    return !$hasLinhaDigitavel || !$hasPix;
}

function seixastec_bancointer_refreshCollectionIfNeeded(?object $tx, array $params, bool $force = false): ?object
{
    if (!$tx || empty($tx->codigo_solicitacao)) {
        return $tx;
    }

    if (!$force && !seixastec_bancointer_transactionNeedsRefresh($tx)) {
        return $tx;
    }

    try {
        $response = seixastec_bancointer_buildApi($params)->getCollection((string) $tx->codigo_solicitacao);
        $row = seixastec_bancointer_collectionRowFromResponse((int) $tx->invoice_id, $response, [
            "codigo_solicitacao" => (string) $tx->codigo_solicitacao,
            "seu_numero" => (string) ($tx->seu_numero ?? $tx->invoice_id),
            "amount" => $tx->amount ?? null,
            "due_date" => $tx->due_date ?? null,
        ]);
        BancoInterHelper::saveTransaction($row);

        return BancoInterHelper::findByInvoice((int) $tx->invoice_id) ?: $tx;
    } catch (Throwable $e) {
        BancoInterHelper::log("collection.refresh_failed", [
            "invoice_id" => (int) $tx->invoice_id,
            "codigo_solicitacao" => (string) $tx->codigo_solicitacao,
        ], $e->getMessage());
        return $tx;
    }
}

/**
 * Render the PIX copia-e-cola string as a PNG QR Code. Tries, in order:
 *   1. endroid/qr-code (bundled with WHMCS 9.x)
 *   2. chillerlan/php-qrcode (some WHMCS distros)
 *   3. BaconQrCode (optional)
 * Throws if no library is available.
 */
function seixastec_bancointer_renderPixQr(string $payload, int $size = 240): string
{
    if (class_exists("Endroid\\QrCode\\Builder\\Builder")) {
        $result = \Endroid\QrCode\Builder\Builder::create()
            ->data($payload)
            ->size($size)
            ->margin(8)
            ->build();
        return $result->getString();
    }

    // Endroid v3 (API antiga — sem Builder, tem writeString() direto).
    if (class_exists("Endroid\\QrCode\\QrCode")) {
        $qr = new \Endroid\QrCode\QrCode($payload);
        if (method_exists($qr, "setSize"))   { $qr->setSize($size); }
        if (method_exists($qr, "setMargin")) { $qr->setMargin(8); }
        if (method_exists($qr, "writeString")) {
            return $qr->writeString();
        }
        if (method_exists($qr, "get")) {
            return $qr->get("png");
        }
    }

    if (class_exists("chillerlan\\QRCode\\QRCode")) {
        $options = new \chillerlan\QRCode\QROptions([
            "outputType" => \chillerlan\QRCode\QRCode::OUTPUT_IMAGE_PNG,
            "imageBase64" => false,
            "scale" => max(2, (int) round($size / 41)),
            "imageTransparent" => false,
        ]);
        return (new \chillerlan\QRCode\QRCode($options))->render($payload);
    }

    if (class_exists("BaconQrCode\\Writer")) {
        // Bacon QR: usamos backend SVG porque Imagick raramente está em hospedagem compartilhada.
        // SVG é renderizado nativamente pelo <img src=...>.
        $renderer = new \BaconQrCode\Renderer\ImageRenderer(
            new \BaconQrCode\Renderer\RendererStyle\RendererStyle($size, 1),
            new \BaconQrCode\Renderer\Image\SvgImageBackEnd()
        );
        return (new \BaconQrCode\Writer($renderer))->writeString($payload);
    }

    throw new RuntimeException("Nenhuma biblioteca de QR Code disponível neste WHMCS.");
}

/** The QR image arrives either as a bare base64 blob or under pix.qrCode. */
function seixastec_bancointer_extractQrBase64(array $response): ?string
{
    foreach (["pix.qrCode", "pix.qrcode", "qrCode", "qrcode"] as $path) {
        $segments = explode(".", $path);
        $value = $response;
        foreach ($segments as $seg) {
            if (!is_array($value) || !isset($value[$seg])) {
                $value = null;
                break;
            }
            $value = $value[$seg];
        }
        if (is_string($value) && $value !== "") {
            return preg_replace("~^data:image/[^;]+;base64,~", "", $value);
        }
    }
    return null;
}
