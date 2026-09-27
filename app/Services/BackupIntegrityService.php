<?php

declare(strict_types=1);

/**
 * Saúde e integridade de backups — v1.1.12.
 *
 * Valida os backups existentes sem restaurar o banco ativo e sem extrair
 * arquivos sobre o Portal. O resultado da última verificação é salvo em
 * storage/backups/integrity-last.json.
 */
final class BackupIntegrityService
{
    private BackupService $database;
    private FullBackupService $full;
    private string $backupDir;
    private string $stateFile;

    public function __construct(
        private PDO $pdo,
        private string $rootPath
    ) {
        $this->rootPath =
            rtrim(
                $this->rootPath,
                DIRECTORY_SEPARATOR
            );

        $this->loadDependencies();

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

        $this->backupDir =
            $this->database
                ->backupDirectory();

        $this->stateFile =
            $this->backupDir
            . DIRECTORY_SEPARATOR
            . 'integrity-last.json';
    }

    /**
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $dbBackups =
            $this->database
                ->listDatabaseBackups();

        $fullBackups = [];

        try {
            $fullBackups =
                $this->full
                    ->listFullBackups();
        } catch (Throwable $ignored) {
        }

        $latestDb =
            $dbBackups[0]
            ?? null;

        $latestFull =
            $fullBackups[0]
            ?? null;

        $staleHours =
            max(
                12,
                min(
                    720,
                    (int)siteConfig(
                        $this->pdo,
                        'backup_stale_hours',
                        '36'
                    )
                )
            );

        return [
            'stale_hours' =>
                $staleHours,
            'database' =>
                $this->decorateBackup(
                    $latestDb,
                    $staleHours
                ),
            'full' =>
                $this->decorateBackup(
                    $latestFull,
                    $staleHours
                ),
            'last_integrity' =>
                $this->readState(),
            'task_database' =>
                $this->taskStatus(
                    'backup_banco_automatico'
                ),
            'task_full' =>
                $this->taskStatus(
                    'backup_completo_automatico'
                ),
            'task_integrity' =>
                $this->taskStatus(
                    'backup_integridade_automatico'
                ),
            'zip_supported' =>
                $this->full
                    ->isSupported(),
            'database_backups' =>
                array_slice(
                    $dbBackups,
                    0,
                    8
                ),
            'full_backups' =>
                array_slice(
                    $fullBackups,
                    0,
                    8
                ),
        ];
    }

    /**
     * Executa a verificação sem restaurar nada.
     *
     * @return array<string,mixed>
     */
    public function run(
        string $origin = 'manual'
    ): array {
        $started =
            microtime(true);

        $dbBackups =
            $this->database
                ->listDatabaseBackups();

        $fullBackups = [];

        try {
            $fullBackups =
                $this->full
                    ->listFullBackups();
        } catch (Throwable $ignored) {
        }

        $result = [
            'ok' => true,
            'origin' =>
                self::cut(
                    trim($origin)
                    ?: 'manual',
                    30
                ),
            'started_at' =>
                date(
                    'Y-m-d H:i:s'
                ),
            'finished_at' =>
                null,
            'duration_ms' =>
                0,
            'database' =>
                null,
            'full' =>
                null,
            'warnings' =>
                [],
            'errors' =>
                [],
        ];

        if (!$dbBackups) {
            $result['errors'][] =
                'Nenhum backup do banco foi encontrado.';
        } else {
            try {
                $result['database'] =
                    $this->verifyDatabaseBackup(
                        $dbBackups[0]
                    );
            } catch (Throwable $e) {
                $result['errors'][] =
                    'Banco: '
                    . $e->getMessage();
            }
        }

        if ($fullBackups) {
            if (!$this->full->isSupported()) {
                $result['warnings'][] =
                    'Existe backup completo, mas ZipArchive não está disponível para validar o ZIP.';
            } else {
                try {
                    $result['full'] =
                        $this->verifyFullBackup(
                            $fullBackups[0]
                        );
                } catch (Throwable $e) {
                    $result['errors'][] =
                        'Completo: '
                        . $e->getMessage();
                }
            }
        } else {
            $result['warnings'][] =
                'Nenhum backup completo foi encontrado. O backup completo automático é opcional.';
        }

        $result['ok'] =
            !$result['errors'];

        $result['finished_at'] =
            date(
                'Y-m-d H:i:s'
            );

        $result['duration_ms'] =
            max(
                0,
                (int)round(
                    (
                        microtime(true)
                        - $started
                    )
                    * 1000
                )
            );

        $this->writeState(
            $result
        );

        return $result;
    }

