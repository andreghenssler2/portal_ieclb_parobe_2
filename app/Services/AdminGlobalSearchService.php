<?php

declare(strict_types=1);

/**
 * Busca global do painel administrativo.
 *
 * Os resultados respeitam as permissões da sessão atual. Cada módulo é
 * consultado de forma isolada para que uma tabela opcional/legada ausente
 * não derrube toda a pesquisa.
 */
final class AdminGlobalSearchService
{
    public function __construct(
        private PDO $pdo
    ) {
    }

    /**
     * @return array{
     *   query:string,
     *   total:int,
     *   results:array<int,array<string,mixed>>
     * }
     */
    public function search(
        string $query,
        int $limitPerModule = 6
    ): array {
        $query = $this->normalize($query);

        if (
            $query === ''
            || mb_strlen($query) < 2
        ) {
            return [
                'query' => $query,
                'total' => 0,
                'results' => [],
            ];
        }

        $limitPerModule =
            max(
                1,
                min(
                    10,
                    $limitPerModule
                )
            );

        $like = '%' . $query . '%';

        $results = [];

        if (Auth::can('noticias.gerenciar')) {
            $results = array_merge(
                $results,
                $this->searchPosts(
                    $like,
                    $limitPerModule
                )
            );
        }

        if (Auth::can('paginas.gerenciar')) {
            $results = array_merge(
                $results,
                $this->searchPages(
                    $like,
                    $limitPerModule
                )
            );
        }

        if (Auth::can('eventos.gerenciar')) {
            $results = array_merge(
                $results,
                $this->searchEvents(
                    $like,
                    $limitPerModule
                )
            );
        }

        if (Auth::can('documentos.gerenciar')) {
            $results = array_merge(
                $results,
                $this->searchDocuments(
                    $like,
                    $limitPerModule
                )
            );
        }

        if (Auth::can('midias.gerenciar')) {
            $results = array_merge(
                $results,
                $this->searchMedia(
                    $like,
                    $limitPerModule
                )
            );
        }

        if (Auth::can('usuarios.gerenciar')) {
            $results = array_merge(
                $results,
                $this->searchUsers(
                    $like,
                    $limitPerModule
                )
            );
        }

        if (Auth::can('comunidades.gerenciar')) {
            $results = array_merge(
                $results,
                $this->searchCommunities(
                    $like,
                    $limitPerModule
                )
            );
        }

        return [
            'query' => $query,
            'total' => count($results),
            'results' => $results,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function searchPosts(
        string $like,
        int $limit
    ): array {
        return $this->queryRows(
            "SELECT
                id,
                titulo,
                slug,
                status,
                publicado_em,
                updated_at
             FROM posts
             WHERE status <> 'lixeira'
               AND (
                    titulo LIKE :q1
                    OR slug LIKE :q2
                    OR COALESCE(resumo,'') LIKE :q3
               )
             ORDER BY
                COALESCE(
                    publicado_em,
                    updated_at,
                    created_at
                ) DESC,
                id DESC
             LIMIT {$limit}",
            [
                'q1' => $like,
                'q2' => $like,
                'q3' => $like,
            ],
            static function (array $row): array {
                $status =
                    (string)($row['status'] ?? '');

                return [
                    'section' => 'Notícias',
                    'type' => 'noticia',
                    'label' => (string)$row['titulo'],
                    'subtitle' =>
                        $status !== ''
                            ? 'Status: ' . $status
                            : 'Notícia',
                    'icon' => 'bi-newspaper',
                    'url' =>
                        url(
                            'admin/noticias/form.php?id='
                            . (int)$row['id']
                        ),
                    'badge' => $status,
                    'badge_class' => 'secondary',
                ];
            }
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function searchPages(
        string $like,
        int $limit
    ): array {
        return $this->queryRows(
            "SELECT
                id,
                titulo,
                slug,
                status,
                updated_at
             FROM paginas
             WHERE status <> 'lixeira'
               AND (
                    titulo LIKE :q1
                    OR slug LIKE :q2
                    OR COALESCE(resumo,'') LIKE :q3
               )
             ORDER BY
                updated_at DESC,
                id DESC
             LIMIT {$limit}",
            [
                'q1' => $like,
                'q2' => $like,
                'q3' => $like,
            ],
            static function (array $row): array {
                $status =
                    (string)($row['status'] ?? '');

                return [
                    'section' => 'Páginas',
                    'type' => 'pagina',
                    'label' => (string)$row['titulo'],
                    'subtitle' =>
                        $status !== ''
                            ? 'Status: ' . $status
                            : 'Página',
                    'icon' => 'bi-files',
                    'url' =>
                        url(
                            'admin/paginas/form.php?id='
                            . (int)$row['id']
                        ),
                    'badge' => $status,
                    'badge_class' => 'secondary',
                ];
            }
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function searchEvents(
        string $like,
        int $limit
    ): array {
        return $this->queryRows(
            "SELECT
                id,
                titulo,
                tipo,
                status,
                data_inicio
             FROM eventos
             WHERE status <> 'lixeira'
               AND (
                    titulo LIKE :q1
                    OR COALESCE(tipo,'') LIKE :q2
                    OR COALESCE(local,'') LIKE :q3
               )
             ORDER BY
                data_inicio DESC,
                id DESC
             LIMIT {$limit}",
            [
                'q1' => $like,
                'q2' => $like,
                'q3' => $like,
            ],
            static function (array $row): array {
                $type =
                    trim(
                        (string)(
                            $row['tipo']
                            ?? ''
                        )
                    );

                $date =
                    !empty($row['data_inicio'])
                        ? formatDateBr(
                            $row['data_inicio']
                        )
                        : '';

                $subtitle =
                    trim(
                        $type
                        . (
                            $type !== ''
                            && $date !== ''
                                ? ' · '
                                : ''
                        )
                        . $date
                    );

                return [
                    'section' => 'Eventos',
                    'type' => 'evento',
                    'label' => (string)$row['titulo'],
                    'subtitle' =>
                        $subtitle !== ''
                            ? $subtitle
                            : 'Evento / culto',
                    'icon' => 'bi-calendar-event',
                    'url' =>
                        url(
                            'admin/eventos/form.php?id='
                            . (int)$row['id']
                        ),
                    'badge' =>
                        (string)($row['status'] ?? ''),
                    'badge_class' => 'secondary',
                ];
            }
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function searchDocuments(
        string $like,
        int $limit
    ): array {
        return $this->queryRows(
            "SELECT
                d.id,
                d.titulo,
                d.status,
                d.descricao,
                m.nome_original
             FROM documentos d
             LEFT JOIN midias m
                ON m.id=d.midia_id
             WHERE (
                    d.titulo LIKE :q1
                    OR COALESCE(d.descricao,'') LIKE :q2
                    OR COALESCE(m.nome_original,'') LIKE :q3
               )
             ORDER BY d.updated_at DESC,d.id DESC
             LIMIT {$limit}",
            [
                'q1' => $like,
                'q2' => $like,
                'q3' => $like,
            ],
            static function (array $row): array {
                $filename =
                    trim(
                        (string)(
                            $row['nome_original']
                            ?? ''
                        )
                    );

                return [
                    'section' => 'Documentos',
                    'type' => 'documento',
                    'label' => (string)$row['titulo'],
                    'subtitle' =>
                        $filename !== ''
                            ? $filename
                            : 'Documento / download',
                    'icon' => 'bi-file-earmark-arrow-down',
                    'url' =>
                        url(
                            'admin/documentos/form.php?id='
                            . (int)$row['id']
                        ),
                    'badge' =>
                        (string)($row['status'] ?? ''),
                    'badge_class' => 'secondary',
                ];
            }
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function searchMedia(
        string $like,
        int $limit
    ): array {
        return $this->queryRows(
            "SELECT
                id,
                titulo,
                nome_original,
                mime_type,
                extensao,
                tamanho
             FROM midias
             WHERE (
                    COALESCE(titulo,'') LIKE :q1
                    OR nome_original LIKE :q2
                    OR COALESCE(alt_text,'') LIKE :q3
               )
             ORDER BY id DESC
             LIMIT {$limit}",
            [
                'q1' => $like,
                'q2' => $like,
                'q3' => $like,
            ],
            static function (array $row): array {
                $title =
                    trim(
                        (string)(
                            $row['titulo']
                            ?? ''
                        )
                    );

                $original =
                    (string)(
                        $row['nome_original']
                        ?? 'Arquivo'
                    );

                $extension =
                    strtoupper(
                        trim(
                            (string)(
                                $row['extensao']
                                ?? ''
                            )
                        )
                    );

                return [
                    'section' => 'Mídia',
                    'type' => 'midia',
                    'label' =>
                        $title !== ''
                            ? $title
                            : $original,
                    'subtitle' =>
                        $title !== ''
                            ? $original
                            : (
                                $extension !== ''
                                    ? 'Arquivo ' . $extension
                                    : 'Biblioteca de mídia'
                            ),
                    'icon' =>
                        str_starts_with(
                            (string)($row['mime_type'] ?? ''),
                            'image/'
                        )
                            ? 'bi-image'
                            : 'bi-file-earmark',
                    'url' =>
                        url(
                            'admin/midias/editar.php?id='
                            . (int)$row['id']
                        ),
                    'badge' => $extension,
                    'badge_class' => 'secondary',
                ];
            }
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function searchUsers(
        string $like,
        int $limit
    ): array {
        return $this->queryRows(
            "SELECT
                u.id,
                u.nome,
                u.email,
                u.ativo,
                p.nome AS perfil_nome
             FROM usuarios u
             LEFT JOIN perfis p
                ON p.id=u.perfil_id
             WHERE (
                    u.nome LIKE :q1
                    OR u.email LIKE :q2
                    OR COALESCE(p.nome,'') LIKE :q3
               )
             ORDER BY u.nome ASC,u.id ASC
             LIMIT {$limit}",
            [
                'q1' => $like,
                'q2' => $like,
                'q3' => $like,
            ],
            static function (array $row): array {
                $profile =
                    trim(
                        (string)(
                            $row['perfil_nome']
                            ?? ''
                        )
                    );

                return [
                    'section' => 'Usuários',
                    'type' => 'usuario',
                    'label' => (string)$row['nome'],
                    'subtitle' =>
                        (string)$row['email']
                        . (
                            $profile !== ''
                                ? ' · ' . $profile
                                : ''
                        ),
                    'icon' => 'bi-person',
                    'url' =>
                        url(
                            'admin/usuarios/form.php?id='
                            . (int)$row['id']
                        ),
                    'badge' =>
                        (int)($row['ativo'] ?? 0) === 1
                            ? 'ativo'
                            : 'inativo',
                    'badge_class' =>
                        (int)($row['ativo'] ?? 0) === 1
                            ? 'success'
                            : 'secondary',
                ];
            }
        );
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function searchCommunities(
        string $like,
        int $limit
    ): array {
        return $this->queryRows(
            "SELECT
                id,
                nome,
                slug,
                ativa
             FROM comunidades
             WHERE (
                    nome LIKE :q1
                    OR slug LIKE :q2
               )
             ORDER BY nome ASC,id ASC
             LIMIT {$limit}",
            [
                'q1' => $like,
                'q2' => $like,
            ],
            static function (array $row): array {
                return [
                    'section' => 'Comunidades',
                    'type' => 'comunidade',
                    'label' => (string)$row['nome'],
                    'subtitle' => 'Comunidade',
                    'icon' => 'bi-geo-alt',
                    'url' =>
                        url(
                            'admin/comunidades/form.php?id='
                            . (int)$row['id']
                        ),
                    'badge' =>
                        (int)($row['ativa'] ?? 0) === 1
                            ? 'ativa'
                            : 'inativa',
                    'badge_class' =>
                        (int)($row['ativa'] ?? 0) === 1
                            ? 'success'
                            : 'secondary',
                ];
            }
        );
    }

    /**
     * @param array<string,mixed> $params
     * @param callable(array<string,mixed>):array<string,mixed> $mapper
     * @return array<int,array<string,mixed>>
     */
    private function queryRows(
        string $sql,
        array $params,
        callable $mapper
    ): array {
        try {
            $stmt =
                $this->pdo->prepare($sql);

            $stmt->execute($params);

            $rows =
                $stmt->fetchAll(
                    PDO::FETCH_ASSOC
                ) ?: [];

            $result = [];

            foreach ($rows as $row) {
                try {
                    $item = $mapper($row);

                    if (
                        trim(
                            (string)(
                                $item['label']
                                ?? ''
                            )
                        ) === ''
                    ) {
                        continue;
                    }

                    $result[] = $item;
                } catch (Throwable $ignored) {
                }
            }

            return $result;
        } catch (Throwable $e) {
            return [];
        }
    }

    private function normalize(
        string $query
    ): string {
        $query =
            preg_replace(
                '/\s+/u',
                ' ',
                trim($query)
            );

        if (!is_string($query)) {
            return '';
        }

        return mb_substr(
            $query,
            0,
            80
        );
    }
}
