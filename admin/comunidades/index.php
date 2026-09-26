<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

Auth::requireLogin();
Auth::requirePermission('comunidades.gerenciar');

$pdo =
    Database::connection();

CommunityProfileService::ensureSchema(
    $pdo
);

$comunidades =
    $pdo->query(
        'SELECT
            c.*,
            cp.pastor_nome,
            cp.telefone,
            cp.whatsapp,
            cp.email
         FROM comunidades c
         LEFT JOIN comunidade_perfis cp
            ON cp.comunidade_id=c.id
         ORDER BY c.ordem,c.nome'
    )->fetchAll();

$pageTitle =
    'Comunidades';

require __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">
            Comunidades
        </h1>

        <p class="text-secondary mb-0">
            Gerencie páginas, endereço, cultos, contatos, redes e fotos das comunidades.
        </p>
    </div>

    <a
        class="btn btn-primary"
        href="<?= e(url('admin/comunidades/form.php')) ?>"
    >
        <i class="bi bi-plus-lg me-1"></i>
        Nova comunidade
    </a>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table mb-0 align-middle">
            <thead>
                <tr>
                    <th>Nome</th>
                    <th>Cidade</th>
                    <th>Responsável</th>
                    <th>Status</th>
                    <th>Ordem</th>
                    <th></th>
                </tr>
            </thead>

            <tbody>
                <?php foreach ($comunidades as $item): ?>
                    <tr>
                        <td class="fw-semibold">
                            <?= e((string)$item['nome']) ?>
                        </td>

                        <td>
                            <?= e(
                                trim(
                                    (string)($item['cidade'] ?? '')
                                    . '/'
                                    . (string)($item['uf'] ?? ''),
                                    '/'
                                )
                            ) ?>
                        </td>

                        <td>
                            <?= e(
                                (string)($item['pastor_nome'] ?: '—')
                            ) ?>
                        </td>

                        <td>
                            <?= !empty($item['ativa'])
                                ? '<span class="badge text-bg-success">Ativa</span>'
                                : '<span class="badge text-bg-secondary">Inativa</span>' ?>
                        </td>

                        <td>
                            <?= (int)$item['ordem'] ?>
                        </td>

                        <td class="text-end">
                            <div class="d-inline-flex gap-2">
                                <?php if (!empty($item['ativa'])): ?>
                                    <a
                                        class="btn btn-sm btn-outline-primary"
                                        target="_blank"
                                        rel="noopener"
                                        href="<?= e(
                                            url(
                                                'comunidade.php?slug='
                                                . rawurlencode(
                                                    (string)$item['slug']
                                                )
                                            )
                                        ) ?>"
                                    >
                                        Ver página
                                    </a>
                                <?php endif; ?>

                                <a
                                    class="btn btn-sm btn-outline-secondary"
                                    href="<?= e(
                                        url(
                                            'admin/comunidades/form.php?id='
                                            . (int)$item['id']
                                        )
                                    ) ?>"
                                >
                                    Editar
                                </a>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if (!$comunidades): ?>
                    <tr>
                        <td
                            colspan="6"
                            class="text-center text-secondary py-5"
                        >
                            Nenhuma comunidade cadastrada.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require __DIR__ . '/../_footer.php'; ?>
