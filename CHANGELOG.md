# Changelog

All notable changes to this project will be documented in this file.

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
