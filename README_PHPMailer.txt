Portal IECLB Parobé - correção de e-mail v0.26.0 (PHPMailer)
================================================================

1. Extraia este pacote na raiz do Portal, substituindo os arquivos existentes.
2. No terminal, na raiz do Portal, execute:

   php atualizar_phpmailer_v0.26.0.php

3. O script baixa a versão oficial PHPMailer 7.1.1 diretamente do repositório
   PHPMailer/PHPMailer no GitHub, valida o Git blob SHA de cada arquivo e instala em:

   vendor/phpmailer/phpmailer/

4. Abra no painel:

   Configurações > E-mail

5. Prefira SMTP e use primeiro o botão "Diagnosticar SMTP".

Combinações comuns:
- Porta 465 + SSL/TLS direto
- Porta 587 + STARTTLS

IMPORTANTE: porta 485 não é a porta SMTPS padrão. Se você colocou 485 por engano,
troque para 465 antes do teste.

Se a hospedagem bloquear downloads externos e o instalador não conseguir baixar
PHPMailer, instale-o por Composer:

   composer require phpmailer/phpmailer:^7.1

ou copie manualmente PHPMailer 7.1.1 para vendor/phpmailer/phpmailer/ contendo,
no mínimo:

   src/Exception.php
   src/PHPMailer.php
   src/SMTP.php
   LICENSE

Não há alteração de banco de dados e o APP_VERSION permanece 0.26.0.
