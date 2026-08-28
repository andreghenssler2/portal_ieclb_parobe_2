<?php

declare(strict_types=1);

final class HomeService
{
    private PDO $pdo;
    private array $columnCache = [];
    private array $tableCache = [];
    private ?array $categoryRelationCache = null;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function sections(bool $onlyActive = true): array
    {
        if (!$this->tableExists('home_secoes')) {
            return [];
        }
        $sql = 'SELECT * FROM home_secoes';
        if ($onlyActive) {
            $sql .= ' WHERE ativo=1';
        }
        $sql .= ' ORDER BY ordem ASC, id ASC';
        return $this->pdo->query($sql)->fetchAll() ?: [];
    }

    public function section(int $id): ?array
    {
        if ($id <= 0 || !$this->tableExists('home_secoes')) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT * FROM home_secoes WHERE id=:id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function categories(): array
    {
        if (!$this->tableExists('categorias')) {
            return [];
        }
        $cols = $this->columns('categorias');
        $name = $this->findColumn($cols, ['nome', 'name', 'titulo']);
        if (!$name) {
            return [];
        }
        $slug = $this->findColumn($cols, ['slug']);
        $select = '`id`, `' . $name . '` AS nome' . ($slug ? ', `' . $slug . '` AS slug' : ", '' AS slug");
        return $this->pdo->query('SELECT ' . $select . ' FROM categorias ORDER BY `' . $name . '` ASC')->fetchAll() ?: [];
    }

    public function saveSection(array $data, int $userId): int
    {
        $id = (int)($data['id'] ?? 0);
        $title = trim((string)($data['titulo'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('Informe o título da seção.');
        }

        $type = (string)($data['tipo'] ?? 'carousel');
        if (!in_array($type, ['featured', 'carousel', 'grid'], true)) {
            $type = 'carousel';
        }
        $source = (string)($data['origem'] ?? 'posts');
        if (!in_array($source, ['posts', 'eventos', 'paginas', 'mais_lidas'], true)) {
            $source = 'posts';
        }
        $limit = max(1, min(20, (int)($data['limite'] ?? 8)));
        $category = (int)($data['categoria_id'] ?? 0);
        $category = $category > 0 ? $category : null;
        $active = !empty($data['ativo']) ? 1 : 0;

        $datePosition = (string)($data['date_position'] ?? 'after');
        if (!in_array($datePosition, ['before', 'after'], true)) {
            $datePosition = 'after';
        }
        $background = (string)($data['background'] ?? 'white');
        if (!in_array($background, ['white', 'soft'], true)) {
            $background = 'white';
        }

        $width = (string)($data['width'] ?? 'full');
        if (!in_array($width, ['full', 'half'], true)) {
            $width = 'full';
        }

        $config = [
            'show_date' => !empty($data['show_date']),
            'show_excerpt' => !empty($data['show_excerpt']),
            'autoplay' => !empty($data['autoplay']),
            'date_position' => $datePosition,
            'background' => $background,
            'width' => $width,
        ];

        $payload = [
            'titulo' => $title,
            'tipo' => $type,
            'origem' => $source,
            'categoria_id' => $category,
            'link_texto' => trim((string)($data['link_texto'] ?? '')),
            'link_url' => trim((string)($data['link_url'] ?? '')),
            'limite' => $limit,
            'ativo' => $active,
            'configuracao_json' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'usuario_id' => $userId > 0 ? $userId : null,
        ];

        if ($id > 0 && $this->section($id)) {
            $sql = 'UPDATE home_secoes SET titulo=:titulo,tipo=:tipo,origem=:origem,categoria_id=:categoria_id,link_texto=:link_texto,link_url=:link_url,limite=:limite,ativo=:ativo,configuracao_json=:configuracao_json,usuario_id=:usuario_id,updated_at=NOW() WHERE id=:id';
            $payload['id'] = $id;
            $this->pdo->prepare($sql)->execute($payload);
            return $id;
        }

        $next = (int)$this->pdo->query('SELECT COALESCE(MAX(ordem),0)+10 FROM home_secoes')->fetchColumn();
        $payload['ordem'] = $next;
        $sql = 'INSERT INTO home_secoes (titulo,tipo,origem,categoria_id,link_texto,link_url,limite,ativo,ordem,configuracao_json,usuario_id,created_at,updated_at) VALUES (:titulo,:tipo,:origem,:categoria_id,:link_texto,:link_url,:limite,:ativo,:ordem,:configuracao_json,:usuario_id,NOW(),NOW())';
        $this->pdo->prepare($sql)->execute($payload);
        return (int)$this->pdo->lastInsertId();
    }

    public function deleteSection(int $id): void
    {
        if ($id <= 0) return;
        $stmt = $this->pdo->prepare('DELETE FROM home_secoes WHERE id=:id');
        $stmt->execute(['id' => $id]);
    }

    public function toggleSection(int $id): void
    {
        if ($id <= 0) return;
        $stmt = $this->pdo->prepare('UPDATE home_secoes SET ativo=IF(ativo=1,0,1),updated_at=NOW() WHERE id=:id');
        $stmt->execute(['id' => $id]);
    }

    public function reorder(array $ids): void
    {
        $stmt = $this->pdo->prepare('UPDATE home_secoes SET ordem=:ordem,updated_at=NOW() WHERE id=:id');
        $order = 10;
        foreach ($ids as $id) {
            $id = (int)$id;
            if ($id <= 0) continue;
            $stmt->execute(['ordem' => $order, 'id' => $id]);
            $order += 10;
        }
    }

    public function itemsForSection(array $section): array
    {
        $source = (string)($section['origem'] ?? 'posts');
        $limit = max(1, min(20, (int)($section['limite'] ?? 8)));
        $categoryId = (int)($section['categoria_id'] ?? 0);

        if ($source === 'mais_lidas') {
            return NewsEngagementService::popular($this->pdo, $limit, '30');
        }

        return $this->fetchItems($source, $limit, $categoryId > 0 ? $categoryId : null);
    }

    public function config(array $section): array
    {
        $config = json_decode((string)($section['configuracao_json'] ?? ''), true);
        return is_array($config) ? $config : [];
    }

    public function itemImage(array $row, string $source): string
    {
        if ($source === 'mais_lidas' && !empty($row['imagem_capa_midia'])) {
            return $this->normalizePublicUrl((string)$row['imagem_capa_midia']);
        }
        if ($source === 'mais_lidas') {
            $source = 'posts';
        }

        $table = $source;
        if (!$this->tableExists($table)) return '';
        $cols = $this->columns($table);

        // v0.44.1 - imagem de capa atual do Portal.
        // Posts e Páginas usam imagem_capa_id; ela precisa ter prioridade
        // sobre campos legados e sobre imagens encontradas no conteúdo.
        if (
            isset($cols['imagem_capa_id'])
            && !empty($row['imagem_capa_id'])
        ) {
            $url = $this->mediaUrl((int)$row['imagem_capa_id']);
            if ($url !== '') {
                return $url;
            }
        }

        // v0.28.2: prioriza a referência da Biblioteca de Mídias. Assim, se o
        // post ainda conservar uma URL antiga/absoluta, a home usa o arquivo
        // local que a v0.27.2 já baixou para o Portal.
        foreach (['imagem_destacada_id','midia_id','imagem_id','featured_media_id','capa_id','thumbnail_id','foto_id'] as $col) {
            if (isset($cols[$col]) && !empty($row[$col])) {
                $url = $this->mediaUrl((int)$row[$col]);
                if ($url !== '') return $url;
            }
        }

        $candidates = [
            'imagem_destacada_path','featured_image_path','imagem_path','capa_path','thumbnail_path','foto_path','imagem_arquivo','capa_arquivo',
            'imagem_destacada_url','featured_image_url','imagem_url','capa_url','thumbnail_url','foto_url','image_url',
            'imagem_destacada','featured_image','imagem','capa','thumbnail','foto','image',
        ];
        foreach ($candidates as $col) {
            if (!isset($cols[$col]) || !array_key_exists($col, $row)) continue;
            $value = trim((string)$row[$col]);
            if ($value === '') continue;
            if (ctype_digit($value) && (int)$value > 0) {
                $url = $this->mediaUrl((int)$value);
                if ($url !== '') return $url;
                continue;
            }
            $url = $this->normalizePublicUrl($value);
            if ($url !== '') return $url;
        }

        // Compatibilidade: quando o esquema não possui coluna de capa, usa a
        // primeira imagem do conteúdo já importado/localizado no Portal.
        foreach (['conteudo','content','descricao'] as $contentCol) {
            $html = (string)($row[$contentCol] ?? '');
            if ($html === '') continue;
            if (preg_match('~<img\b[^>]*\bsrc=["\']([^"\']+)["\']~i', $html, $m)) {
                $url = $this->normalizePublicUrl((string)$m[1]);
                if ($url !== '') return $url;
            }
        }
        return '';
    }

    public function itemTitle(array $row): string
    {
        foreach (['titulo','title','nome'] as $key) {
            if (isset($row[$key]) && trim((string)$row[$key]) !== '') return trim((string)$row[$key]);
        }
        return 'Sem título';
    }

    public function itemExcerpt(array $row): string
    {
        foreach (['resumo','excerpt','resumo_curto'] as $key) {
            if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
                return trim(strip_tags((string)$row[$key]));
            }
        }
        foreach (['conteudo','content','descricao'] as $key) {
            if (isset($row[$key]) && trim((string)$row[$key]) !== '') {
                $text = preg_replace('/\s+/u', ' ', trim(strip_tags((string)$row[$key]))) ?: '';
                return function_exists('mb_substr') ? mb_substr($text, 0, 180) : substr($text, 0, 180);
            }
        }
        return '';
    }

    public function itemDate(array $row, string $source): ?DateTimeImmutable
    {
        if ($source === 'mais_lidas') $source = 'posts';
        $keys = $source === 'eventos'
            ? ['inicio','data_inicio','starts_at','start_date','data','created_at']
            : ['publicado_em','data_publicacao','publication_date','data','created_at'];
        foreach ($keys as $key) {
            $value = trim((string)($row[$key] ?? ''));
            if ($value === '') continue;
            try { return new DateTimeImmutable($value); } catch (Throwable $e) {}
        }
        return null;
    }

    public function itemUrl(array $row, string $source): string
    {
        if ($source === 'mais_lidas') $source = 'posts';
        // v0.28.2: aproveita uma URL pública interna já existente apenas quando
        // ela realmente pertence ao Portal. Permalinks externos do WordPress
        // são ignorados. Caminhos físicos antigos são convertidos para URL.
        foreach (['url_publica','permalink','link'] as $key) {
            $stored = trim((string)($row[$key] ?? ''));
            if ($stored === '' || !$this->isInternalContentUrl($stored)) continue;
            $resolved = $this->normalizePublicUrl($stored);
            if ($resolved !== '') return $resolved;
        }

        $slug = trim((string)($row['slug'] ?? ''));
        $id = (int)($row['id'] ?? 0);

        if ($source === 'eventos') {
            return $slug !== '' ? url('evento/' . rawurlencode($slug)) : url('evento.php?id=' . $id);
        }
        if ($source === 'paginas') {
            return $slug !== '' ? url('pagina/' . rawurlencode($slug)) : url('pagina.php?id=' . $id);
        }
        return $slug !== '' ? url('noticia/' . rawurlencode($slug)) : url('noticia.php?id=' . $id);
    }

    private function isInternalContentUrl(string $value): bool
    {
        $normalized = str_replace('\\', '/', trim($value));
        if ($normalized === '') return false;
        if ($this->looksLikePhysicalPath($normalized)) return true;

        if (preg_match('~^https?://~i', $normalized)) {
            $host = strtolower((string)(parse_url($normalized, PHP_URL_HOST) ?: ''));
            $baseHost = strtolower((string)(parse_url(defined('BASE_URL') ? (string)BASE_URL : '', PHP_URL_HOST) ?: ''));
            if ($host === '' || $baseHost === '' || $host !== $baseHost) return false;
        }

        $path = strtolower((string)(parse_url($normalized, PHP_URL_PATH) ?: $normalized));
        return (bool)preg_match('~(?:^|/)(?:noticia|evento|pagina)(?:/|\.php(?:$|\?))~', $path)
            || str_contains($path, '/public/')
            || str_contains($path, '/portal_');
    }

    /**
     * Normaliza uma URL configurada pelo administrador ou armazenada no banco.
     * Aceita URL HTTP(S), caminho relativo e também caminhos físicos do Windows/
     * Linux, convertendo-os para a URL pública da instalação.
     */
    public function publicUrl(string $value): string
    {
        return $this->normalizePublicUrl($value);
    }

    private function fetchItems(string $table, int $limit, ?int $categoryId): array
    {
        if (!$this->tableExists($table)) return [];
        $cols = $this->columns($table);
        $where = [];
        $params = [];
        $status = $this->findColumn($cols, ['status','situacao']);
        if ($status) {
            $where[] = "LOWER(COALESCE(`$status`,'')) NOT IN ('lixeira','trash','rascunho','draft','privado','private','inativo')";
        }

        $joins = '';
        $distinct = '';
        if ($categoryId && $table === 'posts') {
            // v0.29.0: filtra pela união de TODAS as formas de relacionamento
            // disponíveis. Isto é importante porque o Portal pode guardar uma
            // categoria principal no próprio post e as demais em tabela pivô.
            $categoryIds = $this->categoryTreeIds($categoryId);
            if (!$categoryIds) $categoryIds = [$categoryId];
            $categoryIds = array_values(array_unique(array_filter(array_map('intval', $categoryIds), static fn(int $id): bool => $id > 0)));
            $in = implode(',', $categoryIds);
            $categoryWhere = [];

            $direct = $this->findColumn($cols, ['categoria_id','category_id','categoria_principal_id','primary_category_id']);
            if ($direct && $in !== '') {
                $categoryWhere[] = '`' . $table . '`.`' . $direct . '` IN (' . $in . ')';
            }

            foreach ($this->postCategoryRelations() as $index => $relation) {
                if ($in === '') break;
                $alias = 'hcr' . $index;
                $categoryWhere[] = 'EXISTS (SELECT 1 FROM `' . $relation['table'] . '` ' . $alias
                    . ' WHERE ' . $alias . '.`' . $relation['post_col'] . '`=`' . $table . '`.`id`'
                    . ' AND ' . $alias . '.`' . $relation['category_col'] . '` IN (' . $in . '))';
            }

            // Alguns esquemas antigos armazenam IDs em CSV/JSON no próprio post.
            // A comparação usa delimitadores para não confundir 1 com 11.
            foreach (['categorias','categories','categoria_ids','category_ids','categorias_json','categories_json'] as $listCol) {
                if (!isset($cols[$listCol])) continue;
                foreach ($categoryIds as $cid) {
                    $categoryWhere[] = "CONCAT(',',REPLACE(REPLACE(REPLACE(COALESCE(`$table`.`$listCol`,''),'[',''),']',''),' ',''),',') LIKE '%," . (int)$cid . ",%'";
                }
            }

            // Se uma categoria foi escolhida e o esquema não possui nenhuma
            // forma conhecida de associação, não exibe notícias aleatórias.
            $where[] = $categoryWhere ? '(' . implode(' OR ', $categoryWhere) . ')' : '1=0';
        }

        $orderCol = $table === 'eventos'
            ? $this->findColumn($cols, ['inicio','data_inicio','starts_at','start_date','data','created_at','id'])
            : $this->findColumn($cols, ['publicado_em','data_publicacao','publication_date','data','created_at','id']);
        $sql = 'SELECT ' . $distinct . '`' . $table . '`.* FROM `' . $table . '`' . $joins;
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY `' . $table . '`.`' . ($orderCol ?: 'id') . '` DESC, `' . $table . '`.`id` DESC LIMIT ' . (int)$limit;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll() ?: [];
    }


    /**
     * Descobre tabelas que relacionam posts e categorias, incluindo nomes
     * usados por versões antigas e instalações personalizadas do Portal.
     * A tabela home_post_categorias da v0.29.0 sempre tem prioridade.
     */
    private function postCategoryRelations(): array
    {
        if ($this->categoryRelationCache !== null) return $this->categoryRelationCache;

        $postCandidates = ['post_id','posts_id','noticia_id','noticias_id','conteudo_id','content_id'];
        $categoryCandidates = ['categoria_id','categorias_id','category_id','categories_id'];
        $preferred = [
            'home_post_categorias','post_categorias','posts_categorias','post_categoria','posts_categoria',
            'categoria_posts','categorias_posts','categoria_post','categorias_post','post_categories','posts_categories'
        ];

        $tables = [];
        try {
            $stmt = $this->pdo->query('SELECT table_name,column_name FROM information_schema.columns WHERE table_schema=DATABASE() ORDER BY table_name,ordinal_position');
            foreach ($stmt->fetchAll() ?: [] as $row) {
                $table = (string)($row['table_name'] ?? $row['TABLE_NAME'] ?? '');
                $column = (string)($row['column_name'] ?? $row['COLUMN_NAME'] ?? '');
                if ($table !== '' && $column !== '') $tables[$table][] = $column;
            }
        } catch (Throwable $e) {
            $tables = [];
        }

        $ordered = [];
        foreach ($preferred as $name) if (isset($tables[$name])) $ordered[] = $name;
        foreach (array_keys($tables) as $name) {
            if (in_array($name, $ordered, true)) continue;
            $key = strtolower($name);
            if ((str_contains($key, 'post') || str_contains($key, 'noticia')) && (str_contains($key, 'categor') || str_contains($key, 'category'))) {
                $ordered[] = $name;
            }
        }

        $relations = [];
        foreach ($ordered as $table) {
            if (in_array($table, ['posts','categorias','home_secoes'], true)) continue;
            $cols = array_fill_keys($tables[$table] ?? [], true);
            $postCol = $this->findColumn($cols, $postCandidates);
            $categoryCol = $this->findColumn($cols, $categoryCandidates);
            if (!$postCol || !$categoryCol) continue;
            $relations[] = ['table'=>$table,'post_col'=>$postCol,'category_col'=>$categoryCol];
        }

        return $this->categoryRelationCache = $relations;
    }

    private function categoryTreeIds(int $rootId): array
    {
        if ($rootId <= 0 || !$this->tableExists('categorias')) return [];
        $cols = $this->columns('categorias');
        $parentCol = $this->findColumn($cols, ['pai_id','parent_id','categoria_pai_id','categoria_pai','pai','parent','ascendente_id','ascendente']);
        if (!$parentCol) return [$rootId];

        $rows = $this->pdo->query('SELECT `id`, `' . $parentCol . '` AS parent_id FROM `categorias`')->fetchAll() ?: [];
        $children = [];
        foreach ($rows as $row) {
            $parent = (int)($row['parent_id'] ?? 0);
            if ($parent <= 0) continue;
            $children[$parent][] = (int)$row['id'];
        }

        $seen = [];
        $queue = [$rootId];
        while ($queue) {
            $id = (int)array_shift($queue);
            if ($id <= 0 || isset($seen[$id])) continue;
            $seen[$id] = true;
            foreach ($children[$id] ?? [] as $childId) {
                if (!isset($seen[$childId])) $queue[] = $childId;
            }
        }
        return array_map('intval', array_keys($seen));
    }

    private function mediaUrl(int $id): string
    {
        if ($id <= 0 || !$this->tableExists('midias')) return '';
        $cols = $this->columns('midias');
        $urlCol = $this->findColumn($cols, ['url','arquivo_url','local_url','source_url']);
        $pathCol = $this->findColumn($cols, ['arquivo','caminho','path','arquivo_path','local_path']);
        if (!$urlCol && !$pathCol) return '';
        $select = [];
        if ($urlCol) $select[] = '`' . $urlCol . '` AS media_url';
        if ($pathCol) $select[] = '`' . $pathCol . '` AS media_path';
        $stmt = $this->pdo->prepare('SELECT ' . implode(',', $select) . ' FROM midias WHERE id=:id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch() ?: [];

        // Caminho local primeiro; URL armazenada fica como fallback.
        foreach (['media_path','media_url'] as $key) {
            $value = trim((string)($row[$key] ?? ''));
            if ($value === '') continue;
            $resolved = $this->normalizePublicUrl($value);
            if ($resolved !== '') return $resolved;
        }
        return '';
    }

    private function normalizePublicUrl(string $value): string
    {
        $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = str_replace('\\', '/', $value);
        if ($value === '') return '';

        // Corrige valores já contaminados no formato http://localhost/C:/xampp/...
        // antes de aceitar uma URL HTTP como válida.
        if (preg_match('~^https?://~i', $value)) {
            $parts = parse_url($value);
            $path = str_replace('\\', '/', (string)($parts['path'] ?? ''));
            if ($this->looksLikePhysicalPath($path)) {
                $value = $path;
            } else {
                return $value;
            }
        }

        $value = preg_replace('~^file:/+~i', '', $value) ?? $value;
        $value = rawurldecode($value);
        $value = str_replace('\\', '/', $value);

        // 1) Caminho físico exatamente dentro da raiz atual da aplicação.
        $root = str_replace('\\', '/', dirname(__DIR__, 2));
        if ($root !== '' && str_starts_with(strtolower($value), strtolower($root . '/'))) {
            $value = substr($value, strlen($root) + 1);
        }

        // 2) Caminho físico de outra máquina/ambiente contendo o diretório
        // da instalação (ex.: C:/xampp/htdocs/portal_ieclb_parobe/public/...).
        $basePath = trim((string)(parse_url(defined('BASE_URL') ? (string)BASE_URL : '', PHP_URL_PATH) ?: ''), '/');
        $appDir = $basePath !== '' ? basename($basePath) : basename($root);
        if ($appDir !== '') {
            $needle = '/' . strtolower($appDir) . '/';
            $lower = '/' . ltrim(strtolower($value), '/');
            $pos = strpos($lower, $needle);
            if ($pos !== false) {
                $value = substr('/' . ltrim($value, '/'), $pos + strlen($needle));
            }
        }

        // 3) Último recurso para uploads: elimina qualquer prefixo físico até
        // public/uploads, preservando somente o caminho que o navegador entende.
        $normalizedLower = strtolower($value);
        foreach (['public/uploads/', 'uploads/wordpress/', 'uploads/'] as $marker) {
            $pos = strpos($normalizedLower, $marker);
            if ($pos !== false) {
                $tail = substr($value, $pos);
                if (str_starts_with(strtolower($tail), 'uploads/')) {
                    // Compatibilidade: algumas mídias estão em /uploads/
                    // e outras em /public/uploads/. Usa o arquivo que
                    // realmente existir no servidor.
                    $cleanTail = ltrim($tail, '/');
                    $rootCandidate = $root . '/' . $cleanTail;
                    $publicCandidate = $root . '/public/' . $cleanTail;

                    if (is_file($rootCandidate)) {
                        $tail = $cleanTail;
                    } elseif (is_file($publicCandidate)) {
                        $tail = 'public/' . $cleanTail;
                    } else {
                        // Se não for possível confirmar no disco,
                        // preserva /uploads/ em vez de forçar /public/uploads/.
                        $tail = $cleanTail;
                    }
                }
                $value = $tail;
                break;
            }
        }

        // Nunca exponha drive letter, DOCUMENT_ROOT ou outro caminho físico.
        if ($this->looksLikePhysicalPath($value)) {
            return '';
        }

        return url(ltrim($value, '/'));
    }

    private function looksLikePhysicalPath(string $value): bool
    {
        $value = str_replace('\\', '/', trim($value));
        if ($value === '') return false;
        if (preg_match('~^/?[A-Za-z]:/~', $value)) return true;
        foreach (['/xampp/htdocs/', '/wamp64/www/', '/laragon/www/', '/var/www/', '/srv/www/', '/home/'] as $marker) {
            if (str_contains(strtolower($value), $marker)) return true;
        }
        return false;
    }

    private function tableExists(string $table): bool
    {
        if (array_key_exists($table, $this->tableCache)) return $this->tableCache[$table];
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:t');
        $stmt->execute(['t' => $table]);
        return $this->tableCache[$table] = ((int)$stmt->fetchColumn() > 0);
    }

    private function columns(string $table): array
    {
        if (isset($this->columnCache[$table])) return $this->columnCache[$table];
        $rows = $this->pdo->query('SHOW COLUMNS FROM `' . str_replace('`','``',$table) . '`')->fetchAll() ?: [];
        $out = [];
        foreach ($rows as $row) $out[(string)$row['Field']] = $row;
        return $this->columnCache[$table] = $out;
    }

    private function findColumn(array $cols, array $candidates): ?string
    {
        foreach ($candidates as $candidate) if (isset($cols[$candidate])) return $candidate;
        return null;
    }
}
