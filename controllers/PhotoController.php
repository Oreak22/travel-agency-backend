<?php
// controllers/PhotoController.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/Cloudinary.php';
require_once __DIR__ . '/../middleware/auth.php';

class PhotoController
{

    private $db;
    private $allowedMimeTypes;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();

        // Strict Magic-Byte MIME Whitelist
        $this->allowedMimeTypes = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp'
        ];
    }

    /**
     * POST /api/photos/upload
     * Cloudinary CDN Photo Upload & DB Record Insertion
     * Protected: All Authenticated Users
     */
    public function upload()
    {
        $currentUser = AuthMiddleware::authenticate();
        $userId   = (int)$currentUser['sub'];
        $userRole = $currentUser['role'];

        if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            $errorCode = $_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE;
            Response::json(400, "File Upload Error: No file uploaded or upload failed.", null, [
                'upload_error_code' => $errorCode
            ]);
        }

        $file      = $_FILES['photo'];
        $packageId = (int)($_POST['package_id'] ?? 0);
        $caption   = trim($_POST['caption'] ?? '');
        $photoType = strtolower(trim($_POST['photo_type'] ?? 'gallery'));

        if ($packageId <= 0) {
            Response::json(422, "Validation Error.", null, ['package_id' => "A valid package ID is required."]);
        }

        $validTypes = ['cover', 'marketing', 'gallery'];
        if (!in_array($photoType, $validTypes, true)) {
            Response::json(422, "Validation Error.", null, ['photo_type' => "Photo type must be cover, marketing, or gallery."]);
        }

        // Max Size 5MB
        $maxSizeBytes = 5 * 1024 * 1024;
        if ($file['size'] > $maxSizeBytes) {
            Response::json(422, "Validation Error: File size exceeds maximum limit of 5MB.");
        }

        // MIME Validation via Fileinfo Magic Bytes
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($file['tmp_name']);

        if (!array_key_exists($detectedMime, $this->allowedMimeTypes)) {
            Response::json(415, "Unsupported Media Type: Only JPG, PNG, and WEBP images are permitted.", null, [
                'detected_mime' => $detectedMime
            ]);
        }

        // Verify Target Package Exists
        try {
            $pkgStmt = $this->db->prepare("SELECT id FROM packages WHERE id = :id LIMIT 1");
            $pkgStmt->execute([':id' => $packageId]);
            if (!$pkgStmt->fetch()) {
                Response::json(404, "Package not found.");
            }
        } catch (Exception $e) {
            Response::json(500, "Database Error during package check.");
        }

        // --- UPLOAD TO CLOUDINARY ---
        try {
            $cloudinaryResult = CloudinaryHelper::upload($file['tmp_name'], 'travel_agency/packages');
            $publicPhotoUrl   = $cloudinaryResult['secure_url'];
            $cloudinaryId     = $cloudinaryResult['public_id'];
        } catch (Exception $e) {
            error_log("Cloudinary Upload Error: " . $e->getMessage());
            Response::json(500, "Media Storage Error: Could not upload file to Cloudinary CDN.");
        }

        // Approval Workflow: Admins/Agents auto-approve; Travelers require moderation
        $isApproved = in_array($userRole, ['admin', 'agent'], true) ? 1 : 0;

        try {
            if ($photoType === 'cover' && $isApproved === 1) {
                $unsetStmt = $this->db->prepare("UPDATE package_photos SET photo_type = 'gallery' WHERE package_id = :package_id AND photo_type = 'cover'");
                $unsetStmt->execute([':package_id' => $packageId]);
            }

            // Store full HTTPS Cloudinary URL in DB
            $sql = "INSERT INTO package_photos (package_id, user_id, photo_url, caption, photo_type, is_approved) 
                    VALUES (:package_id, :user_id, :photo_url, :caption, :photo_type, :is_approved)";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':package_id'  => $packageId,
                ':user_id'     => $userId,
                ':photo_url'   => $publicPhotoUrl,
                ':caption'     => !empty($caption) ? $caption : null,
                ':photo_type'  => $photoType,
                ':is_approved' => $isApproved
            ]);

            $photoId = (int)$this->db->lastInsertId();

            $message = ($isApproved === 1)
                ? "Photo uploaded to Cloudinary and published successfully."
                : "Photo uploaded to Cloudinary successfully. Pending administrator moderation.";

            Response::json(201, $message, [
                'photo_id'      => $photoId,
                'package_id'    => $packageId,
                'photo_url'     => $publicPhotoUrl,
                'cloudinary_id' => $cloudinaryId,
                'photo_type'    => $photoType,
                'is_approved'   => (bool)$isApproved
            ]);
        } catch (Exception $e) {
            error_log("Photo Controller Database Error: " . $e->getMessage());
            Response::json(500, "Internal Server Error: Could not record photo details.");
        }
    }

    /**
     * DELETE /api/photos/{id}
     * Deletes DB record and removes asset from Cloudinary CDN
     */
    public function delete($id)
    {
        $currentUser = AuthMiddleware::authenticate();
        $userId   = (int)$currentUser['sub'];
        $userRole = $currentUser['role'];
        $photoId  = (int)$id;

        if ($photoId <= 0) {
            Response::json(400, "Invalid photo ID.");
        }

        try {
            $stmt = $this->db->prepare("SELECT id, user_id, photo_url FROM package_photos WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $photoId]);
            $photo = $stmt->fetch();

            if (!$photo) {
                Response::json(404, "Photo record not found.");
            }

            if ($userRole === 'traveler' && (int)$photo['user_id'] !== $userId) {
                Response::json(403, "Forbidden: You do not have permission to delete this photo.");
            }

            // Extract Cloudinary Public ID from URL if stored
            $photoUrl = $photo['photo_url'];
            if (str_contains($photoUrl, 'res.cloudinary.com')) {
                // Parse public_id from Cloudinary URL pattern
                $path = parse_url($photoUrl, PHP_URL_PATH);
                $filename = pathinfo($path, PATHINFO_FILENAME);
                $dirname  = pathinfo($path, PATHINFO_DIRNAME);

                // Extract folder hierarchy starting after upload/ (or version numbers e.g. v123456)
                if (preg_match('#/upload/(?:v\d+/)?(.+)$#', $dirname . '/' . $filename, $matches)) {
                    $publicId = $matches[1];
                    CloudinaryHelper::destroy($publicId);
                }
            }

            // Remove Record from DB
            $deleteStmt = $this->db->prepare("DELETE FROM package_photos WHERE id = :id");
            $deleteStmt->execute([':id' => $photoId]);

            Response::json(200, "Photo successfully removed from database and Cloudinary CDN.", [
                'photo_id' => $photoId
            ]);
        } catch (Exception $e) {
            error_log("Photo Delete Exception: " . $e->getMessage());
            Response::json(500, "Internal Server Error: Could not delete photo.");
        }
    }
}
