# Portal IECLB Parobé — v1.1.9

## Comunidades

A v1.1.9 transforma cada comunidade em uma página pública completa.

### Página própria

Cada comunidade ativa recebe uma página acessível por:

`comunidade.php?slug=<slug>`

A listagem `comunidades.php` passa a mostrar cards com foto de capa e botão
para acessar essa página.

### Informações disponíveis

- descrição;
- endereço;
- mapa Google Maps;
- horários de cultos;
- pastor(a) ou responsável;
- telefone;
- WhatsApp;
- e-mail;
- Instagram;
- Facebook;
- YouTube;
- site;
- foto de capa;
- galeria com até 30 imagens;
- próximos eventos vinculados à comunidade.

### Administração

`Admin > Comunidades` passa a editar todos os campos da página pública.

As fotos usam a Biblioteca de Mídia já existente.

### Banco

A instalação cria de forma idempotente:

- `comunidade_perfis`
- `comunidade_fotos`

A tabela `comunidades` existente não sofre alteração destrutiva.

`APP_VERSION = 1.1.9`
