# Portal IECLB Parobé — v1.1.4 R1

## Upload de vídeos MP4

A R1 corrige o instalador inicial da v1.1.4, que dependia de um trecho exato do
`MediaService.php`.

O novo instalador é tolerante a diferenças de formatação e a correções
anteriores já aplicadas no servidor.

### Suporte adicionado

- `video/mp4`
- `application/mp4`
- extensão `.mp4`
- método `MediaService::isVideo()`
- seletor de upload da Biblioteca de Mídia com MP4

A validação continua usando `Fileinfo`, portanto não basta renomear outro tipo
de arquivo para `.mp4`.

Sem migração de banco.

`APP_VERSION = 1.1.4`