    /**
     * Handler para o Scheduler.
     *
     * @return array{status:string,message:string}
     */
    public function runScheduled(): array
    {
        $result =
            $this->run(
                'cron'
            );

        if (empty($result['ok'])) {
            throw new RuntimeException(
                'Integridade de backup falhou: '
                . implode(
                    ' | ',
                    array_map(
                        'strval',
                        (array)(
                            $result['errors']
                            ?? []
                        )
                    )
                )
            );
        }

        $parts = [
            'Banco verificado',
        ];

        if (
            is_array(
                $result['full']
                ?? null
            )
        ) {
            $parts[] =
                'backup completo verificado';
        }

        if (
            !empty(
                $result['warnings']
            )
        ) {
            $parts[] =
                count(
                    (array)$result['warnings']
                )
                . ' aviso(s)';
        }

        return [
            'status' =>
                'ok',
            'message' =>
                'Integridade automática: '
                . implode(
                    '; ',
                    $parts
                )
                . '.',
        ];
    }

    /**
     * @param array<string,mixed> $backup
     * @return array<string,mixed>
     */
    private function verifyDatabaseBackup(
        array $backup
    ): array {
        $path =
            (string)(
                $backup['path']
                ?? ''
            );

        $name =
            (string)(
                $backup['name']
                ?? basename($path)
            );

        if (
            $path === ''
            || !is_file($path)
        ) {
            throw new RuntimeException(
                'Arquivo do backup não foi encontrado.'
            );
        }

        $size =
            filesize($path);

        if (
            $size === false
            || $size <= 0
        ) {
            throw new RuntimeException(
                'O backup está vazio.'
            );
        }

        $sha256 =
            hash_file(
                'sha256',
                $path
            )
            ?: '';

        if ($sha256 === '') {
            throw new RuntimeException(
                'Não foi possível calcular SHA-256.'
            );
        }

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
                'Não foi possível abrir o backup para leitura.'
            );
        }

        $seenForeignOff = false;
        $seenForeignOn = false;
        $seenCreate = false;
        $bytesRead = 0;
        $carry = '';

        try {
            while (true) {
                $chunk =
                    $gzip
                        ? gzread(
                            $handle,
                            1024 * 1024
                        )
                        : fread(
                            $handle,
                            1024 * 1024
                        );

                if ($chunk === false) {
                    throw new RuntimeException(
                        'Falha ao ler o conteúdo do backup.'
                    );
                }

                if ($chunk === '') {
                    $eof =
                        $gzip
                            ? gzeof($handle)
                            : feof($handle);

                    if ($eof) {
                        break;
                    }

                    continue;
                }

                $bytesRead +=
                    strlen($chunk);

                $scan =
                    $carry
                    . $chunk;

                if (
                    stripos(
                        $scan,
                        'SET FOREIGN_KEY_CHECKS=0'
                    )
                    !== false
                ) {
                    $seenForeignOff = true;
                }

                if (
                    stripos(
                        $scan,
                        'SET FOREIGN_KEY_CHECKS=1'
                    )
                    !== false
                ) {
                    $seenForeignOn = true;
                }

                if (
                    stripos(
                        $scan,
                        'CREATE TABLE'
                    )
                    !== false
                ) {
                    $seenCreate = true;
                }

                $carry =
                    substr(
                        $scan,
                        -256
                    );
            }
        } finally {
            if ($gzip) {
                gzclose($handle);
            } else {
                fclose($handle);
            }
        }

        if ($bytesRead <= 0) {
            throw new RuntimeException(
                'O conteúdo SQL não pôde ser lido.'
            );
        }

        if (!$seenCreate) {
            throw new RuntimeException(
                'Nenhum CREATE TABLE foi localizado no dump.'
            );
        }

        if (
            !$seenForeignOff
            || !$seenForeignOn
        ) {
            throw new RuntimeException(
                'O ciclo FOREIGN_KEY_CHECKS do dump está incompleto.'
            );
        }

        return [
            'ok' =>
                true,
            'name' =>
                $name,
            'size' =>
                (int)$size,
            'sha256' =>
                $sha256,
            'gzip' =>
                $gzip,
            'sql_bytes_read' =>
                $bytesRead,
        ];
    }

    /**
     * @param array<string,mixed> $backup
     * @return array<string,mixed>
     */
    private function verifyFullBackup(
        array $backup
    ): array {
        if (!$this->full->isSupported()) {
            throw new RuntimeException(
                'ZipArchive não está disponível.'
            );
        }

        $name =
            (string)(
                $backup['name']
                ?? ''
            );

        if ($name === '') {
            throw new RuntimeException(
                'Nome do backup completo ausente.'
            );
        }

        $path =
            $this->full
                ->fullBackupPath(
                    $name
                );

        $size =
            filesize($path);

        if (
            $size === false
            || $size <= 0
        ) {
            throw new RuntimeException(
                'O ZIP está vazio.'
            );
        }

        $zip =
            new ZipArchive();

        $opened =
            $zip->open(
                $path
            );

        if ($opened !== true) {
            throw new RuntimeException(
                'Não foi possível abrir o ZIP. Código: '
                . (string)$opened
            );
        }

        $filesVerified = 0;
        $bytesVerified = 0;
        $manifest = null;

        try {
            $raw =
                $zip->getFromName(
                    'manifest.json'
                );

            if (
                !is_string($raw)
                || trim($raw) === ''
            ) {
                throw new RuntimeException(
                    'manifest.json não encontrado.'
                );
            }

            try {
                $manifest =
                    json_decode(
                        $raw,
                        true,
                        512,
                        JSON_THROW_ON_ERROR
                    );
            } catch (JsonException $e) {
                throw new RuntimeException(
                    'manifest.json inválido: '
                    . $e->getMessage()
                );
            }

            if (
                !is_array($manifest)
                || (
                    $manifest['format']
                    ?? ''
                ) !== 'portal-ieclb-backup'
                || (
                    $manifest['type']
                    ?? ''
                ) !== 'full'
            ) {
                throw new RuntimeException(
                    'Manifesto do backup completo inválido.'
                );
            }

            $files =
                $manifest['files']
                ?? null;

            if (!is_array($files)) {
                throw new RuntimeException(
                    'Lista de arquivos ausente no manifesto.'
                );
            }

            foreach ($files as $entry) {
                if (!is_array($entry)) {
                    throw new RuntimeException(
                        'Entrada inválida no manifesto.'
                    );
                }

                $relative =
                    (string)(
                        $entry['path']
                        ?? ''
                    );

                $expectedHash =
                    strtolower(
                        (string)(
                            $entry['sha256']
                            ?? ''
                        )
                    );

                $expectedSize =
                    (int)(
                        $entry['size']
                        ?? -1
                    );

                if (
                    !$this->safeRelativePath(
                        $relative
                    )
                    || $expectedHash === ''
                    || $expectedSize < 0
                ) {
                    throw new RuntimeException(
                        'Entrada inválida no manifesto: '
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
                    (int)(
                        $stat['size']
                        ?? -1
                    );

                if (
                    $actualSize
                    !== $expectedSize
                ) {
                    throw new RuntimeException(
                        'Tamanho divergente: '
                        . $relative
                    );
                }

                $stream =
                    $zip->getStream(
                        $relative
                    );

                if ($stream === false) {
                    throw new RuntimeException(
                        'Não foi possível ler: '
                        . $relative
                    );
                }

                $hash =
                    hash_init(
                        'sha256'
                    );

                $read = 0;

                try {
                    while (!feof($stream)) {
                        $chunk =
                            fread(
                                $stream,
                                1024 * 1024
                            );

                        if ($chunk === false) {
                            throw new RuntimeException(
                                'Falha de leitura: '
                                . $relative
                            );
                        }

                        if ($chunk === '') {
                            continue;
                        }

                        $read +=
                            strlen($chunk);

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
                    $read !== $expectedSize
                    || !hash_equals(
                        $expectedHash,
                        $actualHash
                    )
                ) {
                    throw new RuntimeException(
                        'SHA-256 divergente: '
                        . $relative
                    );
                }

                $filesVerified++;
                $bytesVerified +=
                    $read;
            }

            $databaseFile =
                (string)(
                    $manifest['database']['file']
                    ?? ''
                );

            if (
                !$this->safeRelativePath(
                    $databaseFile
                )
                || !str_starts_with(
                    $databaseFile,
                    'database/'
                )
                || $zip->locateName(
                    $databaseFile
                ) === false
            ) {
                throw new RuntimeException(
                    'Arquivo do banco não foi localizado no ZIP.'
                );
            }
        } finally {
            $zip->close();
        }

        return [
            'ok' =>
                true,
            'name' =>
                $name,
            'size' =>
                (int)$size,
            'sha256' =>
                hash_file(
                    'sha256',
                    $path
                )
                ?: '',
            'files_verified' =>
                $filesVerified,
            'bytes_verified' =>
                $bytesVerified,
            'app_version' =>
                is_array($manifest)
                    ? (string)(
                        $manifest['app_version']
                        ?? ''
                    )
                    : '',
        ];
    }

    /**
     * @param array<string,mixed>|null $backup
     * @return array<string,mixed>
     */
    private function decorateBackup(
        ?array $backup,
        int $staleHours
    ): array {
        if (!$backup) {
            return [
                'exists' => false,
                'stale' => true,
                'age_hours' => null,
                'name' => null,
                'mtime' => null,
                'size' => 0,
            ];
        }

        $mtime =
            (int)(
                $backup['mtime']
                ?? 0
            );

        $age =
            $mtime > 0
                ? max(
                    0,
                    (int)floor(
                        (
                            time()
                            - $mtime
                        )
                        / 3600
                    )
                )
                : null;

        return
            array_merge(
                $backup,
                [
                    'exists' => true,
                    'age_hours' =>
                        $age,
                    'stale' =>
                        $age === null
                        || $age > $staleHours,
                ]
            );
    }

    /**
     * @return array<string,mixed>
     */
    private function taskStatus(
        string $slug
    ): array {
        $status = [
            'slug' =>
                $slug,
            'registered' =>
                false,
            'active' =>
                false,
            'interval' =>
                null,
            'next_run' =>
                null,
            'last_status' =>
                null,
            'last_message' =>
                null,
            'last_finished' =>
                null,
        ];

        try {
            $stmt =
                $this->pdo->prepare(
                    "SELECT *
                     FROM tarefas_agendadas
                     WHERE slug=?
                     LIMIT 1"
                );

            $stmt->execute([
                $slug,
            ]);

            $task =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );

            if ($task) {
                $status['registered'] =
                    true;

                $status['active'] =
                    (int)(
                        $task['ativa']
                        ?? 0
                    ) === 1;

                $status['interval'] =
                    isset(
                        $task['intervalo_minutos']
                    )
                        ? (int)$task['intervalo_minutos']
                        : null;

                $status['next_run'] =
                    !empty(
                        $task['proxima_execucao_em']
                    )
                        ? (string)$task['proxima_execucao_em']
                        : null;
            }
        } catch (Throwable $ignored) {
        }

        try {
            $stmt =
                $this->pdo->prepare(
                    "SELECT *
                     FROM tarefas_execucoes
                     WHERE tarefa_slug=?
                     ORDER BY id DESC
                     LIMIT 1"
                );

            $stmt->execute([
                $slug,
            ]);

            $last =
                $stmt->fetch(
                    PDO::FETCH_ASSOC
                );

            if ($last) {
                $status['last_status'] =
                    !empty(
                        $last['status']
                    )
                        ? (string)$last['status']
                        : null;

                $status['last_message'] =
                    !empty(
                        $last['mensagem']
                    )
                        ? (string)$last['mensagem']
                        : null;

                $status['last_finished'] =
                    !empty(
                        $last['finalizada_em']
                    )
                        ? (string)$last['finalizada_em']
                        : (
                            !empty(
                                $last['iniciada_em']
                            )
                                ? (string)$last['iniciada_em']
                                : null
                        );
            }
        } catch (Throwable $ignored) {
        }

        return $status;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readState(): ?array
    {
        if (!is_file($this->stateFile)) {
            return null;
        }

        $raw =
            file_get_contents(
                $this->stateFile
            );

        if (
            $raw === false
            || trim($raw) === ''
        ) {
            return null;
        }

        try {
            $data =
                json_decode(
                    $raw,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );

            return
                is_array($data)
                    ? $data
                    : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * @param array<string,mixed> $state
     */
    private function writeState(
        array $state
    ): void {
        $json =
            json_encode(
                $state,
                JSON_PRETTY_PRINT
                | JSON_UNESCAPED_SLASHES
                | JSON_UNESCAPED_UNICODE
            );

        if ($json === false) {
            return;
        }

        $tmp =
            $this->stateFile
            . '.tmp';

        if (
            @file_put_contents(
                $tmp,
                $json
                . "\n",
                LOCK_EX
            )
            === false
        ) {
            return;
        }

        if (
            DIRECTORY_SEPARATOR === '\\'
            && is_file(
                $this->stateFile
            )
        ) {
            @unlink(
                $this->stateFile
            );
        }

        @rename(
            $tmp,
            $this->stateFile
        );
    }

    private function safeRelativePath(
        string $path
    ): bool {
        $path =
            str_replace(
                '\\',
                '/',
                trim($path)
            );

        if (
            $path === ''
            || str_starts_with(
                $path,
                '/'
            )
            || preg_match(
                '/^[A-Za-z]:\//',
                $path
            )
        ) {
            return false;
        }

        foreach (
            explode(
                '/',
                $path
            )
            as $part
        ) {
            if (
                $part === ''
                || $part === '.'
                || $part === '..'
            ) {
                return false;
            }
        }

        return true;
    }

    private function loadDependencies(): void
    {
        foreach (
            [
                'BackupService' =>
                    'BackupService.php',
                'FullBackupService' =>
                    'FullBackupService.php',
            ]
            as $class => $file
        ) {
            if (class_exists($class)) {
                continue;
            }

            $path =
                $this->rootPath
                . DIRECTORY_SEPARATOR
                . 'app'
                . DIRECTORY_SEPARATOR
                . 'Services'
                . DIRECTORY_SEPARATOR
                . $file;

            if (is_file($path)) {
                require_once $path;
            }
        }

        if (
            !class_exists(
                'BackupService'
            )
            || !class_exists(
                'FullBackupService'
            )
        ) {
            throw new RuntimeException(
                'Serviços base de backup não estão disponíveis.'
            );
        }
    }

    private static function cut(
        string $value,
        int $length
    ): string {
        return
            function_exists(
                'mb_substr'
            )
                ? mb_substr(
                    $value,
                    0,
                    $length
                )
                : substr(
                    $value,
                    0,
                    $length
                );
    }
}
