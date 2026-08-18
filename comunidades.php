<?php
require_once __DIR__ . '/bootstrap.php';
$pdo=Database::connection();
$comunidades=$pdo->query('SELECT * FROM comunidades WHERE ativa=1 ORDER BY ordem,nome')->fetchAll();
$metaTitle='Comunidades - IECLB Parobé';
require __DIR__.'/theme/ieclb/header.php';
?>
<div class="container py-5"><h1 class="display-6 fw-bold mb-4">Comunidades</h1><div class="row g-4">
<?php foreach($comunidades as $c): ?><div class="col-md-6"><div class="card h-100 border-0 shadow-sm"><div class="card-body p-4"><h2 class="h4"><?= e($c['nome']) ?></h2><p class="text-secondary mb-2"><?= e(trim(($c['cidade']??'').'/'.($c['uf']??''),'/')) ?></p><?php if($c['descricao']): ?><p><?= e($c['descricao']) ?></p><?php endif; ?><?php if($c['endereco']): ?><div class="small"><?= e($c['endereco']) ?></div><?php endif; ?></div></div></div><?php endforeach; ?>
</div></div>
<?php require __DIR__.'/theme/ieclb/footer.php'; ?>
