# Portal IECLB Parobé — v0.91.0

## Central de Consentimento de Cookies

A v0.91.0 adiciona uma camada de consentimento para tecnologias opcionais no Portal público.

### Categorias

- **Necessários** — sempre ativos;
- **Estatísticas** — opcional;
- **Marketing** — opcional.

### Integrações

- Google Analytics 4 é classificado como **Estatísticas**;
- Google Tag Manager pode ser configurado como **Estatísticas** ou **Marketing**.

Enquanto não houver consentimento válido, GA4 e GTM não são emitidos quando a Central estiver ativa.

### Admin

Novo caminho:

`Admin → Configurações → Cookies`

O administrador pode configurar ativação, versão do consentimento, validade, textos, categorias, classificação do GTM e link permanente no rodapé.

### Armazenamento

A escolha fica no navegador no cookie `portal_cookie_consent`. Não é criada tabela de visitantes.

### Reconsentimento

Ao aumentar a versão no Admin, escolhas antigas deixam de ser válidas e o aviso volta a aparecer.

### Observação

A implementação técnica não substitui a revisão jurídica da Política de Privacidade e dos textos usados no Portal.


## Compatibilidade R2

O aviso informativo na tela de Privacidade é inserido de forma tolerante.
Diferenças locais no texto do Google Analytics não impedem a atualização.
