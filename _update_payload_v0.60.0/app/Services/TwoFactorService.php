<?php

declare(strict_types=1);

/**
 * Autenticação em dois fatores TOTP para o painel administrativo.
 *
 * Compatível com aplicativos que implementam RFC 6238, como:
 * Google Authenticator, Microsoft Authenticator, 2FAS e similares.
 */
final class TwoFactorService
{
    private const PERIOD = 30;
    private const DIGITS = 6;
    private const RECOVERY_COUNT = 10;
    private const RECOVERY_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function isSupported(): bool
    {
        return function_exists('sodium_crypto_secretbox')
            || function_exists('openssl_encrypt');
    }

    public static function isEnabled(PDO $pdo, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT totp_secret, totp_enabled_at
                 FROM usuarios
                 WHERE id=:id
                 LIMIT 1'
            );
            $stmt->execute(['id' => $userId]);
            $row = $stmt->fetch();

            return is_array($row)
                && trim((string)($row['totp_secret'] ?? '')) !== ''
                && !empty($row['totp_enabled_at']);
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function generateSecret(int $bytes = 20): string
    {
        $bytes = max(16, min(32, $bytes));
        return self::base32Encode(random_bytes($bytes));
    }

    public static function otpauthUri(
        string $secret,
        string $email,
        string $issuer = 'Portal IECLB Parobé'
    ): string {
        $issuer = trim($issuer) !== '' ? trim($issuer) : 'Portal IECLB Parobé';
        $email = trim($email);

        $label = rawurlencode($issuer . ':' . $email);

        return 'otpauth://totp/' . $label
            . '?secret=' . rawurlencode(self::normalizeSecret($secret))
            . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1'
            . '&digits=' . self::DIGITS
            . '&period=' . self::PERIOD;
    }

    /**
     * Ativa o 2FA depois de confirmar um código TOTP válido.
     *
     * @return string[] Códigos de recuperação em texto claro, exibidos uma única vez.
     */
    public static function enable(
        PDO $pdo,
        int $userId,
        string $secret,
        string $code
    ): array {
        if ($userId <= 0) {
            throw new RuntimeException('Usuário inválido.');
        }

        if (!self::isSupported()) {
            throw new RuntimeException(
                'O servidor precisa de Sodium ou OpenSSL para proteger a chave do 2FA.'
            );
        }

        $secret = self::normalizeSecret($secret);
        if ($secret === '') {
            throw new RuntimeException('Chave do autenticador inválida.');
        }

        $matchedStep = null;
        if (!self::verifyTotpSecret($secret, $code, $matchedStep)) {
            throw new RuntimeException(
                'O código informado não foi aceito. Confirme o horário do celular e tente novamente.'
            );
        }

        $encrypted = self::encryptSecret($secret);
        $recoveryCodes = self::generateRecoveryCodes();

        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                'UPDATE usuarios
                 SET totp_secret=:secret,
                     totp_enabled_at=NOW(),
                     totp_last_used_step=NULL
                 WHERE id=:id'
            );
            $stmt->execute([
                'secret' => $encrypted,
                'id' => $userId,
            ]);

            if ($stmt->rowCount() < 1) {
                $exists = $pdo->prepare('SELECT id FROM usuarios WHERE id=:id LIMIT 1');
                $exists->execute(['id' => $userId]);
                if (!$exists->fetchColumn()) {
                    throw new RuntimeException('Usuário não encontrado.');
                }
            }

