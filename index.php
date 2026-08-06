<?php
// index.php - Central REST API Router

// 1. Core Bootstrapping
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/cors.php';
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/helpers/Response.php';

// Load Environment Variables
try {
    EnvLoader::load(__DIR__ . '/.env');
} catch (Exception $e) {
    Response::json(500, "Server Configuration Error: Unable to load environment settings.");
}

// 2. Parse Incoming Request URI and HTTP Method
$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Strip query string (e.g., /api/auth/me?ref=header -> /api/auth/me)
$path = parse_url($requestUri, PHP_URL_PATH) ?? '/';

// Dynamically strip base subfolder path (e.g., /travel-agency-backend)
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
$baseFolder = rtrim($scriptDir, '/');

if (!empty($baseFolder) && strpos($path, $baseFolder) === 0) {
    $path = substr($path, strlen($baseFolder));
}

// Ensure clean leading slash and strip trailing slash
$path = '/' . trim($path, '/');

// Standardize root & index.php paths
if ($path === '/index.php' || $path === '') {
    $path = '/';
}

// 3. Central Route Definitions
// Structure: [ HTTP_METHOD, URI_PATTERN, CONTROLLER_CLASS@METHOD_NAME ]
$routes = [
    // Health Check & Root Endpoints
    ['GET',  '/',                  'HealthController@check'],
    ['GET',  '/api/health',        'HealthController@check'],

    // Phase 2: Authentication Routes
    ['POST', '/api/auth/register', 'AuthController@register'],
    ['POST', '/api/auth/login',    'AuthController@login'],
    ['GET',  '/api/auth/me',       'AuthController@me'], // Protected route

    // Phase 3: Packages & Catalog Routes
    ['GET',  '/api/packages',      'PackageController@index'],
    ['GET',  '/api/packages/{id}', 'PackageController@show'],

    // Phase 5: Photo Storage Routes
    ['POST', '/api/photos/upload', 'PhotoController@upload'],

    // Phase 3.2: Admin Package & Schedule Management Routes
    ['POST', '/api/admin/packages',                  'PackageController@createPackage'],
    ['POST', '/api/admin/packages/{id}/schedules',   'PackageController@addSchedule'],
    ['POST', '/api/admin/packages/{id}/itineraries', 'PackageController@addItinerary'],
    ['POST', '/api/bookings',                         'BookingController@create'],

    // Phase 4.2: Booking Query Routes
    ['GET',  '/api/bookings',      'BookingController@index'],
    ['GET',  '/api/bookings/{id}', 'BookingController@show'],

    // Destination Routes
    ['GET',  '/api/destinations',       'DestinationController@index'],
    ['POST', '/api/admin/destinations', 'DestinationController@create'],

    // Booking Cancellation Route
    ['PATCH', '/api/bookings/{id}/cancel', 'BookingController@cancel'],

    // Gap D: User Settings & Security Routes
    ['PUT',  '/api/auth/me',              'AuthController@updateProfile'],
    ['PUT',  '/api/auth/change-password', 'AuthController@changePassword'],

    // Email Verification Routes
    ['GET',  '/api/auth/verify-email',        'AuthController@verifyEmail'],
    ['POST', '/api/auth/resend-verification', 'AuthController@resendVerification'],

    // Phase 6: Payment Engine Routes
    ['POST', '/api/payments/initialize',          'PaymentController@initialize'],
    ['GET',  '/api/payments/verify/{reference}',  'PaymentController@verify'],
    ['POST', '/api/payments/webhook',             'PaymentController@webhook'],

    // Module 7.2: Admin Analytics Routes
    ['GET',  '/api/admin/stats', 'AdminController@stats'],

    // Module 7.3: Reviews & Ratings Routes
    ['POST', '/api/reviews',              'ReviewController@create'],
    ['GET',  '/api/packages/{id}/reviews', 'ReviewController@getPackageReviews'],
];

$routeMatched = false;

// 4. Match and Execute Route
foreach ($routes as $route) {
    list($method, $pattern, $handler) = $route;

    if ($requestMethod !== $method) {
        continue;
    }

    // Convert curly brace placeholders (e.g., {id}) into Regex capturing groups
    $regexPattern = preg_replace('/\{([a-zA-Z0-9_]+)\}/', '([^/]+)', $pattern);
    $regexPattern = '#^' . $regexPattern . '$#';

    if (preg_match($regexPattern, $path, $matches)) {
        array_shift($matches); // Remove full string match, leave dynamic URL parameters

        list($controllerName, $action) = explode('@', $handler);
        $controllerFile = __DIR__ . '/controllers/' . $controllerName . '.php';

        if (!file_exists($controllerFile)) {
            Response::json(500, "Internal Server Error: Controller file missing ({$controllerName}).");
        }

        require_once $controllerFile;

        if (!class_exists($controllerName)) {
            Response::json(500, "Internal Server Error: Controller class '{$controllerName}' not found.");
        }

        $controllerInstance = new $controllerName();

        if (!method_exists($controllerInstance, $action)) {
            Response::json(500, "Internal Server Error: Controller action '{$action}' not found.");
        }

        // Execute controller method passing regex URL parameters
        call_user_func_array([$controllerInstance, $action], $matches);
        $routeMatched = true;
        break;
    }
}

// 5. Unmatched Endpoints (404 Fallback)
if (!$routeMatched) {
    Response::json(404, "Endpoint not found: [{$requestMethod}] {$path}");
}
