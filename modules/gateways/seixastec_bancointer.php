<?php

/**
 * Banco Inter - WHMCS Payment Gateway Module
 *
 * Supports: Boleto Bancário (PDF), PIX (QR Code + Copia e Cola), Cobrança Híbrida.
 *
 * Compatible with: WHMCS 9.x | PHP 8.3 | Banco Inter API Cobrança V3 + Pix V2
 *
 * @author      Eduardo Seixas
 * @copyright   2026
 * @license     GPL-3.0
 */

declare(strict_types=1);

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use Illuminate\Database\Capsule\Manager as Capsule;

// ---------------------------------------------------------------------------
// Metadata
// ---------------------------------------------------------------------------

function seixastec_bancointer_MetaData(): array
{
    return [
        'DisplayName'                 => 'Banco Inter - Boleto e Pix',
        'APIVersion'                  => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage'            => false,
    ];
}

// ---------------------------------------------------------------------------
// Configurações do Módulo
// ---------------------------------------------------------------------------

function seixastec_bancointer_config(): array
{
    $customFields = _seixastec_bancointer_getCustomFieldsDropdown();

    return [
        'FriendlyName' => [
            'Type'  => 'System',
            'Value' => 'Banco Inter - Boleto e Pix',
        ],

        // --- Credenciais ---
        'clientId' => [
            'FriendlyName' => 'Client ID',
            'Type'         => 'text',
            'Size'         => 60,
            'Description'  => 'Obtido no Portal do Desenvolvedor Inter Empresas.',
        ],
        'clientSecret' => [
            'FriendlyName' => 'Client Secret',
            'Type'         => 'password',
            'Size'         => 60,
        ],

        // --- Certificados mTLS ---
        'certPath' => [
            'FriendlyName' => 'Caminho do Certificado (.crt / .pem)',
            'Type'         => 'text',
            'Size'         => 100,
            'Description'  => 'Caminho absoluto no servidor (ex: /home/user/ssl/inter.crt). Mantenha fora do public_html.',
        ],
        'keyPath' => [
            'FriendlyName' => 'Caminho da Chave Privada (.key)',
            'Type'         => 'text',
            'Size'         => 100,
            'Description'  => 'Caminho absoluto no servidor (ex: /home/user/ssl/inter.key). Mantenha fora do public_html.',
        ],

        // --- Número da Conta ---
        'contaCorrente' => [
            'FriendlyName' => 'Número da Conta Corrente',
            'Type'         => 'text',
            'Size'         => 20,
            'Description'  => '<strong>Obrigatório.</strong> Número da conta corrente Inter PJ (apenas dígitos, sem zeros à esquerda e sem o dígito verificador separado por hífen). Ex: 12345678.',
        ],

        // --- Comportamento ---
        'emitirSempre' => [
            'FriendlyName' => 'Emitir cobrança para todas as faturas em aberto?',
            'Type'         => 'yesno',
            'Description'  => 'Se ativado, gera Boleto/Pix para qualquer fatura em aberto neste gateway. Também anexa o PDF do Boleto ao e-mail da fatura.',
        ],
        'validarCampos' => [
            'FriendlyName' => 'Validar campos obrigatórios antes de emitir?',
            'Type'         => 'yesno',
            'Description'  => 'Se ativado, verifica se CPF/CNPJ, CEP, endereço, cidade e UF estão preenchidos antes de gerar a cobrança.',
        ],

        // --- Dados do pagador ---
        'cpfCnpjFieldId' => [
            'FriendlyName' => 'Campo personalizado CPF/CNPJ',
            'Type'         => 'dropdown',
            'Options'      => $customFields,
            'Description'  => 'Selecione o campo personalizado do cliente que contém o CPF ou CNPJ.',
        ],

        // --- Escopos OAuth ---
        'oauthScope' => [
            'FriendlyName' => 'Escopos OAuth (opcional)',
            'Type'         => 'text',
            'Size'         => 100,
            'Default'      => '',
            'Description'  => 'Deixe <strong>vazio</strong> para usar todos os escopos registrados na sua aplicação no Portal Inter (recomendado). Ou informe manualmente separado por espaço, ex: <code>boleto-cobv.read boleto-cobv.write pix.read pix.write</code>',
        ],

        // --- Vencimento e encargos ---
        'diasVencimento' => [
            'FriendlyName' => 'Dias para vencimento',
            'Type'         => 'text',
            'Size'         => 5,
            'Default'      => '3',
            'Description'  => 'Dias adicionais para o vencimento quando a fatura já estiver vencida.',
        ],
        'multa' => [
            'FriendlyName' => 'Multa por atraso (%)',
            'Type'         => 'text',
            'Size'         => 5,
            'Default'      => '2',
            'Description'  => 'Percentual de multa (ex: 2 para 2%). Máx. 2% conforme CDC.',
        ],
        'mora' => [
            'FriendlyName' => 'Juros de mora mensal (%)',
            'Type'         => 'text',
            'Size'         => 5,
            'Default'      => '1',
            'Description'  => 'Percentual de juros mensais (ex: 1 para 1% a.m.).',
        ],

        // --- Webhook ---
        'webhookSecret' => [
            'FriendlyName' => 'Token de segurança do Webhook',
            'Type'         => 'text',
            'Size'         => 60,
            'Description'  => 'Defina um token secreto (ex: string aleatória longa). Cadastre a URL do webhook no Portal Inter incluindo <code>?token=SEU_TOKEN</code> no final. Se vazio, a verificação é desativada.',
        ],
    ];
}

