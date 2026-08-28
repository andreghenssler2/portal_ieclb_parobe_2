<?php
require_once __DIR__ . '/../../bootstrap.php';
Auth::requirePermission('aparencia.gerenciar');
$pdo = Database::connection();
$error = '';

$defaults = [
    'aparencia_cor_primaria' => '#0b5d4b',
    'aparencia_cor_secundaria' => '#6c757d',
    'aparencia_cor_fundo' => '#ffffff',
    'aparencia_cor_texto' => '#1f2937',
    'aparencia_cor_rodape' => '#f8f9fa',
    'aparencia_cor_rodape_texto' => '#495057',
    'aparencia_container_max' => '1140',
    'aparencia_cabecalho_sticky' => '0',
    'aparencia_mostrar_nome_com_logo' => '0',
    'aparencia_bordas_arredondadas' => '16',
];
$settings = array_merge($defaults, siteConfigAll($pdo));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($defaults as $key => $default) {
        $settings[$key] = str_starts_with($key, 'aparencia_') && in_array($key, ['aparencia_cabecalho_sticky','aparencia_mostrar_nome_com_logo'], true)
            ? (isset($_POST[$key]) ? '1' : '0')
            : trim((string)($_POST[$key] ?? $default));
    }

    if (!Csrf::validate($_POST['_token'] ?? null)) {
        $error = 'Token de segurança inválido.';
    } else {
        try {
            foreach (['aparencia_cor_primaria','aparencia_cor_secundaria','aparencia_cor_fundo','aparencia_cor_texto','aparencia_cor_rodape','aparencia_cor_rodape_texto'] as $color) {
                if (!preg_match('/^#[0-9a-fA-F]{6}$/', $settings[$color])) {
                    throw new RuntimeException('Informe cores válidas no formato hexadecimal (#RRGGBB).');
                }
            }
            $width = (int)$settings['aparencia_container_max'];
            if ($width < 900 || $width > 1600) throw new RuntimeException('A largura máxima deve ficar entre 900 e 1600 px.');
            $radius = (int)$settings['aparencia_bordas_arredondadas'];
            if ($radius < 0 || $radius > 40) throw new RuntimeException('O arredondamento deve ficar entre 0 e 40 px.');

            $pdo->beginTransaction();
            foreach ($defaults as $key => $default) {
                saveSiteConfig($pdo, $key, (string)$settings[$key], in_array($key, ['aparencia_container_max','aparencia_bordas_arredondadas'], true) ? 'numero' : 'texto');
            }
            $pdo->commit();
            logAction($pdo, 'aparencia.personalizar', 'configuracoes', null, 'Personalização visual atualizada');
            Session::flash('success', 'Aparência atualizada com sucesso.');
            header('Location: ' . url('admin/aparencia/personalizar.php'));
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $error = $e->getMessage();
        }
    }
}

$pageTitle = 'Personalizar';
require __DIR__ . '/../_header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
    <div><h1 class="h3 mb-1">Personalizar</h1><p class="text-secondary mb-0">Cores, dimensões e comportamento visual do tema ativo.</p></div>
    <div class="d-flex gap-2"><a class="btn btn-outline-secondary" href="<?= e(url('admin/aparencia/temas.php')) ?>">Temas</a><a class="btn btn-outline-primary" href="<?= e(url()) ?>" target="_blank">Visualizar portal</a></div>
