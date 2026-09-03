# Bloco Importar Formulário — v0.77.0

A v0.77.0 integra os formulários existentes ao editor de blocos de Páginas e
Notícias.

## Novo bloco

No editor:

```text
Adicionar bloco
→ Conteúdo dinâmico do Portal
→ Importar Formulário
```

O administrador escolhe um formulário já publicado e ativo.

## Opções do bloco

- formulário;
- título personalizado;
- mostrar/ocultar título;
- mostrar/ocultar descrição;
- visual em Card ou Limpo;
- texto do botão de envio.

## Respostas

O bloco não cria um segundo sistema de formulários.

As respostas continuam sendo armazenadas em:

```text
formulario_respostas
formulario_resposta_valores
```

e continuam aparecendo em:

```text
Admin → Formulários → Respostas
```

As notificações configuradas no formulário continuam usando
`FormNotificationService`.

## Segurança

O formulário incorporado mantém:

- CSRF;
- honeypot;
- limitação de reenvio em poucos segundos;
- validação de obrigatórios;
- validação de e-mail;
- validação de número;
- validação de opções do select;
- retorno apenas para URL local do próprio Portal.

Um formulário só pode ser incorporado/publicamente enviado quando estiver:

```text
status = publicado
ativo = 1
publicado_em <= agora
```

## Estado de erros/sucesso

Depois do POST o visitante volta para o mesmo bloco da Página/Notícia.

Erros e valores digitados são guardados apenas na sessão até a próxima
renderização daquele bloco.

## Banco

Nenhuma migração é necessária.

A v0.77 reutiliza a estrutura de Formulários já existente.
