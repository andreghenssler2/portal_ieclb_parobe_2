<?php

declare(strict_types=1);

final class CookieConsentService
{
    private const COOKIE_NAME = 'portal_cookie_consent';

    public static function defaults(): array
    {
        return [
            'cookie_consent_enabled' => '1',
            'cookie_consent_version' => '1',
            'cookie_consent_days' => '180',
            'cookie_consent_title' => 'Sua privacidade',
            'cookie_consent_text' => 'Usamos cookies necessários para o funcionamento do Portal. Com sua autorização, também podemos usar tecnologias de estatísticas e marketing.',
            'cookie_consent_analytics_label' => 'Estatísticas',
            'cookie_consent_analytics_description' => 'Ajuda a entender como o Portal é utilizado, por exemplo por meio do Google Analytics.',
            'cookie_consent_marketing_label' => 'Marketing',
            'cookie_consent_marketing_description' => 'Permite tecnologias usadas para campanhas, conversões e outros recursos de marketing.',
            'cookie_gtm_category' => 'analytics',
            'cookie_preferences_footer_link' => '1',
        ];
    }

    public static function settings(PDO $pdo): array
    {
        return array_merge(self::defaults(), siteConfigAll($pdo));
    }

    public static function enabled(PDO $pdo): bool
    {
        $settings = self::settings($pdo);
        return (string)($settings['cookie_consent_enabled'] ?? '1') === '1';
    }

    public static function cookieName(): string
    {
        return self::COOKIE_NAME;
    }

    public static function version(PDO $pdo): int
    {
        $settings = self::settings($pdo);
        return max(1, min(999, (int)($settings['cookie_consent_version'] ?? 1)));
    }

    public static function days(PDO $pdo): int
    {
        $settings = self::settings($pdo);
        return max(30, min(730, (int)($settings['cookie_consent_days'] ?? 180)));
    }

    public static function cookiePath(): string
    {
        $path = (string)(parse_url(defined('BASE_URL') ? (string)BASE_URL : '/', PHP_URL_PATH) ?? '/');
        $path = '/' . trim($path, '/');
        return $path === '/' ? '/' : rtrim($path, '/');
    }

    public static function secureCookie(): bool
    {
        if (
            isset($_SERVER['HTTPS'])
            && strtolower((string)$_SERVER['HTTPS']) !== 'off'
            && (string)$_SERVER['HTTPS'] !== ''
        ) {
            return true;
        }

        return strtolower((string)(parse_url(defined('BASE_URL') ? (string)BASE_URL : '', PHP_URL_SCHEME) ?? '')) === 'https';
    }

