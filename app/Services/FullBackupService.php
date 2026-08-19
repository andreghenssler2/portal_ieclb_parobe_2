<?php

declare(strict_types=1);

final class FullBackupService
{
    private string $backupDir;
    private BackupService $databaseBackup;

    public function __construct(
        private PDO $pdo,
        private string $rootPath
    ) {
        $this->rootPath = rtrim($this->rootPath, DIRECTORY_SEPARATOR);
        $this->backupDir = $this->rootPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups';
        $this->databaseBackup = new BackupService($this->pdo, $this->rootPath);
        $this->ensureStorage();
    }

    public function isSupported(): bool
    {
        return class_exists('ZipArchive');
    }

    public function createFullBackup(string $reason = 'manual', bool $includeUploads = true, bool $includeThemes = true): array
    {
        if (!$this->isSupported()) {
            throw new RuntimeException('A extensão PHP ZipArchive não está disponível neste servidor.');
        }

        $reason = preg_replace('/[^a-z0-9_-]+/i', '-', trim($reason)) ?: 'manual';
        $stamp = date('Ymd-His');
        $suffix = bin2hex(random_bytes(3));
        $filename = sprintf('portal-%s-%s-%s.zip', $stamp, $reason, $suffix);
        $destination = $this->backupDir . DIRECTORY_SEPARATOR . $filename;
        $temp = $this->createTempDirectory();

        try {
            $databaseDir = $temp . DIRECTORY_SEPARATOR . 'database';
            if (!mkdir($databaseDir, 0750, true) && !is_dir($databaseDir)) {
                throw new RuntimeException('Não foi possível criar a pasta temporária do banco.');
            }
            $gzip = function_exists('gzopen');
            $databaseName = 'database.sql' . ($gzip ? '.gz' : '');
            $databasePath = $databaseDir . DIRECTORY_SEPARATOR . $databaseName;
            $dbInfo = $this->databaseBackup->createDatabaseSnapshot($databasePath, $gzip);

            $zip = new ZipArchive();
            $result = $zip->open($destination, ZipArchive::CREATE | ZipArchive::OVERWRITE);
            if ($result !== true) {
                throw new RuntimeException('Não foi possível criar o arquivo ZIP do backup completo. Código: ' . $result);
            }

            $entries = [];
            try {
                $this->addFileToZip($zip, $databasePath, 'database/' . $databaseName, $entries);

                if ($includeUploads) {
                    $this->addDirectoryToZip($zip, $this->rootPath . DIRECTORY_SEPARATOR . 'uploads', 'uploads', $entries);
                }
                if ($includeThemes) {
                    $this->addDirectoryToZip($zip, $this->rootPath . DIRECTORY_SEPARATOR . 'theme', 'theme', $entries);
                }

                $exampleConfig = $this->rootPath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'config.example.php';
                if (is_file($exampleConfig)) {
                    $this->addFileToZip($zip, $exampleConfig, 'config/config.example.php', $entries);
                }

                $manifest = [
                    'format' => 'portal-ieclb-backup',
                    'format_version' => 1,
                    'type' => 'full',
                    'created_at' => date(DATE_ATOM),
                    'app_version' => defined('APP_VERSION') ? (string)APP_VERSION : '',
                    'app_name' => defined('APP_NAME') ? (string)APP_NAME : 'Portal IECLB Parobé',
                    'base_url' => defined('BASE_URL') ? (string)BASE_URL : '',
                    'php_version' => PHP_VERSION,
                    'database' => [
                        'file' => 'database/' . $databaseName,
                        'sha256' => (string)$dbInfo['sha256'],
                        'size' => (int)$dbInfo['size'],
                        'gzip' => (bool)$dbInfo['gzip'],
                    ],
                    'includes' => [
                        'database' => true,
                        'uploads' => $includeUploads,
                        'themes' => $includeThemes,
                        'config_credentials' => false,
                    ],
                    'files_count' => count($entries),
                    'files_bytes' => array_sum(array_column($entries, 'size')),
                    'files' => $entries,
                    'restore_note' => 'config/config.php não é restaurado automaticamente.',
                ];

                $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($json === false || !$zip->addFromString('manifest.json', $json . "\n")) {
                    throw new RuntimeException('Não foi possível gravar o manifest.json no backup.');
                }
            } finally {
                $zip->close();
            }

            clearstatcache(true, $destination);
            return $this->fileInfo($filename, $manifest ?? null);
        } catch (Throwable $e) {
            @unlink($destination);
            throw $e;
        } finally {
            $this->removeTree($temp);
        }
    }

