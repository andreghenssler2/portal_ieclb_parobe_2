<?php

declare(strict_types=1);

final class PortalInstaller
{
    public const TARGET_VERSION = '0.40.0';
    public const BASE_SCHEMA_VERSION = '0.2.0';
    public const INSTALLER_MARKER_TABLE = 'portal_instalador_migrations';
    public const INSTALLER_STATEMENTS_TABLE = 'portal_instalador_statements';

    private string $portalRoot;
    private string $installerRoot;

    public function __construct(string $portalRoot, string $installerRoot)
    {
        $this->portalRoot = rtrim($portalRoot, '/\\');
        $this->installerRoot = rtrim($installerRoot, '/\\');
    }

    public function portalRoot(): string
    {
        return $this->portalRoot;
    }

    public function lockFile(): string
    {
        return $this->portalRoot . '/storage/installed.lock';
    }

    public function isInstalled(): bool
    {
        return is_file($this->lockFile());
    }

    /** @return array<int,array{label:string,ok:bool,detail:string,required:bool}> */
    public function requirements(): array
    {
        $requirements = [
            [
                'label' => 'PHP 8.2 ou superior',
                'ok' => version_compare(PHP_VERSION, '8.2.0', '>='),
                'detail' => 'Versão encontrada: ' . PHP_VERSION,
                'required' => true,
            ],
        ];

        foreach ([
            'pdo' => 'PDO',
            'pdo_mysql' => 'PDO MySQL',
            'mbstring' => 'Mbstring',
            'fileinfo' => 'Fileinfo',
            'openssl' => 'OpenSSL',
            'json' => 'JSON',
            'session' => 'Session',
        ] as $extension => $label) {
            $requirements[] = [
                'label' => 'Extensão ' . $label,
                'ok' => extension_loaded($extension),
                'detail' => extension_loaded($extension) ? 'Disponível' : 'Não encontrada',
                'required' => true,
            ];
        }

        foreach ([
            'config' => $this->portalRoot . '/config',
            'storage' => $this->portalRoot . '/storage',
            'uploads' => $this->portalRoot . '/uploads',
        ] as $label => $directory) {
            if (!is_dir($directory)) {
                @mkdir($directory, 0755, true);
            }

            $requirements[] = [
                'label' => 'Pasta gravável: ' . $label . '/',
                'ok' => is_dir($directory) && is_writable($directory),
                'detail' => $directory,
                'required' => true,
            ];
        }

        $requirements[] = [
            'label' => 'Schema-base do instalador',
            'ok' => is_file($this->installerRoot . '/schema-base.sql'),
            'detail' => 'instalar/schema-base.sql',
            'required' => true,
        ];

        foreach ([
            'bootstrap.php' => $this->portalRoot . '/bootstrap.php',
            'admin/login.php' => $this->portalRoot . '/admin/login.php',
            'v0.39 EventCalendarService' => $this->portalRoot . '/app/Services/EventCalendarService.php',
            'v0.40 NewsAnalyticsService' => $this->portalRoot . '/app/Services/NewsAnalyticsService.php',
        ] as $label => $sourceFile) {
            $requirements[] = [
                'label' => 'Arquivo do Portal: ' . $label,
                'ok' => is_file($sourceFile),
                'detail' => str_replace('\\', '/', $sourceFile),
                'required' => true,
            ];
        }

        $migrations = $this->migrationFiles();
        $requirements[] = [
            'label' => 'Migrações do Portal',
            'ok' => count($migrations) > 0,
            'detail' => count($migrations) . ' arquivo(s) aplicável(is) encontrado(s) após a base v' . self::BASE_SCHEMA_VERSION,
            'required' => true,
        ];

        $composerAutoload = $this->portalRoot . '/lib/autoload.php';
        $requirements[] = [
            'label' => 'Dependências Composer em /lib',
            'ok' => is_file($composerAutoload),
            'detail' => is_file($composerAutoload)
                ? 'lib/autoload.php encontrado'
                : 'Aviso: execute "composer install --no-dev --optimize-autoloader" na raiz para habilitar bibliotecas como PHPMailer.',
            'required' => false,
        ];

        return $requirements;
    }

    public function requirementsOk(): bool
    {
        foreach ($this->requirements() as $requirement) {
            if ($requirement['required'] && !$requirement['ok']) {
                return false;
            }
        }

        return true;
    }