// ---------------------------------------------------------------------------
// Helpers internos
// ---------------------------------------------------------------------------

/**
 * Cria a tabela de controle caso ainda não exista.
 * Chamada uma única vez por seixastec_bancointer_link(), não no _config().
 */
function _seixastec_bancointer_ensureTable(): void
{
    if (!Capsule::schema()->hasTable('tb_seixastec_bancointer_tx')) {
        Capsule::schema()->create('tb_seixastec_bancointer_tx', function ($table) {
            $table->increments('id');
            $table->integer('invoice_id')->unique();
            $table->string('codigo_solicitacao', 100)->nullable();
            $table->string('txid', 100)->nullable();
            $table->string('nosso_numero', 100)->nullable();
            $table->timestamps();
        });
    }
}

/**
 * Retorna todos os campos personalizados de cliente para o dropdown de config.
 */
function _seixastec_bancointer_getCustomFieldsDropdown(): string
{
    try {
        $fields = Capsule::table('tblcustomfields')
            ->where('type', 'client')
            ->orderBy('fieldname')
            ->get(['id', 'fieldname']);

        if ($fields->isEmpty()) {
            return '0|Nenhum campo de cliente encontrado';
        }

        $options = ['0|-- Selecione um campo --'];
        foreach ($fields as $field) {
            $options[] = $field->id . '|' . $field->fieldname;
        }
        return implode(',', $options);
    } catch (\Throwable $e) {
        return '0|Erro: ' . $e->getMessage();
    }
}

/**
 * Busca o valor de um campo personalizado de um cliente diretamente no BD.
 */
function _seixastec_bancointer_getClientCustomField(int $clientId, int $fieldId): string
{
    try {
        $row = Capsule::table('tblcustomfieldsvalues')
            ->where('relid', $clientId)
            ->where('fieldid', $fieldId)
            ->first(['value']);
        return $row ? (string)$row->value : '';
    } catch (\Throwable) {
        return '';
    }
}

/**
 * Remove tudo que não for dígito de uma string (ou retorna '' se array).
 */
function seixastec_bancointer_apenas_numeros(mixed $value): string
{
    if (is_array($value)) {
        $value = $value['value'] ?? (is_scalar(reset($value)) ? reset($value) : '');
    }
    return preg_replace('/[^0-9]/', '', (string)$value);
}

/**
 * Obtém token OAuth2 do Banco Inter via mTLS.
 */
