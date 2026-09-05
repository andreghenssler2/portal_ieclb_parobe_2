# v0.84.0 R2 — Correção do carregamento do Autosave

Sintoma:

```text
Serviço de autosave indisponível.
```

O endpoint `admin/autosave.php` estava sendo alcançado normalmente, porém a
classe `ContentAutosaveService` não estava carregada naquela requisição.

A R2 torna o carregamento redundante e seguro:

1. garante `app/Services/ContentAutosaveService.php`;
2. garante o carregamento condicional no `bootstrap.php`;
3. o próprio `admin/autosave.php` possui um fallback e carrega diretamente o
   serviço se a classe ainda não estiver disponível;
4. garante a tabela `content_autosaves`;
5. mantém a versão em `0.84.0`.

Com isso, mesmo que o bootstrap de uma instalação tenha sido alterado de forma
diferente, o endpoint de autosave continua funcional.
