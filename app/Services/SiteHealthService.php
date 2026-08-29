<?php

declare(strict_types=1);

final class SiteHealthService
{
    private PDO $pdo;
    private string $root;

    public function __construct(PDO $pdo, ?string $root = null)
    {
        $this->pdo = $pdo;
        $this->root = rtrim($root ?? dirname(__DIR__, 2), DIRECTORY_SEPARATOR);
    }

    /**
     * @return array{sections:array<string,array{title:string,checks:array<int,array<string,mixed>>}>,summary:array{ok:int,warn:int,error:int,info:int,total:int,overall:string}}
     */
    public function run(bool $diagnoseSmtp = false): array
    {
        $sections = [
            'system' => ['title' => 'Servidor e PHP', 'checks' => $this->systemChecks()],
            'database' => ['title' => 'Banco de dados', 'checks' => $this->databaseChecks()],
            'files' => ['title' => 'Arquivos e permissões', 'checks' => $this->fileChecks()],
            'urls' => ['title' => 'URLs, SEO e ambiente', 'checks' => $this->urlChecks()],
            'email' => ['title' => 'E-mail', 'checks' => $this->emailChecks($diagnoseSmtp)],
            'security' => ['title' => 'Segurança', 'checks' => $this->securityChecks()],
        ];

        $summary = ['ok' => 0, 'warn' => 0, 'error' => 0, 'info' => 0, 'total' => 0, 'overall' => 'ok'];
        foreach ($sections as $section) {
            foreach ($section['checks'] as $check) {
                $status = (string)($check['status'] ?? 'info');
                if (!array_key_exists($status, $summary)) {
                    $status = 'info';
                }
                $summary[$status]++;
                $summary['total']++;
            }
        }
        $summary['overall'] = $summary['error'] > 0 ? 'error' : ($summary['warn'] > 0 ? 'warn' : 'ok');

        return ['sections' => $sections, 'summary' => $summary];
    }

    /** @return array<int,array<string,mixed>> */
    private function systemChecks(): array
    {
        $checks = [];

        $checks[] = $this->check(
            version_compare(PHP_VERSION, '8.2.0', '>='),
            'PHP 8.2 ou superior',
            'Versão atual: ' . PHP_VERSION,
            'Atualize o PHP para 8.2 ou 8.3 antes de usar o Portal em produção.'
        );

        $required = [
            'pdo_mysql' => ['PDO MySQL', 'Necessário para acessar o banco de dados.'],
            'fileinfo' => ['Fileinfo', 'Necessário para validar uploads com segurança.'],
            'openssl' => ['OpenSSL', 'Necessário para TLS/SMTP e proteção de segredos.'],
        ];
        foreach ($required as $ext => [$label, $help]) {
            $checks[] = $this->check(extension_loaded($ext), 'Extensão ' . $label, extension_loaded($ext) ? 'Ativa.' : 'Não carregada.', $help);
        }

        $recommended = [
            'mbstring' => ['Mbstring', 'Recomendada para texto UTF-8 e processamento de conteúdo.'],
            'zip' => ['ZIP', 'Necessária para o Backup Completo do Portal.'],
            'curl' => ['cURL', 'Recomendada para o importador WordPress e integrações HTTP.'],
            'gd' => ['GD', 'Recomendada para operações com imagens.'],
        ];
        foreach ($recommended as $ext => [$label, $help]) {
            $ok = extension_loaded($ext);
            $checks[] = $this->row($ok ? 'ok' : 'warn', 'Extensão ' . $label, $ok ? 'Ativa.' : 'Não carregada.', $ok ? '' : $help);
        }

        $maxExecution = (int)ini_get('max_execution_time');
        $executionOk = $maxExecution === 0 || $maxExecution >= 300;
        $checks[] = $this->row(
            $executionOk ? 'ok' : 'warn',
            'Tempo máximo de execução',
            $maxExecution === 0 ? 'Sem limite no PHP.' : $maxExecution . ' segundos.',
            $executionOk ? '' : 'Para importações grandes do WordPress, use pelo menos 300 segundos; 600 segundos é recomendado.'
        );

        $memory = $this->iniBytes((string)ini_get('memory_limit'));
        $memoryOk = $memory < 0 || $memory >= 128 * 1024 * 1024;
        $checks[] = $this->row(
            $memoryOk ? 'ok' : 'warn',
            'Limite de memória',
            (string)ini_get('memory_limit'),
            $memoryOk ? '' : 'Recomenda-se pelo menos 128M para importações, mídia e backups.'
        );

        $configuredUpload = defined('UPLOAD_MAX_SIZE') ? (int)UPLOAD_MAX_SIZE : 10 * 1024 * 1024;
        $uploadMax = $this->iniBytes((string)ini_get('upload_max_filesize'));
        $postMax = $this->iniBytes((string)ini_get('post_max_size'));
        $effective = min($uploadMax > 0 ? $uploadMax : PHP_INT_MAX, $postMax > 0 ? $postMax : PHP_INT_MAX);
        $uploadOk = $effective >= $configuredUpload;
        $checks[] = $this->row(
            $uploadOk ? 'ok' : 'warn',
            'Limite real de upload',
            'Portal: ' . $this->formatBytes($configuredUpload) . ' · PHP: upload_max_filesize=' . ini_get('upload_max_filesize') . ', post_max_size=' . ini_get('post_max_size'),
            $uploadOk ? '' : 'O PHP está limitando uploads abaixo do valor configurado pelo Portal.'
        );

        $checks[] = $this->row('info', 'Servidor', PHP_SAPI . ' · ' . PHP_OS_FAMILY, '');

        return $checks;
    }

