<?php

require_once dirname(__DIR__, 2) . '/core/config.php';
require_once APP_PATH . '/core/auth.php';

requireLogin();

session_unset();
session_destroy();

$redirectUrl = BASE_URL;

echo "<!DOCTYPE html>
<html lang='pt-BR'>
<head>
    <meta charset='utf-8'>
    <meta http-equiv='refresh' content='0;url={$redirectUrl}'>
    <script>window.location.href = '{$redirectUrl}';</script>
</head>
<body></body>
</html>";
