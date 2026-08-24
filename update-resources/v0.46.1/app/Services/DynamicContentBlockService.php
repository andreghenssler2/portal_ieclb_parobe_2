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
        $title = self::cut(
            trim((string)($data['title'] ?? self::defaultTitle($type))),
            180
        );

        $limit = max(1, min(12, (int)($data['limit'] ?? 4)));

        $layout = (string)($data['layout'] ?? 'cards');
        if (!in_array(
            $layout,
            ['cards', 'list', 'carousel', 'grid', 'featured2'],
            true
        )) {
            $layout = 'cards';
        }

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
            default => $common,
        };
    }

    public static function render(
        PDO $pdo,
        string $type,
        array $data
    ): string {
        try {
            return match ($type) {
                'portal_posts' => self::renderPosts($pdo, $data),
                'portal_events' => self::renderEvents($pdo, $data),
                'portal_documents' => self::renderDocuments($pdo, $data),
                'portal_galleries' => self::renderGalleries($pdo, $data),
                'portal_communities' => self::renderCommunities($pdo, $data),
                default => '',
            };
        } catch (Throwable $e) {
            // Um bloco dinâmico nunca deve derrubar a Página/Notícia pública.
            return '';
        }
    }

    private static function renderPosts(PDO $pdo, array $data): string
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

        $sql =
            "SELECT p.id,p.titulo,p.slug,p.resumo,p.conteudo,"
            . "p.publicado_em,p.created_at,{$imageSelect}"
            . " FROM posts p{$joins}"
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY COALESCE(p.publicado_em,p.created_at) DESC,p.id DESC'
            . ' LIMIT ' . $limit;

        $items = $pdo->query($sql)->fetchAll() ?: [];

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
            ]
        );
    }

    private static function renderEvents(PDO $pdo, array $data): string
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

        $sql =
            "SELECT e.id,e.titulo,e.slug,e.resumo,e.local,e.data_inicio,"
            . "{$communitySelect},{$imageSelect}"
            . " FROM eventos e{$joins}"
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY e.data_inicio ASC,e.id ASC'
            . ' LIMIT ' . $limit;

        $items = $pdo->query($sql)->fetchAll() ?: [];

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
            ]
        );
    }

    private static function renderDocuments(PDO $pdo, array $data): string
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

        $sql =
            "SELECT d.id,d.titulo,d.slug,d.descricao,d.publicado_em,d.created_at,"
            . "{$categorySelect},{$fileSelect}"
            . " FROM documentos d{$joins}"
            . ' WHERE ' . implode(' AND ', $where)
            . ' ORDER BY d.ordem ASC,COALESCE(d.publicado_em,d.created_at) DESC,d.id DESC'
            . ' LIMIT ' . $limit;

        $items = $pdo->query($sql)->fetchAll() ?: [];

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
            ]
        );
    }

    private static function renderGalleries(PDO $pdo, array $data): string
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

        $sql =
            "SELECT g.id,g.titulo,g.slug,g.descricao,g.publicado_em,g.created_at,"
            . "{$imageSelect}"
            . " FROM galerias g{$joins}"
            . " WHERE g.status='publicado'"
            . ' AND (g.publicado_em IS NULL OR g.publicado_em<=NOW())'
            . ' ORDER BY COALESCE(g.publicado_em,g.created_at) DESC,g.id DESC'
            . ' LIMIT ' . $limit;

        $items = $pdo->query($sql)->fetchAll() ?: [];

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
            ]
        );
    }

    private static function renderCommunities(PDO $pdo, array $data): string
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

        $sql =
            "SELECT c.id,c.nome AS titulo,c.slug,c.descricao,c.endereco,c.cidade,c.uf,"
            . "{$imageSelect}"
            . " FROM comunidades c{$joins}"
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY c.ordem ASC,c.nome ASC,c.id ASC'
            . ' LIMIT ' . $limit;

        $items = $pdo->query($sql)->fetchAll() ?: [];

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
            ]
        );
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

        $html = '<section class="portal-dynamic-block my-5">';

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
            $html .= '</div></section>';
            return $html;
        }

        if ($layout === 'carousel') {
            $html .= self::renderCarousel($items, $options);
            $html .= '</section>';
            return $html;
        }

        if ($layout === 'featured2') {
            $html .= self::renderFeaturedPlusTwo($items, $options);
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
            $html .= '</div></section>';
            return $html;
        }

        $html .= '<div class="row g-3">';
        foreach ($items as $item) {
            $html .= '<div class="col-md-6 col-xl-4">'
                . self::renderCard($item, $options)
                . '</div>';
        }
        $html .= '</div></section>';

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
            default => 'Conteúdo do Portal',
        };
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
