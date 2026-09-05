<?php

declare(strict_types=1);

/**
 * Teste seguro de restaurabilidade v0.93.0.
 *
 * Cria backups reais e valida o que seria consumido por uma restauração,
 * sem restaurar o banco ativo nem sobrescrever arquivos do Portal.
 */
final class BackupRestoreTestService
{
    private BackupService $database;
    private FullBackupService $full;

    public function __construct(
        private PDO $pdo,
        private string $rootPath
    ) {
        $this->rootPath = rtrim($this->rootPath, DIRECTORY_SEPARATOR);

        $backupFile =
            $this->rootPath
            . DIRECTORY_SEPARATOR
            . 'app'
            . DIRECTORY_SEPARATOR
            . 'Services'
            . DIRECTORY_SEPARATOR
            . 'BackupService.php';

        $fullFile =
            $this->rootPath
            . DIRECTORY_SEPARATOR
            . 'app'
            . DIRECTORY_SEPARATOR
            . 'Services'
            . DIRECTORY_SEPARATOR
            . 'FullBackupService.php';

        if (
            !class_exists('BackupService')
            && is_file($backupFile)
        ) {
            require_once $backupFile;
        }

        if (
            !class_exists('FullBackupService')
            && is_file($fullFile)
        ) {
            require_once $fullFile;
        }

        if (
            !class_exists('BackupService')
            || !class_exists('FullBackupService')
        ) {
            throw new RuntimeException(
                'Serviços de backup do Portal não estão disponíveis.'
            );
        }

        $this->database =
            new BackupService(
                $this->pdo,
                $this->rootPath
            );

        $this->full =
            new FullBackupService(
                $this->pdo,
                $this->rootPath
            );
    }

