<?php

declare(strict_types=1);

const TARGET_VERSION = '0.42.2';
const MIN_VERSION = '0.42.1';

function out(string $message = ''): void { echo $message . PHP_EOL; }
function fail(string $message): never { out('[ERRO] ' . $message); exit(1); }

$root = __DIR__;
$backupDir = $root . '/storage/update-backups/v' . TARGET_VERSION . '-' . date('Ymd-His');

function lintPhp(string $path): void
{
    $cmd = escapeshellarg(PHP_BINARY ?: 'php') . ' -l ' . escapeshellarg($path) . ' 2>&1';
    $lines = [];
    $code = 1;
    exec($cmd, $lines, $code);
    if ($code !== 0) {
        throw new RuntimeException(basename($path) . " não passou no php -l:\n" . implode(PHP_EOL, $lines));
    }
}

function backupFile(string $path): void
{
    global $root, $backupDir;
    if (!is_file($path)) return;
    $relative = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
    $target = $backupDir . '/' . $relative;
    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true) && !is_dir(dirname($target))) {
        throw new RuntimeException('Não foi possível criar backup de ' . $relative . '.');
    }
    if (!copy($path, $target)) {
        throw new RuntimeException('Não foi possível criar backup de ' . $relative . '.');
    }
}

function writeChanged(string $path, string $source, string $label): void
{
    $current = is_file($path) ? (string)file_get_contents($path) : '';
    if ($current === $source) {
        out('[OK] ' . $label . ' já estava atualizado.');
        return;
    }
    backupFile($path);
    if (file_put_contents($path, $source, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível atualizar ' . $label . '.');
    }
    if (str_ends_with(strtolower($path), '.php')) lintPhp($path);
    out('[OK] ' . $label . ' atualizado.');
}

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.tables
         WHERE table_schema=DATABASE() AND table_name=?'
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function normalizeTitle(string $value): string
{
    $value = trim($value);
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if (is_string($converted) && $converted !== '') $value = $converted;
    }
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}

function patchIndex(string $path): void
{
    $source = (string)file_get_contents($path);
    $original = $source;

    if (!str_contains($source, '$homeModularActive = !empty($homeModularSections);')) {
        $old = <<<'PHP'
$widgets = homeWidgets($pdo);
require themeFile($pdo, 'header.php');
PHP;
        $new = <<<'PHP'
$widgets = homeWidgets($pdo);

// v0.42.2: a Home modular substitui os blocos antigos quando há seções ativas.
$homeModularService = new HomeService($pdo);
$homeModularSections = $homeModularService->sections(true);
$homeModularActive = !empty($homeModularSections);

require themeFile($pdo, 'header.php');
PHP;
        if (!str_contains($source, $old)) {
            throw new RuntimeException('Não foi possível preparar o modo modular em index.php.');
        }
        $source = str_replace($old, $new, $source);
    }

    if (!str_contains($source, '// v0.42.2 - evita duplicação da Home antiga')) {
        $old = <<<'PHP'
    $type = (string) $widget['tipo'];
    $widgetTitle = trim((string) ($widget['titulo'] ?? ''));
    ?>
PHP;
        $new = <<<'PHP'
    $type = (string) $widget['tipo'];
    $widgetTitle = trim((string) ($widget['titulo'] ?? ''));

    // v0.42.2 - evita duplicação da Home antiga.
    // Em modo modular, só banners antigos permanecem acima da Home.
    // A Agenda é renderizada separadamente no final.
    if ($homeModularActive && $type !== 'banners') {
        continue;
    }
    ?>
PHP;
        if (!str_contains($source, $old)) {
            throw new RuntimeException('Não foi possível filtrar os widgets antigos em index.php.');
        }
        $source = str_replace($old, $new, $source);
    }

    if (!str_contains($source, "home-agenda-bottom.php")) {
        $old = <<<'PHP'
<?php /* HOME_MODULAR_V028_APPEND */ require __DIR__ . '/public/home-modular.php'; ?>
<?php require themeFile($pdo, 'footer.php'); ?>
PHP;
        $new = <<<'PHP'
<?php /* HOME_MODULAR_V028_APPEND */ require __DIR__ . '/public/home-modular.php'; ?>

<?php
// v0.42.2: Agenda sempre depois da Home modular.
if ($homeModularActive) {
    require __DIR__ . '/public/home-agenda-bottom.php';
}
?>

<?php require themeFile($pdo, 'footer.php'); ?>
PHP;
        if (!str_contains($source, $old)) {
            throw new RuntimeException('Não foi possível posicionar a Agenda no final de index.php.');
        }
        $source = str_replace($old, $new, $source);
    }

    if ($source !== $original) writeChanged($path, $source, 'index.php');
    else out('[OK] index.php já estava consolidado.');
}

