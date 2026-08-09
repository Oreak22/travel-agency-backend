<?php
// controllers/AdminBookingController.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/Response.php';

class AdminBookingController
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    // GET /api/admin/bookings
    public function index()
    {
        $sql = "SELECT b.id, b.total_amount AS total_price, b.status, b.created_at,
                       u.full_name AS customer_name, u.email AS customer_email, u.phone AS customer_phone,
                       p.title AS package_title, ps.start_date, ps.end_date,
                       b.seats_booked
                FROM bookings b
                JOIN users u ON b.user_id = u.id
                JOIN package_schedules ps ON b.schedule_id = ps.id
                JOIN packages p ON ps.package_id = p.id
                ORDER BY b.created_at DESC";

        $stmt = $this->db->query($sql);
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($bookings as &$booking) {
            $passengerStmt = $this->db->prepare("
                SELECT full_name, id_type, id_number, emergency_contact_name, emergency_contact_phone
                FROM booking_passengers
                WHERE booking_id = :booking_id
                ORDER BY id ASC
            ");
            $passengerStmt->execute([':booking_id' => $booking['id']]);
            $booking['guest_details'] = $passengerStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        Response::json(200, "Bookings retrieved successfully", $bookings);
    }

    // PUT /api/admin/bookings/status
    public function updateStatus()
    {
        $data = json_decode(file_get_contents('php://input'), true);
        $bookingId = $data['booking_id'] ?? null;
        $status = $data['status'] ?? null;

        $allowed = ['pending', 'confirmed', 'cancelled'];
        if (!$bookingId || !in_array($status, $allowed)) {
            Response::json(400, "Invalid booking ID or status value.");
        }

        $stmt = $this->db->prepare("UPDATE bookings SET status = :status WHERE id = :id");
        $stmt->execute([':status' => $status, ':id' => $bookingId]);

        Response::json(200, "Booking status updated to {$status}.");
    }

    // GET /api/admin/bookings/verify-paystack?reference=...
    public function verifyPaystack()
    {
        $reference = $_GET['reference'] ?? null;
        if (!$reference) {
            Response::json(400, "Transaction reference is required.");
        }

        $paystackSecretKey = getenv('PAYSTACK_SECRET_KEY');
        $url = "https://api.paystack.co/transaction/verify/" . rawurlencode($reference);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$paystackSecretKey}",
            "Cache-Control: no-cache"
        ]);

        $result = curl_exec($ch);
        curl_close($ch);

        $response = json_decode($result, true);

        if (!$response || !$response['status']) {
            Response::json(400, "Paystack verification failed: " . ($response['message'] ?? 'Unknown error'));
        }

        Response::json(200, "Paystack verification status fetched", $response['data']);
    }
}
