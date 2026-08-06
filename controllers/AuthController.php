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

            // Generate Cryptographic Verification Token (24-hour expiration)
            $token = bin2hex(random_bytes(32));
            $tokenExpiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

            $sql = "INSERT INTO users (full_name, email, password_hash, phone, role, is_email_verified, verification_token, verification_token_expires_at) 
                    VALUES (:full_name, :email, :password_hash, :phone, 'traveler', 0, :token, :expires_at)";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':full_name'     => $fullName,
                ':email'         => $email,
                ':password_hash' => $passwordHash,
                ':phone'         => !empty($phone) ? $phone : null,
                ':token'         => $token,
                ':expires_at'    => $tokenExpiresAt
            ]);

            $userId = (int)$this->db->lastInsertId();

            // Dispatch Verification Email
            $appUrl = rtrim(getenv('APP_URL') ?: 'http://localhost:8000', '/');
            $verifyUrl = "{$appUrl}/api/auth/verify-email?token={$token}";
            $emailHtml = Mailer::getVerificationTemplate($fullName, $verifyUrl);

            Mailer::send($email, "Verify Your Email Address - Travel Agency", $emailHtml);

            Response::json(201, "Registration successful. Please check your email to verify your account.", [
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
        $token = trim($_GET['token'] ?? '');

        if (empty($token)) {
            Response::json(400, "Verification token is required.");
        }

        try {
            $sql = "SELECT id, full_name, email, is_email_verified, verification_token_expires_at 
                    FROM users 
                    WHERE verification_token = :token LIMIT 1";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':token' => $token]);
            $user = $stmt->fetch();

            if (!$user) {
                Response::json(404, "Invalid or expired verification token.");
            }

            if ((int)$user['is_email_verified'] === 1) {
                Response::json(200, "Email address is already verified.");
            }

            // Check token expiration
            if (strtotime($user['verification_token_expires_at']) < time()) {
                Response::json(410, "Verification link has expired. Please request a new verification email.");
            }

            // Activate user account
            $updateSql = "UPDATE users 
                          SET is_email_verified = 1, verification_token = NULL, verification_token_expires_at = NULL 
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
    /**
     * POST /api/auth/resend-verification
     * Issue new verification token and email to unverified accounts
     */
    public function resendVerification()
    {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        $email = strtolower(trim($input['email'] ?? ''));

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::json(422, "Validation Error: A valid email address is required.");
        }

        try {
            $stmt = $this->db->prepare("SELECT id, full_name, is_email_verified FROM users WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            if (!$user) {
                // Prevent email enumeration
                Response::json(200, "If an unverified account matches that email, a verification link has been sent.");
            }

            if ((int)$user['is_email_verified'] === 1) {
                Response::json(409, "This account is already verified.");
            }

            // Regenerate Token
            $newToken = bin2hex(random_bytes(32));
            $tokenExpiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));

            $updateStmt = $this->db->prepare("UPDATE users SET verification_token = :token, verification_token_expires_at = :expires_at WHERE id = :id");
            $updateStmt->execute([
                ':token'      => $newToken,
                ':expires_at' => $tokenExpiresAt,
                ':id'         => $user['id']
            ]);

            $appUrl = rtrim(getenv('APP_URL') ?: 'http://localhost:8000', '/');
            $verifyUrl = "{$appUrl}/api/auth/verify-email?token={$newToken}";
            $emailHtml = Mailer::getVerificationTemplate($user['full_name'], $verifyUrl);

            Mailer::send($email, "New Email Verification Link - Travel Agency", $emailHtml);

            Response::json(200, "A fresh email verification link has been sent.");
        } catch (Exception $e) {
            error_log("Resend Verification Exception: " . $e->getMessage());
            Response::json(500, "Internal Server Error: Unable to resend verification email.");
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
            // Fetch User by Email
            $stmt = $this->db->prepare("SELECT id, full_name, email, password_hash, phone, role FROM users WHERE email = :email LIMIT 1");
            $stmt->execute([':email' => $email]);
            $user = $stmt->fetch();

            // Verify Password Hash
            if (!$user || !password_verify($password, $user['password_hash'])) {
                Response::json(401, "Invalid credentials.", null, [
                    'auth' => "The email or password provided is incorrect."
                ]);
            }

            // Issue JWT Token
            $tokenPayload = [
                'sub'   => $user['id'],
                'email' => $user['email'],
                'role'  => $user['role'],
                'name'  => $user['full_name']
            ];
            $token = JWT::encode($tokenPayload);

            Response::json(200, "Login successful.", [
                'user' => [
                    'id'        => (int) $user['id'],
                    'full_name' => $user['full_name'],
                    'email'     => $user['email'],
                    'phone'     => $user['phone'],
                    'role'      => $user['role']
                ],
                'token' => $token
            ]);
        } catch (Exception $e) {
            error_log("Login Exception: " . $e->getMessage());
            Response::json(500, "Internal Server Error: Could not complete login.");
        }
    }

    /**
     * Step 2.3: Fetch Authenticated User Profile
     * GET /api/auth/me
     */
    public function me()
    {
        // Guard Route using AuthMiddleware (Accepts any valid authenticated role)
        $currentUser = AuthMiddleware::authenticate();

        try {
            $stmt = $this->db->prepare("SELECT id, full_name, email, phone, role, created_at FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $currentUser['sub']]);
            $user = $stmt->fetch();

            if (!$user) {
                Response::json(404, "User account no longer exists.");
            }

            Response::json(200, "User profile retrieved successfully.", [
                'user' => [
                    'id'         => (int) $user['id'],
                    'full_name'  => $user['full_name'],
                    'email'      => $user['email'],
                    'phone'      => $user['phone'],
                    'role'       => $user['role'],
                    'created_at' => $user['created_at']
                ]
            ]);
        } catch (Exception $e) {
            error_log("Profile Exception: " . $e->getMessage());
            Response::json(500, "Internal Server Error: Unable to fetch profile.");
        }
    }
    /**
     * PUT /api/auth/me
     * Gap D1: Update Profile Details (Full Name & Phone)
     * Protected: All Authenticated Users
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
            // Build dynamic UPDATE query
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

            // Fetch refreshed profile data
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
     * Gap D2: Secure Password Rotation with Old Password Verification
     * Protected: All Authenticated Users
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
            // Fetch stored password hash
            $stmt = $this->db->prepare("SELECT password_hash FROM users WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $userId]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
                Response::json(401, "Authentication failed: Incorrect current password.");
            }

            // Prevent re-using identical password
            if (password_verify($newPassword, $user['password_hash'])) {
                Response::json(422, "Validation Error: New password cannot be identical to the current password.");
            }

            // Generate new Bcrypt Hash (Cost: 12)
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
