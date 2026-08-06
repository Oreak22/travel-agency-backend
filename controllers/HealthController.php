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

            Response::json(200, "Travel Agency API is online and database connection is healthy.", [
                "api_status" => "active",
                "database"   => "connected",
                "environment" => getenv('APP_ENV') ?: 'development'
            ]);
        } catch (Exception $e) {
            Response::json(500, "Database health check failed.", null, [$e->getMessage()]);
        }
    }
}
