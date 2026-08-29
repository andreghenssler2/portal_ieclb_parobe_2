<?php

declare(strict_types=1);

/**
 * Blocos dinâmicos que consultam o próprio Portal no momento da exibição.
 *
 * v0.45.0
 */
final class DynamicContentBlockService
{
    // v0.46.1 - novos layouts: carrossel, grade e destaque + 2.
    // v0.46.2 - paginação: Lista 15/página, Card 10/página.
    // v0.47.0 - blocos Mais Lidas e Lideranças.
    // v0.48.0 - galeria incorporada dentro de Página/Post.
    private static array $tableCache = [];

    /**
     * Opções usadas pelo editor administrativo.
     *
     * @return array{
     *   categorias:array,
     *   comunidades:array,
     *   documento_categorias:array
     * }
     */
    public static function editorOptions(PDO $pdo): array
    {
        return [
            'categorias' => self::simpleOptions(
                $pdo,
                'categorias',
                'nome',
                'id',
                'nome ASC,id ASC'
            ),
            'comunidades' => self::simpleOptions(
                $pdo,
                'comunidades',
                'nome',
                'id',
                'ordem ASC,nome ASC,id ASC',
                self::columnExists($pdo, 'comunidades', 'ativa')
                    ? 'ativa=1'
                    : ''
            ),
            'documento_categorias' => self::simpleOptions(
                $pdo,
                'documento_categorias',
                'nome',
                'id',
                'ordem ASC,nome ASC,id ASC',
                self::columnExists($pdo, 'documento_categorias', 'ativo')
                    ? 'ativo=1'
                    : ''
            ),
            'leadership_types' => self::leadershipTypeOptions(),
            'galleries' => self::simpleOptions(
                $pdo,
                'galerias',
                'titulo',
                'id',
                'COALESCE(publicado_em,created_at) DESC,id DESC',
                "status='publicado'"
            ),
        ];
    }

    /**
     * Sanitiza a configuração de um bloco dinâmico.
     *
     * @return array<string,mixed>
     */
    public static function sanitize(
        PDO $pdo,
        string $type,
        array $data
    ): array {
        if ($type === 'portal_gallery_embed') {
            $galleryLayout = (string)($data['gallery_layout'] ?? 'grid');
            if (!in_array($galleryLayout, ['grid', 'carousel'], true)) {
                $galleryLayout = 'grid';
            }

            $columns = max(
                2,
                min(4, (int)($data['columns'] ?? 4))
            );

            return [
                'gallery_id' => self::validId(
                    $pdo,
                    'galerias',
                    (int)($data['gallery_id'] ?? 0)
                ),
                'gallery_layout' => $galleryLayout,
                'columns' => $columns,
                'photo_limit' => max(
                    0,
                    min(500, (int)($data['photo_limit'] ?? 0))
                ),
                'show_title' => !array_key_exists('show_title', $data)
                    || !empty($data['show_title']),
                'show_description' => !empty($data['show_description']),
                'show_captions' => !array_key_exists('show_captions', $data)
                    || !empty($data['show_captions']),
                'lightbox' => !array_key_exists('lightbox', $data)
                    || !empty($data['lightbox']),
                'title' => self::cut(
                    trim((string)($data['title'] ?? '')),
                    180
                ),
            ];
        }

        $title = self::cut(
            trim((string)($data['title'] ?? self::defaultTitle($type))),
            180
        );

        $layout = (string)($data['layout'] ?? 'cards');
        if (!in_array(
            $layout,
            ['cards', 'list', 'carousel', 'grid', 'featured2'],
            true
        )) {
            $layout = 'cards';
        }

        $requestedLimit = max(
            1,
            (int)($data['limit'] ?? 4)
        );

        // Lista e Card podem receber qualquer quantidade no editor.
        // A consulta pública busca somente a página necessária.
        $limit = in_array($layout, ['cards', 'list'], true)
            ? $requestedLimit
            : min(12, $requestedLimit);

        $common = [
            'title' => $title !== '' ? $title : self::defaultTitle($type),
            'limit' => $limit,
            'layout' => $layout,
        ];

        return match ($type) {
            'portal_posts' => $common + [
                'category_id' => self::validId(
                    $pdo,
                    'categorias',
                    (int)($data['category_id'] ?? 0)
                ),
                'show_excerpt' => !empty($data['show_excerpt']),
                'show_date' => !array_key_exists('show_date', $data)
                    || !empty($data['show_date']),
            ],
            'portal_events' => $common + [
                'community_id' => self::validId(
                    $pdo,
                    'comunidades',
                    (int)($data['community_id'] ?? 0)
                ),
                'show_date' => true,
            ],
            'portal_documents' => $common + [
                'category_id' => self::validId(
                    $pdo,
                    'documento_categorias',
                    (int)($data['category_id'] ?? 0)
                ),
                'show_date' => !array_key_exists('show_date', $data)
                    || !empty($data['show_date']),
            ],
            'portal_galleries' => $common + [
                'show_date' => !array_key_exists('show_date', $data)
                    || !empty($data['show_date']),
            ],
            'portal_communities' => $common,
            'portal_most_read' => $common + [
                'period' => in_array(
                    (string)($data['period'] ?? 'total'),
                    ['7', '30', 'total'],
                    true
                )
                    ? (string)($data['period'] ?? 'total')
                    : 'total',
                'show_date' => !array_key_exists('show_date', $data)
                    || !empty($data['show_date']),
                'show_excerpt' => !empty($data['show_excerpt']),
                'show_views' => !array_key_exists('show_views', $data)
                    || !empty($data['show_views']),
            ],
            'portal_leadership' => $common + [
                'leadership_type' => self::validLeadershipType(
                    (string)($data['leadership_type'] ?? '')
                ),
                'community_id' => self::validId(
                    $pdo,
                    'comunidades',
                    (int)($data['community_id'] ?? 0)
                ),
                'show_excerpt' => !array_key_exists('show_excerpt', $data)
                    || !empty($data['show_excerpt']),
            ],
            default => $common,
        };
    }

    public static function render(
        PDO $pdo,
        string $type,
        array $data,
        string $instanceKey = ''
    ): string {
        try {
            $instanceKey = trim($instanceKey);
            if ($instanceKey === '') {
                $instanceKey = $type
                    . '-'
                    . substr(
                        hash(
                            'sha256',
                            json_encode(
                                $data,
                                JSON_UNESCAPED_UNICODE
                                | JSON_UNESCAPED_SLASHES
                            ) ?: ''
                        ),
                        0,
                        12
                    );
            }

            return match ($type) {
                'portal_posts' => self::renderPosts(
                    $pdo,
                    $data,
                    $instanceKey
                ),
                'portal_events' => self::renderEvents(
                    $pdo,
                    $data,
                    $instanceKey
                ),
                'portal_documents' => self::renderDocuments(
                    $pdo,
                    $data,
                    $instanceKey
                ),
                'portal_galleries' => self::renderGalleries(
                    $pdo,
                    $data,
                    $instanceKey
                ),
                'portal_communities' => self::renderCommunities(
                    $pdo,
                    $data,
                    $instanceKey
                ),
                'portal_most_read' => self::renderMostRead(
                    $pdo,
                    $data,
                    $instanceKey
                ),
                'portal_leadership' => self::renderLeadership(
                    $pdo,
                    $data,
                    $instanceKey
                ),
                'portal_gallery_embed' => self::renderGalleryEmbed(
                    $pdo,
                    $data,
                    $instanceKey
                ),
                default => '',
            };
        } catch (Throwable $e) {
            // Um bloco dinâmico nunca deve derrubar a Página/Notícia pública.
            return '';
        }
    }

