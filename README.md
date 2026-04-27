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
