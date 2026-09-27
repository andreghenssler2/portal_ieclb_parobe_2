<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

/* PORTAL_HEALTH_REDIRECT_V120_R5 */
Auth::requirePermission('saude.visualizar');

header(
    'Location: ' . url('admin/ferramentas/saude-central.php'),
    true,
    302
);
exit;
