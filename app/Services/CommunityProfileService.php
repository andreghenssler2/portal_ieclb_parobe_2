<?php

declare(strict_types=1);

final class CommunityProfileService
{
    /* PORTAL_COMMUNITY_SCHEMA_TX_GUARD_V119_R3 */
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

        if ($pdo->inTransaction()) {
            foreach (
                [
                    'comunidade_perfis',
                    'comunidade_fotos',
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
                        'Estrutura da v1.1.9 não inicializada antes da transação: '
                        . $table
                    );
                }
            }

            self::$schemaReady[$key] = true;
            return;
        }

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS comunidade_perfis (
                comunidade_id BIGINT UNSIGNED NOT NULL,
                horarios_cultos TEXT NULL,
                pastor_nome VARCHAR(190) NULL,
                telefone VARCHAR(60) NULL,
                whatsapp VARCHAR(60) NULL,
                email VARCHAR(190) NULL,
                instagram VARCHAR(255) NULL,
                facebook VARCHAR(255) NULL,
                youtube VARCHAR(255) NULL,
                site VARCHAR(255) NULL,
                foto_capa_id BIGINT UNSIGNED NULL,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (comunidade_id),
                KEY idx_comunidade_perfil_capa (foto_capa_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS comunidade_fotos (
                comunidade_id BIGINT UNSIGNED NOT NULL,
                midia_id BIGINT UNSIGNED NOT NULL,
                ordem INT NOT NULL DEFAULT 0,
                PRIMARY KEY (comunidade_id, midia_id),
                KEY idx_comunidade_fotos_midia (midia_id),
                KEY idx_comunidade_fotos_ordem (comunidade_id, ordem)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );

        self::$schemaReady[$key] = true;
    }

    /**
     * @return array<string,mixed>
     */
    public static function load(PDO $pdo, int $communityId): array
    {
        self::ensureSchema($pdo);

        $profile = self::emptyProfile();

        if ($communityId <= 0) {
            return $profile;
        }

        $stmt =
            $pdo->prepare(
                'SELECT *
                 FROM comunidade_perfis
                 WHERE comunidade_id=?
                 LIMIT 1'
            );

        $stmt->execute([$communityId]);
        $row = $stmt->fetch();

        if (is_array($row)) {
            $profile =
                array_replace(
                    $profile,
                    $row
                );
        }

        $stmt =
            $pdo->prepare(
                'SELECT midia_id
                 FROM comunidade_fotos
                 WHERE comunidade_id=?
                 ORDER BY ordem,midia_id'
            );

        $stmt->execute([$communityId]);

        $profile['foto_ids'] =
            array_map(
                'intval',
                $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []
            );

        $profile['foto_capa_id'] =
            max(
                0,
                (int)($profile['foto_capa_id'] ?? 0)
            );

        return $profile;
    }

    /**
     * @param array<string,mixed> $input
     * @param array<string,mixed> $current
     * @return array<string,mixed>
     */
    public static function formState(
        array $input,
        array $current = []
    ): array {
        $profile =
            array_replace(
                self::emptyProfile(),
                $current
            );

        foreach (
            [
                'horarios_cultos',
                'pastor_nome',
                'telefone',
                'whatsapp',
                'email',
                'instagram',
                'facebook',
                'youtube',
                'site',
            ]
            as $field
        ) {
            if (array_key_exists($field, $input)) {
                $profile[$field] =
                    trim(
                        (string)$input[$field]
                    );
            }
        }

        if (array_key_exists('foto_capa_id', $input)) {
            $profile['foto_capa_id'] =
                max(
                    0,
                    (int)$input['foto_capa_id']
                );
        }

        if (array_key_exists('foto_ids', $input)) {
            $profile['foto_ids'] =
                self::normalizeIds(
                    (array)$input['foto_ids'],
                    30
                );
        }

        return $profile;
    }

    /**
     * @param array<string,mixed> $input
     */
    public static function save(
        PDO $pdo,
        int $communityId,
        array $input
    ): void {
        if ($communityId <= 0) {
            throw new InvalidArgumentException(
                'Comunidade inválida.'
            );
        }

        self::ensureSchema($pdo);

        $email =
            trim(
                (string)($input['email'] ?? '')
            );

        if (
            $email !== ''
            && filter_var(
                $email,
                FILTER_VALIDATE_EMAIL
            ) === false
        ) {
            throw new RuntimeException(
                'Informe um e-mail válido para a comunidade.'
            );
        }

        $coverId =
            max(
                0,
                (int)($input['foto_capa_id'] ?? 0)
            );

        if ($coverId > 0) {
            $cover =
                MediaService::find(
                    $pdo,
                    $coverId
                );

            if (
                !$cover
                || !MediaService::isImage($cover)
            ) {
                throw new RuntimeException(
                    'A foto de capa precisa ser uma imagem válida da Biblioteca de Mídia.'
                );
            }
        }

        $photoIds =
            self::normalizeIds(
                (array)($input['foto_ids'] ?? []),
                30
            );

        foreach ($photoIds as $mediaId) {
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
                    'A galeria da comunidade contém uma mídia que não é imagem.'
                );
            }
        }

        $links = [];

        foreach (
            [
                'instagram',
                'facebook',
                'youtube',
                'site',
            ]
            as $field
        ) {
            $links[$field] =
                self::normalizeWebUrl(
                    (string)($input[$field] ?? ''),
                    $field
                );
        }

        $stmt =
            $pdo->prepare(
                'INSERT INTO comunidade_perfis
                    (
                        comunidade_id,
                        horarios_cultos,
                        pastor_nome,
                        telefone,
                        whatsapp,
                        email,
                        instagram,
                        facebook,
                        youtube,
                        site,
                        foto_capa_id
                    )
                 VALUES
                    (
                        :comunidade_id,
                        :horarios_cultos,
                        :pastor_nome,
                        :telefone,
                        :whatsapp,
                        :email,
                        :instagram,
                        :facebook,
                        :youtube,
                        :site,
                        :foto_capa_id
                    )
                 ON DUPLICATE KEY UPDATE
                    horarios_cultos=VALUES(horarios_cultos),
                    pastor_nome=VALUES(pastor_nome),
                    telefone=VALUES(telefone),
                    whatsapp=VALUES(whatsapp),
                    email=VALUES(email),
                    instagram=VALUES(instagram),
                    facebook=VALUES(facebook),
                    youtube=VALUES(youtube),
                    site=VALUES(site),
                    foto_capa_id=VALUES(foto_capa_id)'
            );

        $stmt->execute([
            'comunidade_id' =>
                $communityId,
            'horarios_cultos' =>
                self::nullIfEmpty(
                    (string)($input['horarios_cultos'] ?? '')
                ),
            'pastor_nome' =>
                self::nullIfEmpty(
                    (string)($input['pastor_nome'] ?? '')
                ),
            'telefone' =>
                self::nullIfEmpty(
                    (string)($input['telefone'] ?? '')
                ),
            'whatsapp' =>
                self::nullIfEmpty(
                    (string)($input['whatsapp'] ?? '')
                ),
            'email' =>
                $email !== ''
                    ? $email
                    : null,
            'instagram' =>
                $links['instagram'],
            'facebook' =>
                $links['facebook'],
            'youtube' =>
                $links['youtube'],
            'site' =>
                $links['site'],
            'foto_capa_id' =>
                $coverId > 0
                    ? $coverId
                    : null,
        ]);

        $pdo
            ->prepare(
                'DELETE FROM comunidade_fotos
                 WHERE comunidade_id=?'
            )
            ->execute([$communityId]);

        if ($photoIds) {
            $link =
                $pdo->prepare(
                    'INSERT INTO comunidade_fotos
                        (comunidade_id,midia_id,ordem)
                     VALUES
                        (?,?,?)'
                );

            foreach (
                $photoIds
                as $index => $mediaId
            ) {
                $link->execute([
                    $communityId,
                    $mediaId,
                    ($index + 1) * 10,
                ]);
            }
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function editorImages(PDO $pdo): array
    {
        return
            $pdo->query(
                "SELECT id,caminho,titulo,alt_text,nome_original,mime_type,
                        largura,altura,tamanho
                 FROM midias
                 WHERE mime_type LIKE 'image/%'
                 ORDER BY id DESC
                 LIMIT 300"
            )->fetchAll()
            ?: [];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function gallery(
        PDO $pdo,
        int $communityId
    ): array {
        self::ensureSchema($pdo);

        if ($communityId <= 0) {
            return [];
        }

        $stmt =
            $pdo->prepare(
                "SELECT m.*
                 FROM comunidade_fotos cf
                 INNER JOIN midias m
                    ON m.id=cf.midia_id
                 WHERE cf.comunidade_id=?
                   AND m.mime_type LIKE 'image/%'
                 ORDER BY cf.ordem,cf.midia_id"
            );

        $stmt->execute([$communityId]);

        return
            $stmt->fetchAll()
            ?: [];
    }

    /**
     * @return array<string,mixed>|null
     */
    public static function cover(
        PDO $pdo,
        array $profile
    ): ?array {
        $coverId =
            max(
                0,
                (int)($profile['foto_capa_id'] ?? 0)
            );

        if ($coverId <= 0) {
            return null;
        }

        $media =
            MediaService::find(
                $pdo,
                $coverId
            );

        return
            $media
            && MediaService::isImage($media)
                ? $media
                : null;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public static function upcomingEvents(
        PDO $pdo,
        int $communityId,
        int $limit = 6
    ): array {
        if ($communityId <= 0) {
            return [];
        }

        $limit =
            max(
                1,
                min(
                    12,
                    $limit
                )
            );

        $stmt =
            $pdo->prepare(
                "SELECT
                    e.id,
                    e.tipo,
                    e.titulo,
                    e.slug,
                    e.resumo,
                    e.data_inicio,
                    e.data_fim,
                    e.local,
                    e.santa_ceia,
                    ec.nome AS categoria_nome
                 FROM eventos e
                 LEFT JOIN evento_categorias ec
                    ON ec.id=e.categoria_evento_id
                 WHERE e.comunidade_id=?
                   AND e.status='publicado'
                   AND e.data_inicio>=NOW()
                 ORDER BY e.data_inicio,e.id
                 LIMIT "
                 . $limit
            );

        $stmt->execute([$communityId]);

        return
            $stmt->fetchAll()
            ?: [];
    }

    public static function mapQuery(array $community): string
    {
        return
            trim(
                implode(
                    ', ',
                    array_filter(
                        [
                            trim((string)($community['endereco'] ?? '')),
                            trim((string)($community['cidade'] ?? '')),
                            trim((string)($community['uf'] ?? '')),
                        ],
                        static fn(string $value): bool =>
                            $value !== ''
                    )
                )
            );
    }

    public static function mapEmbedUrl(array $community): string
    {
        $query =
            self::mapQuery(
                $community
            );

        if ($query === '') {
            return '';
        }

        return
            'https://www.google.com/maps?q='
            . rawurlencode($query)
            . '&output=embed';
    }

    public static function mapExternalUrl(array $community): string
    {
        $query =
            self::mapQuery(
                $community
            );

        if ($query === '') {
            return '';
        }

        return
            'https://www.google.com/maps/search/?api=1&query='
            . rawurlencode($query);
    }

    public static function whatsappUrl(string $value): string
    {
        $digits =
            preg_replace(
                '/\D+/',
                '',
                $value
            )
            ?: '';

        if ($digits === '') {
            return '';
        }

        /*
         * Se o administrador informar apenas DDD+número, acrescenta Brasil.
         */
        if (
            strlen($digits) >= 10
            && strlen($digits) <= 11
        ) {
            $digits =
                '55'
                . $digits;
        }

        return
            'https://wa.me/'
            . $digits;
    }

    /**
     * @return array<int,array{label:string,url:string,icon:string}>
     */
    public static function socialLinks(array $profile): array
    {
        $definitions = [
            'instagram' => [
                'label' => 'Instagram',
                'icon' => 'bi-instagram',
            ],
            'facebook' => [
                'label' => 'Facebook',
                'icon' => 'bi-facebook',
            ],
            'youtube' => [
                'label' => 'YouTube',
                'icon' => 'bi-youtube',
            ],
            'site' => [
                'label' => 'Site',
                'icon' => 'bi-globe2',
            ],
        ];

        $links = [];

        foreach ($definitions as $field => $definition) {
            $url =
                trim(
                    (string)($profile[$field] ?? '')
                );

            if (
                $url === ''
                || filter_var(
                    $url,
                    FILTER_VALIDATE_URL
                ) === false
            ) {
                continue;
            }

            $scheme =
                strtolower(
                    (string)(
                        parse_url(
                            $url,
                            PHP_URL_SCHEME
                        )
                        ?: ''
                    )
                );

            if (
                !in_array(
                    $scheme,
                    [
                        'http',
                        'https',
                    ],
                    true
                )
            ) {
                continue;
            }

            $links[] = [
                'label' =>
                    (string)$definition['label'],
                'url' =>
                    $url,
                'icon' =>
                    (string)$definition['icon'],
            ];
        }

        return $links;
    }

    /**
     * @return array<string,mixed>
     */
    private static function emptyProfile(): array
    {
        return [
            'horarios_cultos' => '',
            'pastor_nome' => '',
            'telefone' => '',
            'whatsapp' => '',
            'email' => '',
            'instagram' => '',
            'facebook' => '',
            'youtube' => '',
            'site' => '',
            'foto_capa_id' => 0,
            'foto_ids' => [],
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

    private static function nullIfEmpty(string $value): ?string
    {
        $value =
            trim($value);

        return
            $value !== ''
                ? $value
                : null;
    }

    private static function normalizeWebUrl(
        string $value,
        string $label
    ): ?string {
        $value =
            trim($value);

        if ($value === '') {
            return null;
        }

        if (
            !preg_match(
                '~^https?://~i',
                $value
            )
        ) {
            $value =
                'https://'
                . ltrim(
                    $value,
                    '/'
                );
        }

        if (
            filter_var(
                $value,
                FILTER_VALIDATE_URL
            ) === false
        ) {
            throw new RuntimeException(
                'URL inválida em '
                . ucfirst($label)
                . '.'
            );
        }

        $scheme =
            strtolower(
                (string)(
                    parse_url(
                        $value,
                        PHP_URL_SCHEME
                    )
                    ?: ''
                )
            );

        if (
            !in_array(
                $scheme,
                [
                    'http',
                    'https',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Use somente URLs http/https em '
                . ucfirst($label)
                . '.'
            );
        }

        return $value;
    }
}
