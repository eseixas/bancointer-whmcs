# Banco Inter WHMCS Gateway Development

Este é o plano de implementação do gateway de pagamento do Banco Inter para o WHMCS 9.0.1.

## Objetivo
Desenvolver um módulo WHMCS que suporte Boleto e PIX via Banco Inter (API v3). O módulo gerenciará a visualização da fatura, a geração automática de boletos, o mapeamento do documento do cliente e a reconciliação de pagamentos.

## Requisitos
1. **Certificados (mTLS)**: Campo para o **caminho absoluto** do servidor (ex: `/home/user/inter_certs/`), permitindo que as chaves fiquem seguras, totalmente fora do `public_html`.
2. **Registro de Webhook Automático**: Ferramenta nas configurações para registrar a URL de webhook do WHMCS no Banco Inter de forma rápida e automática.
3. **Anexo em PDF no Email**: Action Hook (`EmailPreSend` / `InvoiceCreation`) para capturar o PDF gerado pelo Banco Inter e anexá-lo ao e-mail de "Fatura Criada".
4. **Vencimento do Boleto e Cancelamento**: O `dataVencimento` enviado para o Banco Inter será **igual** ao configurado no campo `duedate` (vencimento) da fatura do WHMCS. O cancelamento ("Baixa Automática") poderá ser parametrizado nos ajustes (padrão 15 dias).
5. **Juros, Multa e Descontos**: Opções para gerenciar juros ao mês, percentual de multa de atraso, e sistema percentual/fixo de desconto para pagamento antecipado.

## Mudanças Propostas

### 1. Módulo Gateway Principal
#### [NEW] modules/gateways/bancointer.php
- `bancointer_config()`: 
  - ClientID / ClientSecret e Caminhos mTLS.
  - Checkbox "Gerar Boleto Automático".
  - Configurações de Faturamento: Multa (%), Juros ao Mês (%), **Dias para Baixa Automática (Ex: 15)** e **Desconto para Pagto. Antecipado (%)**.
  - Dropdown: Campo Customizado de CPF/CNPJ.
  - Botão utilitário de Registro de Webhook.
- `bancointer_link()`: Renderiza o bloco de pagamento visual (QR Code PIX e Dados do Boleto) com os dados em banco local (não em cascata com a API bancária). Se não estiver no banco (porque auto-gerar está desligado), fornece um formulário para emissão.

### 2. Integração da API 
#### [NEW] modules/gateways/bancointer/BancoInterAPI.php
- Classe gerenciadora de OAuth 2.0 via mTLS.
- Endpoint de Emissão de Cobrança (Pix e Boleto unidos, v3).
- Salva o hash EMV do Pix, o QR Code em base64 e as linhas do Boleto no Custom DB Logging (`mod_bancointer_transactions`). 

### 3. Recebedor de Callbacks (Webhook)
#### [NEW] modules/gateways/callback/bancointer.php
- Endereço exposto para o Banco Inter avisar sobre Pix recebido ou Boleto compensado.
- Chama internamente o WHMCS (`addInvoicePayment`) informando TXID e ID da transação (Nosso Número/E2EId).

### 4. Hook para Anexar PDF Padrão no E-mail
#### [NEW] includes/hooks/bancointer_email_pdf.php
- Intercepta disparo do e-mail da fatura.
- Chama API no Banco Inter requisitando o Base64 do PDF e converte diretamente para anexo em memória `(name: "Boleto_Fatura_{ID}.pdf")`.

### 5. Hook de Geração Automática
#### [NEW] includes/hooks/bancointer_auto_generate.php
- Dispara quando o WHMCS gera uma fatura ("InvoiceCreation").
- Aplica a lógica: Fatura WHMCS Vencimento = Banco Inter Vencimento.
- Aciona o cancelamento/baixa baseando-se nos dias do setup.
- Aplica as regras percentuais de descontos.

---

> [!NOTE]
> ## Planejamento Registrado
> O plano acima concentra **todas** as regras estipuladas. Caso esteja satisfeito com este escopo para começarmos o código, basta aprovar! Se quiser refinar detalhes sobre como será aplicado esse desconto (quantos dias de antecipação), é só informar.
