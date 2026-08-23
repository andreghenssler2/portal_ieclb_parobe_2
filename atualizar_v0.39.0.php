<?php

declare(strict_types=1);

const TARGET_VERSION = '0.39.0';
const MINIMUM_VERSION = '0.38.3';

function out(string $message = ''): void
{
    echo $message . PHP_EOL;
}

function fail(string $message): never
{
    out('[ERRO] ' . $message);
    exit(1);
}

$root = __DIR__;
$backupDir = $root . '/storage/update-backups/v' . TARGET_VERSION . '-' . date('Ymd-His');

function backupFile(string $path): void
{
    global $root, $backupDir;

    if (!is_file($path)) {
        return;
    }

    $relative = ltrim(str_replace('\\', '/', substr($path, strlen($root))), '/');
    $target = $backupDir . '/' . $relative;

    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0755, true) && !is_dir(dirname($target))) {
        throw new RuntimeException('Não foi possível criar a pasta de backup para ' . $relative . '.');
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

    if (is_file($path)) {
        backupFile($path);
    }

    if (file_put_contents($path, $source, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível atualizar ' . $label . '.');
    }

    out('[OK] ' . $label . ' atualizado.');
}

function lintPhp(string $path): void
{
    $command = escapeshellarg(PHP_BINARY ?: 'php') . ' -l ' . escapeshellarg($path) . ' 2>&1';
    $lines = [];
    $code = 1;
    exec($command, $lines, $code);

    if ($code !== 0) {
        throw new RuntimeException(
            $path . " não passou no php -l:\n" . implode(PHP_EOL, $lines)
        );
    }
}

function installAgenda(string $target, string $payload): void
{
    if (!is_file($payload)) {
        throw new RuntimeException('Payload agenda.php da v0.39.0 não encontrado.');
    }

    $source = (string)file_get_contents($payload);
    backupFile($target);

    if (file_put_contents($target, $source, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível instalar o novo agenda.php.');
    }

    lintPhp($target);
    out('[OK] agenda.php atualizado para calendário mensal.');
}

function patchBootstrap(string $path): void
{
    $source = (string)file_get_contents($path);

    if (str_contains($source, 'EventCalendarService.php')) {
        out('[OK] bootstrap.php já carrega EventCalendarService.');
        return;
    }

    $anchors = [
        "require_once __DIR__ . '/app/Services/SearchService.php';",
        "require_once __DIR__ . '/app/Services/SchedulerService.php';",
        "require_once __DIR__ . '/app/Services/HomeService.php';",
    ];

    foreach ($anchors as $anchor) {
        $position = strpos($source, $anchor);
        if ($position === false) {
            continue;
        }

        $replacement = $anchor . "\nrequire_once __DIR__ . '/app/Services/EventCalendarService.php';";
        $source = substr_replace($source, $replacement, $position, strlen($anchor));
        writeChanged($path, $source, 'bootstrap.php');
        lintPhp($path);
        return;
    }

    throw new RuntimeException('Não foi possível integrar EventCalendarService no bootstrap.php.');
}

function patchRouter(string $path): void
{
    $source = (string)file_get_contents($path);
    $original = $source;

    if (!str_contains($source, "'agenda.ics' => 'agenda-ics.php'")) {
        $anchor = "'agenda' => 'agenda.php',";
        if (!str_contains($source, $anchor)) {
            throw new RuntimeException('Não foi possível localizar a rota estática da Agenda.');
        }

        $source = str_replace(
            $anchor,
            $anchor . "\n        'agenda.ics' => 'agenda-ics.php',",
            $source
        );
    }

    if (!str_contains($source, "calendario.ics") || !str_contains($source, "evento-ics.php")) {
        $anchor = <<<'PHP'
// v0.35.0 - download de documento por caminho amigável.
PHP;

        $block = <<<'PHP'
// v0.39.0 - exportação iCalendar de evento individual.
if (count($segments) === 3) {
    $eventPrefix = permalinkPrefix('evento', $pdo);
    $first = strtolower(rawurldecode((string)$segments[0]));
    $last = strtolower(rawurldecode((string)$segments[2]));

    if (($first === $eventPrefix || $first === 'evento') && $last === 'calendario.ics') {
        require __DIR__ . '/evento-ics.php';
        exit;
    }
}

PHP;

        if (!str_contains($source, $anchor)) {
            throw new RuntimeException('Não foi possível localizar o ponto das rotas de 3 segmentos.');
        }

        $source = str_replace($anchor, $block . $anchor, $source);
    }

    if ($source === $original) {
        out('[OK] router.php já possui as rotas iCalendar.');
        return;
    }

    writeChanged($path, $source, 'router.php');
    lintPhp($path);
}

function patchEvento(string $path): void
{
    $source = (string)file_get_contents($path);

    if (str_contains($source, 'Adicionar ao calendário')) {
        out('[OK] evento.php já possui botão Adicionar ao calendário.');
        return;
    }

    $old = <<<'PHP'
    <div class="mt-4"><a class="btn btn-outline-primary" href="<?= e(url('agenda.php')) ?>">Voltar para a agenda</a></div>
PHP;

    $new = <<<'PHP'
    <div class="mt-4 d-flex flex-wrap gap-2">
        <a class="btn btn-outline-primary" href="<?= e(url('agenda')) ?>">Voltar para a agenda</a>
        <a class="btn btn-outline-success" href="<?= e(EventCalendarService::eventIcsUrl($evento)) ?>">
            <i class="bi bi-calendar-plus me-1"></i>Adicionar ao calendário
        </a>
    </div>
PHP;

    if (!str_contains($source, $old)) {
        throw new RuntimeException('Não foi possível localizar o botão de retorno em evento.php.');
    }

    $source = str_replace($old, $new, $source);
    writeChanged($path, $source, 'evento.php');
    lintPhp($path);
}

function updateVersion(string $config): void
{
    $source = (string)file_get_contents($config);
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
        $declare = 'declare(strict_types=1);';
        $position = strpos($source, $declare);

        if ($position !== false) {
            $insertAt = $position + strlen($declare);
            $source = substr($source, 0, $insertAt)
                . "\n\ndefine('APP_VERSION', '" . TARGET_VERSION . "');"
                . substr($source, $insertAt);
        } else {
            $php = strpos($source, '<?php');
            if ($php === false) {
                throw new RuntimeException('config/config.php inválido.');
            }

            $insertAt = $php + 5;
            $source = substr($source, 0, $insertAt)
                . "\n\ndefine('APP_VERSION', '" . TARGET_VERSION . "');"
                . substr($source, $insertAt);
        }
    }

    if ($source !== $original) {
        writeChanged($config, $source, 'config/config.php');
    } else {
        out('[OK] APP_VERSION já é ' . TARGET_VERSION . '.');
    }
}

out('Portal IECLB Parobé - atualização v' . TARGET_VERSION);
out('Agenda / Calendário + iCalendar');
out(str_repeat('-', 76));

$config = $root . '/config/config.php';

if (!is_file($config)) {
    fail('config/config.php não encontrado.');
}

foreach ([
    'app/Services/EventCalendarService.php',
    'agenda-ics.php',
    'evento-ics.php',
    '_update_payload/v0.39.0/agenda.php',
] as $required) {
    if (!is_file($root . '/' . $required)) {
        fail('Arquivo da v0.39.0 não encontrado: ' . $required);
    }
}

require_once $config;

$current = defined('APP_VERSION') ? (string)APP_VERSION : '0.0.0';
out('Versão identificada: ' . $current);

if (version_compare($current, MINIMUM_VERSION, '<')) {
    fail('A v' . TARGET_VERSION . ' requer Portal v' . MINIMUM_VERSION . ' ou superior.');
}

try {
    lintPhp($root . '/app/Services/EventCalendarService.php');
    lintPhp($root . '/agenda-ics.php');
    lintPhp($root . '/evento-ics.php');
    out('[OK] Novos arquivos PHP validados.');

    patchBootstrap($root . '/bootstrap.php');
    patchRouter($root . '/router.php');
    patchEvento($root . '/evento.php');

    installAgenda(
        $root . '/agenda.php',
        $root . '/_update_payload/v0.39.0/agenda.php'
    );

    updateVersion($config);

    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }

    out(str_repeat('-', 76));
    out('Atualização v' . TARGET_VERSION . ' concluída.');
    out('Abra /agenda para visualizar o calendário mensal.');
    out('Exportação geral: /agenda.ics');
    if (is_dir($backupDir)) {
        out('Backups: ' . str_replace('\\', '/', $backupDir));
    }
} catch (Throwable $e) {
    fail($e->getMessage());
}
