<?php
// helpers/Cloudinary.php

class CloudinaryHelper
{

    /**
     * Upload a local temporary file to Cloudinary
     * 
     * @param string $filePath Absolute path to temp file ($_FILES['photo']['tmp_name'])
     * @param string $folder Target Cloudinary folder
     * @return array Array containing 'public_id' and 'secure_url'
     * @throws Exception On cURL or API failure
     */
    public static function upload($filePath, $folder = 'packages')
    {
        $cloudName    = getenv('CLOUDINARY_CLOUD_NAME');
        $apiKey       = getenv('CLOUDINARY_API_KEY');
        $apiSecret    = getenv('CLOUDINARY_API_SECRET');
        $uploadPreset = getenv('CLOUDINARY_UPLOAD_PRESET');

        if (!$cloudName) {
            throw new Exception("Cloudinary configuration missing in environment variables.");
        }

        $apiUrl = "https://api.cloudinary.com/v1_1/{$cloudName}/image/upload";

        $timestamp = time();

        // If an unsigned upload preset is provided, use standard preset mode
        if (!empty($uploadPreset)) {
            $postFields = [
                'file'          => new CURLFile($filePath),
                'upload_preset' => $uploadPreset,
                'folder'        => $folder
            ];
        } else {
            // Signed Upload Mode (Fallback using API Secret)
            $paramsToSign = [
                'folder'    => $folder,
                'timestamp' => $timestamp
            ];

            ksort($paramsToSign);
            $stringToSign = "";
            foreach ($paramsToSign as $key => $val) {
                $stringToSign .= "{$key}={$val}&";
            }
            $stringToSign = rtrim($stringToSign, '&') . $apiSecret;
            $signature = sha1($stringToSign);

            $postFields = [
                'file'      => new CURLFile($filePath),
                'api_key'   => $apiKey,
                'timestamp' => $timestamp,
                'signature' => $signature,
                'folder'    => $folder
            ];
        }

        // Execute cURL POST Request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $responseBody = curl_exec($ch);
        $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            throw new Exception("Cloudinary cURL Error: " . $curlError);
        }

        $result = json_decode($responseBody, true);

        if ($httpCode !== 200 || isset($result['error'])) {
            $errorMessage = $result['error']['message'] ?? 'Unknown Cloudinary error';
            throw new Exception("Cloudinary API Upload Failed (HTTP {$httpCode}): " . $errorMessage);
        }

        return [
            'public_id'  => $result['public_id'],
            'secure_url' => $result['secure_url']
        ];
    }

    /**
     * Delete an asset from Cloudinary by its public_id
     * 
     * @param string $publicId Cloudinary public_id
     * @return bool
     */
    public static function destroy($publicId)
    {
        $cloudName = getenv('CLOUDINARY_CLOUD_NAME');
        $apiKey    = getenv('CLOUDINARY_API_KEY');
        $apiSecret = getenv('CLOUDINARY_API_SECRET');

        if (!$cloudName || !$apiKey || !$apiSecret) {
            return false;
        }

        $apiUrl    = "https://api.cloudinary.com/v1_1/{$cloudName}/image/destroy";
        $timestamp = time();

        $stringToSign = "public_id={$publicId}&timestamp={$timestamp}" . $apiSecret;
        $signature    = sha1($stringToSign);

        $postFields = [
            'public_id' => $publicId,
            'api_key'   => $apiKey,
            'timestamp' => $timestamp,
            'signature' => $signature
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $apiUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $responseBody = curl_exec($ch);
        curl_close($ch);

        $result = json_decode($responseBody, true);
        return isset($result['result']) && $result['result'] === 'ok';
    }
}
