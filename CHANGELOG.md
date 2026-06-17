# Changelog

All notable changes to this project will be documented in this file.

## [1.5.1] - 2026-06-16

### Fixed
- **CPF/CNPJ com valor legado no dropdown**: `normalizeCustomFieldId()` aceita configurações antigas salvas como `1=[1] CPF/CNPJ` e resolve o custom field correto antes do fallback para `tblclients.tax_id`.
- Mensagens de erro em `generateForInvoice` agora citam o ID normalizado e o nome do campo (ex.: `custom field #1 (CPF/CNPJ)`).

### Changed
- Dropdown **Origem do CPF/CNPJ** exibe o nome do custom field (ex.: `CPF/CNPJ`) em vez de `[1] CPF/CNPJ`.

## [1.5.0] - 2026-06-16

### Changed
- Multa e juros passam a ser cobrados **somente no boleto** (regras enviadas ao Banco Inter). O WHMCS não aplica mais late fees nativas em faturas com gateway `seixastec_bancointer`.
- No webhook de pagamento, multa/juros recebidos do banco são registrados na fatura como itens `"Multa por atraso (Banco Inter)"` e `"Juros de mora (Banco Inter)"` **antes** de `addInvoicePayment()`, alinhando ledger e valor pago.
- Cron diário remove late fees WHMCS remanescentes em faturas Banco Inter ainda não pagas (corrige faturas afetadas anteriormente, ex. ledger com Debit Note).

### Added
- Hook `includes/hooks/seixastec_bancointer_late_fees.php` (`AddInvoiceLateFee` + limpeza em `DailyCronJob`).
- Coluna `charges_synced_at` em `mod_seixastec_bancointer_transactions` para idempotência na sincronização pós-pagamento.

## [1.4.9] - 2026-05-?? 

### Fixed
- **Dropdown "Origem do CPF/CNPJ" com "1" perdido**: corrigido o builder da string `Options` para o campo `dropdown` no config nativo do gateway. Agora usa formato `val=label` (ex: `1=[1] CPF/CNPJ`) unido por vírgulas. Isso impede que o parser do WHMCS crie opções avulsas como o número "1" separado do label formatado. Inclui opção de fallback "usar Tax ID padrão" + os campos custom reais no formato `[id] Nome`. O valor salvo continua sendo o ID numérico (ou vazio para fallback).
- **Logs Webhook ocupando o frame no embed**: reforçado o modo `minimal=1` (usado quando o painel é embutido na página de config do gateway):
  - CSS dedicado para forçar `height: 100%` no container do iframe, sidebar lateral estreita fixa (165px, sem stacking em telas pequenas), e `.bi-main` com `overflow: auto` (só o conteúdo rola; sidebar de navegação fica sempre visível).
  - Altura do `<iframe>` reduzida para 680px (fixa) para não dominar a página de config do WHMCS.
  - No `bi_renderWebhookLogsCard`: payload do `<pre>` truncado para ~180 chars + `…` + `title` com texto completo (evita expansão por JSON longo).
- Removido o botão "Abrir Painel Administrativo em Nova Aba" do embed rico na página nativa (conforme solicitado).
- Removido completamente o "Simulador rápido (multa + juros)" da página de configuração nativa (conforme solicitado). Somente o valor da multa é necessário/ mantido como campo principal. O campo de juros permanece (com nota para deixar 0 se não usado); o simulador que misturava os dois foi retirado.

### Changed
- Versão atualizada para 1.4.9 em `whmcs.json` e no addon.
- Addon continua sendo **opcional** (atalho de menu). O principal é tudo dentro de Setup > Payments > Payment Gateways > Banco Inter (credenciais + regras operacionais + ferramentas no iframe embutido).
- Melhoria na navegação do iframe embutido: `<iframe name="bi-panel-iframe">` + `target="bi-panel-iframe"` em todos os links da sidebar e forms (filtros + ações). Garante que cliques/submits (incluindo em Logs Webhook) recarreguem dentro do mesmo iframe.

## [1.4.8] - Previous

### Fixed
- Dropdown "Origem do CPF/CNPJ": corrigido o builder de "Options" para WHMCS. Agora lista **somente** os custom fields reais no formato "[id] Nome" (ex: "[1] CPF/CNPJ"). Removida a entrada em branco/"Usar Tax ID do Cliente" do dropdown (o comportamento de fallback para o Tax ID do cliente continua quando nada é selecionado).
- Removido completamente o botão "Abrir Painel Administrativo em Nova Aba" do embed na página nativa do gateway (conforme solicitado).
- Navegação em "Logs Webhook" (e todas as views do painel embutido): adicionado `name="bi-panel-iframe"` no `<iframe>` + `target="bi-panel-iframe"` em todos os `<a>` da sidebar e em todos os `<form>` (filtros de data GET + ações POST como register/delete/rotate/clear). Isso garante que cliques e submits dentro do iframe (incluindo após ir para Logs Webhook e usar "Buscar") sempre recarreguem **dentro do mesmo iframe**, mantendo a sidebar visível o tempo todo e eliminando a necessidade de "voltar" no navegador. O modo minimal continua removendo o header repetido para não "ocupar a tela toda".