    /** @return array<int,array<string,mixed>> */
    private function databaseChecks(): array
    {
        $checks = [];
        try {
            $version = (string)$this->pdo->query('SELECT VERSION()')->fetchColumn();
            $database = (string)$this->pdo->query('SELECT DATABASE()')->fetchColumn();
            $charset = (string)$this->pdo->query('SELECT @@character_set_database')->fetchColumn();
            $collation = (string)$this->pdo->query('SELECT @@collation_database')->fetchColumn();
            $checks[] = $this->row('ok', 'Conexão com o banco', ($database !== '' ? $database : '(sem nome)') . ' · ' . $version, '');
            $checks[] = $this->row($charset === 'utf8mb4' ? 'ok' : 'warn', 'Charset do banco', $charset . ' / ' . $collation, $charset === 'utf8mb4' ? '' : 'Recomenda-se utf8mb4 para suportar corretamente caracteres Unicode.');
        } catch (Throwable $e) {
            return [$this->row('error', 'Conexão com o banco', $e->getMessage(), 'Revise config/config.php e o serviço MySQL/MariaDB.')];
        }

        $expected = [
            'perfis', 'usuarios', 'permissoes', 'perfil_permissoes', 'midias', 'comunidades',
            'categorias', 'posts', 'post_categorias', 'tags', 'post_tags', 'paginas', 'eventos',
            'evento_categorias', 'configuracoes', 'logs', 'login_tentativas', 'comentarios', 'revisoes',
            'formularios', 'formulario_campos', 'formulario_respostas', 'formulario_resposta_valores',
            'galerias', 'galeria_midias', 'banners', 'widgets', 'menus', 'menu_itens',
            'newsletter_assinantes', 'newsletter_campanhas', 'newsletter_envios', 'email_envios',
            'wordpress_importacoes', 'wordpress_import_map', 'wordpress_import_logs',
            'home_secoes', 'home_post_categorias',
        ];

        try {
            $stmt = $this->pdo->query('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()');
            $present = array_map('strtolower', array_column($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'table_name'));
            $missing = array_values(array_diff($expected, $present));
            $checks[] = $this->row(
                $missing === [] ? 'ok' : 'error',
                'Estrutura principal',
                $missing === [] ? count($expected) . ' tabelas essenciais encontradas.' : 'Faltando: ' . implode(', ', $missing),
                $missing === [] ? '' : 'Execute os atualizadores pendentes antes de continuar.'
            );
        } catch (Throwable $e) {
            $checks[] = $this->row('warn', 'Estrutura principal', 'Não foi possível comparar as tabelas: ' . $e->getMessage(), '');
        }

        try {
            $count = (int)$this->pdo->query("SELECT COUNT(*) FROM usuarios WHERE ativo=1")->fetchColumn();
            $checks[] = $this->row($count > 0 ? 'ok' : 'error', 'Usuários ativos', $count . ' usuário(s) ativo(s).', $count > 0 ? '' : 'O painel ficará inacessível sem ao menos um usuário ativo.');
        } catch (Throwable $e) {
            $checks[] = $this->row('warn', 'Usuários ativos', 'Não foi possível verificar.', '');
        }

        return $checks;
    }

