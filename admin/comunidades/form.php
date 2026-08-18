<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requireLogin();
$pdo = Database::connection();
$id = isset($_GET['id']) ? (int) $_GET['id'] : null;
$item = ['nome' => '', 'descricao' => '', 'endereco' => '', 'cidade' => '', 'uf' => 'RS', 'ativa' => 1, 'ordem' => 0];
if ($id) {
    $stmt = $pdo->prepare('SELECT * FROM comunidades WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $found = $stmt->fetch();
    if (!$found) {
        http_response_code(404);
        exit('Comunidade não encontrada.');
    }
    $item = $found;
}
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        $nome = trim((string) ($_POST['nome'] ?? ''));
        if ($nome === '') {
            $error = 'Informe o nome da comunidade.';
        } else {
            $slug = uniqueSlug($pdo, 'comunidades', $nome, $id);
            $data = [
                'nome' => $nome,
                'slug' => $slug,
                'descricao' => trim((string) ($_POST['descricao'] ?? '')) ?: null,
                'endereco' => trim((string) ($_POST['endereco'] ?? '')) ?: null,
                'cidade' => trim((string) ($_POST['cidade'] ?? '')) ?: null,
                'uf' => strtoupper(substr(trim((string) ($_POST['uf'] ?? 'RS')), 0, 2)),
                'ativa' => isset($_POST['ativa']) ? 1 : 0,
                'ordem' => (int) ($_POST['ordem'] ?? 0),
            ];
            if ($id) {
                $data['id'] = $id;
                $stmt = $pdo->prepare('UPDATE comunidades SET nome=:nome,slug=:slug,descricao=:descricao,endereco=:endereco,cidade=:cidade,uf=:uf,ativa=:ativa,ordem=:ordem WHERE id=:id');
            } else {
                $stmt = $pdo->prepare('INSERT INTO comunidades (nome,slug,descricao,endereco,cidade,uf,ativa,ordem) VALUES (:nome,:slug,:descricao,:endereco,:cidade,:uf,:ativa,:ordem)');
            }
            $stmt->execute($data);
            Session::flash('success', $id ? 'Comunidade atualizada.' : 'Comunidade criada.');
            header('Location: ' . url('admin/comunidades/index.php'));
            exit;
        }
    }
}
$pageTitle = $id ? 'Editar comunidade' : 'Nova comunidade';
require __DIR__ . '/../_header.php';
?>
<h1 class="h3 mb-4"><?= e($pageTitle) ?></h1>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<form method="post" class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <?= Csrf::field() ?>
        <div class="row g-3">
            <div class="col-md-8"><label class="form-label">Nome</label><input class="form-control" name="nome"
                    value="<?= e($item['nome']) ?>" required></div>
            <div class="col-md-4"><label class="form-label">Ordem</label><input type="number" class="form-control"
                    name="ordem" value="<?= (int) $item['ordem'] ?>"></div>
            <div class="col-12"><label class="form-label">Descrição</label><textarea class="form-control"
                    name="descricao" rows="4"><?= e($item['descricao'] ?? '') ?></textarea></div>
            <div class="col-md-8"><label class="form-label">Endereço</label><input class="form-control" name="endereco"
                    value="<?= e($item['endereco'] ?? '') ?>"></div>
            <div class="col-md-3"><label class="form-label">Cidade</label><input class="form-control" name="cidade"
                    value="<?= e($item['cidade'] ?? '') ?>"></div>
            <div class="col-md-1"><label class="form-label">UF</label><input class="form-control" name="uf"
                    maxlength="2" value="<?= e($item['uf'] ?? 'RS') ?>"></div>
            <div class="col-12">
                <div class="form-check"><input class="form-check-input" type="checkbox" name="ativa" id="ativa"
                        <?= $item['ativa'] ? 'checked' : '' ?>><label class="form-check-label" for="ativa">Comunidade
                        ativa</label></div>
            </div>
        </div>
        <div class="mt-4 d-flex gap-2"><button class="btn btn-primary">Salvar</button><a
                class="btn btn-outline-secondary" href="<?= e(url('admin/comunidades/index.php')) ?>">Cancelar</a></div>
    </div>
</form>
<?php require __DIR__ . '/../_footer.php'; ?>