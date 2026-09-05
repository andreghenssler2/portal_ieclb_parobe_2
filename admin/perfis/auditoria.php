<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

Auth::requirePermission('permissoes.gerenciar');

$pdo = Database::connection();

if (!class_exists('PermissionAuditService')) {
    throw new RuntimeException(
        'PermissionAuditService indisponível.'
    );
}

$report =
    PermissionAuditService::report(
        $pdo,
        dirname(__DIR__, 2)
    );

if (($_GET['export'] ?? '') === 'csv') {
    $filename =
        'auditoria-permissoes-'
        . date('Ymd-His')
        . '.csv';

    header(
        'Content-Type: text/csv; charset=UTF-8'
    );

    header(
        'Content-Disposition: attachment; filename="'
        . $filename
        . '"'
    );

    echo "\xEF\xBB\xBF";

    $out =
        fopen(
            'php://output',
            'wb'
        );

    if ($out !== false) {
        fputcsv(
            $out,
            [
                'Permissão',
                'Grupo',
                ...array_map(
                    static fn(array $profile): string =>
                        (string)$profile['profile_name'],
                    $report['matrix']
                ),
            ],
            ';'
        );

        foreach ($report['permissions'] as $permission) {
            $row = [
                (string)$permission['slug'],
                (string)$permission['grupo'],
            ];

            foreach ($report['matrix'] as $profile) {
                $row[] =
                    in_array(
                        (string)$permission['slug'],
                        $profile['permissions'],
                        true
                    )
                        ? 'Sim'
                        : 'Não';
            }

            fputcsv(
                $out,
                $row,
                ';'
            );
        }

        fclose($out);
    }

    exit;
}

$summary =
    $report['summary'];

$pageTitle =
    'Auditoria de Permissões';

require __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">
            Auditoria de Permissões
        </h1>

        <p class="text-secondary mb-0">
            Revisão de perfis, permissões, usuários e proteção das páginas administrativas.
        </p>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <a
            class="btn btn-outline-secondary"
            href="<?= e(url('admin/perfis/index.php#permissoes')) ?>"
        >
            Gerenciar permissões
        </a>

        <a
            class="btn btn-outline-primary"
            href="<?= e(url('admin/perfis/auditoria.php?export=csv')) ?>"
        >
            Exportar matriz CSV
        </a>

        <a
            class="btn btn-primary"
            href="<?= e(url('admin/perfis/auditoria.php')) ?>"
        >
            Atualizar auditoria
        </a>
    </div>
</div>