function patchHomeService(string $path): void
{
    $source = (string)file_get_contents($path);
    $original = $source;

    if (!str_contains($source, "\$width = (string)(\$data['width'] ?? 'full');")) {
        $old = <<<'PHP'
        $background = (string)($data['background'] ?? 'white');
        if (!in_array($background, ['white', 'soft'], true)) {
            $background = 'white';
        }

        $config = [
PHP;
        $new = <<<'PHP'
        $background = (string)($data['background'] ?? 'white');
        if (!in_array($background, ['white', 'soft'], true)) {
            $background = 'white';
        }

        $width = (string)($data['width'] ?? 'full');
        if (!in_array($width, ['full', 'half'], true)) {
            $width = 'full';
        }

        $config = [
PHP;
        if (!str_contains($source, $old)) {
            throw new RuntimeException('Não foi possível adicionar largura ao HomeService.');
        }
        $source = str_replace($old, $new, $source);
    }

    if (!str_contains($source, "'width' => \$width,")) {
        $old = <<<'PHP'
            'background' => $background,
        ];
PHP;
        $new = <<<'PHP'
            'background' => $background,
            'width' => $width,
        ];
PHP;
        if (!str_contains($source, $old)) {
            throw new RuntimeException('Não foi possível salvar largura no HomeService.');
        }
        $source = str_replace($old, $new, $source);
    }

    if ($source !== $original) writeChanged($path, $source, 'app/Services/HomeService.php');
    else out('[OK] HomeService já suporta meia largura.');
}

function patchHomeAdmin(string $path): void
{
    $source = (string)file_get_contents($path);
    $original = $source;

    if (!str_contains($source, "'width' => (string)(\$editConfig['width'] ?? 'full'),")) {
        $old = <<<'PHP'
    'background' => (string)($editConfig['background'] ?? 'white'),
];
PHP;
        $new = <<<'PHP'
    'background' => (string)($editConfig['background'] ?? 'white'),
    'width' => (string)($editConfig['width'] ?? 'full'),
];
PHP;
        if (!str_contains($source, $old)) {
            throw new RuntimeException('Não foi possível preparar largura no formulário da Home.');
        }
        $source = str_replace($old, $new, $source);
    }

    if (!str_contains($source, 'name="width"')) {
        $anchor = <<<'PHP'
                    <div class="border rounded p-3 mb-3 bg-body-tertiary">
PHP;
        $field = <<<'PHP'
                    <div class="mb-3">
                        <label class="form-label">Largura da seção</label>
                        <select class="form-select" name="width">
                            <option value="full" <?= $form['width']==='full'?'selected':'' ?>>Largura inteira</option>
                            <option value="half" <?= $form['width']==='half'?'selected':'' ?>>Meia largura</option>
                        </select>
                        <div class="form-text">
                            Use meia largura em duas seções consecutivas, como Informativo e IECLB, para deixá-las lado a lado.
                        </div>
                    </div>

PHP;
        if (!str_contains($source, $anchor)) {
            throw new RuntimeException('Não foi possível inserir o seletor de largura na Home.');
        }
        $source = str_replace($anchor, $field . $anchor, $source);
    }

    if ($source !== $original) writeChanged($path, $source, 'admin/aparencia/home.php');
    else out('[OK] Administração da Home já suporta meia largura.');
}

function patchHomeModular(string $path): void
{
    $source = (string)file_get_contents($path);
    $original = $source;

    if (!str_contains($source, "\$width = (string)(\$config['width'] ?? 'full');")) {
        $old = <<<'PHP'
    ?>
    <section class="home-block home-block--<?= e($type) ?> home-block--bg-<?= e($background) ?>" id="<?= e($sectionId) ?>" data-home-title="<?= e($titleKey) ?>" data-home-autoplay="<?= !empty($config['autoplay']) ? '1' : '0' ?>">
PHP;
        $new = <<<'PHP'
    $width = (string)($config['width'] ?? 'full');
    if (!in_array($width, ['full','half'], true)) {
        $width = 'full';
    }
    ?>
    <section class="home-block home-block--<?= e($type) ?> home-block--bg-<?= e($background) ?> home-block--width-<?= e($width) ?>" id="<?= e($sectionId) ?>" data-home-title="<?= e($titleKey) ?>" data-home-width="<?= e($width) ?>" data-home-autoplay="<?= !empty($config['autoplay']) ? '1' : '0' ?>">
PHP;
        if (!str_contains($source, $old)) {
            throw new RuntimeException('Não foi possível aplicar largura em public/home-modular.php.');
        }
        $source = str_replace($old, $new, $source);
    }

    if ($source !== $original) writeChanged($path, $source, 'public/home-modular.php');
    else out('[OK] Home modular já suporta meia largura.');
}