    public function detectBaseUrl(array $server): string
    {
        $https = strtolower((string)($server['HTTPS'] ?? ''));
        $forwarded = strtolower(trim(explode(',', (string)($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
        $scheme = ($https !== '' && $https !== 'off')
            || $forwarded === 'https'
            || (string)($server['SERVER_PORT'] ?? '') === '443'
            ? 'https'
            : 'http';

        $host = trim((string)($server['HTTP_HOST'] ?? 'localhost'));
        $script = str_replace('\\', '/', (string)($server['SCRIPT_NAME'] ?? '/instalar/index.php'));
        $installerPath = rtrim(str_replace('\\', '/', dirname($script)), '/');
        $portalPath = rtrim(str_replace('\\', '/', dirname($installerPath)), '/');

        if ($portalPath === '.' || $portalPath === '/') {
            $portalPath = '';
        }

        return rtrim($scheme . '://' . $host . $portalPath, '/');
    }

    public function validateDatabaseInput(array $input): array
    {
        $host = trim((string)($input['host'] ?? ''));
        $port = trim((string)($input['port'] ?? '3306'));
        $name = trim((string)($input['name'] ?? ''));
        $user = trim((string)($input['user'] ?? ''));
        $pass = (string)($input['pass'] ?? '');
        $create = !empty($input['create']);

        if ($host === '') {
            throw new RuntimeException('Informe o servidor do banco de dados.');
        }
        if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) {
            throw new RuntimeException('A porta do banco de dados é inválida.');
        }
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new RuntimeException('O nome do banco pode conter somente letras, números e "_".');
        }
        if ($user === '') {
            throw new RuntimeException('Informe o usuário do banco de dados.');
        }

        return [
            'host' => $host,
            'port' => $port,
            'name' => $name,
            'user' => $user,
            'pass' => $pass,
            'create' => $create,
        ];
    }

    public function connectDatabase(array $db, bool $allowCreate = false): PDO
    {
        $db = $this->validateDatabaseInput($db);
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        if ($allowCreate && $db['create']) {
            $serverDsn = sprintf(
                'mysql:host=%s;port=%s;charset=utf8mb4',
                $db['host'],
                $db['port']
            );
            $server = new PDO($serverDsn, $db['user'], $db['pass'], $options);
            $quotedName = '`' . str_replace('`', '``', $db['name']) . '`';
            $server->exec(
                'CREATE DATABASE IF NOT EXISTS ' . $quotedName
                . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $db['host'],
            $db['port'],
            $db['name']
        );

        return new PDO($dsn, $db['user'], $db['pass'], $options);
    }

    /** @return array{tables:int,resumable:bool,names:array<int,string>} */
    public function databaseState(PDO $pdo): array
    {
        $stmt = $pdo->query(
            'SELECT table_name
             FROM information_schema.tables
             WHERE table_schema=DATABASE()
             ORDER BY table_name'
        );
        $tables = array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));

        return [
            'tables' => count($tables),
            'resumable' => in_array(self::INSTALLER_MARKER_TABLE, $tables, true),
            'names' => $tables,
        ];
    }

    public function ensureInstallControl(PDO $pdo): void
    {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS " . self::INSTALLER_MARKER_TABLE . " (
                arquivo VARCHAR(255) NOT NULL PRIMARY KEY,
                versao VARCHAR(40) NULL,
                concluida_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS " . self::INSTALLER_STATEMENTS_TABLE . " (
                migration_file VARCHAR(255) NOT NULL,
                statement_hash CHAR(64) NOT NULL,
                executada_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (migration_file,statement_hash)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    public function installDatabase(PDO $pdo, callable $progress): array
    {
        $state = $this->databaseState($pdo);

        if ($state['tables'] > 0 && !$state['resumable']) {
            throw new RuntimeException(
                'O banco selecionado já possui tabelas e não pertence a uma instalação iniciada por este assistente. '
                . 'Por segurança, use um banco vazio.'
            );
        }

        $this->ensureInstallControl($pdo);
        $progress('Controle de instalação preparado.');

        $baseFile = $this->installerRoot . '/schema-base.sql';
        $this->runSqlFile($pdo, $baseFile, '__schema_base_v0.2.0__', $progress);
        $this->markMigration($pdo, '__schema_base_v0.2.0__', self::BASE_SCHEMA_VERSION);
        $progress('Estrutura-base instalada.');

        $applied = [];
        foreach ($this->migrationFiles() as $migration) {
            if ($this->migrationApplied($pdo, $migration['file'])) {
                $applied[] = $migration['file'] . ' (já aplicada)';
                continue;
            }

            $this->runSqlFile(
                $pdo,
                $migration['path'],
                $migration['file'],
                $progress
            );
            $this->markMigration($pdo, $migration['file'], $migration['version']);
            $applied[] = $migration['file'];
            $progress('Migração concluída: ' . $migration['file']);
        }

        $this->ensureCurrentSchema($pdo);
        $progress('Compatibilidade final da v' . self::TARGET_VERSION . ' verificada.');

        return $applied;
    }

    public function configureSite(PDO $pdo, array $site): void
    {
        if (!$this->tableExists($pdo, 'configuracoes')) {
            throw new RuntimeException('Tabela configuracoes não encontrada após as migrações.');
        }

        $settings = [
            'site_nome' => [(string)$site['name'], 'texto'],
            'site_descricao' => [(string)$site['description'], 'texto'],
            'site_email' => [(string)$site['email'], 'email'],
            'date_format' => ['d/m/Y', 'texto'],
            'time_format' => ['H:i', 'texto'],
        ];

        $stmt = $pdo->prepare(
            'INSERT INTO configuracoes (chave,valor,tipo)
             VALUES (?,?,?)
             ON DUPLICATE KEY UPDATE valor=VALUES(valor),tipo=VALUES(tipo)'
        );

        foreach ($settings as $key => [$value, $type]) {
            $stmt->execute([$key, $value, $type]);
        }
    }

    public function createAdministrator(PDO $pdo, array $admin): int
    {
        $name = trim((string)($admin['name'] ?? ''));
        $email = strtolower(trim((string)($admin['email'] ?? '')));
        $password = (string)($admin['password'] ?? '');

        if ($name === '') {
            throw new RuntimeException('Informe o nome do administrador.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Informe um e-mail válido para o administrador.');
        }
        if (strlen($password) < 10) {
            throw new RuntimeException('A senha do administrador deve possuir pelo menos 10 caracteres.');
        }

        $pdo->prepare(
            "INSERT INTO perfis (nome,slug)
             VALUES ('Administrador','administrador')
             ON DUPLICATE KEY UPDATE nome=VALUES(nome)"
        )->execute();

        $stmt = $pdo->prepare("SELECT id FROM perfis WHERE slug='administrador' LIMIT 1");
        $stmt->execute();
        $profileId = (int)$stmt->fetchColumn();

        if ($profileId <= 0) {
            throw new RuntimeException('Não foi possível localizar o perfil Administrador.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('Não foi possível gerar a senha do administrador.');
        }

        $stmt = $pdo->prepare('SELECT id FROM usuarios WHERE email=? LIMIT 1');
        $stmt->execute([$email]);
        $existing = (int)($stmt->fetchColumn() ?: 0);

        if ($existing > 0) {
            $stmt = $pdo->prepare(
                'UPDATE usuarios
                 SET perfil_id=?,nome=?,senha=?,ativo=1
                 WHERE id=?'
            );
            $stmt->execute([$profileId, $name, $hash, $existing]);
            $userId = $existing;
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO usuarios (perfil_id,nome,email,senha,ativo)
                 VALUES (?,?,?,?,1)'
            );
            $stmt->execute([$profileId, $name, $email, $hash]);
            $userId = (int)$pdo->lastInsertId();
        }

        if ($this->tableExists($pdo, 'permissoes') && $this->tableExists($pdo, 'perfil_permissoes')) {
            $pdo->prepare(
                'INSERT IGNORE INTO perfil_permissoes (perfil_id,permissao_id)
                 SELECT ?,id FROM permissoes'
            )->execute([$profileId]);
        }

        return $userId;
    }

    public function writeConfig(array $db, array $site): string
    {
        $db = $this->validateDatabaseInput($db);
        $baseUrl = rtrim(trim((string)$site['base_url']), '/');

        if (!preg_match('#^https?://#i', $baseUrl)) {
            throw new RuntimeException('A URL do Portal deve começar com http:// ou https://.');
        }

        $timezone = trim((string)($site['timezone'] ?? 'America/Sao_Paulo'));
        try {
            new DateTimeZone($timezone);
        } catch (Throwable $e) {
            throw new RuntimeException('Fuso horário inválido.');
        }

        $config = "<?php\n\ndeclare(strict_types=1);\n\n";
        $config .= "define('APP_NAME', 'Portal IECLB Parobé');\n";
        $config .= "define('APP_VERSION', " . var_export(self::TARGET_VERSION, true) . ");\n";
        $config .= "define('APP_ENV', 'production');\n";
        $config .= "define('APP_DEBUG', false);\n";
        $config .= "define('BASE_URL', " . var_export($baseUrl, true) . ");\n";
        $config .= "define('TIMEZONE', " . var_export($timezone, true) . ");\n";
        $config .= "define('UPLOAD_MAX_SIZE', 300 * 1024 * 1024);\n\n";
        $config .= "define('DB_HOST', " . var_export($db['host'], true) . ");\n";
        $config .= "define('DB_PORT', " . var_export($db['port'], true) . ");\n";
        $config .= "define('DB_NAME', " . var_export($db['name'], true) . ");\n";
        $config .= "define('DB_USER', " . var_export($db['user'], true) . ");\n";
        $config .= "define('DB_PASS', " . var_export($db['pass'], true) . ");\n";
        $config .= "define('DB_CHARSET', 'utf8mb4');\n\n";
        $config .= "date_default_timezone_set(TIMEZONE);\n";

        $configDir = $this->portalRoot . '/config';
        if (!is_dir($configDir) && !mkdir($configDir, 0755, true) && !is_dir($configDir)) {
            throw new RuntimeException('Não foi possível criar config/.');
        }

        $target = $configDir . '/config.php';

        if (is_file($target)) {
            $backupDir = $this->portalRoot . '/storage/install-backups';
            if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true) && !is_dir($backupDir)) {
                throw new RuntimeException('Não foi possível criar storage/install-backups/.');
            }

            $backup = $backupDir . '/config-' . date('Ymd-His') . '.php';
            if (!copy($target, $backup)) {
                throw new RuntimeException('Não foi possível criar backup do config/config.php atual.');
            }
        }

        if (file_put_contents($target, $config, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível gravar config/config.php.');
        }

        return $target;
    }

    public function writeLock(array $site): void
    {
        $storage = $this->portalRoot . '/storage';
        if (!is_dir($storage) && !mkdir($storage, 0755, true) && !is_dir($storage)) {
            throw new RuntimeException('Não foi possível criar storage/.');
        }

        $data = [
            'version' => self::TARGET_VERSION,
            'installed_at' => date(DATE_ATOM),
            'base_url' => rtrim((string)$site['base_url'], '/'),
            'site_name' => (string)$site['name'],
        ];

        if (file_put_contents(
            $this->lockFile(),
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        ) === false) {
            throw new RuntimeException('Não foi possível criar o bloqueio da instalação.');
        }
    }

    /** @return array<int,array{file:string,path:string,version:string}> */
    public function migrationFiles(): array
    {
        $directory = $this->portalRoot . '/migrations';
        if (!is_dir($directory)) {
            return [];
        }

        $migrations = [];

        foreach (glob($directory . '/*.sql') ?: [] as $path) {
            $file = basename($path);

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

            if (version_compare($version, self::BASE_SCHEMA_VERSION, '<=')) {
                continue;
            }

            // A otimização de imagens foi removida do Portal na v0.38.1.
            if ($version === '0.32.0') {
                continue;
            }

            $migrations[] = [
                'file' => $file,
                'path' => $path,
                'version' => $version,
            ];
        }

        usort($migrations, static function (array $a, array $b): int {
            $versionCompare = version_compare($a['version'], $b['version']);
            if ($versionCompare !== 0) {
                return $versionCompare;
            }
            return strcmp($a['file'], $b['file']);
        });

        return $migrations;
    }

    /** @return array<int,string> */
    public function splitSql(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $length = strlen($sql);
        $quote = null;
        $lineComment = false;
        $blockComment = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($lineComment) {
                if ($char === "\n") {
                    $lineComment = false;
                    $buffer .= $char;
                }
                continue;
            }

            if ($blockComment) {
                if ($char === '*' && $next === '/') {
                    $blockComment = false;
                    $i++;
                }
                continue;
            }

            if ($quote === null) {
                if (($char === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($sql[$i + 2])))
                    || $char === '#') {
                    $lineComment = true;
                    if ($char === '-') $i++;
                    continue;
                }

                if ($char === '/' && $next === '*') {
                    $blockComment = true;
                    $i++;
                    continue;
                }

                if ($char === "'" || $char === '"' || $char === '`') {
                    $quote = $char;
                    $buffer .= $char;
                    continue;
                }

                if ($char === ';') {
                    $statement = trim($buffer);
                    if ($statement !== '') {
                        $statements[] = $statement;
                    }
                    $buffer = '';
                    continue;
                }

                $buffer .= $char;
                continue;
            }

            $buffer .= $char;

            if ($char === '\\' && $quote !== '`' && $i + 1 < $length) {
                $buffer .= $sql[++$i];
                continue;
            }

            if ($char === $quote) {
                if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                    $buffer .= $sql[++$i];
                    continue;
                }

                $quote = null;
            }
        }

        $statement = trim($buffer);
        if ($statement !== '') {
            $statements[] = $statement;
        }

        return $statements;
    }

