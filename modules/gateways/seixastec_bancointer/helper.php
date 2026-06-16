<?php
/**
 * Banco Inter WHMCS Gateway — Shared helpers.
 *
 * Centralises DB schema bootstrap, transaction persistence, formatting,
 * financial-rule application and logging so the gateway module, hooks and
 * callback handler share one source of truth.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

class BancoInterHelper
{
    public const TABLE = "mod_seixastec_bancointer_transactions";
    public const LOG_GATEWAY = "seixastec_bancointer";
    public const GATEWAY_MODULE = "seixastec_bancointer";
    public const CHARGE_DESC_MULTA = "Multa por atraso (Banco Inter)";
    public const CHARGE_DESC_JUROS = "Juros de mora (Banco Inter)";
    // Local synonyms (PENDING/CREATED/PROCESSING) + situações reais da API cobrança v3 do Inter.
    private const NON_TERMINAL_STATUSES = ["PENDING", "CREATED", "PROCESSING", "A_RECEBER", "EM_PROCESSAMENTO", "ATRASADO", "VENCIDO"];
    public const TERMINAL_CANCELLED_STATUSES = ["CANCELLED", "EXPIRED", "CANCELADO", "EXPIRADO"];
    public const TERMINAL_PAID_STATUSES = ["PAID", "RECEBIDO", "MARCADO_RECEBIDO"];

    // Persistent cache keys for OAuth access tokens (to avoid excessive calls to /oauth/v2/token and 429 rate limits).
    private const OAUTH_TOKEN_SETTING = "seixastec_bancointer_oauth_token";
    private const OAUTH_EXPIRES_SETTING = "seixastec_bancointer_oauth_expires_at";

    /**
     * Create the transaction-tracking table on first use. Safe to call every
     * request — uses INFORMATION_SCHEMA, not CREATE TABLE IF NOT EXISTS, so
     * Capsule schema builder can track columns for later migrations.
     */
    public static function ensureSchema(): void
    {
        if (Capsule::schema()->hasTable(self::TABLE)) {
            self::ensureRefundColumns();
            self::ensureJurosMoraColumns();
            self::ensureChargesSyncedColumn();
            return;
        }

        Capsule::schema()->create(self::TABLE, function ($table) {
            $table->increments("id");
            $table->unsignedInteger("invoice_id")->index();
            $table->string("codigo_solicitacao", 100)->nullable()->index();
            $table->string("nosso_numero", 50)->nullable()->index();
            $table->string("seu_numero", 50)->nullable();
            $table->string("txid", 100)->nullable()->index();
            $table->string("e2e_id", 100)->nullable()->index();
            $table->text("pix_copia_cola")->nullable();
            $table->mediumText("pix_qrcode_base64")->nullable();
            $table->string("linha_digitavel", 100)->nullable();
            $table->string("codigo_barras", 100)->nullable();
            $table->string("status", 30)->default("PENDING");
            $table->decimal("amount", 12, 2)->nullable();
            $table->decimal("paid_amount", 12, 2)->nullable();
            $table->date("due_date")->nullable();
            $table->dateTime("paid_at")->nullable();

            // Juros / multa rules snapshot (from gateway config at creation time) + received breakdown (populated on payment).
            $table->decimal("multa_taxa", 8, 4)->nullable();
            $table->decimal("mora_taxa", 8, 4)->nullable();
            $table->string("mora_codigo", 20)->nullable();
            $table->decimal("paid_juros", 12, 2)->nullable();
            $table->decimal("paid_multa", 12, 2)->nullable();
            $table->decimal("paid_desconto", 12, 2)->nullable();

            $table->string("refund_id", 35)->nullable()->index();
            $table->string("refund_status", 30)->nullable();
            $table->decimal("refund_amount", 12, 2)->nullable();
            $table->mediumText("refund_raw_response")->nullable();
            $table->dateTime("refunded_at")->nullable();
            $table->mediumText("raw_request")->nullable();
            $table->mediumText("raw_response")->nullable();
            $table->timestamps();
        });

        // Ensure additive columns even for brand-new table creation (in case create()
        // closure lags behind in a given deployment).
        self::ensureRefundColumns();
        self::ensureJurosMoraColumns();
        self::ensureChargesSyncedColumn();
    }

    private static function ensureChargesSyncedColumn(): void
    {
        if (!Capsule::schema()->hasColumn(self::TABLE, "charges_synced_at")) {
            Capsule::schema()->table(self::TABLE, function ($table) {
                $table->dateTime("charges_synced_at")->nullable();
            });
        }
    }

    private static function ensureRefundColumns(): void
    {
        $columns = [
            "refund_id" => function ($table) {
                $table->string("refund_id", 35)->nullable()->index();
            },
            "refund_status" => function ($table) {
                $table->string("refund_status", 30)->nullable();
            },
            "refund_amount" => function ($table) {
                $table->decimal("refund_amount", 12, 2)->nullable();
            },
            "refund_raw_response" => function ($table) {
                $table->mediumText("refund_raw_response")->nullable();
            },
            "refunded_at" => function ($table) {
                $table->dateTime("refunded_at")->nullable();
            },
        ];

        foreach ($columns as $column => $definition) {
            if (!Capsule::schema()->hasColumn(self::TABLE, $column)) {
                Capsule::schema()->table(self::TABLE, $definition);
            }
        }
    }

    /**
     * Additive migration for juros/mora/multa snapshot + received breakdown columns.
     * Safe to call repeatedly. Mirrors the pattern used for refund_* columns.
     */
    private static function ensureJurosMoraColumns(): void
    {
        $columns = [
            "multa_taxa" => function ($table) {
                $table->decimal("multa_taxa", 8, 4)->nullable();
            },
            "mora_taxa" => function ($table) {
                $table->decimal("mora_taxa", 8, 4)->nullable();
            },
            "mora_codigo" => function ($table) {
                $table->string("mora_codigo", 20)->nullable();
            },
            "paid_juros" => function ($table) {
                $table->decimal("paid_juros", 12, 2)->nullable();
            },
            "paid_multa" => function ($table) {
                $table->decimal("paid_multa", 12, 2)->nullable();
            },
            "paid_desconto" => function ($table) {
                $table->decimal("paid_desconto", 12, 2)->nullable();
            },
        ];

        foreach ($columns as $column => $definition) {
            if (!Capsule::schema()->hasColumn(self::TABLE, $column)) {
                Capsule::schema()->table(self::TABLE, $definition);
            }
        }
    }

    /** Fetch the latest transaction row for a given invoice. */
    public static function findByInvoice(int $invoiceId): ?object
    {
        self::ensureSchema();

        $row = Capsule::table(self::TABLE)
            ->where("invoice_id", $invoiceId)
            ->orderBy("id", "desc")
            ->first();

        return $row ?: null;
    }

    /** Fetch the latest still-reusable cobrança row for an invoice. */
    public static function findActiveByInvoice(int $invoiceId): ?object
    {
        self::ensureSchema();

        $row = Capsule::table(self::TABLE)
            ->where("invoice_id", $invoiceId)
            ->whereNotNull("codigo_solicitacao")
            ->whereNotIn("status", array_merge(self::TERMINAL_PAID_STATUSES, self::TERMINAL_CANCELLED_STATUSES))
            ->orderBy("id", "desc")
            ->first();

        return $row ?: null;
    }

    public static function findByCodigoSolicitacao(string $codigoSolicitacao): ?object
    {
        self::ensureSchema();

        $row = Capsule::table(self::TABLE)
            ->where("codigo_solicitacao", $codigoSolicitacao)
            ->first();

        return $row ?: null;
    }

    public static function findByTxid(string $txid): ?object
    {
        self::ensureSchema();

        $row = Capsule::table(self::TABLE)
            ->where("txid", $txid)
            ->orWhere("e2e_id", $txid)
            ->orWhere("nosso_numero", $txid)
            ->first();

        return $row ?: null;
    }

    /**
     * Upsert a transaction row keyed by invoice_id + codigo_solicitacao.
     * Returns the persisted row id.
     */
    public static function saveTransaction(array $data): int
    {
        self::ensureSchema();

        $invoiceId = (int) ($data["invoice_id"] ?? 0);
        if ($invoiceId <= 0) {
            throw new InvalidArgumentException("invoice_id is required");
        }

        $now = date("Y-m-d H:i:s");
        $data["updated_at"] = $now;

        $existing = null;
        if (!empty($data["id"])) {
            $existing = Capsule::table(self::TABLE)
                ->where("id", (int) $data["id"])
                ->first();
            unset($data["id"]);
        }
        if (!$existing && !empty($data["codigo_solicitacao"])) {
            $existing = self::findByCodigoSolicitacao($data["codigo_solicitacao"]);
        }
        if (!$existing && empty($data["codigo_solicitacao"])) {
            $existing = Capsule::table(self::TABLE)
                ->where("invoice_id", $invoiceId)
                ->orderBy("id", "desc")
                ->first();
        }

        if ($existing) {
            Capsule::table(self::TABLE)
                ->where("id", $existing->id)
                ->update($data);
            return (int) $existing->id;
        }

        $data["created_at"] = $now;
        return (int) Capsule::table(self::TABLE)->insertGetId($data);
    }

    public static function markPaid(int $rowId, float $amount, ?string $paidAt = null): void
    {
        self::ensureSchema();

        Capsule::table(self::TABLE)
            ->where("id", $rowId)
            ->update([
                "status" => "PAID",
                "paid_amount" => $amount,
                "paid_at" => $paidAt ?: date("Y-m-d H:i:s"),
                "updated_at" => date("Y-m-d H:i:s"),
            ]);
    }

    /** Strip all non-digits from CPF/CNPJ. */
    public static function onlyDigits(?string $value): string
    {
        return preg_replace("/\D+/", "", (string) $value);
    }

    public static function classifyDocument(string $digits): string
    {
        return strlen($digits) === 14 ? "JURIDICA" : "FISICA";
    }

    /**
     * Resolve the invoice client's CPF/CNPJ from the configured custom field.
     * Falls back to the client tax_id when the custom field is empty.
     */
    public static function resolveClientDocument(int $userId, ?string $customFieldId): string
    {
        $digits = "";

        // Defesa: só aceita IDs numéricos. Valores corrompidos (hash, string) caem no fallback.
        if (!empty($customFieldId) && ctype_digit((string) $customFieldId)) {
            $row = Capsule::table("tblcustomfieldsvalues")
                ->where("fieldid", (int) $customFieldId)
                ->where("relid", $userId)
                ->first();
            if ($row && !empty($row->value)) {
                $digits = self::onlyDigits($row->value);
            }
        }

        if ($digits === "") {
            $client = Capsule::table("tblclients")->where("id", $userId)->first();
            if ($client) {
                $digits = self::onlyDigits($client->tax_id ?? "");
            }
        }

        return $digits;
    }

    /**
     * Translate gateway params into the discount/interest/fine block expected
     * by the Banco Inter cobrança v3 API.
     *
     * IMPORTANT (juros/mora semantics):
     * - "mora" uses codigo=TAXAMENSAL + taxa=monthly percentage points (e.g. 1 for 1% a.m.).
     * - The bank applies the mora automatically after dataVencimento and typically prorates
     *   the monthly taxa by days (commonly /30). The exact proration (30/360, actual/365 etc.)
     *   is controlled by the bank and visible on the emitted boleto PDF.
     * - "multa" is a one-time PERCENTUAL applied after vencimento.
     * - "taxa" values are the human-facing percentage numbers (2.0 = 2%), NOT the 0.02 fraction.
     * - We deliberately keep the wire shape identical to previous versions for compatibility.
     *
     * Callers that want to snapshot the source percentages for auditing should read
     * $params["multa_pct"] / $params["juros_pct"] themselves around the call site
     * (see generateForInvoice).
     */
    public static function buildChargeOptions(array $params): array
    {
        $options = [];

        $multaPct = (float) ($params["multa_pct"] ?? 0);
        if ($multaPct > 0) {
            $options["multa"] = [
                "codigo" => "PERCENTUAL",
                "taxa" => round($multaPct, 4),
            ];
        }

        $jurosPct = (float) ($params["juros_pct"] ?? 0);
        if ($jurosPct > 0) {
            $options["mora"] = [
                "codigo" => "TAXAMENSAL",
                "taxa" => round($jurosPct, 4),
            ];
        }

        $descontoPct = (float) ($params["desconto_pct"] ?? 0);
        $descontoFixo = (float) ($params["desconto_fixo"] ?? 0);
        $descontoDias = (int) ($params["desconto_dias"] ?? 0);

        if (($descontoPct > 0 || $descontoFixo > 0) && $descontoDias > 0) {
            $dataLimite = date("Y-m-d", strtotime(($params["due_date"] ?? "today") . " -{$descontoDias} days"));
            $options["desconto1"] = [
                "codigo" => $descontoPct > 0 ? "PERCENTUALDATAINFORMADA" : "VALORFIXODATAINFORMADA",
                "data" => $dataLimite,
            ];
            
            if ($descontoPct > 0) {
                $options["desconto1"]["taxa"] = round($descontoPct, 4);
            } else {
                $options["desconto1"]["valor"] = round($descontoFixo, 2);
            }
        }

        return $options;
    }

    /**
     * Approximate accrued multa + mora for a given nominal + days late.
     * Uses simple linear proration (monthly / 30). This is for UI preview / admin
     * visibility only — the authoritative amount is always the one computed by
     * Banco Inter and shown on the boleto PDF / at settlement time.
     *
     * Returns: ['multa' => float, 'juros' => float, 'total_accrued' => float, 'note' => string]
     */
    public static function estimateAccrued(float $nominal, float $moraMensalPct, int $daysLate, float $multaPct = 0): array
    {
        $nominal = max(0, $nominal);
        $daysLate = max(0, $daysLate);
        $multa = $multaPct > 0 ? round($nominal * ($multaPct / 100), 2) : 0.0;

        $dailyFactor = $moraMensalPct > 0 ? ($moraMensalPct / 100) / 30 : 0;
        $juros = $nominal * $dailyFactor * $daysLate;
        $juros = round($juros, 2);

        return [
            "multa" => $multa,
            "juros" => $juros,
            "total_accrued" => round($multa + $juros, 2),
            "note" => "Aproximado (taxa mensal prorrateada /30). O boleto/PDF do Banco Inter é a fonte oficial.",
        ];
    }

    /**
     * Best-effort extraction of payment breakdown from a webhook event or a
     * getCollection() response. Inter may include explicit component values on
     * settlement events (valorJuros, valorMulta etc.) or under nested keys.
     *
     * Falls back gracefully; the sum of components is not guaranteed to equal
     * the total received (tarifas, other adjustments etc. may exist).
     */
    public static function parsePaymentBreakdown(array $payload): array
    {
        $get = function (array $paths) use ($payload) {
            foreach ($paths as $p) {
                $v = $payload;
                foreach (explode(".", $p) as $seg) {
                    if (!is_array($v) || !array_key_exists($seg, $v)) {
                        $v = null;
                        break;
                    }
                    $v = $v[$seg];
                }
                if ($v !== null && $v !== "") {
                    return is_numeric($v) ? (float) $v : null;
                }
            }
            return null;
        };

        $principal = $get(["valorNominal", "cobranca.valorNominal", "boleto.valorNominal"]) ?? null;
        $juros     = $get(["valorJuros", "juros", "mora", "cobranca.juros", "pix.juros"]) ?? null;
        $multa     = $get(["valorMulta", "multa", "cobranca.multa", "pix.multa"]) ?? null;
        $desconto  = $get(["valorDesconto", "desconto", "cobranca.desconto", "pix.desconto"]) ?? null;
        $tarifa    = $get(["valorTarifa", "tarifa", "pix.valorTarifa"]) ?? null;
        $total     = $get([
            "valorTotalRecebimento", "valorPago", "valorRecebido",
            "valor", "amount", "pix.valor", "pix.valorPago", "pix.amount"
        ]) ?? null;

        return [
            "principal" => $principal,
            "juros"     => $juros,
            "multa"     => $multa,
            "desconto"  => $desconto,
            "tarifa"    => $tarifa,
            "total"     => $total,
        ];
    }

    /** Persist a line into WHMCS's gateway log without mirroring secrets. */
    public static function log(string $action, $request, $response): void
    {
        if (function_exists("logModuleCall")) {
            $sanitized = self::sanitizeForLog($request);
            logModuleCall(self::LOG_GATEWAY, $action, $sanitized, self::sanitizeForLog($response));
        }
    }

    private static function sanitizeForLog($payload)
    {
        if (is_array($payload)) {
            $sanitized = [];
            foreach ($payload as $key => $value) {
                $lowerKey = strtolower((string) $key);
                if (in_array($lowerKey, [
                    "client_secret",
                    "clientsecret",
                    "cert",
                    "key",
                    "authorization",
                    "cpfcnpj",
                    "email",
                    "pixcopiaecola",
                    "linhadigitavel",
                    "codigobarras",
                    "body",
                    "rawbody",
                ], true)) {
                    $sanitized[$key] = "***";
                    continue;
                }

                $sanitized[$key] = self::sanitizeForLog($value);
            }
            return $sanitized;
        }

        if (is_object($payload)) {
            return self::sanitizeForLog((array) $payload);
        }

        if (is_string($payload) && strlen($payload) > 1000) {
            return mb_substr($payload, 0, 1000) . "...";
        }

        return $payload;
    }

    /** Build the canonical callback URL WHMCS expects to receive webhooks on. */
    public static function callbackUrl(string $systemUrl, ?string $secret = null): string
    {
        $base = rtrim($systemUrl, "/");
        $url = $base . "/modules/gateways/callback/seixastec_bancointer.php";
        if ($secret !== null && $secret !== "") {
            $url .= "?token=" . rawurlencode($secret);
        }
        return $url;
    }

    public static function isPaidStatus(?string $status): bool
    {
        return in_array(strtoupper((string) $status), self::TERMINAL_PAID_STATUSES, true);
    }

    public static function isReusableStatus(?string $status): bool
    {
        return in_array(strtoupper((string) $status), self::NON_TERMINAL_STATUSES, true);
    }

    public static function issueCsrfToken(string $scope): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return "";
        }

        if (empty($_SESSION["seixastec_bancointer_csrf"][$scope])) {
            $_SESSION["seixastec_bancointer_csrf"][$scope] = bin2hex(random_bytes(16));
        }

        return (string) $_SESSION["seixastec_bancointer_csrf"][$scope];
    }

    public static function validateCsrfToken(string $scope, ?string $token): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $expected = $_SESSION["seixastec_bancointer_csrf"][$scope] ?? null;
        $isValid = is_string($expected) && is_string($token) && hash_equals($expected, $token);
        if ($isValid) {
            unset($_SESSION["seixastec_bancointer_csrf"][$scope]);
        }

        return $isValid;
    }

    public static function systemUrl(): string
    {
        $url = (string) Capsule::table("tblconfiguration")->where("setting", "SystemURL")->value("value");

        if ($url !== '') {
            return rtrim($url, "/");
        }

        // Fallbacks (useful when SystemURL is not yet saved in the DB)
        if (!empty($_SERVER['REQUEST_SCHEME']) && !empty($_SERVER['HTTP_HOST'])) {
            $scheme = $_SERVER['REQUEST_SCHEME'];
            $host = $_SERVER['HTTP_HOST'];
            $script = $_SERVER['SCRIPT_NAME'] ?? '';
            // Try to guess the WHMCS root (strip /admin or /modules etc.)
            $base = preg_replace('#/(admin|modules|includes).*$#', '', $script);
            if ($base === '' || $base === $script) {
                $base = '/';
            }
            return rtrim($scheme . '://' . $host . $base, '/');
        }

        if (!empty($_SERVER['HTTP_HOST'])) {
            return 'https://' . $_SERVER['HTTP_HOST'];
        }

        return '';
    }

    /**
     * Returns a reliable absolute URL to the rich admin panel (tools.php via the stable admin.php shim).
     * Used to embed the unified configuration (multa/juros, webhook, simulador, extrato...) inside
     * the native gateway config page or in the addon.
     *
     * Tries official SystemURL first. Falls back to reconstructing from current request
     * ($_SERVER) so the panel link/iframe works even if SystemURL is not yet saved in tblconfiguration.
     */
    public static function adminPanelUrl(string $view = "config"): string
    {
        // Direct to tools.php (the rich panel renderer). admin.php is just a thin stable require shim if needed.
        $path = "/modules/gateways/seixastec_bancointer/tools.php?view=" . urlencode($view);

        $base = self::systemUrl();
        if ($base !== "") {
            return rtrim($base, "/") . $path;
        }

        // Robust absolute URL from the current admin request context (when SystemURL is empty).
        $isHttps = (!empty($_SERVER["HTTPS"]) && strtolower((string) $_SERVER["HTTPS"]) !== "off")
            || (isset($_SERVER["SERVER_PORT"]) && (string) $_SERVER["SERVER_PORT"] === "443");
        $scheme = $isHttps ? "https" : "http";
        $host = $_SERVER["HTTP_HOST"] ?? ($_SERVER["SERVER_NAME"] ?? "localhost");

        $script = (string) ($_SERVER["SCRIPT_NAME"] ?? "");
        $prefix = "";
        if (preg_match("#^(.*)/(admin|modules|includes)(/|$)#i", $script, $m)) {
            $prefix = $m[1];
        }

        return $scheme . "://" . $host . $prefix . $path;
    }

    public static function upsertGatewaySetting(string $gateway, string $setting, string $value): void
    {
        $updated = Capsule::table("tblpaymentgateways")
            ->where("gateway", $gateway)
            ->where("setting", $setting)
            ->update(["value" => $value]);

        if (!$updated) {
            Capsule::table("tblpaymentgateways")->insert([
                "gateway" => $gateway,
                "setting" => $setting,
                "value" => $value,
            ]);
        }
    }

    public static function ensureWebhookSecret(string $gateway = "seixastec_bancointer"): string
    {
        $existing = trim((string) Capsule::table("tblpaymentgateways")
            ->where("gateway", $gateway)
            ->where("setting", "webhook_secret")
            ->value("value"));

        if ($existing !== "") {
            return $existing;
        }

        $secret = bin2hex(random_bytes(24));
        self::upsertGatewaySetting($gateway, "webhook_secret", $secret);
        self::upsertGatewaySetting($gateway, "webhook_secret_created_at", date("Y-m-d H:i:s"));

        return $secret;
    }

    public static function rotateWebhookSecret(string $gateway = "seixastec_bancointer"): string
    {
        $secret = bin2hex(random_bytes(24));
        self::upsertGatewaySetting($gateway, "webhook_secret", $secret);
        self::upsertGatewaySetting($gateway, "webhook_secret_created_at", date("Y-m-d H:i:s"));

        return $secret;
    }

    /**
     * Retrieve a still-valid cached OAuth access token from persistent storage (tblconfiguration).
     * Returns null if no token or if it is expired/expiring soon.
     */
    public static function getCachedOAuthToken(): ?array
    {
        $token = Capsule::table("tblconfiguration")
            ->where("setting", self::OAUTH_TOKEN_SETTING)
            ->value("value");

        $expiresAt = Capsule::table("tblconfiguration")
            ->where("setting", self::OAUTH_EXPIRES_SETTING)
            ->value("value");

        if ($token && $expiresAt && (int) $expiresAt > time() + 30) {
            return [
                "access_token" => (string) $token,
                "expires_at" => (int) $expiresAt,
            ];
        }

        return null;
    }

    /**
     * Persist a freshly obtained OAuth access token so other PHP requests (page loads, crons, etc.)
     * can reuse it without hitting /oauth/v2/token again (prevents 429 rate limits on the token endpoint).
     */
    public static function storeOAuthToken(string $accessToken, int $expiresAt): void
    {
        self::upsertModuleSetting(self::OAUTH_TOKEN_SETTING, $accessToken);
        self::upsertModuleSetting(self::OAUTH_EXPIRES_SETTING, (string) $expiresAt);
    }

    /**
     * Clears any cached OAuth token. Useful as emergency button when hitting 429
     * rate limits on the token endpoint (forces a fresh token on next API call).
     */
    public static function clearOAuthTokenCache(): void
    {
        Capsule::table("tblconfiguration")
            ->whereIn("setting", [self::OAUTH_TOKEN_SETTING, self::OAUTH_EXPIRES_SETTING])
            ->delete();
    }

    /**
     * Generic upsert for module-level transient settings in tblconfiguration.
     * Used for OAuth token caching (not to be confused with gateway-specific settings).
     */
    private static function upsertModuleSetting(string $setting, string $value): void
    {
        $updated = Capsule::table("tblconfiguration")
            ->where("setting", $setting)
            ->update(["value" => $value]);

        if (!$updated) {
            Capsule::table("tblconfiguration")->insert([
                "setting" => $setting,
                "value" => $value,
            ]);
        }
    }

    public static function isBancoInterInvoice(int $invoiceId): bool
    {
        if ($invoiceId <= 0) {
            return false;
        }

        $invoice = Capsule::table("tblinvoices")->where("id", $invoiceId)->first();

        return $invoice
            && strtolower((string) ($invoice->paymentmethod ?? "")) === self::GATEWAY_MODULE;
    }

    /**
     * Locate WHMCS-native late-fee rows (items + ledger accounts), excluding
     * charges we add after Banco Inter settlement.
     *
     * @return array{items: int[], accounts: int[]}
     */
    public static function findWhmcsLateFeeEntries(int $invoiceId): array
    {
        $itemIds = [];
        $accountIds = [];

        $items = Capsule::table("tblinvoiceitems")
            ->where("invoiceid", $invoiceId)
            ->get();

        foreach ($items as $item) {
            if (self::isBancoInterChargeItem((string) ($item->description ?? ""))) {
                continue;
            }
            if (self::isWhmcsLateFeeDescription((string) ($item->description ?? ""), (string) ($item->type ?? ""))) {
                $itemIds[] = (int) $item->id;
            }
        }

        if (Capsule::schema()->hasTable("tblaccounts")) {
            $accounts = Capsule::table("tblaccounts")
                ->where("invoiceid", $invoiceId)
                ->get();

            foreach ($accounts as $account) {
                if (self::isWhmcsLateFeeAccountDescription((string) ($account->description ?? ""))) {
                    $accountIds[] = (int) $account->id;
                }
            }
        }

        return ["items" => $itemIds, "accounts" => $accountIds];
    }

    public static function removeWhmcsLateFeeEntries(int $invoiceId): bool
    {
        if (!self::isBancoInterInvoice($invoiceId)) {
            return false;
        }

        $entries = self::findWhmcsLateFeeEntries($invoiceId);
        if ($entries["items"] === [] && $entries["accounts"] === []) {
            return false;
        }

        if ($entries["items"] !== []) {
            Capsule::table("tblinvoiceitems")->whereIn("id", $entries["items"])->delete();
        }

        if ($entries["accounts"] !== []) {
            Capsule::table("tblaccounts")->whereIn("id", $entries["accounts"])->delete();
        }

        self::recalculateInvoiceTotal($invoiceId);

        self::log("late_fee.removed", [
            "invoice_id" => $invoiceId,
            "item_ids" => $entries["items"],
            "account_ids" => $entries["accounts"],
        ], "WHMCS late fee entries removed for Banco Inter invoice");

        if (function_exists("logActivity")) {
            logActivity("Banco Inter: removidos ajustes de late fee WHMCS da fatura #{$invoiceId}");
        }

        return true;
    }

    /**
     * Batch cleanup for unpaid Banco Inter invoices that still carry WHMCS late fees.
     */
    public static function cleanupWhmcsLateFeesBatch(int $limit = 50): int
    {
        $invoiceIds = Capsule::table("tblinvoices")
            ->where("paymentmethod", self::GATEWAY_MODULE)
            ->where("status", "Unpaid")
            ->orderBy("id", "asc")
            ->limit($limit)
            ->pluck("id")
            ->all();

        $cleaned = 0;
        foreach ($invoiceIds as $invoiceId) {
            if (self::removeWhmcsLateFeeEntries((int) $invoiceId)) {
                $cleaned++;
            }
        }

        return $cleaned;
    }

    /**
     * Add multa/juros line items from bank settlement, then recalculate invoice total.
     * Idempotent: skips when charges_synced_at is set or matching items already exist.
     */
    public static function applyReceivedChargesToInvoice(int $invoiceId, float $multa, float $juros, ?int $transactionRowId = null): bool
    {
        if (!self::isBancoInterInvoice($invoiceId)) {
            return false;
        }

        $multa = round(max(0, $multa), 2);
        $juros = round(max(0, $juros), 2);

        if ($multa < 0.01 && $juros < 0.01) {
            return false;
        }

        if ($transactionRowId !== null) {
            self::ensureSchema();
            $tx = Capsule::table(self::TABLE)->where("id", $transactionRowId)->first();
            if ($tx && !empty($tx->charges_synced_at)) {
                return false;
            }
        }

        $invoice = Capsule::table("tblinvoices")->where("id", $invoiceId)->first();
        if (!$invoice || (string) $invoice->status === "Paid") {
            return false;
        }

        $dueDate = ($invoice->duedate ?? null) && $invoice->duedate !== "0000-00-00"
            ? (string) $invoice->duedate
            : date("Y-m-d");

        $added = false;

        if ($multa >= 0.01 && !self::hasChargeItem($invoiceId, self::CHARGE_DESC_MULTA)) {
            Capsule::table("tblinvoiceitems")->insert([
                "invoiceid" => $invoiceId,
                "userid" => (int) $invoice->userid,
                "type" => "",
                "relid" => 0,
                "description" => self::CHARGE_DESC_MULTA,
                "amount" => $multa,
                "taxed" => 0,
                "duedate" => $dueDate,
                "paymentmethod" => (string) $invoice->paymentmethod,
                "notes" => "",
            ]);
            $added = true;
        }

        if ($juros >= 0.01 && !self::hasChargeItem($invoiceId, self::CHARGE_DESC_JUROS)) {
            Capsule::table("tblinvoiceitems")->insert([
                "invoiceid" => $invoiceId,
                "userid" => (int) $invoice->userid,
                "type" => "",
                "relid" => 0,
                "description" => self::CHARGE_DESC_JUROS,
                "amount" => $juros,
                "taxed" => 0,
                "duedate" => $dueDate,
                "paymentmethod" => (string) $invoice->paymentmethod,
                "notes" => "",
            ]);
            $added = true;
        }

        if (!$added) {
            return false;
        }

        self::recalculateInvoiceTotal($invoiceId);

        if ($transactionRowId !== null) {
            Capsule::table(self::TABLE)
                ->where("id", $transactionRowId)
                ->update([
                    "charges_synced_at" => date("Y-m-d H:i:s"),
                    "updated_at" => date("Y-m-d H:i:s"),
                ]);
        }

        self::log("charges.applied", [
            "invoice_id" => $invoiceId,
            "multa" => $multa,
            "juros" => $juros,
        ], "Multa/juros do Banco Inter aplicados na fatura");

        return true;
    }

    public static function recalculateInvoiceTotal(int $invoiceId): void
    {
        if (!function_exists("updateInvoiceTotal")) {
            $path = defined("ROOTDIR") ? ROOTDIR . "/includes/invoicefunctions.php" : null;
            if ($path && is_file($path)) {
                require_once $path;
            }
        }

        if (function_exists("updateInvoiceTotal")) {
            updateInvoiceTotal($invoiceId);
        }
    }

    private static function hasChargeItem(int $invoiceId, string $description): bool
    {
        return Capsule::table("tblinvoiceitems")
            ->where("invoiceid", $invoiceId)
            ->where("description", $description)
            ->exists();
    }

    private static function isBancoInterChargeItem(string $description): bool
    {
        return str_contains($description, "(Banco Inter)");
    }

    private static function isWhmcsLateFeeDescription(string $description, string $type): bool
    {
        if (self::isBancoInterChargeItem($description)) {
            return false;
        }

        $typeNorm = strtoupper(trim($type));
        if ($typeNorm === "LATEFEE") {
            return true;
        }

        $lower = strtolower($description);

        return str_contains($lower, "late fee")
            || str_contains($lower, "debit note for late fee")
            || str_contains($lower, "taxa de atraso")
            || str_contains($lower, "multa por atraso")
            || str_contains($lower, "juros de mora")
            || str_contains($lower, "encargo por atraso");
    }

    private static function isWhmcsLateFeeAccountDescription(string $description): bool
    {
        $lower = strtolower($description);

        return str_contains($lower, "late fee")
            || str_contains($lower, "debit note for late fee")
            || str_contains($lower, "taxa de atraso");
    }
}
