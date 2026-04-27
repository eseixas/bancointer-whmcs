<?php
/**
 * Banco Inter API v3 client.
 *
 * - OAuth2 client credentials + mTLS (cert paths must sit outside public_html).
 * - Caches access tokens in-process for the life of the request.
 * - Surfaces cobrança v3 endpoints (create, get, cancel, PDF) and webhook
 *   management, returning decoded payloads and throwing on transport errors.
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

class BancoInterAPI
{
    public const BASE_URL = "https://cdpj.partners.bancointer.com.br";

    private const SCOPES = "boleto-cobranca.read boleto-cobranca.write webhook-banking.read webhook-banking.write";
    private const DEFAULT_TIMEOUT = 30;

    private string $clientId;
    private string $clientSecret;
    private string $certPath;
    private string $keyPath;
    private ?string $contaCorrente;
    private int $timeout;

    private ?array $tokenCache = null;

    public function __construct(array $config)
    {
        $this->clientId = trim($config["client_id"] ?? "");
        $this->clientSecret = trim($config["client_secret"] ?? "");
        $this->certPath = $this->normalizePath($config["cert_path"] ?? "");
        $this->keyPath = $this->normalizePath($config["key_path"] ?? "");
        $this->contaCorrente = !empty($config["conta_corrente"]) ? $config["conta_corrente"] : null;
        $this->timeout = (int) ($config["timeout"] ?? self::DEFAULT_TIMEOUT);

        $this->assertCredentials();
    }

    /* -------------------------------------------------- auth */

    public function getAccessToken(): string
    {
        if ($this->tokenCache && $this->tokenCache["expires_at"] > time() + 30) {
            return $this->tokenCache["access_token"];
        }

        $response = $this->request("POST", "/oauth/v2/token", [
            "grant_type" => "client_credentials",
            "client_id" => $this->clientId,
            "client_secret" => $this->clientSecret,
            "scope" => self::SCOPES,
        ], [
            "Content-Type: application/x-www-form-urlencoded",
        ], /* form */ true, /* skipAuth */ true);

        if (empty($response["access_token"])) {
            throw new RuntimeException("Banco Inter: authentication failed — missing access_token");
        }

        $this->tokenCache = [
            "access_token" => $response["access_token"],
            "expires_at" => time() + (int) ($response["expires_in"] ?? 3500),
        ];

        return $this->tokenCache["access_token"];
    }

    /* -------------------------------------------------- cobrança v3 */

    /**
     * Create a unified PIX + Boleto collection. Returns the response body
     * (typically `{ "codigoSolicitacao": "..." }`) plus the detail view.
     */
    public function createCollection(array $payload): array
    {
        $created = $this->request("POST", "/cobranca/v3/cobrancas", $payload);

        if (empty($created["codigoSolicitacao"])) {
            throw new RuntimeException("Banco Inter: collection create returned no codigoSolicitacao");
        }

        $detail = $this->getCollection($created["codigoSolicitacao"]);
        return array_merge(["codigoSolicitacao" => $created["codigoSolicitacao"]], $detail);
    }

    public function getCollection(string $codigoSolicitacao): array
    {
        return $this->request("GET", "/cobranca/v3/cobrancas/" . rawurlencode($codigoSolicitacao));
    }

    public function cancelCollection(string $codigoSolicitacao, string $motivo = "APEDIDODOCLIENTE"): array
    {
        return $this->request(
            "POST",
            "/cobranca/v3/cobrancas/" . rawurlencode($codigoSolicitacao) . "/cancelar",
            ["motivoCancelamento" => $motivo]
        );
    }

    /** Returns the PDF as raw bytes ready to stream to a browser or attach to an email. */
    public function getCollectionPdf(string $codigoSolicitacao): string
    {
        $response = $this->request(
            "GET",
            "/cobranca/v3/cobrancas/" . rawurlencode($codigoSolicitacao) . "/pdf"
        );

        $base64 = $response["pdf"] ?? ($response["base64"] ?? null);
        if (!$base64) {
            throw new RuntimeException("Banco Inter: PDF response missing base64 payload");
        }

        $decoded = base64_decode($base64, true);
        if ($decoded === false) {
            throw new RuntimeException("Banco Inter: PDF base64 decode failed");
        }

        return $decoded;
    }

    /* -------------------------------------------------- webhook */

    public function registerWebhook(string $url): array
    {
        return $this->request("PUT", "/cobranca/v3/cobrancas/webhook", [
            "webhookUrl" => $url,
        ]);
    }

    public function getWebhook(): array
    {
        return $this->request("GET", "/cobranca/v3/cobrancas/webhook");
    }

    public function deleteWebhook(): array
    {
        return $this->request("DELETE", "/cobranca/v3/cobrancas/webhook");
    }

    /* -------------------------------------------------- transport */

    private function request(
        string $method,
        string $path,
        array $payload = [],
        array $extraHeaders = [],
        bool $asForm = false,
        bool $skipAuth = false
    ): array {
        $url = self::BASE_URL . $path;

        $headers = array_merge([
            "Accept: application/json",
        ], $extraHeaders);

        if (!$skipAuth) {
            $headers[] = "Authorization: Bearer " . $this->getAccessToken();
        }

        if ($this->contaCorrente) {
            $headers[] = "x-conta-corrente: " . $this->contaCorrente;
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
        curl_setopt($ch, CURLOPT_SSLCERT, $this->certPath);
        curl_setopt($ch, CURLOPT_SSLKEY, $this->keyPath);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        if ($method !== "GET" && $method !== "DELETE") {
            if ($asForm) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
            } else {
                $headers[] = "Content-Type: application/json";
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(
                    $payload,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
                ));
            }
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        BancoInterHelper::log("API {$method} {$path}", [
            "payload" => $payload,
            "headers" => array_map(fn($h) => stripos($h, "Authorization") === 0 ? "Authorization: ***" : $h, $headers),
        ], [
            "status" => $status,
            "body" => is_string($raw) ? mb_substr($raw, 0, 8000) : null,
            "error" => $err ?: null,
        ]);

        if ($raw === false) {
            throw new RuntimeException("Banco Inter: transport error — {$err}");
        }

        $body = json_decode($raw, true);
        if ($status >= 400) {
            $message = is_array($body)
                ? ($body["message"] ?? $body["detail"] ?? $body["title"] ?? $raw)
                : $raw;

            // Inter devolve detalhes de validação em `violacoes`: [{propriedade, razao}, ...]
            if (is_array($body) && !empty($body["violacoes"]) && is_array($body["violacoes"])) {
                $details = [];
                foreach ($body["violacoes"] as $v) {
                    $prop = $v["propriedade"] ?? ($v["campo"] ?? "?");
                    $reason = $v["razao"] ?? ($v["mensagem"] ?? "invalid");
                    $details[] = "{$prop}: {$reason}";
                }
                if ($details) {
                    $message .= " — " . implode(" | ", $details);
                }
            }

            throw new RuntimeException("Banco Inter {$method} {$path} failed [{$status}]: {$message}");
        }

        return is_array($body) ? $body : [];
    }

    /* -------------------------------------------------- internals */

    private function assertCredentials(): void
    {
        foreach (["clientId" => $this->clientId, "clientSecret" => $this->clientSecret] as $name => $value) {
            if ($value === "") {
                throw new RuntimeException("Banco Inter: missing {$name}");
            }
        }

        foreach (["certPath" => $this->certPath, "keyPath" => $this->keyPath] as $name => $path) {
            if (!is_readable($path)) {
                throw new RuntimeException("Banco Inter: {$name} unreadable at '{$path}' — check absolute path and filesystem permissions");
            }
        }
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);
        if ($path === "") {
            return "";
        }
        if (DIRECTORY_SEPARATOR === "\\") {
            $path = str_replace("/", "\\", $path);
        }
        return $path;
    }
}
