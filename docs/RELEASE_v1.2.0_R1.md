# Portal IECLB Parobé — v1.2.0 R1

## Correção do pré-requisito da Biblioteca de Mídia

O instalador inicial da v1.2.0 verificava a string `PortalMediaPicker` em
`admin/_editor_media_picker.php`.

O componente real usa IDs como:

- `portalMediaPickerModal`;
- `portalMediaGrid`;
- `portalMediaUploadInput`;

por isso a verificação anterior falhava por diferença de maiúsculas/minúsculas,
mesmo com o picker correto instalado.

A R1 valida a estrutura real do componente por três marcadores:

- `portalMediaPickerModal`;
- `portalMediaGrid`;
- `MediaService::isVideo`.

A falha ocorre ainda no preflight da consolidação, portanto a tentativa inicial
não modifica arquivos nem banco.

O `APP_VERSION` final continua sendo `1.2.0`.
