<?php

declare(strict_types=1);

/**
 * SEO e compartilhamento v1.1.14.
 *
 * - imagem social automática;
 * - JSON-LD para notícias, eventos e comunidades;
 * - redirects 301 gerenciados pelo painel;
 * - normalização de URLs absolutas para canonical/Open Graph.
 */
final class SeoSharingService
{
    /** @var array<int,bool> */
    private static array $schemaReady = [];

    public static function ensureSchema(PDO $pdo): void
    {
        $key = spl_object_id($pdo);

        if (!empty(self::$schemaReady[$key])) {
            return;
        }

        if ($pdo->inTransaction()) {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM information_schema.tables
                 WHERE table_schema=DATABASE()
                   AND table_name=?'
            );
            $stmt->execute(['seo_redirects']);

            if ((int)$stmt->fetchColumn() <= 0) {
                throw new RuntimeException(
                    'A estrutura de redirects SEO da v1.1.14 não foi inicializada antes da transação.'
                );
            }

            self::$schemaReady[$key] = true;
            return;
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS seo_redirects (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                source_path VARCHAR(500) NOT NULL,
                target_path VARCHAR(500) NOT NULL,
                http_code SMALLINT NOT NULL DEFAULT 301,
                ativo TINYINT(1) NOT NULL DEFAULT 1,
                hits BIGINT UNSIGNED NOT NULL DEFAULT 0,
                last_hit_at DATETIME NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uq_seo_redirect_source (source_path),
                KEY idx_seo_redirect_active (ativo),
                KEY idx_seo_redirect_hits (hits)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        self::$schemaReady[$key] = true;
    }

    public static function absoluteUrl(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return $value;
        }

        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }

        $candidate = url(ltrim($value, '/'));

        if (preg_match('#^https?://#i', $candidate)) {
            return $candidate;
        }

        if (PHP_SAPI === 'cli') {
            return $candidate;
        }

