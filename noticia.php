<?php
require_once __DIR__ . '/bootstrap.php';
$pdo = Database::connection();
$slug = routeSlug('noticia');
$siteLabel = siteConfig($pdo, 'seo_titulo', 'IECLB Parobé');

$stmt = $pdo->prepare("SELECT p.*, c.nome AS comunidade_nome, cat.nome AS categoria_nome, cat.slug AS categoria_slug, u.nome AS autor_nome, m.caminho AS imagem_capa_midia, m.alt_text AS imagem_capa_alt FROM posts p LEFT JOIN comunidades c ON c.id=p.comunidade_id LEFT JOIN categorias cat ON cat.id=p.categoria_id LEFT JOIN usuarios u ON u.id=p.autor_id LEFT JOIN midias m ON m.id=p.imagem_capa_id WHERE p.slug=:slug AND p.status='publicado' AND (p.publicado_em IS NULL OR p.publicado_em <= NOW()) LIMIT 1");
$stmt->execute(['slug' => $slug]);
$post = $stmt->fetch();
$tags = [];

if (!$post) {
    http_response_code(404);
    $metaTitle = 'Notícia não encontrada - ' . $siteLabel;
    $metaDescription = 'A notícia solicitada não está disponível.';
    $metaNoindex = true;
    require themeFile($pdo, 'header.php');
    echo '<div class="container py-5"><h1>Notícia não encontrada</h1><p class="text-secondary">O conteúdo solicitado não está disponível.</p></div>';
    require themeFile($pdo, 'footer.php');
    exit;
}

$tagStmt=$pdo->prepare('SELECT t.nome,t.slug FROM tags t INNER JOIN post_tags pt ON pt.tag_id=t.id WHERE pt.post_id=:id ORDER BY t.nome');
$tagStmt->execute(['id'=>$post['id']]);
$tags=$tagStmt->fetchAll();

$commentsAvailable = false;
$comments = [];
$commentsEnabled = siteConfig($pdo, 'comments_enabled', '1') === '1';
$commentsOpen = (int)($post['comentarios_ativos'] ?? 1) === 1;
$commentsRequireModeration = siteConfig($pdo, 'comments_require_moderation', '1') === '1';
$commentsMaxLength = max(200, min(10000, (int)siteConfig($pdo, 'comments_max_length', '2000')));
$commentsRateLimit = max(10, min(3600, (int)siteConfig($pdo, 'comments_rate_limit_seconds', '60')));
try {
    $pdo->query('SELECT 1 FROM comentarios LIMIT 1');
    $commentsAvailable = true;
} catch (Throwable $e) {}

if ($commentsAvailable && $_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'comment') {
    $commentError = '';
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $commentError = 'Token de segurança inválido. Atualize a página e tente novamente.';
    } elseif (!$commentsEnabled || !$commentsOpen) {
        $commentError = 'Os comentários estão fechados para esta notícia.';
    } elseif (trim((string)($_POST['website'] ?? '')) !== '') {
        // Honeypot: não informa ao robô o motivo exato.
        $commentError = 'Não foi possível enviar o comentário.';
    } else {
        $authorName = trim((string)($_POST['autor_nome'] ?? ''));
        $authorEmail = strtolower(trim((string)($_POST['autor_email'] ?? '')));
        $commentText = str_replace(["\r\n", "\r"], "\n", (string)($_POST['conteudo_comentario'] ?? ''));
        $commentText = trim(preg_replace('/[\t ]+/u', ' ', $commentText) ?? '');
        if (mb_strlen($authorName) < 2 || mb_strlen($authorName) > 150) {
            $commentError = 'Informe seu nome.';
        } elseif (!filter_var($authorEmail, FILTER_VALIDATE_EMAIL) || mb_strlen($authorEmail) > 190) {
            $commentError = 'Informe um e-mail válido.';
        } elseif ($commentText === '') {
            $commentError = 'Escreva seu comentário.';
        } elseif (mb_strlen($commentText) > $commentsMaxLength) {
            $commentError = 'O comentário pode ter no máximo ' . $commentsMaxLength . ' caracteres.';
        } else {
            $ip = mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
            if ($ip !== '') {
                $rateStmt = $pdo->prepare('SELECT COUNT(*) FROM comentarios WHERE post_id=:post_id AND ip=:ip AND created_at >= DATE_SUB(NOW(), INTERVAL ' . $commentsRateLimit . ' SECOND)');
                $rateStmt->execute(['post_id' => $post['id'], 'ip' => $ip]);
                if ((int)$rateStmt->fetchColumn() > 0) {
                    $commentError = 'Aguarde alguns instantes antes de enviar outro comentário.';
                }
            }
            if ($commentError === '') {
                $commentStatus = $commentsRequireModeration ? 'pendente' : 'aprovado';
                $insertComment = $pdo->prepare('INSERT INTO comentarios (post_id,autor_nome,autor_email,conteudo,status,ip,user_agent) VALUES (:post_id,:autor_nome,:autor_email,:conteudo,:status,:ip,:user_agent)');
                $insertComment->execute([
                    'post_id' => $post['id'],
                    'autor_nome' => $authorName,
                    'autor_email' => $authorEmail,
                    'conteudo' => $commentText,
                    'status' => $commentStatus,
                    'ip' => $ip !== '' ? $ip : null,
                    'user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
                ]);
                Session::flash('comment_success', $commentsRequireModeration ? 'Comentário enviado e aguardando aprovação.' : 'Comentário publicado com sucesso.');
                header('Location: ' . contentUrl('noticia', (string)$post['slug']) . '#comentarios', true, 303);
                exit;
            }
        }
    }
    if ($commentError !== '') Session::flash('comment_error', $commentError);
}