function patchHomeCss(string $path): void
{
    $source = (string)file_get_contents($path);

    if (str_contains($source, '/* v0.42.2 - Home consolidada e colunas editoriais */')) {
        out('[OK] CSS da Home já está na v0.42.2.');
        return;
    }

    $extra = <<<'CSS'

/* v0.42.2 - Home consolidada e colunas editoriais */
.portal-home{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  column-gap:20px;
}
.portal-home > .home-block{grid-column:1/-1}
.portal-home > .home-block--width-half{grid-column:span 1}

.home-block--width-half.home-block--bg-soft{
  padding:24px;
  border-radius:4px;
  background:#f5f7f8;
}
.home-block--width-half.home-block--bg-soft::before{display:none}

/* Informativo/IECLB: primeira matéria maior e chamadas compactas abaixo. */
.home-block--width-half.home-block--grid .home-grid{
  display:flex;
  flex-direction:column;
  gap:14px;
}
.home-block--width-half.home-block--grid .home-card{
  display:grid;
  grid-template-columns:145px minmax(0,1fr);
  gap:14px;
  min-height:96px;
  padding:8px;
  border-radius:4px;
  background:#fff;
  box-shadow:0 2px 8px rgba(0,0,0,.16);
}
.home-block--width-half.home-block--grid .home-card:first-child{
  display:block;
  padding:0 0 14px;
  overflow:hidden;
}
.home-block--width-half.home-block--grid .home-card:first-child .home-card__image{
  aspect-ratio:16/8.6;
  border-radius:4px 4px 0 0;
}
.home-block--width-half.home-block--grid .home-card:first-child .home-card__body{
  padding:14px 14px 0;
}
.home-block--width-half.home-block--grid .home-card:not(:first-child) .home-card__image{
  width:145px;
  height:82px;
  aspect-ratio:auto;
  border-radius:4px;
}
.home-block--width-half.home-block--grid .home-card:not(:first-child) .home-card__body{
  padding:2px 4px 2px 0;
}
.home-block--width-half.home-block--grid .home-card h3{
  font-family:Arial,Helvetica,sans-serif;
  font-weight:500;
  line-height:1.22;
}
.home-block--width-half.home-block--grid .home-card:not(:first-child) h3{font-size:17px}
.home-block--width-half.home-block--grid .home-card p{display:none}

/* Agenda no final */
.portal-home--agenda-bottom{display:block;padding-top:0}
.home-agenda-bottom{margin-bottom:0}
.home-agenda-grid{
  display:grid;
  grid-template-columns:repeat(3,minmax(0,1fr));
  gap:16px;
}
.home-agenda-card{
  display:flex;
  align-items:flex-start;
  gap:14px;
  padding:18px;
  border:1px solid var(--home-line);
  border-radius:5px;
  background:#fff;
  color:var(--home-text);
  text-decoration:none;
  transition:box-shadow .2s ease,transform .2s ease;
}
.home-agenda-card:hover{
  transform:translateY(-1px);
  box-shadow:0 5px 18px rgba(0,0,0,.08);
}
.home-agenda-date{
  display:flex;
  flex:0 0 58px;
  min-height:62px;
  flex-direction:column;
  align-items:center;
  justify-content:center;
  padding-right:14px;
  border-right:1px solid var(--home-line);
}
.home-agenda-date strong{font:700 28px/1 Arial,Helvetica,sans-serif}
.home-agenda-date small{
  margin-top:5px;
  color:var(--home-muted);
  font:600 11px/1 Arial,Helvetica,sans-serif;
  text-transform:uppercase;
}
.home-agenda-content{
  display:flex;
  min-width:0;
  flex-direction:column;
  gap:5px;
}
.home-agenda-content > small,
.home-agenda-content > span{
  color:var(--home-muted);
  font:400 12px/1.3 Arial,Helvetica,sans-serif;
}
.home-agenda-content > strong{
  color:#242424;
  font:600 16px/1.3 Arial,Helvetica,sans-serif;
}
.home-agenda-empty{
  padding:18px;
  border:1px solid var(--home-line);
  border-radius:4px;
  color:var(--home-muted);
  background:#fff;
}

@media (max-width:900px){
  .portal-home{grid-template-columns:1fr}
  .portal-home > .home-block--width-half{grid-column:1}
  .home-agenda-grid{grid-template-columns:1fr 1fr}
}
@media (max-width:560px){
  .home-block--width-half.home-block--grid .home-card{
    grid-template-columns:110px minmax(0,1fr);
  }
  .home-block--width-half.home-block--grid .home-card:not(:first-child) .home-card__image{
    width:110px;
    height:76px;
  }
  .home-agenda-grid{grid-template-columns:1fr}
}
CSS;

    writeChanged($path, rtrim($source) . "\n" . $extra . "\n", 'public/css/home-modular.css');
}

