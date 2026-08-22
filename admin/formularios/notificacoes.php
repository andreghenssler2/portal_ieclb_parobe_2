<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('formularios.gerenciar');

$pdo = Database::connection();
$id = max(0, (int)($_GET['id'] ?? $_POST['id'] ?? 0));

$stmt = $pdo->prepare('SELECT * FROM formularios WHERE id=:id LIMIT 1');
$stmt->execute(['id' => $id]);
$form = $stmt->fetch();

if (!$form) {
    http_response_code(404);
    exit('Formulário não encontrado.');
}

$stmt = $pdo->prepare(
    "SELECT id,rotulo,nome,tipo,ativo,ordem
     FROM formulario_campos
     WHERE formulario_id=:id AND ativo=1
     ORDER BY ordem ASC,id ASC"
);
$stmt->execute(['id' => $id]);
$fields = $stmt->fetchAll() ?: [];
$emailFields = array_values(array_filter(
    $fields,
    static fn(array $field): bool => (string)$field['tipo'] === 'email'
));

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            $notify = isset($_POST['notificar_email']) ? 1 : 0;
            $recipientsRaw = trim((string)($_POST['emails_notificacao'] ?? ''));
            $recipients = FormNotificationService::parseRecipients($recipientsRaw);

            if ($notify && !$recipients) {
                throw new RuntimeException('Informe pelo menos um e-mail válido para receber as notificações.');
            }

            $auto = isset($_POST['resposta_automatica']) ? 1 : 0;
            $emailFieldId = max(0, (int)($_POST['campo_email_resposta_id'] ?? 0));

            if ($auto && !$emailFields) {
                throw new RuntimeException('Para enviar resposta automática, o formulário precisa ter um campo do tipo E-mail.');
            }

            if ($emailFieldId > 0) {
                $validField = false;
                foreach ($emailFields as $field) {
                    if ((int)$field['id'] === $emailFieldId) {
                        $validField = true;
                        break;
                    }
                }
                if (!$validField) {
                    throw new RuntimeException('O campo de e-mail selecionado é inválido.');
                }
            }

            $subjectAdmin = trim((string)($_POST['assunto_notificacao'] ?? ''));
            $subjectAuto = trim((string)($_POST['assunto_resposta_automatica'] ?? ''));
            $messageAuto = trim((string)($_POST['mensagem_resposta_automatica'] ?? ''));

            $stmt = $pdo->prepare(
                "UPDATE formularios SET
                    notificar_email=:notificar,
                    emails_notificacao=:emails,
                    assunto_notificacao=:assunto_notificacao,
                    resposta_automatica=:resposta_automatica,
                    campo_email_resposta_id=:campo_email,
                    assunto_resposta_automatica=:assunto_resposta,
                    mensagem_resposta_automatica=:mensagem_resposta,
                    updated_at=NOW()
                 WHERE id=:id"
            );
            $stmt->execute([
                'notificar' => $notify,
                'emails' => $recipientsRaw !== '' ? $recipientsRaw : null,
                'assunto_notificacao' => $subjectAdmin !== '' ? $subjectAdmin : null,
                'resposta_automatica' => $auto,
                'campo_email' => $emailFieldId > 0 ? $emailFieldId : null,
                'assunto_resposta' => $subjectAuto !== '' ? $subjectAuto : null,
                'mensagem_resposta' => $messageAuto !== '' ? $messageAuto : null,
                'id' => $id,
            ]);

            logAction(
                $pdo,
                'formulario.notificacoes.atualizar',
                'formularios',
                $id,
                'Notificação: ' . ($notify ? 'sim' : 'não') . ' · Resposta automática: ' . ($auto ? 'sim' : 'não')
            );

            Session::flash('success', 'Configurações de notificação atualizadas.');
            header('Location: ' . url('admin/formularios/notificacoes.php?id=' . $id));
            exit;
        } catch (Throwable $e) {
            $error = $e->getMessage();
            $form = array_merge($form, $_POST);
            $form['notificar_email'] = isset($_POST['notificar_email']) ? 1 : 0;
            $form['resposta_automatica'] = isset($_POST['resposta_automatica']) ? 1 : 0;
        }
    }
}

$logs = FormNotificationService::recentLogs($pdo, $id, 30);
$mailIssue = MailService::configurationIssue($pdo);

$pageTitle = 'Notificações do formulário';
require __DIR__ . '/../_header.php';

