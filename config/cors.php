<?php
// config/cors.php

require_once __DIR__ . '/env.php';
EnvLoader::load(__DIR__ . '/../.env');

$allowedOrigin = getenv('ALLOWED_ORIGIN') ?: 'http://localhost:4200';
$requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($requestOrigin === $allowedOrigin) {
    header("Access-Control-Allow-Origin: " . $requestOrigin);
}

header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