    /** @return array<int,array<string,mixed>> */
    private function fileChecks(): array
    {
        $checks = [];
        $config = $this->root . '/config/config.php';
        $configProtect = $this->root . '/config/.htaccess';
        $rootHtaccess = $this->root . '/.htaccess';

        $checks[] = $this->check(is_file($config) && is_readable($config), 'Arquivo de configuração', is_file($config) ? 'config/config.php encontrado.' : 'config/config.php ausente.', 'O Portal precisa do arquivo config/config.php para iniciar.');
        $checks[] = $this->row(is_file($configProtect) ? 'ok' : 'error', 'Proteção da pasta config', is_file($configProtect) ? 'config/.htaccess presente.' : 'config/.htaccess não encontrado.', 'A v0.30.0 inclui proteção contra acesso HTTP aos arquivos de configuração e backups.');

        $dirs = [
            'uploads' => true,
            'storage' => true,
            'storage/backups' => false,
            'storage/private' => false,
            'storage/config-backups' => false,
        ];
        foreach ($dirs as $relative => $required) {
            $path = $this->root . '/' . $relative;
            $exists = is_dir($path);
            $writable = $exists && is_writable($path);
            $status = $writable ? 'ok' : ($required ? 'error' : 'warn');
            $detail = !$exists ? 'Pasta ausente.' : ($writable ? 'Gravável.' : 'Sem permissão de gravação.');
            $checks[] = $this->row($status, 'Pasta ' . $relative, $detail, $writable ? '' : ($required ? 'Ajuste as permissões da pasta para o usuário do PHP/Apache.' : 'Alguns recursos opcionais podem ficar indisponíveis.'));
        }

        $rootHtaccessText = is_file($rootHtaccess) ? (string)@file_get_contents($rootHtaccess) : '';
        $hardened = str_contains($rootHtaccessText, 'BEGIN IECLB V0.30 SENSITIVE FILES');
        $checks[] = $this->row(
            $hardened ? 'ok' : 'warn',
            'Proteção de arquivos sensíveis no Apache',
            $hardened ? 'Regras v0.30.0 instaladas.' : 'Regras v0.30.0 não detectadas no .htaccess.',
            $hardened ? '' : 'Execute php atualizar_v0.30.0.php para bloquear atualizadores, dumps SQL, backups e checksums via HTTP.'
        );

        $backups = glob($this->root . '/config/config.php.bak-*') ?: [];
        if ($backups !== []) {
            $checks[] = $this->row(
                is_file($configProtect) ? 'warn' : 'error',
                'Backups antigos em config/',
                count($backups) . ' arquivo(s) de backup encontrado(s).',
                'O atualizador v0.30.0 move esses backups para storage/config-backups. Execute-o novamente se eles permanecerem aqui.'
            );
        } else {
            $checks[] = $this->row('ok', 'Backups de configuração', 'Nenhum backup antigo exposto na pasta config/.', '');
        }

        return $checks;
    }

