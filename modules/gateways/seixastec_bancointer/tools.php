<?php
/**
 * Seixas Tecnologia admin console for Banco Inter Boleto e PIX.
 *
 * Hosts a dedicated backoffice for configuration, webhook lifecycle,
 * transaction extracts, emission metrics and logs instead of relying on
 * the limited native gateway configuration renderer.
 */

use WHMCS\Database\Capsule;

require_once __DIR__ . "/../../../init.php";
require_once __DIR__ . "/../seixastec_bancointer.php";

const BI_RESULT_LIMIT = 100;

if (empty($_SESSION["adminid"])) {
    http_response_code(403);
    exit("Admin login required.");
}

$params = seixastec_bancointer_loadParams();
if (!$params) {
    exit("Gateway Banco Inter Boleto e PIX não está ativo.");
}

$views = [
    "config" => "Configuracoes",
    "webhook" => "Webhook",
    "extract" => "Extrato de Boletos",
    "metrics" => "Metricas de Emissao",
    "logs" => "Logs",
    "webhook_logs" => "Logs Webhook",
];

$view = (string) ($_GET["view"] ?? "config");
if (!isset($views[$view])) {
    $view = "config";
}

$flash = null;
$errors = [];

if (strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "GET")) === "POST") {
    if (!BancoInterHelper::validateCsrfToken("admin_webhook_tools", $_POST["csrf_token"] ?? null)) {
        http_response_code(403);
        exit("Invalid request token.");
    }

    $action = (string) ($_POST["action"] ?? "");
    try {
        switch ($action) {
            case "save_config":
                bi_saveConfiguration($_POST);
                $flash = ["type" => "success", "message" => "Configuracoes salvas com sucesso."];
                $params = seixastec_bancointer_loadParams() ?: $params;
                $view = "config";
                break;
            case "register_webhook":
                $response = seixastec_bancointer_buildApi($params)->registerWebhook(
                    BancoInterHelper::callbackUrl($params["systemurl"], $params["webhook_secret"] ?? null)
                );
                $flash = ["type" => "success", "message" => "Webhook registrado com sucesso."];
                BancoInterHelper::log("admin.webhook.register", [], $response);
                $view = "webhook";
                break;
            case "delete_webhook":
                $response = seixastec_bancointer_buildApi($params)->deleteWebhook();
                $flash = ["type" => "success", "message" => "Webhook removido com sucesso."];
                BancoInterHelper::log("admin.webhook.delete", [], $response);
                $view = "webhook";
                break;
            case "rotate_secret":
                BancoInterHelper::rotateWebhookSecret("seixastec_bancointer");
                $params = seixastec_bancointer_loadParams() ?: $params;
                $flash = ["type" => "success", "message" => "Novo token de webhook gerado. Atualize o webhook no Banco Inter para aplicar a nova URL."];
                $view = "webhook";
                break;
            default:
                $errors[] = "Acao administrativa invalida.";
                break;
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

$csrfToken = htmlspecialchars(BancoInterHelper::issueCsrfToken("admin_webhook_tools"), ENT_QUOTES);
$systemUrl = $params["systemurl"] ?? BancoInterHelper::systemUrl();
$callbackUrl = BancoInterHelper::callbackUrl($systemUrl, $params["webhook_secret"] ?? null);
$customFieldOptions = bi_getClientDocumentOptions();
$webhookState = bi_loadWebhookState($params, $callbackUrl);
$metrics = bi_loadMetrics();
$extractRows = $view === "extract" ? bi_loadExtractRows($_GET) : [];
$logRows = $view === "logs" ? bi_loadModuleLogs($_GET) : [];
$webhookRows = $view === "webhook_logs" ? bi_loadWebhookLogs($_GET) : [];
$logoUrl = bi_logoUrl();

header("Content-Type: text/html; charset=utf-8");
echo "<!doctype html><html lang='pt-BR'><head><meta charset='utf-8'><meta name='viewport' content='width=device-width, initial-scale=1'>";
echo "<title>Banco Inter Boleto e PIX</title>";
echo "<style>" . bi_adminCss() . "</style>";
echo "</head><body>";
echo "<div class='bi-admin'>";
echo "<header class='bi-topbar'><div class='bi-brand'>";
if ($logoUrl !== "") {
    echo "<img class='bi-brand-logo' src='" . htmlspecialchars($logoUrl, ENT_QUOTES) . "' alt='Banco Inter'>";
}
echo "<div class='bi-brand-copy'><h1>Banco Inter Boleto e PIX</h1><p>Integracao com Banco Inter</p></div></div></header>";
echo "<div class='bi-shell'>";
echo "<aside class='bi-sidebar'>";
foreach ($views as $key => $label) {
    $active = $key === $view ? " is-active" : "";
    echo "<a class='bi-side-link{$active}' href='" . htmlspecialchars(bi_adminUrl($key, [], $systemUrl), ENT_QUOTES) . "'>{$label}</a>";
}
echo "</aside>";
echo "<main class='bi-main'>";
echo "<div class='bi-breadcrumb'>Gerenciar Gateway Boleto - Banco Inter Boleto e PIX <span>/</span> " . htmlspecialchars($views[$view], ENT_QUOTES) . "</div>";

if ($flash) {
    echo "<div class='bi-alert bi-alert-" . htmlspecialchars($flash["type"], ENT_QUOTES) . "'>" . htmlspecialchars($flash["message"], ENT_QUOTES) . "</div>";
}
foreach ($errors as $error) {
    echo "<div class='bi-alert bi-alert-error'>" . htmlspecialchars($error, ENT_QUOTES) . "</div>";
}

switch ($view) {
    case "config":
        bi_renderConfigCard($params, $customFieldOptions, $csrfToken);
        break;
    case "webhook":
        bi_renderWebhookCard($params, $webhookState, $callbackUrl, $csrfToken);
        break;
    case "extract":
        bi_renderExtractCard($extractRows, $systemUrl);
        break;
    case "metrics":
        bi_renderMetricsCard($metrics);
        break;
    case "logs":
        bi_renderLogsCard($logRows, $systemUrl);
        break;
    case "webhook_logs":
        bi_renderWebhookLogsCard($webhookRows, $csrfToken, $systemUrl);
        break;
}

echo "</main></div></div></body></html>";

function bi_adminUrl(string $view, array $query = [], ?string $systemUrl = null): string
{
    $base = ($systemUrl ?: BancoInterHelper::systemUrl()) . "/modules/gateways/seixastec_bancointer/tools.php";
    return $base . "?" . http_build_query(array_merge(["view" => $view], $query));
}

function bi_logoUrl(): string
{
    $absolute = ROOTDIR . "/modules/gateways/seixastec_bancointer/inter.png";
    if (!is_file($absolute)) {
        return "";
    }

    return BancoInterHelper::systemUrl() . "/modules/gateways/seixastec_bancointer/inter.png";
}

function bi_saveConfiguration(array $data): void
{
    $fields = [
        "client_id" => trim((string) ($data["client_id"] ?? "")),
        "client_secret" => trim((string) ($data["client_secret"] ?? "")),
        "conta_corrente" => trim((string) ($data["conta_corrente"] ?? "")),
        "cert_path" => trim((string) ($data["cert_path"] ?? "")),
        "key_path" => trim((string) ($data["key_path"] ?? "")),
        "auto_generate" => !empty($data["auto_generate"]) ? "on" : "off",
        "dias_baixa" => (string) max(1, (int) ($data["dias_baixa"] ?? 15)),
        "multa_pct" => (string) (float) ($data["multa_pct"] ?? 0),
        "juros_pct" => (string) (float) ($data["juros_pct"] ?? 0),
        "desconto_pct" => (string) (float) ($data["desconto_pct"] ?? 0),
        "desconto_fixo" => (string) (float) ($data["desconto_fixo"] ?? 0),
        "desconto_dias" => (string) max(0, (int) ($data["desconto_dias"] ?? 0)),
        "cpf_cnpj_field" => bi_sanitizeCustomFieldId($data["cpf_cnpj_field"] ?? ""),
    ];

    foreach ($fields as $setting => $value) {
        BancoInterHelper::upsertGatewaySetting("seixastec_bancointer", $setting, $value);
    }
}

/**
 * Garante que apenas um ID numérico de custom field do tipo `client` seja persistido
 * em `cpf_cnpj_field`. Qualquer outro input (hash, string, id inexistente) vira "".
 */
function bi_sanitizeCustomFieldId($raw): string
{
    $raw = trim((string) $raw);
    if ($raw === "" || !ctype_digit($raw)) {
        return "";
    }

    $exists = Capsule::table("tblcustomfields")
        ->where("id", (int) $raw)
        ->where("type", "client")
        ->exists();

    return $exists ? $raw : "";
}

function bi_getClientDocumentOptions(): array
{
    $options = ["" => "Usar Tax ID padrao do cliente"];
    try {
        $rows = Capsule::table("tblcustomfields")
            ->where("type", "client")
            ->orderBy("fieldname")
            ->get(["id", "fieldname"]);
        foreach ($rows as $row) {
            $options[(string) $row->id] = sprintf("[%d] %s", $row->id, $row->fieldname);
        }
    } catch (Throwable $e) {
        // Ignore missing schema in reduced test environments.
    }
    return $options;
}

function bi_loadWebhookState(array $params, string $callbackUrl): array
{
    $state = [
        "status" => "Nao configurado",
        "remote_url" => "",
        "message" => "",
    ];

    try {
        $response = seixastec_bancointer_buildApi($params)->getWebhook();
        $remoteUrl = (string) ($response["webhookUrl"] ?? "");
        $state["remote_url"] = $remoteUrl;
        $state["status"] = $remoteUrl === $callbackUrl ? "Ativo" : ($remoteUrl !== "" ? "Divergente" : "Nao configurado");
    } catch (Throwable $e) {
        $state["message"] = $e->getMessage();
    }

    return $state;
}

function bi_loadMetrics(): array
{
    $query = Capsule::table(BancoInterHelper::TABLE);
    $total = (clone $query)->count();
    $paid = (clone $query)->whereIn("status", BancoInterHelper::TERMINAL_PAID_STATUSES)->count();
    $pending = (clone $query)->whereIn("status", ["PENDING", "CREATED", "PROCESSING", "A_RECEBER", "EM_PROCESSAMENTO", "ATRASADO"])->count();
    $cancelled = (clone $query)->whereIn("status", BancoInterHelper::TERMINAL_CANCELLED_STATUSES)->count();
    $volume = (float) ((clone $query)->whereIn("status", BancoInterHelper::TERMINAL_PAID_STATUSES)->sum("paid_amount"));

    return [
        "total" => $total,
        "paid" => $paid,
        "pending" => $pending,
        "cancelled" => $cancelled,
        "volume" => $volume,
    ];
}

function bi_loadExtractRows(array $filters): array
{
    $query = Capsule::table(BancoInterHelper::TABLE)->orderBy("id", "desc");
    bi_applyDateFilters($query, $filters, "created_at");

    return $query->limit(BI_RESULT_LIMIT)->get()->all();
}

function bi_loadModuleLogs(array $filters): array
{
    if (!Capsule::schema()->hasTable("tblmodulelog")) {
        return [];
    }

    $query = Capsule::table("tblmodulelog")
        ->where("module", BancoInterHelper::LOG_GATEWAY)
        ->orderBy("id", "desc");
    bi_applyDateFilters($query, $filters, "date");

    return $query->limit(BI_RESULT_LIMIT)->get()->all();
}

function bi_loadWebhookLogs(array $filters): array
{
    $query = Capsule::table(BancoInterHelper::TABLE)
        ->whereNotNull("raw_response")
        ->orderBy("updated_at", "desc");
    bi_applyDateFilters($query, $filters, "updated_at");

    return $query->limit(BI_RESULT_LIMIT)->get()->all();
}

function bi_applyDateFilters($query, array $filters, string $column): void
{
    $start = bi_normalizeDateFilter($filters["start"] ?? null);
    $end = bi_normalizeDateFilter($filters["end"] ?? null);

    if ($start !== null) {
        $query->whereDate($column, ">=", $start);
    }
    if ($end !== null) {
        $query->whereDate($column, "<=", $end);
    }
}

function bi_normalizeDateFilter($value): ?string
{
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat("!Y-m-d", $value);
    if (!$date || $date->format("Y-m-d") !== $value) {
        return null;
    }

    return $value;
}

function bi_renderConfigCard(array $params, array $customFieldOptions, string $csrfToken): void
{
    echo "<section class='bi-card'><div class='bi-card-title'>Configuracoes do sistema</div><div class='bi-card-body'>";
    echo "<form method='post' class='bi-form-grid'>";
    echo "<input type='hidden' name='csrf_token' value='{$csrfToken}'>";
    echo "<input type='hidden' name='action' value='save_config'>";
    echo "<div class='bi-form-intro bi-form-span'><h2>Credenciais e regras de cobranca</h2><p>Configure a API Banco Inter, certificados mTLS e regras financeiras usadas na emissao das cobrancas.</p></div>";
    bi_inputRow("Client ID", "client_id", (string) ($params["client_id"] ?? ""));
    bi_inputRow("Client Secret", "client_secret", (string) ($params["client_secret"] ?? ""), "password");
    bi_inputRow("Conta Corrente", "conta_corrente", (string) ($params["conta_corrente"] ?? ""));
    bi_inputRow("Caminho Certificado", "cert_path", (string) ($params["cert_path"] ?? ""));
    bi_inputRow("Caminho Chave Privada", "key_path", (string) ($params["key_path"] ?? ""));
    bi_checkboxRow("Gerar automaticamente", "auto_generate", !empty($params["auto_generate"]) && $params["auto_generate"] !== "off");
    bi_inputRow("Dias para Baixa", "dias_baixa", (string) ($params["dias_baixa"] ?? "15"), "number");
    bi_inputRow("Multa (%)", "multa_pct", (string) ($params["multa_pct"] ?? "2"), "number", "0.01");
    bi_inputRow("Juros ao Mes (%)", "juros_pct", (string) ($params["juros_pct"] ?? "1"), "number", "0.01");
    bi_inputRow("Desconto (%)", "desconto_pct", (string) ($params["desconto_pct"] ?? "0"), "number", "0.01");
    bi_inputRow("Desconto Fixo (R$)", "desconto_fixo", (string) ($params["desconto_fixo"] ?? "0"), "number", "0.01");
    bi_inputRow("Dias do Desconto", "desconto_dias", (string) ($params["desconto_dias"] ?? "0"), "number");
    echo "<label class='bi-form-label'>Origem CPF / CNPJ</label><div class='bi-form-control'><select name='cpf_cnpj_field'>";
    foreach ($customFieldOptions as $id => $label) {
        $selected = (string) ($params["cpf_cnpj_field"] ?? "") === (string) $id ? " selected" : "";
        echo "<option value='" . htmlspecialchars((string) $id, ENT_QUOTES) . "'{$selected}>" . htmlspecialchars($label, ENT_QUOTES) . "</option>";
    }
    echo "</select></div>";
    echo "<div class='bi-form-actions'><button class='bi-btn bi-btn-primary' type='submit'>Salvar Configuracoes</button></div>";
    echo "</form></div></section>";
}

function bi_renderWebhookCard(array $params, array $state, string $callbackUrl, string $csrfToken): void
{
    $createdAt = !empty($params["webhook_secret_created_at"]) ? date("d/m/Y H:i:s", strtotime((string) $params["webhook_secret_created_at"])) : "—";
    echo "<section class='bi-card'><div class='bi-card-title'>Webhook</div><div class='bi-card-body'>";
    echo "<table class='bi-table bi-table-meta'>";
    echo "<tr><th>Status</th><td>" . htmlspecialchars($state["status"], ENT_QUOTES) . "</td></tr>";
    echo "<tr><th>Token Seguranca</th><td><input class='bi-readonly' readonly value='" . htmlspecialchars((string) ($params["webhook_secret"] ?? ""), ENT_QUOTES) . "'>";
    echo "<form method='post' class='bi-inline-form'><input type='hidden' name='csrf_token' value='{$csrfToken}'><input type='hidden' name='action' value='rotate_secret'><button type='submit' class='bi-btn bi-btn-secondary'>Gerar um novo token de seguranca</button></form></td></tr>";
    echo "<tr><th>URL Conexao</th><td><input class='bi-readonly' readonly value='" . htmlspecialchars($callbackUrl, ENT_QUOTES) . "'></td></tr>";
    echo "<tr><th>Data Criacao</th><td>{$createdAt}</td></tr>";
    echo "</table>";
    if ($state["message"] !== "") {
        echo "<div class='bi-note bi-note-warning'>" . htmlspecialchars($state["message"], ENT_QUOTES) . "</div>";
    }
    echo "<div class='bi-action-stack'>";
    echo "<form method='post'><input type='hidden' name='csrf_token' value='{$csrfToken}'><input type='hidden' name='action' value='register_webhook'><button type='submit' class='bi-btn bi-btn-success bi-btn-block'>Atualizar Webhook</button></form>";
    echo "<form method='post' onsubmit=\"return confirm('Remover webhook registrado no Banco Inter?')\"><input type='hidden' name='csrf_token' value='{$csrfToken}'><input type='hidden' name='action' value='delete_webhook'><button type='submit' class='bi-btn bi-btn-danger bi-btn-block'>Deletar Webhook</button></form>";
    echo "</div></div></section>";
}

function bi_renderExtractCard(array $rows, ?string $systemUrl = null): void
{
    echo "<section class='bi-card'><div class='bi-card-title'>Extrato de boletos por periodo</div><div class='bi-card-body'>";
    echo bi_dateFilter("extract", "Pesquisar", $systemUrl);
    echo bi_limitNotice($rows);
    echo bi_transactionsTable($rows, true);
    echo "</div></section>";
}

function bi_renderMetricsCard(array $metrics): void
{
    echo "<section class='bi-card'><div class='bi-card-title'>Metricas de emissao</div><div class='bi-card-body'>";
    echo "<div class='bi-metrics'>";
    bi_metric("Total", (string) $metrics["total"]);
    bi_metric("Pagas", (string) $metrics["paid"]);
    bi_metric("Pendentes", (string) $metrics["pending"]);
    bi_metric("Canceladas", (string) $metrics["cancelled"]);
    bi_metric("Volume recebido", "R$ " . number_format((float) $metrics["volume"], 2, ",", "."));
    echo "</div></div></section>";
}

function bi_renderLogsCard(array $rows, ?string $systemUrl = null): void
{
    echo "<section class='bi-card'><div class='bi-card-title'>Logs do sistema</div><div class='bi-card-body'>";
    echo bi_dateFilter("logs", "Ver Logs", $systemUrl);
    echo bi_limitNotice($rows);
    if (!$rows) {
        echo "<div class='bi-empty'>Nenhum log encontrado para o filtro selecionado.</div>";
    } else {
        echo "<table class='bi-table'><thead><tr><th>Data</th><th>Acao</th><th>Resumo</th></tr></thead><tbody>";
        foreach ($rows as $row) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars((string) ($row->date ?? $row->created_at ?? ""), ENT_QUOTES) . "</td>";
            echo "<td>" . htmlspecialchars((string) ($row->action ?? "—"), ENT_QUOTES) . "</td>";
            echo "<td><pre class='bi-pre'>" . htmlspecialchars(mb_substr((string) ($row->request ?? $row->data ?? ""), 0, 400), ENT_QUOTES) . "</pre></td>";
            echo "</tr>";
        }
        echo "</tbody></table>";
    }
    echo "</div></section>";
}

function bi_renderWebhookLogsCard(array $rows, string $csrfToken, ?string $systemUrl = null): void
{
    echo "<section class='bi-card'><div class='bi-card-title'>Logs Webhook</div><div class='bi-card-body'>";
    echo bi_dateFilter("webhook_logs", "Buscar", $systemUrl);
    echo bi_limitNotice($rows);
    if (!$rows) {
        echo "<div class='bi-empty'>Nenhum retorno de webhook registrado.</div>";
    } else {
        echo "<table class='bi-table'><thead><tr><th>Fatura</th><th>Status</th><th>Atualizado em</th><th>Payload</th></tr></thead><tbody>";
        foreach ($rows as $row) {
            echo "<tr>";
            echo "<td>" . (int) ($row->invoice_id ?? 0) . "</td>";
            echo "<td>" . htmlspecialchars((string) ($row->status ?? ""), ENT_QUOTES) . "</td>";
            echo "<td>" . htmlspecialchars((string) ($row->updated_at ?? ""), ENT_QUOTES) . "</td>";
            echo "<td><pre class='bi-pre'>" . htmlspecialchars(mb_substr((string) ($row->raw_response ?? ""), 0, 500), ENT_QUOTES) . "</pre></td>";
            echo "</tr>";
        }
        echo "</tbody></table>";
    }
    echo "</div></section>";
}

function bi_inputRow(string $label, string $name, string $value, string $type = "text", ?string $step = null): void
{
    $stepAttr = $step !== null ? " step='" . htmlspecialchars($step, ENT_QUOTES) . "'" : "";
    echo "<label class='bi-form-label'>" . htmlspecialchars($label, ENT_QUOTES) . "</label>";
    echo "<div class='bi-form-control'><input type='" . htmlspecialchars($type, ENT_QUOTES) . "' name='" . htmlspecialchars($name, ENT_QUOTES) . "' value='" . htmlspecialchars($value, ENT_QUOTES) . "'{$stepAttr}></div>";
}

function bi_checkboxRow(string $label, string $name, bool $checked): void
{
    echo "<label class='bi-form-label'>" . htmlspecialchars($label, ENT_QUOTES) . "</label>";
    echo "<div class='bi-form-control'><label class='bi-toggle'><input type='checkbox' name='" . htmlspecialchars($name, ENT_QUOTES) . "'" . ($checked ? " checked" : "") . "><span>Ativar</span></label></div>";
}

function bi_metric(string $label, string $value): void
{
    echo "<div class='bi-metric'><span>" . htmlspecialchars($label, ENT_QUOTES) . "</span><strong>" . htmlspecialchars($value, ENT_QUOTES) . "</strong></div>";
}

function bi_limitNotice(array $rows): string
{
    if (count($rows) < BI_RESULT_LIMIT) {
        return "";
    }

    return "<div class='bi-note bi-note-warning'>Mostrando os ultimos " . BI_RESULT_LIMIT . " registros. Refine o filtro de datas.</div>";
}

function bi_dateFilter(string $view, string $buttonLabel, ?string $systemUrl = null): string
{
    $start = htmlspecialchars((string) ($_GET["start"] ?? ""), ENT_QUOTES);
    $end = htmlspecialchars((string) ($_GET["end"] ?? ""), ENT_QUOTES);
    $action = htmlspecialchars(bi_adminUrl($view, [], $systemUrl), ENT_QUOTES);
    $label = htmlspecialchars($buttonLabel, ENT_QUOTES);

    return "<form method='get' class='bi-filter-row'>"
        . "<input type='hidden' name='view' value='" . htmlspecialchars($view, ENT_QUOTES) . "'>"
        . "<div><label>Data inicio</label><input type='date' name='start' value='{$start}'></div>"
        . "<div><label>Data final</label><input type='date' name='end' value='{$end}'></div>"
        . "<div class='bi-filter-action'><button type='submit' formaction='{$action}' class='bi-btn bi-btn-success'>{$label}</button></div>"
        . "</form>";
}

function bi_transactionsTable(array $rows, bool $showDueDate): string
{
    if (!$rows) {
        return "<div class='bi-empty'>Nenhum registro localizado.</div>";
    }

    $html = "<table class='bi-table'><thead><tr><th>Fatura</th><th>Status</th><th>Valor</th>";
    if ($showDueDate) {
        $html .= "<th>Vencimento</th>";
    }
    $html .= "<th>Atualizado</th></tr></thead><tbody>";

    foreach ($rows as $row) {
        $html .= "<tr>";
        $html .= "<td>" . (int) ($row->invoice_id ?? 0) . "</td>";
        $html .= "<td>" . htmlspecialchars((string) ($row->status ?? ""), ENT_QUOTES) . "</td>";
        $html .= "<td>R$ " . number_format((float) ($row->amount ?? 0), 2, ",", ".") . "</td>";
        if ($showDueDate) {
            $html .= "<td>" . htmlspecialchars((string) ($row->due_date ?? ""), ENT_QUOTES) . "</td>";
        }
        $html .= "<td>" . htmlspecialchars((string) ($row->updated_at ?? ""), ENT_QUOTES) . "</td>";
        $html .= "</tr>";
    }

    return $html . "</tbody></table>";
}

function bi_adminCss(): string
{
    return <<<CSS
body{margin:0;background:#f2f3f5;font-family:"Segoe UI",Tahoma,sans-serif;color:#333;font-size:13px}
.bi-topbar{padding:10px 14px;background:#f8f8f8;border-bottom:1px solid #e2e2e2}
.bi-brand{display:flex;align-items:center;gap:12px}
.bi-brand-logo{display:block;max-height:46px;width:auto}
.bi-brand-copy h1{margin:0;font-size:20px;font-weight:600;color:#333}
.bi-brand-copy p{margin:2px 0 0;color:#777;font-size:12px}
.bi-shell{display:flex;gap:14px;padding:14px}
.bi-sidebar{width:230px;background:#f6f6f6;border:1px solid #d9d9d9;border-radius:4px;overflow:hidden;height:max-content}
.bi-side-link{display:block;padding:10px 14px;color:#555;text-decoration:none;border-bottom:1px solid #d9d9d9;background:#fafafa;font-size:13px}
.bi-side-link.is-active,.bi-side-link:hover{background:#f0f0f0}
.bi-main{flex:1}
.bi-breadcrumb{background:#f6f6f6;border:1px solid #e0e0e0;border-radius:4px;padding:8px 12px;margin-bottom:14px;color:#777;font-size:12px}
.bi-breadcrumb span{margin:0 8px;color:#b8b8b8}
.bi-card{background:#fff;border:1px solid #dcdcdc;border-radius:4px;overflow:hidden}
.bi-card-title{padding:10px 14px;background:#f6f6f6;border-bottom:1px solid #e6e6e6;font-size:18px;font-weight:600;color:#444}
.bi-card-body{padding:16px}
.bi-alert{padding:10px 12px;border-radius:4px;margin-bottom:12px}
.bi-alert-success{background:#ebf7ec;color:#2d6b36;border:1px solid #bfe0c3}
.bi-alert-error{background:#fff0f0;color:#943c3c;border:1px solid #efc4c4}
.bi-form-grid{display:grid;grid-template-columns:170px minmax(0,720px);gap:10px 16px;align-items:center}
.bi-form-span{grid-column:1 / -1}
.bi-form-label{font-size:13px;color:#333;font-weight:600}
.bi-form-control input,.bi-form-control select,.bi-readonly{width:100%;box-sizing:border-box;border:1px solid #c7c7c7;border-radius:3px;padding:7px 9px;font-size:13px;line-height:1.35;background:#fff;min-height:34px}
.bi-readonly{background:#f7f7f7}
.bi-form-actions{grid-column:2;display:flex;justify-content:flex-start;padding-top:6px}
.bi-toggle{display:flex;align-items:center;gap:8px;font-size:13px}
.bi-toggle input{width:16px;height:16px}
.bi-btn{display:inline-flex;align-items:center;justify-content:center;border:none;border-radius:3px;padding:8px 12px;font-size:13px;text-decoration:none;cursor:pointer;line-height:1.35}
.bi-btn-primary{background:#3b7bbb;color:#fff}
.bi-btn-secondary{background:#3b7bbb;color:#fff;margin-top:6px}
.bi-btn-success{background:#4caf50;color:#fff}
.bi-btn-danger{background:#d9534f;color:#fff}
.bi-btn-block{width:100%;font-size:13px}
.bi-inline-form{margin-top:6px}
.bi-action-stack{margin-top:14px;display:grid;gap:8px;max-width:360px}
.bi-table{width:100%;border-collapse:collapse}
.bi-table th,.bi-table td{border:1px solid #d8d8d8;padding:8px 9px;text-align:left;vertical-align:top;font-size:13px}
.bi-table th{background:#fbfbfb;font-weight:600}
.bi-table-meta th{width:210px}
.bi-filter-row{display:grid;grid-template-columns:minmax(0,180px) minmax(0,180px) auto;gap:10px;margin-bottom:14px;align-items:end}
.bi-filter-row label{display:block;margin-bottom:4px;font-size:12px;font-weight:600}
.bi-filter-row input{width:100%;box-sizing:border-box;border:1px solid #cfcfcf;padding:7px 9px;font-size:13px}
.bi-filter-action{padding-bottom:1px}
.bi-empty{padding:14px;border:1px dashed #cfcfcf;background:#fafafa;color:#666}
.bi-pre{white-space:pre-wrap;margin:0;font-size:12px;line-height:1.4;max-width:100%;overflow:auto}
.bi-metrics{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px}
.bi-metric{padding:12px;background:#f7f7f7;border:1px solid #dedede;border-radius:4px}
.bi-metric span{display:block;font-size:12px;color:#777;margin-bottom:6px}
.bi-metric strong{font-size:20px;font-weight:600;color:#343434}
.bi-status{display:inline-block;padding:3px 8px;border-radius:999px;font-size:12px}
.bi-status.is-success{background:#e6f7ea;color:#2d6b36}
.bi-status.is-danger{background:#fdeaea;color:#9d3c3c}
.bi-note{margin-top:12px;padding:10px 12px;border-radius:4px}
.bi-note-warning{background:#fff6db;color:#9a6a00;border:1px solid #efd48a}
.bi-form-intro{margin-bottom:4px;padding:10px 12px;background:#f8f8f8;border:1px solid #e1e1e1;border-radius:4px}
.bi-hero-copy{margin-bottom:16px}
.bi-hero-copy h2,.bi-form-intro h2{margin:0 0 4px;font-size:16px;font-weight:600;color:#2f2f2f}
.bi-hero-copy h3{margin:16px 0 8px;font-size:15px;color:#4b4b4b}
.bi-hero-copy p,.bi-form-intro p{margin:0;color:#676767;font-size:13px;line-height:1.45}
.bi-feature-list{margin:0 0 14px 18px;padding:0;color:#505050}
.bi-feature-list li{margin:0 0 8px}
.bi-signature{font-size:14px;color:#6e6e6e}
@media (max-width: 1100px){
  .bi-shell{flex-direction:column}
  .bi-sidebar{width:auto}
  .bi-form-grid{grid-template-columns:1fr}
  .bi-form-actions{grid-column:auto}
  .bi-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}
  .bi-filter-row{grid-template-columns:1fr}
  .bi-brand{align-items:flex-start;flex-direction:column}
}
CSS;
}
