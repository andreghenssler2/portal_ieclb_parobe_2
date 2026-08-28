<?php

declare(strict_types=1);

final class SearchService
{
    /** @var array<int,string> */
    private static array $errors = [];

    /** @var array<int,string> */
    private const STOP_WORDS = [
        'a','o','as','os','e','de','da','do','das','dos','em','no','na','nos','nas',
        'para','por','com','um','uma','uns','umas',
    ];

    /**
     * Busca em todos os conteúdos públicos.
     *
     * Para pesquisas com várias palavras, cada termo relevante precisa aparecer
     * em pelo menos um dos campos pesquisáveis do mesmo registro.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function search(PDO $pdo, string $query): array
    {
        self::$errors = [];

        $query = self::normalizeQuery($query);
        $terms = self::terms($query);

        if ($query === '' || !$terms) {
            return [];
        }

        $results = [];

        foreach (self::sources() as $source) {
            if (!self::tableExists($pdo, (string)$source['table'])) {
                continue;
            }

            $termClauses = [];
            $params = [];

            foreach ($terms as $term) {
                $fieldClauses = [];

                foreach ($source['fields'] as $field) {
                    $fieldClauses[] = $field . ' LIKE ?';
                    $params[] = '%' . $term . '%';
                }

                $termClauses[] = '(' . implode(' OR ', $fieldClauses) . ')';
            }

            $where = trim((string)$source['base_where']);
            if ($where !== '') {
                $where .= ' AND ';
            }

            $sql = (string)$source['select']
                . ' WHERE '
                . $where
                . implode(' AND ', $termClauses)
                . ' ORDER BY '
                . (string)$source['order']
                . ' LIMIT '
                . (int)$source['limit'];

            try {
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                foreach (($stmt->fetchAll() ?: []) as $row) {
                    $row['type'] = (string)$source['type'];
                    $row['_score'] = self::score($row, $query, $terms);
                    $results[] = $row;
                }
            } catch (Throwable $e) {
                $message = 'Busca [' . $source['type'] . ']: ' . $e->getMessage();
                self::$errors[] = $message;
                error_log($message);
            }
        }

        usort(
            $results,
            static function (array $a, array $b): int {
                $score = ((int)($b['_score'] ?? 0)) <=> ((int)($a['_score'] ?? 0));
                if ($score !== 0) {
                    return $score;
                }

                return strcmp((string)($b['dt'] ?? ''), (string)($a['dt'] ?? ''));
            }
        );

        foreach ($results as &$result) {
            unset($result['_score']);
        }
        unset($result);

        return $results;
    }

    /** @return array<int,string> */
    public static function errors(): array
    {
        return self::$errors;
    }

    /** @return array<int,string> */
    public static function terms(string $query): array
    {
        $query = self::normalizeQuery($query);
        if ($query === '') {
            return [];
        }

        $raw = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $terms = [];

        foreach ($raw as $word) {
            $word = trim((string)$word, " \t\n\r\0\x0B.,;:!?()[]{}\"'");
            if ($word === '') {
                continue;
            }

            $lower = self::lower($word);

            if (count($raw) > 1 && in_array($lower, self::STOP_WORDS, true)) {
                continue;
            }

            if (!isset($terms[$lower])) {
                $terms[$lower] = $word;
            }

            if (count($terms) >= 8) {
                break;
            }
        }

        // Se a pesquisa for composta somente por stop words, ainda permite buscar.
        if (!$terms) {
            foreach (array_slice($raw, 0, 8) as $word) {
                $lower = self::lower((string)$word);
                $terms[$lower] = (string)$word;
            }
        }

        return array_values($terms);
    }

    public static function normalizeQuery(string $query): string
    {
        $query = trim($query);
        $query = preg_replace('/\s+/u', ' ', $query) ?? $query;
        return self::cut($query, 120);
    }

