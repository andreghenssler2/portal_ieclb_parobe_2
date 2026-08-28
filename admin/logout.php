<?php
require_once __DIR__ . '/../bootstrap.php';
Auth::logout();
header('Location: ' . url('admin/login.php'));
exit;
