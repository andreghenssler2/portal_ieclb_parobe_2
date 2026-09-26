<?php

require_once __DIR__ . '/bootstrap.php';

$pdo = Database::connection();
$filters = EventCalendarService::filters($_GET);
$month = EventCalendarService::normalizeMonth((string)($_GET['mes'] ?? ''));
$monthInfo = EventCalendarService::monthInfo($month);

$view = strtolower(trim((string)($_GET['visualizacao'] ?? 'calendario')));
if (!in_array($view, ['calendario', 'lista'], true)) {
    $view = 'calendario';
}

$listMode = strtolower(trim((string)($_GET['periodo'] ?? 'mes')));
if (!in_array($listMode, ['mes', 'proximos'], true)) {
    $listMode = 'mes';
}

$eventos = $view === 'lista' && $listMode === 'proximos'
    ? EventCalendarService::upcomingEvents($pdo, $filters)
    : EventCalendarService::monthEvents($pdo, $month, $filters);

$calendarDays = $view === 'calendario'
    ? EventCalendarService::calendarDays($month, $eventos)
    : [];

$listGroups = $view === 'lista'
    ? EventCalendarService::groupByDate($eventos)
    : [];

$comunidades = $pdo->query(
    'SELECT id,nome FROM comunidades WHERE ativa=1 ORDER BY ordem,nome'
)->fetchAll() ?: [];

$categorias = $pdo->query(
    'SELECT id,nome FROM evento_categorias WHERE ativa=1 ORDER BY ordem,nome'
)->fetchAll() ?: [];

$baseParams = [];
if ($filters['tipo'] !== '') $baseParams['tipo'] = $filters['tipo'];
if ($filters['comunidade_id'] > 0) $baseParams['comunidade'] = $filters['comunidade_id'];
if ($filters['categoria_id'] > 0) $baseParams['categoria'] = $filters['categoria_id'];
if ($filters['q'] !== '') $baseParams['q'] = $filters['q'];

$monthUrl = static function (string $targetMonth, string $targetView = 'calendario') use ($baseParams): string {
    $params = $baseParams + [
        'mes' => $targetMonth,
        'visualizacao' => $targetView,
        'periodo' => 'mes',
    ];

    return url('agenda') . '?' . http_build_query($params);
};

$viewUrl = static function (string $targetView, string $period = 'mes') use ($baseParams, $month): string {
    $params = $baseParams + [
        'mes' => $month,
        'visualizacao' => $targetView,
        'periodo' => $period,
    ];

    return url('agenda') . '?' . http_build_query($params);
};

$todayMonth = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m');

$siteLabel = siteConfig($pdo, 'seo_titulo', 'IECLB Parobé');
$metaTitle = 'Agenda - ' . $siteLabel;
$metaDescription = 'Calendário de cultos, festas, atividades e reuniões da Paróquia Evangélica de Confissão Luterana de Parobé.';
$canonicalUrl = url('agenda');

