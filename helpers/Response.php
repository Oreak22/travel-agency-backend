<?php
// helpers/Response.php

class Response
{
    public static function json($statusCode, $message = "", $data = null, $errors = null, $meta = null)
    {
        http_response_code($statusCode);

        $payload = [
            "timestamp" => date('Y-m-d\TH:i:sP'),
            "status"    => ($statusCode >= 200 && $statusCode < 300) ? "success" : "error",
            "message"   => $message
        ];

        if ($data !== null) {
            $payload["data"] = $data;
        }

        if ($errors !== null) {
            $payload["errors"] = $errors;
        }

        if ($meta !== null) {
            $payload["meta"] = $meta;
        }

        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit();
    }
}