    private static function renderPosts(
        PDO $pdo,
        array $data,
        string $instanceKey
    ): string
    {
        if (!self::tableExists($pdo, 'posts')) {
            return '';
        }

        $limit = max(1, min(12, (int)($data['limit'] ?? 4)));
        $categoryId = max(0, (int)($data['category_id'] ?? 0));

        $joins = '';
        $imageSelect = "'' AS imagem_capa_midia,'' AS imagem_capa_alt";

        if (self::tableExists($pdo, 'midias')) {
            $joins .= ' LEFT JOIN midias m ON m.id=p.imagem_capa_id';
            $legacyImage = self::columnExists($pdo, 'posts', 'imagem_capa')
                ? "NULLIF(p.imagem_capa,'')"
                : 'NULL';

            $imageSelect =
                "COALESCE(NULLIF(m.caminho,''),{$legacyImage}) AS imagem_capa_midia,"
                . "COALESCE(NULLIF(m.alt_text,''),p.titulo) AS imagem_capa_alt";
        } elseif (self::columnExists($pdo, 'posts', 'imagem_capa')) {
            $imageSelect =
                "p.imagem_capa AS imagem_capa_midia,p.titulo AS imagem_capa_alt";
        }

        $where = [
            "p.status='publicado'",
            '(p.publicado_em IS NULL OR p.publicado_em<=NOW())',
        ];

        if ($categoryId > 0) {
            $parts = [];

            if (self::columnExists($pdo, 'posts', 'categoria_id')) {
                $parts[] = 'p.categoria_id=' . $categoryId;
            }

            if (self::tableExists($pdo, 'post_categorias')) {
                $parts[] =
                    'EXISTS (SELECT 1 FROM post_categorias pcb'
                    . ' WHERE pcb.post_id=p.id'
                    . ' AND pcb.categoria_id=' . $categoryId . ')';
            }

            if ($parts) {
                $where[] = '(' . implode(' OR ', $parts) . ')';
            }
        }

        $fromWhere =
            " FROM posts p{$joins}"
            . ' WHERE ' . implode(' AND ', $where);

        $pagination = self::paginationState(
            $pdo,
            'SELECT COUNT(*)' . $fromWhere,
            $data,
            $instanceKey
        );

        if ($pagination['query_limit'] <= 0) {
            $items = [];
        } else {
            $sql =
                "SELECT p.id,p.titulo,p.slug,p.resumo,p.conteudo,"
                . "p.publicado_em,p.created_at,{$imageSelect}"
                . $fromWhere
                . ' ORDER BY COALESCE(p.publicado_em,p.created_at) DESC,p.id DESC'
                . ' LIMIT ' . (int)$pagination['query_limit']
                . ' OFFSET ' . (int)$pagination['offset'];

            $items = $pdo->query($sql)->fetchAll() ?: [];
        }

        foreach ($items as &$item) {
            $item['_url'] = contentUrl('noticia', (string)$item['slug']);
            $item['_image'] = self::imageUrl($item['imagem_capa_midia'] ?? '');
            $item['_date'] = self::formatDate($item['publicado_em'] ?? $item['created_at'] ?? null);
            $item['_excerpt'] = self::excerpt(
                (string)($item['resumo'] ?? ''),
                (string)($item['conteudo'] ?? '')
            );
        }
        unset($item);

        return self::renderCollection(
            (string)($data['title'] ?? 'Últimas Notícias'),
            $items,
            (string)($data['layout'] ?? 'cards'),
            [
                'show_image' => true,
                'show_excerpt' => !empty($data['show_excerpt']),
                'show_date' => !empty($data['show_date']),
                'empty' => 'Nenhuma notícia publicada.',
                'pagination' => $pagination,
            ]
        );
    }

    private static function renderEvents(
        PDO $pdo,
        array $data,
        string $instanceKey
    ): string
    {
        if (!self::tableExists($pdo, 'eventos')) {
            return '';
        }

        $limit = max(1, min(12, (int)($data['limit'] ?? 4)));
        $communityId = max(0, (int)($data['community_id'] ?? 0));

        $joins = '';
        $imageSelect = "'' AS imagem_capa_midia,'' AS imagem_capa_alt";

        if (self::tableExists($pdo, 'midias')) {
            $joins .= ' LEFT JOIN midias m ON m.id=e.imagem_capa_id';
            $imageSelect =
                "m.caminho AS imagem_capa_midia,"
                . "COALESCE(NULLIF(m.alt_text,''),e.titulo) AS imagem_capa_alt";
        }

        if (self::tableExists($pdo, 'comunidades')) {
            $joins .= ' LEFT JOIN comunidades c ON c.id=e.comunidade_id';
            $communitySelect = 'c.nome AS comunidade_nome';
        } else {
            $communitySelect = "'' AS comunidade_nome";
        }

        $where = [
            "e.status='publicado'",
            'e.data_inicio>=NOW()',
        ];

        if ($communityId > 0) {
            $where[] = 'e.comunidade_id=' . $communityId;
        }

        $fromWhere =
            " FROM eventos e{$joins}"
            . ' WHERE ' . implode(' AND ', $where);

        $pagination = self::paginationState(
            $pdo,
            'SELECT COUNT(*)' . $fromWhere,
            $data,
            $instanceKey
        );

        if ($pagination['query_limit'] <= 0) {
            $items = [];
        } else {
            $sql =
                "SELECT e.id,e.titulo,e.slug,e.resumo,e.local,e.data_inicio,"
                . "{$communitySelect},{$imageSelect}"
                . $fromWhere
                . ' ORDER BY e.data_inicio ASC,e.id ASC'
                . ' LIMIT ' . (int)$pagination['query_limit']
                . ' OFFSET ' . (int)$pagination['offset'];

            $items = $pdo->query($sql)->fetchAll() ?: [];
        }

        foreach ($items as &$item) {
            $item['_url'] = contentUrl('evento', (string)$item['slug']);
            $item['_image'] = self::imageUrl($item['imagem_capa_midia'] ?? '');
            $item['_date'] = self::formatDateTime($item['data_inicio'] ?? null);

            $parts = [];
            if (!empty($item['comunidade_nome'])) {
                $parts[] = (string)$item['comunidade_nome'];
            }
            if (!empty($item['local'])) {
                $parts[] = (string)$item['local'];
            }
            $item['_excerpt'] = implode(' · ', $parts);
        }
        unset($item);

        return self::renderCollection(
            (string)($data['title'] ?? 'Agenda'),
            $items,
            (string)($data['layout'] ?? 'cards'),
            [
                'show_image' => true,
                'show_excerpt' => true,
                'show_date' => true,
                'empty' => 'Nenhum evento futuro publicado.',
                'pagination' => $pagination,
            ]
        );
    }

    private static function renderDocuments(
        PDO $pdo,
        array $data,
        string $instanceKey
    ): string
    {
        if (!self::tableExists($pdo, 'documentos')) {
            return '';
        }

        $limit = max(1, min(12, (int)($data['limit'] ?? 5)));
        $categoryId = max(0, (int)($data['category_id'] ?? 0));

        $joins = '';
        $categorySelect = "'' AS categoria_nome";
        if (self::tableExists($pdo, 'documento_categorias')) {
            $joins .= ' LEFT JOIN documento_categorias dc ON dc.id=d.categoria_id';
            $categorySelect = 'dc.nome AS categoria_nome';
        }

        $fileSelect = "'' AS extensao";
        if (self::tableExists($pdo, 'midias')) {
            $joins .= ' LEFT JOIN midias m ON m.id=d.midia_id';
            $fileSelect = 'm.extensao AS extensao';
        }

        $where = [
            "d.status='publicado'",
            '(d.publicado_em IS NULL OR d.publicado_em<=NOW())',
        ];

        if ($categoryId > 0) {
            $where[] = 'd.categoria_id=' . $categoryId;
        }

        $fromWhere =
            " FROM documentos d{$joins}"
            . ' WHERE ' . implode(' AND ', $where);

        $pagination = self::paginationState(
            $pdo,
            'SELECT COUNT(*)' . $fromWhere,
            $data,
            $instanceKey
        );

        if ($pagination['query_limit'] <= 0) {
            $items = [];
        } else {
            $sql =
                "SELECT d.id,d.titulo,d.slug,d.descricao,d.publicado_em,d.created_at,"
                . "{$categorySelect},{$fileSelect}"
                . $fromWhere
                . ' ORDER BY d.ordem ASC,COALESCE(d.publicado_em,d.created_at) DESC,d.id DESC'
                . ' LIMIT ' . (int)$pagination['query_limit']
                . ' OFFSET ' . (int)$pagination['offset'];

            $items = $pdo->query($sql)->fetchAll() ?: [];
        }

        foreach ($items as &$item) {
            $item['_url'] = contentUrl('documento', (string)$item['slug']);
            $item['_image'] = '';
            $item['_date'] = self::formatDate(
                $item['publicado_em'] ?? $item['created_at'] ?? null
            );

            $parts = [];
            if (!empty($item['categoria_nome'])) {
                $parts[] = (string)$item['categoria_nome'];
            }
            if (!empty($item['extensao'])) {
                $parts[] = strtoupper((string)$item['extensao']);
            }
            if (!empty($item['descricao'])) {
                $parts[] = self::cut(
                    trim(strip_tags((string)$item['descricao'])),
                    140
                );
            }
            $item['_excerpt'] = implode(' · ', $parts);
        }
        unset($item);

        return self::renderCollection(
            (string)($data['title'] ?? 'Documentos'),
            $items,
            (string)($data['layout'] ?? 'list'),
            [
                'show_image' => false,
                'show_excerpt' => true,
                'show_date' => !empty($data['show_date']),
                'icon' => 'bi-file-earmark-text',
                'empty' => 'Nenhum documento publicado.',
                'pagination' => $pagination,
            ]
        );
    }

