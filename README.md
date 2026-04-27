# Banco Inter Boleto e PIX — WHMCS 9.0.1 Payment Gateway

Módulo WHMCS para emissão de cobranças PIX + Boleto via API v3 do Banco Inter,
com registro de webhook automático, anexo de PDF nos e-mails e baixa automática
parametrizável.

## Instalação

Copie a árvore de arquivos para a raiz do seu WHMCS preservando a estrutura:

```
modules/gateways/seixastec_bancointer.php
modules/gateways/seixastec_bancointer/BancoInterAPI.php
modules/gateways/seixastec_bancointer/helper.php
modules/gateways/seixastec_bancointer/generate.php
modules/gateways/seixastec_bancointer/tools.php
modules/gateways/seixastec_bancointer/inter.png
modules/gateways/callback/seixastec_bancointer.php
includes/hooks/seixastec_bancointer_auto_generate.php
includes/hooks/seixastec_bancointer_email_pdf.php
```

Em seguida ative o gateway em **Setup → Payments → Payment Gateways → All
Payment Gateways → Banco Inter Boleto e PIX**. A tabela `mod_seixastec_bancointer_transactions`
é criada automaticamente no primeiro acesso à tela de configuração.

Depois de ativar, use o botão **Abrir Painel Administrativo** na configuração
do gateway para centralizar:

- credenciais e caminhos dos certificados
- gestão e rotação do webhook
- extrato local de cobranças
- métricas de emissão
- logs do módulo e logs de webhook

Se o WHMCS não exibir esse botão na tela do gateway, você pode abrir o painel
diretamente por:

```text
https://seu-whmcs.com/modules/gateways/seixastec_bancointer/admin.php?view=license
```

Opcionalmente, ative também o addon:

```
modules/addons/seixastec_bancointer_admin/seixastec_bancointer_admin.php
```

Depois acesse o painel por **Addons → Seixastec Bancointer Admin**.

## Certificados mTLS

Coloque os certificados **fora do `public_html`** (ex.: `/home/whmcs/inter_certs/`).

```bash
mkdir -p /home/whmcs/inter_certs
chown whmcs:whmcs /home/whmcs/inter_certs
chmod 700 /home/whmcs/inter_certs
chmod 600 /home/whmcs/inter_certs/*.crt /home/whmcs/inter_certs/*.key
```

Informe os **caminhos absolutos** dos arquivos `.crt/.pem` e `.key` no painel
administrativo do módulo.

## Campos de configuração

| Campo | Função |
|---|---|
| Client ID / Secret | Credenciais OAuth2 da aplicação Inter |
| Conta Corrente | Obrigatório apenas em aplicações multi-conta |
| Gerar Boleto/PIX automaticamente | Dispara emissão ao criar a fatura (hook InvoiceCreation) |
| Dias para Baixa Automática | Dias após o vencimento para cancelar no banco (padrão: 15) |
| Multa (%) / Juros ao Mês (%) | Regras pós-vencimento |
| Desconto (%, R$, dias) | Desconto para pagamento antecipado |
| Campo Customizado de CPF/CNPJ | Dropdown com os custom fields de cliente; se vazio usa `tblclients.tax_id` |

## Registro do webhook

No painel administrativo, acesse **Webhook** e clique em **Atualizar Webhook**.
O sistema faz `PUT /cobranca/v3/cobrancas/webhook` apontando para:

```
https://seu-whmcs.com/modules/gateways/callback/seixastec_bancointer.php
```

Use a própria tela de **Webhook** para conferir o status, gerar um novo token
de segurança e remover o registro quando necessário.

## Fluxo de pagamento

1. Cliente visualiza a fatura → `seixastec_bancointer_link()` mostra QR Code PIX + linha
   digitável + link de PDF.
2. Se *Gerar automaticamente* estiver desligado, o cliente clica em
   **Gerar Boleto + PIX** (handler `generate.php`).
3. Banco Inter dispara webhook ao compensar → callback chama `addInvoicePayment()`
   e marca a transação como `PAID`.
4. Hook `DailyCronJob` cancela cobranças vencidas além de `dias_baixa`.

## Logs

Todas as chamadas à API são registradas em **Utilities → Logs → Gateway Log** sob
o nome `seixastec_bancointer` com credenciais mascaradas.

## Troubleshooting

- **"cert_path unreadable"** — verifique `ls -l` e ownership; o usuário do
  PHP-FPM precisa ter permissão de leitura no `.crt` e `.key`.
