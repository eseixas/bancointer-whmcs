# Changelog

All notable changes to this project will be documented in this file.

## [1.2.0] - 2026-04-27

### Fixed
- Fixed PIX QR Code image not loading in the client area due to session initialization issues in `generate.php`.
- Fixed PDF download redirecting to the client area instead of triggering a direct download.
- Added support for admin sessions (`adminid`) in `generate.php` to allow administrators to view QR codes and download PDFs when using "View as Client".

### Changed
- Refactored `generate.php` to handle binary responses (PNG, PDF) using a lightweight session check, bypassing the full WHMCS `ClientArea` initialization for these actions.
- Changed PDF `Content-Disposition` to `attachment` to force browser download.
- Updated Gateway version to 1.2.
- Updated Addon version to 1.0.1.

## [1.1.0] - 2026-04-20

### Added
- Initial stable release of Banco Inter API v3 integration.
- Automatic webhook registration.
- PIX and Boleto support.
