<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('formularios.gerenciar');
$pdo=Database::connection();
$id=(int)($_GET['id'] ?? 0);
$stmt=$pdo->prepare('SELECT * FROM formularios WHERE id=:id LIMIT 1');$stmt->execute(['id'=>$id]);$form=$stmt->fetch();
if(!$form){http_response_code(404);exit('Formulário não encontrado.');}
$stmt=$pdo->prepare('SELECT id,rotulo,nome FROM formulario_campos WHERE formulario_id=:id ORDER BY ordem,id');$stmt->execute(['id'=>$id]);$fields=$stmt->fetchAll();
$stmt=$pdo->prepare('SELECT * FROM formulario_respostas WHERE formulario_id=:id ORDER BY id ASC');$stmt->execute(['id'=>$id]);$responses=$stmt->fetchAll();
$filename='respostas-'.slugify((string)$form['titulo']).'-'.date('Y-m-d-His').'.csv';
header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="'.$filename.'"');header('X-Content-Type-Options: nosniff');
$out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");
$header=['ID','Status','Recebida em','IP'];foreach($fields as $f)$header[]=$f['rotulo'];fputcsv($out,$header,';');
$find=$pdo->prepare('SELECT campo_id,valor FROM formulario_resposta_valores WHERE resposta_id=:id');
foreach($responses as $r){$find->execute(['id'=>$r['id']]);$vals=[];foreach($find->fetchAll() as $v)$vals[(int)$v['campo_id']]=$v['valor'];$row=[(int)$r['id'],$r['status'],$r['created_at'],$r['ip']];foreach($fields as $f)$row[]=$vals[(int)$f['id']] ?? '';fputcsv($out,$row,';');}
fclose($out);exit;
