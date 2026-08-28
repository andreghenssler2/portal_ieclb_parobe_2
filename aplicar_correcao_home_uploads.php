<?php

declare(strict_types=1);

/**
 * Correção pontual da Home modular do Portal IECLB Parobé.
 *
 * Execute pela linha de comando, na raiz do projeto:
 *     php aplicar_correcao_home_uploads.php
 *
 * O script cria um backup .bak antes de alterar o arquivo.
 */
if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Por segurança, execute este arquivo apenas pela linha de comando.\n");
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

$old = <<<'OLD'
        // 3) Último recurso para uploads: elimina qualquer prefixo físico até
        // public/uploads, preservando somente o caminho que o navegador entende.
        $normalizedLower = strtolower($value);
        foreach (['public/uploads/', 'uploads/wordpress/', 'uploads/'] as $marker) {
            $pos = strpos($normalizedLower, $marker);
            if ($pos !== false) {
                $tail = substr($value, $pos);
                if (str_starts_with(strtolower($tail), 'uploads/')) {
                    $tail = 'public/' . $tail;
                }
                $value = $tail;
                break;
            }
        }
OLD;

$new = <<<'NEW'
        // 3) Compatibilidade de uploads.
        // O Portal pode ter arquivos tanto em /uploads/ quanto em
        // /public/uploads/. Não force "public/": detecte onde o arquivo
        // realmente existe e gere a URL correspondente.
        $normalizedLower = strtolower($value);

        $uploadPos = strpos($normalizedLower, 'public/uploads/');
        if ($uploadPos === false) {
            $uploadPos = strpos($normalizedLower, 'uploads/');
        }

        if ($uploadPos !== false) {
            $tail = substr($value, $uploadPos);

            // Trabalha internamente sempre com uploads/...
            $tail = preg_replace('~^public/~i', '', $tail) ?? $tail;
            $tail = ltrim($tail, '/');

            $rootCandidate = $root . '/' . $tail;
            $publicCandidate = $root . '/public/' . $tail;

            if (is_file($rootCandidate)) {
                // Arquivo em /uploads/...
                $value = $tail;
            } elseif (is_file($publicCandidate)) {
                // Arquivo em /public/uploads/...
                $value = 'public/' . $tail;
            } else {
                // Se o arquivo não puder ser confirmado no disco, mantém
                // a origem informada em vez de trocar o diretório à força.
                $originalHadPublic = str_contains($normalizedLower, 'public/uploads/');
                $value = $originalHadPublic ? 'public/' . $tail : $tail;
            }
        }
NEW;

if (!str_contains($content, $old)) {
    if (str_contains($content, "Compatibilidade de uploads.")) {
        echo "A correção já parece estar aplicada. Nenhuma alteração foi feita.\n";
        exit(0);
    }

    fwrite(
        STDERR,
        "O trecho esperado não foi encontrado. O arquivo pode ser de outra versão.\n" .
        "Use o patch manual fix-home-uploads.patch ou compare o HomeService.php.\n"
    );
    exit(1);
}

$backup = $target . '.bak-' . date('Ymd-His');
if (!copy($target, $backup)) {
    fwrite(STDERR, "Não foi possível criar o backup: {$backup}\n");
    exit(1);
}

$updated = str_replace($old, $new, $content, $count);
if ($count !== 1) {
    fwrite(STDERR, "Quantidade inesperada de substituições: {$count}.\n");
    exit(1);
}

if (file_put_contents($target, $updated) === false) {
    fwrite(STDERR, "Não foi possível gravar HomeService.php.\n");
    exit(1);
}

echo "Correção aplicada com sucesso.\n";
echo "Arquivo alterado: {$target}\n";
echo "Backup criado: {$backup}\n";
echo "Agora limpe o cache da Home e teste novamente.\n";
