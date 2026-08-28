<?php

declare(strict_types=1);

const FIX_NAME = 'v0.42.0-r2';

function out(string $message = ''): void
{
    echo $message . PHP_EOL;
}

function fail(string $message): never
{
    out('[ERRO] ' . $message);
    exit(1);
}

function lintPhp(string $file): void
{
    $command = escapeshellarg(PHP_BINARY ?: 'php') . ' -l ' . escapeshellarg($file) . ' 2>&1';
    $lines = [];
    $code = 1;
    exec($command, $lines, $code);

    if ($code !== 0) {
        throw new RuntimeException(
            basename($file) . " não passou no php -l:\n" . implode(PHP_EOL, $lines)
        );
    }
}

function backupFile(string $root, string $backupDir, string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $relative = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
    $target = $backupDir . '/' . $relative;

    if (!is_dir(dirname($target))
        && !mkdir(dirname($target), 0755, true)
        && !is_dir(dirname($target))) {
        throw new RuntimeException('Não foi possível criar backup de ' . $relative . '.');
    }

    if (!copy($path, $target)) {
        throw new RuntimeException('Não foi possível criar backup de ' . $relative . '.');
    }
}

function writeChanged(
    string $root,
    string $backupDir,
    string $path,
    string $source,
    string $label
): void {
    $current = is_file($path) ? (string)file_get_contents($path) : '';

    if ($current === $source) {
        out('[OK] ' . $label . ' já estava atualizado.');
        return;
    }

    backupFile($root, $backupDir, $path);

    if (file_put_contents($path, $source, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível atualizar ' . $label . '.');
    }

    lintPhp($path);
    out('[OK] ' . $label . ' atualizado.');
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_name=?'
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.columns
         WHERE table_schema=DATABASE()
           AND table_name=?
           AND column_name=?'
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function indexExists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.statistics
         WHERE table_schema=DATABASE()
           AND table_name=?
           AND index_name=?'
    );
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

function constraintExists(PDO $pdo, string $table, string $constraint): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.table_constraints
         WHERE table_schema=DATABASE()
           AND table_name=?
           AND constraint_name=?'
    );
    $stmt->execute([$table, $constraint]);
    return (int)$stmt->fetchColumn() > 0;
}

