<?php

declare(strict_types=1);

ob_start();

require_once __DIR__ . '/bootstrap.php';

$pdo = Database::connection();

$type =
    strtolower(
        trim(
            (string)(
                $_GET['tipo']
                ?? ''
            )
        )
    );

$communityId =
    max(
        0,
        (int)(
            $_GET['comunidade']
            ?? 0
        )
    );

$categoryId =
    max(
        0,
        (int)(
            $_GET['categoria']
            ?? 0
        )
    );

$query =
    trim(
        (string)(
            $_GET['q']
            ?? ''
        )
    );

$month =
    trim(
        (string)(
            $_GET['mes']
            ?? ''
        )
    );

$year =
    trim(
        (string)(
            $_GET['ano']
            ?? ''
        )
    );

if (
    !in_array(
        $type,
        [
            '',
            'culto',
            'festa',
            'atividade',
            'reuniao',
        ],
        true
    )
) {
    $type = '';
}

if (
    $month !== ''
    && !preg_match(
        '/^\d{4}-\d{2}$/',
        $month
    )
) {
    $month = '';
}

if (
    $year !== ''
    && !preg_match(
        '/^\d{4}$/',
        $year
    )
) {
    $year = '';
}

/*
|--------------------------------------------------------------------------
| Segurança pública
|--------------------------------------------------------------------------
|
| Este endpoint nunca exporta rascunhos, cancelados ou arquivados.
|
*/

$where = [
    "e.status='publicado'",
];

$params = [];

if ($month !== '') {
    $start =
        DateTimeImmutable::createFromFormat(
            '!Y-m',
            $month,
            new DateTimeZone(
                'America/Sao_Paulo'
            )
        );

    if (
        $start instanceof DateTimeImmutable
    ) {
        $end =
            $start->modify(
                '+1 month'
            );

        $where[] =
            'e.data_inicio < ?';

        $params[] =
            $end->format(
                'Y-m-d H:i:s'
            );

        $where[] =
            'COALESCE(e.data_fim,e.data_inicio) >= ?';

        $params[] =
            $start->format(
                'Y-m-d H:i:s'
            );
    }
} elseif ($year !== '') {
    $where[] =
        'e.data_inicio >= ?';

    $params[] =
        $year
        . '-01-01 00:00:00';

    $where[] =
        'e.data_inicio < ?';

    $params[] =
        ((int)$year + 1)
        . '-01-01 00:00:00';
}

if ($type !== '') {
    $where[] =
        'e.tipo = ?';

    $params[] =
        $type;
}

if ($communityId > 0) {
    $where[] =
        'e.comunidade_id = ?';

    $params[] =
        $communityId;
}

if ($categoryId > 0) {
    $where[] =
        'e.categoria_evento_id = ?';

    $params[] =
        $categoryId;
}

if ($query !== '') {
    $terms =
        preg_split(
            '/\s+/u',
            $query,
            -1,
            PREG_SPLIT_NO_EMPTY
        )
        ?: [];

    $terms =
        array_slice(
            $terms,
            0,
            6
        );

    foreach ($terms as $term) {
        $term =
            trim(
                (string)$term
            );

        if ($term === '') {
            continue;
        }

        $where[] =
            '(e.titulo LIKE ? OR e.resumo LIKE ? OR e.descricao LIKE ? OR e.local LIKE ?)';

        $like =
            '%'
            . $term
            . '%';

        array_push(
            $params,
            $like,
            $like,
            $like,
            $like
        );
    }
}

$sql = "
    SELECT
        e.*,
        c.nome AS comunidade_nome,
        ec.nome AS categoria_nome
    FROM eventos e
    LEFT JOIN comunidades c
        ON c.id=e.comunidade_id
    LEFT JOIN evento_categorias ec
        ON ec.id=e.categoria_evento_id
    WHERE "
    . implode(
        ' AND ',
        $where
    )
    . "
    ORDER BY e.data_inicio ASC,e.id ASC
    LIMIT 5000
";

$stmt =
    $pdo->prepare(
        $sql
    );

$stmt->execute(
    $params
);

$events =
    $stmt->fetchAll()
    ?: [];

$calendarName =
    'Agenda IECLB Parobé';

if ($month !== '') {
    $calendarName .=
        ' - '
        . $month;
} elseif ($year !== '') {
    $calendarName .=
        ' - '
        . $year;
}

if ($type !== '') {
    $calendarName .=
        ' - '
        . eventTypeLabel(
            $type
        );
}

$ics =
    EventCalendarService::buildCalendarIcs(
        $events,
        $calendarName
    );

if (
    ob_get_level()
    > 0
) {
    ob_end_clean();
}

header(
    'Content-Type: text/calendar; charset=utf-8'
);

header(
    'Cache-Control: public, max-age=300, must-revalidate'
);

header(
    'Content-Disposition: inline; filename="agenda-ieclb-parobe.ics"'
);

header(
    'X-Robots-Tag: noindex, nofollow'
);

echo $ics;