        $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));

        if ($host === '') {
            return $candidate;
        }

        $https =
            (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
            || (string)($_SERVER['SERVER_PORT'] ?? '') === '443'
            || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

        $scheme = $https ? 'https' : 'http';

        return $scheme
            . '://'
            . $host
            . '/'
            . ltrim($candidate, '/');
    }

    public static function automaticSocialImageUrl(
        string $type,
        int $id,
        string $version = ''
    ): string {
        if ($id <= 0) {
            return '';
        }

        $type = match ($type) {
            'post', 'noticia' => 'post',
            'evento' => 'evento',
            'comunidade' => 'comunidade',
            default => '',
        };

        if ($type === '') {
            return '';
        }

        $query =
            'social-image.php?type='
            . rawurlencode($type)
            . '&id='
            . $id;

        if ($version !== '') {
            $query .= '&v=' . rawurlencode($version);
        }

        return self::absoluteUrl(url($query));
    }

    /**
     * Determina a imagem automática a partir das variáveis da página pública.
     *
     * @param array<string,mixed>|null $noticia
     * @param array<string,mixed>|null $evento
     * @param array<string,mixed>|null $comunidade
     */
    public static function socialImageForContent(
        ?array $noticia,
        ?array $evento,
        ?array $comunidade
    ): string {
        if ($noticia) {
            return self::automaticSocialImageUrl(
                'post',
                (int)($noticia['id'] ?? 0),
                (string)(
                    $noticia['updated_at']
                    ?? $noticia['publicado_em']
                    ?? $noticia['created_at']
                    ?? ''
                )
            );
        }

        if ($evento) {
            return self::automaticSocialImageUrl(
                'evento',
                (int)($evento['id'] ?? 0),
                (string)(
                    $evento['updated_at']
                    ?? $evento['data_inicio']
                    ?? $evento['created_at']
                    ?? ''
                )
            );
        }

        if ($comunidade) {
            return self::automaticSocialImageUrl(
                'comunidade',
                (int)($comunidade['id'] ?? 0),
                (string)(
                    $comunidade['updated_at']
                    ?? $comunidade['created_at']
                    ?? ''
                )
            );
        }

        return '';
    }

    /**
     * @param array<string,mixed> $noticia
     * @return array<string,mixed>
     */
    public static function newsJsonLd(
        array $noticia,
        string $canonical,
        string $image,
        string $siteName
    ): array {
        $description = trim((string)($noticia['resumo'] ?? ''));

        if ($description === '') {
            $description = portalExcerpt(
                (string)($noticia['conteudo'] ?? ''),
                220
            );
        }

        $published =
            (string)(
                $noticia['publicado_em']
                ?? $noticia['created_at']
                ?? ''
            );

        $modified =
            (string)(
                $noticia['updated_at']
                ?? $published
            );

        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'NewsArticle',
            'headline' => (string)($noticia['titulo'] ?? ''),
            'description' => $description,
            'mainEntityOfPage' => [
                '@type' => 'WebPage',
                '@id' => $canonical,
            ],
            'url' => $canonical,
            'publisher' => [
                '@type' => 'Organization',
                'name' => $siteName,
                'url' => self::absoluteUrl(url()),
            ],
        ];

        if ($published !== '' && strtotime($published) !== false) {
            $data['datePublished'] = date(DATE_ATOM, strtotime($published));
        }

        if ($modified !== '' && strtotime($modified) !== false) {
            $data['dateModified'] = date(DATE_ATOM, strtotime($modified));
        }

        $author = trim((string)($noticia['autor_nome'] ?? ''));

        if ($author !== '') {
            $data['author'] = [
                '@type' => 'Person',
                'name' => $author,
            ];
        }

        if ($image !== '') {
            $data['image'] = [$image];
        }

        return $data;
    }

    /**
     * @param array<string,mixed> $evento
     * @return array<string,mixed>
     */
    public static function eventJsonLd(
        array $evento,
        string $canonical,
        string $image,
        string $siteName
    ): array {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Event',
            'name' => (string)($evento['titulo'] ?? ''),
            'description' => portalExcerpt(
                (string)($evento['descricao'] ?? ''),
                240
            ),
            'url' => $canonical,
            'eventStatus' => 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'organizer' => [
                '@type' => 'Organization',
                'name' => $siteName,
                'url' => self::absoluteUrl(url()),
            ],
        ];

        $start = (string)($evento['data_inicio'] ?? '');

        if ($start !== '' && strtotime($start) !== false) {
            $data['startDate'] = date(DATE_ATOM, strtotime($start));
        }

        $end = (string)($evento['data_fim'] ?? '');

        if ($end !== '' && strtotime($end) !== false) {
            $data['endDate'] = date(DATE_ATOM, strtotime($end));
        }

        $place = trim((string)($evento['local'] ?? ''));
        $address = trim((string)($evento['endereco'] ?? ''));

        if ($place !== '' || $address !== '') {
            $data['location'] = [
                '@type' => 'Place',
                'name' => $place !== '' ? $place : $siteName,
            ];

            if ($address !== '') {
                $data['location']['address'] = [
                    '@type' => 'PostalAddress',
                    'streetAddress' => $address,
                ];
            }
        }

        if ($image !== '') {
            $data['image'] = [$image];
        }

        $registration = trim(
            (string)(
                $evento['url_inscricao']
                ?? $evento['link_inscricao']
                ?? ''
            )
        );

        if ($registration !== '') {
            $data['offers'] = [
                '@type' => 'Offer',
                'url' => self::absoluteUrl($registration),
                'availability' => 'https://schema.org/InStock',
            ];
        }

        return $data;
    }

    /**
     * @param array<string,mixed> $comunidade
     * @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    public static function communityJsonLd(
        array $comunidade,
        array $profile,
        string $canonical,
        string $image
    ): array {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Church',
            'name' => (string)($comunidade['nome'] ?? ''),
            'description' => portalExcerpt(
                (string)($comunidade['descricao'] ?? $comunidade['conteudo'] ?? ''),
                240
            ),
            'url' => $canonical,
        ];

        if ($image !== '') {
            $data['image'] = $image;
        }

        $street = trim((string)($comunidade['endereco'] ?? ''));
        $city = trim((string)($comunidade['cidade'] ?? ''));
        $state = trim(
            (string)(
                $comunidade['uf']
                ?? $comunidade['estado']
                ?? ''
            )
        );

        if ($street !== '' || $city !== '' || $state !== '') {
            $data['address'] = [
                '@type' => 'PostalAddress',
            ];

            if ($street !== '') {
                $data['address']['streetAddress'] = $street;
            }

            if ($city !== '') {
                $data['address']['addressLocality'] = $city;
            }

            if ($state !== '') {
                $data['address']['addressRegion'] = $state;
            }

            $data['address']['addressCountry'] = 'BR';
        }

        $phone = trim((string)($profile['telefone'] ?? $profile['whatsapp'] ?? ''));

        if ($phone !== '') {
            $data['telephone'] = $phone;
        }

        $email = trim((string)($profile['email'] ?? ''));

        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $data['email'] = $email;
        }

        $sameAs = [];

        foreach (
            ['instagram', 'facebook', 'youtube', 'site']
            as $key
        ) {
            $value = trim((string)($profile[$key] ?? ''));

            if ($value !== '' && preg_match('#^https?://#i', $value)) {
                $sameAs[] = $value;
            }
        }

        if ($sameAs) {
            $data['sameAs'] = array_values(array_unique($sameAs));
        }

        return $data;
    }

    /**
     * @param array<string,mixed>|null $noticia
     * @param array<string,mixed>|null $evento
     * @param array<string,mixed>|null $comunidade
     * @param array<string,mixed> $profile
     * @return array<int,array<string,mixed>>
     */
    public static function structuredDataForContent(
        ?array $noticia,
        ?array $evento,
        ?array $comunidade,
        array $profile,
        string $canonical,
        string $image,
        string $siteName
    ): array {
        if ($noticia) {
            return [
                self::newsJsonLd(
                    $noticia,
                    $canonical,
                    $image,
                    $siteName
                ),
            ];
        }

        if ($evento) {
            return [
                self::eventJsonLd(
                    $evento,
                    $canonical,
                    $image,
                    $siteName
                ),
            ];
        }

        if ($comunidade) {
            return [
                self::communityJsonLd(
                    $comunidade,
                    $profile,
                    $canonical,
                    $image
                ),
            ];
        }

        return [];
    }

    public static function normalizeSourcePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return '/';
        }

        $parsed = parse_url($path, PHP_URL_PATH);

        if (!is_string($parsed) || $parsed === '') {
            $parsed = $path;
        }

        $parsed = '/' . ltrim($parsed, '/');

        $parsed = preg_replace('#/+#', '/', $parsed) ?: '/';

        if (strlen($parsed) > 1) {
            $parsed = rtrim($parsed, '/');
        }

        return $parsed;
    }

    public static function normalizeTargetPath(string $path): string
    {
        $path = trim($path);

        if (
            $path === ''
            || preg_match('#^[a-z][a-z0-9+.-]*://#i', $path)
        ) {
            throw new RuntimeException(
                'O destino deve ser um caminho interno do Portal, como /noticia/nova-url.'
            );
        }

        return self::normalizeSourcePath($path);
    }

    public static function saveRedirect(
        PDO $pdo,
        string $source,
        string $target,
        int $httpCode = 301,
        bool $active = true,
        ?int $id = null
    ): int {
        self::ensureSchema($pdo);

        $source = self::normalizeSourcePath($source);
        $target = self::normalizeTargetPath($target);

        if ($source === $target) {
            throw new RuntimeException(
                'Origem e destino do redirect não podem ser iguais.'
            );
        }

        if (
            str_starts_with($source, '/admin/')
            || $source === '/admin'
        ) {
            throw new RuntimeException(
                'Não é permitido criar redirect para a área administrativa.'
            );
        }

        $httpCode = in_array($httpCode, [301, 302], true)
            ? $httpCode
            : 301;

        if ($id !== null && $id > 0) {
            $stmt = $pdo->prepare(
                "UPDATE seo_redirects
                 SET
                    source_path=:source,
                    target_path=:target,
                    http_code=:code,
                    ativo=:ativo,
                    updated_at=NOW()
                 WHERE id=:id"
            );

            $stmt->execute([
                'source' => $source,
                'target' => $target,
                'code' => $httpCode,
                'ativo' => $active ? 1 : 0,
                'id' => $id,
            ]);

            return $id;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO seo_redirects
                (source_path,target_path,http_code,ativo,hits,created_at,updated_at)
             VALUES
                (:source,:target,:code,:ativo,0,NOW(),NOW())
             ON DUPLICATE KEY UPDATE
                target_path=VALUES(target_path),
                http_code=VALUES(http_code),
                ativo=VALUES(ativo),
                updated_at=NOW()"
        );

        $stmt->execute([
            'source' => $source,
            'target' => $target,
            'code' => $httpCode,
            'ativo' => $active ? 1 : 0,
        ]);

        $savedId = (int)$pdo->lastInsertId();

        if ($savedId > 0) {
            return $savedId;
        }

        $find = $pdo->prepare(
            'SELECT id
             FROM seo_redirects
             WHERE source_path=?
             LIMIT 1'
        );
        $find->execute([$source]);

        return (int)$find->fetchColumn();
    }

    public static function deleteRedirect(
        PDO $pdo,
        int $id
    ): bool {
        self::ensureSchema($pdo);

        $stmt = $pdo->prepare(
            'DELETE FROM seo_redirects WHERE id=?'
        );
        $stmt->execute([$id]);

        return $stmt->rowCount() === 1;
    }

    /** @return array<int,array<string,mixed>> */
    public static function redirects(
        PDO $pdo,
        int $limit = 250
    ): array {
        self::ensureSchema($pdo);

        $limit = max(1, min(1000, $limit));

        return $pdo->query(
            "SELECT *
             FROM seo_redirects
             ORDER BY id DESC
             LIMIT {$limit}"
        )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function maybeRedirectRequest(PDO $pdo): void
    {
        if (
            PHP_SAPI === 'cli'
            || headers_sent()
            || !in_array(
                strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')),
                ['GET', 'HEAD'],
                true
            )
        ) {
            return;
        }

        $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');

        if ($requestUri === '') {
            return;
        }

        $path = parse_url($requestUri, PHP_URL_PATH);

        if (!is_string($path) || $path === '') {
            return;
        }

        /*
         * Transforma /subpasta/portal/noticia/x em /noticia/x.
         */
        $basePath = parse_url(url(), PHP_URL_PATH);

        if (
            is_string($basePath)
            && $basePath !== ''
            && $basePath !== '/'
        ) {
            $basePath = '/' . trim($basePath, '/');

            if ($path === $basePath) {
                $path = '/';
            } elseif (str_starts_with($path, $basePath . '/')) {
                $path = substr($path, strlen($basePath));
            }
        }

        $source = self::normalizeSourcePath($path);

        foreach (
            [
                '/admin',
                '/public',
                '/storage',
                '/lib',
                '/tests',
            ]
            as $blocked
        ) {
            if ($source === $blocked || str_starts_with($source, $blocked . '/')) {
                return;
            }
        }

        if (
            in_array(
                $source,
                [
                    '/social-image.php',
                    '/sitemap.php',
                    '/robots.php',
                ],
                true
            )
        ) {
            return;
        }

        try {
            $stmt = $pdo->prepare(
                "SELECT
                    id,
                    target_path,
                    http_code
                 FROM seo_redirects
                 WHERE source_path=?
                   AND ativo=1
                 LIMIT 1"
            );
            $stmt->execute([$source]);

            $redirect = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$redirect) {
                return;
            }

            $targetPath = self::normalizeTargetPath(
                (string)$redirect['target_path']
            );

            if ($targetPath === $source) {
                return;
            }

            $target = self::absoluteUrl(
                url(ltrim($targetPath, '/'))
            );

            $hit = $pdo->prepare(
                "UPDATE seo_redirects
                 SET
                    hits=hits+1,
                    last_hit_at=NOW()
                 WHERE id=?"
            );
            $hit->execute([(int)$redirect['id']]);

            $code = (int)$redirect['http_code'];

            if (!in_array($code, [301, 302], true)) {
                $code = 301;
            }

            header(
                'Location: ' . $target,
                true,
                $code
            );

            exit;
        } catch (Throwable $ignored) {
            /*
             * Durante migração ou instalação, redirects não podem derrubar
             * o Portal público.
             */
        }
    }
}
