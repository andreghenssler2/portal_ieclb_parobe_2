# Portal IECLB Parobé — v1.1.2 R1

## Correção complementar do agendamento no editor

A R1 complementa a v1.1.2 em dois pontos do editor de Notícias.

### 1. Edição de notícia já publicada

O formulário copiava o `status` enviado por POST para `$post` antes de chamar
`EditorialWorkflowService::assertStatusTransitionAllowed()`.

Isso fazia o serviço receber o status NOVO como se fosse o status anterior.
Exemplo:

- notícia no banco: `publicado`;
- administrador muda para: `agendado`;
- o formulário sobrescrevia `$post['status']` com `agendado`;
- o workflow deixava de reconhecer que a notícia já estava publicada e mostrava
  a mensagem de revisão editorial obrigatória.

A R1 guarda o status original carregado do banco antes de processar o POST.

### 2. Administrador e revisão editorial obrigatória

A revisão obrigatória continua valendo para os demais perfis.

Porém o perfil Administrador passa a ter override explícito para publicação e
agendamento direto pelo editor. Assim o administrador pode:

- criar uma notícia e agendá-la;
- criar e publicar diretamente;
- reagendar uma notícia já publicada.

Usuários comuns com fluxo editorial obrigatório continuam usando a Fila de
Revisão.

### Visibilidade pública

Permanece a regra da v1.1.2:

- `status='agendado'` não aparece na Home;
- `status='publicado'` com data futura também não aparece;
- a notícia só fica pública quando a data estiver disponível.

### Banco

Sem migração.
