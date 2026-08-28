<?php

declare(strict_types=1);

final class EventCalendarService
{
    private const TZ = 'America/Sao_Paulo';

    /** @return array{tipo:string,comunidade_id:int,categoria_id:int,q:string} */
    public static function filters(array $input): array
    {
        $tipo = strtolower(trim((string)($input['tipo'] ?? '')));
        if (!in_array($tipo, ['culto', 'evento'], true)) {
            $tipo = '';
        }

        return [
            'tipo' => $tipo,
            'comunidade_id' => max(0, (int)($input['comunidade'] ?? 0)),
            'categoria_id' => max(0, (int)($input['categoria'] ?? 0)),
            'q' => self::cut(trim((string)($input['q'] ?? '')), 120),
        ];
    }

    public static function normalizeMonth(?string $month): string
    {
        $month = trim((string)$month);

        if (preg_match('/^\d{4}-\d{2}$/', $month)) {
            $date = DateTimeImmutable::createFromFormat('!Y-m', $month, new DateTimeZone(self::TZ));
            $errors = DateTimeImmutable::getLastErrors();

            if ($date instanceof DateTimeImmutable
                && ($errors === false || ((int)$errors['warning_count'] === 0 && (int)$errors['error_count'] === 0))
                && $date->format('Y-m') === $month) {
                return $month;
            }
        }

        return (new DateTimeImmutable('now', new DateTimeZone(self::TZ)))->format('Y-m');
    }

