<?php
// config/database.php

require_once __DIR__ . '/env.php';

class Database
{
    private static $instance = null;
    private $conn;

    private function __construct()
    {
        EnvLoader::load(__DIR__ . '/../.env');

        $host = getenv('DB_HOST') ?: 'localhost';
        $db   = getenv('DB_NAME') ?: 'travel_agency_db';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: '';
        $port = getenv('DB_PORT') ?: '3306';

        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4";

        // Clean, deduplicated options array
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // Forces native MySQL prepared statements
        ];

        try {
            $this->conn = new PDO($dsn, $user, $pass, $options);

            // Set charset and collation after connection setup to avoid constant error
            $this->conn->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (PDOException $e) {
            // Fail safely without leaking credentials in stack trace
            error_log("Database Connection Error: " . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                "status" => "error",
                "message" => "Internal System Error: Database connection could not be established."
            ]);
            exit();
        }
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection()
    {
        return $this->conn;
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup()
    {
        throw new Exception("Cannot unserialize singleton");
    }
}