    private function runSqlFile(PDO $pdo, string $path, string $migrationFile, callable $progress): void
    {
        if (!is_file($path)) {
            throw new RuntimeException('Arquivo SQL não encontrado: ' . basename($path));
        }

        $sql = (string)file_get_contents($path);
        $statements = $this->splitSql($sql);

        foreach ($statements as $index => $statement) {
            $hash = hash('sha256', trim($statement));

            if ($this->statementApplied($pdo, $migrationFile, $hash)) {
                continue;
            }

            try {
                $this->executeCompatStatement($pdo, $statement);
            } catch (Throwable $e) {
                throw new RuntimeException(
                    'Falha em ' . $migrationFile
                    . ', comando ' . ($index + 1)
                    . ': ' . $e->getMessage()
                );
            }

            $stmt = $pdo->prepare(
                'INSERT IGNORE INTO ' . self::INSTALLER_STATEMENTS_TABLE
                . ' (migration_file,statement_hash) VALUES (?,?)'
            );
            $stmt->execute([$migrationFile, $hash]);
        }
    }

    /**
     * Executa DDL de migrações antigas de modo compatível com versões de
     * MySQL/MariaDB que não aceitam ADD COLUMN/CREATE INDEX IF NOT EXISTS.
     */
    private function executeCompatStatement(PDO $pdo, string $statement): void
    {
        $statement = trim($statement);

        // MySQL antigos não aceitam CREATE INDEX IF NOT EXISTS.
        if (preg_match(
            '/^CREATE\s+(UNIQUE\s+)?INDEX\s+IF\s+NOT\s+EXISTS\s+(`?[A-Za-z0-9_]+`?)\s+ON\s+(`?[A-Za-z0-9_]+`?)\s+(.+)$/is',
            $statement,
            $match
        )) {
            $unique = trim((string)$match[1]) !== '';
            $index = trim((string)$match[2], '`');
            $table = trim((string)$match[3], '`');
            $definition = trim((string)$match[4]);

            if (!$this->indexExists($pdo, $table, $index)) {
                $pdo->exec(
                    'CREATE ' . ($unique ? 'UNIQUE ' : '')
                    . 'INDEX `' . $index . '` ON `' . $table . '` ' . $definition
                );
            }
            return;
        }

        // Compatibilidade para ALTER TABLE com cláusulas IF NOT EXISTS.
        if (preg_match(
            '/^ALTER\s+TABLE\s+(`?[A-Za-z0-9_]+`?)\s+(.+)$/is',
            $statement,
            $match
        ) && stripos((string)$match[2], 'IF NOT EXISTS') !== false) {
            $table = trim((string)$match[1], '`');
            $clauses = $this->splitTopLevelComma((string)$match[2]);

            foreach ($clauses as $clause) {
                $clause = trim($clause);
                if ($clause === '') {
                    continue;
                }

                if (preg_match(
                    '/^ADD\s+(?:COLUMN\s+)?IF\s+NOT\s+EXISTS\s+(`?[A-Za-z0-9_]+`?)\s+(.+)$/is',
                    $clause,
                    $columnMatch
                )) {
                    $column = trim((string)$columnMatch[1], '`');
                    $definition = trim((string)$columnMatch[2]);

                    if (!$this->columnExists($pdo, $table, $column)) {
                        $pdo->exec(
                            'ALTER TABLE `' . $table . '` ADD COLUMN `'
                            . $column . '` ' . $definition
                        );
                    }
                    continue;
                }

                if (preg_match(
                    '/^ADD\s+(UNIQUE\s+)?(?:INDEX|KEY)\s+IF\s+NOT\s+EXISTS\s+(`?[A-Za-z0-9_]+`?)\s+(.+)$/is',
                    $clause,
                    $indexMatch
                )) {
                    $unique = trim((string)$indexMatch[1]) !== '';
                    $index = trim((string)$indexMatch[2], '`');
                    $definition = trim((string)$indexMatch[3]);

                    if (!$this->indexExists($pdo, $table, $index)) {
                        $pdo->exec(
                            'ALTER TABLE `' . $table . '` ADD '
                            . ($unique ? 'UNIQUE ' : '')
                            . 'INDEX `' . $index . '` ' . $definition
                        );
                    }
                    continue;
                }

                // Cláusula sem sintaxe especial: executa normalmente.
                $pdo->exec('ALTER TABLE `' . $table . '` ' . $clause);
            }
            return;
        }

        $pdo->exec($statement);
    }

