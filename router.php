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
        'agenda.ics' => 'agenda-ics.php',
        'galerias' => 'galerias.php',
        'comunidades' => 'comunidades.php',
        'grupos' => 'grupos.php',
        'busca' => 'busca.php',
        'mais-lidas' => 'mais-lidas.php',
        'newsletter' => 'newsletter.php',
        'documentos' => 'documentos.php',
        'liderancas' => 'liderancas.php',
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
// v0.35.0 - download de documento por caminho amigável.
if (count($segments) === 3) {
    $documentPrefix = permalinkPrefix('documento', $pdo);
    $first = strtolower(rawurldecode((string)$segments[0]));
    if (($first === $documentPrefix || $first === 'documento') && strtolower((string)$segments[2]) === 'baixar') {
        require __DIR__ . '/documento-baixar.php';
        exit;
    }
}
if (count($segments) === 3 && strtolower(rawurldecode((string)$segments[0])) === 'newsletter' && in_array(strtolower((string)$segments[1]), ['confirmar','cancelar'], true)) {
    require __DIR__ . '/newsletter.php';
    exit;
}

if (count($segments) === 2 && strtolower(rawurldecode((string)$segments[0])) === 'tag') {
    require __DIR__ . '/tag.php';
    exit;
}

if (count($segments) === 2 && strtolower(rawurldecode((string)$segments[0])) === 'categoria') {
    require __DIR__ . '/categoria.php';
    exit;
}

// v0.43.0 - páginas hierárquicas com prefixo.
// Ex.: /pagina/quem-somos/historia ou /institucional/quem-somos/historia.
$pagePrefix = permalinkPrefix('pagina', $pdo);
if ($pagePrefix !== '' && count($segments) >= 2) {
    $first = strtolower(rawurldecode((string)$segments[0]));
    if ($first === strtolower($pagePrefix) || $first === 'pagina') {
        require __DIR__ . '/pagina.php';
        exit;
    }
}

if (count($segments) === 2) {
    $prefix = strtolower(rawurldecode((string)$segments[0]));
    $routes = [];

    // IMPORTANTE: esta lista deve conter somente tipos aceitos por permalinkPrefix().
    // "comunidade" e "grupo" não possuem permalinkPrefix/controlador individual e,
    // quando eram passados para a função, causavam Fatal error em qualquer URL de 2 segmentos.
    foreach (['noticia','pagina','evento','galeria','formulario','documento','lideranca'] as $type) {
        $configured = permalinkPrefix($type, $pdo);
        if ($configured !== '') {
            $routes[$configured] = $type . '.php';
        }
        // Prefixos históricos continuam reconhecidos para redirecionamento canônico.
        $routes[$type] = $type . '.php';
    }

    if (isset($routes[$prefix])) {
        require __DIR__ . '/' . $routes[$prefix];
        exit;
    }
}

// v0.43.0 - páginas hierárquicas na raiz quando "pagina" usa __root__.
if ($pagePrefix === '' && count($segments) >= 1) {
    require __DIR__ . '/pagina.php';
    exit;
}

http_response_code(404);
$metaTitle = 'Página não encontrada';
$metaDescription = 'O endereço solicitado não foi encontrado.';
$metaNoindex = true;
require themeFile($pdo, 'header.php');
echo '<div class="container py-5"><h1 class="h2">Página não encontrada</h1><p class="text-secondary">O endereço solicitado não existe ou foi removido.</p><a class="btn btn-primary" href="' . e(url()) . '">Voltar ao início</a></div>';
require themeFile($pdo, 'footer.php');
