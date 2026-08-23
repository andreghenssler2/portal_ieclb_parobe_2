<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$pdo = Database::connection();
$path = trim(currentRelativePath(), '/');
$segments = $path === ''
    ? []
    : array_values(array_filter(explode('/', $path), static fn(string $value): bool => $value !== ''));

$slug = '';
if (count($segments) >= 2) {
    $slug = rawurldecode((string)$segments[1]);
}

$event = $slug !== ''
    ? EventCalendarService::eventBySlug($pdo, $slug)
    : [];

if (!$event) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Evento não encontrado.';
    exit;
}

$ics = EventCalendarService::buildCalendarIcs([$event], (string)$event['titulo']);
$filename = EventCalendarService::downloadFilename((string)$event['slug']);

header('Content-Type: text/calendar; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($ics));
header('Cache-Control: public, max-age=300');

echo $ics;
