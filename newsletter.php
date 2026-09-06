<?php
require_once __DIR__ . '/bootstrap.php';
$pdo = Database::connection();
$segments = array_values(array_filter(explode('/', trim(currentRelativePath(), '/')), static fn($v) => $v !== ''));
$action = $segments[1] ?? '';
$token = strtolower((string) ($segments[2] ?? ''));
$metaNoindex = in_array($action, ['confirmar', 'cancelar'], true);
$metaTitle = 'Newsletter';
$metaDescription = 'Receba novidades, notícias e informações da IECLB Parobé por e-mail.';
$message = '';
$error = '';
$newsletterEnabled = siteConfig($pdo, 'newsletter_enabled', '1') === '1';

if ($action === 'confirmar' && $token !== '') {
    if (NewsletterService::confirm($pdo, $token)) {
        $message = 'Inscrição confirmada com sucesso. Você já pode receber nossa newsletter.';
    } else {
        $error = 'Este link de confirmação é inválido, já foi utilizado ou expirou.';
    }
} elseif ($action === 'cancelar' && $token !== '') {
    if (NewsletterService::unsubscribe($pdo, $token)) {
        $message = 'Sua inscrição foi cancelada. Você não receberá novos envios.';
    } else {
        $error = 'Não foi possível localizar esta inscrição.';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$newsletterEnabled) {
        $error = 'As inscrições na newsletter estão temporariamente desativadas.';
    } elseif (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido. Atualize a página e tente novamente.';
    } elseif (trim((string) ($_POST['website'] ?? '')) !== '') {
        $message = 'Inscrição recebida.';
    } else {
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $name = trim((string) ($_POST['nome'] ?? ''));
        try {
            $ip = isset($_SERVER['REMOTE_ADDR']) ? mb_substr((string) $_SERVER['REMOTE_ADDR'], 0, 45) : null;
            if ($ip) {
                $check = $pdo->prepare('SELECT COUNT(*) FROM newsletter_assinantes WHERE ip=:ip AND created_at >= DATE_SUB(NOW(), INTERVAL 20 SECOND)');
                $check->execute(['ip' => $ip]);
                if ((int) $check->fetchColumn() > 2)
                    throw new RuntimeException('Aguarde alguns segundos antes de tentar novamente.');
            }
            $result = NewsletterService::subscribe($pdo, $name, $email, $ip, $_SERVER['HTTP_USER_AGENT'] ?? null);
            if (!empty($result['already_active'])) {
                $message = 'Este e-mail já está inscrito na newsletter.';
            } elseif ($result['status'] === 'ativo') {
                $message = 'Inscrição realizada com sucesso.';
            } elseif (!empty($result['mail_sent'])) {
                $message = 'Inscrição recebida. Enviamos um e-mail para você confirmar o cadastro.';
            } else {
                $message = 'Inscrição registrada como pendente, mas o servidor não conseguiu enviar o e-mail de confirmação. Entre em contato conosco para ativação.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

require themeFile($pdo, 'header.php');
?>
<div class="container py-5" style="max-width:760px">
    <div class="text-center mb-4">
        <span class="badge text-bg-light border mb-2">Newsletter</span>
        <h1 class="display-6 fw-bold"><?= e(siteConfig($pdo, 'newsletter_title', 'Receba nossas novidades')) ?></h1>
        <p class="lead text-secondary">
            <?= e(siteConfig($pdo, 'newsletter_description', 'Cadastre seu e-mail para receber notícias, agenda e informações da IECLB Parobé.')) ?>
        </p>
    </div>
    <?php if ($message): ?>
        <div class="alert alert-success"><?= e($message) ?></div><?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
    <?php if ($action === '' && $newsletterEnabled): ?>
        <form method="post" class="card border-0 shadow-sm">
            <div class="card-body p-4 p-md-5">
                <?= Csrf::field() ?>
                <div class="position-absolute opacity-0 pe-none" aria-hidden="true"><label>Website<input name="website"
                            tabindex="-1" autocomplete="off"></label></div>
                <div class="mb-3"><label class="form-label">Nome <span
                            class="text-secondary">(opcional)</span></label><input class="form-control form-control-lg"
                        name="nome" maxlength="150" autocomplete="name"></div>
                <div class="mb-3"><label class="form-label">E-mail</label><input class="form-control form-control-lg"
                        type="email" name="email" maxlength="190" required autocomplete="email"></div>
                <p class="small text-secondary">Ao se inscrever, você concorda em receber mensagens da IECLB Parobé. Todo
                    envio inclui uma opção de descadastro.</p>
                <button class="btn btn-primary btn-lg w-100">Quero receber novidades</button>
            </div>
        </form>
    <?php elseif ($action === '' && !$newsletterEnabled): ?>
        <div class="alert alert-secondary">As inscrições estão temporariamente fechadas.</div><?php endif; ?>
</div>
<?php require themeFile($pdo, 'footer.php'); ?>