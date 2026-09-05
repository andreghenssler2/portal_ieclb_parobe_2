# Portal IECLB Parobé — v0.97.0

## Refinamento Mobile

A v0.97.0 complementa a responsividade global existente no Portal com uma camada
final para celular e tablet.

### Portal público

- alvos de toque com pelo menos 44 px na navegação;
- menu recolhido com rolagem vertical usando `100dvh`;
- campos com 16 px em celular para evitar zoom automático no iOS;
- tabelas e modais mais confortáveis;
- tipografia fluida;
- paginação adaptada ao toque;
- rodapé melhorado;
- suporte a `prefers-reduced-motion`.

### Admin

- offcanvas limitado à largura útil do telefone;
- botões e itens do menu com área de toque maior;
- formulários com fonte mínima de 16 px;
- tabelas e paginações com rolagem/touch;
- ações flexíveis em telas estreitas;
- topo compactado em celulares pequenos;
- modais limitados a `100dvh`.

### Compatibilidade

A nova camada não substitui `responsive-v51.css`. Ela é carregada depois e atua
como refinamento final.

### CLI

`php tests/mobile.php`
