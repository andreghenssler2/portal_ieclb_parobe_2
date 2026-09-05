# Hotfix v0.89.0 R5 — Imagens sempre pela Biblioteca de Mídia

Esta atualização padroniza os pontos do Admin que ainda escolhiam imagens
diretamente por `<select>`, grade fixa ou upload solto.

## Padrão adotado

Sempre que uma tela precisa **escolher/inserir uma imagem em um conteúdo**:

1. o formulário guarda somente o ID da mídia em campo oculto;
2. o usuário clica em **Escolher na Biblioteca de Mídia**;
3. abre o modal compartilhado;
4. o modal permite busca;
5. o modal permite upload;
6. a imagem escolhida volta para a tela com prévia;
7. o salvamento continua usando o mesmo campo/ID do banco.

## Telas ajustadas pela R5

- Banners — imagem do banner;
- Eventos/Cultos — imagem destacada;
- Eventos/Cultos — botão de imagem do TinyMCE;
- Galerias — capa;
- Galerias — seleção múltipla das fotos;
- SEO → Social — imagem social, caso o R4 ainda não tenha sido aplicado.

## Telas já padronizadas e preservadas

- Notícias;
- Páginas;
- Lideranças;
- Configurações gerais — logo e favicon;
- Newsletter — imagens dentro do editor;
- Blocos de conteúdo — bloco Imagem.

## Exceção intencional

A própria **Biblioteca de Mídia** continua com seus comandos de upload e
gerenciamento fora de modal. Ela é a origem das imagens, não um campo de
seleção de imagem de outro conteúdo.

## Banco

Não há alteração de banco.

## Versão

APP_VERSION permanece 0.89.0.
