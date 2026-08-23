<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$pdo = Database::connection();
$filters = EventCalendarService::filters($_GET);
$monthRaw = trim((string)($_GET['mes'] ?? ''));

if ($monthRaw !== '') {
    $month = EventCalendarService::normalizeMonth($monthRaw);
    $events = EventCalendarService::monthEvents($pdo, $month, $filters);
    $info = EventCalendarService::monthInfo($month);
    $calendarName = 'Agenda IECLB Parobé - ' . $info['label'];
    $filename = EventCalendarService::downloadFilename('agenda-ieclb-parobe-' . $month);
} else {
    $events = EventCalendarService::upcomingEvents($pdo, $filters, 500);
    $calendarName = 'Agenda IECLB Parobé';
    $filename = EventCalendarService::downloadFilename('agenda-ieclb-parobe');
}

$ics = EventCalendarService::buildCalendarIcs($events, $calendarName);

header('Content-Type: text/calendar; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($ics));
header('Cache-Control: public, max-age=300');

echo $ics;
