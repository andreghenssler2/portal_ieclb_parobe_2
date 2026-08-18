<?php
/**
 * Front controller leve para links permanentes configuráveis (v0.12.0).
 * O .htaccess envia somente caminhos que não são arquivos/diretórios reais.
 */
require_once __DIR__ . '/bootstrap.php';
$pdo = Database::connection();
$path = trim(currentRelativePath(), '/');
$segments = $path === '' ? [] : array_values(array_filter(explode('/', $path), static fn($v) => $v !== ''));

if (count($segments) === 1) {
    $alias = strtolower(rawurldecode((string)$segments[0]));
    $static = [
        'agenda' => 'agenda.php',
        'galerias' => 'galerias.php',
        'comunidades' => 'comunidades.php',
    ];
    if (isset($static[$alias])) {
        require __DIR__ . '/' . $static[$alias];
        exit;
    }
    if (permalinkPrefix('pagina', $pdo) === '') {
        require __DIR__ . '/pagina.php';
        exit;
    }
}

if (count($segments) === 2) {
    $prefix = strtolower(rawurldecode((string)$segments[0]));
    $routes = [];
    foreach (['noticia','pagina','evento','galeria','formulario'] as $type) {
        $configured = permalinkPrefix($type, $pdo);
        if ($configured !== '') $routes[$configured] = $type . '.php';
        // Prefixos históricos continuam reconhecidos para redirecionamento canônico.
        $routes[$type] = $type . '.php';
    }
    if (isset($routes[$prefix])) {
        require __DIR__ . '/' . $routes[$prefix];
        exit;
    }
}

http_response_code(404);
$metaTitle = 'Página não encontrada';
$metaDescription = 'O endereço solicitado não foi encontrado.';
$metaNoindex = true;
require themeFile($pdo, 'header.php');
echo '<div class="container py-5"><h1 class="h2">Página não encontrada</h1><p class="text-secondary">O endereço solicitado não existe ou foi removido.</p><a class="btn btn-primary" href="' . e(url()) . '">Voltar ao início</a></div>';
require themeFile($pdo, 'footer.php');