    /** @return array<int,array<string,mixed>> */
    private function urlChecks(): array
    {
        $checks = [];
        $base = defined('BASE_URL') ? trim((string)BASE_URL) : '';
        $parts = $base !== '' ? parse_url($base) : false;
        $valid = is_array($parts) && isset($parts['scheme'], $parts['host']) && in_array(strtolower((string)$parts['scheme']), ['http', 'https'], true);
        $checks[] = $this->row($valid ? 'ok' : 'error', 'BASE_URL', $base !== '' ? $base : 'Não definida.', $valid ? '' : 'Defina uma URL completa, por exemplo https://www.exemplo.org.br.');

        $env = defined('APP_ENV') ? strtolower((string)APP_ENV) : 'development';
        $isProduction = $env === 'production';
        $https = $valid && strtolower((string)$parts['scheme']) === 'https';
        $checks[] = $this->row(!$isProduction || $https ? 'ok' : 'warn', 'HTTPS em produção', $isProduction ? ($https ? 'Ativo.' : 'BASE_URL não usa HTTPS.') : 'Ambiente atual: ' . $env, !$isProduction || $https ? '' : 'Use HTTPS no ambiente de produção.');

        $checks[] = $this->row(is_file($this->root . '/sitemap.php') ? 'ok' : 'error', 'Sitemap', is_file($this->root . '/sitemap.php') ? 'Gerador de sitemap encontrado.' : 'sitemap.php ausente.', '');
        $checks[] = $this->row(is_file($this->root . '/robots.php') ? 'ok' : 'warn', 'Robots', is_file($this->root . '/robots.php') ? 'robots.php encontrado.' : 'robots.php ausente.', '');
        $checks[] = $this->row(is_file($this->root . '/router.php') ? 'ok' : 'warn', 'Roteador de links permanentes', is_file($this->root . '/router.php') ? 'router.php encontrado.' : 'router.php ausente.', '');

        return $checks;
    }

    /** @return array<int,array<string,mixed>> */
    private function emailChecks(bool $diagnoseSmtp): array
    {
        $checks = [];
        if (!class_exists('MailService')) {
            return [$this->row('error', 'Serviço de e-mail', 'MailService não carregado.', 'Verifique app/Services/MailService.php e bootstrap.php.')];
        }

        $installed = MailService::libraryInstalled();
        $checks[] = $this->row($installed ? 'ok' : 'error', 'PHPMailer', $installed ? 'Versão ' . MailService::libraryVersion() . ' instalada.' : 'Biblioteca não instalada.', $installed ? '' : 'Execute o instalador/correção do PHPMailer antes de enviar e-mails.');

        $issue = null;
        try {
            $issue = MailService::configurationIssue($this->pdo);
        } catch (Throwable $e) {
            $issue = $e->getMessage();
        }
        $checks[] = $this->row($issue === null ? 'ok' : 'warn', 'Configuração de e-mail', $issue === null ? MailService::transportLabel($this->pdo) . ' configurado.' : $issue, $issue === null ? '' : 'Revise Configurações > E-mail.');

        try {
            foreach (MailService::configurationWarnings($this->pdo) as $warning) {
                $checks[] = $this->row('warn', 'Aviso de e-mail', $warning, '');
            }
        } catch (Throwable $e) {
            $checks[] = $this->row('warn', 'Avisos de e-mail', $e->getMessage(), '');
        }

        if ($diagnoseSmtp && $installed && MailService::transport($this->pdo) === 'smtp' && $issue === null) {
            try {
                $diag = MailService::diagnoseSmtp($this->pdo);
                $checks[] = $this->row(!empty($diag['ok']) ? 'ok' : 'error', 'Diagnóstico SMTP', (string)($diag['summary'] ?? 'Diagnóstico concluído.'), !empty($diag['ok']) ? '' : 'Abra Configurações > E-mail para revisar host, porta, TLS e autenticação.');
                if (!empty($diag['ips'])) {
                    $checks[] = $this->row('info', 'DNS do SMTP', implode(', ', (array)$diag['ips']), '');
                }
            } catch (Throwable $e) {
                $checks[] = $this->row('error', 'Diagnóstico SMTP', $e->getMessage(), '');
            }
        } elseif ($diagnoseSmtp && MailService::transport($this->pdo) !== 'smtp') {
            $checks[] = $this->row('info', 'Diagnóstico SMTP', 'O transporte atual não é SMTP.', 'Selecione SMTP em Configurações > E-mail para testar conexão e autenticação.');
        }

        return $checks;
    }