    /**
     * Separa cláusulas ALTER TABLE por vírgula sem quebrar ENUM(), índices
     * compostos ou textos entre aspas.
     *
     * @return array<int,string>
     */
    private function splitTopLevelComma(string $sql): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        $quote = null;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($quote !== null) {
                $buffer .= $char;

                if ($char === '\\' && $quote !== '`' && $i + 1 < $length) {
                    $buffer .= $sql[++$i];
                    continue;
                }

                if ($char === $quote) {
                    if ($i + 1 < $length && $sql[$i + 1] === $quote) {
                        $buffer .= $sql[++$i];
                        continue;
                    }
                    $quote = null;
                }
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }

            if ($char === '(') {
                $depth++;
                $buffer .= $char;
                continue;
            }

            if ($char === ')') {
                $depth = max(0, $depth - 1);
                $buffer .= $char;
                continue;
            }

            if ($char === ',' && $depth === 0) {
                $part = trim($buffer);
                if ($part !== '') {
                    $parts[] = $part;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $part = trim($buffer);
        if ($part !== '') {
            $parts[] = $part;
        }

        return $parts;
    }

    private function statementApplied(PDO $pdo, string $file, string $hash): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM ' . self::INSTALLER_STATEMENTS_TABLE
            . ' WHERE migration_file=? AND statement_hash=? LIMIT 1'
        );
        $stmt->execute([$file, $hash]);

