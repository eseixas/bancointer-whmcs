# PLAN - Anexar Boleto no E-mail de Fatura Modificada

Este plano detalha a correção para garantir que o boleto PDF do Banco Inter seja anexado aos e-mails enviados a partir do template "Invoice Modified" (ou equivalentes traduzidos), mesmo que a fatura tenha sido alterada ou não. Também inclui a publicação das alterações no repositório GitHub, a atualização do changelog, o incremento de versão para `1.4.5` e o deploy no servidor FTP de produção.

## User Review Required

> [!NOTE]
> Esta alteração afeta apenas o fluxo de envio de e-mails do WHMCS (`EmailPreSend`). Ela garante que o e-mail do tipo "Invoice Modified" seja classificado corretamente e que o boleto correspondente seja anexado.
> Se a fatura modificada ainda não possuir uma cobrança ativa gerada, o hook gerará uma nova cobrança na API do Banco Inter no momento do envio e anexará o boleto gerado.

## Proposed Changes

---

### WHMCS Hooks

#### [MODIFY] [seixastec_bancointer_email_pdf.php](file:///c:/Temp/code/bancointer-whmcs/includes/hooks/seixastec_bancointer_email_pdf.php)

- **Alteração 1:** Atualizar a função `seixastec_bancointer_classifyEmail()` para reconhecer o template "Invoice Modified" (e suas variações em português como "Fatura Alterada", "Fatura Modificada").
  ```php
  function seixastec_bancointer_classifyEmail(string $messageName): ?string
  {
      $lower = strtolower($messageName);

      // Order matters: check "overdue" before generic "invoice" patterns.
      if (
          str_contains($lower, "overdue") ||
          str_contains($lower, "vencid") ||
          str_contains($lower, "past due")
      ) {
          return "overdue";
      }

      if (str_contains($lower, "reminder")) {
          return "reminder";
      }

      if (
          str_contains($lower, "modified") ||
          str_contains($lower, "alterad") ||
          str_contains($lower, "modificad")
      ) {
          return "invoice_modified";
      }

      if (
          (str_contains($lower, "invoice") && str_contains($lower, "creat")) ||
          (str_contains($lower, "fatura") && str_contains($lower, "cri"))
      ) {
          return "invoice_created";
      }

      return null;
  }
  ```

- **Alteração 2:** Permitir que o bucket `invoice_modified` também possa gerar a cobrança Banco Inter em tempo de execução (on-the-fly) caso não exista nenhuma transação ativa.
  ```php
  // No active cobrança exists yet.
  if (!$tx || empty($tx->codigo_solicitacao)) {
      // Only invoice_created and invoice_modified emails trigger on-the-fly generation.
      if ($classification !== "invoice_created" && $classification !== "invoice_modified") {
          BancoInterHelper::log(
              "hook.email_pdf",
              ["invoiceid" => $relid, "messagename" => $messageName, "classification" => $classification],
              "skipped_no_active_tx"
          );
          return;
      }
      ...
  }
  ```

---

### Versão e Changelog

#### [MODIFY] [CHANGELOG.md](file:///c:/Temp/code/bancointer-whmcs/CHANGELOG.md)
- Adicionar uma nova seção para a versão `[1.4.5]` detalhando as alterações.
  ```markdown
  ## [1.4.5] - 2026-05-20

  ### Fixed
  - Adicionado suporte ao template `Invoice Modified` (e variantes como `Fatura Alterada`) no hook `EmailPreSend` para anexar o boleto PDF do Banco Inter ao e-mail enviado ao cliente após alterações na fatura.

  ### Maintenance
  - Versão do addon `seixastec_bancointer_admin` e gateway incrementadas para `1.4.5`.
  ```

#### [MODIFY] [seixastec_bancointer_admin.php](file:///c:/Temp/code/bancointer-whmcs/modules/addons/seixastec_bancointer_admin/seixastec_bancointer_admin.php)
- Incrementar a propriedade `version` de `1.4.4` para `1.4.5`.
  ```php
  "version" => "1.4.5",
  ```

#### [MODIFY] [whmcs.json](file:///c:/Temp/code/bancointer-whmcs/modules/gateways/seixastec_bancointer/whmcs.json)
- Incrementar o campo `version` de `1.4.4` para `1.4.5`.
  ```json
  "version": "1.4.5",
  ```

---

## Deployment & Repository Plan

### 1. Atualização do Repositório (GitHub)
- Enviar as alterações locais para a branch principal do repositório remoto.
- Comandos a serem executados:
  ```bash
  git add includes/hooks/seixastec_bancointer_email_pdf.php CHANGELOG.md modules/addons/seixastec_bancointer_admin/seixastec_bancointer_admin.php modules/gateways/seixastec_bancointer/whmcs.json
  git commit -m "bump: v1.4.5 - attach boleto to Invoice Modified emails"
  git push origin main
  ```

### 2. Publicação no Servidor (FTP)
- Realizar a atualização dos arquivos modificados no servidor remoto utilizando FTP.
- **Detalhes de Conexão:**
  - Servidor: `portugal.nitmail.com`
  - Usuário: `nitmail`
  - Arquivos a subir para `sites/secure.nitmail.com/billing/`:
    - `includes/hooks/seixastec_bancointer_email_pdf.php`
    - `modules/addons/seixastec_bancointer_admin/seixastec_bancointer_admin.php`
    - `modules/gateways/seixastec_bancointer/whmcs.json`

---

## Verification Plan

### Manual Verification
- Enviar um e-mail de teste utilizando o template "Invoice Modified" a partir do admin do WHMCS para uma fatura cujo gateway seja o Banco Inter.
- Verificar se o log do gateway (`WHMCS ➔ Utilities ➔ Logs ➔ Gateway Log`) registra a entrada de telemetria correspondente (com status `attached` ou `mint_failed`/`api_error`).
- Confirmar no e-mail recebido que o PDF do boleto foi anexado e está legível.
