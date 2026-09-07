<?php

declare(strict_types=1);
ob_start();
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Execute este atualizador somente pelo terminal.\n"); }
$root=__DIR__;$payload=$root.'/_update_payload_v1.1.2_R3';$stamp=date('Ymd-His');
function v112r3Fail(string $m): never { fwrite(STDERR,"[ERRO] {$m}\n"); exit(1); }
function v112r3Read(string $f,string $l): string { if(!is_file($f))v112r3Fail("Arquivo não encontrado: {$l}");$c=file_get_contents($f);if($c===false)v112r3Fail("Não foi possível ler: {$l}");return $c; }
function v112r3Lint(string $c,string $l): void { if(!function_exists('exec'))return;$t=tempnam(sys_get_temp_dir(),'ieclb_r3_');if($t===false||file_put_contents($t,$c)===false)v112r3Fail("Não foi possível validar {$l}");$o=[];$s=0;@exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($t).' 2>&1',$o,$s);@unlink($t);if($s!==0)v112r3Fail("Erro de sintaxe em {$l}:\n".implode("\n",$o)); }
function v112r3Write(string $f,string $c): void { $d=dirname($f);if(!is_dir($d)&&!@mkdir($d,0775,true)&&!is_dir($d))v112r3Fail("Não foi possível criar: {$d}");$t=$f.'.tmp-v112r3';if(file_put_contents($t,$c,LOCK_EX)===false)v112r3Fail("Não foi possível gravar: {$f}");if(DIRECTORY_SEPARATOR==='\\'&&is_file($f)&&!@unlink($f)){@unlink($t);v112r3Fail("Não foi possível substituir: {$f}");}if(!@rename($t,$f)){@unlink($t);v112r3Fail("Não foi possível finalizar: {$f}");}}
function v112r3Patch(string $c): string {
    if(str_contains($c,'PORTAL_EVENT_SEARCH_PARAMS_V112_R3'))return $c;
    $old=<<<'PHP'
if ($busca !== '') {
    $where[] = '(e.titulo LIKE :busca OR e.local LIKE :busca OR e.resumo LIKE :busca)';
    $params['busca'] = '%' . $busca . '%';
}

PHP;
    if(!str_contains($c,$old))v112r3Fail('admin/eventos/index.php: bloco de busca esperado não encontrado. Nenhum arquivo foi alterado.');
    $new=<<<'PHP'
if ($busca !== '') {
    /* PORTAL_EVENT_SEARCH_PARAMS_V112_R3 */
    $where[] = '(e.titulo LIKE :busca_titulo OR e.local LIKE :busca_local OR e.resumo LIKE :busca_resumo)';
    $searchTerm = '%' . $busca . '%';
    $params['busca_titulo'] = $searchTerm;
    $params['busca_local'] = $searchTerm;
    $params['busca_resumo'] = $searchTerm;
}

PHP;
    $p=str_replace($old,$new,$c,$n);if($n!==1)v112r3Fail('admin/eventos/index.php: número inesperado de substituições.');return $p;
}
echo "Portal IECLB Parobé - Correção v1.1.2 R3 Busca da Agenda\n";echo str_repeat('=',82)."\n";
require_once $root.'/bootstrap.php';$v=defined('APP_VERSION')?(string)APP_VERSION:'0.0.0';echo "Versão atual: {$v}\n\n";if(version_compare($v,'1.1.2','<'))v112r3Fail('A correção R3 requer a v1.1.2 instalada.');
$rel='admin/eventos/index.php';$file=$root.'/'.$rel;$orig=v112r3Read($file,$rel);$patched=v112r3Patch($orig);v112r3Lint($patched,$rel);
foreach(['PORTAL_EVENT_SEARCH_PARAMS_V112_R3',':busca_titulo',':busca_local',':busca_resumo',"'busca_titulo'","'busca_local'","'busca_resumo'"] as $m){if(!str_contains($patched,$m))v112r3Fail("Validação em memória falhou: {$m}");}
$testRel='tests/eventos-busca-v112-r3.php';$test=v112r3Read($payload.'/'.$testRel,$testRel);v112r3Lint($test,$testRel);$docRel='docs/RELEASE_v1.1.2_R3.md';$doc=v112r3Read($payload.'/'.$docRel,$docRel);
echo "[OK] Alterações validadas em memória.\n";
$backup=$root.'/storage/update-backups/v1.1.2-R3-eventos-busca-'.$stamp.'/'.$rel;if(!is_dir(dirname($backup))&&!@mkdir(dirname($backup),0775,true)&&!is_dir(dirname($backup)))v112r3Fail('Não foi possível criar a pasta de backup.');if(file_put_contents($backup,$orig,LOCK_EX)===false)v112r3Fail('Não foi possível salvar o backup.');echo "[OK] Backup criado.\n";
v112r3Write($file,$patched);v112r3Write($root.'/'.$testRel,$test);v112r3Write($root.'/'.$docRel,$doc);echo "[OK] {$rel} atualizado.\n";
if(function_exists('opcache_reset'))@opcache_reset();
if(function_exists('exec')){$o=[];$s=0;@exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/'.$testRel).' 2>&1',$o,$s);echo "\n".implode("\n",$o)."\n";echo $s===0?"\n[OK] Teste R3 aprovado.\n":"\n[AVISO] O teste R3 encontrou problema.\n";}
echo "\n".str_repeat('=',82)."\n CORREÇÃO v1.1.2 R3 CONCLUÍDA\n".str_repeat('=',82)."\n\n";echo "APP_VERSION permanece {$v}.\nBusca da Agenda corrigida para PDO/MySQL.\nSem migração de banco.\n\nValidação:\n  php diagnosticar_v1.1.2_R3.php\n  php tests/eventos-busca-v112-r3.php\n";