    public function listFullBackups(): array
    {
        $items = [];
        foreach (scandir($this->backupDir) ?: [] as $name) {
            if (!$this->isValidFullBackupName($name)) {
                continue;
            }
            try {
                $items[] = $this->fileInfo($name);
            } catch (Throwable $ignored) {
            }
        }
        usort($items, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
        return $items;
    }

    public function fullBackupPath(string $filename): string
    {
        if (!$this->isValidFullBackupName($filename)) {
            throw new InvalidArgumentException('Nome de backup completo inválido.');
        }
        $path = $this->backupDir . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) {
            throw new RuntimeException('Backup completo não encontrado.');
        }
        return $path;
    }

    public function deleteFullBackup(string $filename): void
    {
        $path = $this->fullBackupPath($filename);
        if (!unlink($path)) {
            throw new RuntimeException('Não foi possível excluir o backup completo.');
        }
    }

    public function pruneFullBackups(int $keep): int
    {
        $keep = max(1, min(50, $keep));
        $items = $this->listFullBackups();
        $removed = 0;
        foreach (array_slice($items, $keep) as $item) {
            if (@unlink((string)$item['path'])) {
                $removed++;
            }
        }
        return $removed;
    }

    public function inspectFullBackup(string $filename): array
    {
        if (!$this->isSupported()) {
            throw new RuntimeException('A extensão PHP ZipArchive não está disponível neste servidor.');
        }
        $path = $this->fullBackupPath($filename);
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Não foi possível abrir o backup completo.');
        }
        try {
            $this->validateArchiveEntries($zip);
            $raw = $zip->getFromName('manifest.json');
            if (!is_string($raw) || $raw === '') {
                throw new RuntimeException('O backup não possui manifest.json.');
            }
            $manifest = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($manifest) || ($manifest['format'] ?? '') !== 'portal-ieclb-backup' || ($manifest['type'] ?? '') !== 'full') {
                throw new RuntimeException('Manifesto de backup inválido.');
            }
            return $manifest;
        } catch (JsonException $e) {
            throw new RuntimeException('manifest.json inválido: ' . $e->getMessage());
        } finally {
            $zip->close();
        }
    }

    /**
     * Restaura banco e/ou arquivos de um backup completo gerado pelo Portal.
     * Arquivos existentes são sobrescritos; arquivos criados após o backup não são apagados.
     */
    public function restoreFullBackup(
        string $filename,
        bool $restoreDatabase = true,
        bool $restoreUploads = true,
        bool $restoreThemes = true,
        bool $createSafetyBackup = true
    ): array {
        if (!$this->isSupported()) {
            throw new RuntimeException('A extensão PHP ZipArchive não está disponível neste servidor.');
        }
        if (!$restoreDatabase && !$restoreUploads && !$restoreThemes) {
            throw new RuntimeException('Selecione pelo menos uma parte para restaurar.');
        }

        $source = $this->fullBackupPath($filename);
        $manifest = $this->inspectFullBackup($filename);

        if ($createSafetyBackup) {
            $this->createFullBackup('pre-restore', true, true);
        }

        $temp = $this->createTempDirectory();
        $zip = new ZipArchive();
        if ($zip->open($source) !== true) {
            $this->removeTree($temp);
            throw new RuntimeException('Não foi possível abrir o ZIP para restauração.');
        }

        try {
            $this->validateArchiveEntries($zip);
            if (!$zip->extractTo($temp)) {
                throw new RuntimeException('Não foi possível extrair o backup em área temporária.');
            }
        } finally {
            $zip->close();
        }

        $this->verifyManifestFiles($temp, $manifest);

        $result = [
            'arquivo' => $filename,
            'database_commands' => 0,
            'uploads_files' => 0,
            'theme_files' => 0,
            'safety_backup' => $createSafetyBackup,
        ];

        try {
            if ($restoreDatabase) {
                $dbRelative = (string)($manifest['database']['file'] ?? '');
                $expectedHash = strtolower((string)($manifest['database']['sha256'] ?? ''));
                if (!$this->isSafeRelativePath($dbRelative) || !str_starts_with($dbRelative, 'database/')) {
                    throw new RuntimeException('Caminho do banco inválido no manifesto.');
                }
                $dbPath = $temp . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dbRelative);
                if (!is_file($dbPath)) {
                    throw new RuntimeException('Arquivo do banco não encontrado no backup completo.');
                }
                $actualHash = strtolower(hash_file('sha256', $dbPath) ?: '');
                if ($expectedHash === '' || !hash_equals($expectedHash, $actualHash)) {
                    throw new RuntimeException('A verificação SHA-256 do banco falhou. A restauração foi cancelada.');
                }
                $dbResult = $this->databaseBackup->restoreDatabaseFromPath($dbPath, false);
                $result['database_commands'] = (int)($dbResult['comandos'] ?? 0);
            }

            if ($restoreUploads && !empty($manifest['includes']['uploads'])) {
                $sourceUploads = $temp . DIRECTORY_SEPARATOR . 'uploads';
                if (is_dir($sourceUploads)) {
                    $result['uploads_files'] = $this->copyTree($sourceUploads, $this->rootPath . DIRECTORY_SEPARATOR . 'uploads');
                }
            }

            if ($restoreThemes && !empty($manifest['includes']['themes'])) {
                $sourceThemes = $temp . DIRECTORY_SEPARATOR . 'theme';
                if (is_dir($sourceThemes)) {
                    $result['theme_files'] = $this->copyTree($sourceThemes, $this->rootPath . DIRECTORY_SEPARATOR . 'theme');
                }
            }

            return $result;
        } finally {
            $this->removeTree($temp);
        }
    }

    public function storageStats(): array
    {
        $items = $this->listFullBackups();
        return [
            'count' => count($items),
            'bytes' => array_sum(array_map(static fn(array $i): int => (int)$i['size'], $items)),
            'writable' => is_writable($this->backupDir),
            'zip_supported' => $this->isSupported(),
        ];
    }

    private function ensureStorage(): void
    {
        if (!is_dir($this->backupDir) && !mkdir($this->backupDir, 0750, true) && !is_dir($this->backupDir)) {
            throw new RuntimeException('Não foi possível criar storage/backups.');
        }
    }

    private function addDirectoryToZip(ZipArchive $zip, string $directory, string $prefix, array &$entries): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $rootReal = realpath($directory);
        if ($rootReal === false) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rootReal, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) {
                continue;
            }
            $real = $file->getRealPath();
            if ($real === false || !str_starts_with($real, $rootReal . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $relative = ltrim(substr($real, strlen($rootReal)), DIRECTORY_SEPARATOR);
            $archivePath = trim($prefix, '/') . '/' . str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            $this->addFileToZip($zip, $real, $archivePath, $entries);
        }
    }

    private function addFileToZip(ZipArchive $zip, string $source, string $archivePath, array &$entries): void
    {
        if (!$this->isSafeRelativePath($archivePath)) {
            throw new RuntimeException('Caminho inseguro durante criação do backup: ' . $archivePath);
        }
        if (!$zip->addFile($source, $archivePath)) {
            throw new RuntimeException('Não foi possível adicionar ao backup: ' . $archivePath);
        }
        $size = filesize($source);
        $entries[] = [
            'path' => $archivePath,
            'size' => $size === false ? 0 : (int)$size,
            'sha256' => hash_file('sha256', $source) ?: '',
        ];
    }

    private function verifyManifestFiles(string $temp, array $manifest): void
    {
        $files = $manifest['files'] ?? null;
        if (!is_array($files)) {
            throw new RuntimeException('Lista de arquivos ausente no manifesto.');
        }
        if (count($files) > 200000) {
            throw new RuntimeException('Quantidade de arquivos inválida no manifesto.');
        }

        foreach ($files as $entry) {
            if (!is_array($entry)) {
                throw new RuntimeException('Entrada inválida no manifesto.');
            }
            $relative = (string)($entry['path'] ?? '');
            $expectedHash = strtolower((string)($entry['sha256'] ?? ''));
            $expectedSize = (int)($entry['size'] ?? -1);
            if (!$this->isSafeRelativePath($relative) || $expectedHash === '' || $expectedSize < 0) {
                throw new RuntimeException('Entrada inválida no manifesto: ' . $relative);
            }
            $path = $temp . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($path)) {
                throw new RuntimeException('Arquivo ausente no backup: ' . $relative);
            }
            $actualSize = filesize($path);
            $actualHash = strtolower(hash_file('sha256', $path) ?: '');
            if ($actualSize === false || (int)$actualSize !== $expectedSize || !hash_equals($expectedHash, $actualHash)) {
                throw new RuntimeException('A verificação de integridade falhou para: ' . $relative);
            }
        }
    }

    private function validateArchiveEntries(ZipArchive $zip): void
    {
        if ($zip->numFiles <= 0 || $zip->numFiles > 200000) {
            throw new RuntimeException('Quantidade de arquivos inválida no backup.');
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = is_array($stat) ? (string)($stat['name'] ?? '') : '';
            if (!$this->isSafeRelativePath($name)) {
                throw new RuntimeException('Caminho inseguro detectado no ZIP: ' . $name);
            }
        }
    }

    private function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            return false;
        }
        $normalized = str_replace('\\', '/', $path);
        foreach (explode('/', $normalized) as $part) {
            if ($part === '..') {
                return false;
            }
        }
        return !preg_match('/^[A-Za-z]:\//', $normalized);
    }

    private function createTempDirectory(): string
    {
        $path = $this->backupDir . DIRECTORY_SEPARATOR . '.tmp-' . bin2hex(random_bytes(8));
        if (!mkdir($path, 0750, true) && !is_dir($path)) {
            throw new RuntimeException('Não foi possível criar a área temporária do backup.');
        }
        return $path;
    }

    private function copyTree(string $source, string $destination): int
    {
        $count = 0;
        $sourceReal = realpath($source);
        if ($sourceReal === false) {
            return 0;
        }
        if (!is_dir($destination) && !mkdir($destination, 0755, true) && !is_dir($destination)) {
            throw new RuntimeException('Não foi possível criar a pasta de destino: ' . $destination);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceReal, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || $item->isLink()) {
                continue;
            }
            $real = $item->getRealPath();
            if ($real === false || !str_starts_with($real, $sourceReal . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $relative = ltrim(substr($real, strlen($sourceReal)), DIRECTORY_SEPARATOR);
            $target = $destination . DIRECTORY_SEPARATOR . $relative;
            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                    throw new RuntimeException('Não foi possível criar a pasta durante a restauração: ' . $relative);
                }
                continue;
            }
            $targetDir = dirname($target);
            if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                throw new RuntimeException('Não foi possível criar a pasta durante a restauração.');
            }
            if (!copy($real, $target)) {
                throw new RuntimeException('Falha ao restaurar o arquivo: ' . $relative);
            }
            $count++;
        }
        return $count;
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }

    private function isValidFullBackupName(string $name): bool
    {
        return preg_match('/^portal-\d{8}-\d{6}-[A-Za-z0-9_-]+-[a-f0-9]{6}\.zip$/', $name) === 1;
    }

    private function fileInfo(string $filename, ?array $manifest = null): array
    {
        $path = $this->fullBackupPath($filename);
        $size = filesize($path);
        $mtime = filemtime($path);
        if ($size === false || $mtime === false) {
            throw new RuntimeException('Não foi possível ler os dados do backup completo.');
        }
        return [
            'name' => $filename,
            'path' => $path,
            'size' => (int)$size,
            'mtime' => (int)$mtime,
            'sha256' => hash_file('sha256', $path) ?: '',
            'manifest' => $manifest,
        ];
    }
}
