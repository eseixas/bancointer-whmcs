# Changelog

All notable changes to this project will be documented in this file.

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
