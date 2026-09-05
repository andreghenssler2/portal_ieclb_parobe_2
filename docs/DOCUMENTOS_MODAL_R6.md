# Hotfix v0.89.0 R6 — Modal no módulo Documentos

O formulário `admin/documentos/form.php` deixa de usar um `<select>` longo
para escolher o arquivo da Biblioteca de Mídia.

## Novo fluxo

1. O campo **Arquivo** mostra o documento atualmente selecionado.
2. O botão **Escolher na Biblioteca de Mídia** abre um modal.
3. O modal permite:
   - pesquisar por título, nome e extensão;
   - selecionar um único documento;
   - enviar novos arquivos sem sair da tela;
   - visualizar extensão e tamanho.
4. Ao confirmar, o `midia_id` é atualizado no formulário.

## Arquivos novos

- `admin/_document_media_picker.php`
- `admin/documentos/upload-modal.php`
- `public/js/document-media-picker-v89-r6.js`

## Banco

Nenhuma alteração.

## Versão

`APP_VERSION` permanece `0.89.0`.
