# Portal IECLB Parobé — v1.1.6 R2

Reparo do instalador v1.1.6.

O instalador inicial dependia de blocos exatos em `admin/midias/index.php`.
A R1 usa regex/âncoras mais tolerantes e valida tudo antes de gravar.

Recursos:

- filtro separado **Vídeos**;
- preview/player MP4 na Biblioteca;
- player MP4 em Detalhes da mídia;
- seletor de mídia do TinyMCE com imagens e MP4;
- upload de MP4 pelo próprio editor;
- inserção responsiva com `<video controls>`;
- imagem destacada continua somente imagem;
- Páginas usam transporte base64url para reduzir bloqueios de ModSecurity.

Sem migração. `APP_VERSION = 1.1.6`.

## Reparo específico R2

O R1 ainda procurava a declaração JavaScript `pageEditorForm` como um bloco
multilinha exato. Em Windows/XAMPP o arquivo pode usar CRLF, enquanto o pacote
foi gerado com LF. A R2 localiza essa declaração por expressão regular
tolerante a espaços e quebras de linha, funcionando em Windows e Linux.

A falha do R1 ocorreu durante a preparação em memória, antes do backup/escrita.