function seixastec_bancointer_oauth(array $params): string|false
{
    // Scope: usa o configurado, ou envia sem scope para obter todos os escopos registrados na aplicação
    $scopeConfigured = trim((string)($params['oauthScope'] ?? ''));

    $oauthData = [
        'client_id'     => $params['clientId'],
        'client_secret' => $params['clientSecret'],
        'grant_type'    => 'client_credentials',
    ];
    // Só adiciona o scope se estiver configurado — sem ele, o Inter retorna todos os escopos da aplicação
    if ($scopeConfigured !== '') {
        $oauthData['scope'] = $scopeConfigured;
    }

    $data = http_build_query($oauthData);

    $ch = curl_init('https://cdpj.partners.bancointer.com.br/oauth/v2/token');
    curl_setopt_array($ch, [
        CURLOPT_SSLCERT        => $params['certPath'],
        CURLOPT_SSLKEY         => $params['keyPath'],
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 15,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        logModuleCall('seixastec_bancointer', 'oauth_curl_error', $data, 'cURL error: ' . $curlError);
        return false;
    }

    $json = json_decode($response, true);

    if ($httpCode === 200 && is_array($json) && !empty($json['access_token'])) {
        // Loga os escopos concedidos para diagnóstico
        logModuleCall('seixastec_bancointer', 'oauth_success', $data, 'Escopos concedidos: ' . ($json['scope'] ?? 'não informado'));
        return $json['access_token'];
    }

    // Loga o erro completo para diagnóstico no painel do WHMCS
    $errInfo = 'HTTP ' . $httpCode . ' | ' . $response;
    logModuleCall('seixastec_bancointer', 'oauth_error', $data, $errInfo);
    return false;

}

/**
 * Realiza chamada autenticada à API do Banco Inter.
 */
function seixastec_bancointer_api(array $params, string $token, string $method, string $endpoint, ?array $payload = null): array
{
    // O header x-conta-corrente é OBRIGATÓRIO na API de Cobrança V3 do Inter
    $headers = [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
    ];
    $contaCorrente = preg_replace('/[^0-9]/', '', (string)($params['contaCorrente'] ?? ''));
    if ($contaCorrente !== '') {
        $headers[] = 'x-conta-corrente: ' . $contaCorrente;
    }

    $ch   = curl_init('https://cdpj.partners.bancointer.com.br' . $endpoint);
    $opts = [
        CURLOPT_SSLCERT        => $params['certPath'],
        CURLOPT_SSLKEY         => $params['keyPath'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 15,
    ];

    if ($method === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = $payload ? json_encode($payload) : '';
    } else {
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
    }

    curl_setopt_array($ch, $opts);
    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        logModuleCall('seixastec_bancointer', $method . ' ' . $endpoint . '_curl_error', $payload ? json_encode($payload) : '', 'cURL error: ' . $curlError);
        return ['code' => 0, 'response' => null];
    }

    logModuleCall('seixastec_bancointer', $method . ' ' . $endpoint, $payload ? json_encode($payload) : '', $response);

    return ['code' => $httpCode, 'response' => json_decode($response, true)];
}

/**
 * Valida campos obrigatórios e retorna lista de erros (vazia = OK).
 */
function _seixastec_bancointer_validarCampos(array $clientDetails, string $cpfCnpj): array
{
    $errors = [];

    if (empty($cpfCnpj) || !in_array(strlen($cpfCnpj), [11, 14])) {
        $errors[] = 'CPF (11 dígitos) ou CNPJ (14 dígitos) inválido ou não cadastrado.';
    }
    if (empty(trim($clientDetails['address1'] ?? ''))) {
        $errors[] = 'Endereço não preenchido.';
    }
    if (empty(trim($clientDetails['city'] ?? ''))) {
        $errors[] = 'Cidade não preenchida.';
    }
    $uf = strtoupper(preg_replace('/[^A-Za-z]/', '', $clientDetails['state'] ?? ''));
    if (strlen($uf) < 2) {
        $errors[] = 'Estado (UF) inválido ou não preenchido.';
    }
    $cep = preg_replace('/[^0-9]/', '', $clientDetails['postcode'] ?? '');
    if (strlen($cep) !== 8) {
        $errors[] = 'CEP inválido (deve ter 8 dígitos).';
    }
    if (empty(trim($clientDetails['email'] ?? ''))) {
        $errors[] = 'E-mail não preenchido.';
    }

    return $errors;
}

// ---------------------------------------------------------------------------
// Função principal: geração do link de pagamento na fatura
// ---------------------------------------------------------------------------

function seixastec_bancointer_link(array $params): string
{
    _seixastec_bancointer_ensureTable();

    $invoiceId     = (int)$params['invoiceid'];
    $amount        = (float)$params['amount'];
    $clientDetails = $params['clientdetails'];
    $systemUrl     = rtrim($params['systemurl'], '/') . '/';

    // --- Configurações ---
    $validarCampos  = ($params['validarCampos'] ?? '') === 'on';
    $cpfCnpjFieldId = (int)($params['cpfCnpjFieldId'] ?? 0);
    $diasVenc       = max(1, (int)($params['diasVencimento'] ?? 3));
    $multa          = max(0, (float)($params['multa'] ?? 2));
    $mora           = max(0, (float)($params['mora'] ?? 1));
    $clientId       = (int)$clientDetails['userid'];

    // --- CPF/CNPJ via campo personalizado ---
    $cpfCnpj = '';
    if ($cpfCnpjFieldId > 0) {
        $cpfCnpj = _seixastec_bancointer_getClientCustomField($clientId, $cpfCnpjFieldId);
    }
    $cpfCnpj = seixastec_bancointer_apenas_numeros($cpfCnpj);

    // --- Validação de campos obrigatórios ---
    if ($validarCampos) {
        $errors = _seixastec_bancointer_validarCampos($clientDetails, $cpfCnpj);
        if (!empty($errors)) {
            $listItems = implode('', array_map(fn($e) => "<li>{$e}</li>", $errors));
            return <<<HTML
<div class="alert alert-warning">
  <strong>⚠️ Não foi possível emitir a cobrança. Corrija os seguintes dados cadastrais:</strong>
  <ul style="margin:8px 0 0 0;">{$listItems}</ul>
</div>
HTML;
        }
    }

    // --- Dados do pagador ---
    $nome     = substr(trim(($clientDetails['firstname'] ?? '') . ' ' . ($clientDetails['lastname'] ?? '')), 0, 80);
    $email    = substr($clientDetails['email'] ?? '', 0, 50);
    $address1 = substr($clientDetails['address1'] ?? '', 0, 90);
    $city     = substr($clientDetails['city'] ?? '', 0, 50);
    $stateUf  = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $clientDetails['state'] ?? ''), 0, 2));
    $cep      = substr(seixastec_bancointer_apenas_numeros($clientDetails['postcode'] ?? ''), 0, 8);
    $phone    = seixastec_bancointer_apenas_numeros($clientDetails['phonenumber'] ?? '');
    $ddd      = strlen($phone) >= 10 ? substr($phone, 0, 2) : '';
    $telefone = strlen($phone) >= 10 ? substr($phone, 2) : $phone;

    // Tipo de pessoa
    if (strlen($cpfCnpj) === 11) {
        $tipoPessoa = 'FISICA';
    } elseif (strlen($cpfCnpj) === 14) {
        $tipoPessoa = 'JURIDICA';
    } else {
        $tipoPessoa = 'FISICA';
        $cpfCnpj    = '';
    }

    $dueDate      = date('Y-m-d', strtotime("+{$diasVenc} days"));
    $txRecord     = Capsule::table('tb_seixastec_bancointer_tx')->where('invoice_id', $invoiceId)->first();
    $contaCorrente = preg_replace('/[^0-9]/', '', (string)($params['contaCorrente'] ?? ''));

    // --- Diagnóstico de configuração (antes de autenticar) ---
    $configErrors = [];
    if (empty(trim($params['clientId'] ?? '')))     $configErrors[] = 'Client ID não preenchido.';
    if (empty(trim($params['clientSecret'] ?? ''))) $configErrors[] = 'Client Secret não preenchido.';
    if (empty(trim($params['certPath'] ?? '')))     $configErrors[] = 'Caminho do Certificado não preenchido.';
    if (empty(trim($params['keyPath'] ?? '')))      $configErrors[] = 'Caminho da Chave Privada não preenchido.';
    if ($contaCorrente === '')                      $configErrors[] = '<strong>Número da Conta Corrente não preenchido</strong> — obrigatório para a API V3 do Inter.';
    if (!file_exists(trim($params['certPath'] ?? ''))) $configErrors[] = 'Arquivo de certificado NÃO encontrado no caminho: <code>' . htmlspecialchars($params['certPath']) . '</code>';
    if (!file_exists(trim($params['keyPath'] ?? '')))  $configErrors[] = 'Arquivo de chave NÃO encontrado no caminho: <code>' . htmlspecialchars($params['keyPath']) . '</code>';

    if (!empty($configErrors)) {
        $listItems = implode('', array_map(fn($e) => "<li>{$e}</li>", $configErrors));
        return <<<HTML
<div class="alert alert-danger">
  <strong>❌ Configuração incompleta do Banco Inter:</strong>
  <ul style="margin:8px 0 0 0;">{$listItems}</ul>
  <small style="display:block;margin-top:8px;opacity:.8;">Acesse <b>Configurações → Gateways de Pagamento → Banco Inter</b> para corrigir.</small>
</div>
HTML;
    }

    // --- Autenticação ---
    $token = seixastec_bancointer_oauth($params);
    if (!$token) {
        return '<div class="alert alert-danger">❌ <strong>Falha na autenticação OAuth com o Banco Inter.</strong><br>Verifique se o Client ID, Client Secret e os arquivos de certificado (.crt e .key) estão corretos e acessíveis pelo PHP.<br><small>Detalhes logados em <b>Módulos → Log de Gateway → seixastec_bancointer → oauth_error</b>.</small></div>';
    }

    // --- Tenta recuperar cobrança existente ---
    $cobrancaAtual = null;
    if ($txRecord && $txRecord->codigo_solicitacao) {
        $req = seixastec_bancointer_api($params, $token, 'GET', '/cobranca/v3/cobrancas/' . $txRecord->codigo_solicitacao);
        if ($req['code'] === 200 && isset($req['response']['cobranca'])) {
            $cobrancaAtual = $req['response']['cobranca'];
            $status = $cobrancaAtual['situacao'] ?? '';
            if (in_array($status, ['CANCELADO', 'BAIXADO', 'EXPIRADO'])) {
                $cobrancaAtual = null;
            }
        }
    }

    // --- Gera nova cobrança se necessário ---
    if (!$cobrancaAtual) {
        $pagador = [
            'tipoPessoa' => $tipoPessoa,
            'nome'       => $nome,
            'endereco'   => $address1,
            'cidade'     => $city,
            'uf'         => $stateUf,
            'cep'        => $cep,
            'email'      => $email,
        ];
        if (!empty($cpfCnpj)) {
            $pagador['cpfCnpj'] = $cpfCnpj;
        }
        if (strlen($ddd) === 2 && strlen($telefone) >= 8) {
            $pagador['ddd']      = $ddd;
            $pagador['telefone'] = $telefone;
        }

        $payload = [
            'seuNumero'         => 'INV' . $invoiceId,
            'valorNominal'      => round($amount, 2),
            'dataVencimento'    => $dueDate,
            'numDiasAgenda'     => 60,
            'pagador'           => $pagador,
            'formasRecebimento' => ['BOLETO', 'PIX'],
        ];
        if ($multa > 0) {
            $payload['multa'] = ['codigo' => 'PERCENTUAL', 'taxa' => $multa];
        }
        if ($mora > 0) {
            $payload['mora'] = ['codigo' => 'TAXAMENSAL', 'taxa' => $mora];
        }

        $createReq = seixastec_bancointer_api($params, $token, 'POST', '/cobranca/v3/cobrancas', $payload);

        if ($createReq['code'] >= 200 && $createReq['code'] < 300 && isset($createReq['response']['codigoSolicitacao'])) {
            $codigoSolicitacao = $createReq['response']['codigoSolicitacao'];

            try {
                if ($txRecord) {
                    Capsule::table('tb_seixastec_bancointer_tx')->where('id', $txRecord->id)->update([
                        'codigo_solicitacao' => $codigoSolicitacao,
                        'updated_at'         => date('Y-m-d H:i:s'),
                    ]);
                } else {
                    Capsule::table('tb_seixastec_bancointer_tx')->insert([
                        'invoice_id'         => $invoiceId,
                        'codigo_solicitacao' => $codigoSolicitacao,
                        'created_at'         => date('Y-m-d H:i:s'),
                        'updated_at'         => date('Y-m-d H:i:s'),
                    ]);
                }
            } catch (\Throwable $e) {
                logModuleCall('seixastec_bancointer', 'db_save_error', 'invoice_' . $invoiceId, $e->getMessage());
            }

            $req = seixastec_bancointer_api($params, $token, 'GET', '/cobranca/v3/cobrancas/' . $codigoSolicitacao);
            if ($req['code'] === 200 && isset($req['response']['cobranca'])) {
                $cobrancaAtual = $req['response']['cobranca'];
                $txid        = $cobrancaAtual['pix']['txid'] ?? null;
                $nossoNumero = $cobrancaAtual['boleto']['nossoNumero'] ?? null;
                $updateData  = ['updated_at' => date('Y-m-d H:i:s')];
                if ($txid)        $updateData['txid']         = $txid;
                if ($nossoNumero) $updateData['nosso_numero'] = $nossoNumero;
                if (count($updateData) > 1) {
                    Capsule::table('tb_seixastec_bancointer_tx')
                        ->where('codigo_solicitacao', $codigoSolicitacao)
                        ->update($updateData);
                }
            }
        } else {
            $errMsg = '';
            $resp   = $createReq['response'] ?? [];
            if (!empty($resp['violacoes']) && is_array($resp['violacoes'])) {
                $msgs   = array_map(fn($v) => '<b>' . ($v['campo'] ?? '') . '</b>: ' . ($v['razao'] ?? ''), $resp['violacoes']);
                $errMsg = implode('<br>', $msgs);
            } elseif (!empty($resp['message'])) {
                $errMsg = htmlspecialchars($resp['message']);
            } elseif (!empty($resp['title'])) {
                $errMsg = htmlspecialchars($resp['title']);
            } else {
                $errMsg = 'HTTP ' . $createReq['code'] . ' — ' . htmlspecialchars(json_encode($resp));
            }
            return "<div class=\"alert alert-danger\"><strong>❌ Erro ao gerar cobrança no Banco Inter:</strong><br>{$errMsg}</div>";
        }
    }

    if (!$cobrancaAtual) {
        return '<div class="alert alert-warning">⏳ Cobrança em processamento. Por favor, recarregue a página em alguns instantes.</div>';
    }

    $linhaDigitavel = $cobrancaAtual['boleto']['linhaDigitavel'] ?? '';
    $pixCopiaECola  = $cobrancaAtual['pix']['pixCopiaECola'] ?? '';

    if (empty($linhaDigitavel) && empty($pixCopiaECola)) {
        return '<div class="alert alert-warning">⏳ Os dados de pagamento ainda estão sendo gerados. Por favor, recarregue a página em alguns instantes.</div>';
    }

    $pdfUrl = $systemUrl . 'modules/gateways/callback/seixastec_bancointer.php?action=download_pdf&invoiceid=' . $invoiceId;
    $qrUrl  = !empty($pixCopiaECola)
        ? 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&data=' . urlencode($pixCopiaECola)
        : '';

    $pixSection = '';
    if (!empty($pixCopiaECola)) {
        $pixEscaped = htmlspecialchars($pixCopiaECola);
        $pixSection = <<<HTML
        <div class="bi-section">
          <h5 class="bi-section-title">📱 Pagar com Pix</h5>
          <div class="bi-qr-wrap">
            <img src="{$qrUrl}" alt="QR Code Pix" class="bi-qr-img" />
          </div>
          <p class="bi-label">Pix Copia e Cola</p>
          <div class="bi-copy-wrap">
            <input type="text" id="bi-pix-{$invoiceId}" class="bi-input" value="{$pixEscaped}" readonly />
            <button class="bi-copy-btn" onclick="biCopy('bi-pix-{$invoiceId}', this)">Copiar</button>
          </div>
        </div>
HTML;
    }

    $boletoSection = '';
    if (!empty($linhaDigitavel)) {
        $ldEscaped     = htmlspecialchars($linhaDigitavel);
        $boletoSection = <<<HTML
        <div class="bi-section">
          <h5 class="bi-section-title">🏦 Pagar com Boleto</h5>
          <p class="bi-label">Linha Digitável</p>
          <div class="bi-copy-wrap">
            <input type="text" id="bi-ld-{$invoiceId}" class="bi-input" value="{$ldEscaped}" readonly />
            <button class="bi-copy-btn" onclick="biCopy('bi-ld-{$invoiceId}', this)">Copiar</button>
          </div>
          <a href="{$pdfUrl}" target="_blank" class="bi-pdf-btn">⬇️ Baixar PDF do Boleto</a>
        </div>
HTML;
    }

    $divider = (!empty($pixCopiaECola) && !empty($linhaDigitavel)) ? '<hr class="bi-divider" />' : '';

    return <<<HTML