</div>
<?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
<form method="post">
<?= Csrf::field() ?>
<div class="row g-4">
    <div class="col-xl-8">
        <div class="card border-0 shadow-sm mb-4"><div class="card-header bg-white fw-semibold">Paleta de cores</div><div class="card-body p-4"><div class="row g-3">
            <?php $colors=[
                ['aparencia_cor_primaria','Cor principal'],['aparencia_cor_secundaria','Cor secundária'],['aparencia_cor_fundo','Fundo do portal'],['aparencia_cor_texto','Texto principal'],['aparencia_cor_rodape','Fundo do rodapé'],['aparencia_cor_rodape_texto','Texto do rodapé']
            ]; foreach($colors as [$key,$label]): ?>
                <div class="col-sm-6"><label class="form-label"><?= e($label) ?></label><div class="input-group"><input class="form-control form-control-color" type="color" name="<?= e($key) ?>" value="<?= e($settings[$key]) ?>"><input class="form-control color-text-sync" value="<?= e($settings[$key]) ?>" data-for="<?= e($key) ?>" maxlength="7"></div></div>
            <?php endforeach; ?>
        </div></div></div>
        <div class="card border-0 shadow-sm"><div class="card-header bg-white fw-semibold">Layout</div><div class="card-body p-4"><div class="row g-3">
            <div class="col-md-6"><label class="form-label">Largura máxima do conteúdo (px)</label><input class="form-control" type="number" min="900" max="1600" step="10" name="aparencia_container_max" value="<?= e($settings['aparencia_container_max']) ?>"></div>
            <div class="col-md-6"><label class="form-label">Arredondamento dos cards (px)</label><input class="form-control" type="number" min="0" max="40" name="aparencia_bordas_arredondadas" value="<?= e($settings['aparencia_bordas_arredondadas']) ?>"></div>
            <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="sticky" name="aparencia_cabecalho_sticky" value="1" <?= $settings['aparencia_cabecalho_sticky']==='1'?'checked':'' ?>><label class="form-check-label" for="sticky">Manter cabeçalho fixo ao rolar a página</label></div></div>
            <div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="brand" name="aparencia_mostrar_nome_com_logo" value="1" <?= $settings['aparencia_mostrar_nome_com_logo']==='1'?'checked':'' ?>><label class="form-check-label" for="brand">Mostrar o nome “IECLB Parobé” ao lado do logo</label></div></div>
        </div></div></div>
    </div>
    <div class="col-xl-4"><div class="card border-0 shadow-sm sticky-xl-top" style="top:5.5rem"><div class="card-header bg-white fw-semibold">Prévia de cores</div><div class="card-body p-4"><div id="appearancePreview" class="appearance-preview rounded-4 overflow-hidden border"><div class="preview-head p-3 fw-semibold">IECLB Parobé</div><div class="preview-body p-4"><div class="preview-title mb-2"></div><div class="preview-line mb-2"></div><div class="preview-line short mb-3"></div><button type="button" class="preview-button border-0 px-3 py-2 rounded">Saiba mais</button></div><div class="preview-foot p-3 small">Rodapé do portal</div></div></div></div></div>
</div>
<div class="mt-4"><button class="btn btn-primary px-4">Salvar personalização</button></div>
</form>
<script>
function refreshAppearancePreview(){
 const get=n=>document.querySelector('[name="'+n+'"]')?.value||'';
 const p=document.getElementById('appearancePreview'); if(!p)return;
 p.style.setProperty('--p',get('aparencia_cor_primaria')); p.style.setProperty('--s',get('aparencia_cor_secundaria')); p.style.setProperty('--b',get('aparencia_cor_fundo')); p.style.setProperty('--t',get('aparencia_cor_texto')); p.style.setProperty('--f',get('aparencia_cor_rodape')); p.style.setProperty('--ft',get('aparencia_cor_rodape_texto'));
}
document.querySelectorAll('input[type=color]').forEach(el=>{el.addEventListener('input',()=>{const text=document.querySelector('.color-text-sync[data-for="'+el.name+'"]'); if(text)text.value=el.value; refreshAppearancePreview();});});
document.querySelectorAll('.color-text-sync').forEach(el=>{el.addEventListener('input',()=>{if(/^#[0-9a-fA-F]{6}$/.test(el.value)){const color=document.querySelector('[name="'+el.dataset.for+'"]'); if(color)color.value=el.value; refreshAppearancePreview();}});});
refreshAppearancePreview();
</script>
<?php require __DIR__ . '/../_footer.php'; ?>