    private static function renderGalleries(
        PDO $pdo,
        array $data,
        string $instanceKey
    ): string
    {
        if (!self::tableExists($pdo, 'galerias')) {
            return '';
        }

        $limit = max(1, min(12, (int)($data['limit'] ?? 4)));

        $joins = '';
        $imageSelect = "'' AS imagem_capa_midia,'' AS imagem_capa_alt";

        if (self::tableExists($pdo, 'midias')) {
            $joins .= ' LEFT JOIN midias m ON m.id=g.imagem_capa_id';
            $imageSelect =
                "m.caminho AS imagem_capa_midia,"
                . "COALESCE(NULLIF(m.alt_text,''),g.titulo) AS imagem_capa_alt";
        }

        $fromWhere =
            " FROM galerias g{$joins}"
            . " WHERE g.status='publicado'"
            . ' AND (g.publicado_em IS NULL OR g.publicado_em<=NOW())';

        $pagination = self::paginationState(
            $pdo,
            'SELECT COUNT(*)' . $fromWhere,
            $data,
            $instanceKey
        );

        if ($pagination['query_limit'] <= 0) {
            $items = [];
        } else {
            $sql =
                "SELECT g.id,g.titulo,g.slug,g.descricao,g.publicado_em,g.created_at,"
                . "{$imageSelect}"
                . $fromWhere
                . ' ORDER BY COALESCE(g.publicado_em,g.created_at) DESC,g.id DESC'
                . ' LIMIT ' . (int)$pagination['query_limit']
                . ' OFFSET ' . (int)$pagination['offset'];

            $items = $pdo->query($sql)->fetchAll() ?: [];
        }

        foreach ($items as &$item) {
            $item['_url'] = contentUrl('galeria', (string)$item['slug']);
            $item['_image'] = self::imageUrl($item['imagem_capa_midia'] ?? '');
            $item['_date'] = self::formatDate(
                $item['publicado_em'] ?? $item['created_at'] ?? null
            );
            $item['_excerpt'] = self::cut(
                trim(strip_tags((string)($item['descricao'] ?? ''))),
                150
            );
        }
        unset($item);

        return self::renderCollection(
            (string)($data['title'] ?? 'Galerias'),
            $items,
            (string)($data['layout'] ?? 'cards'),
            [
                'show_image' => true,
                'show_excerpt' => false,
                'show_date' => !empty($data['show_date']),
                'empty' => 'Nenhuma galeria publicada.',
                'pagination' => $pagination,
            ]
        );
    }

    private static function renderCommunities(
        PDO $pdo,
        array $data,
        string $instanceKey
    ): string
    {
        if (!self::tableExists($pdo, 'comunidades')) {
            return '';
        }

        $limit = max(1, min(12, (int)($data['limit'] ?? 4)));

        $joins = '';
        $imageSelect = "'' AS imagem_capa_midia,'' AS imagem_capa_alt";

        if (self::tableExists($pdo, 'midias')) {
            $joins .= ' LEFT JOIN midias m ON m.id=c.imagem_capa_id';

            $legacy = self::columnExists($pdo, 'comunidades', 'imagem')
                ? "NULLIF(c.imagem,'')"
                : 'NULL';

            $imageSelect =
                "COALESCE(NULLIF(m.caminho,''),{$legacy}) AS imagem_capa_midia,"
                . "COALESCE(NULLIF(m.alt_text,''),c.nome) AS imagem_capa_alt";
        } elseif (self::columnExists($pdo, 'comunidades', 'imagem')) {
            $imageSelect =
                'c.imagem AS imagem_capa_midia,c.nome AS imagem_capa_alt';
        }

        $where = [];
        if (self::columnExists($pdo, 'comunidades', 'ativa')) {
            $where[] = 'c.ativa=1';
        }

        $fromWhere =
            " FROM comunidades c{$joins}"
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '');

        $pagination = self::paginationState(
            $pdo,
            'SELECT COUNT(*)' . $fromWhere,
            $data,
            $instanceKey
        );

        if ($pagination['query_limit'] <= 0) {
            $items = [];
        } else {
            $sql =
                "SELECT c.id,c.nome AS titulo,c.slug,c.descricao,c.endereco,c.cidade,c.uf,"
                . "{$imageSelect}"
                . $fromWhere
                . ' ORDER BY c.ordem ASC,c.nome ASC,c.id ASC'
                . ' LIMIT ' . (int)$pagination['query_limit']
                . ' OFFSET ' . (int)$pagination['offset'];

            $items = $pdo->query($sql)->fetchAll() ?: [];
        }

        foreach ($items as &$item) {
            // O Portal atual possui a listagem pública /comunidades.
            $item['_url'] = url('comunidades');

            $item['_image'] = self::imageUrl($item['imagem_capa_midia'] ?? '');
            $item['_date'] = '';

            $parts = [];
            if (!empty($item['cidade'])) {
                $location = (string)$item['cidade'];
                if (!empty($item['uf'])) {
                    $location .= '/' . (string)$item['uf'];
                }
                $parts[] = $location;
            }
            if (!empty($item['descricao'])) {
                $parts[] = self::cut(
                    trim(strip_tags((string)$item['descricao'])),
                    130
                );
            }
            $item['_excerpt'] = implode(' · ', $parts);
        }
        unset($item);