<div class="alert alert-light border d-flex align-items-start gap-2">
    <i class="bi bi-shield-check fs-5"></i>
    <div>
        <strong>Auditoria somente leitura.</strong>
        Esta página não altera perfis nem permissões.
        As mudanças continuam sendo feitas em
        <a href="<?= e(url('admin/perfis/index.php#permissoes')) ?>">Perfis e Permissões</a>.
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-2">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">Perfis</div>
                <div class="fs-4 fw-bold"><?= (int)$summary['profiles'] ?></div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-2">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">Permissões</div>
                <div class="fs-4 fw-bold"><?= (int)$summary['permissions'] ?></div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-2">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">Usuários ativos</div>
                <div class="fs-4 fw-bold"><?= (int)$summary['active_users'] ?></div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-2">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">Páginas auditadas</div>
                <div class="fs-4 fw-bold"><?= (int)$summary['routes'] ?></div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-2">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">Avisos</div>
                <div class="fs-4 fw-bold <?= $report['warnings'] ? 'text-warning' : 'text-success' ?>">
                    <?= count($report['warnings']) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-6 col-lg-2">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="small text-secondary">Erros</div>
                <div class="fs-4 fw-bold <?= $report['errors'] ? 'text-danger' : 'text-success' ?>">
                    <?= count($report['errors']) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($report['errors']): ?>
    <div class="alert alert-danger">
        <div class="fw-semibold mb-2">
            Problemas que exigem revisão
        </div>

        <ul class="mb-0">
            <?php foreach ($report['errors'] as $message): ?>
                <li><?= e((string)$message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php else: ?>
    <div class="alert alert-success">
        Nenhum erro de integridade de permissões foi encontrado.
    </div>
<?php endif; ?>

<?php if ($report['warnings']): ?>
    <div class="alert alert-warning">
        <div class="fw-semibold mb-2">
            Pontos para conferência
        </div>

        <ul class="mb-0">
            <?php foreach ($report['warnings'] as $message): ?>
                <li><?= e((string)$message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="fw-semibold">
            Perfis
        </div>

        <small class="text-secondary">
            Usuários e quantidade de permissões efetivas
        </small>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Perfil</th>
                    <th>Slug</th>
                    <th class="text-end">Permissões</th>
                    <th class="text-end">Usuários</th>
                    <th class="text-end">Ativos</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report['matrix'] as $profile): ?>
                    <tr>
                        <td>
                            <strong><?= e((string)$profile['profile_name']) ?></strong>
                            <?php if ($profile['administrator']): ?>
                                <span class="badge text-bg-primary ms-1">Acesso total</span>
                            <?php endif; ?>
                        </td>
                        <td><code><?= e((string)$profile['profile_slug']) ?></code></td>
                        <td class="text-end"><?= (int)$profile['permission_count'] ?></td>
                        <td class="text-end"><?= (int)$profile['users'] ?></td>
                        <td class="text-end"><?= (int)$profile['active_users'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">
        Matriz de acesso
    </div>

    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="min-width: 260px;">Permissão</th>
                    <?php foreach ($report['matrix'] as $profile): ?>
                        <th class="text-center" style="min-width: 110px;">
                            <?= e((string)$profile['profile_name']) ?>
                        </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php
                $lastGroup = null;
                foreach ($report['permissions'] as $permission):
                    $group = (string)$permission['grupo'];
                    if ($group !== $lastGroup):
                        $lastGroup = $group;
                ?>
                    <tr class="table-light">
                        <th colspan="<?= count($report['matrix']) + 1 ?>">
                            <?= e($group) ?>
                        </th>
                    </tr>
                <?php endif; ?>

                    <tr>
                        <td>
                            <div class="fw-semibold">
                                <?= e((string)$permission['nome']) ?>
                            </div>
                            <code><?= e((string)$permission['slug']) ?></code>
                        </td>

                        <?php foreach ($report['matrix'] as $profile): ?>
                            <?php
                            $allowed =
                                in_array(
                                    (string)$permission['slug'],
                                    $profile['permissions'],
                                    true
                                );
                            ?>
                            <td class="text-center">
                                <?php if ($allowed): ?>
                                    <span class="badge text-bg-success">Sim</span>
                                <?php else: ?>
                                    <span class="badge text-bg-light border text-secondary">Não</span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="fw-semibold">
            Proteção das páginas administrativas
        </div>

        <span class="badge <?= (int)$summary['unguarded_routes'] > 0 ? 'text-bg-warning' : 'text-bg-success' ?>">
            <?= (int)$summary['unguarded_routes'] ?> sem proteção detectável
        </span>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Arquivo</th>
                    <th>Proteção</th>
                    <th>Permissão exigida</th>
                    <th>Permissões consultadas</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report['routes'] as $route): ?>
                    <tr>
                        <td><code><?= e((string)$route['file']) ?></code></td>
                        <td>
                            <?php
                            $protection = (string)$route['protection'];

                            $badgeClass = match ($protection) {
                                'permission' => 'text-bg-success',
                                'login' => 'text-bg-info',
                                'public' => 'text-bg-secondary',
                                default => 'text-bg-warning',
                            };

                            $label = match ($protection) {
                                'permission' => 'Permissão',
                                'login' => 'Login',
                                'public' => 'Pública',
                                default => 'Não detectada',
                            };
                            ?>
                            <span class="badge <?= e($badgeClass) ?>">
                                <?= e($label) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($route['required_permissions']): ?>
                                <?php foreach ($route['required_permissions'] as $permission): ?>
                                    <code class="d-block"><?= e((string)$permission) ?></code>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-secondary">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($route['can_permissions']): ?>
                                <?php foreach ($route['can_permissions'] as $permission): ?>
                                    <code class="d-block"><?= e((string)$permission) ?></code>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="text-secondary">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($report['unused_permissions']): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">
            Permissões não localizadas na análise estática
        </div>

        <div class="card-body">
            <div class="d-flex flex-wrap gap-2">
                <?php foreach ($report['unused_permissions'] as $permission): ?>
                    <span class="badge text-bg-light border text-secondary">
                        <?= e((string)$permission['slug']) ?>
                    </span>
                <?php endforeach; ?>
            </div>

            <div class="small text-secondary mt-3">
                Isto não significa necessariamente que a permissão esteja incorreta.
                Ela pode ser utilizada fora da pasta Admin ou em um fluxo dinâmico.
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white fw-semibold">
        Observações da auditoria
    </div>

    <div class="card-body">
        <ul class="mb-0">
            <?php foreach ($report['notes'] as $note): ?>
                <li><?= e((string)$note) ?></li>
            <?php endforeach; ?>
        </ul>

        <div class="small text-secondary mt-3">
            Gerado em <?= e((string)$report['generated_at']) ?>.
        </div>
    </div>
</div>

<?php require __DIR__ . '/../_footer.php'; ?>
