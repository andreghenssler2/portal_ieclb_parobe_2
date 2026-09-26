<?php

declare(strict_types=1);

/**
 * Recursos editoriais avançados da notícia:
 * - período de destaque;
 * - fim programado da publicação;
 * - vídeo MP4 associado;
 * - galeria de imagens;
 * - anexos para download.
 */
final class NewsFeatureService
{
    /* PORTAL_NEWS_SCHEMA_TX_GUARD_V118_R3 */
    private static array $schemaReady = [];

    public static function ensureSchema(PDO $pdo): void
    {
        $key =
            spl_object_id(
                $pdo
            );

        if (!empty(self::$schemaReady[$key])) {
            return;
        }

        /*
         * Nunca executar DDL dentro da transação do editor.
         * MySQL/MariaDB fazem commit implícito em CREATE TABLE.
         */
        if ($pdo->inTransaction()) {
            foreach (
                [
                    'post_publicacao_extras',
                    'post_galeria_midias',
                    'post_anexos_midias',
                ]
                as $table
            ) {
                $stmt =
                    $pdo->prepare(
                        'SELECT COUNT(*)
                         FROM information_schema.tables
                         WHERE table_schema=DATABASE()
                           AND table_name=?'
                    );

                $stmt->execute([$table]);

                if ((int)$stmt->fetchColumn() <= 0) {
                    throw new RuntimeException(
                        'Estrutura da v1.1.8 não inicializada antes da transação: '
                        . $table
                    );
                }
            }

            self::$schemaReady[$key] = true;
            return;
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS post_publicacao_extras (
                post_id BIGINT UNSIGNED NOT NULL,
                destaque_inicio DATETIME NULL,
                destaque_fim DATETIME NULL,
                publicado_ate DATETIME NULL,
                video_midia_id BIGINT UNSIGNED NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (post_id),
                KEY idx_post_extras_destaque (destaque_inicio, destaque_fim),
                KEY idx_post_extras_publicado_ate (publicado_ate),
                KEY idx_post_extras_video (video_midia_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS post_galeria_midias (
                post_id BIGINT UNSIGNED NOT NULL,
                midia_id BIGINT UNSIGNED NOT NULL,
                ordem INT NOT NULL DEFAULT 0,
                PRIMARY KEY (post_id, midia_id),
                KEY idx_post_galeria_midia (midia_id),
                KEY idx_post_galeria_ordem (post_id, ordem)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS post_anexos_midias (
                post_id BIGINT UNSIGNED NOT NULL,
                midia_id BIGINT UNSIGNED NOT NULL,
                ordem INT NOT NULL DEFAULT 0,
                titulo VARCHAR(220) NULL,
                PRIMARY KEY (post_id, midia_id),
                KEY idx_post_anexo_midia (midia_id),
                KEY idx_post_anexo_ordem (post_id, ordem)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        self::$schemaReady[$key] = true;
    }
    /**
     * @return array{
     *   destaque_inicio:string,
     *   destaque_fim:string,
     *   publicado_ate:string,
     *   video_midia_id:int,
     *   galeria_ids:array<int,int>,
     *   anexo_ids:array<int,int>
     * }
     */
    public static function load(PDO $pdo, int $postId): array
    {
        self::ensureSchema($pdo);

        $state = self::emptyState();

        if ($postId <= 0) {
            return $state;
        }

        $stmt =
            $pdo->prepare(
                'SELECT destaque_inicio,destaque_fim,publicado_ate,video_midia_id
                 FROM post_publicacao_extras
                 WHERE post_id=?
                 LIMIT 1'
            );

        $stmt->execute([$postId]);
        $row = $stmt->fetch();

        if (is_array($row)) {
            $state['destaque_inicio'] =
                self::dateForInput(
                    (string)($row['destaque_inicio'] ?? '')
                );

            $state['destaque_fim'] =
                self::dateForInput(
                    (string)($row['destaque_fim'] ?? '')
                );

            $state['publicado_ate'] =
                self::dateForInput(
                    (string)($row['publicado_ate'] ?? '')
                );

            $state['video_midia_id'] =
                max(
                    0,
                    (int)($row['video_midia_id'] ?? 0)
                );
        }

        $stmt =
            $pdo->prepare(
                'SELECT midia_id
                 FROM post_galeria_midias
                 WHERE post_id=?
                 ORDER BY ordem,midia_id'
            );

        $stmt->execute([$postId]);
        $state['galeria_ids'] =
            array_map(
                'intval',
                $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []
            );

        $stmt =
            $pdo->prepare(
                'SELECT midia_id
                 FROM post_anexos_midias
                 WHERE post_id=?
                 ORDER BY ordem,midia_id'
            );

        $stmt->execute([$postId]);

        $state['anexo_ids'] =
            array_map(
                'intval',
                $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []
            );

        return $state;
    }

    /**
     * Mantém o estado do formulário quando uma validação falha.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $current
     * @return array<string,mixed>
     */
    public static function formState(
        array $input,
        array $current = []
    ): array {
        $state =
            array_replace(
                self::emptyState(),
                $current
            );

        foreach (
            [
                'destaque_inicio',
                'destaque_fim',
                'publicado_ate',
            ]
            as $field
        ) {
            if (array_key_exists($field, $input)) {
                $state[$field] =
                    trim(
                        (string)$input[$field]
                    );
            }
        }

        if (array_key_exists('video_midia_id', $input)) {
            $state['video_midia_id'] =
                max(
                    0,
                    (int)$input['video_midia_id']
                );
        }

        if (array_key_exists('galeria_ids', $input)) {
            $state['galeria_ids'] =
                self::normalizeIds(
                    (array)$input['galeria_ids'],
                    30
                );
        }

        if (array_key_exists('anexo_ids', $input)) {
            $state['anexo_ids'] =
                self::normalizeIds(
                    (array)$input['anexo_ids'],
                    30
                );
        }

        return $state;
    }

    /**
     * @return array{
     *   imagens:array<int,array<string,mixed>>,
     *   videos:array<int,array<string,mixed>>,
     *   anexos:array<int,array<string,mixed>>
     * }
     */
    public static function editorMedia(PDO $pdo): array
    {
        $images =
            $pdo->query(
                "SELECT id,caminho,titulo,alt_text,nome_original,mime_type,
                        largura,altura,tamanho,extensao
                 FROM midias
                 WHERE mime_type LIKE 'image/%'
                 ORDER BY id DESC
                 LIMIT 250"
            )->fetchAll()
            ?: [];

        $videos =
            $pdo->query(
                "SELECT id,caminho,titulo,alt_text,nome_original,mime_type,
                        largura,altura,tamanho,extensao
                 FROM midias
                 WHERE mime_type IN ('video/mp4','application/mp4')
                 ORDER BY id DESC
                 LIMIT 120"
            )->fetchAll()
            ?: [];

        $attachments =
            $pdo->query(
                "SELECT id,caminho,titulo,alt_text,nome_original,mime_type,
                        largura,altura,tamanho,extensao
                 FROM midias
                 WHERE mime_type NOT LIKE 'image/%'
                   AND mime_type NOT IN ('video/mp4','application/mp4')
                 ORDER BY id DESC
                 LIMIT 250"
            )->fetchAll()
            ?: [];

        return [
            'imagens' => $images,
            'videos' => $videos,
            'anexos' => $attachments,
        ];
    }

    /**
     * @param array<string,mixed> $input
     */
    public static function save(
        PDO $pdo,
        int $postId,
        array $input
    ): void {
        if ($postId <= 0) {
            throw new InvalidArgumentException(
                'Notícia inválida para salvar recursos adicionais.'
            );
        }

        self::ensureSchema($pdo);

        $highlightStart =
            self::normalizeDate(
                (string)($input['destaque_inicio'] ?? ''),
                'Início do destaque'
            );

        $highlightEnd =
            self::normalizeDate(
                (string)($input['destaque_fim'] ?? ''),
                'Fim do destaque'
            );

        $publishEnd =
            self::normalizeDate(
                (string)($input['publicado_ate'] ?? ''),
                'Fim da publicação'
            );

        if (
            $highlightStart !== null
            && $highlightEnd !== null
            && strtotime($highlightEnd) < strtotime($highlightStart)
        ) {
            throw new RuntimeException(
                'O fim do destaque não pode ser anterior ao início.'
            );
        }

        $publishStart =
            self::normalizeDate(
                (string)($input['publicado_em'] ?? ''),
                'Início da publicação'
            );

        if (
            $publishEnd !== null
            && $publishStart !== null
            && strtotime($publishEnd) <= strtotime($publishStart)
        ) {
            throw new RuntimeException(
                'O fim da publicação precisa ser posterior ao início.'
            );
        }

        $videoId =
            max(
                0,
                (int)($input['video_midia_id'] ?? 0)
            );

        if ($videoId > 0) {
            $video =
                MediaService::find(
                    $pdo,
                    $videoId
                );

            if (
                !$video
                || !MediaService::isVideo($video)
            ) {
                throw new RuntimeException(
                    'O vídeo selecionado não é um MP4 válido.'
                );
            }
        } else {
            $videoId = 0;
        }

        $galleryIds =
            self::normalizeIds(
                (array)($input['galeria_ids'] ?? []),
                30
            );

        $attachmentIds =
            self::normalizeIds(
                (array)($input['anexo_ids'] ?? []),
                30
            );

        foreach ($galleryIds as $mediaId) {
            $media =
                MediaService::find(
                    $pdo,
                    $mediaId
                );

            if (
                !$media
                || !MediaService::isImage($media)
            ) {
                throw new RuntimeException(
                    'A galeria contém uma mídia que não é imagem.'
                );
            }
        }

        foreach ($attachmentIds as $mediaId) {
            $media =
                MediaService::find(
                    $pdo,
                    $mediaId
                );

            if (
                !$media
                || MediaService::isImage($media)
                || MediaService::isVideo($media)
            ) {
                throw new RuntimeException(
                    'Os anexos devem ser documentos da Biblioteca de Mídia.'
                );
            }
        }

        $stmt =
            $pdo->prepare(
                'INSERT INTO post_publicacao_extras
                    (
                        post_id,
                        destaque_inicio,
                        destaque_fim,
                        publicado_ate,
                        video_midia_id
                    )
                 VALUES
                    (
                        :post_id,
                        :destaque_inicio,
                        :destaque_fim,
                        :publicado_ate,
                        :video_midia_id
                    )
                 ON DUPLICATE KEY UPDATE
                    destaque_inicio=VALUES(destaque_inicio),
                    destaque_fim=VALUES(destaque_fim),
                    publicado_ate=VALUES(publicado_ate),
                    video_midia_id=VALUES(video_midia_id)'
            );

        $stmt->execute([
            'post_id' => $postId,
            'destaque_inicio' => $highlightStart,
            'destaque_fim' => $highlightEnd,
            'publicado_ate' => $publishEnd,
            'video_midia_id' => $videoId > 0 ? $videoId : null,
        ]);

        $pdo
            ->prepare(
                'DELETE FROM post_galeria_midias
                 WHERE post_id=?'
            )
            ->execute([$postId]);

        if ($galleryIds) {
            $link =
                $pdo->prepare(
                    'INSERT INTO post_galeria_midias
                        (post_id,midia_id,ordem)
                     VALUES
                        (?,?,?)'
                );

            foreach (
                $galleryIds
                as $index => $mediaId
            ) {
                $link->execute([
                    $postId,
                    $mediaId,
                    ($index + 1) * 10,
                ]);
            }
        }

        $pdo
            ->prepare(
                'DELETE FROM post_anexos_midias
                 WHERE post_id=?'
            )
            ->execute([$postId]);

        if ($attachmentIds) {
            $link =
                $pdo->prepare(
                    'INSERT INTO post_anexos_midias
                        (post_id,midia_id,ordem,titulo)
                     VALUES
                        (?,?,?,NULL)'
                );

            foreach (
                $attachmentIds
                as $index => $mediaId
            ) {
                $link->execute([
                    $postId,
                    $mediaId,
                    ($index + 1) * 10,
                ]);
            }
        }
    }

    /**
     * @return array{
     *   video:?array<string,mixed>,
     *   galeria:array<int,array<string,mixed>>,
     *   anexos:array<int,array<string,mixed>>
     * }
     */
    public static function publicAssets(
        PDO $pdo,
        int $postId
    ): array {
        self::ensureSchema($pdo);

        $video = null;

        $stmt =
            $pdo->prepare(
                "SELECT m.*
                 FROM post_publicacao_extras pe
                 INNER JOIN midias m
                    ON m.id=pe.video_midia_id
                 WHERE pe.post_id=?
                   AND m.mime_type IN ('video/mp4','application/mp4')
                 LIMIT 1"
            );

        $stmt->execute([$postId]);
        $videoRow = $stmt->fetch();

        if (is_array($videoRow)) {
            $video = $videoRow;
        }

        $stmt =
            $pdo->prepare(
                "SELECT m.*
                 FROM post_galeria_midias pg
                 INNER JOIN midias m
                    ON m.id=pg.midia_id
                 WHERE pg.post_id=?
                   AND m.mime_type LIKE 'image/%'
                 ORDER BY pg.ordem,pg.midia_id"
            );

        $stmt->execute([$postId]);
        $gallery =
            $stmt->fetchAll()
            ?: [];

        $stmt =
            $pdo->prepare(
                "SELECT m.*,pa.titulo AS anexo_titulo
                 FROM post_anexos_midias pa
                 INNER JOIN midias m
                    ON m.id=pa.midia_id
                 WHERE pa.post_id=?
                 ORDER BY pa.ordem,pa.midia_id"
            );

        $stmt->execute([$postId]);
        $attachments =
            $stmt->fetchAll()
            ?: [];

        return [
            'video' => $video,
            'galeria' => $gallery,
            'anexos' => $attachments,
        ];
    }

    public static function renderAssets(
        PDO $pdo,
        int $postId
    ): string {
        if ($postId <= 0) {
            return '';
        }

        $assets =
            self::publicAssets(
                $pdo,
                $postId
            );

        if (
            !$assets['video']
            && !$assets['galeria']
            && !$assets['anexos']
        ) {
            return '';
        }

        ob_start();
        ?>
        <?php if ($assets['video']): ?>
            <?php
            $video = $assets['video'];
            $videoTitle =
                trim(
                    (string)($video['titulo'] ?? '')
                )
                ?: trim(
                    (string)($video['nome_original'] ?? '')
                );
            ?>
            <section class="mt-5 news-feature-video">
                <h2 class="h4 mb-3">Vídeo</h2>
                <video
                    class="w-100 rounded shadow-sm"
                    controls
                    preload="metadata"
                    playsinline
                    style="max-height:720px;background:#111"
                    aria-label="<?= e($videoTitle ?: 'Vídeo da notícia') ?>"
                >
                    <source
                        src="<?= e(mediaUrl((string)$video['caminho'])) ?>"
                        type="<?= e((string)($video['mime_type'] ?: 'video/mp4')) ?>"
                    >
                    Seu navegador não suporta vídeo HTML5.
                </video>
            </section>
        <?php endif; ?>

        <?php if ($assets['galeria']): ?>
            <section class="mt-5 news-feature-gallery">
                <div class="d-flex justify-content-between align-items-center gap-3 mb-3">
                    <h2 class="h4 mb-0">Galeria de fotos</h2>
                    <span class="text-secondary small">
                        <?= count($assets['galeria']) ?> foto(s)
                    </span>
                </div>

                <div class="row g-3">
                    <?php foreach ($assets['galeria'] as $image): ?>
                        <?php
                        $alt =
                            trim(
                                (string)($image['alt_text'] ?? '')
                            )
                            ?: trim(
                                (string)($image['titulo'] ?? '')
                            )
                            ?: (string)($image['nome_original'] ?? 'Foto');
                        ?>
                        <div class="col-6 col-lg-4">
                            <a
                                href="<?= e(mediaUrl((string)$image['caminho'])) ?>"
                                target="_blank"
                                rel="noopener"
                                class="d-block"
                            >
                                <img
                                    src="<?= e(mediaUrl((string)$image['caminho'])) ?>"
                                    alt="<?= e($alt) ?>"
                                    loading="lazy"
                                    class="img-fluid rounded shadow-sm w-100"
                                    style="aspect-ratio:4/3;object-fit:cover"
                                >
                            </a>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($assets['anexos']): ?>
            <section class="mt-5 news-feature-attachments">
                <h2 class="h4 mb-3">Anexos</h2>

                <div class="list-group">
                    <?php foreach ($assets['anexos'] as $attachment): ?>
                        <?php
                        $title =
                            trim(
                                (string)($attachment['anexo_titulo'] ?? '')
                            )
                            ?: trim(
                                (string)($attachment['titulo'] ?? '')
                            )
                            ?: (string)($attachment['nome_original'] ?? 'Arquivo');

                        $size =
                            (int)($attachment['tamanho'] ?? 0);
                        ?>
                        <a
                            class="list-group-item list-group-item-action d-flex justify-content-between align-items-center gap-3"
                            href="<?= e(mediaUrl((string)$attachment['caminho'])) ?>"
                            target="_blank"
                            rel="noopener"
                            download
                        >
                            <span>
                                <i class="bi bi-paperclip me-2"></i>
                                <?= e($title) ?>
                            </span>

                            <?php if ($size > 0): ?>
                                <span class="small text-secondary text-nowrap">
                                    <?= e(formatBytes($size)) ?>
                                </span>
                            <?php endif; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
        <?php

        return
            (string)ob_get_clean();
    }

    public static function publicationActive(
        PDO $pdo,
        int $postId
    ): bool {
        self::ensureSchema($pdo);

        $stmt =
            $pdo->prepare(
                'SELECT publicado_ate
                 FROM post_publicacao_extras
                 WHERE post_id=?
                 LIMIT 1'
            );

        $stmt->execute([$postId]);
        $value =
            trim(
                (string)(
                    $stmt->fetchColumn()
                    ?: ''
                )
            );

        if ($value === '') {
            return true;
        }

        try {
            return
                new DateTimeImmutable($value)
                > new DateTimeImmutable('now');
        } catch (Throwable $e) {
            return true;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private static function emptyState(): array
    {
        return [
            'destaque_inicio' => '',
            'destaque_fim' => '',
            'publicado_ate' => '',
            'video_midia_id' => 0,
            'galeria_ids' => [],
            'anexo_ids' => [],
        ];
    }

    /**
     * @param array<int,mixed> $values
     * @return array<int,int>
     */
    private static function normalizeIds(
        array $values,
        int $limit
    ): array {
        $ids = [];

        foreach ($values as $value) {
            $id =
                max(
                    0,
                    (int)$value
                );

            if ($id > 0) {
                $ids[$id] = $id;
            }

            if (count($ids) >= $limit) {
                break;
            }
        }

        return
            array_values($ids);
    }

    private static function normalizeDate(
        string $value,
        string $label
    ): ?string {
        $value =
            trim($value);

        if ($value === '') {
            return null;
        }

        try {
            return
                (new DateTime($value))
                    ->format('Y-m-d H:i:s');
        } catch (Throwable $e) {
            throw new RuntimeException(
                $label . ' inválido.'
            );
        }
    }

    private static function dateForInput(
        string $value
    ): string {
        $value =
            trim($value);

        if ($value === '') {
            return '';
        }

        try {
            return
                (new DateTime($value))
                    ->format('Y-m-d\TH:i');
        } catch (Throwable $e) {
            return '';
        }
    }
}
