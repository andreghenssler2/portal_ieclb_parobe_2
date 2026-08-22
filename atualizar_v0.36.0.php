<?php

declare(strict_types=1);

const TARGET_VERSION = '0.36.0';
const MIN_VERSION = '0.35.0';

function out(string $message=''): void { echo $message . PHP_EOL; }
function fail(string $message): never { out('[ERRO] '.$message); exit(1); }

$root = __DIR__;
$backupDir = $root.'/storage/update-backups/v'.TARGET_VERSION.'-'.date('Ymd-His');

function backupChangedFile(string $path): void
{
    global $root,$backupDir;
    if(!is_file($path)) return;
    $relative=ltrim(str_replace('\\','/',substr($path,strlen($root))),'/');
    $target=$backupDir.'/'.$relative;
    if(!is_dir(dirname($target)) && !mkdir(dirname($target),0755,true) && !is_dir(dirname($target))) {
        throw new RuntimeException('Não foi possível criar backup de '.$relative.'.');
    }
    if(!copy($path,$target)) throw new RuntimeException('Não foi possível criar backup de '.$relative.'.');
}

function writeChanged(string $path,string $content,string $label): void
{
    $old=is_file($path)?(string)file_get_contents($path):'';
    if($old===$content){out('[OK] '.$label.' já estava atualizado.');return;}
    if(is_file($path)) backupChangedFile($path);
    if(file_put_contents($path,$content,LOCK_EX)===false) throw new RuntimeException('Não foi possível gravar '.$label.'.');
    out('[OK] '.$label.' atualizado.');
}

function tableExists(PDO $pdo,string $table): bool
{
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:t');
    $stmt->execute(['t'=>$table]);
    return (int)$stmt->fetchColumn()>0;
}

