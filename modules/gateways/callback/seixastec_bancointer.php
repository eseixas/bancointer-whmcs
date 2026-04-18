<?php

/**
 * Banco Inter - WHMCS Callback / Webhook Handler
 *
 * Handles: PDF download, Pix webhook, Cobrança V3 webhook.
 *
 * @author      Eduardo Seixas
 * @copyright   2026
 * @license     GPL-3.0
 */

declare(strict_types=1);

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

use Illuminate\Database\Capsule\Manager as Capsule;

$gatewayModuleName = 'seixastec_bancointer';
$gatewayParams     = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    die('Module Not Activated');
}

// ---------------------------------------------------------------------------
// Ação: Download do PDF do Boleto
// ---------------------------------------------------------------------------

if (isset($_GET['action']) && $_GET['action'] === 'download_pdf' && isset($_GET['invoiceid'])) {
    $invoiceId = (int)$_GET['invoiceid'];

    // Verifica se o cliente logado é dono da fatura (admins passam livremente)
    $isAdmin  = !empty($_SESSION['adminid']);
    $clientUid = (int)($_SESSION['uid'] ?? 0);
    if (!$isAdmin) {
        if ($clientUid === 0) {
            http_response_code(403);
            die('Acesso negado. Faça login para baixar o boleto.');
        }
        try {
            $inv = Capsule::table('tblinvoices')->where('id', $invoiceId)->first(['userid']);
        } catch (\Throwable) {
            $inv = null;
        }
        if (!$inv || (int)$inv->userid !== $clientUid) {
            http_response_code(403);
            die('Acesso negado.');
        }
    }

    if (!Capsule::schema()->hasTable('tb_seixastec_bancointer_tx')) {
        die('Tabela de controle não encontrada.');
    }

    $tx = Capsule::table('tb_seixastec_bancointer_tx')->where('invoice_id', $invoiceId)->first();
    if (!$tx || empty($tx->codigo_solicitacao)) {
        die('Fatura não encontrada ou cobrança ainda não gerada.');
    }

    require_once __DIR__ . '/../seixastec_bancointer.php';
    $token = seixastec_bancointer_oauth($gatewayParams);

    if (!$token) {
        die('Erro de autenticação com o Banco Inter.');
    }

    $req = seixastec_bancointer_api(
        $gatewayParams,
        $token,
        'GET',
        '/cobranca/v3/cobrancas/' . $tx->codigo_solicitacao . '/pdf'
    );

    if ($req['code'] === 200 && !empty($req['response']['pdf'])) {
        $pdfContent = base64_decode($req['response']['pdf']);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="Boleto_Fatura_' . $invoiceId . '.pdf"');
        header('Content-Length: ' . strlen($pdfContent));
        header('Cache-Control: no-store');
        echo $pdfContent;
        exit;
    }

    die('Não foi possível obter o PDF da cobrança. ' . json_encode($req['response']));
}

// ---------------------------------------------------------------------------
// Webhook Receiver: Pix V2 e Cobrança V3
// ---------------------------------------------------------------------------

// Verificação de token de segurança (configurável no painel do gateway)
$webhookSecret = trim((string)($gatewayParams['webhookSecret'] ?? ''));
if ($webhookSecret !== '') {
    $receivedToken = (string)($_GET['token'] ?? '');
    if (!hash_equals($webhookSecret, $receivedToken)) {
        logModuleCall($gatewayModuleName, 'webhook_unauthorized', $_SERVER['REMOTE_ADDR'] ?? '', 'Token inválido ou ausente');
        http_response_code(401);
        exit('Unauthorized');
    }
}

$body = (string)file_get_contents('php://input');
$data = json_decode($body, true);

logModuleCall($gatewayModuleName, 'webhook_received', $body, '');

if (empty($data) || !is_array($data)) {
    http_response_code(200);
    exit('OK');
}

/**
 * Registra o pagamento no WHMCS.
 */
function seixastec_bancointer_processPayment(
    int    $invoiceId,
    float  $amount,
    string $transId,
    array  $gatewayParams,
    string $gatewayModuleName
): void {
    $invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);
    checkCbTransID($transId);

    addInvoicePayment(
        $invoiceId,
        $transId,
        $amount,
        0,
        $gatewayModuleName
    );
}

// Payload Pix V2: { "pix": [ { "txid": "...", "valor": 0.00, ... } ] }
if (isset($data['pix']) && is_array($data['pix'])) {
    foreach ($data['pix'] as $pix) {
        $txid  = (string)($pix['txid'] ?? '');
        $valor = (float)($pix['valor'] ?? 0);

        if (empty($txid)) {
            continue;
        }

        $tx = Capsule::table('tb_seixastec_bancointer_tx')->where('txid', $txid)->first();
        if ($tx) {
            seixastec_bancointer_processPayment(
                (int)$tx->invoice_id,
                $valor,
                'PIX-' . $txid,
                $gatewayParams,
                $gatewayModuleName
            );
        }
    }
    http_response_code(200);
    exit('OK');
}

// Payload Cobrança V3: array de objetos com codigoSolicitacao e situacao
foreach ($data as $item) {
    if (!is_array($item) || empty($item['codigoSolicitacao'])) {
        continue;
    }

    $codigoSolicitacao = (string)$item['codigoSolicitacao'];
    $situacao          = (string)($item['situacao'] ?? '');
    $valorRecebido     = (float)($item['valorRecebido'] ?? $item['valorNominal'] ?? 0);

    if (!in_array($situacao, ['PAGO', 'RECEBIDO', 'LIQUIDADO'])) {
        continue;
    }

    $tx = Capsule::table('tb_seixastec_bancointer_tx')
        ->where('codigo_solicitacao', $codigoSolicitacao)
        ->first();

    if ($tx) {
        seixastec_bancointer_processPayment(
            (int)$tx->invoice_id,
            $valorRecebido,
            'COB-' . $codigoSolicitacao,
            $gatewayParams,
            $gatewayModuleName
        );
    }
}

http_response_code(200);
echo 'OK';