function ensureDatabaseSchema(PDO $pdo): void
{
    if (!tableExists($pdo, 'posts')) {
        throw new RuntimeException('Tabela posts não encontrada.');
    }
    if (!tableExists($pdo, 'categorias')) {
        throw new RuntimeException('Tabela categorias não encontrada.');
    }

    if (!columnExists($pdo, 'categorias', 'parent_id')) {
        $pdo->exec(
            'ALTER TABLE categorias
             ADD COLUMN parent_id INT UNSIGNED NULL AFTER descricao'
        );
        out('[OK] categorias.parent_id criado.');
    }

    if (!indexExists($pdo, 'categorias', 'idx_categorias_parent_id')) {
        $pdo->exec(
            'CREATE INDEX idx_categorias_parent_id
             ON categorias (parent_id)'
        );
        out('[OK] Índice hierárquico de categorias criado.');
    }

    if (!constraintExists($pdo, 'categorias', 'fk_categorias_parent')) {
        try {
            $pdo->exec(
                'ALTER TABLE categorias
                 ADD CONSTRAINT fk_categorias_parent
                 FOREIGN KEY (parent_id) REFERENCES categorias(id)
                 ON DELETE SET NULL
                 ON UPDATE CASCADE'
            );
            out('[OK] FK hierárquica de categorias criada.');
        } catch (Throwable $e) {
            // A coluna e o índice são suficientes para o Portal funcionar.
            out('[AVISO] FK hierárquica não criada: ' . $e->getMessage());
        }
    }

    if (!tableExists($pdo, 'post_categorias')) {
        $pdo->exec(
            "CREATE TABLE post_categorias (
                post_id INT UNSIGNED NOT NULL,
                categoria_id INT UNSIGNED NOT NULL,
                principal TINYINT(1) NOT NULL DEFAULT 0,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (post_id,categoria_id),
                KEY idx_post_categorias_categoria (categoria_id,post_id),
                KEY idx_post_categorias_principal (post_id,principal),
                CONSTRAINT fk_post_categorias_post
                    FOREIGN KEY (post_id) REFERENCES posts(id)
                    ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT fk_post_categorias_categoria
                    FOREIGN KEY (categoria_id) REFERENCES categorias(id)
                    ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        out('[OK] Tabela post_categorias criada.');
    }

    if (!columnExists($pdo, 'post_categorias', 'principal')) {
        $pdo->exec(
            'ALTER TABLE post_categorias
             ADD COLUMN principal TINYINT(1) NOT NULL DEFAULT 0'
        );
    }

    if (!columnExists($pdo, 'post_categorias', 'created_at')) {
        $pdo->exec(
            'ALTER TABLE post_categorias
             ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP'
        );
    }

    if (!indexExists($pdo, 'post_categorias', 'idx_post_categorias_categoria')) {
        $pdo->exec(
            'CREATE INDEX idx_post_categorias_categoria
             ON post_categorias (categoria_id,post_id)'
        );
    }

    if (!indexExists($pdo, 'post_categorias', 'idx_post_categorias_principal')) {
        $pdo->exec(
            'CREATE INDEX idx_post_categorias_principal
             ON post_categorias (post_id,principal)'
        );
    }

    // Converte o vínculo legado para a tabela muitos-para-muitos.
    if (columnExists($pdo, 'posts', 'categoria_id')) {
        $pdo->exec(
            "INSERT IGNORE INTO post_categorias
                (post_id,categoria_id,principal)
             SELECT p.id,p.categoria_id,1
             FROM posts p
             INNER JOIN categorias c ON c.id=p.categoria_id
             WHERE p.categoria_id IS NOT NULL
               AND p.categoria_id>0"
        );

        $pdo->exec(
            "UPDATE post_categorias pc
             INNER JOIN posts p
                ON p.id=pc.post_id
               AND p.categoria_id=pc.categoria_id
             SET pc.principal=1
             WHERE p.categoria_id IS NOT NULL"
        );

        out('[OK] Categorias legadas das notícias sincronizadas.');
    }

    if (!tableExists($pdo, 'post_categorias')) {
        throw new RuntimeException(
            'A tabela post_categorias ainda não existe após a correção.'
        );
    }
}

function patchCategoryService(
    string $root,
    string $backupDir,
    string $path
): void {
    if (!is_file($path)) {
        throw new RuntimeException('CategoryService.php não encontrado.');
    }

    $source = (string)file_get_contents($path);

    if (str_contains($source, 'public static function ensureSchema(PDO $pdo): void')) {
        out('[OK] CategoryService já garante o schema.');
        return;
    }

    $anchor = "final class CategoryService\n{\n";

    $method = <<<'PHP'
final class CategoryService
{
    /**
     * Garante a estrutura usada pelas categorias hierárquicas e pelas
     * múltiplas categorias de Posts. Idempotente por requisição.
     */
    public static function ensureSchema(PDO $pdo): void
    {
        static $ensured = false;
        if ($ensured) {
            return;
        }

        $tableExists = static function (string $table) use ($pdo): bool {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name=?'
            );
            $stmt->execute([$table]);
            return (int)$stmt->fetchColumn() > 0;
        };

        $columnExists = static function (string $table, string $column) use ($pdo): bool {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema=DATABASE()
                   AND table_name=?
                   AND column_name=?'
            );
            $stmt->execute([$table, $column]);
            return (int)$stmt->fetchColumn() > 0;
        };

        $indexExists = static function (string $table, string $index) use ($pdo): bool {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema=DATABASE()
                   AND table_name=?
                   AND index_name=?'
            );
            $stmt->execute([$table, $index]);
            return (int)$stmt->fetchColumn() > 0;
        };

        if (!$tableExists('posts') || !$tableExists('categorias')) {
            return;
        }

        if (!$columnExists('categorias', 'parent_id')) {
            $pdo->exec(
                'ALTER TABLE categorias
                 ADD COLUMN parent_id INT UNSIGNED NULL AFTER descricao'
            );
        }

        if (!$indexExists('categorias', 'idx_categorias_parent_id')) {
            $pdo->exec(
                'CREATE INDEX idx_categorias_parent_id
                 ON categorias (parent_id)'
            );
        }

        if (!$tableExists('post_categorias')) {
            $pdo->exec(
                "CREATE TABLE post_categorias (
                    post_id INT UNSIGNED NOT NULL,
                    categoria_id INT UNSIGNED NOT NULL,
                    principal TINYINT(1) NOT NULL DEFAULT 0,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (post_id,categoria_id),
                    KEY idx_post_categorias_categoria (categoria_id,post_id),
                    KEY idx_post_categorias_principal (post_id,principal),
                    CONSTRAINT fk_post_categorias_post
                        FOREIGN KEY (post_id) REFERENCES posts(id)
                        ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT fk_post_categorias_categoria
                        FOREIGN KEY (categoria_id) REFERENCES categorias(id)
                        ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        if ($columnExists('posts', 'categoria_id')) {
            $pdo->exec(
                "INSERT IGNORE INTO post_categorias
                    (post_id,categoria_id,principal)
                 SELECT p.id,p.categoria_id,1
                 FROM posts p
                 INNER JOIN categorias c ON c.id=p.categoria_id
                 WHERE p.categoria_id IS NOT NULL
                   AND p.categoria_id>0"
            );
        }

        $ensured = true;
    }

PHP;

    if (!str_contains($source, $anchor)) {
        throw new RuntimeException(
            'Não foi possível localizar a classe CategoryService.'
        );
    }

    $source = str_replace($anchor, $method, $source);

    // O sincronizador não deve depender de uma instalação externa correta.
    $syncAnchor = <<<'PHP'
    public static function syncPostCategories(PDO $pdo, int $postId, array $categoryIds, ?int $primaryId = null): ?int
    {
PHP;
    $syncReplacement = $syncAnchor . "        self::ensureSchema(\$pdo);\n";

    if (str_contains($source, $syncAnchor)
        && !str_contains(
            $source,
            "public static function syncPostCategories(PDO \$pdo, int \$postId, array \$categoryIds, ?int \$primaryId = null): ?int\n    {\n        self::ensureSchema(\$pdo);"
        )) {
        $source = str_replace($syncAnchor, $syncReplacement, $source);
    }

    writeChanged($root, $backupDir, $path, $source, 'app/Services/CategoryService.php');
}

function patchBootstrap(
    string $root,
    string $backupDir,
    string $path
): void {
    if (!is_file($path)) {
        throw new RuntimeException('bootstrap.php não encontrado.');
    }

    $source = (string)file_get_contents($path);

    if (str_contains($source, "/app/Services/CategoryService.php")) {
        out('[OK] bootstrap.php já carrega CategoryService.');
        return;
    }

    $anchors = [
        "require_once __DIR__ . '/app/Services/MediaService.php';",
        "require_once __DIR__ . '/app/Services/SearchService.php';",
    ];

    foreach ($anchors as $anchor) {
        $position = strpos($source, $anchor);
        if ($position === false) {
            continue;
        }

        $insert = $anchor
            . "\nrequire_once __DIR__ . '/app/Services/CategoryService.php';";

        $source = substr_replace(
            $source,
            $insert,
            $position,
            strlen($anchor)
        );

        writeChanged($root, $backupDir, $path, $source, 'bootstrap.php');
        return;
    }

    throw new RuntimeException(
        'Não foi possível carregar CategoryService no bootstrap.php.'
    );
}

function patchEnsureCall(
    string $root,
    string $backupDir,
    string $path,
    string $label
): void {
    if (!is_file($path)) {
        out('[AVISO] ' . $label . ' não encontrado; ignorado.');
        return;
    }

    $source = (string)file_get_contents($path);

    if (str_contains($source, 'CategoryService::ensureSchema($pdo);')) {
        out('[OK] ' . $label . ' já valida post_categorias.');
        return;
    }

    $patterns = [
        '$pdo = Database::connection();',
        '$pdo=Database::connection();',
    ];

    foreach ($patterns as $anchor) {
        $position = strpos($source, $anchor);
        if ($position === false) {
            continue;
        }

        $insert = $anchor
            . "\nCategoryService::ensureSchema(\$pdo);";

        $source = substr_replace(
            $source,
            $insert,
            $position,
            strlen($anchor)
        );

        writeChanged($root, $backupDir, $path, $source, $label);
        return;
    }

    throw new RuntimeException(
        'Não foi possível localizar Database::connection() em ' . $label . '.'
    );
}

function patchNewsEngagement(
    string $root,
    string $backupDir,
    string $path
): void {
    if (!is_file($path)) {
        out('[AVISO] NewsEngagementService.php não encontrado; ignorado.');
        return;
    }

    $source = (string)file_get_contents($path);

    if (str_contains($source, '// v0.42.0-r2 - garante múltiplas categorias')) {
        out('[OK] NewsEngagementService já valida post_categorias.');
        return;
    }

    $anchor = <<<'PHP'
    public static function related(PDO $pdo, array $post, int $limit = 4): array
    {
PHP;

    $replacement = $anchor . <<<'PHP'
        // v0.42.0-r2 - garante múltiplas categorias antes das consultas.
        if (class_exists('CategoryService')) {
            CategoryService::ensureSchema($pdo);
        }

PHP;

    if (!str_contains($source, $anchor)) {
        throw new RuntimeException(
            'Não foi possível atualizar NewsEngagementService.php.'
        );
    }

    $source = str_replace($anchor, $replacement, $source);

    writeChanged(
        $root,
        $backupDir,
        $path,
        $source,
        'app/Services/NewsEngagementService.php'
    );
}

function patchInstaller(
    string $root,
    string $backupDir,
    string $path
): void {
    if (!is_file($path)) {
        out('[AVISO] Instalador web não encontrado; correção do Portal seguirá normalmente.');
        return;
    }

    $source = (string)file_get_contents($path);
    $original = $source;

    // O instalador antigo ignorava migrações sem vX.Y.Z no nome.
    if (!str_contains($source, "'2026_08_22_posts_multiplas_categorias.sql' => '0.26.1'")) {
        $old = <<<'PHP'
            if (!preg_match('/v(\d+\.\d+\.\d+)/i', $file, $match)) {
                continue;
            }

            $version = $match[1];
PHP;

        $new = <<<'PHP'
            $specialVersions = [
                '2026_08_19_categoria_ascendente_posts.sql' => '0.22.1',
                '2026_08_22_posts_multiplas_categorias.sql' => '0.26.1',
            ];

            if (isset($specialVersions[$file])) {
                $version = $specialVersions[$file];
            } elseif (preg_match('/v(\d+\.\d+\.\d+)/i', $file, $match)) {
                $version = $match[1];
            } else {
                continue;
            }
PHP;

        if (str_contains($source, $old)) {
            $source = str_replace($old, $new, $source);
        } else {
            out('[AVISO] Não encontrei o bloco migrationFiles() antigo; mantendo o restante do instalador.');
        }
    }

    // Defesa final: mesmo que uma migração seja esquecida, a instalação nova
    // deve terminar com a estrutura de categorias atual.
    if (!str_contains($source, '// v0.42.0-r2 - schema atual de categorias de posts')) {
        $anchor = <<<'PHP'
        // v0.40.0 - visualizações agregadas.
PHP;

        $block = <<<'PHP'
        // v0.42.0-r2 - schema atual de categorias de posts.
        if ($this->tableExists($pdo, 'categorias')
            && !$this->columnExists($pdo, 'categorias', 'parent_id')) {
            $pdo->exec(
                'ALTER TABLE categorias
                 ADD COLUMN parent_id INT UNSIGNED NULL AFTER descricao'
            );
        }

        if ($this->tableExists($pdo, 'posts')
            && $this->tableExists($pdo, 'categorias')) {
            $pdo->exec(
                "CREATE TABLE IF NOT EXISTS post_categorias (
                    post_id INT UNSIGNED NOT NULL,
                    categoria_id INT UNSIGNED NOT NULL,
                    principal TINYINT(1) NOT NULL DEFAULT 0,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (post_id,categoria_id),
                    KEY idx_post_categorias_categoria (categoria_id,post_id),
                    KEY idx_post_categorias_principal (post_id,principal),
                    CONSTRAINT fk_post_categorias_post
                        FOREIGN KEY (post_id) REFERENCES posts(id)
                        ON DELETE CASCADE ON UPDATE CASCADE,
                    CONSTRAINT fk_post_categorias_categoria
                        FOREIGN KEY (categoria_id) REFERENCES categorias(id)
                        ON DELETE CASCADE ON UPDATE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );

            if ($this->columnExists($pdo, 'posts', 'categoria_id')) {
                $pdo->exec(
                    "INSERT IGNORE INTO post_categorias
                        (post_id,categoria_id,principal)
                     SELECT p.id,p.categoria_id,1
                     FROM posts p
                     INNER JOIN categorias c ON c.id=p.categoria_id
                     WHERE p.categoria_id IS NOT NULL
                       AND p.categoria_id>0"
                );
            }
        }

PHP;

        if (str_contains($source, $anchor)) {
            $source = str_replace($anchor, $block . $anchor, $source);
        } else {
            out('[AVISO] Não encontrei ensureCurrentSchema() para reforço final do instalador.');
        }
    }

    if ($source !== $original) {
        writeChanged($root, $backupDir, $path, $source, 'instalar/lib/Installer.php');
    } else {
        out('[OK] Instalador web já estava compatível.');
    }
}

$root = __DIR__;
$config = $root . '/config/config.php';
$dbFile = $root . '/mod/db/Database.php';
$backupDir = $root . '/storage/update-backups/' . FIX_NAME . '-' . date('Ymd-His');

out('Portal IECLB Parobé - correção global ' . FIX_NAME);
out('post_categorias + categorias hierárquicas + instalador');
out(str_repeat('-', 78));

if (!is_file($config)) {
    fail('config/config.php não encontrado.');
}
if (!is_file($dbFile)) {
    fail('mod/db/Database.php não encontrado.');
}

require_once $config;
require_once $dbFile;

try {
    $pdo = Database::connection();
    out('[OK] Conexão com o banco realizada.');

    // 1. Corrige a estrutura imediatamente.
    ensureDatabaseSchema($pdo);

    // 2. Centraliza a autocorreção no serviço de categorias.
    patchCategoryService(
        $root,
        $backupDir,
        $root . '/app/Services/CategoryService.php'
    );

    // 3. Torna CategoryService disponível no Portal inteiro.
    patchBootstrap(
        $root,
        $backupDir,
        $root . '/bootstrap.php'
    );

    // 4. Todos os pontos atuais que usam post_categorias.
    $files = [
        'noticia.php',
        'categoria.php',
        'feed.php',
        'sitemap.php',
        'admin/noticias/index.php',
        'admin/noticias/form.php',
        'admin/noticias/duplicar.php',
        'admin/categorias/index.php',
    ];

    foreach ($files as $relative) {
        patchEnsureCall(
            $root,
            $backupDir,
            $root . '/' . $relative,
            $relative
        );
    }

    patchNewsEngagement(
        $root,
        $backupDir,
        $root . '/app/Services/NewsEngagementService.php'
    );

    // 5. Corrige a origem do problema em instalações novas.
    patchInstaller(
        $root,
        $backupDir,
        $root . '/instalar/lib/Installer.php'
    );

    // Validação final do banco.
    if (!tableExists($pdo, 'post_categorias')) {
        throw new RuntimeException('Validação final: post_categorias não existe.');
    }
    if (!columnExists($pdo, 'categorias', 'parent_id')) {
        throw new RuntimeException('Validação final: categorias.parent_id não existe.');
    }

    $totalLinks = (int)$pdo->query(
        'SELECT COUNT(*) FROM post_categorias'
    )->fetchColumn();

    out('[OK] Validação final: post_categorias disponível.');
    out('[OK] Validação final: categorias.parent_id disponível.');
    out('[OK] Vínculos notícia/categoria encontrados: ' . $totalLinks . '.');

    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }

    out(str_repeat('-', 78));
    out('Correção global concluída.');
    out('A versão do Portal permanece ' . (defined('APP_VERSION') ? APP_VERSION : '0.42.0') . '.');
    if (is_dir($backupDir)) {
        out('Backups: ' . str_replace('\\', '/', $backupDir));
    }
} catch (Throwable $e) {
    fail($e->getMessage());
}
