<?php

declare(strict_types=1);

require_once __DIR__ . '/../../bootstrap.php';

Auth::requirePermission(
    'formularios.gerenciar'
);

$pdo =
    Database::connection();

$id =
    max(
        0,
        (int)(
            $_GET['id']
            ?? 0
        )
    );

FormReplyService::ensureSchema(
    $pdo
);

$context =
    FormReplyService::context(
        $pdo,
        $id
    );

if (!$context) {
    http_response_code(404);
    exit('Resposta não encontrada.');
}

$resp =
    $context['response'];

$form =
    $context['form'];

$values =
    $context['fields'];

$recipient =
    (string)$context['recipient'];

$replyError = '';

$replySubject =
    FormReplyService::defaultSubject(
        (string)(
            $form['titulo']
            ?? 'Formulário'
        )
    );

$replyMessage = '';

if (
    $_SERVER['REQUEST_METHOD']
    === 'POST'
) {
    $action =
        trim(
            (string)(
                $_POST['action']
                ?? 'status'
            )
        );

    if (
        !Csrf::validate(
            $_POST['_token']
            ?? null
        )
    ) {
        $replyError =
            'Token de segurança inválido. Atualize a página e tente novamente.';
    } elseif ($action === 'reply') {
        $replySubject =
            trim(
                (string)(
                    $_POST['assunto']
                    ?? ''
                )
            );

        $replyMessage =
            trim(
                (string)(
                    $_POST['mensagem']
                    ?? ''
                )
            );

        $user =
            Auth::user()
            ?: [];

        $result =
            FormReplyService::send(
                $pdo,
                $id,
                $replySubject,
                $replyMessage,
                Auth::id(),
                (string)(
                    $user['nome']
                    ?? $user['email']
                    ?? 'Usuário do painel'
                )
            );

        if (!empty($result['ok'])) {
            logAction(
                $pdo,
                'formulario.resposta.enviar_email',
                'formulario_respostas',
                $id,
                (string)$result['recipient']
            );

            Session::flash(
                'success',
                'Resposta enviada por e-mail para '
                . (string)$result['recipient']
                . '.'
            );

            header(
                'Location: '
                . url(
                    'admin/formularios/resposta.php?id='
                    . $id
                )
            );

            exit;
        }

        $replyError =
            (string)(
                $result['error']
                ?? 'Não foi possível enviar a resposta.'
            );
    } else {
        $status =
            (string)(
                $_POST['status']
                ?? 'lida'
            );

        if (
            in_array(
                $status,
                [
                    'nova',
                    'lida',
                    'arquivada',
                ],
                true
            )
        ) {
            $stmt =
                $pdo->prepare(
                    "UPDATE formulario_respostas
                     SET status=:status
                     WHERE id=:id"
                );

            $stmt->execute([
                'status' =>
                    $status,
                'id' =>
                    $id,
            ]);

            logAction(
                $pdo,
                'formulario.resposta.status',
                'formulario_respostas',
                $id,
                $status
            );

            Session::flash(
                'success',
                'Status atualizado.'
            );
        }

        header(
            'Location: '
            . url(
                'admin/formularios/resposta.php?id='
                . $id
            )
        );

        exit;
    }
}

if (
    (string)(
        $resp['status']
        ?? ''
    )
    === 'nova'
) {
    $stmt =
        $pdo->prepare(
            "UPDATE formulario_respostas
             SET status='lida'
             WHERE id=:id"
        );

    $stmt->execute([
        'id' =>
            $id,
    ]);

    $resp['status'] =
        'lida';
}

$mailIssue = null;

try {
    $mailIssue =
        class_exists('MailService')
            ? MailService::configurationIssue(
                $pdo
            )
            : 'O serviço de e-mail não está disponível.';
} catch (Throwable $e) {
    $mailIssue =
        'Não foi possível validar a configuração de e-mail.';
}

$replyHistory =
    FormReplyService::history(
        $pdo,
        $id,
        30
    );

$pageTitle =
    'Resposta #'
    . $id;