        return (bool)$stmt->fetchColumn();
    }

    private function migrationApplied(PDO $pdo, string $file): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM ' . self::INSTALLER_MARKER_TABLE . ' WHERE arquivo=? LIMIT 1'
        );
        $stmt->execute([$file]);

        return (bool)$stmt->fetchColumn();
    }

    private function markMigration(PDO $pdo, string $file, string $version): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO ' . self::INSTALLER_MARKER_TABLE . ' (arquivo,versao)
             VALUES (?,?)
             ON DUPLICATE KEY UPDATE versao=VALUES(versao),concluida_em=CURRENT_TIMESTAMP'
        );
        $stmt->execute([$file, $version]);
    }

    private function ensureCurrentSchema(PDO $pdo): void
    {
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
        // v0.40.0 - visualizações agregadas.
        if ($this->tableExists($pdo, 'posts') && !$this->columnExists($pdo, 'posts', 'visualizacoes')) {
            $pdo->exec(
                'ALTER TABLE posts
                 ADD COLUMN visualizacoes BIGINT UNSIGNED NOT NULL DEFAULT 0'
            );
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS post_visualizacoes_diarias (
                post_id BIGINT UNSIGNED NOT NULL,
                data DATE NOT NULL,
                visualizacoes BIGINT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (post_id,data),
                KEY idx_post_views_data (data),
                KEY idx_post_views_ranking (data,visualizacoes,post_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // O recurso de otimização foi removido na v0.38.1.
        $pdo->exec('DROP TABLE IF EXISTS midia_variantes');

        if ($this->tableExists($pdo, 'configuracoes')) {
            $stmt = $pdo->prepare(
                "DELETE FROM configuracoes
                 WHERE chave IN (
                    'media_optimize_enabled',
                    'media_generate_webp',
                    'media_variant_widths',
                    'media_image_quality'
                 )"
            );
            $stmt->execute();
        }
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema=DATABASE() AND table_name=?'
        );
        $stmt->execute([$table]);

        return (int)$stmt->fetchColumn() > 0;
    }

    private function columnExists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema=DATABASE() AND table_name=? AND column_name=?'
        );
        $stmt->execute([$table, $column]);

        return (int)$stmt->fetchColumn() > 0;
    }

    private function indexExists(PDO $pdo, string $table, string $index): bool
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema=DATABASE() AND table_name=? AND index_name=?'
        );
        $stmt->execute([$table, $index]);

        return (int)$stmt->fetchColumn() > 0;
    }
}