- **"Invalid JSON payload" no webhook** — confirme se o webhook está
  registrado pela URL correta (use o botão *Consultar*).
- **PDF não anexa ao e-mail** — verifique o `logModuleCall` da tag
  `hook.email_pdf`; cobranças criadas fora do fluxo WHMCS não têm registro
  local e portanto não são anexadas.
=======
<div align="center">

<img src="https://upload.wikimedia.org/wikipedia/commons/thumb/e/e7/Banco_Inter_logo.svg/320px-Banco_Inter_logo.svg.png" alt="Banco Inter" width="180" />

# WHMCS — Banco Inter Gateway

**Módulo de pagamento para WHMCS com suporte a Boleto Bancário e Pix**  
via API de Cobrança V3 + Pix V2 do Banco Inter

---

![PHP](https://img.shields.io/badge/PHP-8.2%2B-777BB4?style=flat-square&logo=php&logoColor=white)
![WHMCS](https://img.shields.io/badge/WHMCS-9.x-FF6C37?style=flat-square)
![API](https://img.shields.io/badge/Inter%20API-Cobrança%20V3%20%2B%20Pix%20V2-FF7A00?style=flat-square)
![License](https://img.shields.io/badge/License-GPL--3.0-blue?style=flat-square)
![Status](https://img.shields.io/badge/Status-Produção-success?style=flat-square)

</div>

---

## ✨ Funcionalidades

| Recurso | Detalhe |
|---|---|
| 🏦 **Boleto Bancário** | Emissão via API Cobrança V3, com linha digitável e download do PDF |
| 📱 **Pix** | QR Code + Pix Copia e Cola gerados automaticamente |
| 🔄 **Cobrança Híbrida** | Boleto e Pix na mesma cobrança (uma só chamada à API) |
| 🔐 **Autenticação mTLS** | OAuth2 com certificado digital (.crt + .key) |
| 📧 **PDF no e-mail** | Anexa o PDF do boleto automaticamente nos e-mails de fatura |
| 🔔 **Webhook** | Recebe notificações de pagamento (Pix V2 e Cobrança V3) |
| ❌ **Cancelamento automático** | Cancela a cobrança no Inter quando a fatura é paga por outro meio |
| 🛡️ **Segurança** | Validação de sessão no download do PDF, token de segurança no webhook |
| 🔍 **Diagnóstico** | Mensagens de erro detalhadas no painel e logs do WHMCS |

---

## 📋 Requisitos

- **WHMCS** 8.x ou 9.x
- **PHP** 8.2 ou superior
- **Extensão cURL** habilitada com suporte a mTLS
- **Conta PJ** no Banco Inter com acesso ao [Portal do Desenvolvedor](https://developers.bancointer.com.br/)
- **Certificado digital** (.crt e .key) gerado no portal Inter

---

## 🚀 Instalação

### 1. Copiar os arquivos

```
whmcs/
└── modules/
    └── gateways/
        ├── seixastec_bancointer.php          ← módulo principal
        └── callback/
            └── seixastec_bancointer.php      ← webhook e download PDF
```

### 2. Certificados mTLS

Salve os arquivos de certificado **fora do `public_html`**:

```
/home/seuusuario/ssl/
├── inter.crt
└── inter.key
```

> ⚠️ Nunca coloque os certificados dentro do `public_html` ou de diretórios acessíveis via web.

### 3. Ativar no WHMCS

1. Acesse **Configurações → Gateways de Pagamento**
2. Clique em **Todos os Gateways** e localize **Banco Inter - Boleto e Pix**
3. Clique em **Ativar**

---

## ⚙️ Configuração

Após ativar, preencha os campos em **Configurações → Gateways de Pagamento → Banco Inter**:

| Campo | Descrição |
|---|---|
| **Client ID** | Obtido no Portal do Desenvolvedor Inter |
| **Client Secret** | Obtido no Portal do Desenvolvedor Inter |
| **Caminho do Certificado** | Caminho absoluto do `.crt` no servidor |
| **Caminho da Chave Privada** | Caminho absoluto do `.key` no servidor |
| **Número da Conta Corrente** | Conta PJ Inter (apenas dígitos) |
| **Campo CPF/CNPJ** | Campo personalizado do cliente com CPF ou CNPJ |
| **Emitir para todas as faturas** | Gera boleto/pix e anexa PDF ao e-mail |
| **Validar campos obrigatórios** | Verifica endereço, CEP, UF e CPF/CNPJ antes de emitir |
| **Dias para vencimento** | Dias adicionais quando a fatura já venceu (padrão: 3) |
| **Multa por atraso** | Percentual de multa (máx. 2% conforme CDC) |
| **Juros de mora** | Percentual mensal de juros |
| **Token do Webhook** | Token secreto para validar as notificações do Inter |

---

## 🔔 Configuração do Webhook

### URL do Webhook

```
https://seudominio.com/modules/gateways/callback/seixastec_bancointer.php?token=SEU_TOKEN
```

### Cadastrar no Portal Inter

1. Acesse o [Portal do Desenvolvedor Inter](https://developers.bancointer.com.br/)
2. Vá em **Webhooks**
3. Cadastre a URL acima para os eventos:
   - **Cobrança V3** — notificações de boleto pago
   - **Pix V2** — notificações de Pix recebido

> 💡 O `?token=SEU_TOKEN` deve ser o mesmo valor configurado no campo **Token de segurança do Webhook** no painel do WHMCS.

---

## 🎨 Interface do Cliente

O módulo exibe um card moderno na página de fatura do cliente:

```
┌─────────────────────────────────────────┐
│  🟠  Banco Inter                         │
│      Boleto Bancário e Pix               │
├─────────────────────────────────────────┤
│  📱 Pagar com Pix                        │
│                                          │
│     ┌─────────────┐                      │
│     │  [QR CODE]  │                      │
│     └─────────────┘                      │
│                                          │
│  Pix Copia e Cola                        │
│  ┌──────────────────────┐ [Copiar]       │
│  │ 00020126...          │                │
│  └──────────────────────┘                │
├─────────────────────────────────────────┤
│  🏦 Pagar com Boleto                     │
│                                          │
│  Linha Digitável                         │
│  ┌──────────────────────┐ [Copiar]       │
│  │ 34191.79001...       │                │
│  └──────────────────────┘                │
│                                          │
│  ⬇️  Baixar PDF do Boleto                │
└─────────────────────────────────────────┘
│  🔒 Pagamento seguro via Banco Inter     │
└─────────────────────────────────────────┘
```

---

## 🗄️ Banco de Dados

O módulo cria automaticamente a tabela `tb_seixastec_bancointer_tx` no primeiro uso:

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | int (PK) | ID interno |
| `invoice_id` | int (unique) | ID da fatura no WHMCS |
| `codigo_solicitacao` | varchar | Código da cobrança no Inter |
| `txid` | varchar | TxID do Pix |
| `nosso_numero` | varchar | Nosso Número do boleto |
| `created_at` | timestamp | Data de criação |
| `updated_at` | timestamp | Data de atualização |

---

## 🔍 Diagnóstico e Logs

Os logs ficam em **WHMCS → Módulos → Log do Gateway → seixastec_bancointer**:

| Entrada | Descrição |
|---|---|
| `oauth_success` | Token obtido com sucesso (mostra escopos concedidos) |
| `oauth_error` | Falha na autenticação (mostra HTTP code + resposta) |
| `oauth_curl_error` | Erro de rede no cURL (mTLS, timeout etc.) |
| `POST /cobranca/v3/cobrancas` | Criação de cobrança |
| `GET /cobranca/v3/cobrancas/{id}` | Consulta de cobrança |
| `cancelar_cobranca` | Cancelamento de cobrança |
| `webhook_received` | Payload recebido do Inter |
| `webhook_unauthorized` | Token do webhook inválido |

---

## 📁 Estrutura do Projeto

```
bancointer-whmcs/
├── modules/
│   └── gateways/
│       ├── seixastec_bancointer.php       # Módulo principal
│       └── callback/
│           └── seixastec_bancointer.php   # Webhook + download PDF
├── .gitignore
└── README.md
```

---

## 🔐 Segurança

- Certificados mTLS nunca trafegam no código — são lidos diretamente do servidor via cURL
- O download do PDF valida a sessão: apenas o dono da fatura (ou admin) pode baixar
- O webhook valida o token antes de processar qualquer payload
- Campos de cliente são escapados com `htmlspecialchars()` antes de exibir

---

## 📜 Licença

Distribuído sob a licença [GPL-3.0](https://www.gnu.org/licenses/gpl-3.0.html).

---

## 👤 Autor

**Eduardo Seixas**  
Módulo desenvolvido para uso com WHMCS 9.x e API Inter Empresas.

---

<div align="center">
  <sub>Feito com ☕ e PHP • Compatível com Banco Inter API Cobrança V3 + Pix V2</sub>
</div>
>>>>>>> 89d66b5656e08a359328888bfd4058ebd3bf9dce
