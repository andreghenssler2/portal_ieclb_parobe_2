<?php
require_once __DIR__.'/../../bootstrap.php';
Auth::requirePermission('configuracoes.gerenciar');
$pdo=Database::connection();
$error='';

$defaults=[
    'privacy_page_id'=>'',
    'privacy_footer_link'=>'1',
    'privacy_allow_search_engines'=>'1',
    'analytics_enabled'=>'0',
    'analytics_measurement_id'=>'',
];
$s=array_merge($defaults,siteConfigAll($pdo));

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!Csrf::validate($_POST['_token']??null)){
        $error='Token de segurança inválido.';
    } else {
        try{
            $s['privacy_page_id']=trim((string)($_POST['privacy_page_id']??''));
            $s['privacy_footer_link']=isset($_POST['privacy_footer_link'])?'1':'0';
            $s['privacy_allow_search_engines']=isset($_POST['privacy_allow_search_engines'])?'1':'0';
            $s['analytics_enabled']=isset($_POST['analytics_enabled'])?'1':'0';
            $s['analytics_measurement_id']=strtoupper(trim((string)($_POST['analytics_measurement_id']??'')));

            if($s['privacy_page_id']!==''){
                $st=$pdo->prepare("SELECT 1 FROM paginas WHERE id=:id AND status='publicado'");
                $st->execute(['id'=>(int)$s['privacy_page_id']]);
                if(!$st->fetchColumn()) throw new RuntimeException('Selecione uma página publicada válida.');
            }

            if($s['analytics_measurement_id']!=='' && !preg_match('/^G-[A-Z0-9]+$/',$s['analytics_measurement_id'])){
                throw new RuntimeException('Informe um ID de medição válido do Google Analytics 4, no formato G-XXXXXXXXXX.');
            }
            if($s['analytics_enabled']==='1' && $s['analytics_measurement_id']===''){
                throw new RuntimeException('Informe o ID de medição do Google Analytics 4 antes de ativar a integração.');
            }

            $types=[
                'privacy_page_id'=>'numero',
                'privacy_footer_link'=>'booleano',
                'privacy_allow_search_engines'=>'booleano',
                'analytics_enabled'=>'booleano',
                'analytics_measurement_id'=>'texto',
            ];
            foreach($defaults as $k=>$_) saveSiteConfig($pdo,$k,$s[$k],$types[$k]??'texto');

            logAction($pdo,'configuracoes.privacidade','configuracoes');
            Session::flash('success','Configurações de privacidade e Google Analytics atualizadas.');
            header('Location: '.url('admin/configuracoes/privacidade.php'));
            exit;
        }catch(Throwable $e){
            $error=$e->getMessage();
        }
    }
}

$pages=$pdo->query("SELECT id,titulo,slug FROM paginas WHERE status='publicado' AND (publicado_em IS NULL OR publicado_em<=NOW()) ORDER BY titulo")->fetchAll();
$pageTitle='Privacidade';
require __DIR__.'/../_header.php';
?>
<h1 class="h3 mb-1">Privacidade</h1>
<p class="text-secondary mb-4">Política de privacidade, visibilidade para mecanismos de busca e medição de audiência.</p>
<?php if($error):?><div class="alert alert-danger"><?=e($error)?></div><?php endif;?>
<form method="post">
<?=Csrf::field()?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold">Política de Privacidade</div>
    <div class="card-body p-4">
        <div class="mb-4">
            <label class="form-label">Página da Política de Privacidade</label>
            <select class="form-select" name="privacy_page_id">
                <option value="">Nenhuma página definida</option>
                <?php foreach($pages as $p):?>
                    <option value="<?=(int)$p['id']?>" <?=(string)$s['privacy_page_id']===(string)$p['id']?'selected':''?>><?=e($p['titulo'])?></option>
                <?php endforeach;?>
            </select>
            <div class="form-text">Crie a política em Páginas e publique-a antes de selecioná-la aqui.</div>
        </div>

        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" id="pfl" name="privacy_footer_link" <?=$s['privacy_footer_link']==='1'?'checked':''?>>
            <label class="form-check-label" for="pfl">Exibir link para a Política de Privacidade no rodapé</label>
        </div>

        <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" id="idx" name="privacy_allow_search_engines" <?=$s['privacy_allow_search_engines']==='1'?'checked':''?>>
            <label class="form-check-label" for="idx">Permitir que mecanismos de busca indexem o portal</label>
        </div>

        <div class="alert alert-light border mb-0">Desmarcar a indexação aplica <code>noindex</code> global e bloqueia o rastreamento no <code>robots.txt</code>. A opção de SEO Geral continua sendo respeitada em conjunto.</div>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-bar-chart-line"></i>
        Google Analytics 4 (GA4)
    </div>
    <div class="card-body p-4">
        <div class="row g-3 align-items-end">
            <div class="col-lg-8">
                <label class="form-label" for="analyticsMeasurementId">ID de medição</label>
                <input
                    class="form-control"
                    id="analyticsMeasurementId"
                    name="analytics_measurement_id"
                    value="<?=e((string)$s['analytics_measurement_id'])?>"
                    placeholder="G-XXXXXXXXXX"
                    maxlength="32"
                    autocomplete="off"
                >
                <div class="form-text">No Google Analytics, abra Administrador → Fluxos de dados → Web e copie o ID de medição iniciado por <code>G-</code>.</div>
            </div>
            <div class="col-lg-4">
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" id="analyticsEnabled" name="analytics_enabled" <?=$s['analytics_enabled']==='1'?'checked':''?>>
                    <label class="form-check-label fw-semibold" for="analyticsEnabled">Ativar Google Analytics</label>
                </div>
            </div>
        </div>

        <div class="alert alert-warning mt-4 mb-0">
            O Google Analytics pode utilizar identificadores e tecnologias de medição. Mantenha a Política de Privacidade do portal atualizada conforme as regras aplicáveis antes de ativar a coleta.
        </div>
    </div>
</div>

        <!-- PORTAL_COOKIE_PRIVACY_V91 -->
        <div class="alert alert-info mt-3 mb-4">
            Na v0.91.0, quando a Central de Consentimento estiver ativa, o Google Analytics só é carregado após autorização da categoria <strong>Estatísticas</strong>.
            <a href="<?= e(url('admin/configuracoes/cookies.php')) ?>" class="alert-link">Configurar Cookies e Consentimento</a>.
        </div>
<button class="btn btn-primary">Salvar privacidade e Analytics</button>
</form>
<?php require __DIR__.'/../_footer.php';?>