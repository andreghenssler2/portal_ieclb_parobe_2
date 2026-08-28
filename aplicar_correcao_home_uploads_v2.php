<?php

declare(strict_types=1);

/**
 * Correção tolerante para HomeService.php
 * Resolve imagens que podem existir em:
 *   /uploads/...
 *   /public/uploads/...
 *
 * Execute na raiz do projeto:
 *   php aplicar_correcao_home_uploads_v2.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Execute este arquivo pela linha de comando.\n");
    exit(1);
}

$target = __DIR__ . '/app/Services/HomeService.php';

if (!is_file($target)) {
    fwrite(STDERR, "Arquivo não encontrado: {$target}\n");
    exit(1);
}

$content = file_get_contents($target);
if ($content === false) {
    fwrite(STDERR, "Não foi possível ler HomeService.php.\n");
    exit(1);
}

if (
    str_contains($content, '$rootCandidate = $root . \'/\' . $tail;') &&
    str_contains($content, '$publicCandidate = $root . \'/public/\' . $tail;')
) {
    echo "A correção já está aplicada neste HomeService.php.\n";
    exit(0);
}

$backup = $target . '.bak-' . date('Ymd-His');

if (!copy($target, $backup)) {
    fwrite(STDERR, "Não foi possível criar backup: {$backup}\n");
    exit(1);
}

/*
 * Caso mais comum nas versões v0.44.x:
 *
 * if (str_starts_with(strtolower($tail), 'uploads/')) {
 *     $tail = 'public/' . $tail;
 * }
 *
 * A substituição abaixo é tolerante a espaços/quebras de linha.
 */
$pattern = '~if\s*\(\s*str_starts_with\s*\(\s*strtolower\s*\(\s*\$tail\s*\)\s*,\s*[\'"]uploads/[\'"]\s*\)\s*\)\s*\{\s*\$tail\s*=\s*[\'"]public/[\'"]\s*\.\s*\$tail\s*;\s*\}~s';

$replacement = <<<'PHP'
if (str_starts_with(strtolower($tail), 'uploads/')) {
                    // Compatibilidade: algumas mídias estão em /uploads/
                    // e outras em /public/uploads/. Usa o arquivo que
                    // realmente existir no servidor.
                    $cleanTail = ltrim($tail, '/');
                    $rootCandidate = $root . '/' . $cleanTail;
                    $publicCandidate = $root . '/public/' . $cleanTail;

                    if (is_file($rootCandidate)) {
                        $tail = $cleanTail;
                    } elseif (is_file($publicCandidate)) {
                        $tail = 'public/' . $cleanTail;
                    } else {
                        // Se não for possível confirmar no disco,
                        // preserva /uploads/ em vez de forçar /public/uploads/.
                        $tail = $cleanTail;
                    }
                }
PHP;

$updated = preg_replace($pattern, $replacement, $content, 1, $count);

if (!is_string($updated)) {
    fwrite(STDERR, "Falha ao processar o arquivo.\n");
    @unlink($backup);
    exit(1);
}

/*
 * Fallback para versões em que o trecho está comprimido ou levemente diferente.
 * Só tenta se a primeira regra não encontrou nada.
 */
if ($count === 0) {
    $needle = '$tail = \'public/\' . $tail;';
    $pos = strpos($content, $needle);

    if ($pos !== false) {
        $replacement2 = <<<'PHP'
$cleanTail = ltrim($tail, '/');
                    $rootCandidate = $root . '/' . $cleanTail;
                    $publicCandidate = $root . '/public/' . $cleanTail;

                    if (is_file($rootCandidate)) {
                        $tail = $cleanTail;
                    } elseif (is_file($publicCandidate)) {
                        $tail = 'public/' . $cleanTail;
                    } else {
                        $tail = $cleanTail;
                    }
PHP;
        $updated = substr_replace($content, $replacement2, $pos, strlen($needle));
        $count = 1;
    }
}

if ($count === 0) {
    @unlink($backup);

    fwrite(STDERR, "\nNão encontrei o trecho que força uploads/ para public/uploads/.\n");
    fwrite(STDERR, "Seu HomeService.php está diferente da versão do GitHub.\n");
    fwrite(STDERR, "Nesse caso, envie aqui o arquivo app/Services/HomeService.php do seu servidor e eu corrijo exatamente essa versão.\n");
    exit(2);
}

if (file_put_contents($target, $updated) === false) {
    fwrite(STDERR, "Não foi possível gravar HomeService.php.\n");
    fwrite(STDERR, "O backup ficou em: {$backup}\n");
    exit(1);
}

echo "Correção aplicada com sucesso.\n";
echo "Arquivo: {$target}\n";
echo "Backup: {$backup}\n";
echo "\nAgora:\n";
echo "1. Limpe o cache do Portal/Home, se estiver ativo.\n";
echo "2. Atualize a página com Ctrl+F5.\n";
echo "3. Teste uma imagem em /uploads/ e outra em /public/uploads/.\n";
