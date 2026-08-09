<?php
// controllers/MediaController.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/Response.php';

class MediaController
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    // GET /api/admin/media
    public function index()
    {
        $stmt = $this->db->query("SELECT * FROM media_assets ORDER BY created_at DESC");
        Response::json(200, "Media inventory fetched", $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // POST /api/admin/media/upload (Generates Cloudinary Signature)
    public function generateSignature()
    {
        $timestamp = time();
        $apiSecret = getenv('CLOUDINARY_API_SECRET');
        $apiKey = getenv('CLOUDINARY_API_KEY');
        $cloudName = getenv('CLOUDINARY_CLOUD_NAME');

        $paramsToSign = ['timestamp' => $timestamp];
        ksort($paramsToSign);

        $stringToSign = "";
        foreach ($paramsToSign as $key => $value) {
            $stringToSign .= "{$key}={$value}&";
        }
        $stringToSign = rtrim($stringToSign, '&') . $apiSecret;
        $signature = sha1($stringToSign);

        Response::json(200, "Cloudinary upload signature generated", [
            'signature'  => $signature,
            'timestamp'  => $timestamp,
            'api_key'    => $apiKey,
            'cloud_name' => $cloudName
        ]);
    }

    // DELETE /api/admin/media/delete
    public function delete()
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $mediaId = $data['id'] ?? null;

        if (!$mediaId) {
            Response::json(400, "Media asset ID required.");
        }

        $stmt = $this->db->prepare("DELETE FROM media_assets WHERE id = :id");
        $stmt->execute([':id' => $mediaId]);

        Response::json(200, "Asset record removed successfully.");
    }
}
