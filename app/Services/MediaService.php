<?php

declare(strict_types=1);

final class MediaService
{
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'text/plain' => 'txt',
    ];

    public static function upload(PDO $pdo, array $file, int $usuarioId, ?string $titulo = null, ?string $altText = null): array
    {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::uploadErrorMessage($error));
        }

        $tmpName = (string)($file['tmp_name'] ?? '');
        $originalName = trim((string)($file['name'] ?? 'arquivo'));
        $size = (int)($file['size'] ?? 0);

        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new RuntimeException('O arquivo enviado não é válido.');
        }

        if ($size <= 0) {
            throw new RuntimeException('O arquivo enviado está vazio.');
        }

        $maxSize = mediaUploadMaxSize($pdo);
        if ($size > $maxSize) {
            throw new RuntimeException('O arquivo excede o limite de ' . formatBytes($maxSize) . '.');
        }

        if (!class_exists('finfo')) {
            throw new RuntimeException('A extensão Fileinfo do PHP precisa estar habilitada para uploads seguros.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($tmpName);
        $extension = self::ALLOWED_MIME_TYPES[$mime] ?? null;

        if ($extension === null) {
            throw new RuntimeException('Tipo de arquivo não permitido: ' . ($mime ?: 'desconhecido') . '.');
        }
        if (!str_starts_with($mime, 'image/') && !mediaDocumentsAllowed($pdo)) {
            throw new RuntimeException('O envio de documentos está desativado nas configurações de mídia.');
        }

        if (mediaOrganizeByDate($pdo)) {
            $relativeDir = 'uploads/' . date('Y') . '/' . date('m');
        } else {
            $relativeDir = 'uploads';
        }
        $absoluteDir = dirname(__DIR__, 2) . '/' . $relativeDir;

        if (!is_dir($absoluteDir) && !mkdir($absoluteDir, 0755, true) && !is_dir($absoluteDir)) {
            throw new RuntimeException('Não foi possível criar a pasta de uploads.');
        }

        $fileName = bin2hex(random_bytes(18)) . '.' . $extension;
        $absolutePath = $absoluteDir . '/' . $fileName;
        $relativePath = $relativeDir . '/' . $fileName;

        if (!move_uploaded_file($tmpName, $absolutePath)) {
            throw new RuntimeException('Não foi possível salvar o arquivo no servidor.');
        }

        @chmod($absolutePath, 0644);

        $width = null;
        $height = null;
        if (str_starts_with($mime, 'image/')) {
            $dimensions = @getimagesize($absolutePath);
            if (is_array($dimensions)) {
                $width = isset($dimensions[0]) ? (int)$dimensions[0] : null;
                $height = isset($dimensions[1]) ? (int)$dimensions[1] : null;
            }
        }

        $titleValue = trim((string)$titulo);
        if ($titleValue === '') {
            $titleValue = pathinfo($originalName, PATHINFO_FILENAME);
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO midias (usuario_id, nome_original, nome_arquivo, caminho, mime_type, extensao, tamanho, largura, altura, titulo, alt_text)
                 VALUES (:usuario_id, :nome_original, :nome_arquivo, :caminho, :mime_type, :extensao, :tamanho, :largura, :altura, :titulo, :alt_text)'
            );
            $stmt->execute([
                'usuario_id' => $usuarioId,
                'nome_original' => mb_substr($originalName, 0, 255),
                'nome_arquivo' => $fileName,
                'caminho' => $relativePath,
                'mime_type' => $mime,
                'extensao' => $extension,
                'tamanho' => $size,
                'largura' => $width,
                'altura' => $height,
                'titulo' => mb_substr($titleValue, 0, 180),
                'alt_text' => ($alt = trim((string)$altText)) !== '' ? mb_substr($alt, 0, 255) : null,
            ]);
        } catch (Throwable $e) {
            @unlink($absolutePath);
            throw $e;
        }

        $id = (int)$pdo->lastInsertId();
        $media = self::find($pdo, $id);
        if (!$media) {
            throw new RuntimeException('Não foi possível recuperar a mídia enviada.');
        }

        return $media;
    }

    public static function find(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM midias WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $media = $stmt->fetch();
        return $media ?: null;
    }

    public static function isImage(array $media): bool
    {
        return str_starts_with((string)($media['mime_type'] ?? ''), 'image/');
    }

    public static function delete(PDO $pdo, int $id): bool
    {
        $media = self::find($pdo, $id);
        if (!$media) {
            return false;
        }

        $stmt = $pdo->prepare('DELETE FROM midias WHERE id = :id');
        $stmt->execute(['id' => $id]);

        $deletePhysical = true;
        try { $deletePhysical = siteConfig($pdo, 'media_delete_file_on_delete', '1') === '1'; } catch (Throwable $e) {}
        if ($deletePhysical) {
            $absolutePath = dirname(__DIR__, 2) . '/' . ltrim((string)$media['caminho'], '/');
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }
        }

        return true;
    }

    private static function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'O arquivo excede o limite de tamanho permitido.',
            UPLOAD_ERR_PARTIAL => 'O upload foi interrompido antes de terminar.',
            UPLOAD_ERR_NO_FILE => 'Nenhum arquivo foi selecionado.',
            UPLOAD_ERR_NO_TMP_DIR => 'A pasta temporária do servidor não está disponível.',
            UPLOAD_ERR_CANT_WRITE => 'O servidor não conseguiu gravar o arquivo.',
            UPLOAD_ERR_EXTENSION => 'Uma extensão do PHP interrompeu o upload.',
            default => 'Falha no upload do arquivo.',
        };
    }
}