    /**
     * @return array<string,mixed>
     */
    public function run(
        bool $testDatabase = true,
        bool $testFull = true,
        bool $includeUploads = false,
        bool $includeThemes = true
    ): array {
        $started = microtime(true);

        $result = [
            'started_at' => date('Y-m-d H:i:s'),
            'database' => null,
            'full' => null,
            'errors' => [],
            'warnings' => [],
            'duration_ms' => 0,
            'ok' => true,
        ];

        if ($testDatabase) {
            try {
                $result['database'] =
                    $this->testDatabaseBackup();
            } catch (Throwable $e) {
                $result['errors'][] =
                    'Banco: '
                    . $e->getMessage();
            }
        }

        if ($testFull) {
            if (!$this->full->isSupported()) {
                $result['warnings'][] =
                    'Backup completo não testado porque ZipArchive não está disponível.';
            } else {
                try {
                    $result['full'] =
                        $this->testFullBackup(
                            $includeUploads,
                            $includeThemes
                        );
                } catch (Throwable $e) {
                    $result['errors'][] =
                        'Backup completo: '
                        . $e->getMessage();
                }
            }
        }

        $result['duration_ms'] =
            (int)round(
                (microtime(true) - $started) * 1000
            );

        $result['ok'] =
            !$result['errors'];

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    public function quickCheck(): array
    {
        $issues = [];
        $warnings = [];

        $backupDir =
            $this->database->backupDirectory();

        if (!is_dir($backupDir)) {
            $issues[] =
                'storage/backups não existe.';
        } elseif (!is_writable($backupDir)) {
            $issues[] =
                'storage/backups não possui escrita.';
        }

        if (!method_exists($this->database, 'restoreDatabaseBackup')) {
            $issues[] =
                'BackupService::restoreDatabaseBackup não está disponível.';
        }

        if (!method_exists($this->full, 'restoreFullBackup')) {
            $issues[] =
                'FullBackupService::restoreFullBackup não está disponível.';
        }

        if (!$this->full->isSupported()) {
            $warnings[] =
                'ZipArchive indisponível: backup completo não pode ser criado/restaurado neste ambiente.';
        }

        return [
            'ok' => !$issues,
            'issues' => $issues,
            'warnings' => $warnings,
            'zip_supported' => $this->full->isSupported(),
            'backup_directory' => $backupDir,
            'database_backups' =>
                count(
                    $this->database->listDatabaseBackups()
                ),
            'full_backups' =>
                $this->full->isSupported()
                    ? count(
                        $this->full->listFullBackups()
                    )
                    : 0,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function testDatabaseBackup(): array
    {
        $tables =
            $this->databaseTables();

        if (!$tables) {
            throw new RuntimeException(
                'Nenhuma tabela foi localizada no banco atual.'
            );
        }

        $info =
            $this->database->createDatabaseBackup(
                'teste-restauracao'
            );

        $path =
            (string)($info['path'] ?? '');

        if (
            $path === ''
            || !is_file($path)
        ) {
            throw new RuntimeException(
                'O arquivo de backup do banco não foi criado.'
            );
        }

        $size =
            filesize($path);

        if (
            $size === false
            || $size <= 0
        ) {
            throw new RuntimeException(
                'O backup do banco foi criado vazio.'
            );
        }

        $expectedHash =
            strtolower(
                (string)($info['sha256'] ?? '')
            );

        $actualHash =
            strtolower(
                hash_file(
                    'sha256',
                    $path
                )
                ?: ''
            );

        if (
            $expectedHash === ''
            || !hash_equals(
                $expectedHash,
                $actualHash
            )
        ) {
            throw new RuntimeException(
                'SHA-256 do backup do banco não confere.'
            );
        }

        $inspection =
            $this->inspectSqlBackup(
                $path
            );

        $missingTables =
            array_values(
                array_diff(
                    $tables,
                    $inspection['tables']
                )
            );

        if ($missingTables) {
            throw new RuntimeException(
                'O dump não contém todas as tabelas atuais: '
                . implode(', ', $missingTables)
            );
        }

        if (
            !$inspection['foreign_key_disable']
            || !$inspection['foreign_key_enable']
        ) {
            throw new RuntimeException(
                'O dump não contém o ciclo esperado de FOREIGN_KEY_CHECKS.'
            );
        }

        if (
            $inspection['create_count']
            < count($tables)
        ) {
            throw new RuntimeException(
                'Quantidade de CREATE TABLE menor que a quantidade de tabelas atuais.'
            );
        }

        return [
            'ok' => true,
            'name' =>
                (string)($info['name'] ?? basename($path)),
            'path' => $path,
            'size' => (int)$size,
            'sha256' => $actualHash,
            'gzip' =>
                str_ends_with(
                    strtolower($path),
                    '.gz'
                ),
            'current_tables' => count($tables),
            'dump_tables' => count($inspection['tables']),
            'create_count' => $inspection['create_count'],
            'drop_count' => $inspection['drop_count'],
            'insert_count' => $inspection['insert_count'],
            'missing_tables' => [],
            'restoration_method_available' =>
                method_exists(
                    $this->database,
                    'restoreDatabaseBackup'
                ),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function testFullBackup(
        bool $includeUploads,
        bool $includeThemes
    ): array {
        $info =
            $this->full->createFullBackup(
                'teste-restauracao',
                $includeUploads,
                $includeThemes
            );

        $name =
            (string)($info['name'] ?? '');

        if ($name === '') {
            throw new RuntimeException(
                'O backup completo não retornou nome de arquivo.'
            );
        }

        $path =
            $this->full->fullBackupPath(
                $name
            );

        $size =
            filesize($path);

        if (
            $size === false
            || $size <= 0
        ) {
            throw new RuntimeException(
                'O backup completo foi criado vazio.'
            );
        }

        $manifest =
            $this->full->inspectFullBackup(
                $name
            );

        $verification =
            $this->verifyZipAgainstManifest(
                $path,
                $manifest
            );

        if (
            empty(
                $manifest['database']['file']
            )
        ) {
            throw new RuntimeException(
                'Manifesto do backup completo não referencia o banco.'
            );
        }

        if (
            empty(
                $verification['database_present']
            )
        ) {
            throw new RuntimeException(
                'O banco referenciado pelo manifesto não foi localizado no ZIP.'
            );
        }

        return [
            'ok' => true,
            'name' => $name,
            'path' => $path,
            'size' => (int)$size,
            'sha256' =>
                hash_file(
                    'sha256',
                    $path
                )
                ?: '',
            'app_version' =>
                (string)($manifest['app_version'] ?? ''),
            'format_version' =>
                (int)($manifest['format_version'] ?? 0),
            'files_manifest' =>
                count(
                    (array)($manifest['files'] ?? [])
                ),
            'files_verified' =>
                (int)$verification['files_verified'],
            'bytes_verified' =>
                (int)$verification['bytes_verified'],
            'include_uploads' =>
                !empty(
                    $manifest['includes']['uploads']
                ),
            'include_themes' =>
                !empty(
                    $manifest['includes']['themes']
                ),
            'database_present' => true,
            'restoration_method_available' =>
                method_exists(
                    $this->full,
                    'restoreFullBackup'
                ),
        ];
    }

    /**
     * @return array{
     *   tables:array<int,string>,
     *   create_count:int,
     *   drop_count:int,
     *   insert_count:int,
     *   foreign_key_disable:bool,
     *   foreign_key_enable:bool
     * }
     */
    private function inspectSqlBackup(
        string $path
    ): array {
        $gzip =
            str_ends_with(
                strtolower($path),
                '.gz'
            );

        $handle =
            $gzip
                ? gzopen(
                    $path,
                    'rb'
                )
                : fopen(
                    $path,
                    'rb'
                );

        if ($handle === false) {
            throw new RuntimeException(
                'Não foi possível abrir o SQL gerado.'
            );
        }

        $tables = [];
        $createCount = 0;
        $dropCount = 0;
        $insertCount = 0;
        $foreignKeyDisable = false;
        $foreignKeyEnable = false;

        try {
            while (true) {
                $line =
                    $gzip
                        ? gzgets($handle)
                        : fgets($handle);

                if ($line === false) {
                    $eof =
                        $gzip
                            ? gzeof($handle)
                            : feof($handle);

                    if ($eof) {
                        break;
                    }

                    continue;
                }

                $trim =
                    trim(
                        $line
                    );

                if (
                    str_starts_with(
                        $trim,
                        'SET FOREIGN_KEY_CHECKS=0'
                    )
                ) {
                    $foreignKeyDisable = true;
                }

                if (
                    str_starts_with(
                        $trim,
                        'SET FOREIGN_KEY_CHECKS=1'
                    )
                ) {
                    $foreignKeyEnable = true;
                }

                if (
                    preg_match(
                        '/^DROP TABLE IF EXISTS `([^`]+)`;/',
                        $trim,
                        $m
                    )
                ) {
                    $dropCount++;
                    $tables[] =
                        (string)$m[1];
                    continue;
                }

                if (
                    preg_match(
                        '/^CREATE TABLE `([^`]+)`/i',
                        $trim
                    )
                ) {
                    $createCount++;
                    continue;
                }

                if (
                    str_starts_with(
                        strtoupper($trim),
                        'INSERT INTO '
                    )
                ) {
                    $insertCount++;
                }
            }
        } finally {
            if ($gzip) {
                gzclose($handle);
            } else {
                fclose($handle);
            }
        }

        $tables =
            array_values(
                array_unique(
                    $tables
                )
            );

        sort(
            $tables,
            SORT_STRING
        );

        return [
            'tables' => $tables,
            'create_count' => $createCount,
            'drop_count' => $dropCount,
            'insert_count' => $insertCount,
            'foreign_key_disable' => $foreignKeyDisable,
            'foreign_key_enable' => $foreignKeyEnable,
        ];
    }

    /**
     * @return array{
     *   files_verified:int,
     *   bytes_verified:int,
     *   database_present:bool
     * }
     */
    private function verifyZipAgainstManifest(
        string $path,
        array $manifest
    ): array {
        $files =
            $manifest['files']
            ?? null;

        if (!is_array($files)) {
            throw new RuntimeException(
                'Lista de arquivos ausente no manifest.json.'
            );
        }

        $zip =
            new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException(
                'Não foi possível reabrir o ZIP para validação.'
            );
        }

        $filesVerified = 0;
        $bytesVerified = 0;
        $databaseFile =
            (string)($manifest['database']['file'] ?? '');
        $databasePresent = false;

        try {
            foreach ($files as $entry) {
                if (!is_array($entry)) {
                    throw new RuntimeException(
                        'Entrada inválida no manifesto.'
                    );
                }

                $relative =
                    (string)($entry['path'] ?? '');

                $expectedHash =
                    strtolower(
                        (string)($entry['sha256'] ?? '')
                    );

                $expectedSize =
                    (int)($entry['size'] ?? -1);

                if (
                    $relative === ''
                    || $expectedHash === ''
                    || $expectedSize < 0
                ) {
                    throw new RuntimeException(
                        'Entrada incompleta no manifesto: '
                        . $relative
                    );
                }

                $stat =
                    $zip->statName(
                        $relative
                    );

                if (!is_array($stat)) {
                    throw new RuntimeException(
                        'Arquivo ausente no ZIP: '
                        . $relative
                    );
                }

                $actualSize =
                    (int)($stat['size'] ?? -1);

                if (
                    $actualSize !== $expectedSize
                ) {
                    throw new RuntimeException(
                        'Tamanho divergente no ZIP: '
                        . $relative
                    );
                }

                $stream =
                    $zip->getStream(
                        $relative
                    );

                if ($stream === false) {
                    throw new RuntimeException(
                        'Não foi possível abrir no ZIP: '
                        . $relative
                    );
                }

                $hash =
                    hash_init(
                        'sha256'
                    );

                $readBytes = 0;

                try {
                    while (!feof($stream)) {
                        $chunk =
                            fread(
                                $stream,
                                8192
                            );

                        if ($chunk === false) {
                            throw new RuntimeException(
                                'Falha ao ler no ZIP: '
                                . $relative
                            );
                        }

                        if ($chunk === '') {
                            continue;
                        }

                        $readBytes +=
                            strlen(
                                $chunk
                            );

                        hash_update(
                            $hash,
                            $chunk
                        );
                    }
                } finally {
                    fclose($stream);
                }

                $actualHash =
                    strtolower(
                        hash_final(
                            $hash
                        )
                    );

                if (
                    $readBytes !== $expectedSize
                    || !hash_equals(
                        $expectedHash,
                        $actualHash
                    )
                ) {
                    throw new RuntimeException(
                        'SHA-256 divergente no ZIP: '
                        . $relative
                    );
                }

                if (
                    $relative === $databaseFile
                ) {
                    $databasePresent = true;
                }

                $filesVerified++;
                $bytesVerified +=
                    $readBytes;
            }
        } finally {
            $zip->close();
        }

        return [
            'files_verified' => $filesVerified,
            'bytes_verified' => $bytesVerified,
            'database_present' => $databasePresent,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function databaseTables(): array
    {
        $stmt =
            $this->pdo->query(
                "SHOW FULL TABLES WHERE Table_type='BASE TABLE'"
            );

        $tables = [];

        while (
            $row =
            $stmt->fetch(
                PDO::FETCH_NUM
            )
        ) {
            if (isset($row[0])) {
                $tables[] =
                    (string)$row[0];
            }
        }

        sort(
            $tables,
            SORT_STRING
        );

        return $tables;
    }
}
