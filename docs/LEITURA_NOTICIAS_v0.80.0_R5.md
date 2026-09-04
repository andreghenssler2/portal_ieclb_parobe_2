# v0.80.0 R5 — Correção da imagem de capa

A R5 corrige um defeito introduzido no reparo anterior ao envolver apenas uma
parte da tag `<img>` da imagem de capa.

Quando a opção "Não, ocultar na leitura" era usada, podia sobrar texto como:

```text
" alt="Título da notícia">
```

na página pública.

A R5 reconstrói o bloco completo da imagem de capa em `noticia.php` e passa a
aplicar a condição ao elemento inteiro.

Nenhuma alteração no banco de dados é feita.
