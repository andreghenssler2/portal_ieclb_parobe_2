<?php

declare(strict_types=1);

final class ThemeEditorService
{
    private const ALLOWED_EXTENSIONS = ['php', 'css', 'js', 'json'];
    private const MAX_FILE_BYTES = 1048576; // 1 MiB por arquivo editável.

    public function __construct(
        private readonly string $projectRoot
    ) {
    }

    public function themeRoot(string $themeSlug): string
    {
        if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $themeSlug)) {
            throw new InvalidArgumentException('Tema inválido.');
        }

        $root = realpath($this->projectRoot . '/theme');
        $theme = realpath($this->projectRoot . '/theme/' . $themeSlug);
        if ($root === false || $theme === false || !is_dir($theme) || !$this->isInside($theme, $root)) {
            throw new RuntimeException('Diretório do tema não encontrado.');
        }

        return $theme;
    }

    /** @return array<int,array{path:string,size:int,mtime:int,extension:string}> */
    public function editableFiles(string $themeSlug): array
    {
        $themeRoot = $this->themeRoot($themeSlug);
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($themeRoot, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile() || $fileInfo->isLink()) {
                continue;
            }
            $path = $fileInfo->getPathname();
            $relative = ltrim(str_replace('\\', '/', substr($path, strlen($themeRoot))), '/');
            if (!$this->isEditableRelativePath($relative)) {
                continue;
            }
            $files[] = [
                'path' => $relative,
                'size' => (int)$fileInfo->getSize(),
                'mtime' => (int)$fileInfo->getMTime(),
                'extension' => strtolower((string)pathinfo($relative, PATHINFO_EXTENSION)),
            ];
        }

        usort($files, static fn(array $a, array $b): int => strnatcasecmp($a['path'], $b['path']));
        return $files;
    }

    public function read(string $themeSlug, string $relativePath): string
    {
        $path = $this->resolveEditableFile($themeSlug, $relativePath);
        $size = filesize($path);
        if ($size !== false && $size > self::MAX_FILE_BYTES) {
            throw new RuntimeException('O arquivo excede o limite de 1 MB do editor web.');
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException('Não foi possível ler o arquivo do tema.');
        }
        return $contents;
    }

    /** @return array{backup:string,lint:string} */
    public function save(string $themeSlug, string $relativePath, string $contents): array
    {
        if (strlen($contents) > self::MAX_FILE_BYTES) {
            throw new RuntimeException('O conteúdo excede o limite de 1 MB do editor web.');
        }

        $target = $this->resolveEditableFile($themeSlug, $relativePath);
        $extension = strtolower((string)pathinfo($target, PATHINFO_EXTENSION));
        $this->validateContent($extension, $contents);

        $backup = $this->backupCurrent($themeSlug, $relativePath, 'antes-de-salvar');
        $tmp = dirname($target) . '/.' . basename($target) . '.portal-tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível criar o arquivo temporário.');
        }

        try {
            $lint = $extension === 'php' ? $this->lintPhp($tmp) : 'Validação concluída.';
            if ($extension === 'php' && str_starts_with($lint, '[ERRO]')) {
                throw new RuntimeException(substr($lint, 7));
            }
            $mode = @fileperms($target);
            if (!@rename($tmp, $target)) {
                throw new RuntimeException('Não foi possível substituir o arquivo original.');
            }
            if ($mode !== false) {
                @chmod($target, $mode & 0777);
            }
            return ['backup' => $backup, 'lint' => $lint];
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /** @return array<int,array{id:string,date:int,size:int,label:string,path:string}> */
    public function backups(string $themeSlug, string $relativePath): array
    {
        $this->resolveEditableFile($themeSlug, $relativePath);
        $root = $this->backupRoot($themeSlug);
        if (!is_dir($root)) {
            return [];
        }

        $encoded = $this->encodedPath($relativePath);
        $items = [];
        foreach (glob($root . '/*/' . $encoded) ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $dir = basename(dirname($file));
            $parts = explode('--', $dir, 2);
            $timestamp = ctype_digit($parts[0] ?? '') ? (int)$parts[0] : (int)filemtime($file);
            $items[] = [
                'id' => $dir,
                'date' => $timestamp,
                'size' => (int)filesize($file),
                'label' => isset($parts[1]) ? str_replace('-', ' ', $parts[1]) : 'backup',
                'path' => $file,
            ];
        }
        usort($items, static fn(array $a, array $b): int => $b['date'] <=> $a['date']);
        return array_slice($items, 0, 50);
    }

    public function restore(string $themeSlug, string $relativePath, string $backupId): string
    {
        if (!preg_match('/^[0-9]+(?:--[a-z0-9-]+)?$/', $backupId)) {
            throw new InvalidArgumentException('Backup inválido.');
        }

        $target = $this->resolveEditableFile($themeSlug, $relativePath);
        $backup = $this->backupRoot($themeSlug) . '/' . $backupId . '/' . $this->encodedPath($relativePath);
        $realBackup = realpath($backup);
        $realRoot = realpath($this->backupRoot($themeSlug));
        if ($realBackup === false || $realRoot === false || !$this->isInside($realBackup, $realRoot) || !is_file($realBackup)) {
            throw new RuntimeException('Backup não encontrado.');
        }

        $contents = file_get_contents($realBackup);
        if ($contents === false) {
            throw new RuntimeException('Não foi possível ler o backup.');
        }
        $extension = strtolower((string)pathinfo($target, PATHINFO_EXTENSION));
        $this->validateContent($extension, $contents);
        if ($extension === 'php') {
            $tmpLint = tempnam(sys_get_temp_dir(), 'portal-theme-lint-');
            if ($tmpLint === false) {
                throw new RuntimeException('Não foi possível validar o backup PHP.');
            }
            file_put_contents($tmpLint, $contents, LOCK_EX);
            try {
                $lint = $this->lintPhp($tmpLint);
                if (str_starts_with($lint, '[ERRO]')) {
                    throw new RuntimeException(substr($lint, 7));
                }
            } finally {
                @unlink($tmpLint);
            }
        }

        $this->backupCurrent($themeSlug, $relativePath, 'antes-de-restaurar');
        if (file_put_contents($target, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Não foi possível restaurar o backup.');
        }
        return $backupId;
    }

    private function resolveEditableFile(string $themeSlug, string $relativePath): string
    {
        $relativePath = str_replace('\\', '/', trim($relativePath));
        if (!$this->isEditableRelativePath($relativePath)) {
            throw new InvalidArgumentException('Arquivo não permitido no Editor de Temas.');
        }

        $themeRoot = $this->themeRoot($themeSlug);
        $candidate = realpath($themeRoot . '/' . $relativePath);
        if ($candidate === false || !is_file($candidate) || is_link($candidate) || !$this->isInside($candidate, $themeRoot)) {
            throw new RuntimeException('Arquivo do tema não encontrado.');
        }
        return $candidate;
    }

    private function isEditableRelativePath(string $path): bool
    {
        if ($path === '' || str_contains($path, "\0") || str_starts_with($path, '/') || str_contains($path, '../') || str_contains($path, '/..')) {
            return false;
        }
        if (!preg_match('#^[a-zA-Z0-9_./-]+$#', $path)) {
            return false;
        }
        $extension = strtolower((string)pathinfo($path, PATHINFO_EXTENSION));
        return in_array($extension, self::ALLOWED_EXTENSIONS, true);
    }

    private function validateContent(string $extension, string $contents): void
    {
        if ($extension === 'json') {
            json_decode($contents, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('JSON inválido: ' . json_last_error_msg());
            }
        }
    }

    private function backupCurrent(string $themeSlug, string $relativePath, string $label): string
    {
        $source = $this->resolveEditableFile($themeSlug, $relativePath);
        $root = $this->backupRoot($themeSlug);
        $timestamp = time();
        $id = $timestamp . '--' . preg_replace('/[^a-z0-9-]+/', '-', strtolower($label));
        $dir = $root . '/' . $id;
        $suffix = 1;
        while (is_dir($dir)) {
            $dir = $root . '/' . $id . '-' . $suffix++;
        }
        if (!mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar diretório de backup.');
        }
        $dest = $dir . '/' . $this->encodedPath($relativePath);
        if (!copy($source, $dest)) {
            throw new RuntimeException('Não foi possível criar backup do arquivo.');
        }
        return basename($dir);
    }

    private function backupRoot(string $themeSlug): string
    {
        $root = $this->projectRoot . '/storage/theme-backups/' . $themeSlug;
        if (!is_dir($root) && !mkdir($root, 0750, true) && !is_dir($root)) {
            throw new RuntimeException('Não foi possível preparar a pasta de backups do tema.');
        }
        return $root;
    }

    private function encodedPath(string $relativePath): string
    {
        return rtrim(strtr(base64_encode($relativePath), '+/', '-_'), '=') . '.bak';
    }

    private function isInside(string $path, string $root): bool
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');
        return $path === $root || str_starts_with($path, $root . '/');
    }

    private function lintPhp(string $path): string
    {
        if (!function_exists('proc_open')) {
            return 'O servidor não permite executar php -l; backup criado e arquivo salvo sem lint externo.';
        }
        $php = defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : 'php';
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open([$php, '-l', $path], $descriptors, $pipes);
        if (!is_resource($process)) {
            return 'Não foi possível executar php -l; backup criado e arquivo salvo sem lint externo.';
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        $message = trim((string)$stdout . "\n" . (string)$stderr);
        if ($code !== 0) {
            return '[ERRO] Erro de sintaxe PHP. ' . ($message !== '' ? $message : 'php -l retornou código ' . $code . '.');
        }
        return 'PHP validado com php -l.';
    }
}
