<?php
/**
 * Client-area helper for the Banco Inter gateway.
 *
 *   POST /?invoiceid=N           → create/refresh cobrança for the given invoice
 *   GET  /?action=pdf&invoiceid= → stream the boleto PDF (attachment download)
 *   GET  /?action=qr&invoiceid=  → stream PIX QR Code as PNG (rendered server-side)
 *   GET  /?action=qr_diag        → diagnose available QR libraries (admin only)
 *
 * Binary responses (qr, pdf) use a lightweight session check without
 * WHMCS\ClientArea so that no HTML headers or redirects pollute the output.
 * Ownership is verified against tblinvoices.userid so unrelated clients
 * cannot introspect or mutate each other's invoices.
 */

use WHMCS\Database\Capsule;

require_once __DIR__ . "/../../../init.php";
require_once __DIR__ . "/../seixastec_bancointer.php";

$action   = (string) ($_REQUEST["action"] ?? "generate");
$invoiceId = (int) ($_REQUEST["invoiceid"] ?? 0);

// ── Binary endpoints (qr / pdf / qr_diag) ──────────────────────────────────
// These must NOT instantiate WHMCS\ClientArea because initPage() may emit HTML
// headers or redirect to /login.php, breaking image/PDF responses.
// We verify the session manually — exactly how WHMCS callback handlers work.
if (in_array($action, ["qr", "pdf", "qr_diag"], true)) {

    $userId = isset($_SESSION["uid"]) ? (int) $_SESSION["uid"] : 0;
    $adminId = isset($_SESSION["adminid"]) ? (int) $_SESSION["adminid"] : 0;

    if ($userId <= 0 && $adminId <= 0) {
        http_response_code(403);
        header("Content-Type: text/plain; charset=utf-8");
        exit("Sessão expirada. Recarregue a fatura.");
    }

    $params = seixastec_bancointer_loadParams();
    if (!$params) {
        http_response_code(500);
        header("Content-Type: text/plain; charset=utf-8");
        exit("Banco Inter gateway is not active.");
    }

    try {
        if ($action === "qr_diag") {
            // Diagnóstico: reporta quais libs de QR estão disponíveis no runtime do WHMCS.
            header("Content-Type: text/plain; charset=utf-8");
            $checks = [
                "Endroid\\QrCode\\Builder\\Builder (v4)" => class_exists("Endroid\\QrCode\\Builder\\Builder"),
                "Endroid\\QrCode\\QrCode (v3)"           => class_exists("Endroid\\QrCode\\QrCode"),
                "chillerlan\\QRCode\\QRCode"              => class_exists("chillerlan\\QRCode\\QRCode"),
                "BaconQrCode\\Writer"                    => class_exists("BaconQrCode\\Writer"),
                "GD imagecreate()"                       => function_exists("imagecreate"),
                "GD imagepng()"                          => function_exists("imagepng"),
            ];
            foreach ($checks as $label => $ok) {
                echo str_pad($label, 45) . " : " . ($ok ? "SIM" : "não") . PHP_EOL;
            }
            echo PHP_EOL . "PHP version: " . PHP_VERSION . PHP_EOL;
            exit;
        }

        if ($action === "qr") {
            // Verify invoice ownership only when invoiceid is provided.
            if ($invoiceId > 0) {
                $invoice = Capsule::table("tblinvoices")->where("id", $invoiceId)->first();
                if (!$invoice || ($adminId <= 0 && (int) $invoice->userid !== $userId)) {
                    http_response_code(403);
                    header("Content-Type: text/plain; charset=utf-8");
                    exit("Forbidden");
                }
            }

            $tx = BancoInterHelper::findByInvoice($invoiceId);
            $tx = seixastec_bancointer_refreshCollectionIfNeeded($tx, $params, true);
            if (!$tx || empty($tx->pix_copia_cola)) {
                http_response_code(404);
                header("Content-Type: text/plain; charset=utf-8");
                exit("PIX copia-e-cola não disponível para esta fatura.");
            }

            try {
                $qrImage = seixastec_bancointer_renderPixQr((string) $tx->pix_copia_cola, 240);
            } catch (Throwable $qrError) {
                BancoInterHelper::log("generate.qr_failed", ["invoiceid" => $invoiceId], $qrError->getMessage());
                http_response_code(500);
                header("Content-Type: text/plain; charset=utf-8");
                exit("Erro ao processar QR Code Banco Inter.");
            }

            // Detect SVG vs PNG by inspecting the byte header.
            $mime = (strpos($qrImage, "<?xml") === 0 || strpos($qrImage, "<svg") !== false)
                ? "image/svg+xml"
                : "image/png";
            header("Content-Type: {$mime}");
            header("Cache-Control: private, max-age=300");
            header("Content-Length: " . strlen($qrImage));
            echo $qrImage;
            exit;
        }

        if ($action === "pdf") {
            $invoice = Capsule::table("tblinvoices")->where("id", $invoiceId)->first();
            if (!$invoice || ($adminId <= 0 && (int) $invoice->userid !== $userId)) {
                http_response_code(403);
                header("Content-Type: text/plain; charset=utf-8");
                exit("Forbidden");
            }

            $tx = BancoInterHelper::findByInvoice($invoiceId);
            if (!$tx || empty($tx->codigo_solicitacao)) {
                http_response_code(404);
                header("Content-Type: text/plain; charset=utf-8");
                exit("Cobrança não encontrada.");
            }

            $pdf = seixastec_bancointer_buildApi($params)->getCollectionPdf($tx->codigo_solicitacao);

            // 'attachment' forces the browser to download rather than navigate.
            header("Content-Type: application/pdf");
            header("Content-Disposition: attachment; filename=\"Boleto_Fatura_{$invoiceId}.pdf\"");
            header("Content-Length: " . strlen($pdf));
            header("Cache-Control: private, no-store");
            echo $pdf;
            exit;
        }
    } catch (Throwable $e) {
        BancoInterHelper::log("generate.php", ["invoiceid" => $invoiceId, "action" => $action], $e->getMessage());
        http_response_code(500);
        header("Content-Type: text/plain; charset=utf-8");
        echo "Erro ao processar a solicitacao Banco Inter.";
        exit;
    }
}

