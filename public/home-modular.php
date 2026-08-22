<?php
/** v0.28.2 - fragmento modular da página inicial. */
$homePdo = Database::connection();
$homeService = new HomeService($homePdo);
$homeSections = $homeService->sections(true);
$homeResolveLink = static function (string $value) use ($homeService): string {
    return $homeService->publicUrl($value);
};
?>
<link rel="stylesheet" href="<?= e(url('public/css/home-modular.css?v=' . rawurlencode(defined('APP_VERSION') ? (string)APP_VERSION : '0.28.2'))) ?>">
<div class="portal-home" id="portalHomeV028">
<?php foreach ($homeSections as $section): ?>
    <?php
    $items = $homeService->itemsForSection($section);
    if (!$items) continue;
    $config = $homeService->config($section);
    $type = (string)($section['tipo'] ?? 'carousel');
    $source = (string)($section['origem'] ?? 'posts');
    $sectionId = 'home-section-' . (int)$section['id'];
    $linkText = trim((string)($section['link_texto'] ?? ''));
    $linkUrl = $homeResolveLink((string)($section['link_url'] ?? ''));
    ?>
    <section class="home-block home-block--<?= e($type) ?>" id="<?= e($sectionId) ?>" data-home-autoplay="<?= !empty($config['autoplay']) ? '1' : '0' ?>">
        <div class="home-block__head">
            <h2><?= e((string)$section['titulo']) ?></h2>
            <?php if ($linkText !== '' && $linkUrl !== ''): ?>
                <a class="home-block__more" href="<?= e($linkUrl) ?>"><?= e($linkText) ?><span aria-hidden="true">›</span></a>
            <?php endif; ?>
        </div>

        <?php if ($type === 'featured'): ?>
            <?php $lead = $items[0] ?? null; $side = array_slice($items, 1, 2); ?>
            <div class="home-featured<?= count($side) < 1 ? ' home-featured--single' : '' ?>">
                <?php if ($lead): ?>
                    <?php $img = $homeService->itemImage($lead, $source); $date = $homeService->itemDate($lead, $source); ?>
                    <a class="home-featured__lead" href="<?= e($homeService->itemUrl($lead, $source)) ?>">
                        <?php if ($img !== ''): ?><img src="<?= e($img) ?>" alt="" loading="eager"><?php else: ?><span class="home-media-placeholder"></span><?php endif; ?>
                        <span class="home-featured__shade"></span>
                        <span class="home-featured__content">
                            <?php if (!empty($config['show_date']) && $date): ?><span class="home-card__date home-card__date--light"><?= e($date->format('d/m/Y')) ?></span><?php endif; ?>
                            <strong><?= e($homeService->itemTitle($lead)) ?></strong>
                            <?php if (!empty($config['show_excerpt'])): ?><small><?= e($homeService->itemExcerpt($lead)) ?></small><?php endif; ?>
                        </span>
                    </a>
                <?php endif; ?>
                <?php if ($side): ?>
                    <div class="home-featured__side">
                        <?php foreach ($side as $item): ?>
                            <?php $img = $homeService->itemImage($item, $source); $date = $homeService->itemDate($item, $source); ?>
                            <a class="home-featured__small" href="<?= e($homeService->itemUrl($item, $source)) ?>">
                                <?php if ($img !== ''): ?><img src="<?= e($img) ?>" alt="" loading="lazy"><?php else: ?><span class="home-media-placeholder"></span><?php endif; ?>
                                <span class="home-featured__shade"></span>
                                <span class="home-featured__content home-featured__content--small">
                                    <?php if (!empty($config['show_date']) && $date): ?><span class="home-card__date home-card__date--light"><?= e($date->format('d/m/Y')) ?></span><?php endif; ?>
                                    <strong><?= e($homeService->itemTitle($item)) ?></strong>
                                </span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        <?php elseif ($type === 'grid'): ?>
            <div class="home-grid">
                <?php foreach ($items as $item): ?>
                    <?php $img = $homeService->itemImage($item, $source); $date = $homeService->itemDate($item, $source); ?>
                    <article class="home-card">
                        <a class="home-card__image" href="<?= e($homeService->itemUrl($item, $source)) ?>"><?php if ($img !== ''): ?><img src="<?= e($img) ?>" alt="" loading="lazy"><?php else: ?><span class="home-media-placeholder"></span><?php endif; ?></a>
                        <div class="home-card__body">
                            <?php if (!empty($config['show_date']) && $date): ?><div class="home-card__date"><?= e($date->format('d/m/Y')) ?></div><?php endif; ?>
                            <h3><a href="<?= e($homeService->itemUrl($item, $source)) ?>"><?= e($homeService->itemTitle($item)) ?></a></h3>
                            <?php if (!empty($config['show_excerpt'])): ?><p><?= e($homeService->itemExcerpt($item)) ?></p><?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

        <?php else: ?>
            <div class="home-carousel-wrap">
                <button class="home-carousel-arrow home-carousel-arrow--prev" type="button" aria-label="Anterior" data-home-prev>‹</button>
                <div class="home-carousel" data-home-carousel>
                    <?php foreach ($items as $item): ?>
                        <?php $img = $homeService->itemImage($item, $source); $date = $homeService->itemDate($item, $source); ?>
                        <article class="home-card home-card--carousel">
                            <a class="home-card__image" href="<?= e($homeService->itemUrl($item, $source)) ?>"><?php if ($img !== ''): ?><img src="<?= e($img) ?>" alt="" loading="lazy"><?php else: ?><span class="home-media-placeholder"></span><?php endif; ?></a>
                            <div class="home-card__body">
                                <?php if (!empty($config['show_date']) && $date): ?><div class="home-card__date"><?= e($date->format('d/m/Y')) ?></div><?php endif; ?>
                                <h3><a href="<?= e($homeService->itemUrl($item, $source)) ?>"><?= e($homeService->itemTitle($item)) ?></a></h3>
                                <?php if (!empty($config['show_excerpt'])): ?><p><?= e($homeService->itemExcerpt($item)) ?></p><?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
                <button class="home-carousel-arrow home-carousel-arrow--next" type="button" aria-label="Próximo" data-home-next>›</button>
            </div>
        <?php endif; ?>
    </section>
<?php endforeach; ?>
</div>
<script defer src="<?= e(url('public/js/home-modular.js?v=' . rawurlencode(defined('APP_VERSION') ? (string)APP_VERSION : '0.28.2'))) ?>"></script>
