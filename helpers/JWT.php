<?php
// helpers/JWT.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/env.php';

use Firebase\JWT\JWT as FirebaseJWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;

class JWT
{

    /**
     * Generate a signed JWT token using Firebase JWT
     *
     * @param array $payload Claims and user data
     * @param int|null $ttl Time-To-Live in seconds
     * @return string
     */
    public static function encode(array $payload, ?int $ttl = null): string
    {
        EnvLoader::load(__DIR__ . '/../.env');

        $secret = getenv('JWT_SECRET');
        if (!$secret || strlen($secret) < 32) {
            throw new Exception("JWT Security Error: JWT_SECRET must be at least 32 characters long.");
        }

        $defaultTtl = (int)(getenv('JWT_EXPIRATION') ?: 86400); // 24 hours default
        $issuedAt = time();
        $expiration = $issuedAt + ($ttl ?? $defaultTtl);

        $claims = array_merge($payload, [
            'iat' => $issuedAt,
            'exp' => $expiration
        ]);

        return FirebaseJWT::encode($claims, $secret, 'HS256');
    }

    /**
     * Decode and validate a JWT token
     *
     * @param string $token
     * @return array
     * @throws Exception
     */
    public static function decode(string $token): array
    {
        EnvLoader::load(__DIR__ . '/../.env');

        $secret = getenv('JWT_SECRET');
        if (!$secret) {
            throw new Exception("JWT Configuration Error: JWT_SECRET missing.");
        }

        try {
            $decoded = FirebaseJWT::decode($token, new Key($secret, 'HS256'));
            return (array) $decoded;
        } catch (ExpiredException $e) {
            throw new Exception("Token has expired.");
        } catch (SignatureInvalidException $e) {
            throw new Exception("Invalid token signature.");
        } catch (\UnexpectedValueException $e) {
            throw new Exception("Invalid token structure or algorithm.");
        } catch (\Exception $e) {
            throw new Exception("Authentication failure: " . $e->getMessage());
        }
    }
}
