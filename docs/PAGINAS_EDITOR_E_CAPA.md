# v0.82.0 — Páginas com o mesmo padrão visual das Notícias

A v0.82.0 aplica às Páginas a mesma regra usada nas Notícias.

## Configurações > Mídia

Nova opção:

```text
Imagem de capa das Páginas

(•) Não, ocultar na leitura
( ) Sim, exibir ao abrir a página
```

O padrão é **Não mostrar**.

A configuração é global para todas as Páginas.

## Leitura pública

Na página individual:

- o `resumo` não aparece como texto no início;
- o resumo continua disponível para SEO e outras áreas administrativas;
- a imagem de capa obedece à configuração global de Mídia;
- a imagem continua disponível para Open Graph/compartilhamento;
- se o primeiro parágrafo do conteúdo legado for exatamente igual ao resumo,
  a duplicação é ocultada somente na renderização pública.

## Editor de Páginas

O editor passa a usar o mesmo layout visual do editor de Posts/Notícias:

- barra superior;
- título grande no canvas;
- permalink;
- editor principal;
- blocos de conteúdo;
- SEO;
- painel lateral de configurações;
- imagem destacada;
- resumo recolhível;
- status/publicação/slug;
- página superior;
- menu público;
- ordem;
- revisões e visualização.

Não existe opção individual para exibir/ocultar a capa. Isso é definido
somente em `Configurações > Mídia`.

## Banco

Nova coluna:

```text
paginas.exibir_imagem_capa TINYINT(1) NOT NULL DEFAULT 0
```

Ela é mantida sincronizada por compatibilidade, embora a leitura pública use a
configuração global.


## R2 do instalador

A R2 torna a integração com `Configurações > Mídia` independente da
formatação exata criada pela v0.81. Ela localiza a configuração das capas das
Notícias pelo nome da chave e pelo fim real da instrução PHP.

Também reconstrói a tag completa da imagem de capa de `pagina.php`, evitando
o problema já corrigido nas Notícias em que atributos HTML poderiam ficar
visíveis ao ocultar a imagem.
