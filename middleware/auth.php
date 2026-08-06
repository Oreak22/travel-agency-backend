<?php
// middleware/auth.php

require_once __DIR__ . '/../helpers/JWT.php';
require_once __DIR__ . '/../helpers/Response.php';

class AuthMiddleware
{

    /**
     * Authenticate the JWT token from HTTP request headers.
     *
     * @param array $allowedRoles Array of permitted roles (e.g., ['admin', 'agent']). Empty array allows any authenticated user.
     * @return array Decoded user payload from token
     */
    public static function authenticate(array $allowedRoles = []): array
    {
        $headers = null;

        // Extract Authorization header across different server environments
        if (isset($_SERVER['Authorization'])) {
            $headers = trim($_SERVER["Authorization"]);
        } else if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
        } else if (function_exists('apache_request_headers')) {
            $requestHeaders = apache_request_headers();
            $requestHeaders = array_combine(
                array_map('ucwords', array_keys($requestHeaders)),
                array_values($requestHeaders)
            );
            if (isset($requestHeaders['Authorization'])) {
                $headers = trim($requestHeaders['Authorization']);
            }
        }

        // 1. Verify Header Presence
        if (empty($headers)) {
            Response::json(401, "Access Denied: Missing Authorization Header.");
        }

        // 2. Validate Bearer Token Syntax
        if (!preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
            Response::json(401, "Access Denied: Invalid Authorization Header format. Expected 'Bearer <token>'.");
        }

        $jwtToken = $matches[1];

        // 3. Decode Token Signature & Expiration
        try {
            $decodedUser = JWT::decode($jwtToken);
        } catch (Exception $e) {
            Response::json(401, "Authentication Failed: " . $e->getMessage());
        }

        // 4. Role-Based Access Control Check (RBAC)
        if (!empty($allowedRoles)) {
            $userRole = $decodedUser['role'] ?? null;

            if (!$userRole || !in_array($userRole, $allowedRoles, true)) {
                Response::json(403, "Forbidden: You do not have the required permissions to perform this action.");
            }
        }

        // Return validated user context to the controller
        return $decodedUser;
    }
}