<div class="bancointer-card" id="bi-card-{$invoiceId}">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap');
    .bancointer-card {
      font-family: 'Inter', sans-serif;
      max-width: 460px;
      margin: 16px auto;
      border-radius: 16px;
      overflow: hidden;
      box-shadow: 0 8px 32px rgba(255,115,0,.2);
      border: 1px solid rgba(255,115,0,.2);
      background: #fff;
    }
    .bi-header {
      background: linear-gradient(135deg,#FF7A00 0%,#FF4000 100%);
      padding: 20px 24px;
      display: flex;
      align-items: center;
      gap: 14px;
    }
    .bi-header-text { color: #fff; }
    .bi-header-text h4 { margin: 0; font-size: 18px; font-weight: 700; }
    .bi-header-text p  { margin: 2px 0 0; font-size: 13px; opacity: .85; }
    .bi-body { padding: 20px 24px; }
    .bi-section { margin-bottom: 4px; }
    .bi-section-title { font-size: 15px; font-weight: 600; color: #333; margin: 0 0 12px; }
    .bi-qr-wrap { text-align: center; margin-bottom: 12px; }
    .bi-qr-img { border-radius: 12px; border: 1px solid #eee; padding: 6px; }
    .bi-label { font-size: 12px; color: #666; margin: 0 0 6px; }
    .bi-copy-wrap { display: flex; gap: 8px; }
    .bi-input {
      flex: 1; font-size: 11px; padding: 8px 10px;
      border: 1px solid #ddd; border-radius: 8px;
      background: #f9f9f9; color: #333;
      cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    }
    .bi-copy-btn {
      white-space: nowrap; padding: 8px 14px; font-size: 12px; font-weight: 600;
      background: #FF7A00; color: #fff; border: none; border-radius: 8px; cursor: pointer;
      transition: background .2s;
    }
    .bi-copy-btn:hover { background: #e06600; }
    .bi-copy-btn.copied { background: #28a745; }
    .bi-pdf-btn {
      display: block; margin-top: 14px; text-align: center; padding: 11px;
      background: #fff3e0; color: #FF7A00; font-weight: 600; font-size: 14px;
      border: 2px solid #FF7A00; border-radius: 10px; text-decoration: none;
      transition: background .2s;
    }
    .bi-pdf-btn:hover { background: #FF7A00; color: #fff; text-decoration: none; }
    .bi-divider { border: none; border-top: 1px solid #eee; margin: 18px 0; }
    .bi-footer { font-size: 11px; color: #aaa; text-align: center; padding: 10px 24px 16px; }
  </style>

  <div class="bi-header">
    <div class="bi-header-text">
      <h4>Banco Inter</h4>
      <p>Boleto Bancário e Pix</p>
    </div>
  </div>

  <div class="bi-body">
    {$pixSection}
    {$divider}
    {$boletoSection}
  </div>

  <div class="bi-footer">🔒 Pagamento seguro via Banco Inter</div>
</div>

<script>
function biCopy(inputId, btn) {
  var el = document.getElementById(inputId);
  if (!el) return;
  var text = el.value;
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(function() {
      biCopyDone(btn);
    }).catch(function() {
      biCopyFallback(el, btn);
    });
  } else {
    biCopyFallback(el, btn);
  }
}
function biCopyFallback(el, btn) {
  el.select();
  try { document.execCommand('copy'); } catch (e) {}
  biCopyDone(btn);
}
function biCopyDone(btn) {
  btn.textContent = '✓ Copiado!';
  btn.classList.add('copied');
  setTimeout(function() {
    btn.textContent = 'Copiar';
    btn.classList.remove('copied');
  }, 2500);
}
</script>
HTML;
}

// ---------------------------------------------------------------------------
// Hook: Anexa PDF do boleto ao e-mail da fatura (quando emitirSempre = on)
// ---------------------------------------------------------------------------

add_hook('EmailPreSend', 1, function (array $vars): array {
    if (!in_array($vars['messagename'] ?? '', [
        'Invoice Created',
        'Invoice Payment Reminder',
        'Overdue Invoice Notification',
    ])) {
        return $vars;
    }

    $invoiceId = (int)($vars['relid'] ?? 0);
    if ($invoiceId === 0) {
        return $vars;
    }

    $gatewayParams = getGatewayVariables('seixastec_bancointer');
    if (!$gatewayParams['type'] || ($gatewayParams['emitirSempre'] ?? '') !== 'on') {
        return $vars;
    }

    try {
        if (!Capsule::schema()->hasTable('tb_seixastec_bancointer_tx')) {
            return $vars;
        }
        $txRecord = Capsule::table('tb_seixastec_bancointer_tx')->where('invoice_id', $invoiceId)->first();
    } catch (\Throwable) {
        return $vars;
    }
    if (!$txRecord || !$txRecord->codigo_solicitacao) {
        return $vars;
    }

    $token = seixastec_bancointer_oauth($gatewayParams);
    if (!$token) {
        return $vars;
    }

    $req = seixastec_bancointer_api($gatewayParams, $token, 'GET', '/cobranca/v3/cobrancas/' . $txRecord->codigo_solicitacao . '/pdf');
    if ($req['code'] === 200 && !empty($req['response']['pdf'])) {
        $vars['attachments'][] = [
            'filename' => 'Boleto_Fatura_' . $invoiceId . '.pdf',
            'data'     => base64_decode($req['response']['pdf']),
        ];
    }

    return $vars;
});

// ---------------------------------------------------------------------------
// Hook: Cancela o boleto no Banco Inter quando a fatura é paga por outro método
// ---------------------------------------------------------------------------

add_hook('InvoicePaid', 1, function (array $vars): void {
    $invoiceId = (int)($vars['invoiceid'] ?? 0);
    if ($invoiceId === 0) {
        return;
    }

    try {
        $invoice = Capsule::table('tblinvoices')
            ->where('id', $invoiceId)
            ->first(['paymentmethod', 'status']);
    } catch (\Throwable) {
        return;
    }

    // Se pago pelo próprio módulo, o callback já tratou
    if (!$invoice || strtolower((string)$invoice->paymentmethod) === 'seixastec_bancointer') {
        return;
    }

    try {
        if (!Capsule::schema()->hasTable('tb_seixastec_bancointer_tx')) {
            return;
        }
        $txRecord = Capsule::table('tb_seixastec_bancointer_tx')
            ->where('invoice_id', $invoiceId)
            ->first();
    } catch (\Throwable) {
        return;
    }

    if (!$txRecord || empty($txRecord->codigo_solicitacao)) {
        return;
    }

    $gatewayParams = getGatewayVariables('seixastec_bancointer');
    if (!($gatewayParams['type'] ?? false)) {
        return;
    }

    $token = seixastec_bancointer_oauth($gatewayParams);
    if (!$token) {
        logModuleCall('seixastec_bancointer', 'cancelar_hook', 'invoice_' . $invoiceId, 'Falha ao obter token OAuth para cancelamento');
        return;
    }

    // Consulta o status antes de cancelar
    $consultaReq = seixastec_bancointer_api($gatewayParams, $token, 'GET', '/cobranca/v3/cobrancas/' . $txRecord->codigo_solicitacao);
    if ($consultaReq['code'] === 200 && isset($consultaReq['response']['cobranca'])) {
        $situacao = $consultaReq['response']['cobranca']['situacao'] ?? '';
        if (!in_array($situacao, ['A_VENCER', 'VENCIDO', 'EM_DIA'])) {
            return;
        }
    }

    $cancelReq = seixastec_bancointer_api(
        $gatewayParams,
        $token,
        'POST',
        '/cobranca/v3/cobrancas/' . $txRecord->codigo_solicitacao . '/cancelar',
        ['motivoCancelamento' => 'PAGAMENTO_EM_OUTROS_MEIOS']
    );

    logModuleCall(
        'seixastec_bancointer',
        'cancelar_cobranca',
        json_encode([
            'invoice_id'         => $invoiceId,
            'codigo_solicitacao' => $txRecord->codigo_solicitacao,
            'metodo_pagamento'   => $invoice->paymentmethod,
        ]),
        json_encode(['http_code' => $cancelReq['code'], 'response' => $cancelReq['response']])
    );

    if ($cancelReq['code'] >= 200 && $cancelReq['code'] < 300) {
        try {
            Capsule::table('tb_seixastec_bancointer_tx')->where('invoice_id', $invoiceId)->delete();
        } catch (\Throwable) {
            // não crítico
        }
    }
});
