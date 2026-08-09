<?php
// controllers/SettingsController.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/Response.php';

class SettingsController
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    // GET /api/admin/settings
    public function index()
    {
        $stmt = $this->db->query("SELECT setting_key, setting_value FROM site_settings");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Mask secret keys for security
        if (isset($settings['paystack_secret_key'])) {
            $settings['paystack_secret_key'] = 'sk_****' . substr($settings['paystack_secret_key'], -4);
        }

        Response::json(200, "System settings fetched", $settings);
    }

    // POST /api/admin/settings
    public function update()
    {
        $data = json_decode(file_get_contents('php://input'), true);

        if (empty($data)) {
            Response::json(400, "No settings data provided.");
        }

        $stmt = $this->db->prepare("
            INSERT INTO site_settings (setting_key, setting_value) 
            VALUES (:key, :value) 
            ON DUPLICATE KEY UPDATE setting_value = :value
        ");

        foreach ($data as $key => $value) {
            // Encrypt secret keys prior to saving
            if (strpos($key, 'secret') !== false) {
                $value = base64_encode(openssl_encrypt($value, 'AES-128-ECB', getenv('APP_SECRET_KEY')));
            }
            $stmt->execute([':key' => $key, ':value' => $value]);
        }

        Response::json(200, "Settings updated successfully.");
    }
}