function configureExistingHalfSections(PDO $pdo): void
{
    if (!tableExists($pdo, 'home_secoes')) return;

    $rows = $pdo->query(
        'SELECT id,titulo,configuracao_json FROM home_secoes'
    )->fetchAll() ?: [];

    $stmt = $pdo->prepare(
        'UPDATE home_secoes
         SET configuracao_json=?,updated_at=NOW()
         WHERE id=?'
    );

    $changed = 0;

    foreach ($rows as $row) {
        $key = normalizeTitle((string)$row['titulo']);
        if (!in_array($key, ['informativo', 'ieclb'], true)) continue;

        $config = json_decode((string)($row['configuracao_json'] ?? ''), true);
        if (!is_array($config)) $config = [];

        if (isset($config['width'])) continue;

        $config['width'] = 'half';

        $stmt->execute([
            json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            (int)$row['id'],
        ]);

        $changed++;
    }

    if ($changed > 0) {
        out('[OK] Informativo/IECLB definidos como meia largura: ' . $changed . ' seção(ões).');
    }
}

function updateVersion(string $path): void
{
    $source = (string)file_get_contents($path);
    $original = $source;

    $pattern = "/define\\(\\s*['\"]APP_VERSION['\"]\\s*,\\s*['\"][^'\"]*['\"]\\s*\\)\\s*;/";

    if (preg_match($pattern, $source)) {
        $source = preg_replace(
            $pattern,
            "define('APP_VERSION', '" . TARGET_VERSION . "');",
            $source,
            1
        ) ?? $source;
    } else {
        $anchor = 'declare(strict_types=1);';
        if (!str_contains($source, $anchor)) {
            throw new RuntimeException('Não foi possível localizar declare(strict_types=1) em config.php.');
        }
        $source = str_replace(
            $anchor,
            $anchor . "\n\ndefine('APP_VERSION', '" . TARGET_VERSION . "');",
            $source
        );
    }

    if ($source !== $original) writeChanged($path, $source, 'config/config.php');
    else out('[OK] APP_VERSION já é ' . TARGET_VERSION . '.');
}

out('Portal IECLB Parobé - atualização v' . TARGET_VERSION);
out('Home consolidada + Agenda no final');
out(str_repeat('-', 76));

$config = $root . '/config/config.php';
$dbFile = $root . '/mod/db/Database.php';

if (!is_file($config)) fail('config/config.php não encontrado.');
if (!is_file($dbFile)) fail('mod/db/Database.php não encontrado.');
if (!is_file($root . '/public/home-agenda-bottom.php')) {
    fail('public/home-agenda-bottom.php não encontrado.');
}

require_once $config;
require_once $dbFile;

$current = defined('APP_VERSION') ? (string)APP_VERSION : '0.0.0';
out('Versão identificada: ' . $current);

if (version_compare($current, MIN_VERSION, '<')) {
    fail('A v' . TARGET_VERSION . ' requer Portal v' . MIN_VERSION . ' ou superior.');
}

try {
    $pdo = Database::connection();
    out('[OK] Conexão com o banco realizada.');

    lintPhp($root . '/public/home-agenda-bottom.php');

    patchIndex($root . '/index.php');
    patchHomeService($root . '/app/Services/HomeService.php');
    patchHomeAdmin($root . '/admin/aparencia/home.php');
    patchHomeModular($root . '/public/home-modular.php');
    patchHomeCss($root . '/public/css/home-modular.css');

    configureExistingHalfSections($pdo);
    updateVersion($config);

    if (class_exists('CacheService')) {
        try {
            CacheService::clearGroup('page');
            CacheService::clearGroup('public');
        } catch (Throwable $ignored) {}
    }

    if (function_exists('opcache_reset')) @opcache_reset();

    out(str_repeat('-', 76));
    out('[OK] Atualização v' . TARGET_VERSION . ' concluída.');
    out('[OK] Home antiga deixa de aparecer quando há seções modulares ativas.');
    out('[OK] Agenda agora fica no final da página inicial.');
    out('[OK] Meia largura disponível em Aparência > Página Inicial.');
    if (is_dir($backupDir)) {
        out('Backups: ' . str_replace('\\', '/', $backupDir));
    }
} catch (Throwable $e) {
    fail($e->getMessage());
}
