# Portal IECLB Parobé v0.28.1

## Correções da Página Inicial

- Corrige o caso em que as três seções padrão da v0.28.0 aparecem como **Inativa**.
- Se `Últimas Notícias`, `Comunidades` e `Paróquia` estiverem todas inativas após a atualização anterior, elas são reativadas automaticamente uma única vez pela atualização.
- Preserva o topo institucional e o bloco **Próximos cultos e eventos** do `index.php` atual.
- A home modular passa a ser anexada ao final do conteúdo existente em vez de substituir indiscriminadamente a tag `<main>`.
- Quando a v0.28.0 tiver substituído o `<main>`, o atualizador tenta restaurar automaticamente o backup criado pela própria v0.28.0 antes de aplicar a nova integração.
- Seções antigas com o mesmo título de uma seção modular ativa (por exemplo, `Últimas notícias`) são ocultadas no front-end para evitar duplicação.
- Botão de visibilidade no painel agora mostra claramente **Ativar** ou **Desativar**.
- O painel exibe aviso quando nenhuma seção está ativa.
- Ajuste de largura do conteúdo modular para melhor encaixe com o layout atual do portal.

## Instalação

Extraia os arquivos sobre a instalação atual e execute:

```bash
php atualizar_v0.28.1.php
```
