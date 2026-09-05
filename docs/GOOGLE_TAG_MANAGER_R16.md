# Hotfix v0.89.0 R16 — Google Tag Manager

Adiciona suporte administrável ao **Google Tag Manager (GTM)**.

## Admin

Novo caminho:

`Admin → SEO → Google Tag Manager`

Campos:

- ID do contêiner (`GTM-XXXXXXX`);
- Ativar/desativar.

## Portal público

Quando ativo e com ID válido:

1. o snippet GTM é inserido antes de `</head>`;
2. o bloco `noscript` é inserido logo após a abertura do `<body>`.

O Portal não injeta GTM no painel administrativo.

## Temas

O instalador procura `theme/*/header.php` e adiciona os dois pontos de integração
em cada tema compatível que possua `</head>` e `<body>`.

## CSP

O Portal já permite `www.googletagmanager.com` em `script-src`. A R16 também
adiciona o domínio ao `connect-src` e `frame-src`, necessário para o carregamento
do contêiner e do fallback `noscript`.

## Segurança

O ID é validado com o formato:

`GTM-[A-Z0-9]+`

Não existe campo de JavaScript livre no Admin.

## Banco

Sem migração. As configurações são salvas na tabela `configuracoes`.

APP_VERSION permanece 0.89.0.


## Compatibilidade R16R3

Temas que apenas incluem o header de outro tema, como `theme/classico/header.php`,
são tratados como wrappers. O GTM é inserido somente no documento HTML real,
evitando duplicação do contêiner.
