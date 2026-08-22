<?php

declare(strict_types=1);

const TARGET_VERSION = '0.36.1';
const MINIMUM_VERSION = '0.36.0';
const PHPMAILER_VERSION_REQUIRED = '7.1.1';

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

function copyDirectory(string $source, string $target): void
{
    if (!is_dir($source)) {
        return;
    }

    if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
        throw new RuntimeException('Não foi possível criar a pasta de backup ' . $target . '.');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), strlen($source) + 1);
        $destination = $target . DIRECTORY_SEPARATOR . $relative;

        if ($item->isDir()) {
            if (!is_dir($destination) && !mkdir($destination, 0755, true) && !is_dir($destination)) {
                throw new RuntimeException('Não foi possível copiar a pasta ' . $relative . '.');
            }
        } else {
            if (!is_dir(dirname($destination)) && !mkdir(dirname($destination), 0755, true) && !is_dir(dirname($destination))) {
                throw new RuntimeException('Não foi possível criar a pasta de backup de ' . $relative . '.');
            }
            if (!copy($item->getPathname(), $destination)) {
                throw new RuntimeException('Não foi possível copiar ' . $relative . ' para o backup.');
            }
        }
    }
}

function removeDirectory(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($path);
}

function writeChanged(string $path, string $content, string $label): void
{
    $current = is_file($path) ? (string)file_get_contents($path) : '';

    if ($current === $content) {
        out('[OK] ' . $label . ' já estava atualizado.');
        return;
    }

    if (is_file($path)) {
        backupFile($path);
    }

    if (file_put_contents($path, $content, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível atualizar ' . $label . '.');
    }

    out('[OK] ' . $label . ' atualizado.');
}

function commandAvailable(string $command): bool
{
    if (!function_exists('exec')) {
        return false;
    }

    $output = [];
    $code = 1;

    if (PHP_OS_FAMILY === 'Windows') {
        @exec('where ' . $command . ' 2>NUL', $output, $code);
    } else {
        @exec('command -v ' . $command . ' 2>/dev/null', $output, $code);
    }

    return $code === 0 && !empty($output);
}

function composerCommand(): ?string
{
    global $root;

    if (is_file($root . '/composer.phar')) {
        return escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/composer.phar');
    }

    if (commandAvailable('composer')) {
        return 'composer';
    }

    return null;
}

function runComposer(): void
{
    global $root;

    $autoload = $root . '/lib/autoload.php';
    $packageDir = $root . '/lib/phpmailer/phpmailer';

    if (is_file($autoload) && is_dir($packageDir)) {
        out('[OK] Dependências do Composer já existem em /lib.');
        return;
    }

    $composer = composerCommand();
    if ($composer === null || !function_exists('exec')) {
        throw new RuntimeException(
            "Composer não foi encontrado para execução automática.\n"
            . "Execute na raiz do Portal:\n"
            . "  composer install --no-dev --optimize-autoloader\n"
            . "Depois execute novamente:\n"
            . "  php atualizar_v0.36.1.php"
        );
    }

    $oldCwd = getcwd();
    if (!@chdir($root)) {
        throw new RuntimeException('Não foi possível acessar a raiz do Portal para executar o Composer.');
    }

    $command = $composer . ' install --no-dev --optimize-autoloader --no-interaction --prefer-dist 2>&1';
    $output = [];
    $code = 1;

    out('[INFO] Instalando PHPMailer pelo Composer em /lib...');
    @exec($command, $output, $code);

    if ($oldCwd !== false) {
        @chdir($oldCwd);
    }

    foreach ($output as $line) {
        out('  ' . $line);
    }

    if ($code !== 0) {
        throw new RuntimeException(
            "O Composer retornou código {$code}.\n"
            . "Corrija o erro acima e rode novamente: php atualizar_v0.36.1.php"
        );
    }

    if (!is_file($autoload) || !is_dir($packageDir)) {
        throw new RuntimeException('O Composer terminou, mas o PHPMailer não foi encontrado em /lib/phpmailer/phpmailer.');
    }

    out('[OK] Composer concluiu a instalação das dependências.');
}

function verifyComposerPhpMailer(): void
{
    global $root;

    $autoload = $root . '/lib/autoload.php';
    if (!is_file($autoload)) {
        throw new RuntimeException('lib/autoload.php não encontrado. Execute composer install.');
    }

    require_once $autoload;

    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer', true)) {
        throw new RuntimeException('O autoload do Composer não conseguiu carregar PHPMailer\\PHPMailer\\PHPMailer.');
    }

    $version = (string)\PHPMailer\PHPMailer\PHPMailer::VERSION;
    if (version_compare($version, PHPMAILER_VERSION_REQUIRED, '<')) {
        throw new RuntimeException(
            'PHPMailer ' . $version . ' é antigo. A versão mínima exigida é ' . PHPMAILER_VERSION_REQUIRED . '.'
        );
    }

    $reflection = new ReflectionClass(\PHPMailer\PHPMailer\PHPMailer::class);
    $file = $reflection->getFileName();
    $libRoot = realpath($root . '/lib');
    $classFile = is_string($file) ? realpath($file) : false;

    if ($libRoot === false || $classFile === false) {
        throw new RuntimeException('Não foi possível validar o caminho físico do PHPMailer.');
    }

    $prefix = rtrim(str_replace('\\', '/', $libRoot), '/') . '/';
    $normalizedClassFile = str_replace('\\', '/', $classFile);
    if (!str_starts_with($normalizedClassFile, $prefix)) {
        throw new RuntimeException(
            'PHPMailer foi carregado fora de /lib: ' . $normalizedClassFile
        );
    }

    out('[OK] PHPMailer ' . $version . ' carregado pelo Composer em /lib.');
}