$statusClass = static function(string $status): string {
    return match ($status) {
        'enviado' => 'success',
        'erro' => 'danger',
        'ignorado' => 'secondary',
        default => 'light',
    };
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div>
        <h1 class="h3 mb-1">Notificações por e-mail</h1>
        <p class="text-secondary mb-0"><?=e($form['titulo'])?></p>
    </div>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary" href="<?=e(url('admin/formularios/form.php?id='.$id))?>">Editar formulário</a>
        <a class="btn btn-outline-secondary" href="<?=e(url('admin/formularios/respostas.php?formulario='.$id))?>">Ver respostas</a>
    </div>
</div>

<?php if($error):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?>
<?php if($mailIssue!==null):?>
<div class="alert alert-warning">
    <strong>E-mail ainda não está pronto:</strong> <?=e($mailIssue)?>
    <a class="alert-link ms-1" href="<?=e(url('admin/configuracoes/email.php'))?>">Abrir Configurações &gt; E-mail</a>
</div>
<?php else:?>
<div class="alert alert-success py-2"><i class="bi bi-check-circle me-1"></i>PHPMailer/SMTP está disponível para este formulário.</div>
<?php endif;?>

<form method="post">
<?=Csrf::field()?>
<input type="hidden" name="id" value="<?=$id?>">

<div class="row g-4">
    <div class="col-xl-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3"><strong>Aviso para a equipe</strong></div>
            <div class="card-body p-4">
                <div class="form-check form-switch mb-4">
                    <input class="form-check-input" type="checkbox" name="notificar_email" id="notifyAdmin" <?=!empty($form['notificar_email'])?'checked':''?>>
                    <label class="form-check-label fw-semibold" for="notifyAdmin">Enviar e-mail quando chegar uma nova resposta</label>
                </div>

                <div class="mb-3">
                    <label class="form-label">Destinatários</label>
                    <textarea class="form-control" name="emails_notificacao" rows="4" placeholder="secretaria@dominio.org.br&#10;outro@dominio.org.br"><?=e((string)($form['emails_notificacao'] ?? ''))?></textarea>
                    <div class="form-text">Aceita vários endereços separados por linha, vírgula, espaço ou ponto e vírgula.</div>
                </div>

                <div>
                    <label class="form-label">Assunto</label>
                    <input class="form-control" name="assunto_notificacao" maxlength="255" value="<?=e((string)($form['assunto_notificacao'] ?? ''))?>" placeholder="Nova resposta: {{formulario}}">
                </div>

                <div class="alert alert-light border small mt-4 mb-0">
                    Quando a resposta tiver um e-mail válido, o aviso administrativo usa esse endereço como <strong>Responder para</strong>. Assim basta clicar em Responder no cliente de e-mail.
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white py-3"><strong>Confirmação para quem preencheu</strong></div>
            <div class="card-body p-4">
                <div class="form-check form-switch mb-4">
                    <input class="form-check-input" type="checkbox" name="resposta_automatica" id="autoReply" <?=!empty($form['resposta_automatica'])?'checked':''?>>
                    <label class="form-check-label fw-semibold" for="autoReply">Enviar resposta automática</label>
                </div>

                <div class="mb-3">
                    <label class="form-label">Campo que contém o e-mail da pessoa</label>
                    <select class="form-select" name="campo_email_resposta_id">
                        <option value="0">Detectar automaticamente o primeiro campo E-mail</option>
                        <?php foreach($emailFields as $field):?>
                            <option value="<?=(int)$field['id']?>" <?=(int)($form['campo_email_resposta_id']??0)===(int)$field['id']?'selected':''?>><?=e($field['rotulo'])?> (<?=e($field['nome'])?>)</option>
                        <?php endforeach;?>
                    </select>
                    <?php if(!$emailFields):?><div class="form-text text-danger">Este formulário ainda não possui campo do tipo E-mail.</div><?php endif;?>
                </div>

                <div class="mb-3">
                    <label class="form-label">Assunto da confirmação</label>
                    <input class="form-control" name="assunto_resposta_automatica" maxlength="255" value="<?=e((string)($form['assunto_resposta_automatica'] ?? ''))?>" placeholder="Recebemos sua mensagem - {{formulario}}">
                </div>

                <div>
                    <label class="form-label">Mensagem</label>
                    <textarea class="form-control" name="mensagem_resposta_automatica" rows="9" placeholder="Olá!&#10;&#10;Recebemos sua mensagem enviada pelo formulário {{formulario}}.&#10;&#10;Em breve entraremos em contato."><?=e((string)($form['mensagem_resposta_automatica'] ?? ''))?></textarea>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm mt-4">
    <div class="card-header bg-white py-3"><strong>Placeholders disponíveis</strong></div>
    <div class="card-body">
        <div class="d-flex flex-wrap gap-2">
            <code class="border rounded px-2 py-1">{{formulario}}</code>
            <code class="border rounded px-2 py-1">{{resposta_id}}</code>
            <code class="border rounded px-2 py-1">{{data}}</code>
            <code class="border rounded px-2 py-1">{{site_nome}}</code>
            <?php foreach($fields as $field):?>
                <code class="border rounded px-2 py-1">{{<?=e($field['nome'])?>}}</code>
            <?php endforeach;?>
        </div>
        <div class="form-text mt-2">Os placeholders podem ser usados nos assuntos e na mensagem de resposta automática.</div>
    </div>
</div>

<div class="mt-4 d-flex justify-content-end">
    <button class="btn btn-primary px-4">Salvar notificações</button>
</div>
</form>

<div class="card border-0 shadow-sm mt-5">
    <div class="card-header bg-white py-3"><strong>Histórico de notificações</strong></div>
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>Data</th><th>Tipo</th><th>Destinatário</th><th>Status</th><th>Resposta</th><th>Detalhe</th></tr></thead>
            <tbody>
            <?php if(!$logs):?><tr><td colspan="6" class="text-secondary py-4">Nenhuma notificação registrada ainda.</td></tr><?php endif;?>
            <?php foreach($logs as $log):?>
                <tr>
                    <td><?=e(formatDateBr((string)$log['created_at']))?></td>
                    <td><?=e($log['tipo']==='administrador'?'Equipe':'Resposta automática')?></td>
                    <td><?=e((string)($log['destinatario'] ?: '—'))?></td>
                    <td><span class="badge text-bg-<?=$statusClass((string)$log['status'])?>"><?=e($log['status'])?></span></td>
                    <td><?php if($log['resposta_id']):?><a href="<?=e(url('admin/formularios/resposta.php?id='.(int)$log['resposta_id']))?>">#<?=(int)$log['resposta_id']?></a><?php else:?>—<?php endif;?></td>
                    <td class="small text-secondary"><?=e((string)($log['erro'] ?? ''))?></td>
                </tr>
            <?php endforeach;?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../_footer.php'; ?>
