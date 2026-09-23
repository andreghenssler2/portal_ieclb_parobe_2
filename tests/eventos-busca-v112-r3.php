<?php

declare(strict_types=1);
ob_start();
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Execute pelo terminal.\n"); }
$root=dirname(__DIR__);
require_once $root . DIRECTORY_SEPARATOR . 'bootstrap.php';
echo "Portal IECLB Parobé - teste busca Agenda v1.1.2 R3\n";
echo str_repeat('=',78)."\n";
$errors=0;
$file=$root.'/admin/eventos/index.php';
$content=is_file($file)?(file_get_contents($file)?:''):'';
foreach(['PORTAL_EVENT_SEARCH_PARAMS_V112_R3',':busca_titulo',':busca_local',':busca_resumo',"'busca_titulo'","'busca_local'","'busca_resumo'"] as $marker){
    if(str_contains($content,$marker)){echo "[OK] {$marker}\n";}else{echo "[FALHA] Marcador ausente: {$marker}\n";$errors++;}
}
if(!str_contains($content,'LIKE :busca OR')){echo "[OK] Placeholder :busca reutilizado foi removido.\n";}else{echo "[FALHA] Ainda existe reutilização de :busca.\n";$errors++;}
try{
    $pdo=Database::connection();
    $sql="SELECT e.id FROM eventos e WHERE (e.titulo LIKE :busca_titulo OR e.local LIKE :busca_local OR e.resumo LIKE :busca_resumo) LIMIT 1";
    $stmt=$pdo->prepare($sql);
    $term='%teste%';
    $stmt->execute(['busca_titulo'=>$term,'busca_local'=>$term,'busca_resumo'=>$term]);
    echo "[OK] Prepared statement com 3 parâmetros executou sem HY093.\n";
}catch(Throwable $e){echo "[FALHA] PDO: {$e->getMessage()}\n";$errors++;}
echo str_repeat('=',78)."\n";
if($errors>0){echo "RESULTADO: {$errors} falha(s) na correção da busca da Agenda.\n";exit(1);} 
echo "RESULTADO: busca da Agenda aprovada.\n";
