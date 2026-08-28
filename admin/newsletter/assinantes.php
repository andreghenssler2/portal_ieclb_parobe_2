<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('newsletter.gerenciar');
$pdo = Database::connection();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        $action = (string)($_POST['action'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        try {
            if ($id > 0 && in_array($action, ['ativar','cancelar','excluir'], true)) {
                if ($action === 'ativar') {
                    $pdo->prepare("UPDATE newsletter_assinantes SET status='ativo',confirmado_em=COALESCE(confirmado_em,NOW()),cancelado_em=NULL,updated_at=NOW() WHERE id=:id")->execute(['id'=>$id]);
                    logAction($pdo, 'newsletter.assinante.ativar', 'newsletter_assinantes', $id);
                    Session::flash('success', 'Assinante ativado.');
                } elseif ($action === 'cancelar') {
                    $pdo->prepare("UPDATE newsletter_assinantes SET status='cancelado',cancelado_em=NOW(),updated_at=NOW() WHERE id=:id")->execute(['id'=>$id]);
                    logAction($pdo, 'newsletter.assinante.cancelar', 'newsletter_assinantes', $id);
                    Session::flash('success', 'Inscrição cancelada.');
                } else {
                    $pdo->prepare('DELETE FROM newsletter_assinantes WHERE id=:id')->execute(['id'=>$id]);
                    logAction($pdo, 'newsletter.assinante.excluir', 'newsletter_assinantes', $id);
                    Session::flash('success', 'Assinante excluído.');
                }
                header('Location: ' . url('admin/newsletter/assinantes.php')); exit;
            }
            if ($action === 'adicionar') {
                $nome = trim((string)($_POST['nome'] ?? ''));
                $email = strtolower(trim((string)($_POST['email'] ?? '')));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Informe um e-mail válido.');
                $stmt = $pdo->prepare("INSERT INTO newsletter_assinantes (nome,email,status,token_cancelamento,origem,confirmado_em) VALUES (:nome,:email,'ativo',:token,'admin',NOW()) ON DUPLICATE KEY UPDATE nome=VALUES(nome),status='ativo',cancelado_em=NULL,confirmado_em=COALESCE(confirmado_em,NOW()),updated_at=NOW()");
                $stmt->execute(['nome'=>$nome!==''?$nome:null,'email'=>$email,'token'=>NewsletterService::randomToken()]);
                logAction($pdo, 'newsletter.assinante.adicionar', 'newsletter_assinantes');
                Session::flash('success', 'Assinante adicionado/ativado.');
                header('Location: ' . url('admin/newsletter/assinantes.php')); exit;
            }
        } catch (Throwable $e) { $error = $e->getMessage(); }
    }
}

if (($_GET['export'] ?? '') === 'csv') {
    $rows = $pdo->query("SELECT nome,email,status,origem,confirmado_em,cancelado_em,created_at FROM newsletter_assinantes ORDER BY created_at DESC")->fetchAll();
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="newsletter-assinantes-' . date('Ymd-His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'wb');
    fputcsv($out, ['Nome','E-mail','Status','Origem','Confirmado em','Cancelado em','Criado em'], ';');
    foreach ($rows as $r) fputcsv($out, [$r['nome'],$r['email'],$r['status'],$r['origem'],$r['confirmado_em'],$r['cancelado_em'],$r['created_at']], ';');
    fclose($out); exit;
}

