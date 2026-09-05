<?php

declare(strict_types=1);

/**
 * Busca administrativa avançada v0.86.
 *
 * Pesquisa isoladamente em cada módulo para que uma tabela opcional ou
 * diferença de versão não derrube toda a consulta.
 */
final class AdminAdvancedSearchService
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{
     *   query:string,
     *   total:int,
     *   results:array<int,array<string,mixed>>,
     *   sections:array<string,int>,
     *   truncated:bool
     * }
     */
    public function search(
        array $filters
    ): array {
        $query =
            trim(
                (string)(
                    $filters['q']
                    ?? ''
                )
            );

        $module =
            trim(
                (string)(
                    $filters['modulo']
                    ?? 'todos'
                )
            );

        $status =
            trim(
                (string)(
                    $filters['status']
                    ?? ''
                )
            );

        $dateFrom =
            $this->dateOrNull(
                (string)(
                    $filters['data_de']
                    ?? ''
                )
            );

        $dateTo =
            $this->dateOrNull(
                (string)(
                    $filters['data_ate']
                    ?? ''
                )
            );

        $onlyTitle =
            !empty(
                $filters['somente_titulo']
            );

        $sort =
            trim(
                (string)(
                    $filters['ordem']
                    ?? 'recentes'
                )
            );

        if (
            !in_array(
                $sort,
                [
                    'recentes',
                    'antigos',
                    'titulo',
                ],
                true
            )
        ) {
            $sort = 'recentes';
        }

        $limitPerModule =
            max(
                5,
                min(
                    50,
                    (int)(
                        $filters['limite']
                        ?? 25
                    )
                )
            );

        if (
            $query === ''
            && $module === 'todos'
            && $status === ''
            && $dateFrom === null
            && $dateTo === null
        ) {
            return [
                'query' => '',
                'total' => 0,
                'results' => [],
                'sections' => [],
                'truncated' => false,
            ];
        }

        $modules =
            $this->allowedModules();

        if (
            $module !== 'todos'
        ) {
            $modules =
                isset(
                    $modules[$module]
                )
                    ? [
                        $module =>
                            $modules[$module],
                    ]
                    : [];
        }

        $results = [];
        $sections = [];
        $truncated = false;

        foreach (
            $modules
            as $key => $label
        ) {
            $rows =
                $this->searchModule(
                    $key,
                    $query,
                    $status,
                    $dateFrom,
                    $dateTo,
                    $onlyTitle,
                    $limitPerModule + 1
                );

            if (
                count($rows)
                > $limitPerModule
            ) {
                $truncated = true;

                $rows =
                    array_slice(
                        $rows,
                        0,
                        $limitPerModule
                    );
            }

            if ($rows) {
                $sections[$label] =
                    count($rows);

                $results =
                    array_merge(
                        $results,
                        $rows
                    );
            }
        }

        usort(
            $results,
            static function (
                array $a,
                array $b
            ) use ($sort): int {
                if ($sort === 'titulo') {
                    return
                        strcasecmp(
                            (string)(
                                $a['label']
                                ?? ''
                            ),
                            (string)(
                                $b['label']
                                ?? ''
                            )
                        );
                }

                $aTime =
                    strtotime(
                        (string)(
                            $a['date_sort']
                            ?? ''
                        )
                    )
                    ?: 0;

                $bTime =
                    strtotime(
                        (string)(
                            $b['date_sort']
                            ?? ''
                        )
                    )
                    ?: 0;

                if ($aTime === $bTime) {
                    return
                        ((int)(
                            $b['id']
                            ?? 0
                        ))
                        <=>
                        ((int)(
                            $a['id']
                            ?? 0
                        ));
                }

                return
                    $sort === 'antigos'
                        ? $aTime <=> $bTime
                        : $bTime <=> $aTime;
            }
        );

        return [
            'query' => $query,
            'total' => count($results),
            'results' => $results,
            'sections' => $sections,
            'truncated' => $truncated,
        ];
    }

    /**
     * @return array<string,string>
     */
    public function allowedModules(): array
    {
        $modules = [];

        if (
            Auth::can(
                'noticias.gerenciar'
            )
        ) {
            $modules['noticias'] =
                'Notícias';
        }

        if (
            Auth::can(
                'paginas.gerenciar'
            )
        ) {
            $modules['paginas'] =
                'Páginas';
        }

        if (
            Auth::can(
                'eventos.gerenciar'
            )
        ) {
            $modules['eventos'] =
                'Eventos';
        }

        if (
            Auth::can(
                'documentos.gerenciar'
            )
        ) {
            $modules['documentos'] =
                'Documentos';
        }

        if (
            Auth::can(
                'midias.gerenciar'
            )
        ) {
            $modules['midias'] =
                'Mídia';
        }

        if (
            Auth::can(
                'usuarios.gerenciar'
            )
        ) {
            $modules['usuarios'] =
                'Usuários';
        }

        if (
            Auth::can(
                'comunidades.gerenciar'
            )
        ) {
            $modules['comunidades'] =
                'Comunidades';
        }

        return $modules;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function searchModule(
        string $module,
        string $query,
        string $status,
        ?string $dateFrom,
        ?string $dateTo,
        bool $onlyTitle,
        int $limit
    ): array {
        try {
            return
                match ($module) {
                    'noticias' =>
                        $this->posts(
                            $query,
                            $status,
                            $dateFrom,
                            $dateTo,
                            $onlyTitle,
                            $limit
                        ),

                    'paginas' =>
                        $this->pages(
                            $query,
                            $status,
                            $dateFrom,
                            $dateTo,
                            $onlyTitle,
                            $limit
                        ),

                    'eventos' =>
                        $this->events(
                            $query,
                            $status,
                            $dateFrom,
                            $dateTo,
                            $onlyTitle,
                            $limit
                        ),

                    'documentos' =>
                        $this->documents(
                            $query,
                            $status,
                            $dateFrom,
                            $dateTo,
                            $onlyTitle,
                            $limit
                        ),

                    'midias' =>
                        $this->media(
                            $query,
                            $dateFrom,
                            $dateTo,
                            $onlyTitle,
                            $limit
                        ),

                    'usuarios' =>
                        $this->users(
                            $query,
                            $status,
                            $dateFrom,
                            $dateTo,
                            $onlyTitle,
                            $limit
                        ),

                    'comunidades' =>
                        $this->communities(
                            $query,
                            $status,
                            $dateFrom,
                            $dateTo,
                            $onlyTitle,
                            $limit
                        ),

                    default => [],
                };
        } catch (Throwable $ignored) {
            return [];
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function posts(
        string $query,
        string $status,
        ?string $dateFrom,
        ?string $dateTo,
        bool $onlyTitle,
        int $limit
    ): array {
        $where = [
            "status <> 'lixeira'",
        ];

        $params = [];

        $this->appendTextFilter(
            $where,
            $params,
            $query,
            $onlyTitle
                ? [
                    'titulo',
                ]
                : [
                    'titulo',
                    'slug',
                    "COALESCE(resumo,'')",
                    "COALESCE(conteudo,'')",
                ]
        );

        $this->appendStatus(
            $where,
            $params,
            $status,
            [
                'rascunho',
                'agendado',
                'publicado',
                'arquivado',
            ]
        );

        $this->appendDateRange(
            $where,
            $params,
            "COALESCE(publicado_em,updated_at,created_at)",
            $dateFrom,
            $dateTo
        );

        $stmt =
            $this->pdo->prepare(
                "SELECT
                    id,
                    titulo,
                    slug,
                    status,
                    resumo,
                    COALESCE(
                        publicado_em,
                        updated_at,
                        created_at
                    ) AS data_ref
                 FROM posts
                 WHERE "
                 . implode(
                    ' AND ',
                    $where
                 )
                 . "
                 ORDER BY data_ref DESC,id DESC
                 LIMIT {$limit}"
            );

        $stmt->execute($params);

        $rows =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            ) ?: [];

        return
            array_map(
                static function (
                    array $row
                ): array {
                    return [
                        'id' =>
                            (int)$row['id'],
                        'section' =>
                            'Notícias',
                        'type' =>
                            'noticia',
                        'label' =>
                            (string)$row['titulo'],
                        'subtitle' =>
                            portalExcerpt(
                                (string)(
                                    $row['resumo']
                                    ?? ''
                                ),
                                160
                            ),
                        'status' =>
                            (string)(
                                $row['status']
                                ?? ''
                            ),
                        'date_sort' =>
                            (string)(
                                $row['data_ref']
                                ?? ''
                            ),
                        'icon' =>
                            'bi-newspaper',
                        'url' =>
                            url(
                                'admin/noticias/form.php?id='
                                . (int)$row['id']
                            ),
                    ];
                },
                $rows
            );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function pages(
        string $query,
        string $status,
        ?string $dateFrom,
        ?string $dateTo,
        bool $onlyTitle,
        int $limit
    ): array {
        $where = [
            "status <> 'lixeira'",
        ];

        $params = [];

        $this->appendTextFilter(
            $where,
            $params,
            $query,
            $onlyTitle
                ? [
                    'titulo',
                ]
                : [
                    'titulo',
                    'slug',
                    "COALESCE(resumo,'')",
                    "COALESCE(conteudo,'')",
                ]
        );

        $this->appendStatus(
            $where,
            $params,
            $status,
            [
                'rascunho',
                'agendado',
                'publicado',
                'arquivado',
            ]
        );

        $this->appendDateRange(
            $where,
            $params,
            "COALESCE(updated_at,created_at)",
            $dateFrom,
            $dateTo
        );

        $stmt =
            $this->pdo->prepare(
                "SELECT
                    id,
                    titulo,
                    slug,
                    status,
                    resumo,
                    COALESCE(updated_at,created_at) AS data_ref
                 FROM paginas
                 WHERE "
                 . implode(
                    ' AND ',
                    $where
                 )
                 . "
                 ORDER BY data_ref DESC,id DESC
                 LIMIT {$limit}"
            );

        $stmt->execute($params);

        $rows =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            ) ?: [];

        return
            array_map(
                static function (
                    array $row
                ): array {
                    return [
                        'id' =>
                            (int)$row['id'],
                        'section' =>
                            'Páginas',
                        'type' =>
                            'pagina',
                        'label' =>
                            (string)$row['titulo'],
                        'subtitle' =>
                            portalExcerpt(
                                (string)(
                                    $row['resumo']
                                    ?? ''
                                ),
                                160
                            ),
                        'status' =>
                            (string)(
                                $row['status']
                                ?? ''
                            ),
                        'date_sort' =>
                            (string)(
                                $row['data_ref']
                                ?? ''
                            ),
                        'icon' =>
                            'bi-files',
                        'url' =>
                            url(
                                'admin/paginas/form.php?id='
                                . (int)$row['id']
                            ),
                    ];
                },
                $rows
            );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function events(
        string $query,
        string $status,
        ?string $dateFrom,
        ?string $dateTo,
        bool $onlyTitle,
        int $limit
    ): array {
        $where = [
            "status <> 'lixeira'",
        ];

        $params = [];

        $this->appendTextFilter(
            $where,
            $params,
            $query,
            $onlyTitle
                ? [
                    'titulo',
                ]
                : [
                    'titulo',
                    "COALESCE(tipo,'')",
                    "COALESCE(local,'')",
                ]
        );

        $this->appendStatus(
            $where,
            $params,
            $status,
            [
                'rascunho',
                'publicado',
                'arquivado',
            ]
        );

        $this->appendDateRange(
            $where,
            $params,
            'data_inicio',
            $dateFrom,
            $dateTo
        );

        $stmt =
            $this->pdo->prepare(
                "SELECT
                    id,
                    titulo,
                    tipo,
                    local,
                    status,
                    data_inicio AS data_ref
                 FROM eventos
                 WHERE "
                 . implode(
                    ' AND ',
                    $where
                 )
                 . "
                 ORDER BY data_inicio DESC,id DESC
                 LIMIT {$limit}"
            );

        $stmt->execute($params);

        $rows =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            ) ?: [];

        return
            array_map(
                static function (
                    array $row
                ): array {
                    $subtitle =
                        trim(
                            (string)(
                                $row['tipo']
                                ?? ''
                            )
                            . (
                                !empty(
                                    $row['local']
                                )
                                    ? ' · '
                                    . (string)$row['local']
                                    : ''
                            )
                        );

                    return [
                        'id' =>
                            (int)$row['id'],
                        'section' =>
                            'Eventos',
                        'type' =>
                            'evento',
                        'label' =>
                            (string)$row['titulo'],
                        'subtitle' =>
                            $subtitle,
                        'status' =>
                            (string)(
                                $row['status']
                                ?? ''
                            ),
                        'date_sort' =>
                            (string)(
                                $row['data_ref']
                                ?? ''
                            ),
                        'icon' =>
                            'bi-calendar-event',
                        'url' =>
                            url(
                                'admin/eventos/form.php?id='
                                . (int)$row['id']
                            ),
                    ];
                },
                $rows
            );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function documents(
        string $query,
        string $status,
        ?string $dateFrom,
        ?string $dateTo,
        bool $onlyTitle,
        int $limit
    ): array {
        $where = [
            '1=1',
        ];

        $params = [];

        $this->appendTextFilter(
            $where,
            $params,
            $query,
            $onlyTitle
                ? [
                    'd.titulo',
                ]
                : [
                    'd.titulo',
                    "COALESCE(d.descricao,'')",
                    "COALESCE(m.nome_original,'')",
                ]
        );

        $this->appendStatus(
            $where,
            $params,
            $status,
            [
                'rascunho',
                'publicado',
                'arquivado',
            ],
            'd.status'
        );

        $this->appendDateRange(
            $where,
            $params,
            'd.updated_at',
            $dateFrom,
            $dateTo
        );

        $stmt =
            $this->pdo->prepare(
                "SELECT
                    d.id,
                    d.titulo,
                    d.descricao,
                    d.status,
                    d.updated_at AS data_ref,
                    m.nome_original
                 FROM documentos d
                 LEFT JOIN midias m
                    ON m.id=d.midia_id
                 WHERE "
                 . implode(
                    ' AND ',
                    $where
                 )
                 . "
                 ORDER BY d.updated_at DESC,d.id DESC
                 LIMIT {$limit}"
            );

        $stmt->execute($params);

        $rows =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            ) ?: [];

        return
            array_map(
                static function (
                    array $row
                ): array {
                    $subtitle =
                        trim(
                            (string)(
                                $row['descricao']
                                ?? ''
                            )
                        );

                    if (
                        $subtitle === ''
                        && !empty(
                            $row['nome_original']
                        )
                    ) {
                        $subtitle =
                            (string)$row['nome_original'];
                    }

                    return [
                        'id' =>
                            (int)$row['id'],
                        'section' =>
                            'Documentos',
                        'type' =>
                            'documento',
                        'label' =>
                            (string)$row['titulo'],
                        'subtitle' =>
                            portalExcerpt(
                                $subtitle,
                                160
                            ),
                        'status' =>
                            (string)(
                                $row['status']
                                ?? ''
                            ),
                        'date_sort' =>
                            (string)(
                                $row['data_ref']
                                ?? ''
                            ),
                        'icon' =>
                            'bi-file-earmark-arrow-down',
                        'url' =>
                            url(
                                'admin/documentos/form.php?id='
                                . (int)$row['id']
                            ),
                    ];
                },
                $rows
            );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function media(
        string $query,
        ?string $dateFrom,
        ?string $dateTo,
        bool $onlyTitle,
        int $limit
    ): array {
        $where = [
            '1=1',
        ];

        $params = [];

        $this->appendTextFilter(
            $where,
            $params,
            $query,
            $onlyTitle
                ? [
                    "COALESCE(titulo,'')",
                ]
                : [
                    "COALESCE(titulo,'')",
                    'nome_original',
                    "COALESCE(alt_text,'')",
                ]
        );

        /*
         * A biblioteca histórica nem sempre possui created_at/updated_at nas
         * mesmas versões, então o filtro por data é omitido neste módulo.
         */

        $stmt =
            $this->pdo->prepare(
                "SELECT
                    id,
                    titulo,
                    nome_original,
                    mime_type,
                    extensao
                 FROM midias
                 WHERE "
                 . implode(
                    ' AND ',
                    $where
                 )
                 . "
                 ORDER BY id DESC
                 LIMIT {$limit}"
            );

        $stmt->execute($params);

        $rows =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            ) ?: [];

        return
            array_map(
                static function (
                    array $row
                ): array {
                    $title =
                        trim(
                            (string)(
                                $row['titulo']
                                ?? ''
                            )
                        );

                    return [
                        'id' =>
                            (int)$row['id'],
                        'section' =>
                            'Mídia',
                        'type' =>
                            'midia',
                        'label' =>
                            $title !== ''
                                ? $title
                                : (string)(
                                    $row['nome_original']
                                    ?? 'Arquivo'
                                ),
                        'subtitle' =>
                            (string)(
                                $row['nome_original']
                                ?? ''
                            ),
                        'status' =>
                            strtoupper(
                                (string)(
                                    $row['extensao']
                                    ?? ''
                                )
                            ),
                        'date_sort' =>
                            '',
                        'icon' =>
                            str_starts_with(
                                (string)(
                                    $row['mime_type']
                                    ?? ''
                                ),
                                'image/'
                            )
                                ? 'bi-image'
                                : 'bi-file-earmark',
                        'url' =>
                            url(
                                'admin/midias/editar.php?id='
                                . (int)$row['id']
                            ),
                    ];
                },
                $rows
            );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function users(
        string $query,
        string $status,
        ?string $dateFrom,
        ?string $dateTo,
        bool $onlyTitle,
        int $limit
    ): array {
        $where = [
            '1=1',
        ];

        $params = [];

        $this->appendTextFilter(
            $where,
            $params,
            $query,
            $onlyTitle
                ? [
                    'u.nome',
                ]
                : [
                    'u.nome',
                    'u.email',
                    "COALESCE(p.nome,'')",
                ]
        );

        if (
            $status === 'ativo'
            || $status === 'inativo'
        ) {
            $where[] =
                'u.ativo=:user_ativo';

            $params['user_ativo'] =
                $status === 'ativo'
                    ? 1
                    : 0;
        }

        $stmt =
            $this->pdo->prepare(
                "SELECT
                    u.id,
                    u.nome,
                    u.email,
                    u.ativo,
                    p.nome AS perfil_nome
                 FROM usuarios u
                 LEFT JOIN perfis p
                    ON p.id=u.perfil_id
                 WHERE "
                 . implode(
                    ' AND ',
                    $where
                 )
                 . "
                 ORDER BY u.nome ASC,u.id ASC
                 LIMIT {$limit}"
            );

        $stmt->execute($params);

        $rows =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            ) ?: [];

        return
            array_map(
                static function (
                    array $row
                ): array {
                    return [
                        'id' =>
                            (int)$row['id'],
                        'section' =>
                            'Usuários',
                        'type' =>
                            'usuario',
                        'label' =>
                            (string)$row['nome'],
                        'subtitle' =>
                            (string)$row['email']
                            . (
                                !empty(
                                    $row['perfil_nome']
                                )
                                    ? ' · '
                                    . (string)$row['perfil_nome']
                                    : ''
                            ),
                        'status' =>
                            (int)(
                                $row['ativo']
                                ?? 0
                            ) === 1
                                ? 'ativo'
                                : 'inativo',
                        'date_sort' =>
                            '',
                        'icon' =>
                            'bi-person',
                        'url' =>
                            url(
                                'admin/usuarios/form.php?id='
                                . (int)$row['id']
                            ),
                    ];
                },
                $rows
            );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function communities(
        string $query,
        string $status,
        ?string $dateFrom,
        ?string $dateTo,
        bool $onlyTitle,
        int $limit
    ): array {
        $where = [
            '1=1',
        ];

        $params = [];

        $this->appendTextFilter(
            $where,
            $params,
            $query,
            $onlyTitle
                ? [
                    'nome',
                ]
                : [
                    'nome',
                    'slug',
                ]
        );

        if (
            $status === 'ativa'
            || $status === 'inativa'
        ) {
            $where[] =
                'ativa=:community_active';

            $params['community_active'] =
                $status === 'ativa'
                    ? 1
                    : 0;
        }

        $stmt =
            $this->pdo->prepare(
                "SELECT
                    id,
                    nome,
                    slug,
                    ativa
                 FROM comunidades
                 WHERE "
                 . implode(
                    ' AND ',
                    $where
                 )
                 . "
                 ORDER BY nome ASC,id ASC
                 LIMIT {$limit}"
            );

        $stmt->execute($params);

        $rows =
            $stmt->fetchAll(
                PDO::FETCH_ASSOC
            ) ?: [];

        return
            array_map(
                static function (
                    array $row
                ): array {
                    return [
                        'id' =>
                            (int)$row['id'],
                        'section' =>
                            'Comunidades',
                        'type' =>
                            'comunidade',
                        'label' =>
                            (string)$row['nome'],
                        'subtitle' =>
                            (string)(
                                $row['slug']
                                ?? ''
                            ),
                        'status' =>
                            (int)(
                                $row['ativa']
                                ?? 0
                            ) === 1
                                ? 'ativa'
                                : 'inativa',
                        'date_sort' =>
                            '',
                        'icon' =>
                            'bi-geo-alt',
                        'url' =>
                            url(
                                'admin/comunidades/form.php?id='
                                . (int)$row['id']
                            ),
                    ];
                },
                $rows
            );
    }

    /**
     * @param array<int,string> $columns
     * @param array<int,string> $where
     * @param array<string,mixed> $params
     */
    private function appendTextFilter(
        array &$where,
        array &$params,
        string $query,
        array $columns
    ): void {
        $query =
            trim(
                $query
            );

        if (
            $query === ''
            || !$columns
        ) {
            return;
        }

        $like =
            '%'
            . $query
            . '%';

        $parts = [];

        foreach (
            $columns
            as $index => $column
        ) {
            $key =
                'search_'
                . $index;

            $parts[] =
                $column
                . ' LIKE :'
                . $key;

            $params[$key] =
                $like;
        }

        $where[] =
            '('
            . implode(
                ' OR ',
                $parts
            )
            . ')';
    }

    /**
     * @param array<int,string> $where
     * @param array<string,mixed> $params
     * @param array<int,string> $allowed
     */
    private function appendStatus(
        array &$where,
        array &$params,
        string $status,
        array $allowed,
        string $column = 'status'
    ): void {
        if (
            $status === ''
            || !in_array(
                $status,
                $allowed,
                true
            )
        ) {
            return;
        }

        $where[] =
            $column
            . '=:filter_status';

        $params['filter_status'] =
            $status;
    }

    /**
     * @param array<int,string> $where
     * @param array<string,mixed> $params
     */
    private function appendDateRange(
        array &$where,
        array &$params,
        string $column,
        ?string $dateFrom,
        ?string $dateTo
    ): void {
        if ($dateFrom !== null) {
            $where[] =
                $column
                . ' >= :date_from';

            $params['date_from'] =
                $dateFrom
                . ' 00:00:00';
        }

        if ($dateTo !== null) {
            $where[] =
                $column
                . ' <= :date_to';

            $params['date_to'] =
                $dateTo
                . ' 23:59:59';
        }
    }

    private function dateOrNull(
        string $value
    ): ?string {
        $value =
            trim(
                $value
            );

        if ($value === '') {
            return null;
        }

        $date =
            DateTimeImmutable::createFromFormat(
                'Y-m-d',
                $value
            );

        if (
            !$date
            || $date->format(
                'Y-m-d'
            ) !== $value
        ) {
            return null;
        }

        return $value;
    }
}
