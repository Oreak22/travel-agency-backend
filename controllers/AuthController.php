<?php
// controllers/AuthController.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/JWT.php';
require_once __DIR__ . '/../helpers/Mailer.php';
require_once __DIR__ . '/../middleware/auth.php';

class AuthController
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * Step 2.1: Register a new Traveler account
     * POST /api/auth/register
     */
    public function register()
    {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        if (!$input) {
            Response::json(400, "Invalid JSON payload provided.");
        }

        $fullName = trim($input['full_name'] ?? '');
        $email    = strtolower(trim($input['email'] ?? ''));
        $password = $input['password'] ?? '';
        $phone    = trim($input['phone'] ?? '');

        $errors = [];
        if (empty($fullName)) $errors['full_name'] = "Full name is required.";
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = "A valid email address is required.";
        }
        if (empty($password) || strlen($password) < 8) {
            $errors['password'] = "Password must be at least 8 characters long.";
        }

        if (!empty($errors)) {
            Response::json(422, "Validation failed.", null, $errors);
        }

        try {
            // Check if email already exists
            $checkStmt = $this->db->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
            $checkStmt->execute([':email' => $email]);
            if ($checkStmt->fetch()) {
                Response::json(409, "Conflict: An account with this email address already exists.");
            }

            // Hash password (Bcrypt cost: 12)
            $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

            // Generate Cryptographic 6-digit OTP (10-minute expiration window)
            $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $otpExpiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));

            // Insert user using OTP columns
            $sql = "INSERT INTO users (full_name, email, password_hash, phone, role, is_email_verified, otp, otp_expires_at) 
                VALUES (:full_name, :email, :password_hash, :phone, 'traveler', 0, :otp, :expires_at)";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':full_name'     => $fullName,
                ':email'         => $email,
                ':password_hash' => $passwordHash,
                ':phone'         => !empty($phone) ? $phone : null,
                ':otp'           => $otp,
                ':expires_at'    => $otpExpiresAt
            ]);

            $userId = (int)$this->db->lastInsertId();

            // Dispatch Verification Email with OTP code
            $emailHtml = Mailer::getOtpVerificationTemplate($fullName, $otp);
            Mailer::send($email, "Your Verification Code - Travel Agency", $emailHtml);

            Response::json(201, "Registration successful. Please check your email for your verification code.", [
                'user' => [
                    'id'                => $userId,
                    'full_name'         => $fullName,
                    'email'             => $email,
                    'phone'             => $phone,
                    'role'              => 'traveler',
                    'is_email_verified' => false
                ]
            ]);
        } catch (Exception $e) {
            error_log("Registration Exception: " . $e->getMessage());
            Response::json(500, "Internal Server Error: Unable to complete registration.");
        }
    }

    /**
     * GET /api/auth/verify-email?token={token}
     * Verify email address via cryptographic token
     */
    public function verifyEmail()
    {
        // Ensure request is strictly POST
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::json(405, "Method Not Allowed. Please use POST.");
        }

        // Read and decode JSON request body
        $rawInput = file_get_contents('php://input');
        $data = json_decode($rawInput, true);

        $email = trim($data['email'] ?? '');
        $otp   = trim($data['otp'] ?? '');

        if (empty($email) || empty($otp)) {
            Response::json(400, "Both 'email' and 'otp' fields are required in the request body.");
        }

        try {
            // Fetch user matching both email and OTP
            $sql = "SELECT id, full_name, email, is_email_verified, otp_expires_at 
                FROM users 
                WHERE email = :email AND otp = :otp LIMIT 1";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':email' => $email,
                ':otp'   => $otp
            ]);
            $user = $stmt->fetch();

            if (!$user) {
                Response::json(400, "Invalid OTP or email address.");
            }

            if ((int)$user['is_email_verified'] === 1) {
                Response::json(200, "Email address is already verified.");
            }

            // Check OTP expiration
            if (strtotime($user['otp_expires_at']) < time()) {
                Response::json(410, "OTP has expired. Please request a new verification code.");
            }

            // Activate user account & clear consumed OTP
            $updateSql = "UPDATE users 
                      SET is_email_verified = 1, otp = NULL, otp_expires_at = NULL 
                      WHERE id = :id";
            $updateStmt = $this->db->prepare($updateSql);
            $updateStmt->execute([':id' => $user['id']]);

            Response::json(200, "Email verified successfully! You can now access all platform features.", [
                'email'             => $user['email'],
                'is_email_verified' => true
            ]);
        } catch (Exception $e) {
            error_log("Verify Email Exception: " . $e->getMessage());
            Response::json(500, "Internal Server Error: Could not verify email.");
        }
    }

    private function enforceRateLimit(string $identifier, int $cooldownSeconds = 60, int $maxAttempts = 3, int $decaySeconds = 3600)
    {
        $now = date('Y-m-d H:i:s');

        $stmt = $this->db->prepare("
        SELECT id, attempts, last_attempt_at, created_at 
        FROM otp_rate_limits 
        WHERE identifier = :identifier AND action_type = 'resend_otp' 
        LIMIT 1
        ");
        $stmt->execute([':identifier' => $identifier]);
        $record = $stmt->fetch();

        if ($record) {
            $lastAttemptTime = strtotime($record['last_attempt_at']);
            $createdTime     = strtotime($record['created_at']);
            $elapsedCooldown = time() - $lastAttemptTime;
            $elapsedWindow   = time() - $createdTime;

            if ($elapsedCooldown < $cooldownSeconds) {
                $waitTime = $cooldownSeconds - $elapsedCooldown;
                throw new Exception("Please wait {$waitTime} second(s) before requesting another code.", 429);
            }

            if ($elapsedWindow > $decaySeconds) {
                $resetStmt = $this->db->prepare("
                UPDATE otp_rate_limits 
                SET attempts = 1, last_attempt_at = :last_attempt_at, created_at = :created_at 
                WHERE id = :id
                ");
                $resetStmt->execute([
                    ':last_attempt_at' => $now,
                    ':created_at'      => $now,
                    ':id'              => $record['id']
                ]);
                return;
            }

            if ((int)$record['attempts'] >= $maxAttempts) {
                $timeRemaining = ceil(($decaySeconds - $elapsedWindow) / 60);
                throw new Exception("Too many attempts. Please try again in {$timeRemaining} minute(s).", 429);
            }

            $updateStmt = $this->db->prepare("
            UPDATE otp_rate_limits 
            SET attempts = attempts + 1, last_attempt_at = :last_attempt_at 
            WHERE id = :id
            ");
            $updateStmt->execute([
                ':last_attempt_at' => $now,
                ':id'              => $record['id']
            ]);
        } else {
            $insertStmt = $this->db->prepare("
            INSERT INTO otp_rate_limits (identifier, action_type, attempts, last_attempt_at, created_at) 
            VALUES (:identifier, 'resend_otp', 1, :last_attempt_at, :created_at)
            ");
            $insertStmt->execute([
                ':identifier'      => $identifier,
                ':last_attempt_at' => $now,
                ':created_at'      => $now
            ]);
        }
    }

    /**
     * POST /api/auth/resend-verification
     * Issue new verification token and email to unverified accounts
     */
    public function resendVerification()
    {
        $email = strtolower(trim($_GET['email'] ?? ''));

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::json(422, "Validation Error: A valid email address is required.");
            return;
        }

        try {
            // Enforce Rate Limit: 60s cooldown, max 3 attempts per hour per email
            $this->enforceRateLimit($email, 60, 3, 3600);

            $stmt = $this->db->prepare("SELECT id, full_name, is_email_verified FROM users WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            if (!$user) {
                // Prevent email enumeration
                Response::json(200, "If an unverified account matches that email, a verification code has been sent.");
                return;
            }

            if ((int)$user['is_email_verified'] === 1) {
                Response::json(409, "This account is already verified.");
                return;
            }

            $newOtp       = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $otpExpiresAt = date('Y-m-d H:i:s', strtotime('+10 minutes'));

            $updateStmt = $this->db->prepare("UPDATE users SET otp = :otp, otp_expires_at = :otp_expires_at WHERE id = :id");
            $updateStmt->execute([
                ':otp'            => $newOtp,
                ':otp_expires_at' => $otpExpiresAt,
                ':id'             => $user['id']
            ]);

            $emailHtml = Mailer::getOtpVerificationTemplate($user['full_name'], $newOtp);
            Mailer::send($email, "New Email Verification Code - Travel Agency", $emailHtml);

            Response::json(200, "A fresh email verification code has been sent.");
            return;
        } catch (Throwable $e) {
            if ((int)$e->getCode() === 429 || str_contains($e->getMessage(), 'Rate limit')) {
                Response::json(429, $e->getMessage());
                return;
            }

            Response::json(500, "DEBUG: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
            return;
        }
    }

    /**
     * Step 2.2: Authenticate User & Issue JWT
     * POST /api/auth/login
     */
    public function login()
    {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        if (!$input) {
            Response::json(400, "Invalid JSON payload provided.");
        }

        $email    = strtolower(trim($input['email'] ?? ''));
        $password = $input['password'] ?? '';

        if (empty($email) || empty($password)) {
            Response::json(422, "Validation Error.", null, [
                'auth' => "Both email and password are required."
            ]);
        }

        try {
            $stmt = $this->db->prepare("SELECT id, full_name, email, password_hash, phone, role, is_email_verified FROM users WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password_hash'])) {
                Response::json(401, "Invalid credentials.", null, [
                    'auth' => "The email or password provided is incorrect."
                ]);
            }

            $tokenPayload = [
                'sub'   => $user['id'],
                'email' => $user['email'],
                'role'  => $user['role'],
                'name'  => $user['full_name']
            ];
            $token = JWT::encode($tokenPayload);

            Response::json(200, "Login successful.", [
                'user' => [
                    'id'                => (int) $user['id'],
                    'full_name'         => $user['full_name'],
                    'email'             => $user['email'],
                    'phone'             => $user['phone'],
                    'role'              => $user['role'],
                    'is_email_verified' => (int) $user['is_email_verified']
                ],
                'token' => $token
            ]);
        } catch (Exception $e) {
            error_log("Login Exception: " . $e->getMessage());
            Response::json(500, "Internal Server Error: Could not complete login.");
        }
    }

    /**
     * POST /api/auth/google
     * Handle Google Sign-In / Sign-Up
     */
    public function googleLogin()
    {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        $idToken = $input['id_token'] ?? '';

        if (empty($idToken)) {
            Response::json(400, "Google ID token is required.");
        }

        try {
            // Validate the token via Google's endpoint
            // Note: In production, consider using the official google/apiclient library.
            $verifyUrl = "https://oauth2.googleapis.com/tokeninfo?id_token=" . urlencode($idToken);
            $response = @file_get_contents($verifyUrl);
            $payload = $response ? json_decode($response, true) : null;

            if (!$payload || isset($payload['error']) || empty($payload['email'])) {
                Response::json(401, "Invalid or expired Google ID token.");
            }

            // Optional: verify the token was intended for your app
            // if (isset($payload['aud']) && $payload['aud'] !== 'YOUR_GOOGLE_CLIENT_ID') { ... }

            $email = strtolower(trim($payload['email']));
            $fullName = $payload['name'] ?? 'Google User';

            // Pass to the shared OAuth handler
            $this->handleOAuthUser($email, $fullName);
        } catch (Exception $e) {
            error_log("Google OAuth Exception: " . $e->getMessage());
            Response::json(500, "Internal Server Error: Unable to authenticate with Google.");
        }
    }

    /**
     * POST /api/auth/apple
     * Handle Apple Sign-In / Sign-Up
     */
    public function appleLogin()
    {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        $identityToken = $input['identity_token'] ?? '';

        // IMPORTANT: Apple only sends the user's name on their VERY FIRST sign-in. 
        // Your frontend needs to capture it and pass it to this endpoint as 'full_name'
        $clientProvidedName = $input['full_name'] ?? null;

        if (empty($identityToken)) {
            Response::json(400, "Apple identity token is required.");
        }

        try {
            // Parse Apple JWT payload
            // Note: In production, you MUST verify the RSA signature using Apple's JWKS (https://appleid.apple.com/auth/keys)
            $tokenParts = explode('.', $identityToken);
            if (count($tokenParts) !== 3) {
                Response::json(400, "Invalid Apple identity token format.");
            }

            // Decode the payload part of the JWT
            $payloadBase64 = str_replace(['-', '_'], ['+', '/'], $tokenParts[1]);
            $payload = json_decode(base64_decode($payloadBase64), true);

            if (!$payload || empty($payload['email'])) {
                Response::json(401, "Invalid Apple identity token payload.");
            }

            $email = strtolower(trim($payload['email']));
            $fullName = $clientProvidedName ?: 'Apple User';

            // Pass to the shared OAuth handler
            $this->handleOAuthUser($email, $fullName);
        } catch (Exception $e) {
            error_log("Apple OAuth Exception: " . $e->getMessage());
            Response::json(500, "Internal Server Error: Unable to authenticate with Apple.");
        }
    }

    /**
     * Shared logic to process OAuth Users (Google & Apple)
     */
    private function handleOAuthUser(string $email, string $fullName)
    {
        try {
            $stmt = $this->db->prepare("SELECT id, full_name, email, phone, role, is_email_verified FROM users WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            if (!$user) {
                // User doesn't exist -> Register them
                // Generate a random high-entropy password since they won't use it to log in
                $randomPassword = bin2hex(random_bytes(16));
                $passwordHash = password_hash($randomPassword, PASSWORD_BCRYPT, ['cost' => 12]);

                $sql = "INSERT INTO users (full_name, email, password_hash, role, is_email_verified) 
                        VALUES (:full_name, :email, :password_hash, 'traveler', 1)";

                $insertStmt = $this->db->prepare($sql);
                $insertStmt->execute([
                    ':full_name'     => $fullName,
                    ':email'         => $email,
                    ':password_hash' => $passwordHash
                ]);

                $userId = (int)$this->db->lastInsertId();

                $user = [
                    'id'                => $userId,
                    'full_name'         => $fullName,
                    'email'             => $email,
                    'phone'             => null,
                    'role'              => 'traveler',
                    'is_email_verified' => 1
                ];
            } else {
                // User exists. If they started regular registration previously but didn't verify their email,
                // OAuth implicitly verifies it for them.
                if ((int)$user['is_email_verified'] === 0) {
                    $updateStmt = $this->db->prepare("UPDATE users SET is_email_verified = 1, otp = NULL, otp_expires_at = NULL WHERE id = :id");
                    $updateStmt->execute([':id' => $user['id']]);
                    $user['is_email_verified'] = 1;
                }
            }

            // Issue your platform's standard JWT token
            $tokenPayload = [
                'sub'   => $user['id'],
                'email' => $user['email'],
                'role'  => $user['role'],
                'name'  => $user['full_name']
            ];
            $token = JWT::encode($tokenPayload);

            Response::json(200, "OAuth authentication successful.", [
                'user' => [
                    'id'                => (int) $user['id'],
                    'full_name'         => $user['full_name'],
                    'email'             => $user['email'],
                    'phone'             => $user['phone'],
                    'role'              => $user['role'],
                    'is_email_verified' => (int) $user['is_email_verified']
                ],
                'token' => $token
            ]);
        } catch (Exception $e) {
            error_log("Handle OAuth Exception: " . $e->getMessage());
            Response::json(500, "Internal Server Error: Unable to complete OAuth sign-in.");
        }
    }

    /**
     * Step 2.3: Fetch Authenticated User Profile
     * GET /api/auth/me
     */
    public function me()
    {
        $currentUser = AuthMiddleware::authenticate();

        try {
            $stmt = $this->db->prepare("SELECT id, full_name, email, phone, role, created_at, is_email_verified FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $currentUser['sub']]);
            $user = $stmt->fetch();

            if (!$user) {
                Response::json(404, "User account no longer exists.");
            }

            Response::json(200, "User profile retrieved successfully.", [
                'user' => [
                    'id'                => (int) $user['id'],
                    'full_name'         => $user['full_name'],
                    'email'             => $user['email'],
                    'phone'             => $user['phone'],
                    'role'              => $user['role'],
                    'is_email_verified' => (int) $user['is_email_verified']
                ]
            ]);
        } catch (Exception $e) {
            error_log("Profile Exception: " . $e->getMessage());
            Response::json(500, "Internal Server Error: Unable to fetch profile.");
        }
    }

    /**
     * PUT /api/auth/me
     */
    public function updateProfile()
    {
        $currentUser = AuthMiddleware::authenticate();
        $userId = (int)$currentUser['sub'];

        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        if (!$input) {
            Response::json(400, "Invalid JSON body provided.");
        }

        $fullName = isset($input['full_name']) ? trim($input['full_name']) : null;
        $phone    = isset($input['phone']) ? trim($input['phone']) : null;

        $errors = [];
        if ($fullName !== null && strlen($fullName) < 2) {
            $errors['full_name'] = "Full name must be at least 2 characters.";
        }
        if ($phone !== null && strlen($phone) > 30) {
            $errors['phone'] = "Phone number exceeds max length of 30 characters.";
        }

        if (!empty($errors)) {
            Response::json(422, "Validation failed.", null, $errors);
        }

        try {
            $fields = [];
            $params = [':id' => $userId];

            if ($fullName !== null) {
                $fields[] = "full_name = :full_name";
                $params[':full_name'] = $fullName;
            }
            if ($phone !== null) {
                $fields[] = "phone = :phone";
                $params[':phone'] = $phone;
            }

            if (empty($fields)) {
                Response::json(400, "No valid profile fields provided for update.");
            }

            $sql = "UPDATE users SET " . implode(', ', $fields) . " WHERE id = :id";
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);

            $userStmt = $this->db->prepare("SELECT id, full_name, email, phone, role, created_at FROM users WHERE id = :id LIMIT 1");
            $userStmt->execute([':id' => $userId]);
            $updatedUser = $userStmt->fetch();

            Response::json(200, "Profile updated successfully.", [
                'user' => [
                    'id'         => (int)$updatedUser['id'],
                    'full_name'  => $updatedUser['full_name'],
                    'email'      => $updatedUser['email'],
                    'phone'      => $updatedUser['phone'],
                    'role'       => $updatedUser['role'],
                    'created_at' => $updatedUser['created_at']
                ]
            ]);
        } catch (Exception $e) {
            error_log("Profile Update Exception: " . $e->getMessage());
            Response::json(500, "Internal Server Error: Unable to update user profile.");
        }
    }

    /**
     * PUT /api/auth/change-password
     */
    public function changePassword()
    {
        $currentUser = AuthMiddleware::authenticate();
        $userId = (int)$currentUser['sub'];

        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        if (!$input) {
            Response::json(400, "Invalid JSON body provided.");
        }

        $currentPassword = $input['current_password'] ?? '';
        $newPassword     = $input['new_password'] ?? '';

        $errors = [];
        if (empty($currentPassword)) {
            $errors['current_password'] = "Current password is required.";
        }
        if (empty($newPassword) || strlen($newPassword) < 8) {
            $errors['new_password'] = "New password must be at least 8 characters long.";
        }

        if (!empty($errors)) {
            Response::json(422, "Validation failed.", null, $errors);
        }

        try {
            $stmt = $this->db->prepare("SELECT password_hash FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
                Response::json(401, "Authentication failed: Incorrect current password.");
            }

            if (password_verify($newPassword, $user['password_hash'])) {
                Response::json(422, "Validation Error: New password cannot be identical to the current password.");
            }

            $newPasswordHash = password_hash($newPassword, PASSWORD_BCRYPT, ['cost' => 12]);

            $updateStmt = $this->db->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
            $updateStmt->execute([
                ':hash' => $newPasswordHash,
                ':id'   => $userId
            ]);

            Response::json(200, "Password changed successfully. Please re-authenticate if necessary.");
        } catch (Exception $e) {
            error_log("Change Password Exception: " . $e->getMessage());
            Response::json(500, "Internal Server Error: Unable to update password.");
        }
    }
}
