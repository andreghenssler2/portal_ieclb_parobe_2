<?php

declare(strict_types=1);

final class WordPressImportService
{
    private PDO $pdo;
    private string $baseUrl;
    private string $username;
    private string $applicationPassword;
    private string $originHash;
    private array $columnCache = [];
    private array $mediaAliasCache = [];

    private const MODULES = ['all', 'categories', 'tags', 'media', 'pages', 'posts', 'events'];
    private const ALL_PHASES = ['categories', 'tags', 'media', 'pages', 'posts', 'events'];

    public function __construct(PDO $pdo, string $baseUrl, string $username = '', string $applicationPassword = '')
    {
        $this->pdo = $pdo;
        $this->baseUrl = self::normalizeBaseUrl($baseUrl);
        $this->username = trim($username);
        $this->applicationPassword = trim($applicationPassword);
        $this->originHash = hash('sha256', strtolower($this->baseUrl));
    }

    public static function normalizeBaseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new InvalidArgumentException('Informe a URL do WordPress.');
        }
        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . $url;
        }
        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || !isset($parts['scheme']) || !in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true)) {
            throw new InvalidArgumentException('A URL do WordPress é inválida.');
        }
        if (!empty($parts['user']) || !empty($parts['pass'])) {
            throw new InvalidArgumentException('Não informe usuário e senha dentro da URL. Use os campos de autenticação.');
        }
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        $path = isset($parts['path']) ? '/' . trim((string)$parts['path'], '/') : '';
        if ($path === '/') {
            $path = '';
        }
        return strtolower((string)$parts['scheme']) . '://' . (string)$parts['host'] . $port . $path;
    }

    public static function allowedModules(): array
    {
        return self::MODULES;
    }

    public static function moduleLabel(string $module): string
    {
        return [
            'all' => 'Importação completa',
            'categories' => 'Categorias',
            'tags' => 'Tags',
            'media' => 'Mídias',
            'pages' => 'Páginas',
            'posts' => 'Posts / Notícias',
            'events' => 'Eventos',
        ][$module] ?? $module;
    }

    public function testConnection(): array
    {
        $root = $this->requestJson('/wp-json/');
        $types = [];
        try {
            $typeResponse = $this->requestJson('/wp-json/wp/v2/types', ['context' => 'view']);
            if (is_array($typeResponse['data'])) {
                $types = $typeResponse['data'];
            }
        } catch (Throwable $ignored) {
        }

        $name = '';
        if (is_array($root['data'])) {
            $name = (string)($root['data']['name'] ?? '');
        }

        return [
            'ok' => true,
            'name' => $name,
            'url' => $this->baseUrl,
            'authenticated' => $this->username !== '' && $this->applicationPassword !== '',
            'event_candidates' => $this->eventCandidates($types),
        ];
    }

    public static function createJob(PDO $pdo, int $userId, string $baseUrl, string $module, string $mode, string $eventsEndpoint = '', array $options = []): int
    {
        $baseUrl = self::normalizeBaseUrl($baseUrl);
        if (!in_array($module, self::MODULES, true)) {
            throw new InvalidArgumentException('Módulo de importação inválido.');
        }
        if (!in_array($mode, ['new', 'update', 'simulate'], true)) {
            throw new InvalidArgumentException('Modo de importação inválido.');
        }

        $phase = $module === 'all' ? 'categories' : $module;
        $stmt = $pdo->prepare(
            'INSERT INTO wordpress_importacoes
             (usuario_id,origem_url,origem_hash,modulo,fase,eventos_endpoint,modo,opcoes_json,status,pagina_atual,created_at,updated_at)
             VALUES (:usuario,:url,:hash,:modulo,:fase,:endpoint,:modo,:opcoes,\'aguardando\',1,NOW(),NOW())'
        );
        $stmt->execute([
            'usuario' => $userId > 0 ? $userId : null,
            'url' => $baseUrl,
            'hash' => hash('sha256', strtolower($baseUrl)),
            'modulo' => $module,
            'fase' => $phase,
            'endpoint' => trim($eventsEndpoint),
            'modo' => $mode,
            'opcoes' => json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        return (int)$pdo->lastInsertId();
    }

    public function processJob(int $jobId, int $batchSize = 50): array
    {
        $batchSize = max(5, min(100, $batchSize));
        $job = $this->loadJob($jobId);
        if (!$job) {
            throw new RuntimeException('Importação não encontrada.');
        }
        if (!hash_equals((string)$job['origem_hash'], $this->originHash)) {
            throw new RuntimeException('A origem desta importação não corresponde à sessão atual.');
        }
        if (in_array((string)$job['status'], ['concluido', 'cancelado'], true)) {
            return $this->jobSnapshot($jobId);
        }

        $module = (string)$job['modulo'];
        $phase = (string)$job['fase'];
        if ($phase === 'done') {
            $this->finishJob($jobId);
            return $this->jobSnapshot($jobId);
        }

        if ($phase === 'events' && trim((string)$job['eventos_endpoint']) === '') {
            $detected = $this->detectEventEndpoint();
            if ($detected !== '') {
                $this->pdo->prepare('UPDATE wordpress_importacoes SET eventos_endpoint=:e,updated_at=NOW() WHERE id=:id')
                    ->execute(['e' => $detected, 'id' => $jobId]);
                $job['eventos_endpoint'] = $detected;
            } elseif ($module === 'all') {
                $this->log($jobId, 'aviso', 'Nenhum tipo de evento exposto pela REST API foi detectado. A fase de Eventos foi ignorada.');
                $this->advancePhase($jobId, $module, $phase);
                return $this->jobSnapshot($jobId);
            } else {
                throw new RuntimeException('Não foi possível detectar automaticamente o endpoint de eventos. Informe o endpoint no formulário.');
            }
        }

        $page = max(1, (int)$job['pagina_atual']);
        if (in_array((string)$job['status'], ['aguardando', 'falhou'], true)) {
            $this->pdo->prepare("UPDATE wordpress_importacoes SET status='processando',ultimo_erro=NULL,iniciado_em=COALESCE(iniciado_em,NOW()),updated_at=NOW() WHERE id=:id")
                ->execute(['id' => $jobId]);
        }

        try {
            $endpoint = $this->endpointForPhase($phase, (string)$job['eventos_endpoint']);
            $response = $this->requestCollection($endpoint, ['per_page' => $batchSize, 'page' => $page, 'orderby' => 'id', 'order' => 'asc']);
            $items = $response['items'];
            $totalPages = max(1, (int)$response['total_pages']);
            $totalItems = max(count($items), (int)$response['total_items']);

            $this->pdo->prepare('UPDATE wordpress_importacoes SET total_paginas_fase=:tp,total_itens_fase=:ti,updated_at=NOW() WHERE id=:id')
                ->execute(['tp' => $totalPages, 'ti' => $totalItems, 'id' => $jobId]);

            if (!$items && $page === 1) {
                $this->log($jobId, 'info', 'Nenhum item encontrado em ' . self::moduleLabel($phase) . '.');
                $this->advancePhase($jobId, $module, $phase);
                return $this->jobSnapshot($jobId);
            }

            $counters = ['processed' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
            $mode = (string)$job['modo'];
            $options = json_decode((string)($job['opcoes_json'] ?? '{}'), true);
            if (!is_array($options)) {
                $options = [];
            }

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $counters['processed']++;
                try {
                    $result = $this->importItem($phase, $item, $mode, (int)($job['usuario_id'] ?? 0), $options);
                    if (isset($counters[$result])) {
                        $counters[$result]++;
                    }
                } catch (Throwable $e) {
                    $counters['errors']++;
                    $wpId = isset($item['id']) ? (int)$item['id'] : null;
                    $this->log($jobId, 'erro', $e->getMessage(), $wpId);
                }
            }

            $sql = 'UPDATE wordpress_importacoes SET
                        processados=processados + :processed,
                        criados=criados + :created,
                        atualizados=atualizados + :updated,
                        ignorados=ignorados + :skipped,
                        erros=erros + :errors,
                        ultimo_erro=NULL,
                        pagina_atual=:next_page,
                        updated_at=NOW()
                    WHERE id=:id';
            $nextPage = $page + 1;
            $this->pdo->prepare($sql)->execute([
                'processed' => $counters['processed'],
                'created' => $counters['created'],
                'updated' => $counters['updated'],
                'skipped' => $counters['skipped'],
                'errors' => $counters['errors'],
                'next_page' => $nextPage,
                'id' => $jobId,
            ]);

            if ($page >= $totalPages || count($items) < $batchSize) {
                $this->resolveDeferredRelationships($phase);
                $this->advancePhase($jobId, $module, $phase);
            }
        } catch (Throwable $e) {
            $this->pdo->prepare("UPDATE wordpress_importacoes SET status='falhou',ultimo_erro=:erro,erros=erros+1,updated_at=NOW() WHERE id=:id")
                ->execute(['erro' => mb_substr($e->getMessage(), 0, 5000), 'id' => $jobId]);
            $this->log($jobId, 'erro', $e->getMessage());
        }

        return $this->jobSnapshot($jobId);
    }

    public function jobSnapshot(int $jobId): array
    {
        $job = $this->loadJob($jobId);
        if (!$job) {
            throw new RuntimeException('Importação não encontrada.');
        }
        $stmt = $this->pdo->prepare('SELECT nivel,wp_id,mensagem,created_at FROM wordpress_import_logs WHERE importacao_id=:id ORDER BY id DESC LIMIT 8');
        $stmt->execute(['id' => $jobId]);
        $logs = $stmt->fetchAll() ?: [];

        $current = max(1, (int)$job['pagina_atual']);
        $pages = max(0, (int)$job['total_paginas_fase']);
        $pageProgress = $pages > 0 ? min(100, (int)round((min($current - 1, $pages) / $pages) * 100)) : 0;
        if ((string)$job['status'] === 'concluido') {
            $pageProgress = 100;
        }

        return [
            'id' => (int)$job['id'],
            'status' => (string)$job['status'],
            'module' => (string)$job['modulo'],
            'module_label' => self::moduleLabel((string)$job['modulo']),
            'phase' => (string)$job['fase'],
            'phase_label' => (string)$job['fase'] === 'done' ? 'Concluído' : self::moduleLabel((string)$job['fase']),
            'page' => $current,
            'total_pages' => $pages,
            'total_items_phase' => (int)$job['total_itens_fase'],
            'progress' => $pageProgress,
            'processed' => (int)$job['processados'],
            'created' => (int)$job['criados'],
            'updated' => (int)$job['atualizados'],
            'skipped' => (int)$job['ignorados'],
            'errors' => (int)$job['erros'],
            'last_error' => (string)($job['ultimo_erro'] ?? ''),
            'event_endpoint' => (string)($job['eventos_endpoint'] ?? ''),
            'logs' => $logs,
        ];
    }

    private function loadJob(int $jobId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM wordpress_importacoes WHERE id=:id LIMIT 1');
        $stmt->execute(['id' => $jobId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function finishJob(int $jobId): void
    {
        $this->pdo->prepare("UPDATE wordpress_importacoes SET status='concluido',fase='done',finalizado_em=COALESCE(finalizado_em,NOW()),updated_at=NOW() WHERE id=:id")
            ->execute(['id' => $jobId]);
        $this->log($jobId, 'sucesso', 'Importação concluída.');
    }

    private function advancePhase(int $jobId, string $module, string $phase): void
    {
        if ($module !== 'all') {
            $this->finishJob($jobId);
            return;
        }
        $index = array_search($phase, self::ALL_PHASES, true);
        if ($index === false || $index >= count(self::ALL_PHASES) - 1) {
            $this->finishJob($jobId);
            return;
        }
        $next = self::ALL_PHASES[$index + 1];
        $this->pdo->prepare('UPDATE wordpress_importacoes SET fase=:fase,pagina_atual=1,total_paginas_fase=0,total_itens_fase=0,updated_at=NOW() WHERE id=:id')
            ->execute(['fase' => $next, 'id' => $jobId]);
        $this->log($jobId, 'info', 'Iniciando fase: ' . self::moduleLabel($next) . '.');
    }

    private function endpointForPhase(string $phase, string $eventEndpoint): string
    {
        return match ($phase) {
            'categories' => '/wp-json/wp/v2/categories',
            'tags' => '/wp-json/wp/v2/tags',
            'media' => '/wp-json/wp/v2/media',
            'pages' => '/wp-json/wp/v2/pages',
            'posts' => '/wp-json/wp/v2/posts',
            'events' => $this->normalizeRestEndpoint($eventEndpoint),
            default => throw new RuntimeException('Fase de importação inválida: ' . $phase),
        };
    }

    private function normalizeRestEndpoint(string $endpoint): string
    {
        $endpoint = trim($endpoint);
        if ($endpoint === '') {
            throw new RuntimeException('Endpoint de eventos não informado.');
        }
        if (preg_match('~^https?://~i', $endpoint)) {
            $url = self::normalizeBaseUrl($endpoint);
            if (!str_starts_with($url, $this->baseUrl)) {
                throw new RuntimeException('O endpoint de eventos precisa pertencer ao WordPress informado.');
            }
            return substr($url, strlen($this->baseUrl)) ?: '/';
        }
        if (str_starts_with($endpoint, '/wp-json/')) {
            return $endpoint;
        }
        if (str_contains($endpoint, '/')) {
            return '/wp-json/' . ltrim($endpoint, '/');
        }
        return '/wp-json/wp/v2/' . rawurlencode($endpoint);
    }

    private function requestCollection(string $endpoint, array $params): array
    {
        try {
            $response = $this->requestJson($endpoint, $params);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'rest_post_invalid_page_number')) {
                return ['items' => [], 'total_pages' => max(1, (int)($params['page'] ?? 1) - 1), 'total_items' => 0];
            }
            throw $e;
        }

        $data = $response['data'];
        $items = [];
        if (is_array($data)) {
            if ($this->isListArray($data)) {
                $items = $data;
            } else {
                foreach (['events', 'items', 'data', 'results'] as $key) {
                    if (isset($data[$key]) && is_array($data[$key]) && $this->isListArray($data[$key])) {
                        $items = $data[$key];
                        break;
                    }
                }
            }
        }

        $headers = $response['headers'];
        $totalPages = (int)($headers['x-wp-totalpages'] ?? 0);
        $totalItems = (int)($headers['x-wp-total'] ?? 0);
        if ($totalPages <= 0 && is_array($data) && !$this->isListArray($data)) {
            $totalPages = (int)($data['total_pages'] ?? $data['totalPages'] ?? 0);
            $totalItems = (int)($data['total'] ?? $data['total_items'] ?? $totalItems);
        }
        if ($totalPages <= 0) {
            $totalPages = count($items) < (int)($params['per_page'] ?? 50) ? (int)($params['page'] ?? 1) : (int)($params['page'] ?? 1) + 1;
        }
        if ($totalItems <= 0) {
            $totalItems = count($items);
        }
        return ['items' => $items, 'total_pages' => $totalPages, 'total_items' => $totalItems];
    }

    private function requestJson(string $endpoint, array $params = []): array
    {
        $url = $this->buildUrl($endpoint, $params);
        $headers = ['Accept: application/json', 'User-Agent: Portal-IECLB-WordPress-Importer/0.27.2'];
        if ($this->username !== '' && $this->applicationPassword !== '') {
            $headers[] = 'Authorization: Basic ' . base64_encode($this->username . ':' . $this->applicationPassword);
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('Não foi possível iniciar a conexão HTTP.');
            }
            $responseHeaders = [];
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 4,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 40,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                    $len = strlen($line);
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return $len;
                },
            ]);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            if ($body === false || $error !== '') {
                throw new RuntimeException('Falha ao conectar ao WordPress: ' . ($error !== '' ? $error : 'resposta vazia.'));
            }
        } else {
            $headerText = implode("\r\n", $headers);
            $context = stream_context_create(['http' => [
                'method' => 'GET',
                'header' => $headerText,
                'timeout' => 40,
                'ignore_errors' => true,
            ]]);
            $body = @file_get_contents($url, false, $context);
            $responseHeaders = [];
            $status = 0;
            foreach (($http_response_header ?? []) as $line) {
                if (preg_match('~^HTTP/\S+\s+(\d+)~', $line, $m)) {
                    $status = (int)$m[1];
                } elseif (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($k))] = trim($v);
                }
            }
            if ($body === false) {
                throw new RuntimeException('Falha ao conectar ao WordPress. Habilite cURL para melhor compatibilidade.');
            }
        }

        $decoded = json_decode((string)$body, true);
        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) ? (string)($decoded['message'] ?? $decoded['code'] ?? '') : '';
            $code = is_array($decoded) ? (string)($decoded['code'] ?? '') : '';
            throw new RuntimeException('WordPress respondeu HTTP ' . $status . ($code !== '' ? ' [' . $code . ']' : '') . ($message !== '' ? ': ' . $message : '.'));
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('O WordPress não retornou JSON válido em ' . $endpoint . '.');
        }
        return ['data' => $decoded, 'headers' => $responseHeaders, 'status' => $status];
    }

    private function buildUrl(string $endpoint, array $params = []): string
    {
        if (preg_match('~^https?://~i', $endpoint)) {
            $url = $endpoint;
            if (!str_starts_with($url, $this->baseUrl)) {
                throw new RuntimeException('A requisição precisa permanecer na origem WordPress informada.');
            }
        } else {
            $url = $this->baseUrl . '/' . ltrim($endpoint, '/');
        }
        if ($params) {
            $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
            $url .= (str_contains($url, '?') ? '&' : '?') . $query;
        }
        return $url;
    }

    private function eventCandidates(array $types): array
    {
        $out = [];
        foreach ($types as $key => $type) {
            if (!is_array($type)) {
                continue;
            }
            $slug = strtolower((string)$key);
            $restBase = (string)($type['rest_base'] ?? $key);
            $namespace = trim((string)($type['rest_namespace'] ?? 'wp/v2'), '/');
            $haystack = strtolower($slug . ' ' . $restBase . ' ' . (string)($type['name'] ?? '') . ' ' . (string)($type['slug'] ?? ''));
            if (str_contains($haystack, 'event') || str_contains($haystack, 'evento')) {
                $out[] = [
                    'type' => (string)$key,
                    'name' => (string)($type['name'] ?? $key),
                    'endpoint' => '/' . $namespace . '/' . ltrim($restBase, '/'),
                ];
            }
        }
        return $out;
    }

    private function detectEventEndpoint(): string
    {
        try {
            $response = $this->requestJson('/wp-json/wp/v2/types', ['context' => 'view']);
            $candidates = $this->eventCandidates(is_array($response['data']) ? $response['data'] : []);
            if ($candidates) {
                return (string)$candidates[0]['endpoint'];
            }
        } catch (Throwable $ignored) {
        }
        foreach (['tribe_events', 'events', 'event', 'eventos', 'evento'] as $slug) {
            try {
                $this->requestCollection('/wp-json/wp/v2/' . $slug, ['per_page' => 1, 'page' => 1]);
                return '/wp/v2/' . $slug;
            } catch (Throwable $ignored) {
            }
        }
        return '';
    }

    private function importItem(string $phase, array $item, string $mode, int $userId, array $options): string
    {
        return match ($phase) {
            'categories' => $this->importTaxonomy('categorias', 'category', $item, $mode, $userId),
            'tags' => $this->importTaxonomy('tags', 'tag', $item, $mode, $userId),
            'media' => $this->importMedia($item, $mode, $userId, $options),
            'pages' => $this->importContent('paginas', 'page', $item, $mode, $userId, $options),
            'posts' => $this->importContent('posts', 'post', $item, $mode, $userId, $options),
            'events' => $this->importEvent($item, $mode, $userId, $options),
            default => throw new RuntimeException('Módulo não suportado: ' . $phase),
        };
    }

    private function importTaxonomy(string $table, string $wpType, array $item, string $mode, int $userId): string
    {
        $this->assertTable($table);
        $wpId = (int)($item['id'] ?? 0);
        if ($wpId <= 0) {
            throw new RuntimeException('Item do WordPress sem ID.');
        }
        $existing = $this->mapRow($wpType, $wpId);
        if ($mode === 'simulate') {
            return $existing ? 'updated' : 'created';
        }
        if ($existing && $mode === 'new' && $this->recordExists($table, (int)$existing['local_id'])) {
            return 'skipped';
        }

        $cols = $this->columns($table);
        $record = [];
        $this->setFirst($record, $cols, ['nome', 'name', 'titulo'], $this->rendered($item['name'] ?? ''));
        $slug = (string)($item['slug'] ?? '');
        if ($slug === '') {
            $slug = $this->slugify((string)($item['name'] ?? ('wp-' . $wpId)));
        }
        $slugCol = $this->findColumn($cols, ['slug']);
        if ($slugCol) {
            $record[$slugCol] = $this->uniqueSlug($table, $slugCol, $slug, $existing ? (int)$existing['local_id'] : null);
        }
        $this->setFirst($record, $cols, ['descricao', 'description', 'conteudo'], $this->rendered($item['description'] ?? ''));
        $parentWp = (int)($item['parent'] ?? 0);
        $parentLocal = $parentWp > 0 ? $this->mappedLocalId($wpType, $parentWp) : null;
        $this->setFirst($record, $cols, ['pai_id', 'parent_id', 'categoria_pai_id'], $parentLocal);
        $this->setAuditColumns($record, $cols, $userId);

        if ($existing && $this->recordExists($table, (int)$existing['local_id'])) {
            $localId = (int)$existing['local_id'];
            $this->updateAdaptive($table, $localId, $record);
            $result = 'updated';
        } else {
            $localId = $this->insertAdaptive($table, $record, $userId);
            $result = 'created';
        }
        $this->saveMap($wpType, $wpId, $localId, $table, $slug, $this->wpModified($item), $parentWp ?: null);
        return $result;
    }

    private function importContent(string $table, string $wpType, array $item, string $mode, int $userId, array $options): string
    {
        $this->assertTable($table);
        $wpId = (int)($item['id'] ?? 0);
        if ($wpId <= 0) {
            throw new RuntimeException('Conteúdo WordPress sem ID.');
        }
        $existing = $this->mapRow($wpType, $wpId);
        if ($mode === 'simulate') {
            return $existing ? 'updated' : 'created';
        }
        if ($existing && $mode === 'new' && $this->recordExists($table, (int)$existing['local_id'])) {
            // v0.28.4: além das mídias, o modo "apenas novos" também repara as
            // relações de categorias/tags dos posts já importados. Isso permite
            // corrigir a home sem duplicar notícias.
            $repair = [];
            $repairCols = $this->columns($table);
            $taxonomyRepaired = false;
            if ($wpType === 'post') {
                $this->syncPostTaxonomies((int)$existing['local_id'], $item);
                $taxonomyRepaired = true;
            }
            $featuredWp = (int)($item['featured_media'] ?? 0);
            if ($featuredWp > 0) {
                $this->applyFeaturedMedia($repair, $repairCols, $featuredWp, 'update', $userId, $options);
            }
            $incomingContent = $this->rendered($item['content'] ?? '');
            if (($options['rewrite_media_urls'] ?? true) && $incomingContent !== '') {
                if (($options['download_media'] ?? true) !== false) {
                    $this->ensureContentMediaLocal($incomingContent, $userId, $options);
                }
                $rewritten = $this->rewriteKnownMediaUrls($incomingContent);
                if ($rewritten !== $incomingContent) {
                    $this->setFirst($repair, $repairCols, ['conteudo', 'content', 'descricao'], $rewritten);
                }
            }
            if ($repair) {
                $this->updateAdaptive($table, (int)$existing['local_id'], $repair);
                return 'updated';
            }
            return $taxonomyRepaired ? 'updated' : 'skipped';
        }

        $cols = $this->columns($table);
        $record = [];
        $title = $this->rendered($item['title'] ?? '');
        $content = $this->rendered($item['content'] ?? '');
        $excerpt = $this->rendered($item['excerpt'] ?? '');
        if (($options['rewrite_media_urls'] ?? true) && $content !== '') {
            if (($options['download_media'] ?? true) !== false) {
                $this->ensureContentMediaLocal($content, $userId, $options);
            }
            $content = $this->rewriteKnownMediaUrls($content);
        }
        $this->setFirst($record, $cols, ['titulo', 'title', 'nome'], $title !== '' ? $title : 'Sem título');
        $this->setFirst($record, $cols, ['conteudo', 'content', 'descricao'], $content);
        $this->setFirst($record, $cols, ['resumo', 'excerpt', 'resumo_curto'], strip_tags($excerpt));

        $slug = (string)($item['slug'] ?? '');
        if ($slug === '') {
            $slug = $this->slugify($title !== '' ? $title : ('wp-' . $wpId));
        }
        $slugCol = $this->findColumn($cols, ['slug']);
        if ($slugCol) {
            $record[$slugCol] = $this->uniqueSlug($table, $slugCol, $slug, $existing ? (int)$existing['local_id'] : null);
        }

        $statusCol = $this->findColumn($cols, ['status', 'situacao']);
        if ($statusCol) {
            $record[$statusCol] = $this->adaptStatus($cols[$statusCol], (string)($item['status'] ?? 'draft'));
        }
        $published = $this->wpDate($item['date'] ?? null);
        $modified = $this->wpDate($item['modified'] ?? null);
        $this->setFirst($record, $cols, ['publicado_em', 'data_publicacao', 'publication_date', 'data'], $published);
        $this->setFirst($record, $cols, ['created_at', 'criado_em'], $published ?: date('Y-m-d H:i:s'));
        $this->setFirst($record, $cols, ['updated_at', 'atualizado_em'], $modified ?: date('Y-m-d H:i:s'));
        $this->setFirst($record, $cols, ['autor_id', 'usuario_id', 'user_id'], $userId > 0 ? $userId : null);
        $this->setFirst($record, $cols, ['wordpress_url', 'origem_url', 'url_origem'], (string)($item['link'] ?? ''));

        $featuredWp = (int)($item['featured_media'] ?? 0);
        $this->applyFeaturedMedia($record, $cols, $featuredWp, $mode, $userId, $options);

        if ($wpType === 'post' && is_array($item['categories'] ?? null)) {
            foreach ($item['categories'] as $wpCategoryId) {
                $localCategoryId = $this->mappedLocalId('category', (int)$wpCategoryId);
                if ($localCategoryId) {
                    $this->setFirst($record, $cols, ['categoria_id', 'category_id'], $localCategoryId);
                    break;
                }
            }
        }

        $parentWp = $wpType === 'page' ? (int)($item['parent'] ?? 0) : 0;
        if ($wpType === 'page') {
            $parentLocal = $parentWp > 0 ? $this->mappedLocalId('page', $parentWp) : null;
            $this->setFirst($record, $cols, ['pai_id', 'parent_id', 'pagina_pai_id'], $parentLocal);
            $this->setFirst($record, $cols, ['ordem', 'menu_order'], (int)($item['menu_order'] ?? 0));
        }

        if ($existing && $this->recordExists($table, (int)$existing['local_id'])) {
            $localId = (int)$existing['local_id'];
            $this->updateAdaptive($table, $localId, $record);
            $result = 'updated';
        } else {
            $localId = $this->insertAdaptive($table, $record, $userId);
            $result = 'created';
        }

        $this->saveMap($wpType, $wpId, $localId, $table, $slug, $this->wpModified($item), $parentWp ?: null);
        if ($wpType === 'post') {
            $this->syncPostTaxonomies($localId, $item);
        }
        return $result;
    }

    private function importEvent(array $item, string $mode, int $userId, array $options): string
    {
        $table = 'eventos';
        $this->assertTable($table);
        $wpId = (int)($item['id'] ?? 0);
        if ($wpId <= 0) {
            throw new RuntimeException('Evento WordPress sem ID.');
        }
        $existing = $this->mapRow('event', $wpId);
        if ($mode === 'simulate') {
            return $existing ? 'updated' : 'created';
        }
        if ($existing && $mode === 'new' && $this->recordExists($table, (int)$existing['local_id'])) {
            $repair = [];
            $repairCols = $this->columns($table);
            $featuredWp = (int)($item['featured_media'] ?? 0);
            if ($featuredWp > 0) {
                $this->applyFeaturedMedia($repair, $repairCols, $featuredWp, 'update', $userId, $options);
            }
            $incomingContent = $this->rendered($item['content'] ?? ($item['description'] ?? ''));
            if (($options['rewrite_media_urls'] ?? true) && $incomingContent !== '') {
                if (($options['download_media'] ?? true) !== false) {
                    $this->ensureContentMediaLocal($incomingContent, $userId, $options);
                }
                $rewritten = $this->rewriteKnownMediaUrls($incomingContent);
                if ($rewritten !== $incomingContent) {
                    $this->setFirst($repair, $repairCols, ['conteudo', 'descricao', 'content'], $rewritten);
                }
            }
            if ($repair) {
                $this->updateAdaptive($table, (int)$existing['local_id'], $repair);
                return 'updated';
            }
            return 'skipped';
        }

        $cols = $this->columns($table);
        $record = [];
        $title = $this->rendered($item['title'] ?? ($item['name'] ?? ''));
        $content = $this->rendered($item['content'] ?? ($item['description'] ?? ''));
        if (($options['rewrite_media_urls'] ?? true) && $content !== '') {
            if (($options['download_media'] ?? true) !== false) {
                $this->ensureContentMediaLocal($content, $userId, $options);
            }
            $content = $this->rewriteKnownMediaUrls($content);
        }
        $this->setFirst($record, $cols, ['titulo', 'title', 'nome'], $title !== '' ? $title : 'Evento');
        $this->setFirst($record, $cols, ['conteudo', 'descricao', 'content'], $content);
        $this->setFirst($record, $cols, ['resumo', 'excerpt'], strip_tags($this->rendered($item['excerpt'] ?? '')));
        $slug = (string)($item['slug'] ?? '');
        if ($slug === '') {
            $slug = $this->slugify($title !== '' ? $title : ('evento-' . $wpId));
        }
        $slugCol = $this->findColumn($cols, ['slug']);
        if ($slugCol) {
            $record[$slugCol] = $this->uniqueSlug($table, $slugCol, $slug, $existing ? (int)$existing['local_id'] : null);
        }
        $statusCol = $this->findColumn($cols, ['status', 'situacao']);
        if ($statusCol) {
            $record[$statusCol] = $this->adaptStatus($cols[$statusCol], (string)($item['status'] ?? 'publish'));
        }

        $start = $this->firstValue($item, ['start_date', 'start', 'event_start', 'date_start', 'inicio', 'meta._EventStartDate', 'meta.event_start']);
        $end = $this->firstValue($item, ['end_date', 'end', 'event_end', 'date_end', 'fim', 'meta._EventEndDate', 'meta.event_end']);
        $location = $this->firstValue($item, ['venue.venue', 'venue.name', 'venue', 'location', 'local', 'meta._EventVenue', 'meta.event_location']);
        $address = $this->firstValue($item, ['venue.address', 'address', 'endereco', 'meta._EventAddress']);
        $startDate = $this->wpDate(is_scalar($start) ? (string)$start : null) ?: $this->wpDate($item['date'] ?? null);
        $endDate = $this->wpDate(is_scalar($end) ? (string)$end : null);
        $this->setFirst($record, $cols, ['inicio', 'data_inicio', 'starts_at', 'start_date'], $startDate);
        $this->setFirst($record, $cols, ['fim', 'data_fim', 'ends_at', 'end_date'], $endDate);
        $this->setFirst($record, $cols, ['local', 'location', 'local_nome'], is_scalar($location) ? (string)$location : '');
        $this->setFirst($record, $cols, ['endereco', 'address'], is_scalar($address) ? (string)$address : '');
        $this->setFirst($record, $cols, ['autor_id', 'usuario_id', 'user_id'], $userId > 0 ? $userId : null);
        $this->setFirst($record, $cols, ['created_at', 'criado_em'], $this->wpDate($item['date'] ?? null) ?: date('Y-m-d H:i:s'));
        $this->setFirst($record, $cols, ['updated_at', 'atualizado_em'], $this->wpDate($item['modified'] ?? null) ?: date('Y-m-d H:i:s'));
        $this->setFirst($record, $cols, ['wordpress_url', 'origem_url', 'url_origem'], (string)($item['link'] ?? ''));

        $featuredWp = (int)($item['featured_media'] ?? 0);
        $this->applyFeaturedMedia($record, $cols, $featuredWp, $mode, $userId, $options);

        if ($existing && $this->recordExists($table, (int)$existing['local_id'])) {
            $localId = (int)$existing['local_id'];
            $this->updateAdaptive($table, $localId, $record);
            $result = 'updated';
        } else {
            $localId = $this->insertAdaptive($table, $record, $userId);
            $result = 'created';
        }
        $this->saveMap('event', $wpId, $localId, $table, $slug, $this->wpModified($item), null);
        return $result;
    }

    private function importMedia(array $item, string $mode, int $userId, array $options): string
    {
        $table = 'midias';
        $this->assertTable($table);
        $wpId = (int)($item['id'] ?? 0);
        if ($wpId <= 0) {
            throw new RuntimeException('Mídia WordPress sem ID.');
        }

        $sourceUrl = $this->normalizeMediaSourceUrl((string)($item['source_url'] ?? ''));
        $existing = $this->mapRow('media', $wpId);
        $existingRecord = $existing && $this->recordExists($table, (int)$existing['local_id']);
        if ($mode === 'simulate') {
            return $existing ? 'updated' : 'created';
        }
        if ($sourceUrl === '') {
            throw new RuntimeException('A mídia #' . $wpId . ' não possui source_url.');
        }

        $download = ($options['download_media'] ?? true) !== false;
        $repairRemote = ($options['repair_remote_media'] ?? true) !== false;
        $stored = $existingRecord ? $this->localMediaStorage($existing) : null;

        // Em "apenas novos", uma mídia que já esteja realmente salva no
        // servidor continua sendo ignorada. Se ela existir apenas como URL
        // remota, a v0.27.2 tenta internalizá-la novamente.
        if ($existingRecord && $mode === 'new' && (!$download || !$repairRemote || $stored !== null)) {
            $this->saveMediaAliases($wpId, $item, (int)$existing['local_id'], (string)($existing['local_url'] ?? ''), $stored['path'] ?? '');
            return 'skipped';
        }

        $relativePath = '';
        $localUrl = $sourceUrl;
        $mime = trim((string)($item['mime_type'] ?? 'application/octet-stream'));
        $size = 0;
        $originalName = basename((string)(parse_url($sourceUrl, PHP_URL_PATH) ?: ('wp-media-' . $wpId)));

        if ($download) {
            if ($stored !== null) {
                $relativePath = $stored['path'];
                $localUrl = $stored['url'];
                $size = $stored['size'];
            } else {
                $lastError = null;
                foreach ($this->mediaDownloadCandidates($item) as $candidateUrl) {
                    try {
                        $candidateName = basename((string)(parse_url($candidateUrl, PHP_URL_PATH) ?: $originalName));
                        [$relativePath, $localUrl, $mimeDetected, $size] = $this->downloadMediaFile($candidateUrl, $candidateName, $mime);
                        if ($mimeDetected !== '') {
                            $mime = $mimeDetected;
                        }
                        break;
                    } catch (Throwable $e) {
                        $lastError = $e;
                    }
                }
                if ($relativePath === '') {
                    throw new RuntimeException(
                        'Não foi possível baixar a mídia #' . $wpId . ' para o servidor.' .
                        ($lastError ? ' ' . $lastError->getMessage() : '')
                    );
                }
            }
        }

        $cols = $this->columns($table);
        $record = [];
        $title = $this->rendered($item['title'] ?? '');
        $caption = $this->rendered($item['caption'] ?? '');
        $description = $this->rendered($item['description'] ?? '');
        $alt = (string)($item['alt_text'] ?? '');
        $this->setFirst($record, $cols, ['nome', 'titulo', 'title'], $title !== '' ? $title : $originalName);
        $this->setFirst($record, $cols, ['arquivo', 'caminho', 'path', 'arquivo_path'], $relativePath !== '' ? $relativePath : $sourceUrl);
        $this->setFirst($record, $cols, ['url', 'arquivo_url'], $localUrl);
        $this->setFirst($record, $cols, ['mime_type', 'tipo_mime', 'mime'], $mime);
        $this->setFirst($record, $cols, ['tamanho', 'size', 'tamanho_bytes'], $size);
        $this->setFirst($record, $cols, ['alt_text', 'texto_alt', 'alt'], $alt);
        $this->setFirst($record, $cols, ['legenda', 'caption'], strip_tags($caption));
        $this->setFirst($record, $cols, ['descricao', 'description'], $description);
        $this->setFirst($record, $cols, ['nome_arquivo', 'arquivo_original', 'original_name'], $originalName);
        $this->setFirst($record, $cols, ['usuario_id', 'autor_id', 'user_id'], $userId > 0 ? $userId : null);
        $this->setFirst($record, $cols, ['created_at', 'criado_em'], $this->wpDate($item['date'] ?? null) ?: date('Y-m-d H:i:s'));
        $this->setFirst($record, $cols, ['updated_at', 'atualizado_em'], $this->wpDate($item['modified'] ?? null) ?: date('Y-m-d H:i:s'));

        if ($existingRecord) {
            $localId = (int)$existing['local_id'];
            $this->updateAdaptive($table, $localId, $record);
            $result = 'updated';
        } else {
            $localId = $this->insertAdaptive($table, $record, $userId);
            $result = 'created';
        }

        $this->saveMap('media', $wpId, $localId, $table, (string)($item['slug'] ?? ''), $this->wpModified($item), null, $sourceUrl, $localUrl);
        $this->saveMediaAliases($wpId, $item, $localId, $localUrl, $relativePath);
        return $result;
    }

    /**
     * Garante que a imagem destacada do WordPress exista na biblioteca local e
     * preenche o campo de capa do módulo conforme o esquema instalado.
     *
     * A v0.27.0 dependia de o módulo Mídias ter sido importado antes. Na
     * v0.27.1 Posts/Páginas/Eventos passam a trazer a própria featured_media
     * sob demanda, o que também corrige importações feitas por módulo.
     */
    private function applyFeaturedMedia(array &$record, array $cols, int $featuredWp, string $mode, int $userId, array $options): void
    {
        if ($featuredWp <= 0) {
            return;
        }

        $map = $this->ensureFeaturedMedia($featuredWp, $mode, $userId, $options);
        if (!$map) {
            return;
        }

        $localId = (int)($map['local_id'] ?? 0);
        if ($localId <= 0) {
            return;
        }

        $localUrl = trim((string)($map['local_url'] ?? ''));
        $localPath = $this->mediaLocalPath($localId);
        if ($localPath === '') {
            $localPath = $localUrl;
        }
        if ($localUrl === '') {
            $localUrl = $localPath;
        }

        // Esquemas que armazenam a referência da biblioteca por ID.
        foreach (['imagem_destacada_id', 'midia_id', 'imagem_id', 'featured_media_id', 'capa_id', 'thumbnail_id', 'foto_id'] as $column) {
            if (isset($cols[$column])) {
                $record[$column] = $localId;
            }
        }

        // Esquemas que armazenam explicitamente a URL da capa.
        foreach (['imagem_destacada_url', 'featured_image_url', 'imagem_url', 'capa_url', 'thumbnail_url', 'foto_url', 'image_url'] as $column) {
            if (isset($cols[$column]) && $localUrl !== '') {
                $record[$column] = $localUrl;
            }
        }

        // Esquemas que armazenam explicitamente o caminho do arquivo.
        foreach (['imagem_destacada_path', 'featured_image_path', 'imagem_path', 'capa_path', 'thumbnail_path', 'foto_path', 'imagem_arquivo', 'capa_arquivo'] as $column) {
            if (isset($cols[$column]) && $localPath !== '') {
                $record[$column] = $localPath;
            }
        }

        // Compatibilidade com versões/esquemas em que a coluna se chama apenas
        // imagem/capa/thumbnail. Se for numérica gravamos o ID; se for textual,
        // gravamos o caminho/URL da mídia.
        foreach (['imagem_destacada', 'featured_image', 'imagem', 'capa', 'thumbnail', 'foto', 'image'] as $column) {
            if (!isset($cols[$column])) {
                continue;
            }
            if ($this->columnIsNumeric($cols[$column])) {
                $record[$column] = $localId;
            } elseif ($localPath !== '') {
                $record[$column] = $localPath;
            } elseif ($localUrl !== '') {
                $record[$column] = $localUrl;
            }
        }
    }

    private function ensureFeaturedMedia(int $wpId, string $mode, int $userId, array $options): ?array
    {
        $existing = $this->mapRow('media', $wpId);
        $hasRecord = $existing && $this->recordExists('midias', (int)$existing['local_id']);
        $download = ($options['download_media'] ?? true) !== false;
        $repairRemote = ($options['repair_remote_media'] ?? true) !== false;

        if ($hasRecord) {
            if (!$download || !$repairRemote || $this->localMediaStorage($existing) !== null) {
                return $existing;
            }
            // Existe o registro, mas o arquivo não está no servidor local.
            // Continua abaixo para buscar novamente no WordPress.
        }
        if ($mode === 'simulate') {
            return $existing;
        }

        try {
            $response = $this->requestJson('/wp-json/wp/v2/media/' . $wpId, ['context' => 'view']);
            $media = is_array($response['data'] ?? null) ? $response['data'] : null;
            if (!$media || (int)($media['id'] ?? 0) <= 0) {
                return $existing;
            }

            try {
                $this->importMedia($media, $existing ? 'update' : 'new', $userId, $options);
            } catch (Throwable $downloadError) {
                // Mantemos a URL remota apenas como último recurso para não
                // impedir a publicação. Uma nova execução com "reparar mídias"
                // tentará o download novamente.
                if ($download) {
                    $fallbackOptions = $options;
                    $fallbackOptions['download_media'] = false;
                    $this->importMedia($media, $existing ? 'update' : 'new', $userId, $fallbackOptions);
                } else {
                    throw $downloadError;
                }
            }

            return $this->mapRow('media', $wpId);
        } catch (Throwable $ignored) {
            // A falta de uma capa não deve impedir a importação do conteúdo.
            return $existing;
        }
    }


    /**
     * URLs que o WordPress associa ao mesmo attachment. Além do source_url,
     * inclui os tamanhos gerados pelo próprio WordPress. Todos eles podem ser
     * substituídos pelo arquivo local principal do Portal.
     */
    private function collectMediaSourceUrls(array $item): array
    {
        $urls = [];
        $add = function (mixed $value) use (&$urls): void {
            if (!is_scalar($value)) {
                return;
            }
            $url = $this->normalizeMediaSourceUrl((string)$value);
            if ($url !== '') {
                $urls[$this->canonicalMediaUrl($url)] = $url;
            }
        };

        $add($item['source_url'] ?? '');
        if (is_array($item['guid'] ?? null)) {
            $add($item['guid']['rendered'] ?? '');
        } else {
            $add($item['guid'] ?? '');
        }
        $details = is_array($item['media_details'] ?? null) ? $item['media_details'] : [];
        $sizes = is_array($details['sizes'] ?? null) ? $details['sizes'] : [];
        foreach ($sizes as $size) {
            if (is_array($size)) {
                $add($size['source_url'] ?? '');
            }
        }
        return array_values($urls);
    }

    private function mediaDownloadCandidates(array $item): array
    {
        $urls = $this->collectMediaSourceUrls($item);
        $source = $this->normalizeMediaSourceUrl((string)($item['source_url'] ?? ''));
        if ($source !== '') {
            $urls = array_values(array_unique(array_merge([$source], $urls)));
        }
        return $urls;
    }

    private function normalizeMediaSourceUrl(string $url): string
    {
        $url = trim(html_entity_decode(str_replace('\\/', '/', $url), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '') {
            return '';
        }
        $base = parse_url($this->baseUrl);
        $scheme = strtolower((string)($base['scheme'] ?? 'https'));
        if (str_starts_with($url, '//')) {
            $url = $scheme . ':' . $url;
        } elseif (str_starts_with($url, '/')) {
            $host = (string)($base['host'] ?? '');
            if ($host === '') {
                return '';
            }
            $port = isset($base['port']) ? ':' . (int)$base['port'] : '';
            $url = $scheme . '://' . $host . $port . $url;
        }
        $url = str_replace(' ', '%20', $url);
        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || !isset($parts['scheme']) || !in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true)) {
            return '';
        }
        return $url;
    }

    private function canonicalMediaUrl(string $url): string
    {
        $url = $this->normalizeMediaSourceUrl($url);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if (!$parts) {
            return $url;
        }
        $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
        $host = strtolower((string)($parts['host'] ?? ''));
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        $path = (string)($parts['path'] ?? '/');
        return $scheme . '://' . $host . $port . $path;
    }

    /** Chave sem protocolo, domínio, query, tamanho e sufixos -scaled/-rotated. */
    private function canonicalMediaPathKey(string $url): string
    {
        $url = $this->normalizeMediaSourceUrl($url);
        if ($url === '') {
            return '';
        }
        $path = rawurldecode((string)(parse_url($url, PHP_URL_PATH) ?: ''));
        if ($path === '') {
            return '';
        }
        $dir = strtolower(trim(str_replace('\\', '/', dirname($path)), '/.'));
        $filename = basename($path);
        $stem = pathinfo($filename, PATHINFO_FILENAME);
        do {
            $before = $stem;
            $stem = preg_replace('/-(?:\d+x\d+|scaled|rotated)$/i', '', $stem) ?? $stem;
        } while ($stem !== $before);
        return $dir . '/' . strtolower($stem);
    }

    private function localMediaStorage(array $map): ?array
    {
        $localId = (int)($map['local_id'] ?? 0);
        if ($localId <= 0) {
            return null;
        }
        $row = $this->mediaStorageRow($localId);
        $values = [
            (string)($row['media_path'] ?? ''),
            (string)($row['media_url'] ?? ''),
            (string)($map['local_url'] ?? ''),
        ];
        $root = dirname(__DIR__, 2);
        foreach ($values as $value) {
            $relative = $this->relativeLocalUploadPath($value);
            if ($relative === '') {
                continue;
            }
            $absolute = $root . '/' . $relative;
            if (!is_file($absolute) || (int)filesize($absolute) <= 0) {
                continue;
            }
            $url = trim((string)($row['media_url'] ?? ''));
            if ($url === '' || $this->relativeLocalUploadPath($url) === '') {
                $url = function_exists('url') ? url($relative) : rtrim(defined('BASE_URL') ? BASE_URL : '', '/') . '/' . $relative;
            }
            return ['path' => $relative, 'url' => $url, 'size' => (int)filesize($absolute)];
        }
        return null;
    }

    private function mediaStorageRow(int $localId): array
    {
        if ($localId <= 0 || !$this->tableExists('midias')) {
            return [];
        }
        $cols = $this->columns('midias');
        $pathCol = $this->findColumn($cols, ['arquivo', 'caminho', 'path', 'arquivo_path']);
        $urlCol = $this->findColumn($cols, ['url', 'arquivo_url']);
        if (!$pathCol && !$urlCol) {
            return [];
        }
        $select = [];
        if ($pathCol) {
            $select[] = '`' . $pathCol . '` AS media_path';
        }
        if ($urlCol) {
            $select[] = '`' . $urlCol . '` AS media_url';
        }
        $stmt = $this->pdo->prepare('SELECT ' . implode(',', $select) . ' FROM `midias` WHERE id=:id LIMIT 1');
        $stmt->execute(['id' => $localId]);
        return $stmt->fetch() ?: [];
    }

    private function relativeLocalUploadPath(string $value): string
    {
        $value = trim(str_replace('\\', '/', $value));
        if ($value === '') {
            return '';
        }
        if (preg_match('~^https?://~i', $value)) {
            $value = (string)(parse_url($value, PHP_URL_PATH) ?: '');
        }
        $needle = 'public/uploads/wordpress/';
        $pos = stripos($value, $needle);
        if ($pos === false) {
            return '';
        }
        return ltrim(substr($value, $pos), '/');
    }

    private function saveMediaAliases(int $wpId, array $item, int $localId, string $localUrl, string $localPath): void
    {
        if (!$this->tableExists('wordpress_import_media_urls')) {
            return;
        }
        foreach ($this->collectMediaSourceUrls($item) as $sourceUrl) {
            $this->saveMediaAliasRow($sourceUrl, $wpId, $localId, $localUrl, $localPath);
        }
        $this->mediaAliasCache = [];
    }

    private function saveMediaAliasRow(string $sourceUrl, ?int $wpMediaId, ?int $localId, string $localUrl, string $localPath): void
    {
        if (!$this->tableExists('wordpress_import_media_urls')) {
            return;
        }
        $sourceUrl = $this->canonicalMediaUrl($sourceUrl);
        if ($sourceUrl === '') {
            return;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO wordpress_import_media_urls
             (origem_hash,source_hash,source_url,wp_media_id,local_id,local_url,local_path,created_at,updated_at)
             VALUES (:origem,:hash,:source,:wp,:local,:url,:path,NOW(),NOW())
             ON DUPLICATE KEY UPDATE
                wp_media_id=COALESCE(VALUES(wp_media_id),wp_media_id),
                local_id=COALESCE(VALUES(local_id),local_id),
                local_url=CASE WHEN VALUES(local_url)<>\'\' THEN VALUES(local_url) ELSE local_url END,
                local_path=CASE WHEN VALUES(local_path)<>\'\' THEN VALUES(local_path) ELSE local_path END,
                updated_at=NOW()'
        );
        $stmt->execute([
            'origem' => $this->originHash,
            'hash' => hash('sha256', $sourceUrl),
            'source' => $sourceUrl,
            'wp' => $wpMediaId && $wpMediaId > 0 ? $wpMediaId : null,
            'local' => $localId && $localId > 0 ? $localId : null,
            'url' => $localUrl,
            'path' => $localPath,
        ]);
    }

    private function lookupMediaAlias(string $sourceUrl): ?array
    {
        if (!$this->tableExists('wordpress_import_media_urls')) {
            return null;
        }
        $sourceUrl = $this->canonicalMediaUrl($sourceUrl);
        if ($sourceUrl === '') {
            return null;
        }
        $hash = hash('sha256', $sourceUrl);
        if (array_key_exists($hash, $this->mediaAliasCache)) {
            return $this->mediaAliasCache[$hash];
        }
        $stmt = $this->pdo->prepare('SELECT * FROM wordpress_import_media_urls WHERE origem_hash=:origem AND source_hash=:hash LIMIT 1');
        $stmt->execute(['origem' => $this->originHash, 'hash' => $hash]);
        $row = $stmt->fetch() ?: null;
        $this->mediaAliasCache[$hash] = $row;
        return $row;
    }

    private function aliasHasLocalFile(array $alias): bool
    {
        $path = $this->relativeLocalUploadPath((string)($alias['local_path'] ?? ''));
        if ($path === '') {
            $path = $this->relativeLocalUploadPath((string)($alias['local_url'] ?? ''));
        }
        if ($path === '') {
            return false;
        }
        $absolute = dirname(__DIR__, 2) . '/' . $path;
        return is_file($absolute) && (int)filesize($absolute) > 0;
    }

    /**
     * Traz para o servidor as imagens referenciadas dentro do HTML. Primeiro
     * tenta ligá-las a attachments da REST API; se isso não for possível,
     * baixa a URL diretamente e a cadastra na Biblioteca de Mídias.
     */
    private function ensureContentMediaLocal(string $html, int $userId, array $options): void
    {
        if ($html === '' || ($options['download_media'] ?? true) === false) {
            return;
        }
        $limit = 30;
        foreach ($this->extractContentMediaUrls($html) as $sourceUrl) {
            if ($limit-- <= 0) {
                break;
            }
            try {
                $alias = $this->lookupMediaAlias($sourceUrl);
                if ($alias && $this->aliasHasLocalFile($alias)) {
                    continue;
                }
                $wpMediaId = (int)($alias['wp_media_id'] ?? 0);
                if ($wpMediaId > 0) {
                    $this->ensureFeaturedMedia($wpMediaId, 'update', $userId, $options);
                    $alias = $this->lookupMediaAlias($sourceUrl);
                    if ($alias && $this->aliasHasLocalFile($alias)) {
                        continue;
                    }
                }

                $media = $this->findMediaForContentUrl($sourceUrl);
                if ($media) {
                    $wpId = (int)($media['id'] ?? 0);
                    $existing = $wpId > 0 ? $this->mapRow('media', $wpId) : null;
                    $this->importMedia($media, $existing ? 'update' : 'new', $userId, $options);
                    continue;
                }

                $this->importLooseMediaUrl($sourceUrl, $userId);
            } catch (Throwable $ignored) {
                // Uma imagem embutida inacessível não deve bloquear o post.
            }
        }
    }

    private function extractContentMediaUrls(string $html): array
    {
        $scan = html_entity_decode(str_replace('\\/', '/', $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $urls = [];
        if (preg_match_all('~(?:(?:https?:)?//[^\\s"\'<>]+)?/[^\\s"\'<>]*wp-content/uploads/[^\\s"\'<>]+~iu', $scan, $matches)) {
            foreach ($matches[0] as $match) {
                $match = rtrim((string)$match, ",;.)]}>");
                $url = $this->normalizeMediaSourceUrl($match);
                if ($url !== '') {
                    $urls[$this->canonicalMediaUrl($url)] = $url;
                }
            }
        }
        return array_values($urls);
    }

    private function findMediaForContentUrl(string $sourceUrl): ?array
    {
        $targetKey = $this->canonicalMediaPathKey($sourceUrl);
        if ($targetKey === '') {
            return null;
        }
        $path = rawurldecode((string)(parse_url($sourceUrl, PHP_URL_PATH) ?: ''));
        $stem = pathinfo(basename($path), PATHINFO_FILENAME);
        do {
            $before = $stem;
            $stem = preg_replace('/-(?:\d+x\d+|scaled|rotated)$/i', '', $stem) ?? $stem;
        } while ($stem !== $before);
        $slug = $this->slugify($stem);
        $queries = [];
        if ($slug !== '') {
            $queries[] = ['slug' => $slug, 'per_page' => 10, 'page' => 1];
        }
        if ($stem !== '') {
            $queries[] = ['search' => $stem, 'per_page' => 10, 'page' => 1];
        }

        foreach ($queries as $params) {
            try {
                $response = $this->requestCollection('/wp-json/wp/v2/media', $params);
            } catch (Throwable $ignored) {
                continue;
            }
            $items = is_array($response['items'] ?? null) ? $response['items'] : [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                foreach ($this->collectMediaSourceUrls($item) as $candidate) {
                    if ($this->canonicalMediaPathKey($candidate) === $targetKey) {
                        return $item;
                    }
                }
            }
            if (count($items) === 1 && is_array($items[0])) {
                return $items[0];
            }
        }
        return null;
    }

    private function importLooseMediaUrl(string $sourceUrl, int $userId): void
    {
        $sourceUrl = $this->normalizeMediaSourceUrl($sourceUrl);
        if ($sourceUrl === '') {
            return;
        }
        $alias = $this->lookupMediaAlias($sourceUrl);
        if ($alias && $this->aliasHasLocalFile($alias)) {
            return;
        }

        $originalName = basename((string)(parse_url($sourceUrl, PHP_URL_PATH) ?: 'wordpress-media'));
        [$relativePath, $localUrl, $mime, $size] = $this->downloadMediaFile($sourceUrl, $originalName, 'application/octet-stream');
        $cols = $this->columns('midias');
        $record = [];
        $name = rawurldecode(pathinfo($originalName, PATHINFO_FILENAME));
        $this->setFirst($record, $cols, ['nome', 'titulo', 'title'], $name !== '' ? $name : $originalName);
        $this->setFirst($record, $cols, ['arquivo', 'caminho', 'path', 'arquivo_path'], $relativePath);
        $this->setFirst($record, $cols, ['url', 'arquivo_url'], $localUrl);
        $this->setFirst($record, $cols, ['mime_type', 'tipo_mime', 'mime'], $mime);
        $this->setFirst($record, $cols, ['tamanho', 'size', 'tamanho_bytes'], $size);
        $this->setFirst($record, $cols, ['nome_arquivo', 'arquivo_original', 'original_name'], $originalName);
        $this->setFirst($record, $cols, ['usuario_id', 'autor_id', 'user_id'], $userId > 0 ? $userId : null);
        $this->setFirst($record, $cols, ['created_at', 'criado_em'], date('Y-m-d H:i:s'));
        $this->setFirst($record, $cols, ['updated_at', 'atualizado_em'], date('Y-m-d H:i:s'));

        $localId = (int)($alias['local_id'] ?? 0);
        if ($localId > 0 && $this->recordExists('midias', $localId)) {
            $this->updateAdaptive('midias', $localId, $record);
        } else {
            $localId = $this->insertAdaptive('midias', $record, $userId);
        }
        $this->saveMediaAliasRow($sourceUrl, null, $localId, $localUrl, $relativePath);
        $this->mediaAliasCache = [];
    }

    private function mediaLocalPath(int $localId): string
    {
        if ($localId <= 0 || !$this->tableExists('midias')) {
            return '';
        }
        $cols = $this->columns('midias');
        $pathCol = $this->findColumn($cols, ['arquivo', 'caminho', 'path', 'arquivo_path']);
        $urlCol = $this->findColumn($cols, ['url', 'arquivo_url']);
        if (!$pathCol && !$urlCol) {
            return '';
        }

        $select = [];
        if ($pathCol) {
            $select[] = '`' . $pathCol . '` AS media_path';
        }
        if ($urlCol) {
            $select[] = '`' . $urlCol . '` AS media_url';
        }
        $stmt = $this->pdo->prepare('SELECT ' . implode(',', $select) . ' FROM `midias` WHERE id=:id LIMIT 1');
        $stmt->execute(['id' => $localId]);
        $row = $stmt->fetch();
        if (!$row) {
            return '';
        }
        $path = trim((string)($row['media_path'] ?? ''));
        return $path !== '' ? $path : trim((string)($row['media_url'] ?? ''));
    }

    private function columnIsNumeric(array $meta): bool
    {
        $type = strtolower(trim((string)($meta['Type'] ?? '')));
        return (bool)preg_match('/^(tinyint|smallint|mediumint|int|bigint|decimal|numeric|float|double|real|bit)\\b/', $type);
    }

    private function downloadMediaFile(string $sourceUrl, string $originalName, string $fallbackMime): array
    {
        $sourceUrl = $this->normalizeMediaSourceUrl($sourceUrl);
        $parts = parse_url($sourceUrl);
        if (!$parts || !isset($parts['scheme']) || !in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true)) {
            throw new RuntimeException('URL de mídia inválida: ' . $sourceUrl);
        }
        $root = dirname(__DIR__, 2);
        $year = date('Y');
        $month = date('m');
        $directory = $root . '/public/uploads/wordpress/' . $year . '/' . $month;
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar a pasta de uploads do WordPress.');
        }

        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', rawurldecode($originalName)) ?: 'arquivo';
        $name = trim($name, '.-');
        if ($name === '') {
            $name = 'arquivo';
        }
        $info = pathinfo($name);
        $base = $info['filename'] ?? 'arquivo';
        $ext = isset($info['extension']) ? '.' . strtolower((string)$info['extension']) : '';
        $candidate = $base . $ext;
        $counter = 2;
        while (is_file($directory . '/' . $candidate)) {
            $candidate = $base . '-' . $counter . $ext;
            $counter++;
        }
        $target = $directory . '/' . $candidate;
        $maxBytes = defined('UPLOAD_MAX_SIZE') ? max((int)UPLOAD_MAX_SIZE, 25 * 1024 * 1024) : 25 * 1024 * 1024;

        $mime = $fallbackMime;
        if (function_exists('curl_init')) {
            $fh = fopen($target, 'wb');
            if (!$fh) {
                throw new RuntimeException('Não foi possível criar o arquivo de mídia local.');
            }
            $headers = [
                'User-Agent: Mozilla/5.0 (compatible; Portal-IECLB-WordPress-Importer/0.27.2)',
                'Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8',
                'Accept-Language: pt-BR,pt;q=0.9,en;q=0.7',
                'Cache-Control: no-cache',
                'Referer: ' . rtrim($this->baseUrl, '/') . '/',
            ];
            $sourceHost = strtolower((string)(parse_url($sourceUrl, PHP_URL_HOST) ?: ''));
            $baseHost = strtolower((string)(parse_url($this->baseUrl, PHP_URL_HOST) ?: ''));
            if ($this->username !== '' && $this->applicationPassword !== '' && $sourceHost !== '' && $sourceHost === $baseHost) {
                $headers[] = 'Authorization: Basic ' . base64_encode($this->username . ':' . $this->applicationPassword);
            }
            $ch = curl_init($sourceUrl);
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fh,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 4,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 90,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_ENCODING => '',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $ok = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
            $error = curl_error($ch);
            curl_close($ch);
            fclose($fh);
            if (!$ok || $status < 200 || $status >= 300) {
                @unlink($target);
                throw new RuntimeException('Falha ao baixar mídia (HTTP ' . $status . ')' . ($error !== '' ? ': ' . $error : '.'));
            }
            if ($contentType !== '') {
                $mime = trim(explode(';', $contentType)[0]);
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 90,
                    'follow_location' => 1,
                    'max_redirects' => 4,
                    'header' => "User-Agent: Mozilla/5.0 (compatible; Portal-IECLB-WordPress-Importer/0.27.2)\r\n" .
                                "Accept: image/avif,image/webp,image/apng,image/svg+xml,image/*,*/*;q=0.8\r\n" .
                                'Referer: ' . rtrim($this->baseUrl, '/') . "/\r\n",
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $body = @file_get_contents($sourceUrl, false, $context);
            if ($body === false) {
                throw new RuntimeException('Falha ao baixar mídia. Habilite cURL para melhor compatibilidade.');
            }
            if (strlen($body) > $maxBytes) {
                throw new RuntimeException('A mídia excede o limite de tamanho permitido.');
            }
            file_put_contents($target, $body, LOCK_EX);
        }

        $size = is_file($target) ? (int)filesize($target) : 0;
        if ($size <= 0) {
            @unlink($target);
            throw new RuntimeException('A mídia baixada está vazia.');
        }
        if ($size > $maxBytes) {
            @unlink($target);
            throw new RuntimeException('A mídia excede o limite de ' . round($maxBytes / 1048576) . ' MB.');
        }
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected = finfo_file($finfo, $target);
                finfo_close($finfo);
                if (is_string($detected) && $detected !== '') {
                    $mime = $detected;
                }
            }
        }
        if (in_array(strtolower($mime), ['text/html', 'application/json'], true)) {
            @unlink($target);
            throw new RuntimeException('O servidor retornou uma página de bloqueio no lugar do arquivo de mídia.');
        }
        $relative = 'public/uploads/wordpress/' . $year . '/' . $month . '/' . $candidate;
        $localUrl = function_exists('url') ? url($relative) : rtrim(defined('BASE_URL') ? BASE_URL : '', '/') . '/' . $relative;
        return [$relative, $localUrl, $mime, $size];
    }

    private function rewriteKnownMediaUrls(string $html): string
    {
        $rows = [];
        if ($this->tableExists('wordpress_import_media_urls')) {
            $stmt = $this->pdo->prepare(
                'SELECT source_url,local_url,local_path FROM wordpress_import_media_urls
                 WHERE origem_hash=:hash AND source_url IS NOT NULL
                 ORDER BY id ASC LIMIT 20000'
            );
            $stmt->execute(['hash' => $this->originHash]);
            $rows = $stmt->fetchAll() ?: [];
        }

        // Compatibilidade com mapas criados na v0.27.0/0.27.1 antes da tabela
        // de aliases existir.
        $stmt = $this->pdo->prepare(
            'SELECT source_url,local_url,NULL AS local_path FROM wordpress_import_map
             WHERE origem_hash=:hash AND wp_tipo=\'media\' AND source_url IS NOT NULL
             ORDER BY id ASC LIMIT 5000'
        );
        $stmt->execute(['hash' => $this->originHash]);
        $rows = array_merge($rows, $stmt->fetchAll() ?: []);

        $replace = [];
        $byPathKey = [];
        $root = dirname(__DIR__, 2);
        foreach ($rows as $row) {
            $source = $this->canonicalMediaUrl((string)($row['source_url'] ?? ''));
            if ($source === '') {
                continue;
            }

            $localPath = $this->relativeLocalUploadPath((string)($row['local_path'] ?? ''));
            $localUrl = trim((string)($row['local_url'] ?? ''));
            if ($localPath === '') {
                $localPath = $this->relativeLocalUploadPath($localUrl);
            }
            if ($localPath !== '' && is_file($root . '/' . $localPath)) {
                $localUrl = function_exists('url') ? url($localPath) : rtrim(defined('BASE_URL') ? BASE_URL : '', '/') . '/' . $localPath;
            }
            if ($localUrl === '' || $this->canonicalMediaUrl($localUrl) === $source) {
                continue;
            }

            $sourceVariants = [$source];
            $sourceParts = parse_url($source);
            if (is_array($sourceParts) && !empty($sourceParts['host'])) {
                $sourcePath = (string)($sourceParts['path'] ?? '');
                $port = isset($sourceParts['port']) ? ':' . (int)$sourceParts['port'] : '';
                $sourceVariants[] = '//' . (string)$sourceParts['host'] . $port . $sourcePath;
                if ($sourcePath !== '') {
                    $sourceVariants[] = $sourcePath;
                }
                $scheme = strtolower((string)($sourceParts['scheme'] ?? 'https'));
                $otherScheme = $scheme === 'https' ? 'http' : 'https';
                $sourceVariants[] = $otherScheme . '://' . (string)$sourceParts['host'] . $port . $sourcePath;
            }
            foreach (array_unique($sourceVariants) as $variant) {
                if ($variant === '') {
                    continue;
                }
                $replace[$variant] = $localUrl;
                $replace[htmlspecialchars($variant, ENT_QUOTES | ENT_HTML5, 'UTF-8')] = htmlspecialchars($localUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $replace[str_replace('/', '\\/', $variant)] = str_replace('/', '\\/', $localUrl);
            }
            $key = $this->canonicalMediaPathKey($source);
            if ($key !== '') {
                $byPathKey[$key] = $localUrl;
            }
        }

        if ($replace) {
            $html = strtr($html, $replace);
        }
        if (!$byPathKey) {
            return $html;
        }

        // Captura também URLs com query string e tamanhos não presentes no
        // source_url principal. Ex.: foto-300x200.jpg e foto-768x512.jpg.
        $rewritten = preg_replace_callback(
            '~https?://[^\\s"\'<>]+~iu',
            function (array $match) use ($byPathKey): string {
                $token = (string)$match[0];
                $clean = rtrim($token, ',;.)]}');
                $suffix = substr($token, strlen($clean));
                $key = $this->canonicalMediaPathKey(html_entity_decode($clean, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                return $key !== '' && isset($byPathKey[$key]) ? $byPathKey[$key] . $suffix : $token;
            },
            $html
        );
        return is_string($rewritten) ? $rewritten : $html;
    }

    private function syncPostTaxonomies(int $postId, array $item): void
    {
        $categories = is_array($item['categories'] ?? null) ? $item['categories'] : [];
        $tags = is_array($item['tags'] ?? null) ? $item['tags'] : [];

        // Relação estável usada pela Home a partir da v0.28.4. Mantemos também
        // a tabela legada, quando existir, para compatibilidade com outras telas.
        $this->syncPivot($postId, 'category', $categories, ['home_post_categorias'], ['categoria_id', 'category_id']);
        $this->syncPivot($postId, 'category', $categories, [
            'post_categorias','posts_categorias','post_categoria','posts_categoria',
            'categoria_posts','categorias_posts','categoria_post','categorias_post',
            'post_categories','posts_categories'
        ], ['categoria_id','categorias_id','category_id','categories_id']);
        $this->syncPivot($postId, 'tag', $tags, ['post_tags', 'posts_tags'], ['tag_id']);
    }

    private function syncPivot(int $postId, string $wpType, array $wpIds, array $tableCandidates, array $targetCandidates): void
    {
        $table = null;
        foreach ($tableCandidates as $candidate) {
            if ($this->tableExists($candidate)) {
                $table = $candidate;
                break;
            }
        }
        if (!$table) {
            return;
        }
        $cols = $this->columns($table);
        $postCol = $this->findColumn($cols, ['post_id', 'posts_id']);
        $targetCol = $this->findColumn($cols, $targetCandidates);
        if (!$postCol || !$targetCol) {
            return;
        }
        $localIds = [];
        foreach ($wpIds as $wpId) {
            $local = $this->mappedLocalId($wpType, (int)$wpId);
            if ($local) {
                $localIds[] = $local;
            }
        }
        if ($wpIds === []) {
            $this->pdo->prepare('DELETE FROM `' . $table . '` WHERE `' . $postCol . '`=:post')->execute(['post' => $postId]);
            return;
        }
        if (!$localIds) {
            return;
        }
        $this->pdo->prepare('DELETE FROM `' . $table . '` WHERE `' . $postCol . '`=:post')->execute(['post' => $postId]);
        $stmt = $this->pdo->prepare('INSERT IGNORE INTO `' . $table . '` (`' . $postCol . '`,`' . $targetCol . '`) VALUES (:post,:target)');
        foreach (array_unique($localIds) as $localId) {
            $stmt->execute(['post' => $postId, 'target' => $localId]);
        }
    }

    private function resolveDeferredRelationships(string $phase): void
    {
        if ($phase === 'categories') {
            $this->resolveParents('category', 'categorias', ['pai_id', 'parent_id', 'categoria_pai_id']);
        } elseif ($phase === 'pages') {
            $this->resolveParents('page', 'paginas', ['pai_id', 'parent_id', 'pagina_pai_id']);
        }
    }

    private function resolveParents(string $wpType, string $table, array $parentCandidates): void
    {
        if (!$this->tableExists($table)) {
            return;
        }
        $cols = $this->columns($table);
        $parentCol = $this->findColumn($cols, $parentCandidates);
        if (!$parentCol) {
            return;
        }
        $stmt = $this->pdo->prepare('SELECT wp_id,wp_parent_id,local_id FROM wordpress_import_map WHERE origem_hash=:hash AND wp_tipo=:tipo AND wp_parent_id IS NOT NULL AND wp_parent_id>0');
        $stmt->execute(['hash' => $this->originHash, 'tipo' => $wpType]);
        $update = $this->pdo->prepare('UPDATE `' . $table . '` SET `' . $parentCol . '`=:parent WHERE id=:id');
        foreach ($stmt->fetchAll() ?: [] as $row) {
            $parentLocal = $this->mappedLocalId($wpType, (int)$row['wp_parent_id']);
            if ($parentLocal) {
                $update->execute(['parent' => $parentLocal, 'id' => (int)$row['local_id']]);
            }
        }
    }

    private function mapRow(string $wpType, int $wpId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM wordpress_import_map WHERE origem_hash=:hash AND wp_tipo=:tipo AND wp_id=:wp LIMIT 1');
        $stmt->execute(['hash' => $this->originHash, 'tipo' => $wpType, 'wp' => $wpId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function mappedLocalId(string $wpType, int $wpId): ?int
    {
        if ($wpId <= 0) {
            return null;
        }
        $row = $this->mapRow($wpType, $wpId);
        return $row ? (int)$row['local_id'] : null;
    }

    private function saveMap(string $wpType, int $wpId, int $localId, string $localType, string $slug, ?string $modified, ?int $parentWpId = null, ?string $sourceUrl = null, ?string $localUrl = null): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO wordpress_import_map
             (origem_hash,origem_url,wp_id,wp_tipo,wp_parent_id,wp_slug,wp_modified,source_url,local_id,local_tipo,local_url,created_at,updated_at)
             VALUES (:hash,:url,:wp,:tipo,:parent,:slug,:modified,:source,:local,:local_tipo,:local_url,NOW(),NOW())
             ON DUPLICATE KEY UPDATE wp_parent_id=VALUES(wp_parent_id),wp_slug=VALUES(wp_slug),wp_modified=VALUES(wp_modified),source_url=COALESCE(VALUES(source_url),source_url),local_id=VALUES(local_id),local_tipo=VALUES(local_tipo),local_url=COALESCE(VALUES(local_url),local_url),updated_at=NOW()'
        );
        $stmt->execute([
            'hash' => $this->originHash,
            'url' => $this->baseUrl,
            'wp' => $wpId,
            'tipo' => $wpType,
            'parent' => $parentWpId,
            'slug' => $slug !== '' ? $slug : null,
            'modified' => $modified,
            'source' => $sourceUrl,
            'local' => $localId,
            'local_tipo' => $localType,
            'local_url' => $localUrl,
        ]);
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:table');
        $stmt->execute(['table' => $table]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function assertTable(string $table): void
    {
        if (!$this->tableExists($table)) {
            throw new RuntimeException('Tabela do módulo não encontrada: ' . $table . '.');
        }
    }

    private function columns(string $table): array
    {
        if (isset($this->columnCache[$table])) {
            return $this->columnCache[$table];
        }
        $rows = $this->pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`')->fetchAll();
        $out = [];
        foreach ($rows ?: [] as $row) {
            $out[(string)$row['Field']] = $row;
        }
        return $this->columnCache[$table] = $out;
    }

    private function findColumn(array $cols, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (isset($cols[$candidate])) {
                return $candidate;
            }
        }
        return null;
    }

    private function setFirst(array &$record, array $cols, array $candidates, mixed $value): void
    {
        $col = $this->findColumn($cols, $candidates);
        if ($col === null) {
            return;
        }
        if ($value === null) {
            $meta = $cols[$col];
            $nullable = strtoupper((string)($meta['Null'] ?? 'YES')) === 'YES';
            if ($nullable) {
                $record[$col] = null;
                return;
            }
            if (array_key_exists('Default', $meta) && $meta['Default'] !== null) {
                $record[$col] = $meta['Default'];
                return;
            }
            $record[$col] = $this->fallbackValueForColumn($meta);
            return;
        }
        $record[$col] = $value;
    }

    private function setAuditColumns(array &$record, array $cols, int $userId): void
    {
        $this->setFirst($record, $cols, ['usuario_id', 'autor_id', 'user_id'], $userId > 0 ? $userId : null);
        $this->setFirst($record, $cols, ['created_at', 'criado_em'], date('Y-m-d H:i:s'));
        $this->setFirst($record, $cols, ['updated_at', 'atualizado_em'], date('Y-m-d H:i:s'));
    }

    private function insertAdaptive(string $table, array $record, int $userId): int
    {
        $cols = $this->columns($table);
        foreach ($cols as $name => $meta) {
            if (array_key_exists($name, $record)) {
                continue;
            }
            $extra = strtolower((string)($meta['Extra'] ?? ''));
            $nullable = strtoupper((string)($meta['Null'] ?? 'YES')) === 'YES';
            $default = $meta['Default'] ?? null;
            if (str_contains($extra, 'auto_increment') || str_contains($extra, 'generated') || $nullable || $default !== null) {
                continue;
            }
            if (in_array($name, ['usuario_id', 'autor_id', 'user_id'], true) && $userId > 0) {
                $record[$name] = $userId;
                continue;
            }
            if (in_array($name, ['created_at', 'updated_at', 'criado_em', 'atualizado_em'], true)) {
                $record[$name] = date('Y-m-d H:i:s');
                continue;
            }
            $record[$name] = $this->fallbackValueForColumn($meta);
        }
        if (!$record) {
            throw new RuntimeException('Não foi possível mapear campos para a tabela ' . $table . '.');
        }
        $names = array_keys($record);
        $quoted = array_map(static fn(string $c): string => '`' . str_replace('`', '``', $c) . '`', $names);
        $params = array_map(static fn(string $c): string => ':' . $c, $names);
        $stmt = $this->pdo->prepare('INSERT INTO `' . $table . '` (' . implode(',', $quoted) . ') VALUES (' . implode(',', $params) . ')');
        $stmt->execute($record);
        return (int)$this->pdo->lastInsertId();
    }

    private function updateAdaptive(string $table, int $id, array $record): void
    {
        if (!$record) {
            return;
        }
        $cols = $this->columns($table);
        $pk = $this->primaryKey($cols);
        $sets = [];
        foreach (array_keys($record) as $name) {
            if ($name === $pk) {
                unset($record[$name]);
                continue;
            }
            $sets[] = '`' . str_replace('`', '``', $name) . '`=:' . $name;
        }
        if (!$sets) {
            return;
        }
        $record['_id'] = $id;
        $stmt = $this->pdo->prepare('UPDATE `' . $table . '` SET ' . implode(',', $sets) . ' WHERE `' . $pk . '`=:_id');
        $stmt->execute($record);
    }

    private function primaryKey(array $cols): string
    {
        foreach ($cols as $name => $meta) {
            if (strtoupper((string)($meta['Key'] ?? '')) === 'PRI') {
                return $name;
            }
        }
        return isset($cols['id']) ? 'id' : array_key_first($cols);
    }

    private function recordExists(string $table, int $id): bool
    {
        if ($id <= 0 || !$this->tableExists($table)) {
            return false;
        }
        $cols = $this->columns($table);
        $pk = $this->primaryKey($cols);
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $pk . '`=:id');
        $stmt->execute(['id' => $id]);
        return (int)$stmt->fetchColumn() > 0;
    }

    private function fallbackValueForColumn(array $meta): mixed
    {
        $type = strtolower((string)($meta['Type'] ?? ''));
        if (preg_match('/^enum\((.*)\)$/i', $type, $m)) {
            $values = str_getcsv($m[1], ',', "'");
            return $values[0] ?? '';
        }
        if (str_contains($type, 'int') || str_contains($type, 'decimal') || str_contains($type, 'float') || str_contains($type, 'double')) {
            return 0;
        }
        if (str_contains($type, 'datetime') || str_contains($type, 'timestamp')) {
            return date('Y-m-d H:i:s');
        }
        if ($type === 'date' || str_starts_with($type, 'date(')) {
            return date('Y-m-d');
        }
        if (str_contains($type, 'time')) {
            return '00:00:00';
        }
        return '';
    }

    private function uniqueSlug(string $table, string $slugCol, string $slug, ?int $excludeId): string
    {
        $slug = $this->slugify($slug);
        if ($slug === '') {
            $slug = 'item';
        }
        $cols = $this->columns($table);
        $pk = $this->primaryKey($cols);
        $base = mb_substr($slug, 0, 180);
        $candidate = $base;
        $n = 2;
        while (true) {
            $sql = 'SELECT COUNT(*) FROM `' . $table . '` WHERE `' . $slugCol . '`=:slug';
            $params = ['slug' => $candidate];
            if ($excludeId) {
                $sql .= ' AND `' . $pk . '`<>:id';
                $params['id'] = $excludeId;
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            if ((int)$stmt->fetchColumn() === 0) {
                return $candidate;
            }
            $suffix = '-' . $n++;
            $candidate = mb_substr($base, 0, max(1, 190 - mb_strlen($suffix))) . $suffix;
        }
    }

    private function adaptStatus(array $meta, string $wpStatus): string
    {
        $type = strtolower((string)($meta['Type'] ?? ''));
        $published = in_array(strtolower($wpStatus), ['publish', 'published', 'publicado', 'active', 'ativo'], true);
        $preferred = $published
            ? ['publicado', 'publish', 'published', 'ativo', 'active']
            : ['rascunho', 'draft', 'pendente', 'pending', 'inativo', 'inactive'];
        if (preg_match('/^enum\((.*)\)$/i', $type, $m)) {
            $values = str_getcsv($m[1], ',', "'");
            foreach ($preferred as $candidate) {
                if (in_array($candidate, $values, true)) {
                    return $candidate;
                }
            }
            return (string)($values[0] ?? ($published ? 'publicado' : 'rascunho'));
        }
        return $published ? 'publicado' : 'rascunho';
    }

    private function rendered(mixed $value): string
    {
        if (is_array($value)) {
            return trim((string)($value['rendered'] ?? $value['raw'] ?? ''));
        }
        return is_scalar($value) ? trim((string)$value) : '';
    }

    private function wpDate(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }
        try {
            $dt = new DateTimeImmutable($value);
            return $dt->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            return null;
        }
    }

    private function wpModified(array $item): ?string
    {
        return $this->wpDate($item['modified_gmt'] ?? $item['modified'] ?? null);
    }

    private function slugify(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($ascii) && $ascii !== '') {
            $value = $ascii;
        }
        $value = strtolower($value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }

    private function isListArray(array $value): bool
    {
        if ($value === []) {
            return true;
        }
        return array_keys($value) === range(0, count($value) - 1);
    }

    private function firstValue(array $item, array $paths): mixed
    {
        foreach ($paths as $path) {
            $value = $item;
            $ok = true;
            foreach (explode('.', $path) as $part) {
                if (!is_array($value) || !array_key_exists($part, $value)) {
                    $ok = false;
                    break;
                }
                $value = $value[$part];
            }
            if ($ok && $value !== null && $value !== '') {
                return $value;
            }
        }
        return null;
    }

    private function log(int $jobId, string $level, string $message, ?int $wpId = null): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO wordpress_import_logs (importacao_id,nivel,wp_id,mensagem,created_at) VALUES (:id,:nivel,:wp,:msg,NOW())');
        $stmt->execute([
            'id' => $jobId,
            'nivel' => mb_substr($level, 0, 20),
            'wp' => $wpId,
            'msg' => mb_substr($message, 0, 10000),
        ]);
    }
}