require themeFile($pdo, 'header.php');
?>
<style>
.portal-calendar-shell{overflow-x:auto;padding-bottom:.5rem}
.portal-calendar{min-width:900px;display:grid;grid-template-columns:repeat(7,minmax(0,1fr));border-left:1px solid var(--bs-border-color);border-top:1px solid var(--bs-border-color);background:var(--bs-body-bg)}
.portal-calendar-weekday{padding:.65rem .75rem;text-align:center;font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;background:var(--bs-tertiary-bg);border-right:1px solid var(--bs-border-color);border-bottom:1px solid var(--bs-border-color)}
.portal-calendar-day{min-height:145px;padding:.55rem;border-right:1px solid var(--bs-border-color);border-bottom:1px solid var(--bs-border-color);background:var(--bs-body-bg)}
.portal-calendar-day.is-outside{background:var(--bs-tertiary-bg);opacity:.62}
.portal-calendar-day.is-today{box-shadow:inset 0 0 0 2px var(--bs-primary)}
.portal-calendar-number{display:inline-flex;align-items:center;justify-content:center;width:1.8rem;height:1.8rem;border-radius:50%;font-weight:700;font-size:.9rem}
.portal-calendar-day.is-today .portal-calendar-number{background:var(--bs-primary);color:#fff}
.portal-calendar-event{display:block;position:relative;z-index:1;text-decoration:none;border:1px solid var(--bs-border-color);border-left:4px solid var(--bs-primary);border-radius:.45rem;padding:.35rem .45rem;margin-top:.35rem;background:var(--bs-body-bg);color:var(--bs-body-color);font-size:.78rem;line-height:1.25}
.portal-calendar-event.is-culto{border-left-color:var(--bs-primary)}
.portal-calendar-event.is-festa{border-left-color:var(--bs-danger)}
.portal-calendar-event.is-atividade{border-left-color:var(--bs-success)}
.portal-calendar-event.is-reuniao{border-left-color:var(--bs-secondary)}
.portal-calendar-event:hover{background:var(--bs-tertiary-bg)}
.portal-calendar-time{display:block;font-size:.7rem;color:var(--bs-secondary-color);margin-bottom:.1rem}
.portal-agenda-toolbar .btn{white-space:nowrap}
/* PORTAL_AGENDA_BUTTONS_V115 */
.portal-agenda-filter-actions .btn{display:inline-flex;align-items:center;justify-content:center;gap:.35rem;min-height:38px}
.portal-agenda-month-arrow{font-size:1.35rem;line-height:1;font-weight:700}
.portal-agenda-list-date{position:sticky;top:0;z-index:2;background:var(--bs-body-bg)}
/* PORTAL_AGENDA_V117 */
.portal-agenda-legend{display:flex;flex-wrap:wrap;gap:.65rem 1rem;align-items:center}
.portal-agenda-legend-item{display:inline-flex;align-items:center;gap:.4rem;font-size:.9rem}
.portal-agenda-legend-dot{width:.75rem;height:.75rem;border-radius:50%;display:inline-block}
.portal-agenda-legend-dot.is-culto{background:var(--bs-primary)}
.portal-agenda-legend-dot.is-festa{background:var(--bs-danger)}
.portal-agenda-legend-dot.is-atividade{background:var(--bs-success)}
.portal-agenda-legend-dot.is-reuniao{background:var(--bs-secondary)}
.portal-google-calendar{white-space:nowrap}
@media(max-width:767.98px){
    .portal-calendar-shell{overflow:visible!important;box-shadow:none!important}
    .portal-calendar{min-width:0;display:block;border:0;background:transparent}
    .portal-calendar-weekday{display:none}
    .portal-calendar-day{display:none;min-height:0;border:1px solid var(--bs-border-color);border-radius:.75rem;margin-bottom:.75rem;padding:.8rem;background:var(--bs-body-bg)}
    .portal-calendar-day.has-events,.portal-calendar-day.is-today{display:block}
    .portal-calendar-day.is-outside{display:none}
    .portal-calendar-day-mobile{display:block;font-size:.78rem;color:var(--bs-secondary-color);font-weight:600;margin-left:.45rem}
    .portal-calendar-number{width:2rem;height:2rem}
    .portal-calendar-event{font-size:.9rem;padding:.55rem .65rem;margin-top:.55rem}
    .portal-calendar-time{font-size:.78rem}
}
@media(min-width:768px){
    .portal-calendar-day-mobile{display:none}
}
@media print{
    header,.portal-site-header,footer,.portal-site-footer,.portal-agenda-controls,.portal-agenda-print-hide{display:none!important}
    body{background:#fff!important}
    .container{max-width:none!important;width:100%!important}
    .portal-calendar-shell{overflow:visible!important;box-shadow:none!important}
    .portal-calendar{min-width:0!important}
    .portal-calendar-day{break-inside:avoid}
    a{text-decoration:none!important;color:#000!important}
}
@media(max-width:767.98px){
    .portal-calendar{min-width:760px}
    .portal-calendar-day{min-height:125px}
}
</style>

<section class="container py-5">
    <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
        <div>
            <h1 class="display-6 fw-bold mb-2">Agenda</h1>
            <p class="lead text-secondary mb-0">Cultos, festas, atividades e reuniões da Paróquia de Parobé.</p>
        </div>

        <div class="d-flex flex-wrap gap-2 portal-agenda-print-hide">
                        <a
                class="btn btn-success"
                href="<?=e(url('agenda-exportar.php'))?>"
            >
                <i class="bi bi-link-45deg me-1"></i>Exportar / Assinar
            </a>
<button
                class="btn btn-outline-secondary"
                type="button"
                onclick="window.print()"
            >
                <i class="bi bi-printer me-1"></i>Imprimir agenda
            </button>
<a
                class="btn btn-outline-success"
                href="<?=e(EventCalendarService::agendaIcsUrl($filters, $view === 'calendario' || $listMode === 'mes' ? $month : null))?>"
            >
                <i class="bi bi-calendar-plus me-1"></i>Exportar .ics
            </a>
            <span class="badge text-bg-light border fs-6 align-self-center">
                <?=count($eventos)?> compromisso<?=count($eventos) === 1 ? '' : 's'?>
            </span>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4 portal-agenda-controls">
        <div class="card-body p-3 p-lg-4">
            <form method="get" action="<?=e(url('agenda'))?>">
                <input type="hidden" name="mes" value="<?=e($month)?>">
                <input type="hidden" name="visualizacao" value="<?=e($view)?>">
                <input type="hidden" name="periodo" value="<?=e($listMode)?>">

                <div class="row g-3 align-items-end">
                    <div class="col-xl-3 col-md-6">
                        <label class="form-label">Buscar</label>
                        <input
                            class="form-control"
                            name="q"
                            value="<?=e($filters['q'])?>"
                            placeholder="Título, descrição ou local..."
                        >
                    </div>

                    <div class="col-xl-2 col-md-6">
                        <label class="form-label">Tipo</label>
                        <select class="form-select" name="tipo">
                            <option value="">Todos</option>
                            <option value="culto" <?=$filters['tipo'] === 'culto' ? 'selected' : ''?>>Cultos</option>
                            <option value="festa" <?=$filters['tipo'] === 'festa' ? 'selected' : ''?>>Festas</option>
                            <option value="atividade" <?=$filters['tipo'] === 'atividade' ? 'selected' : ''?>>Atividades</option>
                            <option value="reuniao" <?=$filters['tipo'] === 'reuniao' ? 'selected' : ''?>>Reuniões</option>
                </select>
                    </div>

                    <div class="col-xl-2 col-md-6">
                        <label class="form-label">Categoria</label>
                        <select class="form-select" name="categoria">
                            <option value="">Todas</option>
                            <?php foreach($categorias as $categoria):?>
                                <option
                                    value="<?=(int)$categoria['id']?>"
                                    <?=$filters['categoria_id'] === (int)$categoria['id'] ? 'selected' : ''?>
                                >
                                    <?=e($categoria['nome'])?>
                                </option>
                            <?php endforeach;?>
                        </select>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <label class="form-label">Comunidade</label>
                        <select class="form-select" name="comunidade">
                            <option value="">Todas</option>
                            <?php foreach($comunidades as $comunidade):?>
                                <option
                                    value="<?=(int)$comunidade['id']?>"
                                    <?=$filters['comunidade_id'] === (int)$comunidade['id'] ? 'selected' : ''?>
                                >
                                    <?=e($comunidade['nome'])?>
                                </option>
                            <?php endforeach;?>
                        </select>
                    </div>

                    <div class="col-xl-2 col-md-6 d-flex gap-2 portal-agenda-filter-actions">
                        <button class="btn btn-primary flex-grow-1" title="Filtrar" type="submit">
                            <i class="bi bi-funnel" aria-hidden="true"></i>
                            <span>Filtrar</span>
                        </button>
                        <a class="btn btn-outline-secondary" href="<?=e(url('agenda'))?>" title="Limpar">
                            <i class="bi bi-x-lg" aria-hidden="true"></i>
                            <span>Limpar</span>
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 portal-agenda-toolbar portal-agenda-controls mb-4">
        <div class="btn-group" role="group" aria-label="Navegação mensal">
            <a
                class="btn btn-outline-secondary"
                href="<?=e($monthUrl($monthInfo['previous'], $view))?>"
                title="Mês anterior"
            >
                <span class="portal-agenda-month-arrow" aria-hidden="true">‹</span><span class="visually-hidden">Mês anterior</span>
            </a>

            <a
                class="btn btn-outline-secondary"
                href="<?=e($monthUrl($todayMonth, $view))?>"
            >
                Hoje
            </a>

            <a
                class="btn btn-outline-secondary"
                href="<?=e($monthUrl($monthInfo['next'], $view))?>"
                title="Próximo mês"
            >
                <span class="portal-agenda-month-arrow" aria-hidden="true">›</span><span class="visually-hidden">Próximo mês</span>
            </a>
        </div>

        <h2 class="h4 mb-0 text-center"><?=e($monthInfo['label'])?></h2>

        <div class="d-flex flex-wrap gap-2">
            <?php if($view === 'lista'):?>
                <div class="btn-group" role="group">
                    <a
                        class="btn <?=$listMode === 'mes' ? 'btn-secondary' : 'btn-outline-secondary'?>"
                        href="<?=e($viewUrl('lista', 'mes'))?>"
                    >
                        Mês
                    </a>
                    <a
                        class="btn <?=$listMode === 'proximos' ? 'btn-secondary' : 'btn-outline-secondary'?>"
                        href="<?=e($viewUrl('lista', 'proximos'))?>"
                    >
                        Próximos
                    </a>
                </div>
            <?php endif;?>

            <div class="btn-group" role="group" aria-label="Visualização">
                <a
                    class="btn <?=$view === 'calendario' ? 'btn-primary' : 'btn-outline-primary'?>"
                    href="<?=e($viewUrl('calendario', 'mes'))?>"
                    title="Calendário"
                >
                    <i class="bi bi-calendar3"></i>
                    <span class="ms-1">Calendário</span>
                </a>
                <a
                    class="btn <?=$view === 'lista' ? 'btn-primary' : 'btn-outline-primary'?>"
                    href="<?=e($viewUrl('lista', $listMode))?>"
                    title="Lista"
                >
                    <i class="bi bi-list-ul"></i>
                    <span class="ms-1">Lista</span>
                </a>
            </div>
        </div>
    </div>

    <div class="portal-agenda-legend mb-4 portal-agenda-print-hide" aria-label="Legenda">
        <span class="fw-semibold">Legenda:</span>
        <span class="portal-agenda-legend-item"><span class="portal-agenda-legend-dot is-culto"></span>Culto</span>
        <span class="portal-agenda-legend-item"><span class="portal-agenda-legend-dot is-festa"></span>Festa</span>
        <span class="portal-agenda-legend-item"><span class="portal-agenda-legend-dot is-atividade"></span>Atividade</span>
        <span class="portal-agenda-legend-item"><span class="portal-agenda-legend-dot is-reuniao"></span>Reunião</span>
    </div>
    <?php if(!$eventos):?>
        <div class="alert alert-light border">
            Nenhum item da agenda encontrado com esses filtros.
        </div>
    <?php elseif($view === 'calendario'):?>
        <div class="portal-calendar-shell shadow-sm rounded overflow-auto">
            <div class="portal-calendar">
                <?php foreach(['Seg','Ter','Qua','Qui','Sex','Sáb','Dom'] as $weekday):?>
                    <div class="portal-calendar-weekday"><?=e($weekday)?></div>
                <?php endforeach;?>

                <?php foreach($calendarDays as $day):?>
                    <div class="portal-calendar-day <?=$day['in_month'] ? '' : 'is-outside'?> <?=$day['is_today'] ? 'is-today' : ''?> <?=$day['events'] ? 'has-events' : ''?>">
                        <div class="portal-calendar-number"><?=$day['day']?></div>
                        <span class="portal-calendar-day-mobile"><?=e(EventCalendarService::dateLabel((string)$day['date']))?></span>

                        <?php foreach($day['events'] as $evento):?>
                            <a
                                class="portal-calendar-event is-<?=e((string)$evento['tipo'])?>"
                                href="<?=e(contentUrl('evento', (string)$evento['slug']))?>"
                                title="<?=e($evento['titulo'])?>"
                            >
                                <span class="portal-calendar-time">
                                    <?=e(EventCalendarService::timeLabel($evento))?>
                                </span>
                                <span class="fw-semibold"><?=e($evento['titulo'])?></span>
                            </a>
                        <?php endforeach;?>
                    </div>
                <?php endforeach;?>
            </div>
        </div>
        <div class="small text-secondary mt-2 d-md-none">
            <i class="bi bi-arrows me-1"></i>Deslize horizontalmente para visualizar toda a semana.
        </div>
    <?php else:?>
        <?php foreach($listGroups as $date => $items):?>
            <div class="portal-agenda-list-date py-2 border-bottom mb-3">
                <h3 class="h5 mb-0"><?=e(EventCalendarService::dateLabel($date))?></h3>
            </div>

            <div class="row g-3 mb-4">
                <?php foreach($items as $evento):?>
                    <div class="col-lg-6">
                        <article class="card h-100 border-0 shadow-sm">
                            <div class="card-body p-4">
                                <div class="d-flex flex-wrap gap-2 mb-3">
                                    <span class="badge <?=e(eventTypeBadgeClass((string)$evento['tipo']))?>">
                                        <?=e(eventTypeLabel($evento['tipo']))?>
                                    </span>
                                    <?php if($evento['categoria_nome']):?>
                                        <span class="badge text-bg-light border"><?=e($evento['categoria_nome'])?></span>
                                    <?php endif;?>
                                    <?php if((int)$evento['santa_ceia'] === 1):?>
                                        <span class="badge text-bg-light border">Santa Ceia</span>
                                    <?php endif;?>
                                </div>

                                <div class="small text-secondary mb-1">
                                    <i class="bi bi-clock me-1"></i><?=e(EventCalendarService::timeLabel($evento))?>
                                </div>

                                <h4 class="h5 mb-2">
                                    <a
                                        class="text-decoration-none"
                                        href="<?=e(contentUrl('evento', (string)$evento['slug']))?>"
                                    >
                                        <?=e($evento['titulo'])?>
                                    </a>
                                </h4>

                                <?php if($evento['local'] || $evento['comunidade_nome']):?>
                                    <div class="small text-secondary mb-2">
                                        <i class="bi bi-geo-alt me-1"></i>
                                        <?=e($evento['local'] ?: $evento['comunidade_nome'])?>
                                    </div>
                                <?php endif;?>

                                <?php if($evento['resumo']):?>
                                    <p class="text-secondary mb-3"><?=e($evento['resumo'])?></p>
                                <?php endif;?>

                                <div class="d-flex flex-wrap gap-2">
                                    <a class="btn btn-sm btn-outline-primary" href="<?=e(contentUrl('evento', (string)$evento['slug']))?>">
                                        Ver detalhes
                                    </a>
                                    <?php $googleCalendarUrl = EventCalendarService::googleCalendarUrl($evento); ?>
                                    <?php if ($googleCalendarUrl !== ''): ?>
                                        <a
                                            class="btn btn-sm btn-outline-primary portal-google-calendar"
                                            target="_blank"
                                            rel="noopener"
                                            href="<?=e($googleCalendarUrl)?>"
                                        >
                                            <i class="bi bi-google me-1"></i>Google Agenda
                                        </a>
                                    <?php endif; ?>
                                    <a class="btn btn-sm btn-outline-success" href="<?=e(EventCalendarService::eventIcsUrl($evento))?>">
                                        <i class="bi bi-calendar-plus me-1"></i>.ics
                                    </a>
                                </div>
                            </div>
                        </article>
                    </div>
                <?php endforeach;?>
            </div>
        <?php endforeach;?>
    <?php endif;?>
</section>
<?php require themeFile($pdo, 'footer.php'); ?>