function patchBootstrap(string $path): void
{
    $source = (string)file_get_contents($path);

    if (str_contains($source, "lib/autoload.php")) {
        out('[OK] bootstrap.php já carrega o autoload do Composer.');
        return;
    }

    $anchor = "require_once __DIR__ . '/config/config.php';";
    if (!str_contains($source, $anchor)) {
        throw new RuntimeException('Não foi possível localizar config/config.php no bootstrap.php.');
    }

    $block = <<<'PHP'

$composerAutoload = __DIR__ . '/lib/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}
PHP;

    $source = str_replace($anchor, $anchor . "\n" . $block, $source);
    writeChanged($path, $source, 'bootstrap.php');
}

function patchMailService(string $path): void
{
    $source = (string)file_get_contents($path);
    $original = $source;

    // libraryInstalled passa a validar o Composer em vez de arquivos manuais.
    $patternInstalled = '/    public static function libraryInstalled\(\): bool\s*\{.*?\n    \}\n\n    public static function libraryVersion\(\): string/s';
    $replacementInstalled = <<<'PHP'
    public static function libraryInstalled(): bool
    {
        try {
            self::loadLibrary();
            return class_exists('PHPMailer\\PHPMailer\\PHPMailer', false);
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function libraryVersion(): string
PHP;

    if (preg_match($patternInstalled, $source)) {
        $source = preg_replace($patternInstalled, $replacementInstalled, $source, 1) ?? $source;
    } elseif (!str_contains($source, "return class_exists('PHPMailer\\\\PHPMailer\\\\PHPMailer', false);")) {
        throw new RuntimeException('Não foi possível atualizar libraryInstalled() no MailService.');
    }

    $source = str_replace(
        'PHPMailer não está instalado. Execute: php atualizar_phpmailer_v0.26.0.php',
        'PHPMailer não está instalado pelo Composer. Execute: composer install --no-dev --optimize-autoloader',
        $source
    );

    // Substitui o carregamento manual de /vendor pelo autoload do Composer em /lib.
    $patternLoader = '/    private static function loadLibrary\(\): void\s*\{.*?\n    private static function smtpPassword\(PDO \$pdo\): string/s';
    $replacementLoader = <<<'PHP'
    private static function loadLibrary(): void
    {
        if (class_exists('PHPMailer\\PHPMailer\\PHPMailer', false)) {
            self::validateLibraryVersion();
            return;
        }

        $autoload = self::composerAutoloadPath();
        if (!is_file($autoload)) {
            throw new RuntimeException(
                'PHPMailer não está instalado pelo Composer. Execute: composer install --no-dev --optimize-autoloader'
            );
        }

        require_once $autoload;

        if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer', true)) {
            throw new RuntimeException(
                'O autoload do Composer foi encontrado, mas o PHPMailer não pôde ser carregado.'
            );
        }

        self::validateLibraryVersion();
    }

    private static function validateLibraryVersion(): void
    {
        $version = (string)\PHPMailer\PHPMailer\PHPMailer::VERSION;
        if (version_compare($version, self::PHPMAILER_VERSION, '<')) {
            throw new RuntimeException(
                'PHPMailer ' . $version . ' é antigo. Execute composer update phpmailer/phpmailer.'
            );
        }
    }

    private static function composerAutoloadPath(): string
    {
        return dirname(__DIR__, 2) . '/lib/autoload.php';
    }

    private static function smtpPassword(PDO $pdo): string
PHP;

    if (preg_match($patternLoader, $source)) {
        $source = preg_replace($patternLoader, $replacementLoader, $source, 1) ?? $source;
    } elseif (!str_contains($source, 'private static function composerAutoloadPath(): string')) {
        throw new RuntimeException('Não foi possível trocar o carregamento manual do PHPMailer no MailService.');
    }

    if (str_contains($source, "/vendor/phpmailer/phpmailer/src")) {
        throw new RuntimeException('O MailService ainda contém referência ao PHPMailer antigo em /vendor.');
    }

    if ($source === $original) {
        out('[OK] MailService já utiliza Composer em /lib.');
        return;
    }

    writeChanged($path, $source, 'app/Services/MailService.php');
}

function patchEmailSettings(string $path): void
{
    $source = (string)file_get_contents($path);
    $original = $source;

    $source = str_replace(
        '<p class="text-secondary mb-0">Envio centralizado com PHPMailer <?= e(MailService::libraryVersion()) ?>.</p>',
        '<p class="text-secondary mb-0">Envio centralizado com PHPMailer <?= e(MailService::libraryVersion()) ?> · gerenciado pelo Composer em <code>/lib</code>.</p>',
        $source
    );

    if ($source === $original) {
        if (str_contains($source, 'gerenciado pelo Composer em <code>/lib</code>')) {
            out('[OK] Configurações > E-mail já identifica a instalação Composer.');
            return;
        }

        out('[AVISO] Não foi possível alterar o texto informativo de Configurações > E-mail; o envio continuará funcional.');
        return;
    }

    writeChanged($path, $source, 'admin/configuracoes/email.php');
}

function patchGitignore(string $path): void
{
    $source = is_file($path) ? (string)file_get_contents($path) : '';
    $original = $source;

    $lines = preg_split('/\R/', $source) ?: [];
    $required = [
        '/lib/*',
        '!/lib/.htaccess',
        '/vendor/',
    ];

    foreach ($required as $line) {
        if (!in_array($line, $lines, true)) {
            $lines[] = $line;
        }
    }

    $source = rtrim(implode("\n", $lines)) . "\n";

    if ($source === $original) {
        out('[OK] .gitignore já está preparado para Composer em /lib.');
        return;
    }

    writeChanged($path, $source, '.gitignore');
}

function cleanupOldPhpMailer(): void
{
    global $root, $backupDir;

    $old = $root . '/vendor/phpmailer/phpmailer';
    if (!is_dir($old)) {
        out('[OK] Nenhuma cópia antiga de PHPMailer em /vendor precisa ser removida.');
        return;
    }

    $backup = $backupDir . '/vendor/phpmailer/phpmailer';
    copyDirectory($old, $backup);
    removeDirectory($old);

    if (is_dir($old)) {
        throw new RuntimeException('Não foi possível remover a cópia antiga de PHPMailer em /vendor.');
    }

    @rmdir($root . '/vendor/phpmailer');
    @rmdir($root . '/vendor');

    out('[OK] PHPMailer antigo de /vendor foi movido para o backup e removido do Portal.');
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
out(str_repeat('-', 76));

$config = $root . '/config/config.php';
if (!is_file($config)) {
    fail('config/config.php não encontrado.');
}

foreach ([
    'composer.json',
    'lib/.htaccess',
    'app/Services/MailService.php',
    'bootstrap.php',
] as $required) {
    if (!is_file($root . '/' . $required)) {
        fail('Arquivo necessário não encontrado: ' . $required);
    }
}

require_once $config;

$current = defined('APP_VERSION') ? (string)APP_VERSION : '0.0.0';
out('Versão identificada: ' . $current);

if (version_compare($current, MINIMUM_VERSION, '<')) {
    fail('A v' . TARGET_VERSION . ' requer Portal v' . MINIMUM_VERSION . ' ou superior.');
}

try {
    // Composer deve ser concluído e validado antes de remover o PHPMailer antigo.
    runComposer();
    verifyComposerPhpMailer();

    patchBootstrap($root . '/bootstrap.php');
    patchMailService($root . '/app/Services/MailService.php');

    $emailSettings = $root . '/admin/configuracoes/email.php';
    if (is_file($emailSettings)) {
        patchEmailSettings($emailSettings);
    }

    patchGitignore($root . '/.gitignore');

    // Somente após PHPMailer em /lib estar funcionando.
    cleanupOldPhpMailer();

    updateVersion($config);

    if (function_exists('opcache_reset')) {
        @opcache_reset();
    }

    out(str_repeat('-', 76));
    out('Atualização v' . TARGET_VERSION . ' concluída.');
    out('PHPMailer agora é gerenciado pelo Composer em /lib.');
    out('Autoload: lib/autoload.php');
    out('Pacote: lib/phpmailer/phpmailer/');
    out('Arquivo de lock: composer.lock');
    if (is_dir($backupDir)) {
        out('Backups: ' . str_replace('\\', '/', $backupDir));
    }
} catch (Throwable $e) {
    fail($e->getMessage());
}
