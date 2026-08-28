<?php

declare(strict_types=1);

final class BackupService
{
    private string $backupDir;

    public function __construct(
        private PDO $pdo,
        private string $rootPath
    ) {
        $this->rootPath = rtrim($this->rootPath, DIRECTORY_SEPARATOR);
        $this->backupDir = $this->rootPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups';
        $this->ensureStorage();
    }

    public function backupDirectory(): string
    {
        return $this->backupDir;
    }

    public function createDatabaseBackup(string $reason = 'manual'): array
    {
        $reason = preg_replace('/[^a-z0-9_-]+/i', '-', trim($reason)) ?: 'manual';
        $stamp = date('Ymd-His');
        $suffix = bin2hex(random_bytes(3));
        $gzip = function_exists('gzopen');
        $filename = sprintf('db-%s-%s-%s.sql%s', $stamp, $reason, $suffix, $gzip ? '.gz' : '');
        $path = $this->backupDir . DIRECTORY_SEPARATOR . $filename;

        $this->createDatabaseSnapshot($path, $gzip);
        clearstatcache(true, $path);
        return $this->fileInfo($filename);
    }

    /**
     * Gera um dump do banco em um caminho controlado pelo chamador.
     * Usado pelo backup completo da v0.22.0.
     */
    public function createDatabaseSnapshot(string $path, bool $gzip = false): array
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Não foi possível criar a pasta temporária do backup.');
        }

        $writer = $this->openWriter($path, $gzip);
        try {
            $this->write($writer, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
            foreach ($this->databaseTables() as $table) {
                $this->dumpTable($writer, $table);
            }
            $this->write($writer, "SET FOREIGN_KEY_CHECKS=1;\n");
        } catch (Throwable $e) {
            $this->closeWriter($writer);
            @unlink($path);
            throw $e;
        }
        $this->closeWriter($writer);

        clearstatcache(true, $path);
        $size = filesize($path);
        return [
            'path' => $path,
            'size' => $size === false ? 0 : (int)$size,
            'sha256' => hash_file('sha256', $path) ?: '',
            'gzip' => $gzip,
        ];
    }

    public function listDatabaseBackups(): array
    {
        $files = [];
        foreach (scandir($this->backupDir) ?: [] as $name) {
            if (!$this->isValidBackupName($name)) {
                continue;
            }
            try {
                $files[] = $this->fileInfo($name);
            } catch (Throwable $e) {
                // Ignora arquivo removido durante a listagem.
            }
        }
        usort($files, static fn(array $a, array $b): int => $b['mtime'] <=> $a['mtime']);
        return $files;
    }

    public function backupPath(string $filename): string
    {
        if (!$this->isValidBackupName($filename)) {
            throw new InvalidArgumentException('Nome de backup inválido.');
        }
        $path = $this->backupDir . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) {
            throw new RuntimeException('Backup não encontrado.');
        }
        return $path;
    }

    public function deleteBackup(string $filename): void
    {
        $path = $this->backupPath($filename);
        if (!unlink($path)) {
            throw new RuntimeException('Não foi possível excluir o backup.');
        }
    }

    /**
     * Restaura apenas backups SQL gerados por este serviço.
     * Uma cópia do banco atual é criada antes da restauração.
     */
    public function restoreDatabaseBackup(string $filename, bool $createSafetyBackup = true): array
    {
        $path = $this->backupPath($filename);
        $result = $this->restoreDatabaseFromPath($path, $createSafetyBackup);
        $result['arquivo'] = $filename;
        return $result;
    }

    /** Restaura um SQL/.sql.gz a partir de um caminho local validado pelo chamador. */
    public function restoreDatabaseFromPath(string $path, bool $createSafetyBackup = true): array
    {
        if (!is_file($path)) {
            throw new RuntimeException('Arquivo SQL do backup não encontrado.');
        }
        if (!preg_match('/\.sql(?:\.gz)?$/i', $path)) {
            throw new RuntimeException('Formato de backup SQL inválido.');
        }
        if ($createSafetyBackup) {
            $this->createDatabaseBackup('pre-restore');
        }

        $executed = 0;
        $this->pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($this->sqlStatements($path) as $statement) {
                $sql = trim($statement);
                if ($sql === '') {
                    continue;
                }
                $this->pdo->exec($sql);
                $executed++;
            }
        } finally {
            try { $this->pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $ignored) {}
        }

        return ['comandos' => $executed];
    }

    public function pruneDatabaseBackups(int $keep): int
    {
        $keep = max(1, min(100, $keep));
        $files = $this->listDatabaseBackups();
        $removed = 0;
        foreach (array_slice($files, $keep) as $item) {
            if (@unlink((string)$item['path'])) {
                $removed++;
            }
        }
        return $removed;
    }

    public function storageStats(): array
    {
        $items = $this->listDatabaseBackups();
        return [
            'count' => count($items),
            'bytes' => array_sum(array_map(static fn(array $i): int => (int)$i['size'], $items)),
            'writable' => is_writable($this->backupDir),
            'path' => $this->backupDir,
        ];
    }

    private function ensureStorage(): void
    {
        if (!is_dir($this->backupDir) && !mkdir($this->backupDir, 0750, true) && !is_dir($this->backupDir)) {
            throw new RuntimeException('Não foi possível criar storage/backups.');
        }
        if (!is_writable($this->backupDir)) {
            throw new RuntimeException('A pasta storage/backups não possui permissão de escrita.');
        }

        $htaccess = $this->backupDir . DIRECTORY_SEPARATOR . '.htaccess';
        if (!is_file($htaccess)) {
            @file_put_contents($htaccess, "Options -Indexes\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\n", LOCK_EX);
        }
        $index = $this->backupDir . DIRECTORY_SEPARATOR . 'index.php';
        if (!is_file($index)) {
            @file_put_contents($index, "<?php\nhttp_response_code(404);\nexit;\n", LOCK_EX);
        }
    }

    private function databaseTables(): array
    {
        $stmt = $this->pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        $tables = [];
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            if (isset($row[0])) {
                $tables[] = (string)$row[0];
            }
        }
        sort($tables, SORT_STRING);
        return $tables;
    }

    private function dumpTable(mixed $writer, string $table): void
    {
        $quotedTable = $this->quoteIdentifier($table);
        $create = $this->pdo->query('SHOW CREATE TABLE ' . $quotedTable)->fetch(PDO::FETCH_NUM);
        if (!$create || !isset($create[1])) {
            throw new RuntimeException('Não foi possível obter a estrutura da tabela ' . $table . '.');
        }

        $this->write($writer, "\n");
        $this->write($writer, 'DROP TABLE IF EXISTS ' . $quotedTable . ";\n");
        $this->write($writer, (string)$create[1] . ";\n\n");

        $stmt = $this->pdo->query('SELECT * FROM ' . $quotedTable);
        $columns = null;
        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($columns === null) {
                $columns = array_keys($row);
            }
            $rows[] = $row;
            if (count($rows) >= 100) {
                $this->writeInsertBatch($writer, $table, $columns, $rows);
                $rows = [];
            }
        }
        if ($columns !== null && $rows) {
            $this->writeInsertBatch($writer, $table, $columns, $rows);
        }
    }

    private function writeInsertBatch(mixed $writer, string $table, array $columns, array $rows): void
    {
        $columnSql = implode(',', array_map(fn(string $c): string => $this->quoteIdentifier($c), $columns));
        $values = [];
        foreach ($rows as $row) {
            $parts = [];
            foreach ($columns as $column) {
                $parts[] = $this->quoteValue($row[$column] ?? null);
            }
            $values[] = '(' . implode(',', $parts) . ')';
        }
        $sql = 'INSERT INTO ' . $this->quoteIdentifier($table) . ' (' . $columnSql . ') VALUES\n' . implode(",\n", $values) . ";\n";
        $this->write($writer, $sql);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function quoteValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }
        $quoted = $this->pdo->quote((string)$value);
        if ($quoted === false) {
            throw new RuntimeException('Não foi possível serializar um valor do banco.');
        }
        return $quoted;
    }

    private function openWriter(string $path, bool $gzip): array
    {
        if ($gzip) {
            $handle = gzopen($path, 'wb9');
            if ($handle === false) {
                throw new RuntimeException('Não foi possível criar o arquivo de backup compactado.');
            }
            return ['handle' => $handle, 'gzip' => true];
        }
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new RuntimeException('Não foi possível criar o arquivo de backup.');
        }
        return ['handle' => $handle, 'gzip' => false];
    }

    private function write(array $writer, string $content): void
    {
        $result = $writer['gzip'] ? gzwrite($writer['handle'], $content) : fwrite($writer['handle'], $content);
        if ($result === false) {
            throw new RuntimeException('Falha ao gravar o arquivo de backup.');
        }
    }

    private function closeWriter(array $writer): void
    {
        if ($writer['gzip']) {
            gzclose($writer['handle']);
        } else {
            fclose($writer['handle']);
        }
    }

    private function isValidBackupName(string $name): bool
    {
        return preg_match('/^db-\d{8}-\d{6}-[A-Za-z0-9_-]+-[a-f0-9]{6}\.sql(?:\.gz)?$/', $name) === 1;
    }

    private function fileInfo(string $filename): array
    {
        $path = $this->backupPathUnchecked($filename);
        $size = filesize($path);
        $mtime = filemtime($path);
        if ($size === false || $mtime === false) {
            throw new RuntimeException('Não foi possível ler os dados do backup.');
        }
        return [
            'name' => $filename,
            'path' => $path,
            'size' => (int)$size,
            'mtime' => (int)$mtime,
            'sha256' => hash_file('sha256', $path) ?: '',
            'gzip' => str_ends_with($filename, '.gz'),
        ];
    }

    private function backupPathUnchecked(string $filename): string
    {
        if (!$this->isValidBackupName($filename)) {
            throw new InvalidArgumentException('Nome de backup inválido.');
        }
        $path = $this->backupDir . DIRECTORY_SEPARATOR . $filename;
        if (!is_file($path)) {
            throw new RuntimeException('Backup não encontrado.');
        }
        return $path;
    }

    /** @return Generator<int,string> */
    private function sqlStatements(string $path): Generator
    {
        $gzip = str_ends_with($path, '.gz');
        $handle = $gzip ? gzopen($path, 'rb') : fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Não foi possível abrir o backup para restauração.');
        }

        $buffer = '';
        $quote = null;
        $escaped = false;
        $lineComment = false;
        $blockComment = false;
        $prev = '';

        try {
            while (true) {
                $chunk = $gzip ? gzread($handle, 8192) : fread($handle, 8192);
                if ($chunk === false) {
                    throw new RuntimeException('Falha durante a leitura do backup.');
                }
                if ($chunk === '') {
                    $eof = $gzip ? gzeof($handle) : feof($handle);
                    if ($eof) break;
                    continue;
                }

                $length = strlen($chunk);
                for ($i = 0; $i < $length; $i++) {
                    $ch = $chunk[$i];
                    $next = $i + 1 < $length ? $chunk[$i + 1] : '';

                    if ($lineComment) {
                        if ($ch === "\n") {
                            $lineComment = false;
                            $buffer .= $ch;
                        }
                        $prev = $ch;
                        continue;
                    }
                    if ($blockComment) {
                        if ($prev === '*' && $ch === '/') {
                            $blockComment = false;
                            $prev = '';
                            continue;
                        }
                        $prev = $ch;
                        continue;
                    }

                    if ($quote !== null) {
                        $buffer .= $ch;
                        if ($escaped) {
                            $escaped = false;
                        } elseif ($ch === '\\' && $quote !== '`') {
                            $escaped = true;
                        } elseif ($ch === $quote) {
                            // SQL também aceita aspas duplicadas como escape.
                            if ($next === $quote) {
                                $buffer .= $next;
                                $i++;
                            } else {
                                $quote = null;
                            }
                        }
                        $prev = $ch;
                        continue;
                    }

                    if ($ch === '-' && $next === '-' && ($i + 2 >= $length || ctype_space($chunk[$i + 2]))) {
                        $lineComment = true;
                        $i++;
                        $prev = '-';
                        continue;
                    }
                    if ($ch === '#') {
                        $lineComment = true;
                        $prev = $ch;
                        continue;
                    }
                    if ($ch === '/' && $next === '*') {
                        $blockComment = true;
                        $i++;
                        $prev = '*';
                        continue;
                    }
                    if ($ch === "'" || $ch === '"' || $ch === '`') {
                        $quote = $ch;
                        $buffer .= $ch;
                        $prev = $ch;
                        continue;
                    }
                    if ($ch === ';') {
                        $statement = trim($buffer);
                        $buffer = '';
                        if ($statement !== '') {
                            yield $statement;
                        }
                        $prev = $ch;
                        continue;
                    }

                    $buffer .= $ch;
                    $prev = $ch;
                }
            }
            $tail = trim($buffer);
            if ($tail !== '') {
                yield $tail;
            }
        } finally {
            if ($gzip) gzclose($handle); else fclose($handle);
        }
    }
}
