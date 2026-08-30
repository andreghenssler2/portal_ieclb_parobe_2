# Portal IECLB Parobé v0.62.0

## Central de Pendências e Alertas

A v0.62.0 transforma o Dashboard em um ponto de acompanhamento operacional.

### Nova Central de Pendências

Novo endereço administrativo:

`admin/pendencias.php`

A tela reúne, de acordo com as permissões do usuário:

- notícias aguardando revisão;
- notícias com ajustes solicitados;
- notícias aprovadas aguardando publicação;
- notícias agendadas;
- comentários aguardando moderação;
- novas respostas de formulários;
- alertas de segurança das últimas 24 horas.

### Painel

- Novo sino de pendências na barra superior.
- Badge com a quantidade total de itens que exigem atenção.
- Novo atalho **Pendências** no menu lateral.
- Novo atalho **Fila de revisão** dentro de Posts / Notícias.
- Dashboard passa a destacar a quantidade de pendências e as principais categorias.
- A contagem respeita as permissões do perfil.

### Compatibilidade

A v0.62.0 não cria novas tabelas.

Ela usa dados dos módulos existentes e continua funcionando quando um módulo
opcional não possui registros ou quando uma consulta não está disponível.

Requer a v0.61.0 concluída para integrar corretamente a fila editorial.
