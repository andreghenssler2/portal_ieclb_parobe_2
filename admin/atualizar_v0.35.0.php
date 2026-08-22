<?php

declare(strict_types=1);

const TARGET_VERSION = '0.35.0';
const MIN_VERSION = '0.34.0';

function out(string $message=''): void { echo $message . PHP_EOL; }
function fail(string $message): never { out('[ERRO] ' . $message); exit(1); }

$root = __DIR__;
$backupDir = $root . '/storage/update-backups/v' . TARGET_VERSION . '-' . date('Ymd-His');

function backupChangedFile(string $path): void
{
    global $root, $backupDir;
    if (!is_file($path)) return;
    $relative = ltrim(str_replace('\\','/',substr($path,strlen($root))),'/');
    $target = $backupDir . '/' . $relative;
    if (!is_dir(dirname($target)) && !mkdir(dirname($target),0755,true) && !is_dir(dirname($target))) {
        throw new RuntimeException('Não foi possível criar backup de ' . $relative . '.');
    }
    if (!copy($path,$target)) {
        throw new RuntimeException('Não foi possível criar backup de ' . $relative . '.');
    }
}

function writeIfChanged(string $path, string $source, string $label): void
{
    $old = is_file($path) ? (string)file_get_contents($path) : '';
    if ($old === $source) {
        out('[OK] ' . $label . ' já estava atualizado.');
        return;
    }
    if (is_file($path)) backupChangedFile($path);
    if (file_put_contents($path,$source,LOCK_EX)===false) {
        throw new RuntimeException('Não foi possível gravar ' . $label . '.');
    }
    out('[OK] ' . $label . ' atualizado.');
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=:t');
    $stmt->execute(['t'=>$table]);
    return (int)$stmt->fetchColumn()>0;
}

