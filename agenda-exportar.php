<?php

declare(strict_types=1);

ob_start();

require_once __DIR__ . '/bootstrap.php';

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

$linkParams = [];

if ($year !== '') {
    $linkParams['ano'] =
        $year;
}

if ($type !== '') {
    $linkParams['tipo'] =
        $type;
}

if ($communityId > 0) {
    $linkParams['comunidade'] =
        $communityId;
}

$feedUrl =
    url(
        'agenda.ics'
    );

if ($linkParams) {
    $feedUrl .=
        '?'
        . http_build_query(
            $linkParams
        );
}

$webcalUrl =
    preg_replace(
        '~^https?://~i',
        'webcal://',
        $feedUrl
    )
    ?: $feedUrl;

$downloadUrl =
    url(
        'agenda-exportar.php'
    )
    . '?'
    . http_build_query(
        $linkParams
        + [
            'baixar' => 1,
        ]
    );

$where = [
    "e.status='publicado'",
];

$params = [];

if ($year !== '') {
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

    $ics =
        EventCalendarService::buildCalendarIcs(
            $events,
            $calendarName
        );

    $filename =
        EventCalendarService::downloadFilename(
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
            )
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
        'Cache-Control: public, max-age=300'
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

$siteLabel =
    siteConfig(
        $pdo,
        'seo_titulo',
        'IECLB Parobé'
    );

$metaTitle =
    'Exportar Agenda - '
    . $siteLabel;

$metaDescription =
    'Baixe ou assine a agenda pública da Paróquia de Parobé em formato iCalendar.';

$canonicalUrl =
    url(
        'agenda-exportar.php'
    );

require themeFile(
    $pdo,
    'header.php'
);
?>

<section class="container py-5">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h1 class="display-6 fw-bold mb-2">
                Exportar Agenda
            </h1>

            <p class="lead text-secondary mb-0">
                Baixe a Agenda ou assine por link para receber as atualizações.
            </p>
        </div>

        <a
            class="btn btn-outline-secondary"
            href="<?= e(url('agenda')) ?>"
        >
            <i class="bi bi-arrow-left me-1"></i>
            Voltar para a Agenda
        </a>
    </div>

    <div class="row g-4">
        <div class="col-xl-8">
            <form
                method="get"
                action="<?= e(url('agenda-exportar.php')) ?>"
                class="card border-0 shadow-sm mb-4"
            >
                <div class="card-header bg-white fw-semibold">
                    Escolha o calendário
                </div>

                <div class="card-body p-4">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label
                                class="form-label"
                                for="publicAgendaExportYear"
                            >
                                Ano
                            </label>

                            <input
                                class="form-control"
                                type="number"
                                min="2000"
                                max="2100"
                                name="ano"
                                id="publicAgendaExportYear"
                                value="<?= e($year) ?>"
                            >

                            <div class="form-text">
                                Apague o ano para gerar um link contínuo com todos os anos publicados.
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label
                                class="form-label"
                                for="publicAgendaExportType"
                            >
                                Tipo
                            </label>

                            <select
                                class="form-select"
                                name="tipo"
                                id="publicAgendaExportType"
                            >
                                <option value="">
                                    Todos
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

                        <div class="col-md-4">
                            <label
                                class="form-label"
                                for="publicAgendaExportCommunity"
                            >
                                Comunidade
                            </label>

                            <select
                                class="form-select"
                                name="comunidade"
                                id="publicAgendaExportCommunity"
                            >
                                <option value="0">
                                    Todas
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
                        compromisso<?= count($events) === 1 ? '' : 's' ?>
                        público<?= count($events) === 1 ? '' : 's' ?>
                        encontrado<?= count($events) === 1 ? '' : 's' ?>.
                    </span>

                    <div class="d-flex flex-wrap gap-2">
                        <button
                            class="btn btn-outline-primary"
                            type="submit"
                        >
                            <i class="bi bi-funnel me-1"></i>
                            Atualizar opções
                        </button>

                        <a
                            class="btn btn-success"
                            href="<?= e($downloadUrl) ?>"
                        >
                            <i class="bi bi-download me-1"></i>
                            Baixar .ics
                        </a>
                    </div>
                </div>
            </form>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">
                    Assinar por link
                </div>

                <div class="card-body p-4">
                    <p class="text-secondary">
                        Copie este endereço e adicione como calendário por URL no Google Calendar, Outlook, Apple Calendar ou outro aplicativo compatível.
                    </p>

                    <label
                        class="form-label fw-semibold"
                        for="agendaSubscriptionUrl"
                    >
                        Link de assinatura
                    </label>

                    <div class="input-group mb-3">
                        <input
                            type="text"
                            class="form-control font-monospace"
                            id="agendaSubscriptionUrl"
                            value="<?= e($feedUrl) ?>"
                            readonly
                        >

                        <button
                            type="button"
                            class="btn btn-outline-secondary"
                            id="copyAgendaSubscriptionUrl"
                        >
                            <i class="bi bi-copy me-1"></i>
                            Copiar
                        </button>
                    </div>

                    <div
                        class="small text-success d-none mb-3"
                        id="agendaSubscriptionCopied"
                        aria-live="polite"
                    >
                        Link copiado.
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                        <a
                            class="btn btn-primary"
                            href="<?= e($webcalUrl) ?>"
                        >
                            <i class="bi bi-calendar-plus me-1"></i>
                            Abrir no aplicativo de calendário
                        </a>

                        <a
                            class="btn btn-outline-secondary"
                            href="<?= e($feedUrl) ?>"
                            target="_blank"
                            rel="noopener"
                        >
                            <i class="bi bi-link-45deg me-1"></i>
                            Testar link
                        </a>
                    </div>

                    <div class="alert alert-light border small mt-4 mb-0">
                        <strong>Diferença:</strong>
                        baixar o arquivo cria uma cópia naquele momento.
                        Assinar pelo link permite que o aplicativo consulte novamente a Agenda e receba alterações publicadas no Portal.
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-header bg-white fw-semibold">
                    Compatibilidade
                </div>

                <div class="card-body">
                    <ul class="small mb-0">
                        <li>Google Calendar — adicionar por URL</li>
                        <li>Microsoft Outlook — assinar calendário</li>
                        <li>Apple Calendar — nova assinatura de calendário</li>
                        <li>Thunderbird — calendário na rede</li>
                    </ul>
                </div>
            </div>

            <div class="alert alert-light border small mb-0">
                <i class="bi bi-shield-check me-1"></i>
                O link público contém somente compromissos com status <strong>Publicado</strong>.
            </div>
        </div>
    </div>
</section>

<script>
(() => {
    const button =
        document.getElementById(
            'copyAgendaSubscriptionUrl'
        );

    const input =
        document.getElementById(
            'agendaSubscriptionUrl'
        );

    const feedback =
        document.getElementById(
            'agendaSubscriptionCopied'
        );

    if (
        !button
        || !input
    ) {
        return;
    }

    button.addEventListener(
        'click',
        async () => {
            try {
                await navigator.clipboard.writeText(
                    input.value
                );
            } catch (error) {
                input.focus();
                input.select();
                document.execCommand(
                    'copy'
                );
            }

            feedback?.classList.remove(
                'd-none'
            );

            window.setTimeout(
                () => {
                    feedback?.classList.add(
                        'd-none'
                    );
                },
                2200
            );
        }
    );
})();
</script>

<?php require themeFile($pdo, 'footer.php'); ?>
