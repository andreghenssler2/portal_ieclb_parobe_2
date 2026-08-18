<?php
$footerPdo = $pdo ?? Database::connection();
$footerSettings = $siteSettings ?? siteConfigAll($footerPdo);
$footerText = trim((string)($footerSettings['footer_texto'] ?? '')) ?: 'Paróquia Evangélica de Confissão Luterana de Parobé';
$footerEmail = trim((string)($footerSettings['site_email'] ?? ''));
$footerPhone = trim((string)($footerSettings['site_telefone'] ?? ''));
$footerAddress = trim((string)($footerSettings['site_endereco'] ?? ''));
$socials = [
    'Instagram' => trim((string)($footerSettings['site_instagram'] ?? '')),
    'YouTube' => trim((string)($footerSettings['site_youtube'] ?? '')),
    'Facebook' => trim((string)($footerSettings['site_facebook'] ?? '')),
];
?>
</main>
<footer class="border-top mt-5 py-4 bg-light">
    <div class="container">
        <div class="row g-3 align-items-start">
            <div class="col-lg-7">
                <div class="fw-semibold">© <?= date('Y') ?> <?= e($footerText) ?></div>
                <?php if ($footerAddress): ?><div class="small text-secondary mt-1"><?= e($footerAddress) ?></div><?php endif; ?>
                <?php if ($footerEmail || $footerPhone): ?>
                    <div class="small text-secondary mt-1">
                        <?php if ($footerEmail): ?><a class="text-secondary text-decoration-none" href="mailto:<?= e($footerEmail) ?>"><?= e($footerEmail) ?></a><?php endif; ?>
                        <?php if ($footerEmail && $footerPhone): ?> · <?php endif; ?>
                        <?php if ($footerPhone): ?><?= e($footerPhone) ?><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="col-lg-5 text-lg-end">
                <?php foreach ($socials as $label => $socialUrl): ?>
                    <?php if ($socialUrl): ?><a class="footer-social-link" href="<?= e($socialUrl) ?>" target="_blank" rel="noopener"><?= e($label) ?></a><?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
