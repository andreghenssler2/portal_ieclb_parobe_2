# Portal IECLB Parobé — v0.98.0

## Acessibilidade e navegação por teclado

A v0.98.0 adiciona a camada final de acessibilidade estrutural antes da fase de
release candidate.

### Portal público

- link “Ir para o conteúdo” visível ao receber foco;
- `main` com alvo explícito para o skip link;
- navegação principal identificada com `aria-label`;
- foco visível reforçado em links, botões e formulários;
- suporte a modo de alto contraste/forced colors;
- respeito a `prefers-reduced-motion`.

### Admin

- skip link para o conteúdo administrativo;
- `main` administrativo com alvo de foco;
- barra superior identificada;
- mesma camada de foco visível.

### Auditoria

Novo caminho:

`Admin → Ferramentas → Acessibilidade`

A auditoria estrutural também procura, em páginas públicas principais:

- imagens sem `alt`;
- iframes sem `title`.

Esses itens são avisos para revisão manual e não são corrigidos
automaticamente.

### CLI

`php tests/accessibility.php`

### Compatibilidade v0.97

O instalador v0.98 reconstrói defensivamente o bloco de CSS do Admin. Assim,
também evita que uma instalação com o problema conhecido da primeira v0.97
mantenha o `<head>` quebrado.