        return self::renderCollection(
            (string)($data['title'] ?? 'Comunidades'),
            $items,
            (string)($data['layout'] ?? 'cards'),
            [
                'show_image' => true,
                'show_excerpt' => true,
                'show_date' => false,
                'empty' => 'Nenhuma comunidade disponível.',
                'pagination' => $pagination,
            ]
        );
    }

    private static function renderGalleryEmbed(
        PDO $pdo,
        array $data,
        string $instanceKey
    ): string {
        $galleryId = max(
            0,
            (int)($data['gallery_id'] ?? 0)
        );

        if (
            $galleryId <= 0
            || !self::tableExists($pdo, 'galerias')
            || !self::tableExists($pdo, 'galeria_midias')
            || !self::tableExists($pdo, 'midias')
        ) {
            return '';
        }

        $stmt = $pdo->prepare(
            "SELECT g.id,g.titulo,g.slug,g.descricao
             FROM galerias g
             WHERE g.id=:id
               AND g.status='publicado'
               AND (
                    g.publicado_em IS NULL
                    OR g.publicado_em<=NOW()
               )
             LIMIT 1"
        );
        $stmt->execute(['id' => $galleryId]);
        $gallery = $stmt->fetch();

        if (!$gallery) {
            return '';
        }

        $limit = max(
            0,
            min(500, (int)($data['photo_limit'] ?? 0))
        );

        $sql =
            "SELECT gm.legenda,gm.ordem,
                    m.id,m.caminho,m.titulo,m.alt_text,
                    m.nome_original,m.largura,m.altura
             FROM galeria_midias gm
             INNER JOIN midias m ON m.id=gm.midia_id
             WHERE gm.galeria_id=:id
               AND m.mime_type LIKE 'image/%'
             ORDER BY gm.ordem ASC,gm.id ASC";

        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute(['id' => $galleryId]);
        $photos = $stmt->fetchAll() ?: [];

        return self::renderEmbeddedGallery(
            $gallery,
            $photos,
            $data,
            $instanceKey
        );
    }

    /**
     * Renderiza as fotos da galeria diretamente dentro do conteúdo.
     *
     * @param array<string,mixed> $gallery
     * @param array<int,array<string,mixed>> $photos
     */
    private static function renderEmbeddedGallery(
        array $gallery,
        array $photos,
        array $data,
        string $instanceKey
    ): string {
        $token = substr(
            hash('sha256', $instanceKey),
            0,
            12
        );

        $rootId = 'portalGalleryEmbed-' . $token;
        $modalId = 'portalGalleryEmbedModal-' . $token;
        $carouselId = 'portalGalleryEmbedCarousel-' . $token;

        $layout = (string)($data['gallery_layout'] ?? 'grid');
        if (!in_array($layout, ['grid', 'carousel'], true)) {
            $layout = 'grid';
        }

        $columns = max(
            2,
            min(4, (int)($data['columns'] ?? 4))
        );

        $showTitle = !array_key_exists('show_title', $data)
            || !empty($data['show_title']);
        $showDescription = !empty($data['show_description']);
        $showCaptions = !array_key_exists('show_captions', $data)
            || !empty($data['show_captions']);
        $lightbox = !array_key_exists('lightbox', $data)
            || !empty($data['lightbox']);

        $customTitle = trim(
            (string)($data['title'] ?? '')
        );

        $displayTitle = $customTitle !== ''
            ? $customTitle
            : (string)($gallery['titulo'] ?? 'Galeria');

        $html = '<section id="' . self::e($rootId)
            . '" class="portal-gallery-embed my-5">';

        if ($showTitle && $displayTitle !== '') {
            $html .= '<h2 class="h3 mb-3">'
                . self::e($displayTitle)
                . '</h2>';
        }

        if (
            $showDescription
            && trim((string)($gallery['descricao'] ?? '')) !== ''
        ) {
            $html .= '<div class="text-secondary mb-4">'
                . nl2br(
                    self::e(
                        trim((string)$gallery['descricao'])
                    )
                )
                . '</div>';
        }

        if (!$photos) {
            $html .= '<div class="alert alert-light border mb-0">'
                . 'Esta galeria ainda não possui fotos.'
                . '</div></section>';

            return $html;
        }

        if ($layout === 'carousel') {
            $html .= self::renderEmbeddedGalleryCarousel(
                $photos,
                $carouselId,
                $modalId,
                $showCaptions,
                $lightbox
            );
        } else {
            $html .= self::renderEmbeddedGalleryGrid(
                $photos,
                $columns,
                $modalId,
                $showCaptions,
                $lightbox
            );
        }

        if ($lightbox) {
            $html .= self::renderEmbeddedGalleryModal(
                $photos,
                $rootId,
                $modalId
            );
        }

        $html .= '</section>';

        return $html;
    }

    /**
     * @param array<int,array<string,mixed>> $photos
     */
    private static function renderEmbeddedGalleryGrid(
        array $photos,
        int $columns,
        string $modalId,
        bool $showCaptions,
        bool $lightbox
    ): string {
        $columnClass = match ($columns) {
            2 => 'col-12 col-md-6',
            3 => 'col-6 col-md-4',
            default => 'col-6 col-md-4 col-lg-3',
        };

        $html = '<div class="row g-3 portal-gallery-embed-grid">';
        $displayIndex = 0;

        foreach ($photos as $photo) {
            $src = self::imageUrl(
                $photo['caminho'] ?? ''
            );
            if ($src === '') {
                continue;
            }

            $alt = trim(
                (string)($photo['alt_text'] ?? '')
            );
            if ($alt === '') {
                $alt = trim(
                    (string)($photo['legenda'] ?? '')
                );
            }
            if ($alt === '') {
                $alt = trim(
                    (string)($photo['titulo'] ?? '')
                );
            }

            $caption = trim(
                (string)($photo['legenda'] ?? '')
            );
            if ($caption === '') {
                $caption = trim(
                    (string)($photo['titulo'] ?? '')
                );
            }

            $html .= '<div class="' . self::e($columnClass) . '">'
                . '<figure class="mb-0">';

            if ($lightbox) {
                $html .= '<button type="button"'
                    . ' class="border-0 p-0 bg-transparent w-100 d-block"'
                    . ' data-gallery-embed-index="' . $displayIndex . '"'
                    . ' data-bs-toggle="modal"'
                    . ' data-bs-target="#' . self::e($modalId) . '">';
            }

            $html .= '<img src="' . self::e($src) . '"'
                . ' alt="' . self::e($alt) . '"'
                . ' loading="lazy"'
                . ' class="w-100 rounded shadow-sm"'
                . ' style="height:220px;object-fit:cover">';

            if ($lightbox) {
                $html .= '</button>';
            }

            if ($showCaptions && $caption !== '') {
                $html .= '<figcaption class="small text-secondary mt-2">'
                    . self::e($caption)
                    . '</figcaption>';
            }

            $html .= '</figure></div>';
            $displayIndex++;
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * @param array<int,array<string,mixed>> $photos
     */
    private static function renderEmbeddedGalleryCarousel(
        array $photos,
        string $carouselId,
        string $modalId,
        bool $showCaptions,
        bool $lightbox
    ): string {
        $html = '<div id="' . self::e($carouselId)
            . '" class="carousel slide portal-gallery-embed-carousel">'
            . '<div class="carousel-inner rounded overflow-hidden shadow-sm">';

        $rendered = 0;

        foreach ($photos as $photo) {
            $src = self::imageUrl(
                $photo['caminho'] ?? ''
            );
            if ($src === '') {
                continue;
            }

            $alt = trim(
                (string)($photo['alt_text'] ?? '')
            );
            if ($alt === '') {
                $alt = trim(
                    (string)($photo['legenda'] ?? '')
                );
            }
            if ($alt === '') {
                $alt = trim(
                    (string)($photo['titulo'] ?? '')
                );
            }

            $caption = trim(
                (string)($photo['legenda'] ?? '')
            );
            if ($caption === '') {
                $caption = trim(
                    (string)($photo['titulo'] ?? '')
                );
            }

            $html .= '<div class="carousel-item '
                . ($rendered === 0 ? 'active' : '')
                . '">';

            if ($lightbox) {
                $html .= '<button type="button"'
                    . ' class="border-0 p-0 bg-dark w-100 d-block"'
                    . ' data-gallery-embed-index="' . $rendered . '"'
                    . ' data-bs-toggle="modal"'
                    . ' data-bs-target="#' . self::e($modalId) . '">';
            }

            $html .= '<img src="' . self::e($src) . '"'
                . ' alt="' . self::e($alt) . '"'
                . ' loading="lazy"'
                . ' class="d-block w-100"'
                . ' style="height:min(62vw,560px);object-fit:cover">';

            if ($lightbox) {
                $html .= '</button>';
            }

            if ($showCaptions && $caption !== '') {
                $html .= '<div class="carousel-caption d-none d-md-block">'
                    . '<div class="bg-dark bg-opacity-75 rounded px-3 py-2 d-inline-block">'
                    . self::e($caption)
                    . '</div></div>';
            }

            $html .= '</div>';
            $rendered++;
        }

        $html .= '</div>';

        if ($rendered > 1) {
            $html .= '<button class="carousel-control-prev" type="button"'
                . ' data-bs-target="#' . self::e($carouselId) . '"'
                . ' data-bs-slide="prev">'
                . '<span class="carousel-control-prev-icon" aria-hidden="true"></span>'
                . '<span class="visually-hidden">Anterior</span>'
                . '</button>'
                . '<button class="carousel-control-next" type="button"'
                . ' data-bs-target="#' . self::e($carouselId) . '"'
                . ' data-bs-slide="next">'
                . '<span class="carousel-control-next-icon" aria-hidden="true"></span>'
                . '<span class="visually-hidden">Próxima</span>'
                . '</button>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * @param array<int,array<string,mixed>> $photos
     */
    private static function renderEmbeddedGalleryModal(
        array $photos,
        string $rootId,
        string $modalId
    ): string {
        $payload = [];

        foreach ($photos as $photo) {
            $src = self::imageUrl(
                $photo['caminho'] ?? ''
            );
            if ($src === '') {
                continue;
            }

            $alt = trim(
                (string)($photo['alt_text'] ?? '')
            );
            if ($alt === '') {
                $alt = trim(
                    (string)($photo['legenda'] ?? '')
                );
            }
            if ($alt === '') {
                $alt = trim(
                    (string)($photo['titulo'] ?? '')
                );
            }

            $caption = trim(
                (string)($photo['legenda'] ?? '')
            );
            if ($caption === '') {
                $caption = trim(
                    (string)($photo['titulo'] ?? '')
                );
            }

            $payload[] = [
                'src' => $src,
                'alt' => $alt,
                'caption' => $caption,
            ];
        }

        if (!$payload) {
            return '';
        }

        $json = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_AMP
            | JSON_HEX_APOS
            | JSON_HEX_QUOT
        ) ?: '[]';

        $html = '<div class="modal fade" id="' . self::e($modalId)
            . '" tabindex="-1" aria-hidden="true">'
            . '<div class="modal-dialog modal-xl modal-dialog-centered">'
            . '<div class="modal-content bg-dark text-white border-0">'
            . '<div class="modal-header border-0">'
            . '<h2 class="modal-title fs-6" data-gallery-embed-caption></h2>'
            . '<button type="button" class="btn-close btn-close-white"'
            . ' data-bs-dismiss="modal" aria-label="Fechar"></button>'
            . '</div>'
            . '<div class="modal-body text-center pt-0">'
            . '<img data-gallery-embed-image src="" alt=""'
            . ' class="img-fluid" style="max-height:75vh">'
            . '</div>'
            . '<div class="modal-footer border-0 justify-content-between">'
            . '<button class="btn btn-outline-light" type="button"'
            . ' data-gallery-embed-prev>Anterior</button>'
            . '<span class="small" data-gallery-embed-counter></span>'
            . '<button class="btn btn-outline-light" type="button"'
            . ' data-gallery-embed-next>Próxima</button>'
            . '</div></div></div></div>';

        $html .= '<script>(function(){'
            . 'const root=document.getElementById('
            . json_encode($rootId)
            . ');'
            . 'const modal=document.getElementById('
            . json_encode($modalId)
            . ');'
            . 'if(!root||!modal)return;'
            . 'const photos=' . $json . ';'
            . 'let index=0;'
            . 'const image=modal.querySelector("[data-gallery-embed-image]");'
            . 'const caption=modal.querySelector("[data-gallery-embed-caption]");'
            . 'const counter=modal.querySelector("[data-gallery-embed-counter]");'
            . 'function show(i){'
            . 'if(!photos.length)return;'
            . 'index=(i+photos.length)%photos.length;'
            . 'const photo=photos[index];'
            . 'image.src=photo.src;image.alt=photo.alt||"";'
            . 'caption.textContent=photo.caption||"";'
            . 'counter.textContent=(index+1)+" / "+photos.length;'
            . '}'
            . 'root.querySelectorAll("[data-gallery-embed-index]").forEach(function(button){'
            . 'button.addEventListener("click",function(){show(Number(button.dataset.galleryEmbedIndex||0));});'
            . '});'
            . 'modal.querySelector("[data-gallery-embed-prev]")?.addEventListener("click",function(){show(index-1);});'
            . 'modal.querySelector("[data-gallery-embed-next]")?.addEventListener("click",function(){show(index+1);});'
            . 'document.addEventListener("keydown",function(event){'
            . 'if(!modal.classList.contains("show"))return;'
            . 'if(event.key==="ArrowLeft")show(index-1);'
            . 'if(event.key==="ArrowRight")show(index+1);'
            . '});'
            . '})();</script>';

        return $html;
    }

    private static function renderMostRead(
        PDO $pdo,
        array $data,
        string $instanceKey
    ): string {
        if (!class_exists('NewsAnalyticsService')) {
            return '';
        }

        $requested = max(
            1,
            min(100, (int)($data['limit'] ?? 10))
        );

        $period = in_array(
            (string)($data['period'] ?? 'total'),
            ['7', '30', 'total'],
            true
        )
            ? (string)($data['period'] ?? 'total')
            : 'total';

        $ranking = NewsAnalyticsService::ranking(
            $pdo,
            $period,
            $requested
        );

        $pagination = self::arrayPaginationState(
            count($ranking),
            $data,
            $instanceKey
        );

        $items = $pagination['query_limit'] > 0
            ? array_slice(
                $ranking,
                (int)$pagination['offset'],
                (int)$pagination['query_limit']
            )
            : [];

        foreach ($items as &$item) {
            $item['_url'] = contentUrl(
                'noticia',
                (string)$item['slug']
            );
            $item['_image'] = self::imageUrl(
                $item['imagem_capa_midia'] ?? ''
            );
            $item['_date'] = self::formatDate(
                $item['publicado_em']
                    ?? $item['created_at']
                    ?? null
            );
            $item['_excerpt'] = self::cut(
                trim(
                    preg_replace(
                        '/\s+/u',
                        ' ',
                        strip_tags(
                            (string)($item['resumo'] ?? '')
                        )
                    ) ?? ''
                ),
                170
            );

            if (!empty($data['show_views'])) {
                $views = (int)($item['visualizacoes_periodo'] ?? 0);

                $item['_meta'] = number_format(
                    $views,
                    0,
                    ',',
                    '.'
                )
                    . ' visualiza'
                    . ($views === 1 ? 'ção' : 'ções');
            }
        }
        unset($item);

        return self::renderCollection(
            (string)($data['title'] ?? 'Mais Lidas'),
            $items,
            (string)($data['layout'] ?? 'cards'),
            [
                'show_image' => true,
                'show_excerpt' => !empty($data['show_excerpt']),
                'show_date' => !empty($data['show_date']),
                'show_meta' => !empty($data['show_views']),
                'empty' => 'Ainda não há visualizações suficientes para este ranking.',
                'pagination' => $pagination,
            ]
        );
    }

    private static function renderLeadership(
        PDO $pdo,
        array $data,
        string $instanceKey
    ): string {
        if (!self::tableExists($pdo, 'liderancas')) {
            return '';
        }

        $where = ['l.ativo=1'];

        $type = self::validLeadershipType(
            (string)($data['leadership_type'] ?? '')
        );

        if ($type !== '') {
            $where[] = 'l.tipo=' . $pdo->quote($type);
        }

        $communityId = max(
            0,
            (int)($data['community_id'] ?? 0)
        );

        if ($communityId > 0) {
            $where[] = 'l.comunidade_id=' . $communityId;
        }

        $joins = '';
        $imageSelect =
            "'' AS imagem_capa_midia,'' AS imagem_capa_alt";

        if (self::tableExists($pdo, 'midias')) {
            $joins .= ' LEFT JOIN midias m ON m.id=l.foto_id';
            $imageSelect =
                "m.caminho AS imagem_capa_midia,"
                . "COALESCE(NULLIF(m.alt_text,''),l.nome) AS imagem_capa_alt";
        }

        $communitySelect = "'' AS comunidade_nome";

        if (self::tableExists($pdo, 'comunidades')) {
            $joins .= ' LEFT JOIN comunidades c ON c.id=l.comunidade_id';
            $communitySelect = 'c.nome AS comunidade_nome';
        }

        $fromWhere =
            " FROM liderancas l{$joins}"
            . ' WHERE '
            . implode(' AND ', $where);

        $pagination = self::paginationState(
            $pdo,
            'SELECT COUNT(*)' . $fromWhere,
            $data,
            $instanceKey
        );

        if ($pagination['query_limit'] <= 0) {
            $items = [];
        } else {
            $sql =
                "SELECT l.id,l.nome AS titulo,l.slug,l.tipo,l.funcao,l.resumo,"
                . "{$communitySelect},{$imageSelect}"
                . $fromWhere
                . ' ORDER BY l.ordem ASC,l.nome ASC,l.id ASC'
                . ' LIMIT ' . (int)$pagination['query_limit']
                . ' OFFSET ' . (int)$pagination['offset'];

            $items = $pdo->query($sql)->fetchAll() ?: [];
        }

        foreach ($items as &$item) {
            $item['_url'] = contentUrl(
                'lideranca',
                (string)$item['slug']
            );
            $item['_image'] = self::imageUrl(
                $item['imagem_capa_midia'] ?? ''
            );
            $item['_date'] = '';

            $parts = [];

            if (!empty($item['funcao'])) {
                $parts[] = (string)$item['funcao'];
            }

            if (!empty($item['comunidade_nome'])) {
                $parts[] = (string)$item['comunidade_nome'];
            }

            if (
                !empty($data['show_excerpt'])
                && !empty($item['resumo'])
            ) {
                $parts[] = self::cut(
                    trim(
                        preg_replace(
                            '/\s+/u',
                            ' ',
                            strip_tags(
                                (string)$item['resumo']
                            )
                        ) ?? ''
                    ),
                    140
                );
            }

            $item['_excerpt'] = implode(
                ' · ',
                $parts
            );
        }
        unset($item);

        return self::renderCollection(
            (string)($data['title'] ?? 'Lideranças'),
            $items,
            (string)($data['layout'] ?? 'cards'),
            [
                'show_image' => true,
                'show_excerpt' => true,
                'show_date' => false,
                'empty' => 'Nenhuma liderança encontrada.',
                'pagination' => $pagination,
            ]
        );
    }

    /**
     * Paginação de uma coleção que já veio pronta de outro serviço.
     *
     * @return array{
     *   enabled:bool,page:int,pages:int,page_size:int,total:int,
     *   requested:int,offset:int,query_limit:int,param:string,anchor:string
     * }
     */
    private static function arrayPaginationState(
        int $available,
        array $data,
        string $instanceKey
    ): array {
        $layout = (string)($data['layout'] ?? 'cards');
        $requested = max(
            1,
            (int)($data['limit'] ?? 4)
        );

        $pageSize = match ($layout) {
            'list' => 15,
            'cards' => 10,
            default => 0,
        };

        $total = min(
            max(0, $available),
            $requested
        );

        $token = substr(
            hash('sha256', $instanceKey),
            0,
            12
        );

        $param = 'bloco_' . $token . '_pagina';
        $anchor = 'bloco-' . $token;

        if ($pageSize <= 0) {
            return [
                'enabled' => false,
                'page' => 1,
                'pages' => 1,
                'page_size' => max(1, $requested),
                'total' => $total,
                'requested' => $requested,
                'offset' => 0,
                'query_limit' => $total,
                'param' => $param,
                'anchor' => $anchor,
            ];
        }

        $enabled = $requested > $pageSize
            && $total > $pageSize;

        if (!$enabled) {
            return [
                'enabled' => false,
                'page' => 1,
                'pages' => 1,
                'page_size' => $pageSize,
                'total' => $total,
                'requested' => $requested,
                'offset' => 0,
                'query_limit' => $total,
                'param' => $param,
                'anchor' => $anchor,
            ];
        }

        $pages = max(
            1,
            (int)ceil($total / $pageSize)
        );

        $page = max(
            1,
            (int)($_GET[$param] ?? 1)
        );

        $page = min(
            $page,
            $pages
        );

        $offset = ($page - 1) * $pageSize;

        return [
            'enabled' => true,
            'page' => $page,
            'pages' => $pages,
            'page_size' => $pageSize,
            'total' => $total,
            'requested' => $requested,
            'offset' => $offset,
            'query_limit' => min(
                $pageSize,
                max(0, $total - $offset)
            ),
            'param' => $param,
            'anchor' => $anchor,
        ];
    }

    /**
     * Calcula a página a consultar sem carregar todos os registros.
     *
     * Lista: 15 itens por página.
     * Card: 10 itens por página.
     *
     * @return array{
     *   enabled:bool,
     *   page:int,
     *   pages:int,
     *   page_size:int,
     *   total:int,
     *   requested:int,
     *   offset:int,
     *   query_limit:int,
     *   param:string,
     *   anchor:string
     * }
     */
    private static function paginationState(
        PDO $pdo,
        string $countSql,
        array $data,
        string $instanceKey
    ): array {
        $layout = (string)($data['layout'] ?? 'cards');
        $requested = max(
            1,
            (int)($data['limit'] ?? 4)
        );

        $pageSize = match ($layout) {
            'list' => 15,
            'cards' => 10,
            default => 0,
        };

        $available = 0;
        try {
            $available = max(
                0,
                (int)$pdo->query($countSql)->fetchColumn()
            );
        } catch (Throwable $e) {
            $available = 0;
        }

        $total = min(
            $available,
            $requested
        );

        $token = substr(
            hash('sha256', $instanceKey),
            0,
            12
        );
        $param = 'bloco_' . $token . '_pagina';
        $anchor = 'bloco-' . $token;

        // Carrossel, Grade e Destaque + 2 mantêm o comportamento de até
        // 12 itens e não recebem paginação nesta versão.
        if ($pageSize <= 0) {
            return [
                'enabled' => false,
                'page' => 1,
                'pages' => 1,
                'page_size' => max(1, $requested),
                'total' => $total,
                'requested' => $requested,
                'offset' => 0,
                'query_limit' => $total,
                'param' => $param,
                'anchor' => $anchor,
            ];
        }

        $enabled = $requested > $pageSize
            && $total > $pageSize;

        if (!$enabled) {
            return [
                'enabled' => false,
                'page' => 1,
                'pages' => 1,
                'page_size' => $pageSize,
                'total' => $total,
                'requested' => $requested,
                'offset' => 0,
                'query_limit' => $total,
                'param' => $param,
                'anchor' => $anchor,
            ];
        }

        $pages = max(
            1,
            (int)ceil($total / $pageSize)
        );

        $page = max(
            1,
            (int)($_GET[$param] ?? 1)
        );
        $page = min($page, $pages);

        $offset = ($page - 1) * $pageSize;
        $queryLimit = max(
            0,
            min(
                $pageSize,
                $total - $offset
            )
        );

        return [
            'enabled' => true,
            'page' => $page,
            'pages' => $pages,
            'page_size' => $pageSize,
            'total' => $total,
            'requested' => $requested,
            'offset' => $offset,
            'query_limit' => $queryLimit,
            'param' => $param,
            'anchor' => $anchor,
        ];
    }

    /**
     * @param array<string,mixed> $pagination
     */
    private static function renderPagination(
        array $pagination
    ): string {
        if (
            empty($pagination['enabled'])
            || (int)($pagination['pages'] ?? 1) <= 1
        ) {
            return '';
        }

        $page = max(
            1,
            (int)($pagination['page'] ?? 1)
        );
        $pages = max(
            1,
            (int)($pagination['pages'] ?? 1)
        );
        $param = (string)($pagination['param'] ?? '');
        $anchor = (string)($pagination['anchor'] ?? '');

        if ($param === '' || $anchor === '') {
            return '';
        }

        $numbers = self::paginationNumbers(
            $page,
            $pages
        );

        $html = '<nav class="mt-4" aria-label="Paginação do bloco">'
            . '<ul class="pagination justify-content-center flex-wrap mb-0">';

        $html .= self::paginationItem(
            $param,
            max(1, $page - 1),
            $anchor,
            'Anterior',
            $page <= 1
        );

        $previousNumber = null;

        foreach ($numbers as $number) {
            if (
                $previousNumber !== null
                && $number > $previousNumber + 1
            ) {
                $html .= '<li class="page-item disabled">'
                    . '<span class="page-link">…</span>'
                    . '</li>';
            }

            $html .= self::paginationItem(
                $param,
                $number,
                $anchor,
                (string)$number,
                false,
                $number === $page
            );

            $previousNumber = $number;
        }

        $html .= self::paginationItem(
            $param,
            min($pages, $page + 1),
            $anchor,
            'Próxima',
            $page >= $pages
        );

        $html .= '</ul></nav>';

        return $html;
    }

    /**
     * @return array<int,int>
     */
    private static function paginationNumbers(
        int $page,
        int $pages
    ): array {
        $numbers = [
            1,
            $pages,
        ];

        for (
            $number = max(1, $page - 2);
            $number <= min($pages, $page + 2);
            $number++
        ) {
            $numbers[] = $number;
        }

        $numbers = array_values(
            array_unique($numbers)
        );
        sort($numbers);

        return $numbers;
    }

    private static function paginationItem(
        string $param,
        int $page,
        string $anchor,
        string $label,
        bool $disabled = false,
        bool $active = false
    ): string {
        $classes = ['page-item'];
        if ($disabled) {
            $classes[] = 'disabled';
        }
        if ($active) {
            $classes[] = 'active';
        }

        $class = implode(' ', $classes);

        if ($disabled) {
            return '<li class="' . self::e($class) . '">'
                . '<span class="page-link">'
                . self::e($label)
                . '</span></li>';
        }

        return '<li class="' . self::e($class) . '">'
            . '<a class="page-link" href="'
            . self::e(
                self::paginationUrl(
                    $param,
                    $page,
                    $anchor
                )
            )
            . '">'
            . self::e($label)
            . '</a></li>';
    }

    private static function paginationUrl(
        string $param,
        int $page,
        string $anchor
    ): string {
        $query = $_GET;

        if ($page <= 1) {
            unset($query[$param]);
        } else {
            $query[$param] = $page;
        }

        $requestUri = (string)(
            $_SERVER['REQUEST_URI']
            ?? '/'
        );

        $path = parse_url(
            $requestUri,
            PHP_URL_PATH
        );

        $path = is_string($path) && $path !== ''
            ? $path
            : '/';

        $queryString = http_build_query(
            $query,
            '',
            '&',
            PHP_QUERY_RFC3986
        );

        return $path
            . ($queryString !== '' ? '?' . $queryString : '')
            . '#'
            . rawurlencode($anchor);
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @param array<string,mixed> $options
     */
    private static function renderCollection(
        string $title,
        array $items,
        string $layout,
        array $options
    ): string {
        $title = trim($title);
        $layout = in_array(
            $layout,
            ['cards','list','carousel','grid','featured2'],
            true
        )
            ? $layout
            : 'cards';

        $pagination = is_array($options['pagination'] ?? null)
            ? $options['pagination']
            : [];
        $sectionAnchor = trim(
            (string)($pagination['anchor'] ?? '')
        );

        $html = '<section'
            . ($sectionAnchor !== ''
                ? ' id="' . self::e($sectionAnchor) . '"'
                : '')
            . ' class="portal-dynamic-block my-5">';

        if ($title !== '') {
            $html .= '<div class="d-flex align-items-center justify-content-between gap-3 mb-3">'
                . '<h2 class="h3 mb-0">'
                . self::e($title)
                . '</h2></div>';
        }

        if (!$items) {
            $html .= '<div class="alert alert-light border mb-0">'
                . self::e((string)($options['empty'] ?? 'Nenhum conteúdo encontrado.'))
                . '</div></section>';
            return $html;
        }

        if ($layout === 'list') {
            $html .= '<div class="list-group">';
            foreach ($items as $item) {
                $html .= self::renderListItem($item, $options);
            }
            $html .= '</div>';
            $html .= self::renderPagination(
                (array)($options['pagination'] ?? [])
            );
            $html .= '</section>';
            return $html;
        }

        if ($layout === 'carousel') {
            $html .= self::renderCarousel($items, $options);
            $html .= self::renderPagination(
                (array)($options['pagination'] ?? [])
            );
            $html .= '</section>';
            return $html;
        }

        if ($layout === 'featured2') {
            $html .= self::renderFeaturedPlusTwo($items, $options);
            $html .= self::renderPagination(
                (array)($options['pagination'] ?? [])
            );
            $html .= '</section>';
            return $html;
        }

        if ($layout === 'grid') {
            $html .= '<div class="row g-4">';
            foreach ($items as $item) {
                $html .= '<div class="col-sm-6 col-lg-4">'
                    . self::renderCard($item, $options, true)
                    . '</div>';
            }
            $html .= '</div>';
            $html .= self::renderPagination(
                (array)($options['pagination'] ?? [])
            );
            $html .= '</section>';
            return $html;
        }

        $html .= '<div class="row g-3">';
        foreach ($items as $item) {
            $html .= '<div class="col-md-6 col-xl-4">'
                . self::renderCard($item, $options)
                . '</div>';
        }
        $html .= '</div>';
        $html .= self::renderPagination(
            (array)($options['pagination'] ?? [])
        );
        $html .= '</section>';

        return $html;
    }

    /** @param array<string,mixed> $options */
    private static function renderCard(
        array $item,
        array $options,
        bool $grid = false
    ): string {
        $url = (string)($item['_url'] ?? '#');
        $title = (string)($item['titulo'] ?? '');
        $image = (string)($item['_image'] ?? '');
        $date = (string)($item['_date'] ?? '');
        $excerpt = (string)($item['_excerpt'] ?? '');

        $html = '<article class="card h-100 border-0 shadow-sm overflow-hidden">';

        if (!empty($options['show_image'])) {
            if ($image !== '') {
                $html .= '<a href="' . self::e($url) . '" class="d-block">'
                    . '<img src="' . self::e($image) . '" alt="'
                    . self::e((string)($item['imagem_capa_alt'] ?? $title))
                    . '" class="card-img-top" loading="lazy"'
                    . ' style="height:' . ($grid ? '220px' : '190px') . ';object-fit:cover">'
                    . '</a>';
            } else {
                $html .= '<div class="bg-body-tertiary d-flex align-items-center justify-content-center text-secondary"'
                    . ' style="height:190px"><i class="bi bi-image fs-2"></i></div>';
            }
        }

        $html .= '<div class="card-body">';

        if (!empty($options['show_date']) && $date !== '') {
            $html .= '<div class="small text-secondary mb-2">'
                . self::e($date)
                . '</div>';
        }

        $html .= '<h3 class="h5 card-title">'
            . '<a class="text-decoration-none text-body" href="'
            . self::e($url)
            . '">' . self::e($title) . '</a></h3>';

        if (!empty($options['show_excerpt']) && $excerpt !== '') {
            $html .= '<p class="card-text text-secondary">'
                . self::e($excerpt)
                . '</p>';
        }

        if (
            !empty($options['show_meta'])
            && !empty($item['_meta'])
        ) {
            $html .= '<div class="small text-secondary mt-2">'
                . '<i class="bi bi-eye me-1"></i>'
                . self::e((string)$item['_meta'])
                . '</div>';
        }

        $html .= '</div></article>';
        return $html;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @param array<string,mixed> $options
     */
    private static function renderCarousel(
        array $items,
        array $options
    ): string {
        $id = 'portalDynamicCarousel'
            . substr(
                hash(
                    'sha256',
                    json_encode($items, JSON_UNESCAPED_UNICODE)
                    . microtime(true)
                    . random_int(1, PHP_INT_MAX)
                ),
                0,
                12
            );

        $slides = array_chunk($items, 3);

        $html = '<div id="' . self::e($id)
            . '" class="carousel slide portal-dynamic-carousel">'
            . '<div class="carousel-inner">';

        foreach ($slides as $slideIndex => $slideItems) {
            $html .= '<div class="carousel-item '
                . ($slideIndex === 0 ? 'active' : '')
                . '"><div class="row g-3">';

            foreach ($slideItems as $item) {
                $html .= '<div class="col-md-4">'
                    . self::renderCard($item, $options, true)
                    . '</div>';
            }

            $html .= '</div></div>';
        }

        $html .= '</div>';

        if (count($slides) > 1) {
            $html .= '<button class="carousel-control-prev" type="button"'
                . ' data-bs-target="#' . self::e($id) . '" data-bs-slide="prev"'
                . ' style="width:44px;left:-18px">'
                . '<span class="carousel-control-prev-icon bg-dark rounded-circle p-3" aria-hidden="true"></span>'
                . '<span class="visually-hidden">Anterior</span>'
                . '</button>'
                . '<button class="carousel-control-next" type="button"'
                . ' data-bs-target="#' . self::e($id) . '" data-bs-slide="next"'
                . ' style="width:44px;right:-18px">'
                . '<span class="carousel-control-next-icon bg-dark rounded-circle p-3" aria-hidden="true"></span>'
                . '<span class="visually-hidden">Próximo</span>'
                . '</button>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @param array<string,mixed> $options
     */
    private static function renderFeaturedPlusTwo(
        array $items,
        array $options
    ): string {
        $featured = array_shift($items);

        if (!$featured) {
            return '';
        }

        $secondary = array_slice($items, 0, 2);

        $html = '<div class="row g-3 align-items-stretch">'
            . '<div class="col-lg-8">'
            . self::renderFeaturedCard($featured, $options)
            . '</div>'
            . '<div class="col-lg-4"><div class="d-grid gap-3 h-100">';

        foreach ($secondary as $item) {
            $html .= self::renderCompactFeaturedCard($item, $options);
        }

        if (!$secondary) {
            $html .= '<div class="h-100"></div>';
        }

        $html .= '</div></div></div>';

        return $html;
    }

    /** @param array<string,mixed> $options */
    private static function renderFeaturedCard(
        array $item,
        array $options
    ): string {
        $url = (string)($item['_url'] ?? '#');
        $title = (string)($item['titulo'] ?? '');
        $image = (string)($item['_image'] ?? '');
        $date = (string)($item['_date'] ?? '');
        $excerpt = (string)($item['_excerpt'] ?? '');

        $style = $image !== ''
            ? "background-image:linear-gradient(to top,rgba(0,0,0,.78),rgba(0,0,0,.06)),url('"
                . self::e($image)
                . "');background-size:cover;background-position:center;"
            : 'background:linear-gradient(145deg,#e9ecef,#adb5bd);';

        $html = '<a href="' . self::e($url)
            . '" class="text-white text-decoration-none d-flex align-items-end rounded overflow-hidden shadow-sm h-100"'
            . ' style="min-height:430px;' . $style . '">'
            . '<span class="p-4 w-100">';

        if (!empty($options['show_date']) && $date !== '') {
            $html .= '<small class="d-block mb-2 opacity-75">'
                . self::e($date)
                . '</small>';
        }

        $html .= '<strong class="display-6 d-block lh-sm">'
            . self::e($title)
            . '</strong>';

        if (!empty($options['show_excerpt']) && $excerpt !== '') {
            $html .= '<span class="d-block mt-3 opacity-90">'
                . self::e($excerpt)
                . '</span>';
        }

        $html .= '</span></a>';

        return $html;
    }

    /** @param array<string,mixed> $options */
    private static function renderCompactFeaturedCard(
        array $item,
        array $options
    ): string {
        $url = (string)($item['_url'] ?? '#');
        $title = (string)($item['titulo'] ?? '');
        $image = (string)($item['_image'] ?? '');
        $date = (string)($item['_date'] ?? '');

        $style = $image !== ''
            ? "background-image:linear-gradient(to top,rgba(0,0,0,.8),rgba(0,0,0,.08)),url('"
                . self::e($image)
                . "');background-size:cover;background-position:center;"
            : 'background:linear-gradient(145deg,#e9ecef,#868e96);';

        $html = '<a href="' . self::e($url)
            . '" class="text-white text-decoration-none d-flex align-items-end rounded overflow-hidden shadow-sm"'
            . ' style="min-height:205px;' . $style . '">'
            . '<span class="p-3">';

        if (!empty($options['show_date']) && $date !== '') {
            $html .= '<small class="d-block mb-1 opacity-75">'
                . self::e($date)
                . '</small>';
        }

        $html .= '<strong class="h5 d-block mb-0">'
            . self::e($title)
            . '</strong></span></a>';

        return $html;
    }

    /** @param array<string,mixed> $options */
    private static function renderListItem(array $item, array $options): string
    {
        $url = (string)($item['_url'] ?? '#');
        $title = (string)($item['titulo'] ?? '');
        $image = (string)($item['_image'] ?? '');
        $date = (string)($item['_date'] ?? '');
        $excerpt = (string)($item['_excerpt'] ?? '');
        $icon = (string)($options['icon'] ?? 'bi-chevron-right');

        $html = '<a href="' . self::e($url)
            . '" class="list-group-item list-group-item-action py-3">'
            . '<div class="d-flex gap-3 align-items-start">';

        if (!empty($options['show_image']) && $image !== '') {
            $html .= '<img src="' . self::e($image)
                . '" alt="" loading="lazy" class="rounded flex-shrink-0"'
                . ' style="width:112px;height:76px;object-fit:cover">';
        } else {
            $html .= '<span class="text-secondary flex-shrink-0 pt-1">'
                . '<i class="bi ' . self::e($icon) . ' fs-4"></i>'
                . '</span>';
        }

        $html .= '<span class="flex-grow-1 min-w-0">';

        if (!empty($options['show_date']) && $date !== '') {
            $html .= '<small class="text-secondary d-block mb-1">'
                . self::e($date)
                . '</small>';
        }

        $html .= '<strong class="d-block">'
            . self::e($title)
            . '</strong>';

        if (!empty($options['show_excerpt']) && $excerpt !== '') {
            $html .= '<small class="text-secondary d-block mt-1">'
                . self::e($excerpt)
                . '</small>';
        }

        if (
            !empty($options['show_meta'])
            && !empty($item['_meta'])
        ) {
            $html .= '<small class="text-secondary d-block mt-1">'
                . '<i class="bi bi-eye me-1"></i>'
                . self::e((string)$item['_meta'])
                . '</small>';
        }

        $html .= '</span></div></a>';

        return $html;
    }

    private static function defaultTitle(string $type): string
    {
        return match ($type) {
            'portal_posts' => 'Últimas Notícias',
            'portal_events' => 'Agenda',
            'portal_documents' => 'Documentos',
            'portal_galleries' => 'Galerias',
            'portal_communities' => 'Comunidades',
            'portal_most_read' => 'Mais Lidas',
            'portal_leadership' => 'Lideranças',
            'portal_gallery_embed' => 'Galeria',
            default => 'Conteúdo do Portal',
        };
    }

    /** @return array<int,array{id:string,nome:string}> */
    private static function leadershipTypeOptions(): array
    {
        $labels = class_exists('LeadershipService')
            ? LeadershipService::typeLabels()
            : [
                'pastoral' => 'Ministério Pastoral',
                'presbiterio' => 'Presbitério',
                'lideranca' => 'Liderança',
                'equipe' => 'Equipe',
                'outro' => 'Outro',
            ];

        $out = [];

        foreach ($labels as $id => $name) {
            $out[] = [
                'id' => (string)$id,
                'nome' => (string)$name,
            ];
        }

        return $out;
    }

    private static function validLeadershipType(
        string $type
    ): string {
        $type = strtolower(
            trim($type)
        );

        if ($type === '') {
            return '';
        }

        foreach (self::leadershipTypeOptions() as $option) {
            if ((string)$option['id'] === $type) {
                return $type;
            }
        }

        return '';
    }

    private static function validId(
        PDO $pdo,
        string $table,
        int $id
    ): int {
        if ($id <= 0 || !self::tableExists($pdo, $table)) {
            return 0;
        }

        $stmt = $pdo->prepare(
            'SELECT id FROM `' . str_replace('`', '``', $table)
            . '` WHERE id=:id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);

        return $stmt->fetchColumn() ? $id : 0;
    }

    /** @return array<int,array{id:int,nome:string}> */
    private static function simpleOptions(
        PDO $pdo,
        string $table,
        string $labelColumn,
        string $idColumn,
        string $order,
        string $where = ''
    ): array {
        if (!self::tableExists($pdo, $table)) {
            return [];
        }

        $sql = 'SELECT `'
            . str_replace('`', '``', $idColumn)
            . '` AS id,`'
            . str_replace('`', '``', $labelColumn)
            . '` AS nome FROM `'
            . str_replace('`', '``', $table)
            . '`';

        if ($where !== '') {
            $sql .= ' WHERE ' . $where;
        }

        $sql .= ' ORDER BY ' . $order;

        try {
            return $pdo->query($sql)->fetchAll() ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }

    private static function imageUrl(mixed $value): string
    {
        $value = trim((string)$value);
        if ($value === '') return '';

        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }

        return mediaUrl($value);
    }

    private static function excerpt(
        string $summary,
        string $content
    ): string {
        $text = trim(strip_tags($summary));
        if ($text === '') {
            $text = trim(strip_tags($content));
        }
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return self::cut($text, 170);
    }

    private static function formatDate(mixed $value): string
    {
        $value = trim((string)$value);
        if ($value === '') return '';

        try {
            return (new DateTimeImmutable($value))->format('d/m/Y');
        } catch (Throwable $e) {
            return '';
        }
    }

    private static function formatDateTime(mixed $value): string
    {
        $value = trim((string)$value);
        if ($value === '') return '';

        try {
            return (new DateTimeImmutable($value))->format('d/m/Y H:i');
        } catch (Throwable $e) {
            return '';
        }
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        $key = spl_object_id($pdo) . ':' . $table;
        if (array_key_exists($key, self::$tableCache)) {
            return self::$tableCache[$key];
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.tables'
                . ' WHERE table_schema=DATABASE() AND table_name=?'
            );
            $stmt->execute([$table]);
            return self::$tableCache[$key] =
                ((int)$stmt->fetchColumn() > 0);
        } catch (Throwable $e) {
            return self::$tableCache[$key] = false;
        }
    }

    private static function columnExists(
        PDO $pdo,
        string $table,
        string $column
    ): bool {
        if (!self::tableExists($pdo, $table)) {
            return false;
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.columns'
                . ' WHERE table_schema=DATABASE()'
                . ' AND table_name=? AND column_name=?'
            );
            $stmt->execute([$table, $column]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }

    private static function cut(string $value, int $length): string
    {
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $length)
            : substr($value, 0, $length);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars(
            $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
    }
}