require __DIR__ . '/../_header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">
            Resposta #<?= $id ?>
        </h1>

        <p class="text-secondary mb-0">
            <?= e(
                (string)(
                    $form['titulo']
                    ?? 'Formulário'
                )
            ) ?>
            ·
            <?= e(
                formatDateBr(
                    (string)(
                        $resp['created_at']
                        ?? ''
                    )
                )
            ) ?>
        </p>
    </div>

    <a
        class="btn btn-outline-secondary"
        href="<?= e(
            url(
                'admin/formularios/respostas.php?id='
                . (int)$form['id']
            )
        ) ?>"
    >
        Voltar
    </a>
</div>

<div class="row g-4">
    <div class="col-xl-8">
        <section class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <div class="fw-semibold">
                    Mensagem recebida
                </div>
            </div>

            <div class="card-body p-4">
                <?php foreach ($values as $value): ?>
                    <div class="mb-4">
                        <div class="small text-secondary mb-1">
                            <?= e(
                                (string)(
                                    $value['rotulo']
                                    ?? 'Campo'
                                )
                            ) ?>
                        </div>

                        <div
                            class="fw-medium"
                            style="white-space:pre-wrap"
                        ><?= e(
                            (string)(
                                $value['valor']
                                ?? ''
                            )
                        ) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white py-3">
                <div class="fw-semibold">
                    Responder por e-mail
                </div>

                <div class="small text-secondary">
                    A resposta será enviada para o e-mail informado pela pessoa no formulário.
                </div>
            </div>

            <div class="card-body p-4">
                <?php if ($replyError !== ''): ?>
                    <div class="alert alert-danger">
                        <?= e($replyError) ?>
                    </div>
                <?php endif; ?>

                <?php if ($recipient === ''): ?>
                    <div class="alert alert-warning mb-0">
                        <strong>Não encontrei um e-mail válido nesta resposta.</strong>
                        Configure no formulário qual campo representa o e-mail do visitante
                        ou use um campo ativo do tipo E-mail.
                    </div>
                <?php else: ?>
                    <?php if ($mailIssue !== null): ?>
                        <div class="alert alert-warning">
                            <strong>Configuração de e-mail:</strong>
                            <?= e((string)$mailIssue) ?>
                        </div>
                    <?php endif; ?>

                    <form method="post">
                        <?= Csrf::field() ?>

                        <input
                            type="hidden"
                            name="action"
                            value="reply"
                        >

                        <div class="mb-3">
                            <label class="form-label">
                                Para
                            </label>

                            <input
                                class="form-control"
                                type="email"
                                value="<?= e($recipient) ?>"
                                readonly
                            >

                            <div class="form-text">
                                O destinatário é detectado automaticamente pela configuração do formulário.
                            </div>
                        </div>

                        <div class="mb-3">
                            <label
                                class="form-label"
                                for="replySubject"
                            >
                                Assunto
                            </label>

                            <input
                                class="form-control"
                                id="replySubject"
                                name="assunto"
                                maxlength="190"
                                required
                                value="<?= e($replySubject) ?>"
                            >
                        </div>

                        <div class="mb-3">
                            <label
                                class="form-label"
                                for="replyMessage"
                            >
                                Resposta
                            </label>

                            <textarea
                                class="form-control"
                                id="replyMessage"
                                name="mensagem"
                                rows="9"
                                maxlength="20000"
                                required
                                placeholder="Digite aqui a resposta que será enviada por e-mail..."
                            ><?= e($replyMessage) ?></textarea>
                        </div>

                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                            <div class="small text-secondary">
                                O envio utiliza o SMTP/e-mail configurado no Portal.
                            </div>

                            <button
                                class="btn btn-primary px-4"
                                <?= $mailIssue !== null ? 'disabled' : '' ?>
                            >
                                <i class="bi bi-send me-1"></i>
                                Enviar resposta
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </section>

        <?php if ($replyHistory): ?>
            <section class="card border-0 shadow-sm">
                <div class="card-header bg-white py-3">
                    <div class="fw-semibold">
                        Respostas enviadas
                    </div>

                    <div class="small text-secondary">
                        Histórico desta mensagem.
                    </div>
                </div>

                <div class="list-group list-group-flush">
                    <?php foreach ($replyHistory as $reply): ?>
                        <div class="list-group-item p-4">
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
                                <div>
                                    <div class="fw-semibold">
                                        <?= e(
                                            (string)(
                                                $reply['assunto']
                                                ?? ''
                                            )
                                        ) ?>
                                    </div>

                                    <div class="small text-secondary">
                                        Para:
                                        <?= e(
                                            (string)(
                                                $reply['destinatario']
                                                ?? ''
                                            )
                                        ) ?>
                                        ·
                                        <?= e(
                                            formatDateBr(
                                                (string)(
                                                    $reply['created_at']
                                                    ?? ''
                                                )
                                            )
                                        ) ?>

                                        <?php if (
                                            !empty(
                                                $reply['usuario_nome']
                                            )
                                        ): ?>
                                            · por
                                            <?= e(
                                                (string)$reply['usuario_nome']
                                            ) ?>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <span class="badge <?= ($reply['status'] ?? '') === 'enviado' ? 'text-bg-success' : 'text-bg-danger' ?>">
                                    <?= ($reply['status'] ?? '') === 'enviado'
                                        ? 'Enviado'
                                        : 'Erro' ?>
                                </span>
                            </div>

                            <div style="white-space:pre-wrap"><?= e(
                                (string)(
                                    $reply['mensagem']
                                    ?? ''
                                )
                            ) ?></div>

                            <?php if (
                                !empty(
                                    $reply['erro']
                                )
                            ): ?>
                                <div class="alert alert-danger py-2 mt-3 mb-0 small">
                                    <?= e(
                                        (string)$reply['erro']
                                    ) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
    </div>

    <div class="col-xl-4">
        <section class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white fw-semibold">
                Detalhes
            </div>

            <div class="card-body">
                <div class="small text-secondary">
                    E-mail do contato
                </div>

                <div class="mb-3 text-break">
                    <?php if ($recipient !== ''): ?>
                        <a href="mailto:<?= e($recipient) ?>">
                            <?= e($recipient) ?>
                        </a>
                    <?php else: ?>
                        Não encontrado
                    <?php endif; ?>
                </div>

                <div class="small text-secondary">
                    IP
                </div>

                <div class="mb-3">
                    <?= e(
                        (string)(
                            $resp['ip']
                            ?: 'Não disponível'
                        )
                    ) ?>
                </div>

                <div class="small text-secondary">
                    Origem
                </div>

                <div class="small text-break mb-3">
                    <?= e(
                        (string)(
                            $resp['origem']
                            ?: 'Não informada'
                        )
                    ) ?>
                </div>

                <div class="small text-secondary">
                    Navegador
                </div>

                <div class="small text-break">
                    <?= e(
                        (string)(
                            $resp['user_agent']
                            ?: 'Não informado'
                        )
                    ) ?>
                </div>
            </div>
        </section>

        <form
            method="post"
            class="card border-0 shadow-sm"
        >
            <div class="card-body">
                <?= Csrf::field() ?>

                <input
                    type="hidden"
                    name="action"
                    value="status"
                >

                <label class="form-label">
                    Status
                </label>

                <select
                    class="form-select mb-3"
                    name="status"
                >
                    <option
                        value="nova"
                        <?= ($resp['status'] ?? '') === 'nova' ? 'selected' : '' ?>
                    >
                        Nova
                    </option>

                    <option
                        value="lida"
                        <?= ($resp['status'] ?? '') === 'lida' ? 'selected' : '' ?>
                    >
                        Lida
                    </option>

                    <option
                        value="arquivada"
                        <?= ($resp['status'] ?? '') === 'arquivada' ? 'selected' : '' ?>
                    >
                        Arquivada
                    </option>
                </select>

                <button class="btn btn-outline-primary w-100">
                    Atualizar status
                </button>
            </div>
        </form>
    </div>
</div>

<?php require __DIR__ . '/../_footer.php'; ?>