// ── POST generate (form submit) ─────────────────────────────────────────────
// Instantiating ClientArea here is fine: the response will be a redirect, not
// binary content.
$ca = new WHMCS\ClientArea();
$ca->initPage();

$adminId = isset($_SESSION["adminid"]) ? (int) $_SESSION["adminid"] : 0;

if (!$ca->isLoggedIn() && $adminId <= 0) {
    header("Location: " . rtrim(BancoInterHelper::systemUrl(), "/") . "/login.php");
    exit;
}

$userId = isset($_SESSION["uid"]) ? (int) $_SESSION["uid"] : 0;

$invoice = Capsule::table("tblinvoices")->where("id", $invoiceId)->first();
if (!$invoice || ($adminId <= 0 && (int) $invoice->userid !== $userId)) {
    http_response_code(403);
    exit("Forbidden");
}

if (strtolower((string) $invoice->paymentmethod) !== "seixastec_bancointer") {
    http_response_code(409);
    exit("Esta fatura não usa o gateway Banco Inter.");
}

$params = seixastec_bancointer_loadParams();
if (!$params) {
    http_response_code(500);
    exit("Banco Inter gateway is not active.");
}

try {
    if (strtoupper((string) ($_SERVER["REQUEST_METHOD"] ?? "")) !== "POST") {
        http_response_code(405);
        exit("Method Not Allowed");
    }

    if (!BancoInterHelper::validateCsrfToken("client_generate_invoice", $_POST["csrf_token"] ?? null)) {
        http_response_code(403);
        exit("Invalid request token.");
    }

    if (in_array(strtolower((string) $invoice->status), ["paid", "cancelled", "refunded", "collections"], true)) {
        http_response_code(409);
        exit("Esta fatura não aceita nova cobrança.");
    }

    seixastec_bancointer_generateForInvoice(
        (int) $invoice->id,
        (int) $invoice->userid,
        (float) $invoice->total,
        (string) $invoice->duedate,
        $params
    );
    header("Location: " . rtrim($params["systemurl"], "/") . "/viewinvoice.php?id=" . $invoiceId);
    exit;
} catch (Throwable $e) {
    BancoInterHelper::log("generate.php", ["invoiceid" => $invoiceId, "action" => $action], $e->getMessage());
    http_response_code(500);
    echo "<pre>Erro ao gerar cobrança Banco Inter:\n" . htmlspecialchars($e->getMessage()) . "</pre>";
}
