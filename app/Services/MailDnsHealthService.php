<?php

declare(strict_types=1);

final class MailDnsHealthService
{
    public static function configuredDomain(PDO $pdo): string
    {
        $email = strtolower(trim(siteConfig($pdo, 'mail_from_email', siteConfig($pdo, 'site_email', ''))));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !str_contains($email, '@')) {
            return '';
        }

        $domain = substr(strrchr($email, '@') ?: '', 1);
        $domain = trim($domain, " .\t\n\r\0\x0B");

        if ($domain !== '' && function_exists('idn_to_ascii')) {
            $ascii = @idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $domain = $ascii;
            }
        }

        return strtolower($domain);
    }

    public static function dkimSelector(PDO $pdo): string
    {
        $selector = strtolower(trim(siteConfig($pdo, 'mail_dkim_selector', '')));
        return preg_match('/^[a-z0-9][a-z0-9._-]{0,62}$/', $selector) ? $selector : '';
    }

    /** @return array<string,mixed> */
    public static function report(PDO $pdo): array
    {
        $domain = self::configuredDomain($pdo);
        $selector = self::dkimSelector($pdo);

        $result = [
            'domain' => $domain,
            'selector' => $selector,
            'dns_available' => function_exists('dns_get_record'),
            'spf' => ['status'=>'unknown','records'=>[],'message'=>''],
            'dkim' => ['status'=>'unknown','records'=>[],'message'=>'','host'=>''],
            'dmarc' => ['status'=>'unknown','records'=>[],'message'=>'','policy'=>''],
            'mx' => ['status'=>'unknown','records'=>[],'message'=>''],
            'warnings' => [],
            'errors' => [],
            'score' => 0,
            'max_score' => 4,
        ];

        if ($domain === '') {
            $result['errors'][] = 'Configure um e-mail remetente válido antes de verificar SPF/DKIM/DMARC.';
            return $result;
        }

        if (!function_exists('dns_get_record')) {
            $result['errors'][] = 'A função PHP dns_get_record() não está disponível neste servidor.';
            return $result;
        }

        $txt = self::txtRecords($domain);
        $spf = array_values(array_filter($txt, static fn(string $v): bool => stripos($v, 'v=spf1') === 0));
        $result['spf']['records'] = $spf;

        if (count($spf) === 1) {
            $result['spf']['status'] = 'ok';
            $result['spf']['message'] = 'Um registro SPF foi localizado.';
            $result['score']++;
            if (preg_match('/\s\+all(?:\s|$)/i', $spf[0])) {
                $result['spf']['status'] = 'warning';
                $result['spf']['message'] = 'SPF localizado, mas contém +all, que autoriza qualquer servidor.';
                $result['warnings'][] = $result['spf']['message'];
            } elseif (!preg_match('/\s[-~?]all(?:\s|$)/i', $spf[0])) {
                $result['warnings'][] = 'O SPF não termina com um mecanismo all reconhecido (-all, ~all ou ?all).';
            }
        } elseif (count($spf) > 1) {
            $result['spf']['status'] = 'error';
            $result['spf']['message'] = 'Mais de um registro SPF foi encontrado. SPF deve existir em um único TXT.';
            $result['errors'][] = $result['spf']['message'];
        } else {
            $result['spf']['status'] = 'warning';
            $result['spf']['message'] = 'Nenhum registro SPF foi localizado.';
            $result['warnings'][] = $result['spf']['message'];
        }

        $dmarcHost = '_dmarc.' . $domain;
        $dmarcTxt = self::txtRecords($dmarcHost);
        $dmarc = array_values(array_filter($dmarcTxt, static fn(string $v): bool => stripos($v, 'v=DMARC1') === 0));
        $result['dmarc']['records'] = $dmarc;

        if (count($dmarc) === 1) {
            $result['dmarc']['status'] = 'ok';
            $result['score']++;
            $policy = '';
            if (preg_match('/(?:^|;)\s*p\s*=\s*(none|quarantine|reject)\b/i', $dmarc[0], $m)) {
                $policy = strtolower($m[1]);
            }
            $result['dmarc']['policy'] = $policy;
            $result['dmarc']['message'] = $policy !== ''
                ? 'DMARC localizado com política p=' . $policy . '.'
                : 'DMARC localizado, mas a política p= não pôde ser identificada.';
            if ($policy === 'none') {
                $result['warnings'][] = 'DMARC está em p=none: monitora, mas não rejeita/quarentena mensagens que falham.';
            }
        } elseif (count($dmarc) > 1) {
            $result['dmarc']['status'] = 'error';
            $result['dmarc']['message'] = 'Mais de um registro DMARC foi encontrado.';
            $result['errors'][] = $result['dmarc']['message'];
        } else {
            $result['dmarc']['status'] = 'warning';
            $result['dmarc']['message'] = 'Nenhum registro DMARC foi localizado em ' . $dmarcHost . '.';
            $result['warnings'][] = $result['dmarc']['message'];
        }

        $result['dkim']['host'] = $selector !== '' ? $selector . '._domainkey.' . $domain : '';

        if ($selector === '') {
            $result['dkim']['status'] = 'attention';
            $result['dkim']['message'] = 'Informe o seletor DKIM fornecido pelo provedor de e-mail para validar o registro.';
            $result['warnings'][] = $result['dkim']['message'];
        } else {
            $dkim = self::txtRecords($result['dkim']['host']);
            $result['dkim']['records'] = $dkim;

            $valid = array_values(array_filter(
                $dkim,
                static fn(string $v): bool => stripos($v, 'v=DKIM1') !== false || preg_match('/(?:^|;)\s*p\s*=\s*[A-Za-z0-9+\/=]+/i', $v)
            ));

            if ($valid) {
                $result['dkim']['status'] = 'ok';
                $result['dkim']['message'] = 'Registro DKIM localizado para o seletor ' . $selector . '.';
                $result['score']++;
            } else {
                $result['dkim']['status'] = 'warning';
                $result['dkim']['message'] = 'Nenhum DKIM válido foi localizado em ' . $result['dkim']['host'] . '.';
                $result['warnings'][] = $result['dkim']['message'];
            }
        }

        $mx = @dns_get_record($domain, DNS_MX);
        if (is_array($mx) && $mx) {
            foreach ($mx as $record) {
                $target = trim((string)($record['target'] ?? ''));
                if ($target !== '') {
                    $result['mx']['records'][] = [
                        'target' => $target,
                        'priority' => (int)($record['pri'] ?? 0),
                    ];
                }
            }
        }

        if ($result['mx']['records']) {
            $result['mx']['status'] = 'ok';
            $result['mx']['message'] = 'Registros MX localizados.';
            $result['score']++;
        } else {
            $result['mx']['status'] = 'warning';
            $result['mx']['message'] = 'Nenhum registro MX foi localizado para o domínio do remetente.';
            $result['warnings'][] = $result['mx']['message'];
        }

        return $result;
    }

    /** @return string[] */
    private static function txtRecords(string $host): array
    {
        $records = @dns_get_record($host, DNS_TXT);
        if (!is_array($records)) {
            return [];
        }

        $out = [];
        foreach ($records as $record) {
            $value = '';
            if (isset($record['txt']) && is_string($record['txt'])) {
                $value = trim($record['txt']);
            } elseif (isset($record['entries']) && is_array($record['entries'])) {
                $value = trim(implode('', array_map('strval', $record['entries'])));
            }
            if ($value !== '') {
                $out[] = $value;
            }
        }
        return array_values(array_unique($out));
    }
}
