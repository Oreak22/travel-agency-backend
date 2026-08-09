<?php
// backend/utils/response.php

function setCorsHeaders(): void
{
    $allowedOrigins = ['http://localhost:4200', 'http://127.0.0.1:4200'];
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';

    if (in_array($origin, $allowedOrigins, true)) {
        header("Access-Control-Allow-Origin: {$origin}");
    } else {
        header("Access-Control-Allow-Origin: http://localhost:4200");
    }

    header("Access-Control-Allow-Credentials: true");
    header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
    header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
    header("Content-Type: application/json; charset=UTF-8");

    // Intercept OPTIONS preflight requests
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit();
    }
}

function sendResponse(bool $success, string $message, mixed $data = null, int $statusCode = 200): void
{
    http_response_code($statusCode);

    $response = [
        'success' => $success,
        'message' => $message
    ];

    if ($data !== null) {
        $response['data'] = $data;
    }

    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit();
}

function getJsonInput(): array
{
    $rawInput = file_get_contents('php://input');
    $data = json_decode($rawInput, true);
    return is_array($data) ? $data : [];
}