if ($commentsAvailable) {
    $commentStmt = $pdo->prepare("SELECT id,autor_nome,conteudo,created_at FROM comentarios WHERE post_id=:post_id AND status='aprovado' ORDER BY created_at ASC,id ASC");
    $commentStmt->execute(['post_id' => $post['id']]);
    $comments = $commentStmt->fetchAll();
}

redirectCanonicalContent('noticia', (string)$post['slug']);
$pdo->prepare('UPDATE posts SET visualizacoes=visualizacoes+1 WHERE id=:id')->execute(['id' => $post['id']]);
$cover = $post['imagem_capa_midia'] ?: $post['imagem_capa'];
$metaTitle = trim((string)($post['seo_titulo'] ?? '')) ?: $post['titulo'];
$metaDescription = trim((string)($post['seo_descricao'] ?? '')) ?: ($post['resumo'] ?: trim(strip_tags(mb_substr((string)$post['conteudo'], 0, 160))));
$metaNoindex = (int)($post['seo_noindex'] ?? 0) === 1;
$metaImage = $cover ? mediaUrl((string)$cover) : '';
$canonicalUrl = contentUrl('noticia', (string)$post['slug']);
$metaOgType = 'article';
require themeFile($pdo, 'header.php');
?>
<article class="container py-5 content-reading"><div class="mb-4"><div class="text-secondary mb-2"><?php if(!empty($post['categoria_slug'])):?><a class="text-secondary text-decoration-none" href="<?=e(categoryUrl((string)$post['categoria_slug']))?>"><?=e($post['categoria_nome'])?></a><?php else:?>Notícia<?php endif;?> · <?= e($post['comunidade_nome'] ?: 'Paroquial') ?></div><h1 class="display-5 fw-bold"><?= e($post['titulo']) ?></h1><div class="text-secondary">Publicado em <?= e(formatDateBr($post['publicado_em'] ?: $post['created_at'])) ?><?php if ($post['autor_nome']): ?> · <?= e($post['autor_nome']) ?><?php endif; ?></div></div>
<?php if ($cover): ?><img class="article-cover mb-4" src="<?= e(mediaUrl((string)$cover)) ?>" alt="<?= e($post['imagem_capa_alt'] ?: $post['titulo']) ?>"><?php endif; ?>
<?php if ($post['resumo']): ?><p class="lead"><?= e($post['resumo']) ?></p><?php endif; ?><div class="article-body"><?= $post['conteudo'] ?></div><?php if($tags): ?><div class="article-tags mt-5 pt-3 border-top"><span class="text-secondary me-2">Tags:</span><?php foreach($tags as $tag): ?><a class="badge rounded-pill text-bg-light border text-decoration-none me-1 mb-1" href="<?= e(tagUrl((string)$tag['slug'])) ?>">#<?= e($tag['nome']) ?></a><?php endforeach; ?></div><?php endif; ?></article>

<?php if ($commentsAvailable): ?>
<section class="container content-reading pb-5" id="comentarios">
    <div class="comments-section border-top pt-4">
        <h2 class="h4 mb-4">Comentários <span class="text-secondary fw-normal">(<?= count($comments) ?>)</span></h2>
        <?php if ($msg=Session::flash('comment_success')): ?><div class="alert alert-success"><?=e($msg)?></div><?php endif; ?>
        <?php if ($msg=Session::flash('comment_error')): ?><div class="alert alert-danger"><?=e($msg)?></div><?php endif; ?>

        <?php if ($comments): ?>
            <div class="comment-list mb-5">
                <?php foreach($comments as $comment): ?>
                    <article class="comment-public border rounded-3 p-3 p-md-4 mb-3">
                        <div class="d-flex justify-content-between gap-3 mb-2"><strong><?=e($comment['autor_nome'])?></strong><time class="small text-secondary" datetime="<?=e((new DateTime((string)$comment['created_at']))->format(DateTimeInterface::ATOM))?>"><?=e(formatDateBr($comment['created_at']))?></time></div>
                        <div class="comment-public-text"><?=nl2br(e($comment['conteudo']))?></div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?><p class="text-secondary">Ainda não há comentários publicados. Seja o primeiro a comentar.</p><?php endif; ?>

        <?php if ($commentsEnabled && $commentsOpen): ?>
            <div class="comment-form-wrap mt-4">
                <h3 class="h5">Deixe um comentário</h3>
                <p class="small text-secondary"><?= $commentsRequireModeration ? 'Seu comentário será publicado após aprovação.' : 'Seu comentário será publicado após o envio.' ?> Seu e-mail não será exibido.</p>
                <form method="post" class="row g-3 comment-form">
                    <?=Csrf::field()?>
                    <input type="hidden" name="action" value="comment">
                    <div class="comment-honeypot" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
                    <div class="col-md-6"><label class="form-label">Nome *</label><input class="form-control" name="autor_nome" maxlength="150" required autocomplete="name"></div>
                    <div class="col-md-6"><label class="form-label">E-mail *</label><input class="form-control" type="email" name="autor_email" maxlength="190" required autocomplete="email"></div>
                    <div class="col-12"><label class="form-label">Comentário *</label><textarea class="form-control" name="conteudo_comentario" rows="5" maxlength="<?=$commentsMaxLength?>" required></textarea><div class="form-text">Máximo de <?=$commentsMaxLength?> caracteres.</div></div>
                    <div class="col-12"><button class="btn btn-primary">Enviar comentário</button></div>
                </form>
            </div>
        <?php elseif(!$commentsOpen): ?><div class="alert alert-light border">Os comentários estão encerrados para esta notícia.</div><?php endif; ?>
    </div>
</section>
<?php endif; ?>
<?php require themeFile($pdo, 'footer.php'); ?>