function ensureSchema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS documento_categorias (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nome VARCHAR(120) NOT NULL,
            slug VARCHAR(140) NOT NULL,
            descricao TEXT NULL,
            ordem INT NOT NULL DEFAULT 0,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_documento_categorias_slug (slug),
            KEY idx_documento_categorias_ativo_ordem (ativo,ordem,nome)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS documentos (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            autor_id INT UNSIGNED NULL,
            categoria_id INT UNSIGNED NULL,
            midia_id BIGINT UNSIGNED NULL,
            titulo VARCHAR(220) NOT NULL,
            slug VARCHAR(240) NOT NULL,
            descricao LONGTEXT NULL,
            status ENUM('rascunho','publicado','arquivado') NOT NULL DEFAULT 'rascunho',
            ordem INT NOT NULL DEFAULT 0,
            publicado_em DATETIME NULL,
            seo_titulo VARCHAR(220) NULL,
            seo_descricao VARCHAR(320) NULL,
            seo_noindex TINYINT(1) NOT NULL DEFAULT 0,
            downloads BIGINT UNSIGNED NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_documentos_slug (slug),
            KEY idx_documentos_status_data (status,publicado_em),
            KEY idx_documentos_categoria (categoria_id,status),
            KEY idx_documentos_midia (midia_id),
            CONSTRAINT fk_documentos_autor FOREIGN KEY (autor_id) REFERENCES usuarios(id) ON DELETE SET NULL,
            CONSTRAINT fk_documentos_categoria FOREIGN KEY (categoria_id) REFERENCES documento_categorias(id) ON DELETE SET NULL,
            CONSTRAINT fk_documentos_midia FOREIGN KEY (midia_id) REFERENCES midias(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    out('[OK] Tabelas documento_categorias e documentos verificadas.');
}

function seedData(PDO $pdo): void
{
    $pdo->exec(
        "INSERT INTO permissoes (nome,slug,grupo,descricao,ordem)
         VALUES ('Gerenciar documentos','documentos.gerenciar','Conteúdo','Publicar e organizar documentos e downloads públicos.',48)
         ON DUPLICATE KEY UPDATE nome=VALUES(nome),grupo=VALUES(grupo),descricao=VALUES(descricao),ordem=VALUES(ordem)"
    );

    $pdo->exec(
        "INSERT IGNORE INTO perfil_permissoes (perfil_id,permissao_id)
         SELECT p.id,pe.id FROM perfis p JOIN permissoes pe ON pe.slug='documentos.gerenciar'
         WHERE p.slug IN ('administrador','secretaria','comunicacao')"
    );

    foreach ([
        ['permalink_documento','documento','texto'],
        ['seo_sitemap_documentos','1','booleano'],
    ] as [$key,$value,$type]) {
        $stmt=$pdo->prepare(
            "INSERT INTO configuracoes (chave,valor,tipo) VALUES (:chave,:valor,:tipo)
             ON DUPLICATE KEY UPDATE chave=VALUES(chave)"
        );
        $stmt->execute(['chave'=>$key,'valor'=>$value,'tipo'=>$type]);
    }

    if (tableExists($pdo,'menus') && tableExists($pdo,'menu_itens')) {
        try {
            $pdo->exec(
                "INSERT INTO menu_itens (menu_id,tipo,titulo,url,ordem,ativo)
                 SELECT m.id,'link','Documentos','documentos',70,1
                 FROM menus m
                 WHERE m.localizacao='principal'
                   AND NOT EXISTS (
                       SELECT 1 FROM menu_itens mi
                       WHERE mi.menu_id=m.id AND mi.tipo='link'
                         AND mi.url IN ('documentos','/documentos','documentos.php')
                   )"
            );
        } catch (Throwable $e) {
            out('[AVISO] Não foi possível adicionar Documentos automaticamente ao Menu Principal: ' . $e->getMessage());
        }
    }
    out('[OK] Permissão e configurações iniciais verificadas.');
}

function patchBootstrap(string $path): void
{
    $src=(string)file_get_contents($path);
    if (str_contains($src,"DocumentService.php")) { out('[OK] bootstrap.php já carrega DocumentService.'); return; }

    $anchors=[
        "require_once __DIR__ . '/app/Services/HomeService.php';",
        "require_once __DIR__ . '/app/Services/MediaService.php';",
    ];
    foreach($anchors as $anchor){
        if(str_contains($src,$anchor)){
            $src=str_replace($anchor,$anchor . "\nrequire_once __DIR__ . '/app/Services/DocumentService.php';",$src);
            writeIfChanged($path,$src,'bootstrap.php');
            return;
        }
    }
    throw new RuntimeException('Não foi possível integrar DocumentService no bootstrap.php.');
}

function patchFunctions(string $path): void
{
    $src=(string)file_get_contents($path);
    $original=$src;

    if(!str_contains($src,"'documento' => 'documento'") && !str_contains($src,"'documento'=>'documento'")){
        $src=preg_replace_callback(
            '/function\s+permalinkPrefix\b.*?\$defaults\s*=\s*\[([^\]]*)\];/s',
            static function(array $m): string {
                $body=$m[1];
                if(str_contains($body,"'documento'")) return $m[0];
                $body=rtrim($body);
                if($body!=='' && !str_ends_with(trim($body),',')) $body.=',';
                $body.="\n        'documento' => 'documento',\n    ";
                return str_replace($m[1],$body,$m[0]);
            },
            $src,1
        ) ?? $src;
    }

    // Arrays de tipos públicos usados por contentUrl/routeSlug.
    $src=preg_replace_callback(
        '/\$allowed\s*=\s*\[([^\]]*)\];/',
        static function(array $m): string {
            $body=$m[1];
            if(!str_contains($body,"'noticia'") || !str_contains($body,"'formulario'") || str_contains($body,"'documento'")) return $m[0];
            $body=rtrim($body);
            if($body!=='' && !str_ends_with(trim($body),',')) $body.=',';
            $body.=" 'documento'";
            return '$allowed = ['.$body.'];';
        },
        $src
    ) ?? $src;

    // Tabelas aceitas por uniqueSlug.
    $src=preg_replace_callback(
        '/(function\s+uniqueSlug\b.*?\$allowed\s*=\s*\[)([^\]]*)(\];)/s',
        static function(array $m): string {
            $body=$m[2];
            foreach(["'documentos'","'documento_categorias'"] as $item){
                if(!str_contains($body,$item)){
                    $body=rtrim($body);
                    if($body!=='' && !str_ends_with(trim($body),',')) $body.=',';
                    $body.=' '.$item;
                }
            }
            return $m[1].$body.$m[3];
        },
        $src,1
    ) ?? $src;

    if($src===$original) { out('[OK] app/Helpers/functions.php já suporta documentos.'); return; }
    writeIfChanged($path,$src,'app/Helpers/functions.php');
}

function patchRouter(string $path): void
{
    $src=(string)file_get_contents($path);
    $original=$src;

    if(!preg_match("/['\"]documentos['\"]\s*=>\s*['\"]documentos\.php['\"]/", $src)){
        if(str_contains($src,"'newsletter' => 'newsletter.php',")){
            $src=str_replace("'newsletter' => 'newsletter.php',","'newsletter' => 'newsletter.php',\n        'documentos' => 'documentos.php',",$src);
        } elseif(str_contains($src,"'busca' => 'busca.php',")){
            $src=str_replace("'busca' => 'busca.php',","'busca' => 'busca.php',\n        'documentos' => 'documentos.php',",$src);
        } else {
            throw new RuntimeException('Não foi possível adicionar /documentos ao router.php.');
        }
    }

    if(!str_contains($src,'v0.35.0 - download de documento')){
        $block=<<<'PHP'

// v0.35.0 - download de documento por caminho amigável.
if (count($segments) === 3) {
    $documentPrefix = permalinkPrefix('documento', $pdo);
    $first = strtolower(rawurldecode((string)$segments[0]));
    if (($first === $documentPrefix || $first === 'documento') && strtolower((string)$segments[2]) === 'baixar') {
        require __DIR__ . '/documento-baixar.php';
        exit;
    }
}

PHP;
        $anchor="if (count(\$segments) === 3 && strtolower(rawurldecode((string)\$segments[0])) === 'newsletter'";
        $pos=strpos($src,$anchor);
        if($pos===false){
            $anchor="if (count(\$segments) === 2 && strtolower(rawurldecode((string)\$segments[0])) === 'tag')";
            $pos=strpos($src,$anchor);
        }
        if($pos===false) throw new RuntimeException('Não foi possível inserir a rota de download em router.php.');
        $src=substr($src,0,$pos).$block.substr($src,$pos);
    }

    if(!preg_match("/foreach\s*\(\s*\[([^\]]*)'documento'([^\]]*)\]\s+as\s+\$type/", $src)){
        $src=preg_replace_callback(
            '/foreach\s*\(\s*\[([^\]]*)\]\s+as\s+\$type\s*\)/',
            static function(array $m): string {
                if(!str_contains($m[1],"'noticia'") || str_contains($m[1],"'documento'")) return $m[0];
                $body=rtrim($m[1]);
                if($body!=='' && !str_ends_with(trim($body),',')) $body.=',';
                $body.=" 'documento'";
                return 'foreach (['.$body.'] as $type)';
            },
            $src,1
        ) ?? $src;
    }

    if($src===$original){out('[OK] router.php já suporta documentos.');return;}
    writeIfChanged($path,$src,'router.php');
}

function patchAdminHeader(string $path): void
{
    $src=(string)file_get_contents($path);
    $original=$src;

    if(!str_contains($src,'$documentsOpen')){
        $newsletterLine = <<<'PHP'
$newsletterOpen = $startsPath('newsletter');
PHP;
        $groupsLine = <<<'PHP'
$groupsOpen = $startsPath('grupos');
PHP;
        $documentsLine = <<<'PHP'
$documentsOpen = $startsPath('documentos');
PHP;

        if(str_contains($src,$newsletterLine)){
            $src=str_replace(
                $newsletterLine,
                $documentsLine . "\n" . $newsletterLine,
                $src
            );
        } elseif(str_contains($src,$groupsLine)){
            $src=str_replace(
                $groupsLine,
                $groupsLine . "\n" . $documentsLine,
                $src
            );
        } else {
            // Fallback: insere logo após a declaração de $startsPath.
            $pattern = '/(\$startsPath\s*=\s*static\s+fn\([^;]+;\s*)/';
            if (preg_match($pattern, $src)) {
                $src = preg_replace(
                    $pattern,
                    '$1' . "\n" . $documentsLine . "\n",
                    $src,
                    1
                ) ?? $src;
            } else {
                throw new RuntimeException('Não foi possível registrar o estado do menu Documentos em admin/_header.php.');
            }
        }
    }

    if(!str_contains($src,'id="menuDocumentos"')){
        $block=<<<'PHP'

                <?php if (Auth::can('documentos.gerenciar')): ?>
                    <button class="admin-nav-link admin-nav-toggle <?= $documentsOpen ? 'active' : '' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#menuDocumentos" aria-expanded="<?= $documentsOpen ? 'true' : 'false' ?>">
                        <i class="bi bi-file-earmark-arrow-down"></i><span>Documentos</span><i class="bi bi-chevron-down admin-nav-chevron"></i>
                    </button>
                    <div class="collapse admin-nav-submenu <?= $documentsOpen ? 'show' : '' ?>" id="menuDocumentos">
                        <a class="<?= $isPath('documentos/index.php') ? 'active' : '' ?>" href="<?= e(url('admin/documentos/index.php')) ?>">Todos os Documentos</a>
                        <a class="<?= $isPath('documentos/form.php') && !isset($_GET['id']) ? 'active' : '' ?>" href="<?= e(url('admin/documentos/form.php')) ?>">Adicionar Novo</a>
                        <a class="<?= $isPath('documentos/categorias.php') ? 'active' : '' ?>" href="<?= e(url('admin/documentos/categorias.php')) ?>">Categorias</a>
                    </div>
                <?php endif; ?>

PHP;
        $anchor="<?php if (Auth::can('newsletter.gerenciar')): ?>";
        $pos=strpos($src,$anchor);
        if($pos===false){
            $anchor="<?php if (Auth::can('auditoria.visualizar')): ?>";
            $pos=strpos($src,$anchor);
        }
        if($pos===false) throw new RuntimeException('Não foi possível inserir o menu Documentos em admin/_header.php.');
        $src=substr($src,0,$pos).$block.substr($src,$pos);
    }

    if($src===$original){out('[OK] admin/_header.php já possui o menu Documentos.');return;}
    writeIfChanged($path,$src,'admin/_header.php');
}

function patchSearch(string $path): void
{
    $src=(string)file_get_contents($path);
    $original=$src;

    if(!str_contains($src,"['documento',")){
        $needle="['galeria', \"SELECT titulo,slug,descricao resumo,'' conteudo";
        $pos=strpos($src,$needle);
        $line="        ['documento', \"SELECT titulo,slug,descricao resumo,descricao conteudo,COALESCE(publicado_em,created_at) dt FROM documentos WHERE status='publicado' AND (publicado_em IS NULL OR publicado_em<=NOW()) AND (titulo LIKE :q OR descricao LIKE :q) ORDER BY dt DESC LIMIT 15\"],\n";
        if($pos!==false){
            $src=substr($src,0,$pos).$line.substr($src,$pos);
        } else {
            $needle="    ];";
            $pos=strpos($src,$needle);
            if($pos===false) throw new RuntimeException('Não foi possível integrar Documentos em busca.php.');
            $src=substr($src,0,$pos).$line.substr($src,$pos);
        }
    }

    if(!str_contains($src,"'documento'=>'Documento'")){
        $src=preg_replace_callback(
            '/\$labels=\[([^\]]*)\];/',
            static function(array $m): string {
                $body=$m[1];
                if(str_contains($body,"'documento'")) return $m[0];
                $body=rtrim($body);
                if($body!=='' && !str_ends_with(trim($body),',')) $body.=',';
                return '$labels=['.$body.",'documento'=>'Documento'];";
            },
            $src,1
        ) ?? $src;
    }

    $src=str_replace('Buscar notícias, páginas, eventos...','Buscar notícias, páginas, eventos, documentos...',$src);

    if($src===$original){out('[OK] busca.php já inclui documentos.');return;}
    writeIfChanged($path,$src,'busca.php');
}

function patchSitemap(string $path): void
{
    $src=(string)file_get_contents($path);
    $original=$src;

    if(!str_contains($src,"'documento.sitemaps.xml'")){
        $anchor="    'formulario.sitemaps.xml' => 'formularios.sitemaps.xml',";
        $aliases="    'documento.sitemaps.xml' => 'documentos.sitemaps.xml',\n    'documento.sitemap.xml' => 'documentos.sitemaps.xml',\n    'documentos.sitemap.xml' => 'documentos.sitemaps.xml',\n";
        if(str_contains($src,$anchor)) $src=str_replace($anchor,$aliases.$anchor,$src);
    }

    if(!str_contains($src,"'documentos.sitemaps.xml' => [")){
        $anchor="    'formularios.sitemaps.xml' => [";
        $block="    'documentos.sitemaps.xml' => [\n        'enabled' => (\$settings['seo_sitemap_documentos'] ?? '1') === '1',\n        'lastmod' => sitemapMaxDate(\$pdo, \"SELECT MAX(COALESCE(updated_at,publicado_em,created_at)) FROM documentos WHERE status='publicado' AND (publicado_em IS NULL OR publicado_em<=NOW()) AND seo_noindex=0\"),\n    ],\n";
        if(!str_contains($src,$anchor)) throw new RuntimeException('Não foi possível adicionar o grupo de documentos ao sitemap.php.');
        $src=str_replace($anchor,$block.$anchor,$src);
    }

    if(!str_contains($src,"sitemapEmitUrl(url('documentos')")){
        $anchor="        sitemapEmitUrl(url('galerias'), null, 'weekly', '0.7', [], \$includeImages);";
        if(str_contains($src,$anchor)) $src=str_replace($anchor,$anchor."\n        sitemapEmitUrl(url('documentos'), null, 'weekly', '0.7', [], \$includeImages);",$src);
    }

    if(!str_contains($src,"if (\$requestFile === 'documentos.sitemaps.xml')")){
        $anchor="    if (\$requestFile === 'formularios.sitemaps.xml')";
        $pos=strpos($src,$anchor);
        if($pos===false) throw new RuntimeException('Não foi possível inserir a emissão de documentos no sitemap.php.');
        $block=<<<'PHP'
    if ($requestFile === 'documentos.sitemaps.xml') {
        $sql = "SELECT slug,COALESCE(updated_at,publicado_em,created_at) lm
                FROM documentos
                WHERE status='publicado'
                  AND (publicado_em IS NULL OR publicado_em<=NOW())
                  AND seo_noindex=0
                ORDER BY id DESC";
        foreach ($pdo->query($sql)->fetchAll() as $row) {
            sitemapEmitUrl(contentUrl('documento', (string)$row['slug']), sitemapDate((string)$row['lm']), 'monthly', '0.6', [], false);
        }
    }

PHP;
        $src=substr($src,0,$pos).$block.substr($src,$pos);
    }

    if($src===$original){out('[OK] sitemap.php já inclui documentos.');return;}
    writeIfChanged($path,$src,'sitemap.php');
}

function patchSeoSitemapAdmin(string $path): void
{
    $src=(string)file_get_contents($path);
    $original=$src;

    if(!str_contains($src,"'seo_sitemap_documentos' => '1'")){
        $anchor="    'seo_sitemap_formularios' => '0',";
        if(str_contains($src,$anchor)) $src=str_replace($anchor,"    'seo_sitemap_documentos' => '1',\n".$anchor,$src);
    }

    if(!preg_match("/foreach \(\[[^\]]*seo_sitemap_documentos/s",$src)){
        $src=str_replace(
            "'seo_sitemap_categorias','seo_sitemap_formularios'",
            "'seo_sitemap_categorias','seo_sitemap_documentos','seo_sitemap_formularios'",
            $src
        );
        $src=str_replace(
            "'seo_sitemap_tags','seo_sitemap_categorias','seo_sitemap_formularios'",
            "'seo_sitemap_tags','seo_sitemap_categorias','seo_sitemap_documentos','seo_sitemap_formularios'",
            $src
        );
    }

    if(!str_contains($src,"'Documentos' => \"SELECT COUNT(*) FROM documentos")){
        $anchor="    'Formulários' => \"SELECT COUNT(*) FROM formularios";
        $pos=strpos($src,$anchor);
        if($pos!==false){
            $line="    'Documentos' => \"SELECT COUNT(*) FROM documentos WHERE status='publicado' AND (publicado_em IS NULL OR publicado_em<=NOW()) AND seo_noindex=0\",\n";
            $src=substr($src,0,$pos).$line.substr($src,$pos);
        }
    }

    if(!str_contains($src,"'key'=>'seo_sitemap_documentos'")){
        $anchor="    ['key'=>'seo_sitemap_formularios'";
        $pos=strpos($src,$anchor);
        if($pos!==false){
            $line="    ['key'=>'seo_sitemap_documentos','label'=>'Documentos / Downloads','file'=>'documentos.sitemaps.xml','count'=>\$counts['Documentos'] ?? 0,'description'=>'Documentos publicados e indexáveis.'],\n";
            $src=substr($src,0,$pos).$line.substr($src,$pos);
        }
    }

    $src=str_replace("'count'=>4,'description'=>'Home, Agenda, Comunidades e listagem de Galerias.'","'count'=>5,'description'=>'Home, Agenda, Comunidades, Galerias e Documentos.'",$src);

    if($src===$original){out('[OK] admin/seo/sitemap.php já inclui documentos.');return;}
    writeIfChanged($path,$src,'admin/seo/sitemap.php');
}

function patchHtaccess(string $path): void
{
    $src=(string)file_get_contents($path);
    if(str_contains($src,'PORTAL IECLB v0.35.0 - documentos')){out('[OK] .htaccess já possui regra de sitemap de documentos.');return;}
    $block="# BEGIN PORTAL IECLB v0.35.0 - documentos\nRewriteEngine On\nRewriteRule ^documentos?\\.sitemaps?\\.xml$ sitemap.php [L,QSA]\n# END PORTAL IECLB v0.35.0 - documentos\n\n";
    $src=$block.$src;
    writeIfChanged($path,$src,'.htaccess');
}

function patchCacheService(string $path): void
{
    if(!is_file($path)) return;
    $src=(string)file_get_contents($path);
    if(str_contains($src,"'documento'")){out('[OK] CacheService já reconhece documentos.');return;}
    $original=$src;
    $src=str_replace("'newsletter', 'formulario'","'newsletter', 'formulario', 'documento'",$src);
    if($src===$original){
        out('[AVISO] Não foi possível acrescentar documento aos hints do CacheService; o módulo continuará funcional.');
        return;
    }
    writeIfChanged($path,$src,'app/Services/CacheService.php');
}

function updateVersion(string $config): void
{
    $src=(string)file_get_contents($config);
    $original=$src;
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
    if($src!==$original) writeIfChanged($config,$src,'config/config.php');
    else out('[OK] APP_VERSION já é '.TARGET_VERSION.'.');
}

out('Portal IECLB Parobé - atualização v'.TARGET_VERSION);
out(str_repeat('-',76));

$config=$root.'/config/config.php';
$dbFile=$root.'/mod/db/Database.php';
if(!is_file($config)) fail('config/config.php não encontrado.');
if(!is_file($dbFile)) fail('mod/db/Database.php não encontrado.');

foreach([
    'app/Services/DocumentService.php',
    'admin/documentos/index.php',
    'admin/documentos/form.php',
    'admin/documentos/categorias.php',
    'documentos.php',
    'documento.php',
    'documento-baixar.php',
    'migrations/2026_08_22_v0.35.0.sql',
] as $required){
    if(!is_file($root.'/'.$required)) fail('Arquivo da v0.35.0 não encontrado: '.$required);
}

require_once $config;
require_once $dbFile;

$current=defined('APP_VERSION')?(string)APP_VERSION:'0.0.0';
out('Versão identificada: '.$current);
if(version_compare($current,MIN_VERSION,'<')){
    fail('A v0.35.0 requer Portal v'.MIN_VERSION.' ou superior.');
}

try{
    $pdo=Database::connection();
    out('[OK] Conexão com o banco realizada.');

    ensureSchema($pdo);
    seedData($pdo);

    patchBootstrap($root.'/bootstrap.php');
    patchFunctions($root.'/app/Helpers/functions.php');
    patchRouter($root.'/router.php');
    patchAdminHeader($root.'/admin/_header.php');
    patchSearch($root.'/busca.php');
    patchSitemap($root.'/sitemap.php');
    patchSeoSitemapAdmin($root.'/admin/seo/sitemap.php');
    patchHtaccess($root.'/.htaccess');
    patchCacheService($root.'/app/Services/CacheService.php');
    updateVersion($config);

    if(class_exists('CacheService')){
        try{ CacheService::clearAll(); }catch(Throwable $ignored){}
    }
    if(function_exists('opcache_reset')) @opcache_reset();

    out(str_repeat('-',76));
    out('Atualização v'.TARGET_VERSION.' concluída.');
    out('Acesse Documentos no painel administrativo e atualize o navegador com Ctrl+F5.');
    if(is_dir($backupDir)) out('Backups: '.str_replace('\\','/',$backupDir));
}catch(Throwable $e){
    fail($e->getMessage());
}
