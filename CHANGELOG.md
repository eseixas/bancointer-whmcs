# Changelog

All notable changes to this project will be documented in this file.

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