    public static function consent(PDO $pdo): ?array
    {
        if (!self::enabled($pdo)) {
            return [
                'v' => self::version($pdo),
                'a' => 1,
                'm' => 1,
                't' => time() * 1000,
                'disabled' => true,
            ];
        }

        $raw = trim((string)($_COOKIE[self::COOKIE_NAME] ?? ''));
        if ($raw === '') {
            return null;
        }

        $raw = strtr($raw, '-_', '+/');
        $padding = strlen($raw) % 4;
        if ($padding > 0) {
            $raw .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($raw, true);
        if (!is_string($decoded)) {
            return null;
        }

        $data = json_decode($decoded, true);
        if (!is_array($data)) {
            return null;
        }

        $version = (int)($data['v'] ?? 0);
        if ($version !== self::version($pdo)) {
            return null;
        }

        $timestampMs = (int)($data['t'] ?? 0);
        if ($timestampMs <= 0) {
            return null;
        }

        $minimumMs = (time() - self::days($pdo) * 86400) * 1000;
        if ($timestampMs < $minimumMs) {
            return null;
        }

        return [
            'v' => $version,
            'a' => !empty($data['a']) ? 1 : 0,
            'm' => !empty($data['m']) ? 1 : 0,
            't' => $timestampMs,
        ];
    }

    public static function allows(PDO $pdo, string $category): bool
    {
        $category = strtolower(trim($category));

        if ($category === 'necessary') {
            return true;
        }

        if (!self::enabled($pdo)) {
            return true;
        }

        $consent = self::consent($pdo);
        if ($consent === null) {
            return false;
        }

        return match ($category) {
            'analytics' => (int)($consent['a'] ?? 0) === 1,
            'marketing' => (int)($consent['m'] ?? 0) === 1,
            default => false,
        };
    }

    public static function gtmCategory(PDO $pdo): string
    {
        $settings = self::settings($pdo);
        $category = strtolower(trim((string)($settings['cookie_gtm_category'] ?? 'analytics')));

        return in_array($category, ['analytics', 'marketing'], true)
            ? $category
            : 'analytics';
    }

    public static function privacyUrl(PDO $pdo): string
    {
        try {
            $settings = self::settings($pdo);
            $pageId = (int)($settings['privacy_page_id'] ?? 0);

            if ($pageId <= 0) {
                return '';
            }

            $stmt = $pdo->prepare(
                "SELECT slug
                 FROM paginas
                 WHERE id=?
                   AND status='publicado'
                   AND (publicado_em IS NULL OR publicado_em<=NOW())
                 LIMIT 1"
            );

            $stmt->execute([$pageId]);

            $slug = trim((string)$stmt->fetchColumn());

            return $slug !== ''
                ? contentUrl('pagina', $slug)
                : '';
        } catch (Throwable $ignored) {
            return '';
        }
    }

    public static function publicConfig(PDO $pdo): array
    {
        $settings = self::settings($pdo);
        $consent = self::consent($pdo);
        $defaults = self::defaults();

        return [
            'cookieName' => self::COOKIE_NAME,
            'version' => self::version($pdo),
            'days' => self::days($pdo),
            'path' => self::cookiePath(),
            'secure' => self::secureCookie(),
            'hasConsent' => $consent !== null,
            'analytics' => (int)($consent['a'] ?? 0) === 1,
            'marketing' => (int)($consent['m'] ?? 0) === 1,
            'title' => trim((string)($settings['cookie_consent_title'] ?? $defaults['cookie_consent_title'])),
            'text' => trim((string)($settings['cookie_consent_text'] ?? $defaults['cookie_consent_text'])),
            'analyticsLabel' => trim((string)($settings['cookie_consent_analytics_label'] ?? $defaults['cookie_consent_analytics_label'])),
            'analyticsDescription' => trim((string)($settings['cookie_consent_analytics_description'] ?? $defaults['cookie_consent_analytics_description'])),
            'marketingLabel' => trim((string)($settings['cookie_consent_marketing_label'] ?? $defaults['cookie_consent_marketing_label'])),
            'marketingDescription' => trim((string)($settings['cookie_consent_marketing_description'] ?? $defaults['cookie_consent_marketing_description'])),
            'privacyUrl' => self::privacyUrl($pdo),
        ];
    }

    public static function renderUi(PDO $pdo): string
    {
        if (!self::enabled($pdo)) {
            return '';
        }

        $config = self::publicConfig($pdo);
        $configJson = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (!is_string($configJson)) {
            return '';
        }

        $esc = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        $privacyUrl = trim((string)$config['privacyUrl']);
        $privacyLink = $privacyUrl !== ''
            ? '<a href="' . $esc($privacyUrl) . '" class="portal-cookie-privacy-link">Política de Privacidade</a>'
            : '';

        $bannerHidden = !empty($config['hasConsent']) ? ' hidden' : '';

        return
            '<div class="portal-cookie-consent" data-cookie-consent-root data-config="' . $esc($configJson) . '">'
            . '<section class="portal-cookie-banner" data-cookie-banner' . $bannerHidden . ' aria-label="Preferências de cookies">'
            . '<div class="portal-cookie-banner__content">'
            . '<div class="portal-cookie-banner__text">'
            . '<strong class="portal-cookie-title">' . $esc((string)$config['title']) . '</strong>'
            . '<p>' . $esc((string)$config['text']) . '</p>'
            . $privacyLink
            . '</div>'
            . '<div class="portal-cookie-banner__actions">'
            . '<button type="button" class="btn btn-outline-secondary" data-cookie-reject>Recusar opcionais</button>'
            . '<button type="button" class="btn btn-outline-primary" data-cookie-customize>Personalizar</button>'
            . '<button type="button" class="btn btn-primary" data-cookie-accept>Aceitar todos</button>'
            . '</div></div></section>'
            . '<div class="portal-cookie-dialog-backdrop" data-cookie-dialog-backdrop hidden>'
            . '<section class="portal-cookie-dialog" role="dialog" aria-modal="true" aria-labelledby="portalCookieDialogTitle" tabindex="-1" data-cookie-dialog>'
            . '<div class="portal-cookie-dialog__header"><div>'
            . '<h2 id="portalCookieDialogTitle">Preferências de cookies</h2>'
            . '<p>Escolha quais categorias opcionais podem ser usadas.</p>'
            . '</div><button type="button" class="portal-cookie-close" aria-label="Fechar preferências" data-cookie-close>&times;</button></div>'
            . '<div class="portal-cookie-category"><div><strong>Necessários</strong><p>Essenciais para segurança, sessão, formulários e funcionamento básico do Portal.</p></div>'
            . '<label class="portal-cookie-switch"><input type="checkbox" checked disabled><span>Sempre ativo</span></label></div>'
            . '<div class="portal-cookie-category"><div><strong>' . $esc((string)$config['analyticsLabel']) . '</strong><p>' . $esc((string)$config['analyticsDescription']) . '</p></div>'
            . '<label class="portal-cookie-switch"><input type="checkbox" data-cookie-analytics><span>Permitir</span></label></div>'
            . '<div class="portal-cookie-category"><div><strong>' . $esc((string)$config['marketingLabel']) . '</strong><p>' . $esc((string)$config['marketingDescription']) . '</p></div>'
            . '<label class="portal-cookie-switch"><input type="checkbox" data-cookie-marketing><span>Permitir</span></label></div>'
            . '<div class="portal-cookie-dialog__footer">'
            . '<button type="button" class="btn btn-outline-secondary" data-cookie-dialog-reject>Recusar opcionais</button>'
            . '<button type="button" class="btn btn-primary" data-cookie-save>Salvar preferências</button>'
            . '</div></section></div></div>';
    }
}