    /** @return array{month:string,start:DateTimeImmutable,end:DateTimeImmutable,previous:string,next:string,label:string} */
    public static function monthInfo(string $month): array
    {
        $month = self::normalizeMonth($month);
        $start = DateTimeImmutable::createFromFormat('!Y-m', $month, new DateTimeZone(self::TZ));

        if (!$start instanceof DateTimeImmutable) {
            $start = new DateTimeImmutable('first day of this month 00:00:00', new DateTimeZone(self::TZ));
        }

        $end = $start->modify('+1 month');

        return [
            'month' => $month,
            'start' => $start,
            'end' => $end,
            'previous' => $start->modify('-1 month')->format('Y-m'),
            'next' => $end->format('Y-m'),
            'label' => self::monthName((int)$start->format('n')) . ' de ' . $start->format('Y'),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    public static function monthEvents(PDO $pdo, string $month, array $filters = []): array
    {
        $info = self::monthInfo($month);
        [$where, $params] = self::filterSql($filters);

        $where[] = 'e.data_inicio < ?';
        $params[] = $info['end']->format('Y-m-d H:i:s');

        $where[] = 'COALESCE(e.data_fim,e.data_inicio) >= ?';
        $params[] = $info['start']->format('Y-m-d H:i:s');

        return self::queryEvents($pdo, $where, $params, 500);
    }

    /** @return array<int,array<string,mixed>> */
    public static function upcomingEvents(PDO $pdo, array $filters = [], int $limit = 200): array
    {
        [$where, $params] = self::filterSql($filters);
        $where[] = 'COALESCE(e.data_fim,e.data_inicio) >= NOW()';

        return self::queryEvents($pdo, $where, $params, max(1, min(500, $limit)));
    }

    /** @return array<int,array<string,mixed>> */
    public static function eventBySlug(PDO $pdo, string $slug): array
    {
        $stmt = $pdo->prepare(
            "SELECT e.*,c.nome AS comunidade_nome,ec.nome AS categoria_nome,
                    m.caminho AS imagem_capa_midia,m.alt_text AS imagem_capa_alt
             FROM eventos e
             LEFT JOIN comunidades c ON c.id=e.comunidade_id
             LEFT JOIN evento_categorias ec ON ec.id=e.categoria_evento_id
             LEFT JOIN midias m ON m.id=e.imagem_capa_id
             WHERE e.slug=?
               AND e.status='publicado'
             LIMIT 1"
        );
        $stmt->execute([$slug]);

        $event = $stmt->fetch();
        return is_array($event) ? $event : [];
    }

    /**
     * Dias usados pelo calendário visual, começando na segunda-feira e terminando no domingo.
     *
     * @return array<int,array{date:string,day:int,in_month:bool,is_today:bool,events:array<int,array<string,mixed>>}>
     */
    public static function calendarDays(string $month, array $events): array
    {
        $info = self::monthInfo($month);
        $monthStart = $info['start'];
        $monthEnd = $info['end']->modify('-1 day');

        $gridStart = $monthStart->modify('-' . ((int)$monthStart->format('N') - 1) . ' days');
        $gridEnd = $monthEnd->modify('+' . (7 - (int)$monthEnd->format('N')) . ' days');

        $byDay = self::eventsByDay($events, $info['start'], $info['end']);
        $today = (new DateTimeImmutable('now', new DateTimeZone(self::TZ)))->format('Y-m-d');

        $days = [];
        for ($cursor = $gridStart; $cursor <= $gridEnd; $cursor = $cursor->modify('+1 day')) {
            $key = $cursor->format('Y-m-d');
            $days[] = [
                'date' => $key,
                'day' => (int)$cursor->format('j'),
                'in_month' => $cursor->format('Y-m') === $info['month'],
                'is_today' => $key === $today,
                'events' => $byDay[$key] ?? [],
            ];
        }

        return $days;
    }

    /** @return array<string,array<int,array<string,mixed>>> */
    public static function groupByDate(array $events): array
    {
        $groups = [];

        foreach ($events as $event) {
            $key = substr((string)$event['data_inicio'], 0, 10);
            $groups[$key] ??= [];
            $groups[$key][] = $event;
        }

        ksort($groups);
        return $groups;
    }

    public static function dateLabel(string $date): string
    {
        try {
            $dt = new DateTimeImmutable($date, new DateTimeZone(self::TZ));
            $weekdays = [
                1 => 'Segunda-feira',
                2 => 'Terça-feira',
                3 => 'Quarta-feira',
                4 => 'Quinta-feira',
                5 => 'Sexta-feira',
                6 => 'Sábado',
                7 => 'Domingo',
            ];

            return $weekdays[(int)$dt->format('N')]
                . ', '
                . $dt->format('d/m/Y');
        } catch (Throwable $e) {
            return $date;
        }
    }

    public static function timeLabel(array $event): string
    {
        $startRaw = (string)($event['data_inicio'] ?? '');
        if ($startRaw === '') {
            return '';
        }

        try {
            $start = new DateTimeImmutable($startRaw, new DateTimeZone(self::TZ));
        } catch (Throwable $e) {
            return '';
        }

        if ($start->format('H:i') === '00:00') {
            return 'Dia todo';
        }

        $label = $start->format('H:i');
        $endRaw = trim((string)($event['data_fim'] ?? ''));

        if ($endRaw !== '') {
            try {
                $end = new DateTimeImmutable($endRaw, new DateTimeZone(self::TZ));
                if ($end->format('Y-m-d') === $start->format('Y-m-d')
                    && $end->format('H:i') !== '00:00') {
                    $label .= '–' . $end->format('H:i');
                }
            } catch (Throwable $e) {
            }
        }

        return $label;
    }

    public static function eventIcsUrl(array $event): string
    {
        return rtrim(contentUrl('evento', (string)$event['slug']), '/') . '/calendario.ics';
    }

    public static function agendaIcsUrl(array $filters, ?string $month = null): string
    {
        $params = [];

        if ($month !== null && $month !== '') {
            $params['mes'] = self::normalizeMonth($month);
        }
        if (!empty($filters['tipo'])) {
            $params['tipo'] = (string)$filters['tipo'];
        }
        if (!empty($filters['comunidade_id'])) {
            $params['comunidade'] = (int)$filters['comunidade_id'];
        }
        if (!empty($filters['categoria_id'])) {
            $params['categoria'] = (int)$filters['categoria_id'];
        }
        if (!empty($filters['q'])) {
            $params['q'] = (string)$filters['q'];
        }

        $url = url('agenda.ics');
        return $params ? $url . '?' . http_build_query($params) : $url;
    }

    public static function buildCalendarIcs(array $events, string $calendarName = 'Agenda IECLB Parobé'): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Portal IECLB Parobé//Agenda 0.39.0//PT-BR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:' . self::icsEscape($calendarName),
        ];

        foreach ($events as $event) {
            array_push($lines, ...self::eventIcsLines($event));
        }

        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([self::class, 'foldIcsLine'], $lines)) . "\r\n";
    }

    public static function downloadFilename(string $base): string
    {
        $base = preg_replace('/[^A-Za-z0-9_-]+/', '-', self::ascii($base)) ?? 'agenda';
        $base = trim($base, '-_');

        return ($base !== '' ? strtolower($base) : 'agenda') . '.ics';
    }

    /** @return array{0:array<int,string>,1:array<int,mixed>} */
    private static function filterSql(array $filters): array
    {
        $where = ["e.status='publicado'"];
        $params = [];

        $tipo = strtolower(trim((string)($filters['tipo'] ?? '')));
        if (in_array($tipo, ['culto', 'evento'], true)) {
            $where[] = 'e.tipo=?';
            $params[] = $tipo;
        }

        $community = max(0, (int)($filters['comunidade_id'] ?? 0));
        if ($community > 0) {
            $where[] = 'e.comunidade_id=?';
            $params[] = $community;
        }

        $category = max(0, (int)($filters['categoria_id'] ?? 0));
        if ($category > 0) {
            $where[] = 'e.categoria_evento_id=?';
            $params[] = $category;
        }

        $query = trim((string)($filters['q'] ?? ''));
        if ($query !== '') {
            $terms = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $terms = array_slice($terms, 0, 6);

            foreach ($terms as $term) {
                $term = trim((string)$term);
                if ($term === '') {
                    continue;
                }

                $where[] = '(e.titulo LIKE ? OR e.resumo LIKE ? OR e.descricao LIKE ? OR e.local LIKE ?)';
                $like = '%' . $term . '%';
                array_push($params, $like, $like, $like, $like);
            }
        }

        return [$where, $params];
    }

    /** @return array<int,array<string,mixed>> */
    private static function queryEvents(PDO $pdo, array $where, array $params, int $limit): array
    {
        $sql = "SELECT e.*,c.nome AS comunidade_nome,ec.nome AS categoria_nome,
                       m.caminho AS imagem_capa_midia,m.alt_text AS imagem_capa_alt
                FROM eventos e
                LEFT JOIN comunidades c ON c.id=e.comunidade_id
                LEFT JOIN evento_categorias ec ON ec.id=e.categoria_evento_id
                LEFT JOIN midias m ON m.id=e.imagem_capa_id
                WHERE " . implode(' AND ', $where) . "
                ORDER BY e.data_inicio ASC,e.id ASC
                LIMIT " . (int)$limit;

        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($params));

        return $stmt->fetchAll() ?: [];
    }

    /** @return array<string,array<int,array<string,mixed>>> */
    private static function eventsByDay(array $events, DateTimeImmutable $monthStart, DateTimeImmutable $monthEnd): array
    {
        $byDay = [];

        foreach ($events as $event) {
            try {
                $start = new DateTimeImmutable((string)$event['data_inicio'], new DateTimeZone(self::TZ));
                $endRaw = trim((string)($event['data_fim'] ?? ''));
                $end = $endRaw !== ''
                    ? new DateTimeImmutable($endRaw, new DateTimeZone(self::TZ))
                    : $start;
            } catch (Throwable $e) {
                continue;
            }

            $first = $start < $monthStart ? $monthStart : $start;
            $lastMonthMoment = $monthEnd->modify('-1 second');
            $last = $end > $lastMonthMoment ? $lastMonthMoment : $end;

            $cursor = $first->setTime(0, 0);
            $lastDay = $last->setTime(0, 0);

            while ($cursor <= $lastDay) {
                $key = $cursor->format('Y-m-d');
                $byDay[$key] ??= [];
                $byDay[$key][] = $event;
                $cursor = $cursor->modify('+1 day');
            }
        }

        return $byDay;
    }

    /** @return array<int,string> */
    private static function eventIcsLines(array $event): array
    {
        $slug = (string)($event['slug'] ?? 'evento');
        $id = (int)($event['id'] ?? 0);
        $uid = 'evento-' . ($id > 0 ? $id : md5($slug)) . '@ieclbparobe.com.br';

        $title = trim((string)($event['titulo'] ?? 'Evento'));
        $summary = trim((string)($event['resumo'] ?? ''));
        $description = trim(strip_tags((string)($event['descricao'] ?? '')));
        if ($summary !== '') {
            $description = $summary . ($description !== '' ? "\n\n" . $description : '');
        }

        $location = trim(implode(' - ', array_filter([
            (string)($event['local'] ?? ''),
            (string)($event['endereco'] ?? ''),
        ], static fn(string $v): bool => trim($v) !== '')));

        $url = contentUrl('evento', $slug);
        $start = new DateTimeImmutable((string)$event['data_inicio'], new DateTimeZone(self::TZ));
        $endRaw = trim((string)($event['data_fim'] ?? ''));
        $allDay = $start->format('H:i:s') === '00:00:00';

        $lines = [
            'BEGIN:VEVENT',
            'UID:' . self::icsEscape($uid),
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
        ];

        if ($allDay) {
            $lines[] = 'DTSTART;VALUE=DATE:' . $start->format('Ymd');

            if ($endRaw !== '') {
                $end = new DateTimeImmutable($endRaw, new DateTimeZone(self::TZ));
                $exclusive = $end->setTime(0, 0)->modify('+1 day');
            } else {
                $exclusive = $start->modify('+1 day');
            }

            $lines[] = 'DTEND;VALUE=DATE:' . $exclusive->format('Ymd');
        } else {
            $startUtc = $start->setTimezone(new DateTimeZone('UTC'));
            $lines[] = 'DTSTART:' . $startUtc->format('Ymd\THis\Z');

            if ($endRaw !== '') {
                $end = new DateTimeImmutable($endRaw, new DateTimeZone(self::TZ));
            } else {
                $end = $start->modify('+1 hour');
            }

            $endUtc = $end->setTimezone(new DateTimeZone('UTC'));
            $lines[] = 'DTEND:' . $endUtc->format('Ymd\THis\Z');
        }

        $lines[] = 'SUMMARY:' . self::icsEscape($title);
        if ($description !== '') {
            $lines[] = 'DESCRIPTION:' . self::icsEscape($description);
        }
        if ($location !== '') {
            $lines[] = 'LOCATION:' . self::icsEscape($location);
        }
        $lines[] = 'URL:' . self::icsEscape($url);
        $lines[] = 'STATUS:CONFIRMED';
        $lines[] = 'END:VEVENT';

        return $lines;
    }

    private static function icsEscape(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace(["\r\n", "\r", "\n"], '\\n', $value);
        $value = str_replace(';', '\\;', $value);
        return str_replace(',', '\\,', $value);
    }

    private static function foldIcsLine(string $line): string
    {
        if (strlen($line) <= 73) {
            return $line;
        }

        $parts = [];
        while (strlen($line) > 73) {
            $parts[] = substr($line, 0, 73);
            $line = substr($line, 73);
        }
        $parts[] = $line;

        return implode("\r\n ", $parts);
    }

    private static function monthName(int $month): string
    {
        return [
            1 => 'Janeiro',
            2 => 'Fevereiro',
            3 => 'Março',
            4 => 'Abril',
            5 => 'Maio',
            6 => 'Junho',
            7 => 'Julho',
            8 => 'Agosto',
            9 => 'Setembro',
            10 => 'Outubro',
            11 => 'Novembro',
            12 => 'Dezembro',
        ][$month] ?? '';
    }

    private static function ascii(string $value): string
    {
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted) && $converted !== '') {
                return $converted;
            }
        }

        return $value;
    }

    private static function cut(string $value, int $length): string
    {
        return function_exists('mb_substr')
            ? mb_substr($value, 0, $length, 'UTF-8')
            : substr($value, 0, $length);
    }
}
