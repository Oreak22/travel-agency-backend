<?php
// controllers/HealthController.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/Response.php';

class HealthController
{

    public function check()
    {
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->query("SELECT 1");

            // Helper function to safely pull variables from getenv(), $_ENV, or $_SERVER
            $getEnvVar = function ($key) {
                return getenv($key) ?: ($_ENV[$key] ?? ($_SERVER[$key] ?? null));
            };

            Response::json(200, "Travel Agency API is online and database connection is healthy.", [
                "api_status" => "active",
                "database"   => "connected",
                "environment" => $getEnvVar('APP_ENV') ?: 'development',
                "env_check"  => [
                    "db_host_loaded"  => !empty($getEnvVar('DB_HOST')),
                    "db_name_loaded"  => !empty($getEnvVar('DB_NAME')),
                    "jwt_secret_set"  => !empty($getEnvVar('JWT_SECRET')),
                    // Safe debug preview (shows existence without revealing sensitive full strings)
                    "db_host_preview" => $getEnvVar('DB_HOST') ? substr($getEnvVar('DB_HOST'), 0, 8) . '...' : 'NOT_FOUND',
                ]
            ]);
        } catch (Exception $e) {
            Response::json(500, "Database health check failed.", null, [$e->getMessage()]);
        }
    }
}
