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
    // Local synonyms (PENDING/CREATED/PROCESSING) + situações reais da API cobrança v3 do Inter.
    private const NON_TERMINAL_STATUSES = ["PENDING", "CREATED", "PROCESSING", "A_RECEBER", "EM_PROCESSAMENTO", "ATRASADO"];
    public const TERMINAL_CANCELLED_STATUSES = ["CANCELLED", "EXPIRED", "CANCELADO", "EXPIRADO"];
    public const TERMINAL_PAID_STATUSES = ["PAID", "RECEBIDO", "MARCADO_RECEBIDO"];

    /**
     * Create the transaction-tracking table on first use. Safe to call every
     * request — uses INFORMATION_SCHEMA, not CREATE TABLE IF NOT EXISTS, so
     * Capsule schema builder can track columns for later migrations.
     */
    public static function ensureSchema(): void
    {
        if (Capsule::schema()->hasTable(self::TABLE)) {
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
            $table->mediumText("raw_request")->nullable();
            $table->mediumText("raw_response")->nullable();
            $table->timestamps();
        });
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
        return is_string($expected) && is_string($token) && hash_equals($expected, $token);
    }

    public static function systemUrl(): string
    {
        return rtrim(
            (string) Capsule::table("tblconfiguration")->where("setting", "SystemURL")->value("value"),
            "/"
        );
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
}
