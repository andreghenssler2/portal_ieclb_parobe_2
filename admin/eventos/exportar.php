<?php

declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../../bootstrap.php';

Auth::requireLogin();
Auth::requirePermission('eventos.gerenciar');

$pdo = Database::connection();

$year =
    trim(
        (string)(
            $_GET['ano']
            ?? date('Y')
        )
    );

$type =
    strtolower(
        trim(
            (string)(
                $_GET['tipo']
                ?? ''
            )
        )
    );

$status =
    strtolower(
        trim(
            (string)(
                $_GET['status']
                ?? 'publicado'
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

if (
    $year !== ''
    && !preg_match(
        '/^\d{4}$/',
        $year
    )
) {
    $year = date('Y');
}

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
    !in_array(
        $status,
        [
            '',
            'rascunho',
            'publicado',
            'cancelado',
            'arquivado',
        ],
        true
    )
) {
    $status = 'publicado';
}

$where = [];
$params = [];

if ($year !== '') {
    $start =
        $year
        . '-01-01 00:00:00';

    $end =
        ((int)$year + 1)
        . '-01-01 00:00:00';

    $where[] =
        'e.data_inicio >= ?';

    $params[] =
        $start;

    $where[] =
        'e.data_inicio < ?';

    $params[] =
        $end;
}

if ($type !== '') {
    $where[] =
        'e.tipo = ?';

    $params[] =
        $type;
}

if ($status !== '') {
    $where[] =
        'e.status = ?';

    $params[] =
        $status;
}

if ($communityId > 0) {
    $where[] =
        'e.comunidade_id = ?';

    $params[] =
        $communityId;
}

$sql = "
    SELECT
        e.*,
        c.nome AS comunidade_nome,
        ec.nome AS categoria_nome
    FROM eventos e
    LEFT JOIN comunidades c
        ON c.id = e.comunidade_id
    LEFT JOIN evento_categorias ec
        ON ec.id = e.categoria_evento_id
";

if ($where) {
    $sql .=
        ' WHERE '
        . implode(
            ' AND ',
            $where
        );
}

$sql .=
    ' ORDER BY e.data_inicio ASC,e.id ASC';

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

if (
    isset(
        $_GET['baixar']
    )
    && $_GET['baixar'] === '1'
) {
    $calendarName =
        'Agenda IECLB Parobé';

    if ($year !== '') {
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

    if (
        !class_exists(
            'EventCalendarService'
        )
    ) {
        throw new RuntimeException(
            'EventCalendarService indisponível.'
        );
    }

    $ics =
        EventCalendarService::buildCalendarIcs(
            $events,
            $calendarName
        );

    $filenameBase =
        'agenda-ieclb-parobe'
        . (
            $year !== ''
                ? '-'
                    . $year
                : ''
        )
        . (
            $type !== ''
                ? '-'
                    . $type
                : ''
        );

    $filename =
        EventCalendarService::downloadFilename(
            $filenameBase
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
        'Content-Disposition: attachment; filename="'
        . $filename
        . '"'
    );

    header(
        'Cache-Control: private, no-store, max-age=0'
    );

    echo $ics;
    exit;
}

$comunidades =
    $pdo->query(
        'SELECT id,nome
         FROM comunidades
         WHERE ativa=1
         ORDER BY ordem,nome'
    )->fetchAll()
    ?: [];

$pageTitle =
    'Exportar Agenda';

require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h3 mb-1">
            Exportar Agenda
        </h1>

        <p class="text-secondary mb-0">
            Gere um arquivo iCalendar (.ics) para Google Calendar, Outlook, Apple Calendar e outros aplicativos.
        </p>
    </div>

    <a
        class="btn btn-outline-secondary"
        href="<?= e(url('admin/eventos/index.php')) ?>"
    >
        <i class="bi bi-arrow-left me-1"></i>
        Voltar para a Agenda
    </a>
</div>

<div class="row g-4">
    <div class="col-xl-8">
        <form
            method="get"
            class="card border-0 shadow-sm"
        >
            <div class="card-header bg-white fw-semibold">
                Opções da exportação
            </div>

            <div class="card-body p-4">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label
                            class="form-label"
                            for="agendaExportYear"
                        >
                            Ano
                        </label>

                        <input
                            class="form-control"
                            type="number"
                            min="2000"
                            max="2100"
                            name="ano"
                            id="agendaExportYear"
                            value="<?= e($year) ?>"
                            placeholder="Deixe vazio para todos"
                        >

                        <div class="form-text">
                            Deixe vazio para exportar todos os anos.
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label
                            class="form-label"
                            for="agendaExportType"
                        >
                            Tipo
                        </label>

                        <select
                            class="form-select"
                            name="tipo"
                            id="agendaExportType"
                        >
                            <option value="">
                                Todos os tipos
                            </option>

                            <option
                                value="culto"
                                <?= $type === 'culto' ? 'selected' : '' ?>
                            >
                                Cultos
                            </option>

                            <option
                                value="festa"
                                <?= $type === 'festa' ? 'selected' : '' ?>
                            >
                                Festas
                            </option>

                            <option
                                value="atividade"
                                <?= $type === 'atividade' ? 'selected' : '' ?>
                            >
                                Atividades
                            </option>

                            <option
                                value="reuniao"
                                <?= $type === 'reuniao' ? 'selected' : '' ?>
                            >
                                Reuniões
                            </option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label
                            class="form-label"
                            for="agendaExportStatus"
                        >
                            Status
                        </label>

                        <select
                            class="form-select"
                            name="status"
                            id="agendaExportStatus"
                        >
                            <option
                                value="publicado"
                                <?= $status === 'publicado' ? 'selected' : '' ?>
                            >
                                Publicados
                            </option>

                            <option
                                value="rascunho"
                                <?= $status === 'rascunho' ? 'selected' : '' ?>
                            >
                                Rascunhos
                            </option>

                            <option
                                value="cancelado"
                                <?= $status === 'cancelado' ? 'selected' : '' ?>
                            >
                                Cancelados
                            </option>

                            <option
                                value="arquivado"
                                <?= $status === 'arquivado' ? 'selected' : '' ?>
                            >
                                Arquivados
                            </option>

                            <option
                                value=""
                                <?= $status === '' ? 'selected' : '' ?>
                            >
                                Todos os status
                            </option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label
                            class="form-label"
                            for="agendaExportCommunity"
                        >
                            Comunidade
                        </label>

                        <select
                            class="form-select"
                            name="comunidade"
                            id="agendaExportCommunity"
                        >
                            <option value="0">
                                Todas as comunidades
                            </option>

                            <?php foreach ($comunidades as $comunidade): ?>
                                <option
                                    value="<?= (int)$comunidade['id'] ?>"
                                    <?= $communityId === (int)$comunidade['id'] ? 'selected' : '' ?>
                                >
                                    <?= e((string)$comunidade['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <div class="card-footer bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span class="text-secondary small">
                    <?= count($events) ?>
                    item<?= count($events) === 1 ? '' : 's' ?>
                    encontrado<?= count($events) === 1 ? '' : 's' ?>
                    com os filtros atuais.
                </span>

                <button
                    class="btn btn-primary"
                    type="submit"
                    name="baixar"
                    value="1"
                >
                    <i class="bi bi-download me-1"></i>
                    Baixar arquivo .ics
                </button>
            </div>
        </form>
    </div>

    <div class="col-xl-4">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">
                Exportação rápida
            </div>

            <div class="card-body d-grid gap-2">
                <a
                    class="btn btn-outline-primary"
                    href="<?= e(
                        url(
                            'admin/eventos/exportar.php?'
                            . http_build_query(
                                [
                                    'ano' => date('Y'),
                                    'status' => 'publicado',
                                    'baixar' => 1,
                                ]
                            )
                        )
                    ) ?>"
                >
                    <i class="bi bi-calendar-event me-1"></i>
                    Exportar <?= e(date('Y')) ?>
                </a>

                <a
                    class="btn btn-outline-secondary"
                    href="<?= e(
                        url(
                            'admin/eventos/exportar.php?'
                            . http_build_query(
                                [
                                    'ano' => '',
                                    'status' => 'publicado',
                                    'baixar' => 1,
                                ]
                            )
                        )
                    ) ?>"
                >
                    <i class="bi bi-calendar3 me-1"></i>
                    Exportar todos os publicados
                </a>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                Compatibilidade
            </div>

            <div class="card-body">
                <p class="small text-secondary mb-2">
                    O arquivo gerado usa o padrão iCalendar (.ics).
                </p>

                <ul class="small mb-0">
                    <li>Google Calendar</li>
                    <li>Microsoft Outlook</li>
                    <li>Apple Calendar</li>
                    <li>Thunderbird</li>
                    <li>Outros sistemas compatíveis com iCalendar</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/../_footer.php'; ?>