function ensureSchema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS liderancas (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            autor_id INT UNSIGNED NULL,
            foto_id BIGINT UNSIGNED NULL,
            comunidade_id INT UNSIGNED NULL,
            grupo_id BIGINT UNSIGNED NULL,
            nome VARCHAR(180) NOT NULL,
            slug VARCHAR(220) NOT NULL,
            tipo ENUM('pastoral','presbiterio','lideranca','equipe','outro') NOT NULL DEFAULT 'lideranca',
            funcao VARCHAR(180) NULL,
            resumo VARCHAR(500) NULL,
            biografia LONGTEXT NULL,
            email VARCHAR(190) NULL,
            telefone VARCHAR(40) NULL,
            whatsapp VARCHAR(40) NULL,
            instagram VARCHAR(500) NULL,
            facebook VARCHAR(500) NULL,
            exibir_email TINYINT(1) NOT NULL DEFAULT 0,
            exibir_telefone TINYINT(1) NOT NULL DEFAULT 0,
            exibir_whatsapp TINYINT(1) NOT NULL DEFAULT 0,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            ordem INT NOT NULL DEFAULT 0,
            seo_titulo VARCHAR(220) NULL,
            seo_descricao VARCHAR(320) NULL,
            seo_noindex TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_liderancas_slug (slug),
            KEY idx_liderancas_ativo_ordem (ativo,ordem,nome),
            KEY idx_liderancas_tipo (tipo,ativo),
            KEY idx_liderancas_comunidade (comunidade_id,ativo),
            KEY idx_liderancas_grupo (grupo_id,ativo),
            KEY idx_liderancas_foto (foto_id),
            CONSTRAINT fk_liderancas_autor FOREIGN KEY (autor_id) REFERENCES usuarios(id) ON DELETE SET NULL,
            CONSTRAINT fk_liderancas_foto FOREIGN KEY (foto_id) REFERENCES midias(id) ON DELETE SET NULL,
            CONSTRAINT fk_liderancas_comunidade FOREIGN KEY (comunidade_id) REFERENCES comunidades(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    out('[OK] Tabela liderancas verificada.');
}

function seed(PDO $pdo): void
{
    $pdo->exec(
        "INSERT INTO permissoes (nome,slug,grupo,descricao,ordem)
         VALUES ('Gerenciar lideranças','liderancas.gerenciar','Conteúdo','Administrar equipe, pastores e lideranças públicas do portal.',49)
         ON DUPLICATE KEY UPDATE nome=VALUES(nome),grupo=VALUES(grupo),descricao=VALUES(descricao),ordem=VALUES(ordem)"
    );
    $pdo->exec(
        "INSERT IGNORE INTO perfil_permissoes (perfil_id,permissao_id)
         SELECT p.id,pe.id FROM perfis p JOIN permissoes pe ON pe.slug='liderancas.gerenciar'
         WHERE p.slug IN ('administrador','secretaria','comunicacao','pastor')"
    );
    foreach([
        ['permalink_lideranca','lideranca','texto'],
        ['seo_sitemap_liderancas','1','booleano'],
    ] as [$key,$value,$type]){
        $stmt=$pdo->prepare(
            "INSERT INTO configuracoes (chave,valor,tipo) VALUES (:chave,:valor,:tipo)
             ON DUPLICATE KEY UPDATE chave=VALUES(chave)"
        );
        $stmt->execute(['chave'=>$key,'valor'=>$value,'tipo'=>$type]);
    }

    if(tableExists($pdo,'menus') && tableExists($pdo,'menu_itens')){
        try{
            $pdo->exec(
                "INSERT INTO menu_itens (menu_id,tipo,titulo,url,ordem,ativo)
                 SELECT m.id,'link','Lideranças','liderancas',65,1
                 FROM menus m
                 WHERE m.localizacao='principal'
                   AND NOT EXISTS (
                       SELECT 1 FROM menu_itens mi
                       WHERE mi.menu_id=m.id AND mi.tipo='link'
                         AND mi.url IN ('liderancas','/liderancas','liderancas.php')
                   )"
            );
        }catch(Throwable $e){
            out('[AVISO] Não foi possível acrescentar Lideranças automaticamente ao Menu Principal: '.$e->getMessage());
        }
    }
    out('[OK] Permissão, perfis e configurações iniciais verificados.');
}

function patchBootstrap(string $path): void
{
    $src=(string)file_get_contents($path);
    if(str_contains($src,'LeadershipService.php')){out('[OK] bootstrap.php já carrega LeadershipService.');return;}
    $anchors=[
        "require_once __DIR__ . '/app/Services/DocumentService.php';",
        "require_once __DIR__ . '/app/Services/HomeService.php';",
        "require_once __DIR__ . '/app/Services/MediaService.php';",
    ];
    foreach($anchors as $anchor){
        if(str_contains($src,$anchor)){
            $src=str_replace($anchor,$anchor."\nrequire_once __DIR__ . '/app/Services/LeadershipService.php';",$src);
            writeChanged($path,$src,'bootstrap.php');
            return;
        }
    }
    throw new RuntimeException('Não foi possível integrar LeadershipService no bootstrap.php.');
}

function patchFunctions(string $path): void
{
    $src=(string)file_get_contents($path);
    $original=$src;

    if(!str_contains($src,"'lideranca' => 'lideranca'")){
        $anchor="'documento' => 'documento',";
        if(str_contains($src,$anchor)){
            $src=str_replace($anchor,$anchor."\n        'lideranca' => 'lideranca',",$src);
        }else{
            $src=preg_replace_callback(
                '/(\$defaults\s*=\s*\[)(.*?)(\];)/s',
                static function(array $m): string {
                    if(str_contains($m[2],"'lideranca'")) return $m[0];
                    $body=rtrim($m[2]);
                    if($body!==''&&!str_ends_with(trim($body),','))$body.=',';
                    return $m[1].$body."\n        'lideranca' => 'lideranca',\n    ".$m[3];
                },
                $src,1
            )??$src;
        }
    }

    $src=preg_replace_callback(
        '/(\$allowed\s*=\s*\[)([^\]]*\'noticia\'[^\]]*\'documento\'[^\]]*)(\];)/',
        static function(array $m): string {
            if(str_contains($m[2],"'lideranca'")) return $m[0];
            $body=rtrim($m[2]);
            if($body!==''&&!str_ends_with(trim($body),','))$body.=',';
            return $m[1].$body." 'lideranca'".$m[3];
        },
        $src
    )??$src;

    $src=preg_replace_callback(
        '/(function\s+uniqueSlug\b.*?\$allowed\s*=\s*\[)([^\]]*)(\];)/s',
        static function(array $m): string {
            if(str_contains($m[2],"'liderancas'")) return $m[0];
            $body=rtrim($m[2]);
            if($body!==''&&!str_ends_with(trim($body),','))$body.=',';
            return $m[1].$body." 'liderancas'".$m[3];
        },
        $src,1
    )??$src;

    if($src===$original){out('[OK] app/Helpers/functions.php já suporta lideranças.');return;}
    writeChanged($path,$src,'app/Helpers/functions.php');
}

function patchRouter(string $path): void
{
    $src=(string)file_get_contents($path);
    $original=$src;

    if(!preg_match("/['\"]liderancas['\"]\s*=>\s*['\"]liderancas\.php['\"]/", $src)){
        $anchor="'documentos' => 'documentos.php',";
        if(str_contains($src,$anchor)){
            $src=str_replace($anchor,$anchor."\n        'liderancas' => 'liderancas.php',",$src);
        }else{
            $anchor="'newsletter' => 'newsletter.php',";
            if(!str_contains($src,$anchor)) throw new RuntimeException('Não foi possível adicionar /liderancas ao router.php.');
            $src=str_replace($anchor,$anchor."\n        'liderancas' => 'liderancas.php',",$src);
        }
    }

    $src=preg_replace_callback(
        '/foreach\s*\(\s*\[([^\]]*\'noticia\'[^\]]*)\]\s+as\s+\$type\s*\)/',
        static function(array $m): string {
            if(str_contains($m[1],"'lideranca'")) return $m[0];
            $body=rtrim($m[1]);
            if($body!==''&&!str_ends_with(trim($body),','))$body.=',';
            return 'foreach (['.$body." 'lideranca'] as \$type)";
        },
        $src,1
    )??$src;

    if($src===$original){out('[OK] router.php já suporta lideranças.');return;}
    writeChanged($path,$src,'router.php');
}

function patchAdminHeader(string $path): void
{
    $src=(string)file_get_contents($path);
    $original=$src;

    if(!str_contains($src,'$leadershipOpen')){
        $groupsLine=<<<'PHP'
$groupsOpen = $startsPath('grupos');
PHP;
        $documentsLine=<<<'PHP'
$documentsOpen = $startsPath('documentos');
PHP;
        $leadershipLine=<<<'PHP'
$leadershipOpen = $startsPath('liderancas');
PHP;

        if(str_contains($src,$documentsLine)){
            $src=str_replace($documentsLine,$leadershipLine."\n".$documentsLine,$src);
        }elseif(str_contains($src,$groupsLine)){
            $src=str_replace($groupsLine,$groupsLine."\n".$leadershipLine,$src);
        }else{
            $pattern='/(\$startsPath\s*=\s*static\s+fn\([^;]+;\s*)/';
            if(!preg_match($pattern,$src)) throw new RuntimeException('Não foi possível registrar o estado do menu Lideranças.');
            $src=preg_replace($pattern,'$1'."\n".$leadershipLine."\n",$src,1)??$src;
        }
    }

    if(!str_contains($src,'id="menuLiderancas"')){
        $block=<<<'PHP'

                <?php if (Auth::can('liderancas.gerenciar')): ?>
                    <button class="admin-nav-link admin-nav-toggle <?= $leadershipOpen ? 'active' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#menuLiderancas" aria-expanded="<?= $leadershipOpen ? 'true' : 'false' ?>">
                        <i class="bi bi-people"></i><span>Equipe / Lideranças</span><i class="bi bi-chevron-down admin-nav-chevron"></i>
                    </button>
                    <div class="collapse admin-nav-submenu <?= $leadershipOpen ? 'show' : '' ?>" id="menuLiderancas">
                        <a class="<?= $isPath('liderancas/index.php') ? 'active' : '' ?>" href="<?= e(url('admin/liderancas/index.php')) ?>">Todas as Pessoas</a>
                        <a class="<?= $isPath('liderancas/form.php') && !isset($_GET['id']) ? 'active' : '' ?>" href="<?= e(url('admin/liderancas/form.php')) ?>">Adicionar Nova</a>
                    </div>
                <?php endif; ?>

PHP;
        $anchors=[
            "<?php if (Auth::can('documentos.gerenciar')): ?>",
            "<?php if (Auth::can('newsletter.gerenciar')): ?>",
            "<?php if (Auth::can('auditoria.visualizar')): ?>",
        ];
        $pos=false;
        foreach($anchors as $anchor){
            $pos=strpos($src,$anchor);
            if($pos!==false) break;
        }
        if($pos===false) throw new RuntimeException('Não foi possível inserir o menu Equipe / Lideranças em admin/_header.php.');
        $src=substr($src,0,$pos).$block.substr($src,$pos);
    }

    if($src===$original){out('[OK] admin/_header.php já possui Equipe / Lideranças.');return;}
    writeChanged($path,$src,'admin/_header.php');
}

function patchSearch(string $path): void
{
    $src=(string)file_get_contents($path);
    $original=$src;

    if(!str_contains($src,"['lideranca',")){
        $line="        ['lideranca', \"SELECT nome titulo,slug,resumo,biografia conteudo,updated_at dt FROM liderancas WHERE ativo=1 AND (nome LIKE :q OR funcao LIKE :q OR resumo LIKE :q OR biografia LIKE :q) ORDER BY ordem ASC,nome ASC LIMIT 15\"],\n";
        $anchor="        ['documento',";
        $pos=strpos($src,$anchor);
        if($pos===false){
            $anchor="        ['galeria',";
            $pos=strpos($src,$anchor);
        }
        if($pos===false) throw new RuntimeException('Não foi possível integrar Lideranças em busca.php.');
        $src=substr($src,0,$pos).$line.substr($src,$pos);
    }

    if(!str_contains($src,"'lideranca'=>'Liderança'")){
        $src=preg_replace_callback(
            '/\$labels=\[([^\]]*)\];/',
            static function(array $m): string {
                if(str_contains($m[1],"'lideranca'")) return $m[0];
                $body=rtrim($m[1]);
                if($body!==''&&!str_ends_with(trim($body),','))$body.=',';
                return '$labels=['.$body.",'lideranca'=>'Liderança'];";
            },
            $src,1
        )??$src;
    }
    $src=str_replace('Buscar notícias, páginas, eventos, documentos...','Buscar notícias, páginas, eventos, documentos, lideranças...',$src);
    $src=str_replace('Buscar notícias, páginas, eventos...','Buscar notícias, páginas, eventos, documentos, lideranças...',$src);

    if($src===$original){out('[OK] busca.php já inclui lideranças.');return;}
    writeChanged($path,$src,'busca.php');
}

function patchSitemap(string $path): void
{
    $src=(string)file_get_contents($path);
    $original=$src;

    if(!str_contains($src,"'lideranca.sitemaps.xml'")){
        $anchor="    'documento.sitemaps.xml' => 'documentos.sitemaps.xml',";
        $aliases="    'lideranca.sitemaps.xml' => 'liderancas.sitemaps.xml',\n    'lideranca.sitemap.xml' => 'liderancas.sitemaps.xml',\n    'liderancas.sitemap.xml' => 'liderancas.sitemaps.xml',\n";
        if(str_contains($src,$anchor)) $src=str_replace($anchor,$aliases.$anchor,$src);
        else {
            $anchor="    'formulario.sitemaps.xml' => 'formularios.sitemaps.xml',";
            if(str_contains($src,$anchor)) $src=str_replace($anchor,$aliases.$anchor,$src);
        }
    }

    if(!str_contains($src,"'liderancas.sitemaps.xml' => [")){
        $anchor="    'documentos.sitemaps.xml' => [";
        $block="    'liderancas.sitemaps.xml' => [\n        'enabled' => (\$settings['seo_sitemap_liderancas'] ?? '1') === '1',\n        'lastmod' => sitemapMaxDate(\$pdo, \"SELECT MAX(updated_at) FROM liderancas WHERE ativo=1 AND seo_noindex=0\"),\n    ],\n";
        if(!str_contains($src,$anchor)){
            $anchor="    'formularios.sitemaps.xml' => [";
        }
        if(!str_contains($src,$anchor)) throw new RuntimeException('Não foi possível adicionar o grupo de lideranças ao sitemap.php.');
        $src=str_replace($anchor,$block.$anchor,$src);
    }

    if(!str_contains($src,"sitemapEmitUrl(url('liderancas')")){
        $anchor="        sitemapEmitUrl(url('documentos'), null, 'weekly', '0.7', [], \$includeImages);";
        if(str_contains($src,$anchor)){
            $src=str_replace($anchor,$anchor."\n        sitemapEmitUrl(url('liderancas'), null, 'monthly', '0.7', [], \$includeImages);",$src);
        }else{
            $anchor="        sitemapEmitUrl(url('galerias'), null, 'weekly', '0.7', [], \$includeImages);";
            if(str_contains($src,$anchor)) $src=str_replace($anchor,$anchor."\n        sitemapEmitUrl(url('liderancas'), null, 'monthly', '0.7', [], \$includeImages);",$src);
        }
    }

    if(!str_contains($src,"if (\$requestFile === 'liderancas.sitemaps.xml')")){
        $anchor="    if (\$requestFile === 'documentos.sitemaps.xml')";
        $pos=strpos($src,$anchor);
        if($pos===false){
            $anchor="    if (\$requestFile === 'formularios.sitemaps.xml')";
            $pos=strpos($src,$anchor);
        }
        if($pos===false) throw new RuntimeException('Não foi possível inserir a emissão de lideranças no sitemap.php.');
        $block=<<<'PHP'
    if ($requestFile === 'liderancas.sitemaps.xml') {
        $sql = "SELECT l.slug,l.updated_at lm,m.caminho foto
                FROM liderancas l
                LEFT JOIN midias m ON m.id=l.foto_id
                WHERE l.ativo=1 AND l.seo_noindex=0
                ORDER BY l.ordem ASC,l.nome ASC";
        foreach ($pdo->query($sql)->fetchAll() as $row) {
            $images = !empty($row['foto']) ? [mediaUrl((string)$row['foto'])] : [];
            sitemapEmitUrl(contentUrl('lideranca', (string)$row['slug']), sitemapDate((string)$row['lm']), 'monthly', '0.6', $images, $includeImages);
        }
    }

PHP;
        $src=substr($src,0,$pos).$block.substr($src,$pos);
    }

    if($src===$original){out('[OK] sitemap.php já inclui lideranças.');return;}
    writeChanged($path,$src,'sitemap.php');
}

function patchSeoAdmin(string $path): void
{
    $src=(string)file_get_contents($path);
    $original=$src;

    if(!str_contains($src,"'seo_sitemap_liderancas' => '1'")){
        $anchor="    'seo_sitemap_documentos' => '1',";
        if(str_contains($src,$anchor)) $src=str_replace($anchor,"    'seo_sitemap_liderancas' => '1',\n".$anchor,$src);
        else {
            $anchor="    'seo_sitemap_formularios' => '0',";
            if(str_contains($src,$anchor)) $src=str_replace($anchor,"    'seo_sitemap_liderancas' => '1',\n".$anchor,$src);
        }
    }

    // Acrescenta a chave ao array de checkboxes salvos, sem confundir com o valor padrão acima.
    if(!preg_match('/foreach\s*\(\s*\[([^\]]*seo_sitemap_liderancas[^\]]*)\]\s+as\s+\$key/s',$src)){
        $src=preg_replace_callback(
            '/foreach\s*\(\s*\[([^\]]*seo_sitemap_ativo[^\]]*)\]\s+as\s+\$key/s',
            static function(array $m): string {
                if(str_contains($m[1],"'seo_sitemap_liderancas'")) return $m[0];
                $body=rtrim($m[1]);
                if($body!==''&&!str_ends_with(trim($body),','))$body.=',';
                return 'foreach (['.$body.",'seo_sitemap_liderancas'] as \$key";
            },
            $src,1
        )??$src;
    }

    if(!str_contains($src,"'Lideranças' => \"SELECT COUNT(*) FROM liderancas")){
        $anchor="    'Documentos' => \"SELECT COUNT(*) FROM documentos";
        $pos=strpos($src,$anchor);
        if($pos===false){
            $anchor="    'Formulários' => \"SELECT COUNT(*) FROM formularios";
            $pos=strpos($src,$anchor);
        }
        if($pos!==false){
            $line="    'Lideranças' => \"SELECT COUNT(*) FROM liderancas WHERE ativo=1 AND seo_noindex=0\",\n";
            $src=substr($src,0,$pos).$line.substr($src,$pos);
        }
    }

    if(!str_contains($src,"'key'=>'seo_sitemap_liderancas'")){
        $anchor="    ['key'=>'seo_sitemap_documentos'";
        $pos=strpos($src,$anchor);
        if($pos===false){
            $anchor="    ['key'=>'seo_sitemap_formularios'";
            $pos=strpos($src,$anchor);
        }
        if($pos!==false){
            $line="    ['key'=>'seo_sitemap_liderancas','label'=>'Equipe / Lideranças','file'=>'liderancas.sitemaps.xml','count'=>\$counts['Lideranças'] ?? 0,'description'=>'Pastores, presbitério, lideranças e equipe com perfil público.'],\n";
            $src=substr($src,0,$pos).$line.substr($src,$pos);
        }
    }

    $src=str_replace(
        "'description'=>'Home, Agenda, Comunidades, Galerias e Documentos.'",
        "'description'=>'Home, Agenda, Comunidades, Galerias, Lideranças e Documentos.'",
        $src
    );
    $src=str_replace("'count'=>5,'description'=>'Home, Agenda, Comunidades, Galerias e Documentos.'","'count'=>6,'description'=>'Home, Agenda, Comunidades, Galerias, Lideranças e Documentos.'",$src);

    if($src===$original){out('[OK] admin/seo/sitemap.php já inclui lideranças.');return;}
    writeChanged($path,$src,'admin/seo/sitemap.php');
}

function patchHtaccess(string $path): void
{
    $src=(string)file_get_contents($path);
    if(str_contains($src,'PORTAL IECLB v0.36.0 - liderancas')){out('[OK] .htaccess já possui regra de sitemap de lideranças.');return;}
    $block="# BEGIN PORTAL IECLB v0.36.0 - liderancas\nRewriteEngine On\nRewriteRule ^liderancas?\\.sitemaps?\\.xml$ sitemap.php [L,QSA]\n# END PORTAL IECLB v0.36.0 - liderancas\n\n";
    writeChanged($path,$block.$src,'.htaccess');
}

function patchCache(string $path): void
{
    if(!is_file($path)) return;
    $src=(string)file_get_contents($path);
    if(str_contains($src,"'lideranca'")){out('[OK] CacheService já reconhece lideranças.');return;}
    $old=$src;
    $src=str_replace("'formulario', 'documento'","'formulario', 'documento', 'lideranca'",$src);
    $src=str_replace("'newsletter', 'formulario'","'newsletter', 'formulario', 'lideranca'",$src);
    if($src===$old){out('[AVISO] CacheService não pôde ser ampliado automaticamente; o módulo continuará funcionando.');return;}
    writeChanged($path,$src,'app/Services/CacheService.php');
}

function updateVersion(string $config): void
{
    $src=(string)file_get_contents($config);
    $old=$src;
    $pattern="/define\\(\\s*['\"]APP_VERSION['\"]\\s*,\\s*['\"][^'\"]*['\"]\\s*\\)\\s*;/";
    if(preg_match($pattern,$src)){
        $src=preg_replace($pattern,"define('APP_VERSION', '".TARGET_VERSION."');",$src,1)??$src;
    }else{
        $declare='declare(strict_types=1);';
        $pos=strpos($src,$declare);
        if($pos!==false){
            $at=$pos+strlen($declare);
            $src=substr($src,0,$at)."\n\ndefine('APP_VERSION', '".TARGET_VERSION."');".substr($src,$at);
        }else{
            $pos=strpos($src,'<?php');
            if($pos===false) throw new RuntimeException('config/config.php inválido.');
            $at=$pos+5;
            $src=substr($src,0,$at)."\n\ndefine('APP_VERSION', '".TARGET_VERSION."');".substr($src,$at);
        }
    }
    if($src!==$old) writeChanged($config,$src,'config/config.php');
    else out('[OK] APP_VERSION já é '.TARGET_VERSION.'.');
}

out('Portal IECLB Parobé - atualização v'.TARGET_VERSION);
out(str_repeat('-',76));

$config=$root.'/config/config.php';
$dbFile=$root.'/mod/db/Database.php';
if(!is_file($config)) fail('config/config.php não encontrado.');
if(!is_file($dbFile)) fail('mod/db/Database.php não encontrado.');

foreach([
    'app/Services/LeadershipService.php',
    'admin/liderancas/index.php',
    'admin/liderancas/form.php',
    'liderancas.php',
    'lideranca.php',
    'migrations/2026_08_22_v0.36.0.sql',
] as $required){
    if(!is_file($root.'/'.$required)) fail('Arquivo da v0.36.0 não encontrado: '.$required);
}

require_once $config;
require_once $dbFile;

$current=defined('APP_VERSION')?(string)APP_VERSION:'0.0.0';
out('Versão identificada: '.$current);
if(version_compare($current,MIN_VERSION,'<')) fail('A v0.36.0 requer Portal v'.MIN_VERSION.' ou superior.');

try{
    $pdo=Database::connection();
    out('[OK] Conexão com o banco realizada.');
    ensureSchema($pdo);
    seed($pdo);

    patchBootstrap($root.'/bootstrap.php');
    patchFunctions($root.'/app/Helpers/functions.php');
    patchRouter($root.'/router.php');
    patchAdminHeader($root.'/admin/_header.php');
    patchSearch($root.'/busca.php');
    patchSitemap($root.'/sitemap.php');
    patchSeoAdmin($root.'/admin/seo/sitemap.php');
    patchHtaccess($root.'/.htaccess');
    patchCache($root.'/app/Services/CacheService.php');
    updateVersion($config);

    if(function_exists('opcache_reset')) @opcache_reset();

    out(str_repeat('-',76));
    out('Atualização v'.TARGET_VERSION.' concluída.');
    out('Acesse Equipe / Lideranças no painel e atualize o navegador com Ctrl+F5.');
    if(is_dir($backupDir)) out('Backups: '.str_replace('\\','/',$backupDir));
}catch(Throwable $e){
    fail($e->getMessage());
}
