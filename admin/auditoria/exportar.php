<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('auditoria.visualizar');
$pdo = Database::connection();

$q = trim((string)($_GET['q'] ?? ''));
$nivel = (string)($_GET['nivel'] ?? '');
$usuarioId = max(0, (int)($_GET['usuario_id'] ?? 0));
$acao = trim((string)($_GET['acao'] ?? ''));
$dataDe = trim((string)($_GET['data_de'] ?? ''));
$dataAte = trim((string)($_GET['data_ate'] ?? ''));

$where = ['1=1']; $params=[];
if ($q !== '') { $where[]='(l.acao LIKE :q_acao OR l.entidade LIKE :q_entidade OR l.detalhes LIKE :q_detalhes OR l.ip LIKE :q_ip OR u.nome LIKE :q_nome OR u.email LIKE :q_email)'; $searchValue = '%' . $q . '%';
$params['q_acao'] = $searchValue;
$params['q_entidade'] = $searchValue;
$params['q_detalhes'] = $searchValue;
$params['q_ip'] = $searchValue;
$params['q_nome'] = $searchValue;
$params['q_email'] = $searchValue; }
if (in_array($nivel,['info','warning','critical'],true)) { $where[]='COALESCE(l.nivel,"info")=:nivel'; $params['nivel']=$nivel; }
if ($usuarioId>0) { $where[]='l.usuario_id=:usuario_id'; $params['usuario_id']=$usuarioId; }
if ($acao!=='') { $where[]='l.acao=:acao'; $params['acao']=$acao; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$dataDe)) { $where[]='l.created_at>=:data_de'; $params['data_de']=$dataDe.' 00:00:00'; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/',$dataAte)) { $where[]='l.created_at<=:data_ate'; $params['data_ate']=$dataAte.' 23:59:59'; }

$sql='SELECT l.*,u.nome AS usuario_nome,u.email AS usuario_email FROM logs l LEFT JOIN usuarios u ON u.id=l.usuario_id WHERE '.implode(' AND ',$where).' ORDER BY l.id DESC LIMIT 10000';
$stmt=$pdo->prepare($sql); $stmt->execute($params);

logAction($pdo, 'auditoria.exportar', 'logs', null, 'Exportação CSV da auditoria.');

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="auditoria-'.date('Y-m-d-His').'.csv"');
echo "\xEF\xBB\xBF";
$out=fopen('php://output','wb');
fputcsv($out,['Data','Nível','Usuário','E-mail','Ação','Entidade','ID entidade','IP','Método','Rota','Detalhes','Request ID','User-Agent'],';');
while($row=$stmt->fetch()){
    fputcsv($out,[
        $row['created_at']??'',$row['nivel']??'info',$row['usuario_nome']??'',$row['usuario_email']??'',
        $row['acao']??'',$row['entidade']??'',$row['entidade_id']??'',$row['ip']??'',$row['metodo']??'',
        $row['rota']??'',$row['detalhes']??'',$row['request_id']??'',$row['user_agent']??''
    ],';');
}
fclose($out);
exit;
