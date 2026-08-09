<?php
// config/cors.php

require_once __DIR__ . '/env.php';
EnvLoader::load(__DIR__ . '/../.env');

function handleCors()
{
    $envOrigin = getenv('ALLOWED_ORIGIN') ?: 'http://localhost:4200';
    $allowedOrigins = array_unique([$envOrigin, 'http://localhost:4200', 'http://127.0.0.1:4200']);

    $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if (in_array($requestOrigin, $allowedOrigins, true)) {
        header("Access-Control-Allow-Origin: " . $requestOrigin);
    } else {
        header("Access-Control-Allow-Origin: http://localhost:4200");
    }

    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
    header("Content-Type: application/json; charset=UTF-8");

    // Intercept and exit early on preflight OPTIONS requests
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit();
    }
}

handleCors();
