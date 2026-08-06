<?php
// controllers/PaymentController.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../middleware/auth.php';

class PaymentController
{

    private $db;
    private $paystackSecret;
    private $paystackBaseUrl;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
        $this->paystackSecret  = getenv('PAYSTACK_SECRET_KEY');
        $this->paystackBaseUrl = rtrim(getenv('PAYSTACK_BASE_URL') ?: 'https://api.paystack.co', '/');
    }

    /**
     * POST /api/payments/initialize
     * Step 6.1: Initialize Payment Session & Return Checkout URL
     * Protected: Owner of Booking or Admin/Agent
     */
    public function initialize()
    {
        $currentUser = AuthMiddleware::authenticate();
        $userId   = (int)$currentUser['sub'];
        $userRole = $currentUser['role'];

        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        if (!$input) {
            Response::json(400, "Invalid JSON body provided.");
        }

        $bookingId     = (int)($input['booking_id'] ?? 0);
        $callbackUrl   = trim($input['callback_url'] ?? '');

        if ($bookingId <= 0) {
            Response::json(422, "Validation Error.", null, ['booking_id' => "A valid booking ID is required."]);
        }

        try {
            // 1. Fetch Booking Record & Customer Email
            $sql = "SELECT b.id, b.user_id, b.total_amount, b.status, u.email 
                    FROM bookings b
                    JOIN users u ON b.user_id = u.id
                    WHERE b.id = :id LIMIT 1";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $bookingId]);
            $booking = $stmt->fetch();

            if (!$booking) {
                Response::json(404, "Booking record not found.");
            }

            if ($userRole === 'traveler' && (int)$booking['user_id'] !== $userId) {
                Response::json(403, "Forbidden: You do not have permission to pay for this booking.");
            }

            if ($booking['status'] === 'confirmed') {
                Response::json(409, "Conflict: This booking is already confirmed and paid for.");
            }

            if ($booking['status'] === 'cancelled') {
                Response::json(400, "Bad Request: Cannot process payment for a cancelled booking.");
            }

            // 2. Generate Unique Transaction Reference
            $transactionRef = 'TRX-' . strtoupper(bin2hex(random_bytes(8)));
            $amountKobo = (int)round((float)$booking['total_amount'] * 100); // Amount in smallest currency unit (kobo/cents)

            // 3. Prepare Paystack Gateway API Payload
            $payload = [
                'email'        => $booking['email'],
                'amount'       => $amountKobo,
                'reference'    => $transactionRef,
                'callback_url' => !empty($callbackUrl) ? $callbackUrl : getenv('APP_URL') . '/api/payments/verify/' . $transactionRef,
                'metadata'     => [
                    'booking_id' => $bookingId,
                    'user_id'    => $userId
                ]
            ];

            // 4. Call Paystack REST API via cURL
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->paystackBaseUrl . '/transaction/initialize');
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $this->paystackSecret,
                'Content-Type: application/json'
            ]);

            $responseBody = curl_exec($ch);
            $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $result = json_decode($responseBody, true);

            if ($httpCode !== 200 || !($result['status'] ?? false)) {
                $gatewayError = $result['message'] ?? 'Payment gateway initialization failed.';
                Response::json(502, "Bad Gateway: " . $gatewayError);
            }

            $authorizationUrl = $result['data']['authorization_url'];
            $accessCode       = $result['data']['access_code'];

            // 5. Record Pending Payment Entry in DB
            $insertSql = "INSERT INTO payments (booking_id, transaction_ref, amount, payment_method, status) 
                          VALUES (:booking_id, :transaction_ref, :amount, 'card', 'pending')";

            $insertStmt = $this->db->prepare($insertSql);
            $insertStmt->execute([
                ':booking_id'      => $bookingId,
                ':transaction_ref' => $transactionRef,
                ':amount'          => $booking['total_amount']
            ]);

            Response::json(201, "Payment transaction initialized successfully.", [
                'transaction_ref'   => $transactionRef,
                'authorization_url' => $authorizationUrl,
                'access_code'       => $accessCode,
                'amount'            => (float)$booking['total_amount']
            ]);
        } catch (Exception $e) {
            error_log("Payment Initialization Exception: " . $e->getMessage());
            Response::json(500, "Internal Server Error: Unable to initialize payment transaction.");
        }
    }

    /**
     * GET /api/payments/verify/{reference}
     * Step 6.2: Verify Transaction Status with Gateway
     * Protected: Owner of Booking or Admin/Agent
     */
    public function verify($reference)
    {
        $currentUser = AuthMiddleware::authenticate();
        $reference   = trim($reference);

        if (empty($reference)) {
            Response::json(400, "Transaction reference is required.");
        }

        try {
            // 1. Fetch Payment & Booking Details
            $stmt = $this->db->prepare("SELECT p.id, p.booking_id, p.amount, p.status, b.user_id 
                                        FROM payments p 
                                        JOIN bookings b ON p.booking_id = b.id 
                                        WHERE p.transaction_ref = :ref LIMIT 1");
            $stmt->execute([':ref' => $reference]);
            $payment = $stmt->fetch();

            if (!$payment) {
                Response::json(404, "Payment record not found.");
            }

            // If already completed, return cached verification state
            if ($payment['status'] === 'completed') {
                Response::json(200, "Payment is already verified and completed.", [
                    'transaction_ref' => $reference,
                    'booking_id'      => (int)$payment['booking_id'],
                    'status'          => 'completed',
                    'amount'          => (float)$payment['amount']
                ]);
            }

            // 2. Query Gateway API for Verification Status
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $this->paystackBaseUrl . '/transaction/verify/' . rawurlencode($reference));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $this->paystackSecret,
                'Content-Type: application/json'
            ]);

            $responseBody = curl_exec($ch);
            $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $result = json_decode($responseBody, true);

            if ($httpCode !== 200 || !($result['status'] ?? false)) {
                Response::json(400, "Payment verification failed or transaction not found on gateway.");
            }

            $gatewayStatus = $result['data']['status'] ?? 'failed';

            if ($gatewayStatus === 'success') {
                // 3. Atomic DB Update: Complete Payment & Confirm Booking
                $this->fulfillPayment((int)$payment['booking_id'], $reference, $result['data']['channel'] ?? 'card');

                Response::json(200, "Payment verified successfully. Booking confirmed!", [
                    'transaction_ref' => $reference,
                    'booking_id'      => (int)$payment['booking_id'],
                    'status'          => 'completed',
                    'amount'          => (float)$payment['amount']
                ]);
            } else {
                // Update payment status to failed
                $failStmt = $this->db->prepare("UPDATE payments SET status = 'failed' WHERE transaction_ref = :ref");
                $failStmt->execute([':ref' => $reference]);

                Response::json(400, "Payment verification unsuccessful. Status: " . $gatewayStatus);
            }
        } catch (Exception $e) {
            error_log("Payment Verification Exception: " . $e->getMessage());
            Response::json(500, "Internal Server Error: Could not verify transaction.");
        }
    }

    /**
     * POST /api/payments/webhook
     * Step 6.3: Secure Asynchronous Webhook Endpoint
     * Public Endpoint (Guarded via HMAC Signature Verification)
     */
    public function webhook()
    {
        $webhookSecret = getenv('PAYSTACK_WEBHOOK_SECRET') ?: $this->paystackSecret;
        $input = file_get_contents('php://input');

        $signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';

        // Verify Signature
        if (empty($signature) || $signature !== hash_hmac('sha512', $input, $webhookSecret)) {
            Response::json(401, "Unauthorized: Invalid webhook signature.");
        }

        $event = json_decode($input, true);
        if (!$event || !isset($event['event'])) {
            Response::json(400, "Invalid payload.");
        }

        // Process successful charge event
        if ($event['event'] === 'charge.success') {
            $data = $event['data'];
            $reference = $data['reference'] ?? null;
            $channel   = $data['channel'] ?? 'card';

            if ($reference) {
                try {
                    $stmt = $this->db->prepare("SELECT booking_id, status FROM payments WHERE transaction_ref = :ref LIMIT 1");
                    $stmt->execute([':ref' => $reference]);
                    $payment = $stmt->fetch();

                    if ($payment && $payment['status'] !== 'completed') {
                        $this->fulfillPayment((int)$payment['booking_id'], $reference, $channel);
                    }
                } catch (Exception $e) {
                    error_log("Webhook Fulfill Error: " . $e->getMessage());
                    Response::json(500, "Internal Server Error processing webhook.");
                }
            }
        }

        // Always acknowledge receipt to gateway
        Response::json(200, "Webhook event processed.");
    }

    /**
     * Helper Method: Atomic Transaction to Fulfill Payment & Confirm Booking
     */
    private function fulfillPayment($bookingId, $reference, $paymentMethod = 'card')
    {
        $this->db->beginTransaction();

        try {
            // Update Payment Record
            $payStmt = $this->db->prepare("UPDATE payments 
                                           SET status = 'completed', payment_method = :method, paid_at = CURRENT_TIMESTAMP 
                                           WHERE transaction_ref = :ref");
            $payStmt->execute([
                ':method' => $paymentMethod,
                ':ref'    => $reference
            ]);

            // Update Master Booking Record
            $bookStmt = $this->db->prepare("UPDATE bookings SET status = 'confirmed' WHERE id = :id");
            $bookStmt->execute([':id' => $bookingId]);

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