            self::replaceRecoveryCodes($pdo, $userId, $recoveryCodes);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $recoveryCodes;
    }

    public static function disable(PDO $pdo, int $userId): void
    {
        $pdo->beginTransaction();

        try {
            $pdo->prepare(
                'UPDATE usuarios
                 SET totp_secret=NULL,
                     totp_enabled_at=NULL,
                     totp_last_used_step=NULL
                 WHERE id=:id'
            )->execute(['id' => $userId]);

            $pdo->prepare(
                'DELETE FROM usuario_2fa_recovery_codes
                 WHERE usuario_id=:id'
            )->execute(['id' => $userId]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Valida código do autenticador OU código de recuperação.
     */
    public static function verifyUserCode(
        PDO $pdo,
        int $userId,
        string $code
    ): bool {
        if ($userId <= 0) {
            return false;
        }

        $raw = trim($code);

        if (preg_match('/^\d{6}$/', $raw)) {
            return self::verifyUserTotp($pdo, $userId, $raw);
        }

        return self::consumeRecoveryCode($pdo, $userId, $raw);
    }

    /**
     * Valida somente um código TOTP, sem consumir código de recuperação.
     * Útil para operações sensíveis dentro de uma sessão já autenticada.
     */
    public static function verifyUserTotp(
        PDO $pdo,
        int $userId,
        string $code,
        bool $preventReplay = true
    ): bool {
        try {
            $stmt = $pdo->prepare(
                'SELECT totp_secret, totp_enabled_at, totp_last_used_step
                 FROM usuarios
                 WHERE id=:id
                 LIMIT 1'
            );
            $stmt->execute(['id' => $userId]);
            $row = $stmt->fetch();

            if (
                !$row
                || empty($row['totp_enabled_at'])
                || trim((string)($row['totp_secret'] ?? '')) === ''
            ) {
                return false;
            }

            $secret = self::decryptSecret((string)$row['totp_secret']);
            $matchedStep = null;

            if (!self::verifyTotpSecret($secret, $code, $matchedStep)) {
                return false;
            }

            if ($matchedStep === null) {
                return false;
            }

            if (!$preventReplay) {
                return true;
            }

            $lastStep = isset($row['totp_last_used_step'])
                ? (int)$row['totp_last_used_step']
                : null;

            if ($lastStep !== null && $matchedStep <= $lastStep) {
                return false;
            }

            $update = $pdo->prepare(
                'UPDATE usuarios
                 SET totp_last_used_step=:step
                 WHERE id=:id
                   AND (
                        totp_last_used_step IS NULL
                        OR totp_last_used_step < :step2
                   )'
            );

            $update->execute([
                'step' => $matchedStep,
                'step2' => $matchedStep,
                'id' => $userId,
            ]);

            return $update->rowCount() === 1;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * @return string[]
     */
    public static function regenerateRecoveryCodes(
        PDO $pdo,
        int $userId
    ): array {
        if (!self::isEnabled($pdo, $userId)) {
            throw new RuntimeException('A autenticação em dois fatores não está ativa.');
        }

        $codes = self::generateRecoveryCodes();

        $pdo->beginTransaction();

        try {
            self::replaceRecoveryCodes($pdo, $userId, $codes);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $codes;
    }

    public static function recoveryCodesRemaining(PDO $pdo, int $userId): int
    {
        try {
            $stmt = $pdo->prepare(
                'SELECT COUNT(*)
                 FROM usuario_2fa_recovery_codes
                 WHERE usuario_id=:id
                   AND usado_em IS NULL'
            );
            $stmt->execute(['id' => $userId]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    private static function verifyTotpSecret(
        string $secret,
        string $code,
        ?int &$matchedStep = null
    ): bool {
        $matchedStep = null;
        $code = preg_replace('/\D+/', '', $code) ?? '';

        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }

        $secretBytes = self::base32Decode($secret);
        if ($secretBytes === '') {
            return false;
        }

        $currentStep = (int)floor(time() / self::PERIOD);

        // Aceita pequena diferença de relógio: 30 s antes/depois.
        foreach ([-1, 0, 1] as $offset) {
            $step = $currentStep + $offset;
            $candidate = self::totpForStep($secretBytes, $step);

            if (hash_equals($candidate, $code)) {
                $matchedStep = $step;
                return true;
            }
        }

        return false;
    }

    private static function totpForStep(string $secretBytes, int $step): string
    {
        $high = ($step >> 32) & 0xffffffff;
        $low = $step & 0xffffffff;
        $counter = pack('N2', $high, $low);

        $hash = hash_hmac('sha1', $counter, $secretBytes, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;

        $binary =
            ((ord($hash[$offset]) & 0x7f) << 24)
            | ((ord($hash[$offset + 1]) & 0xff) << 16)
            | ((ord($hash[$offset + 2]) & 0xff) << 8)
            | (ord($hash[$offset + 3]) & 0xff);

        $otp = $binary % (10 ** self::DIGITS);

        return str_pad((string)$otp, self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * @return string[]
     */
    private static function generateRecoveryCodes(): array
    {
        $codes = [];

        for ($i = 0; $i < self::RECOVERY_COUNT; $i++) {
            $chars = '';
            $alphabetLength = strlen(self::RECOVERY_ALPHABET);

            for ($j = 0; $j < 12; $j++) {
                $chars .= self::RECOVERY_ALPHABET[
                    random_int(0, $alphabetLength - 1)
                ];
            }

            $codes[] = substr($chars, 0, 4)
                . '-'
                . substr($chars, 4, 4)
                . '-'
                . substr($chars, 8, 4);
        }

        return $codes;
    }

    /**
     * @param string[] $codes
     */
    private static function replaceRecoveryCodes(
        PDO $pdo,
        int $userId,
        array $codes
    ): void {
        $pdo->prepare(
            'DELETE FROM usuario_2fa_recovery_codes
             WHERE usuario_id=:id'
        )->execute(['id' => $userId]);

        $insert = $pdo->prepare(
            'INSERT INTO usuario_2fa_recovery_codes
                (usuario_id, codigo_hash, created_at)
             VALUES
                (:usuario_id, :codigo_hash, NOW())'
        );

        foreach ($codes as $code) {
            $hash = password_hash(
                self::normalizeRecoveryCode($code),
                PASSWORD_DEFAULT
            );

            $insert->execute([
                'usuario_id' => $userId,
                'codigo_hash' => $hash,
            ]);
        }
    }

    private static function consumeRecoveryCode(
        PDO $pdo,
        int $userId,
        string $code
    ): bool {
        $normalized = self::normalizeRecoveryCode($code);

        if (strlen($normalized) < 8) {
            return false;
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT id, codigo_hash
                 FROM usuario_2fa_recovery_codes
                 WHERE usuario_id=:id
                   AND usado_em IS NULL
                 ORDER BY id
                 LIMIT 20'
            );
            $stmt->execute(['id' => $userId]);
            $rows = $stmt->fetchAll() ?: [];

            foreach ($rows as $row) {
                if (
                    password_verify(
                        $normalized,
                        (string)$row['codigo_hash']
                    )
                ) {
                    $update = $pdo->prepare(
                        'UPDATE usuario_2fa_recovery_codes
                         SET usado_em=NOW()
                         WHERE id=:id
                           AND usado_em IS NULL'
                    );
                    $update->execute(['id' => (int)$row['id']]);

                    return $update->rowCount() === 1;
                }
            }
        } catch (Throwable $e) {
            return false;
        }

        return false;
    }

    private static function normalizeRecoveryCode(string $code): string
    {
        return strtoupper(
            preg_replace('/[^A-Z0-9]/i', '', trim($code)) ?? ''
        );
    }

    private static function normalizeSecret(string $secret): string
    {
        return strtoupper(
            preg_replace('/[^A-Z2-7]/i', '', trim($secret)) ?? ''
        );
    }

    private static function base32Encode(string $data): string
    {
        if ($data === '') {
            return '';
        }

        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $buffer = 0;
        $bitsLeft = 0;
        $output = '';

        for ($i = 0, $len = strlen($data); $i < $len; $i++) {
            $buffer = ($buffer << 8) | ord($data[$i]);
            $bitsLeft += 8;

            while ($bitsLeft >= 5) {
                $bitsLeft -= 5;
                $output .= $alphabet[($buffer >> $bitsLeft) & 31];
            }

            // Mantém apenas bits ainda úteis para evitar crescimento excessivo.
            if ($bitsLeft > 0) {
                $buffer &= (1 << $bitsLeft) - 1;
            } else {
                $buffer = 0;
            }
        }

        if ($bitsLeft > 0) {
            $output .= $alphabet[($buffer << (5 - $bitsLeft)) & 31];
        }

        return $output;
    }

    private static function base32Decode(string $encoded): string
    {
        $encoded = self::normalizeSecret($encoded);

        if ($encoded === '') {
            return '';
        }

        $alphabet = array_flip(
            str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567')
        );

        $buffer = 0;
        $bitsLeft = 0;
        $output = '';

        for ($i = 0, $len = strlen($encoded); $i < $len; $i++) {
            $char = $encoded[$i];

            if (!isset($alphabet[$char])) {
                return '';
            }

            $buffer = ($buffer << 5) | $alphabet[$char];
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xff);

                if ($bitsLeft > 0) {
                    $buffer &= (1 << $bitsLeft) - 1;
                } else {
                    $buffer = 0;
                }
            }
        }

        return $output;
    }

    private static function encryptSecret(string $plain): string
    {
        $key = self::secretKey(true);

        if (function_exists('sodium_crypto_secretbox')) {
            $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
            $cipher = sodium_crypto_secretbox($plain, $nonce, $key);

            return 's1:' . base64_encode($nonce . $cipher);
        }

        if (function_exists('openssl_encrypt')) {
            $iv = random_bytes(12);
            $tag = '';

            $cipher = openssl_encrypt(
                $plain,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($cipher === false) {
                throw new RuntimeException(
                    'Não foi possível criptografar a chave do 2FA.'
                );
            }

            return 'o1:' . base64_encode($iv . $tag . $cipher);
        }

        throw new RuntimeException(
            'O servidor precisa da extensão Sodium ou OpenSSL.'
        );
    }

    private static function decryptSecret(string $encoded): string
    {
        $key = self::secretKey(false);

        if ($key === '') {
            throw new RuntimeException(
                'A chave privada do 2FA não foi encontrada.'
            );
        }

        if (str_starts_with($encoded, 's1:')) {
            if (!function_exists('sodium_crypto_secretbox_open')) {
                throw new RuntimeException(
                    'A extensão Sodium necessária para o 2FA não está disponível.'
                );
            }

            $raw = base64_decode(substr($encoded, 3), true);

            if (
                $raw === false
                || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
            ) {
                throw new RuntimeException('Chave 2FA criptografada inválida.');
            }

            $nonce = substr(
                $raw,
                0,
                SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
            );

            $cipher = substr(
                $raw,
                SODIUM_CRYPTO_SECRETBOX_NONCEBYTES
            );

            $plain = sodium_crypto_secretbox_open(
                $cipher,
                $nonce,
                $key
            );

            if ($plain === false) {
                throw new RuntimeException(
                    'Não foi possível descriptografar a chave do 2FA.'
                );
            }

            return $plain;
        }

        if (str_starts_with($encoded, 'o1:')) {
            if (!function_exists('openssl_decrypt')) {
                throw new RuntimeException(
                    'A extensão OpenSSL necessária para o 2FA não está disponível.'
                );
            }

            $raw = base64_decode(substr($encoded, 3), true);

            if ($raw === false || strlen($raw) <= 28) {
                throw new RuntimeException('Chave 2FA criptografada inválida.');
            }

            $iv = substr($raw, 0, 12);
            $tag = substr($raw, 12, 16);
            $cipher = substr($raw, 28);

            $plain = openssl_decrypt(
                $cipher,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag
            );

            if ($plain === false) {
                throw new RuntimeException(
                    'Não foi possível descriptografar a chave do 2FA.'
                );
            }

            return $plain;
        }

        throw new RuntimeException(
            'Formato da chave 2FA desconhecido.'
        );
    }

    private static function secretKey(bool $create): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/private';
        $file = $dir . '/totp.key';

        if (
            !is_dir($dir)
            && $create
            && !@mkdir($dir, 0700, true)
            && !is_dir($dir)
        ) {
            throw new RuntimeException(
                'Não foi possível criar storage/private para o 2FA.'
            );
        }

        if (is_file($file)) {
            $raw = base64_decode(
                trim((string)file_get_contents($file)),
                true
            );

            if ($raw !== false && strlen($raw) === 32) {
                return $raw;
            }

            throw new RuntimeException(
                'A chave privada do 2FA é inválida.'
            );
        }

        if (!$create) {
            return '';
        }

        $key = random_bytes(32);

        if (
            @file_put_contents(
                $file,
                base64_encode($key) . PHP_EOL,
                LOCK_EX
            ) === false
        ) {
            throw new RuntimeException(
                'Não foi possível gravar a chave privada do 2FA.'
            );
        }

        @chmod($file, 0600);

        $htaccess = $dir . '/.htaccess';

        if (!is_file($htaccess)) {
            @file_put_contents(
                $htaccess,
                "Require all denied\nDeny from all\n",
                LOCK_EX
            );
        }

        return $key;
    }
}