    /** @return array<int,array<string,mixed>> */
    private static function sources(): array
    {
        return [
            [
                'type' => 'noticia',
                'table' => 'posts',
                'select' => "SELECT titulo,slug,resumo,conteudo,COALESCE(publicado_em,created_at) dt FROM posts",
                'base_where' => "status='publicado' AND (publicado_em IS NULL OR publicado_em<=NOW())",
                'fields' => ['titulo','slug','resumo','conteudo'],
                'order' => 'COALESCE(publicado_em,created_at) DESC',
                'limit' => 40,
            ],
            [
                'type' => 'pagina',
                'table' => 'paginas',
                'select' => "SELECT titulo,slug,resumo,conteudo,COALESCE(publicado_em,created_at) dt FROM paginas",
                'base_where' => "status='publicado' AND (publicado_em IS NULL OR publicado_em<=NOW())",
                'fields' => ['titulo','slug','resumo','conteudo'],
                'order' => 'COALESCE(publicado_em,created_at) DESC',
                'limit' => 30,
            ],
            [
                'type' => 'evento',
                'table' => 'eventos',
                'select' => "SELECT titulo,slug,resumo,descricao conteudo,data_inicio dt FROM eventos",
                'base_where' => "status='publicado'",
                'fields' => ['titulo','slug','resumo','descricao','local'],
                'order' => 'data_inicio DESC',
                'limit' => 40,
            ],
            [
                'type' => 'lideranca',
                'table' => 'liderancas',
                'select' => "SELECT nome titulo,slug,resumo,biografia conteudo,updated_at dt FROM liderancas",
                'base_where' => "ativo=1",
                'fields' => ['nome','slug','funcao','resumo','biografia'],
                'order' => 'ordem ASC,nome ASC',
                'limit' => 30,
            ],
            [
                'type' => 'documento',
                'table' => 'documentos',
                'select' => "SELECT titulo,slug,descricao resumo,descricao conteudo,COALESCE(publicado_em,created_at) dt FROM documentos",
                'base_where' => "status='publicado' AND (publicado_em IS NULL OR publicado_em<=NOW())",
                'fields' => ['titulo','slug','descricao'],
                'order' => 'COALESCE(publicado_em,created_at) DESC',
                'limit' => 30,
            ],
            [
                'type' => 'galeria',
                'table' => 'galerias',
                'select' => "SELECT titulo,slug,descricao resumo,'' conteudo,COALESCE(publicado_em,created_at) dt FROM galerias",
                'base_where' => "status='publicado' AND (publicado_em IS NULL OR publicado_em<=NOW())",
                'fields' => ['titulo','slug','descricao'],
                'order' => 'COALESCE(publicado_em,created_at) DESC',
                'limit' => 20,
            ],
        ];
    }

    /** @param array<string,mixed> $row */
    private static function score(array $row, string $query, array $terms): int
    {
        $title = self::lower((string)($row['titulo'] ?? ''));
        $slug = self::lower((string)($row['slug'] ?? ''));
        $summary = self::lower((string)($row['resumo'] ?? ''));
        $content = self::lower((string)($row['conteudo'] ?? ''));
        $needle = self::lower($query);

        $score = 0;

        if ($title === $needle) {
            $score += 1000;
        } elseif ($needle !== '' && str_contains($title, $needle)) {
            $score += 500;
        }

        if ($needle !== '' && str_contains($slug, str_replace(' ', '-', $needle))) {
            $score += 250;
        }

        if ($needle !== '' && str_contains($summary, $needle)) {
            $score += 180;
        }

        if ($needle !== '' && str_contains($content, $needle)) {
            $score += 100;
        }

        foreach ($terms as $term) {
            $term = self::lower((string)$term);

            if (str_contains($title, $term)) {
                $score += 60;
            }
            if (str_contains($summary, $term)) {
                $score += 25;
            }
            if (str_contains($content, $term)) {
                $score += 10;
            }
        }

        return $score;
    }

    private static function lower(string $value): string
    {
        return function_exists('mb_strtolower')
            ? mb_strtolower($value, 'UTF-8')
            : strtolower($value);
    }

    private static function cut(string $value, int $length): string
    {
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $length, 'UTF-8')
            : substr($value, 0, $length);
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.tables
                 WHERE table_schema=DATABASE() AND table_name=?'
            );
            $stmt->execute([$table]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            self::$errors[] = 'Busca [estrutura/' . $table . ']: ' . $e->getMessage();
            return false;
        }
    }
}