- Removido o "Simulador rápido (multa + juros)" da página nativa de configuração do gateway (conforme solicitado). Somente o valor da multa é mantido como campo editável principal (juros permanece como campo opcional com nota para deixar 0 se não utilizado; o simulador que misturava os dois foi retirado por completo).
- Logs Webhook no embed: agora com CSS dedicado no modo minimal para forçar altura 100% do iframe (html/body/.bi-admin), sidebar lateral estreita fixa (sem colapso em larguras pequenas), e .bi-main com overflow:auto (scroll interno). Altura do iframe reduzida para 680px fixa. Payload no pre truncado agressivamente (180 chars + tooltip). Isso resolve o problema de "ocupar o frame todo" mantendo a navegação lateral sempre acessível sem recarregar a página pai.

(Older entries below - see full history for 1.4.6/1.4.7 consolidation, juros transparency, OAuth 429 cache, etc.)

## [1.4.5] - 2026-05-20

### Fixed
- Adicionado suporte ao template `Invoice Modified` (e variantes como `Fatura Alterada`) no hook `EmailPreSend` para anexar o boleto PDF do Banco Inter ao e-mail enviado ao cliente após alterações na fatura.

### Maintenance
- Versão do addon `seixastec_bancointer_admin` e gateway incrementadas para `1.4.5`.

## [1.4.4] - 2026-05-19

### Fixed
- **BUG-3:** `generateForInvoice` agora só marca a cobrança anterior como `CANCELLED` após confirmação da API do Banco Inter. Em caso de falha no cancelamento, a geração é abortada com erro claro — antes a cobrança era marcada cancelada localmente mesmo que o banco ainda a mantivesse ativa, criando duplicatas cobráveis.
- **BUG-4:** `DailyCronJob` consulta o status remoto (`getCollection`) antes de cancelar cada cobrança vencida. Cobranças pagas via webhook perdido não são mais marcadas erroneamente como `CANCELLED`; o status local é sincronizado com o retorno da API.
- **BUG-5:** `logTransaction` dentro de `generateForInvoice` agora usa a chave correta `$params["name"]` (antes usava `$params["paymentmethod"]`, inexistente no array de gateway vars do WHMCS).
- **BUG-9 (principal):** E-mails do tipo `Overdue Invoice Notification` (e variantes) voltam a receber o boleto PDF em anexo quando a cobrança ainda está ativa no Banco Inter.

### Changed
- Hook `EmailPreSend` classifica templates por substring case-insensitive em vez de lista estrita, cobrindo `Overdue Invoice Notification`, `Invoice Overdue Notice`, templates em português (`Notificação de Fatura Vencida`) e qualquer customização que preserve a palavra-chave.
- Hook `EmailPreSend` emite telemetria estruturada em todos os caminhos (`attached`, `skipped_paid`, `skipped_cancelled`, `skipped_no_active_tx`, `skipped_other_gateway`, `mint_failed`, `api_error`) visível em WHMCS → Utilities → Logs → Gateway Log.
- Boleto PDF nunca é anexado quando a cobrança está em status `PAID` ou `CANCELLED` / `EXPIRED`.
- Geração on-the-fly de cobrança no hook de e-mail ocorre apenas para o template `invoice_created`; reminders e overdues não disparam geração nova (evita cobrança com vencimento incorreto em e-mail tardio).
- Admin panel (`tools.php`) deixou de gravar `client_secret` via formulário (Option C de segurança). O campo exibe placeholder `••••••••` com instrução para usar Setup → Payments → Banco Inter para alterações de credencial.

### Security
- **BUG-2:** `client_secret` não é mais gravado em texto plano via painel administrativo customizado. Alterações de credencial devem ser feitas pela página nativa de configuração de gateway do WHMCS, que garante armazenamento criptografado.

### Maintenance
- Versão do addon `seixastec_bancointer_admin` atualizada de `1.4.2` para `1.4.4`.
- `whmcs.json`: corrigido typo `webhoook` → `webhook` e acentuação `automática` em features.

## [1.4.3] - 2026-05-08

### Changed
- O PDF do boleto agora é anexado em criação e reminders de invoice somente quando a fatura usa o gateway Banco Inter.
- O hook `EmailPreSend` agora retorna o anexo em memória (`filename` + `data`), formato compatível com WHMCS.
- A configuração `attach_pdf_always` foi neutralizada para impedir boleto Banco Inter em faturas de outros gateways.

## [1.4.2] - 2026-05-01

### Added
- Implementado refund PIX nativo do WHMCS para pagamentos Banco Inter com `endToEndId`, incluindo devolução total/parcial, chamada `PUT /pix/v2/pix/{e2eId}/devolucao/{id}` e persistência do último refund no registro local.
- Adicionados escopos OAuth `pix.read` e `pix.write` para suportar devoluções PIX.

