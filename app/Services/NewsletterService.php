<?php

declare(strict_types=1);

final class NewsletterService
{
    public static function randomToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function subscribe(PDO $pdo, string $name, string $email, ?string $ip = null, ?string $userAgent = null): array
    {
        $name = trim($name);
        $email = strtolower(trim($email));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Informe um e-mail válido.');
        }
        if (mb_strlen($name) > 150) {
            throw new InvalidArgumentException('O nome é muito longo.');
        }

        $doubleOptIn = siteConfig($pdo, 'newsletter_double_optin', '1') === '1';
        $confirmToken = self::randomToken();
        $cancelToken = self::randomToken();

        $stmt = $pdo->prepare('SELECT * FROM newsletter_assinantes WHERE email=:email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $existing = $stmt->fetch();

        if ($existing) {
            if ((string)$existing['status'] === 'ativo') {
                return ['status' => 'ativo', 'already_active' => true, 'id' => (int)$existing['id']];
            }
            $status = $doubleOptIn ? 'pendente' : 'ativo';
            $pdo->prepare(
                'UPDATE newsletter_assinantes SET nome=:nome,status=:status,token_confirmacao=:confirm,token_cancelamento=:cancel,ip=:ip,user_agent=:ua,confirmado_em=:confirmed,cancelado_em=NULL,updated_at=NOW() WHERE id=:id'
            )->execute([
                'nome' => $name !== '' ? $name : (string)($existing['nome'] ?? ''),
                'status' => $status,
                'confirm' => $confirmToken,
                'cancel' => $cancelToken,
                'ip' => $ip,
                'ua' => $userAgent ? mb_substr($userAgent, 0, 500) : null,
                'confirmed' => $status === 'ativo' ? date('Y-m-d H:i:s') : null,
                'id' => (int)$existing['id'],
            ]);
            $id = (int)$existing['id'];
        } else {
            $status = $doubleOptIn ? 'pendente' : 'ativo';
            $stmt = $pdo->prepare(
                'INSERT INTO newsletter_assinantes (nome,email,status,token_confirmacao,token_cancelamento,origem,ip,user_agent,confirmado_em) VALUES (:nome,:email,:status,:confirm,:cancel,:origem,:ip,:ua,:confirmed)'
            );
            $stmt->execute([
                'nome' => $name !== '' ? $name : null,
                'email' => $email,
                'status' => $status,
                'confirm' => $confirmToken,
                'cancel' => $cancelToken,
                'origem' => 'portal',
                'ip' => $ip,
                'ua' => $userAgent ? mb_substr($userAgent, 0, 500) : null,
                'confirmed' => $status === 'ativo' ? date('Y-m-d H:i:s') : null,
            ]);
            $id = (int)$pdo->lastInsertId();
        }

        $mailSent = true;
        if ($doubleOptIn) {
            $mailSent = self::sendConfirmation($pdo, $email, $name, $confirmToken);
        }

        return ['status' => $status, 'already_active' => false, 'mail_sent' => $mailSent, 'id' => $id];
    }