$status = trim((string)($_GET['status'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$where=[]; $params=[];
if (in_array($status,['pendente','ativo','cancelado'],true)) { $where[]='status=:status'; $params['status']=$status; }
if ($q!=='') { $where[]='(nome LIKE :q OR email LIKE :q)'; $params['q']='%'.$q.'%'; }
$sql='SELECT * FROM newsletter_assinantes'; if($where)$sql.=' WHERE '.implode(' AND ',$where); $sql.=' ORDER BY created_at DESC LIMIT 1000';
$stmt=$pdo->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll();
$counts=['total'=>0,'ativo'=>0,'pendente'=>0,'cancelado'=>0];
foreach($pdo->query("SELECT status,COUNT(*) total FROM newsletter_assinantes GROUP BY status")->fetchAll() as $c){$counts[(string)$c['status']]=(int)$c['total'];$counts['total']+=(int)$c['total'];}
$pageTitle='Newsletter - Assinantes';
require __DIR__.'/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4"><div><h1 class="h3 mb-1">Assinantes</h1><p class="text-secondary mb-0">Gerencie quem recebe a newsletter do Portal.</p></div><div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="<?=e(url('admin/newsletter/assinantes.php?export=csv'))?>"><i class="bi bi-download me-1"></i>CSV</a><button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#novoAssinante"><i class="bi bi-plus-lg me-1"></i>Adicionar</button></div></div>
<?php if($error):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?>
<div class="row g-3 mb-4"><?php foreach(['total'=>'Total','ativo'=>'Ativos','pendente'=>'Pendentes','cancelado'=>'Cancelados'] as $k=>$l):?><div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-secondary"><?=e($l)?></div><div class="h3 mb-0"><?= (int)$counts[$k] ?></div></div></div></div><?php endforeach;?></div>
<form method="get" class="card border-0 shadow-sm mb-4"><div class="card-body"><div class="row g-3 align-items-end"><div class="col-md-6"><label class="form-label">Buscar</label><input class="form-control" name="q" value="<?=e($q)?>" placeholder="Nome ou e-mail"></div><div class="col-md-3"><label class="form-label">Status</label><select class="form-select" name="status"><option value="">Todos</option><?php foreach(['ativo'=>'Ativo','pendente'=>'Pendente','cancelado'=>'Cancelado'] as $v=>$l):?><option value="<?=$v?>" <?=$status===$v?'selected':''?>><?=$l?></option><?php endforeach;?></select></div><div class="col-md-3 d-flex gap-2"><button class="btn btn-outline-primary">Filtrar</button><a class="btn btn-outline-secondary" href="<?=e(url('admin/newsletter/assinantes.php'))?>">Limpar</a></div></div></div></form>
<div class="card border-0 shadow-sm"><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Assinante</th><th>Status</th><th>Origem</th><th>Cadastro</th><th></th></tr></thead><tbody>
<?php if(!$rows):?><tr><td colspan="5" class="text-secondary">Nenhum assinante encontrado.</td></tr><?php endif;?>
<?php foreach($rows as $r):?><tr><td><strong><?=e($r['nome']?:'Sem nome')?></strong><div class="small text-secondary"><?=e($r['email'])?></div></td><td><span class="badge <?= $r['status']==='ativo'?'text-bg-success':($r['status']==='pendente'?'text-bg-warning':'text-bg-secondary') ?>"><?=e(ucfirst((string)$r['status']))?></span></td><td><?=e($r['origem']?:'-')?></td><td><?=e(formatDateBr($r['created_at']))?></td><td class="text-end"><form method="post" class="d-inline"><?=Csrf::field()?><input type="hidden" name="id" value="<?=$r['id']?>"><?php if($r['status']!=='ativo'):?><button class="btn btn-sm btn-outline-success" name="action" value="ativar">Ativar</button><?php endif;?><?php if($r['status']==='ativo'):?><button class="btn btn-sm btn-outline-warning" name="action" value="cancelar">Cancelar</button><?php endif;?><button class="btn btn-sm btn-outline-danger" name="action" value="excluir" onclick="return confirm('Excluir definitivamente este assinante?')">Excluir</button></form></td></tr><?php endforeach;?></tbody></table></div></div>
<div class="modal fade" id="novoAssinante" tabindex="-1"><div class="modal-dialog"><form method="post" class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5">Adicionar assinante</h2><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><?=Csrf::field()?><input type="hidden" name="action" value="adicionar"><div class="mb-3"><label class="form-label">Nome</label><input class="form-control" name="nome" maxlength="150"></div><div><label class="form-label">E-mail</label><input class="form-control" type="email" name="email" required maxlength="190"></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary">Adicionar</button></div></form></div></div>
<?php require __DIR__.'/../_footer.php'; ?>
