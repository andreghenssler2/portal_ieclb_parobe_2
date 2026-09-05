<?php
/**
 * v0.42.2 - Agenda no final da Home modular.
 * Usa $agenda carregada pelo index.php.
 */
$homeAgendaItems = is_array($agenda ?? null) ? $agenda : [];
?>
<div class="portal-home portal-home--agenda-bottom">
    <section class="home-block home-agenda-bottom" data-home-bottom-agenda>
        <div class="home-block__head">
            <h2>Agenda</h2>
            <a class="home-block__more" href="<?= e(url('agenda')) ?>">
                Ver agenda completa <span aria-hidden="true">›</span>
            </a>
        </div>

        <?php if (!$homeAgendaItems): ?>
            <div class="home-agenda-empty">
                Nenhum culto, festa, atividade ou reunião futura publicada.
            </div>
        <?php else: ?>
            <div class="home-agenda-grid">
                <?php foreach ($homeAgendaItems as $evento): ?>
                    <?php
                    $inicio = new DateTimeImmutable((string)$evento['data_inicio']);
                    $eventUrl = contentUrl('evento', (string)$evento['slug']);
                    ?>
                    <a class="home-agenda-card" href="<?= e($eventUrl) ?>">
                        <span class="home-agenda-date">
                            <strong><?= e($inicio->format('d')) ?></strong>
                            <small><?= e(formatMonthShortBr((string)$evento['data_inicio'])) ?></small>
                        </span>

                        <span class="home-agenda-content">
                            <small>
                                <?= e(eventTypeLabel((string)$evento['tipo'])) ?>
                                · <?= e(formatTimeBr((string)$evento['data_inicio'])) ?>
                                <?php if ((int)($evento['santa_ceia'] ?? 0) === 1): ?>
                                    · Santa Ceia
                                <?php endif; ?>
                            </small>
                            <strong><?= e((string)$evento['titulo']) ?></strong>
                            <span>
                                <?= e((string)($evento['comunidade_nome'] ?: 'Paroquial')) ?>
                                <?php if (!empty($evento['local'])): ?>
                                    · <?= e((string)$evento['local']) ?>
                                <?php endif; ?>
                            </span>
                        </span>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</div>