## [1.4.1] - 2026-05-01

### Added
- Adicionado addon administrativo `seixastec_bancointer_admin` para expor o painel Banco Inter no menu **Addons** do WHMCS.

### Changed
- Painel administrativo abre por padrão em **Configurações** em vez da tela de licença.
- Removidas as áreas **Informações da Licença** e **Templates de Mensagem** do painel.
- Compactada a interface do painel administrativo, reduzindo tamanhos de títulos, labels, campos, botões, filtros e tabelas.
- Documentação do webhook agora mostra a URL com `?token=...` e orienta re-registrar o webhook após rotação do token.

### Fixed
- Callback do Banco Inter agora usa o nome técnico do gateway ao chamar `addInvoicePayment()`.
- Callback aceita payloads em lote, payloads Pix aninhados e conciliação por `codigoSolicitacao`, `nossoNumero`, `txid`, `endToEndId` ou `seuNumero`.
- Callback registra rejeições importantes como token inválido, payload sem identificador, status não pago, invoice inválida e transação duplicada.
- Callback tolera atraso da API após pagamento Pix, usando o webhook autenticado quando o evento traz valor, identificador Pix ou data de pagamento.

## [1.4.0] - 2026-05-01

### Changed
- O painel administrativo Banco Inter agora fica apenas dentro da configuração do gateway/ponto `modules/gateways/seixastec_bancointer/admin.php`; a instalação não deve mais expor o addon legado em **Addons**.
- O bloco de pagamento no invoice agora tenta atualizar a cobrança no Banco Inter quando já existe `codigo_solicitacao`, mas ainda faltam QR Code PIX, PIX copia e cola ou linha digitável no registro local.
- O endpoint de QR Code (`generate.php?action=qr`) também força uma atualização da cobrança antes de retornar erro por PIX ausente.
- O token CSRF agora é invalidado após validação bem-sucedida, forçando novo token para a próxima ação sensível.
- Os filtros de data do painel administrativo agora validam datas em formato `YYYY-MM-DD` antes de aplicar consultas.

### Fixed
- Removido o addon legado `Seixastec Bancointer Admin` do pacote.
- Corrigido o invoice para não renderizar botão de copiar PIX, QR Code ou linha digitável vazios quando a API do Inter ainda está processando os dados da cobrança.
- Adicionada mensagem clara de "dados de pagamento em processamento" quando a cobrança foi emitida, mas o Inter ainda não retornou os campos de pagamento.
- Resolvidos marcadores de conflito remanescentes no `README.md`.
- Removida a função pública `seixastec_bancointer_refund()` para impedir que o WHMCS exponha reembolso automático não suportado pelo Banco Inter.
- Endpoints binários de QR/PDF e falhas de geração automática deixam de expor mensagens internas de exceção ao cliente.
- Métricas do painel administrativo agora escapam HTML antes de renderizar os valores.
- Extrato, logs do módulo e logs de webhook exibem aviso quando a listagem atinge o limite de 100 registros.
- Resolvidos marcadores de conflito remanescentes no `.gitignore`.

## [1.3.0] - 2026-04-27

### Added
- Nova opção de gateway `attach_pdf_always` ("Anexar boleto em todas as faturas") que força o anexo do PDF do boleto Banco Inter em e-mails de criação e lembretes, mesmo quando o método de pagamento da fatura não é Banco Inter.
- Suporte ao template `Overdue Invoice Notification` no hook `EmailPreSend` (PDF agora é anexado também em notificações de fatura vencida).
- Geração on-the-fly da cobrança no hook de e-mail quando `attach_pdf_always` está ativo e a fatura ainda não tem cobrança associada.

### Changed
- Hook `InvoiceCreation` (`seixastec_bancointer_auto_generate.php`) agora também gera a cobrança quando `attach_pdf_always` está ativo, independentemente do método de pagamento da fatura.

### Fixed
- Resolvidos conflitos de merge não resolvidos em `modules/gateways/seixastec_bancointer.php` e `modules/gateways/callback/seixastec_bancointer.php` (mantida arquitetura HEAD com `BancoInterHelper`, hooks externos e painel admin).

## [1.2.0] - 2026-04-27

### Fixed
- Fixed PIX QR Code image not loading in the client area due to session initialization issues in `generate.php`.
- Fixed PDF download redirecting to the client area instead of triggering a direct download.
- Added support for admin sessions (`adminid`) in `generate.php` to allow administrators to view QR codes and download PDFs when using "View as Client".

### Changed
- Refactored `generate.php` to handle binary responses (PNG, PDF) using a lightweight session check, bypassing the full WHMCS `ClientArea` initialization for these actions.
- Changed PDF `Content-Disposition` to `attachment` to force browser download.
- Updated Gateway version to 1.2.
- Updated Addon version to 1.2.0.

## [1.1.0] - 2026-04-20

### Added
- Initial stable release of Banco Inter API v3 integration.
- Automatic webhook registration.
- PIX and Boleto support.