    /** @return array<int,array<string,mixed>> */
    private function securityChecks(): array
    {
        $checks = [];
        $env = defined('APP_ENV') ? strtolower((string)APP_ENV) : 'development';
        $debug = defined('APP_DEBUG') ? (bool)APP_DEBUG : false;
        $displayErrors = strtolower((string)ini_get('display_errors'));
        $displayEnabled = in_array($displayErrors, ['1', 'on', 'yes', 'true'], true);

        $checks[] = $this->row(!($env === 'production' && $debug) ? 'ok' : 'error', 'APP_DEBUG', $debug ? 'Ativado.' : 'Desativado.', $env === 'production' && $debug ? 'Desative APP_DEBUG em produção para evitar exposição de detalhes internos.' : '');
        $checks[] = $this->row(!($env === 'production' && $displayEnabled) ? 'ok' : 'warn', 'display_errors', $displayEnabled ? 'Ativado no PHP.' : 'Desativado.', $env === 'production' && $displayEnabled ? 'Em produção, registre erros em log em vez de exibi-los ao visitante.' : '');

        try {
            $timeout = (int)siteConfig($this->pdo, 'security_session_timeout_minutes', '60');
            $attempts = (int)siteConfig($this->pdo, 'security_login_max_attempts', '5');
            $lock = (int)siteConfig($this->pdo, 'security_login_lock_minutes', '15');
            $checks[] = $this->row($timeout >= 15 && $timeout <= 480 ? 'ok' : 'warn', 'Expiração de sessão', $timeout . ' minutos.', $timeout >= 15 && $timeout <= 480 ? '' : 'Use um intervalo razoável, normalmente entre 15 e 480 minutos.');
            $checks[] = $this->row($attempts >= 3 && $attempts <= 10 ? 'ok' : 'warn', 'Tentativas de login', $attempts . ' tentativa(s) antes do bloqueio por ' . $lock . ' minuto(s).', '');
        } catch (Throwable $e) {
            $checks[] = $this->row('warn', 'Configurações de segurança', 'Não foi possível ler as configurações: ' . $e->getMessage(), '');
        }

        $checks[] = $this->row(function_exists('password_hash') && defined('PASSWORD_DEFAULT') ? 'ok' : 'error', 'Hash de senhas', 'password_hash/password_verify disponíveis.', '');
        $checks[] = $this->row(extension_loaded('openssl') ? 'ok' : 'error', 'Criptografia de segredos', extension_loaded('openssl') ? 'OpenSSL disponível.' : 'OpenSSL indisponível.', 'A senha SMTP criptografada depende do OpenSSL.');

        return $checks;
    }

    private function check(bool $ok, string $label, string $detail, string $help = ''): array
    {
        return $this->row($ok ? 'ok' : 'error', $label, $detail, $ok ? '' : $help);
    }

    /** @return array{status:string,label:string,detail:string,help:string} */
    private function row(string $status, string $label, string $detail, string $help = ''): array
    {
        return ['status' => $status, 'label' => $label, 'detail' => $detail, 'help' => $help];
    }

    private function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return $value === '-1' ? -1 : 0;
        }
        $unit = strtolower(substr($value, -1));
        $number = (float)$value;
        return match ($unit) {
            'g' => (int)($number * 1024 * 1024 * 1024),
            'm' => (int)($number * 1024 * 1024),
            'k' => (int)($number * 1024),
            default => (int)$number,
        };
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) return round($bytes / (1024 * 1024 * 1024), 1) . ' GB';
        if ($bytes >= 1024 * 1024) return round($bytes / (1024 * 1024), 1) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }
}