    public static function confirm(PDO $pdo, string $token): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return false;
        $stmt = $pdo->prepare("SELECT id FROM newsletter_assinantes WHERE token_confirmacao=:token AND status='pendente' LIMIT 1");
        $stmt->execute(['token' => $token]);
        $id = (int)$stmt->fetchColumn();
        if ($id <= 0) return false;
        $pdo->prepare("UPDATE newsletter_assinantes SET status='ativo',confirmado_em=NOW(),token_confirmacao=NULL,cancelado_em=NULL,updated_at=NOW() WHERE id=:id")
            ->execute(['id' => $id]);
        return true;
    }

    public static function unsubscribe(PDO $pdo, string $token): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) return false;
        $stmt = $pdo->prepare('SELECT id FROM newsletter_assinantes WHERE token_cancelamento=:token LIMIT 1');
        $stmt->execute(['token' => $token]);
        $id = (int)$stmt->fetchColumn();
        if ($id <= 0) return false;
        $pdo->prepare("UPDATE newsletter_assinantes SET status='cancelado',cancelado_em=NOW(),updated_at=NOW() WHERE id=:id")
            ->execute(['id' => $id]);
        return true;
    }

    public static function sendConfirmation(PDO $pdo, string $email, string $name, string $token): bool
    {
        $siteName = siteConfig($pdo, 'site_nome', defined('APP_NAME') ? APP_NAME : 'Portal IECLB Parobé');
        $subject = 'Confirme sua inscrição - ' . $siteName;
        $confirmUrl = url('newsletter/confirmar/' . rawurlencode($token));
        $safeName = e($name !== '' ? $name : 'Olá');
        $body = '<p>' . $safeName . ',</p>'
            . '<p>Recebemos um pedido de inscrição na newsletter de <strong>' . e($siteName) . '</strong>.</p>'
            . '<p><a href="' . e($confirmUrl) . '" style="display:inline-block;padding:10px 16px;background:#0b5d4b;color:#fff;text-decoration:none;border-radius:6px">Confirmar inscrição</a></p>'
            . '<p>Se você não solicitou esta inscrição, basta ignorar esta mensagem.</p>';
        return self::sendHtmlMail($pdo, $email, $subject, $body);
    }

    public static function sendHtmlMail(PDO $pdo, string $to, string $subject, string $html): bool
    {
        $fromEmail = trim(siteConfig($pdo, 'newsletter_from_email', ''));
        $fromName = trim(siteConfig($pdo, 'newsletter_from_name', ''));
        $options = [];
        if ($fromEmail !== '') {
            $options['from_email'] = $fromEmail;
            $options['reply_to'] = $fromEmail;
        }
        if ($fromName !== '') {
            $options['from_name'] = $fromName;
        }
        return MailService::sendHtml($pdo, $to, $subject, $html, $options);
    }

    public static function sendCampaignBatch(PDO $pdo, int $campaignId, int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $newsletterFrom = trim(siteConfig($pdo, 'newsletter_from_email', ''));
        $issue = MailService::configurationIssue($pdo, $newsletterFrom !== '' ? ['from_email' => $newsletterFrom] : []);
        if ($issue !== null) {
            throw new RuntimeException($issue);
        }
        $stmt = $pdo->prepare("SELECT * FROM newsletter_campanhas WHERE id=:id AND status IN ('rascunho','enviando') LIMIT 1");
        $stmt->execute(['id' => $campaignId]);
        $campaign = $stmt->fetch();
        if (!$campaign) throw new RuntimeException('Campanha não encontrada ou já finalizada.');

        if ((string)$campaign['status'] === 'rascunho') {
            $pdo->prepare("UPDATE newsletter_campanhas SET status='enviando',iniciado_em=NOW() WHERE id=:id")
                ->execute(['id' => $campaignId]);
        }

        $sql = "SELECT a.* FROM newsletter_assinantes a
                LEFT JOIN newsletter_envios e ON e.campanha_id=:campanha AND e.assinante_id=a.id
                WHERE a.status='ativo' AND (e.id IS NULL OR (e.status='falhou' AND e.tentativas < 3))
                ORDER BY a.id LIMIT " . $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['campanha' => $campaignId]);
        $subs = $stmt->fetchAll();
        $sent = 0; $failed = 0;

        foreach ($subs as $sub) {
            $unsubscribe = url('newsletter/cancelar/' . rawurlencode((string)$sub['token_cancelamento']));
            $name = trim((string)($sub['nome'] ?? ''));
            $content = (string)$campaign['conteudo'];
            $content = str_replace(['{{nome}}','{{email}}','{{descadastrar_url}}'], [e($name), e((string)$sub['email']), e($unsubscribe)], $content);
            $content .= '<hr><p style="font-size:12px;color:#777">Você recebe esta mensagem porque está inscrito na newsletter. <a href="' . e($unsubscribe) . '">Cancelar inscrição</a>.</p>';
            $ok = self::sendHtmlMail($pdo, (string)$sub['email'], (string)$campaign['assunto'], $content);

            $up = $pdo->prepare(
                "INSERT INTO newsletter_envios (campanha_id,assinante_id,email,status,tentativas,enviado_em,erro)
                 VALUES (:c,:a,:email,:status,1,:sent,:erro)
                 ON DUPLICATE KEY UPDATE status=VALUES(status),tentativas=tentativas+1,enviado_em=VALUES(enviado_em),erro=VALUES(erro),updated_at=NOW()"
            );
            $up->execute([
                'c' => $campaignId,
                'a' => (int)$sub['id'],
                'email' => (string)$sub['email'],
                'status' => $ok ? 'enviado' : 'falhou',
                'sent' => $ok ? date('Y-m-d H:i:s') : null,
                'erro' => $ok ? null : (MailService::lastError() ?: 'Falha no envio de e-mail.'),
            ]);
            $ok ? $sent++ : $failed++;
        }

        $remainingStmt = $pdo->prepare("SELECT COUNT(*) FROM newsletter_assinantes a LEFT JOIN newsletter_envios e ON e.campanha_id=:c AND e.assinante_id=a.id WHERE a.status='ativo' AND (e.id IS NULL OR (e.status='falhou' AND e.tentativas < 3))");
        $remainingStmt->execute(['c' => $campaignId]);
        $remaining = (int)$remainingStmt->fetchColumn();
        if ($remaining === 0) {
            $pdo->prepare("UPDATE newsletter_campanhas SET status='enviado',enviado_em=NOW() WHERE id=:id")
                ->execute(['id' => $campaignId]);
        }
        return ['processed' => count($subs), 'sent' => $sent, 'failed' => $failed, 'remaining' => $remaining];
    }

}
