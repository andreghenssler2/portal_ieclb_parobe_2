# Portal IECLB Parobé — v0.27.2

Correção do importador WordPress para mídias que ainda permaneciam dependentes do servidor de origem.

## Correções

- O importador agora diferencia uma mídia realmente salva no Portal de uma mídia que possui apenas URL remota no banco.
- Em **Importar apenas novos**, registros de mídia já conhecidos são reprocessados quando o arquivo local não existe.
- Capas (`featured_media`) que ficaram como fallback remoto voltam a ser tentadas em execuções posteriores.
- URLs em `src`, `srcset`, lazy-load e HTML com query string passam a ser normalizadas.
- Tamanhos gerados pelo WordPress, como `imagem-300x200.jpg`, `imagem-768x512.jpg`, `-scaled` e `-rotated`, são associados ao mesmo attachment e podem apontar para a cópia local.
- Imagens existentes dentro do conteúdo são verificadas e, quando possível, relacionadas ao attachment correspondente da REST API.
- Quando uma imagem em `/wp-content/uploads/` não puder ser localizada como attachment, o importador tenta copiá-la diretamente para a Biblioteca de Mídias e substituir a URL no conteúdo.
- O download envia cabeçalhos de navegador/Referer para melhorar compatibilidade com servidores que bloqueiam requisições sem origem.
- O download tenta também URLs alternativas de tamanhos expostas em `media_details.sizes` quando o `source_url` principal falha.

## Banco de dados

Nova tabela:

- `wordpress_import_media_urls`: registra aliases/variações de URLs do mesmo arquivo WordPress e sua localização no Portal.

Os mapeamentos de mídia das versões 0.27.0/0.27.1 são copiados automaticamente para essa tabela durante a atualização.

## Como reparar conteúdo já importado

1. Abra **Ferramentas → Importar WordPress → Mídias**.
2. Mantenha marcados **Baixar mídias para o Portal**, **Trocar URLs de mídia no conteúdo** e **Reparar mídias que ainda usam o WordPress antigo**.
3. Execute no modo **Importar apenas novos**.
4. Depois execute **Posts / Notícias** novamente, também no modo **Importar apenas novos**.
5. Se houver páginas ou eventos importados, repita em **Páginas** e **Eventos**.

Os posts não são duplicados; nessa modalidade somente capas e referências de mídia ainda remotas são reparadas.
