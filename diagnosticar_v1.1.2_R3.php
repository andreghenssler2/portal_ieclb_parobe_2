<?php

declare(strict_types=1);
ob_start();
if(PHP_SAPI!=='cli'){http_response_code(403);exit("Execute pelo terminal.\n");}
$root=__DIR__;require_once $root.'/bootstrap.php';echo "Portal IECLB Parobé - Diagnóstico v1.1.2 R3\n";echo str_repeat('=',82)."\n";$errors=0;$v=defined('APP_VERSION')?(string)APP_VERSION:'0.0.0';echo "[INFO] APP_VERSION: {$v}\n";$file=$root.'/admin/eventos/index.php';$c=is_file($file)?(file_get_contents($file)?:''):'';foreach(['PORTAL_EVENT_SEARCH_PARAMS_V112_R3',':busca_titulo',':busca_local',':busca_resumo'] as $m){$ok=str_contains($c,$m);echo '['.($ok?'OK':'ERRO')."] {$m}\n";if(!$ok)$errors++;}
try{$pdo=Database::connection();$sql="SELECT e.id FROM eventos e WHERE (e.titulo LIKE :busca_titulo OR e.local LIKE :busca_local OR e.resumo LIKE :busca_resumo) LIMIT 1";$stmt=$pdo->prepare($sql);$t='%teste%';$stmt->execute(['busca_titulo'=>$t,'busca_local'=>$t,'busca_resumo'=>$t]);echo "[OK] Consulta preparada executou sem HY093.\n";}catch(Throwable $e){echo "[ERRO] PDO: {$e->getMessage()}\n";$errors++;}
echo "\n".str_repeat('=',82)."\n";if($errors===0){echo "RESULTADO: v1.1.2 R3 aplicada; busca da Agenda operacional.\n";exit(0);}echo "RESULTADO: {$errors} problema(s) encontrado(s).\n";exit(1);
