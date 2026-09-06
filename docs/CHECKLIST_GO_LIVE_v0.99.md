# Checklist de Go-Live — Portal IECLB Parobé

## Ambiente

- [ ] APP_ENV = production
- [ ] APP_DEBUG = false
- [ ] BASE_URL aponta para o domínio definitivo
- [ ] HTTPS ativo e sem conteúdo misto
- [ ] timezone correto
- [ ] PHP 8.2 ou superior

## Banco e arquivos

- [ ] Banco de produção revisado
- [ ] `storage` e uploads graváveis
- [ ] backup do banco criado
- [ ] backup completo criado
- [ ] teste de restaurabilidade aprovado

## Segurança

- [ ] 2FA administrativo testado
- [ ] perfis e permissões auditados
- [ ] scripts de atualização/diagnóstico bloqueados na web
- [ ] CSP/cabeçalhos HTTP revisados
- [ ] sessões administrativas testadas

## Automação

- [ ] Cron Job configurado
- [ ] heartbeat recente
- [ ] tarefas sem erros consecutivos
- [ ] backup automático agendado

## E-mail

- [ ] SMTP testado
- [ ] SPF válido
- [ ] DKIM válido
- [ ] DMARC publicado
- [ ] e-mail de formulário testado
- [ ] newsletter testada

## Portal público

- [ ] Home revisada
- [ ] Notícias revisadas
- [ ] Agenda revisada
- [ ] Comunidades revisadas
- [ ] Grupos e lideranças revisados
- [ ] formulários revisados
- [ ] documentos revisados
- [ ] busca revisada

## SEO e Analytics

- [ ] sitemap.xml validado
- [ ] RSS validado
- [ ] canonical revisado
- [ ] robots/indexação configurados
- [ ] GTM/Analytics configurados
- [ ] consentimento de cookies testado

## Experiência

- [ ] desktop
- [ ] 320 px
- [ ] 375 px
- [ ] 430 px
- [ ] tablet
- [ ] navegação por teclado
- [ ] zoom 200%
- [ ] imagens com alt relevante
- [ ] formulários com mensagens de erro claras

## Encerramento

- [ ] suíte geral sem falhas
- [ ] Central de Pré-produção sem bloqueadores
- [ ] backup final antes do go-live
- [ ] v1.0.0 preparada
