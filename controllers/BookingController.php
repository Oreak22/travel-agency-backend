<?php
// controllers/BookingController.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/JWT.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../helpers/Mailer.php';

class BookingController
{

    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * POST /api/bookings
     * Step 4.1: Atomic Transactional Booking Creation
     * Protected: Any Authenticated User
     */
    /**
     * POST /api/bookings
     * Step 4.1: Atomic Transactional Booking Creation & Notification
     * Protected: Any Authenticated User
     */
    public function create()
    {
        // Guard Endpoint - Must be logged in
        $currentUser = AuthMiddleware::authenticate();
        $userId = (int)$currentUser['sub'];

        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        if (!$input) {
            Response::json(400, "Invalid JSON payload provided.");
            return;
        }

        $scheduleId = (int)($input['schedule_id'] ?? 0);
        $passengers = $input['passengers'] ?? [];

        // Basic Payload Validation
        if ($scheduleId <= 0) {
            Response::json(422, "Validation Error.", null, ['schedule_id' => "Valid schedule ID is required."]);
            return;
        }

        if (!is_array($passengers) || empty($passengers)) {
            Response::json(422, "Validation Error.", null, ['passengers' => "At least one passenger record is required."]);
            return;
        }

        $seatsRequested = count($passengers);

        // Validate Individual Passenger Records
        $passengerErrors = [];
        $validIdTypes = ['passport', 'nin', 'national_id'];

        foreach ($passengers as $index => $passenger) {
            $name    = trim($passenger['full_name'] ?? '');
            $idType  = strtolower(trim($passenger['id_type'] ?? ''));
            $idNum   = trim($passenger['id_number'] ?? '');
            $eName   = trim($passenger['emergency_contact_name'] ?? '');
            $ePhone  = trim($passenger['emergency_contact_phone'] ?? '');

            if (empty($name)) {
                $passengerErrors["passenger_{$index}_full_name"] = "Passenger #" . ($index + 1) . " name is required.";
            }
            if (!in_array($idType, $validIdTypes, true)) {
                $passengerErrors["passenger_{$index}_id_type"] = "Passenger #" . ($index + 1) . " ID type must be passport, nin, or national_id.";
            }
            if (empty($idNum)) {
                $passengerErrors["passenger_{$index}_id_number"] = "Passenger #" . ($index + 1) . " ID/NIN/Passport number is required.";
            }
            if (empty($eName) || empty($ePhone)) {
                $passengerErrors["passenger_{$index}_emergency"] = "Passenger #" . ($index + 1) . " emergency contact name and phone are required.";
            }
        }

        if (!empty($passengerErrors)) {
            Response::json(422, "Validation Failed.", null, $passengerErrors);
            return;
        }

        // --- BEGIN ATOMIC TRANSACTION ---
        try {
            $this->db->beginTransaction();

            // 1. Lock Schedule & JOIN Package and User details for details rendering
            $lockSql = "SELECT 
                            ps.id, 
                            ps.available_seats, 
                            ps.price, 
                            ps.status,
                            p.title as package_title,
                            u.full_name as user_name,
                            u.email as user_email
                        FROM package_schedules ps
                        JOIN packages p ON ps.package_id = p.id
                        JOIN users u ON u.id = :user_id
                        WHERE ps.id = :schedule_id AND ps.status = 'open' 
                        FOR UPDATE";

            $lockStmt = $this->db->prepare($lockSql);
            $lockStmt->execute([
                ':schedule_id' => $scheduleId,
                ':user_id'     => $userId
            ]);
            $schedule = $lockStmt->fetch();

            if (!$schedule) {
                $this->db->rollBack();
                Response::json(404, "Schedule slot not found or no longer open for booking.");
                return;
            }

            $availableSeats = (int)$schedule['available_seats'];
            $pricePerSeat   = (float)$schedule['price'];
            $packageName    = $schedule['package_title'];
            $userName       = $schedule['user_name'];
            $userEmail      = $schedule['user_email'];

            // 2. Validate Available Capacity
            if ($availableSeats < $seatsRequested) {
                $this->db->rollBack();
                Response::json(409, "Booking Conflict: Not enough seats available.", null, [
                    'requested_seats' => $seatsRequested,
                    'available_seats' => $availableSeats
                ]);
                return;
            }

            // 3. Compute Total Amount & Generate Booking Reference
            $totalAmount = $pricePerSeat * $seatsRequested;
            $bookingReference = 'TRV-' . date('Y') . '-' . strtoupper(bin2hex(random_bytes(3)));

            // 4. Create Master Booking Record
            $bookingSql = "INSERT INTO bookings (booking_reference, user_id, schedule_id, seats_booked, total_amount, status) 
                           VALUES (:ref, :user_id, :schedule_id, :seats_booked, :total_amount, 'pending')";

            $bookingStmt = $this->db->prepare($bookingSql);
            $bookingStmt->execute([
                ':ref'          => $bookingReference,
                ':user_id'      => $userId,
                ':schedule_id'  => $scheduleId,
                ':seats_booked' => $seatsRequested,
                ':total_amount' => $totalAmount
            ]);

            $bookingId = (int)$this->db->lastInsertId();

            // 5. Insert Passenger Roster Records
            $passengerSql = "INSERT INTO booking_passengers 
                            (booking_id, full_name, id_type, id_number, emergency_contact_name, emergency_contact_phone) 
                             VALUES (:booking_id, :full_name, :id_type, :id_number, :emergency_contact_name, :emergency_contact_phone)";

            $passengerStmt = $this->db->prepare($passengerSql);

            foreach ($passengers as $p) {
                $passengerStmt->execute([
                    ':booking_id'              => $bookingId,
                    ':full_name'               => trim($p['full_name']),
                    ':id_type'                 => strtolower(trim($p['id_type'])),
                    ':id_number'               => trim($p['id_number']),
                    ':emergency_contact_name'  => trim($p['emergency_contact_name']),
                    ':emergency_contact_phone' => trim($p['emergency_contact_phone'])
                ]);
            }

            // 6. Update Available Seats Count in Schedule
            $newAvailableSeats = $availableSeats - $seatsRequested;
            $scheduleStatus = ($newAvailableSeats === 0) ? 'sold_out' : 'open';

            $updateScheduleSql = "UPDATE package_schedules 
                                  SET available_seats = :available_seats, status = :status 
                                  WHERE id = :schedule_id";

            $updateScheduleStmt = $this->db->prepare($updateScheduleSql);
            $updateScheduleStmt->execute([
                ':available_seats' => $newAvailableSeats,
                ':status'          => $scheduleStatus,
                ':schedule_id'     => $scheduleId
            ]);

            // --- COMMIT TRANSACTION ---
            $this->db->commit();

            // --- DISPATCH TRANSACTIONAL EMAIL ---
            // Sent out of transaction thread so mail network delays don't block DB row locks
            if (!empty($userEmail)) {
                $subject  = "Booking Confirmation Details - [{$bookingReference}]";
                $htmlBody = Mailer::getBookingReceiptTemplate($userName, $bookingReference, $packageName, $totalAmount);
                Mailer::send($userEmail, $subject, $htmlBody);
            }

            Response::json(201, "Booking successfully created. Pending payment.", [
                'booking_id'        => $bookingId,
                'booking_reference' => $bookingReference,
                'seats_booked'      => $seatsRequested,
                'total_amount'      => $totalAmount,
                'status'            => 'pending'
            ]);
        } catch (Exception $e) {
            // Safety Catch: Rollback transaction on any unhandled failure
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("Booking Engine Exception: " . $e->getMessage());
            Response::json(500, "Internal Server Error: Booking process could not be completed.");
        }
    }


    /**
     * GET /api/bookings
     * Step 4.2a: Retrieve booking history for authenticated user (or all bookings if admin/agent)
     * Protected: All Authenticated Users
     */
    public function index()
    {
        $currentUser = AuthMiddleware::authenticate();
        $userId = (int)$currentUser['sub'];
        $userRole = $currentUser['role'];

        $page  = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 10;
        $offset = ($page - 1) * $limit;

        $statusFilter = isset($_GET['status']) ? trim($_GET['status']) : null;

        $whereClauses = [];
        $params = [];

        // Travelers only see their own bookings; Admins/Agents see all
        if ($userRole === 'traveler') {
            $whereClauses[] = "b.user_id = :user_id";
            $params[':user_id'] = $userId;
        }

        if ($statusFilter) {
            $whereClauses[] = "b.status = :status";
            $params[':status'] = $statusFilter;
        }

        $whereSql = !empty($whereClauses) ? "WHERE " . implode(' AND ', $whereClauses) : "";

        try {
            // Count total items
            $countSql = "SELECT COUNT(*) as total FROM bookings b {$whereSql}";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($params);
            $totalItems = (int)$countStmt->fetch()['total'];

            // Query Bookings safely joining ONLY the latest payment record
            $sql = "SELECT 
                        b.id, 
                        b.booking_reference, 
                        b.seats_booked, 
                        b.total_amount, 
                        b.status as booking_status, 
                        b.created_at,
                        ps.start_date, 
                        ps.end_date, 
                        p.title as package_title,
                        u.full_name as booker_name,
                        u.email as booker_email,
                        pay.transaction_ref as payment_reference,
                        (
                            SELECT photo_url FROM package_photos WHERE package_id = p.id AND photo_type = 'cover' LIMIT 1
                        ) as cover_photo,
                        (
                            SELECT status FROM payments WHERE booking_id = b.id ORDER BY id DESC LIMIT 1
                        ) as latest_payment_status,
                        COALESCE((SELECT SUM(amount) FROM payments WHERE booking_id = b.id AND status = 'paid'), 0) as amount_paid
                    FROM bookings b
                    JOIN package_schedules ps ON b.schedule_id = ps.id
                    JOIN packages p ON ps.package_id = p.id
                    JOIN users u ON b.user_id = u.id
                    LEFT JOIN payments pay ON pay.id = (
                        SELECT MAX(id) FROM payments WHERE booking_id = b.id
                    )
                    {$whereSql}
                    ORDER BY b.id DESC
                    LIMIT :limit OFFSET :offset";

            $stmt = $this->db->prepare($sql);
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

            $stmt->execute();
            $bookings = $stmt->fetchAll();

            $formattedBookings = array_map(function ($b) use ($userRole) {
                // Generate secure JWT token for QR rendering
                $ticketToken = JWT::generateTicketJwt($b);

                $data = [
                    'id'                => (int)$b['id'],
                    'booking_reference' => $b['booking_reference'],
                    'payment_reference' => $b['payment_reference'] ?? 'N/A',
                    'payment_status'    => $b['latest_payment_status'] ?? null,
                    'amount_paid'       => (float)($b['amount_paid'] ?? 0),
                    'package_title'     => $b['package_title'],
                    'cover_photo'       => $b['cover_photo'] ?: null,
                    'seats_booked'      => (int)$b['seats_booked'],
                    'total_amount'      => (float)$b['total_amount'],
                    'status'            => $b['booking_status'],
                    'start_date'        => $b['start_date'],
                    'end_date'          => $b['end_date'],
                    'created_at'        => $b['created_at'],
                    'ticket_token'      => $ticketToken // 👈 Added JWT payload string
                ];

                if ($userRole !== 'traveler') {
                    $data['customer'] = [
                        'name'  => $b['booker_name'],
                        'email' => $b['booker_email']
                    ];
                }

                return $data;
            }, $bookings);

            $totalPages = ceil($totalItems / $limit);

            Response::json(200, "Bookings retrieved successfully.", $formattedBookings, null, [
                'pagination' => [
                    'total_items'  => $totalItems,
                    'total_pages'  => $totalPages,
                    'current_page' => $page,
                    'limit'        => $limit
                ]
            ]);
        } catch (Exception $e) {
            error_log("Booking Index Exception: " . $e->getMessage());
            Response::json(500, "Internal Server Error: Could not fetch bookings.");
        }
    }
    // In BookingController.php
    public function updateStatus()
    {
        $currentUser = AuthMiddleware::authenticate(['admin', 'agent']);
        $input = json_decode(file_get_contents('php://input'), true);

        $bookingId = (int)($input['booking_id'] ?? 0);
        $status    = trim($input['status'] ?? '');

        if ($bookingId <= 0 || !in_array($status, ['confirmed', 'pending', 'cancelled'], true)) {
            Response::json(422, "Invalid payload or status value.");
            return;
        }

        $stmt = $this->db->prepare("UPDATE bookings SET status = :status WHERE id = :id");
        $stmt->execute([':status' => $status, ':id' => $bookingId]);

        Response::json(200, "Booking status updated successfully.");
    }
    /**
     * GET /api/bookings/{id}
     * Step 4.2b: Detailed booking lookup with passenger roster and payment logs
     * Protected: Owner or Admin/Agent
     */
    public function show($id)
    {
        $currentUser = AuthMiddleware::authenticate();
        $userId = (int)$currentUser['sub'];
        $userRole = $currentUser['role'];
        $bookingId = (int)$id;

        if ($bookingId <= 0) {
            Response::json(400, "Invalid booking ID.");
        }

        try {
            // 1. Fetch Master Booking Details
            $sql = "SELECT 
                        b.id, b.booking_reference, b.user_id, b.seats_booked, b.total_amount, b.status as booking_status, b.created_at,
                        ps.start_date, ps.end_date, ps.price as seat_price,
                        p.id as package_id, p.title as package_title, p.description as package_description, p.duration_days,
                        d.city as destination_name, d.country as destination_country,
                        (
                            SELECT photo_url FROM package_photos WHERE package_id = p.id AND photo_type = 'cover' LIMIT 1
                        ) as cover_photo,
                        (
                            SELECT status FROM payments WHERE booking_id = b.id ORDER BY id DESC LIMIT 1
                        ) as latest_payment_status,
                        COALESCE((SELECT SUM(amount) FROM payments WHERE booking_id = b.id AND status = 'paid'), 0) as amount_paid
                    FROM bookings b
                    JOIN package_schedules ps ON b.schedule_id = ps.id
                    JOIN packages p ON ps.package_id = p.id
                    JOIN destinations d ON p.destination_id = d.id
                    WHERE b.id = :id
                    LIMIT 1";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':id' => $bookingId]);
            $booking = $stmt->fetch();

            if (!$booking) {
                Response::json(404, "Booking record not found.");
            }

            // Access Control Guard: Travelers can only view their own bookings
            if ($userRole === 'traveler' && (int)$booking['user_id'] !== $userId) {
                Response::json(403, "Forbidden: You do not have permission to view this booking manifest.");
            }

            // 2. Fetch Passenger Roster
            $passengersSql = "SELECT id, full_name, id_type, id_number, emergency_contact_name, emergency_contact_phone 
                              FROM booking_passengers 
                              WHERE booking_id = :booking_id";
            $pStmt = $this->db->prepare($passengersSql);
            $pStmt->execute([':booking_id' => $bookingId]);
            $passengers = $pStmt->fetchAll();

            $formattedPassengers = array_map(function ($p) {
                return [
                    'id'                      => (int)$p['id'],
                    'full_name'               => $p['full_name'],
                    'id_type'                 => $p['id_type'],
                    'id_number'               => $p['id_number'],
                    'emergency_contact_name'  => $p['emergency_contact_name'],
                    'emergency_contact_phone' => $p['emergency_contact_phone']
                ];
            }, $passengers);

            // 3. Fetch Linked Payments
            $paymentSql = "SELECT id, transaction_ref, amount, payment_method, status, paid_at 
                           FROM payments 
                           WHERE booking_id = :booking_id 
                           ORDER BY id DESC";
            $payStmt = $this->db->prepare($paymentSql);
            $payStmt->execute([':booking_id' => $bookingId]);
            $payments = $payStmt->fetchAll();

            $formattedPayments = array_map(function ($pay) {
                return [
                    'id'              => (int)$pay['id'],
                    'transaction_ref' => $pay['transaction_ref'],
                    'amount'          => (float)$pay['amount'],
                    'payment_method'  => $pay['payment_method'],
                    'status'          => $pay['status'],
                    'paid_at'         => $pay['paid_at']
                ];
            }, $payments);

            // Payload Assembly
            $response = [
                'id'                => (int)$booking['id'],
                'booking_reference' => $booking['booking_reference'],
                'seats_booked'      => (int)$booking['seats_booked'],
                'total_amount'      => (float)$booking['total_amount'],
                'status'            => $booking['booking_status'],
                'payment_status'    => $booking['latest_payment_status'] ?? null,
                'amount_paid'       => (float)($booking['amount_paid'] ?? 0),
                'created_at'        => $booking['created_at'],
                'package'           => [
                    'id'            => (int)$booking['package_id'],
                    'title'         => $booking['package_title'],
                    'description'   => $booking['package_description'],
                    'duration_days' => (int)$booking['duration_days'],
                    'cover_photo'   => $booking['cover_photo'] ?: null,
                    'destination'   => $booking['destination_name'] . ', ' . $booking['destination_country'],
                    'schedule'      => [
                        'start_date' => $booking['start_date'],
                        'end_date'   => $booking['end_date'],
                        'seat_price' => (float)$booking['seat_price']
                    ]
                ],
                'passengers'        => $formattedPassengers,
                'payments'          => $formattedPayments
            ];

            Response::json(200, "Booking details retrieved successfully.", $response);
        } catch (Exception $e) {
            error_log("Booking Show Exception: " . $e->getMessage());
            Response::json(500, "Internal Server Error: Unable to fetch booking details.");
        }
    }
    /**
     * PATCH /api/bookings/{id}/cancel
     * Step Gap B: Atomic Booking Cancellation & Seat Restoration
     * Protected: Owner or Admin/Agent
     */
    /**
     * PATCH /api/bookings/{id}/cancel
     * Cancel booking, restore seat inventory, and notify traveler via email
     */
    public function cancel($id)
    {
        $currentUser = AuthMiddleware::authenticate();
        $userId      = (int)$currentUser['sub'];
        $userRole    = $currentUser['role'];
        $bookingId   = (int)$id;

        if ($bookingId <= 0) {
            Response::json(400, "Invalid booking ID.");
            return;
        }

        try {
            // --- BEGIN ATOMIC TRANSACTION ---
            $this->db->beginTransaction();

            // 1. Fetch & Row-Lock Master Booking Record + JOIN User and Package details
            $bookingSql = "SELECT 
                            b.id, 
                            b.user_id, 
                            b.schedule_id, 
                            b.seats_booked, 
                            b.status, 
                            b.booking_reference,
                            u.full_name as user_name,
                            u.email as user_email,
                            p.title as package_title
                           FROM bookings b
                           JOIN users u ON b.user_id = u.id
                           JOIN package_schedules ps ON b.schedule_id = ps.id
                           JOIN packages p ON ps.package_id = p.id
                           WHERE b.id = :id 
                           FOR UPDATE";

            $bookingStmt = $this->db->prepare($bookingSql);
            $bookingStmt->execute([':id' => $bookingId]);
            $booking = $bookingStmt->fetch();

            if (!$booking) {
                $this->db->rollBack();
                Response::json(404, "Booking record not found.");
                return;
            }

            // Access Control Guard: Travelers can only cancel their own bookings
            if ($userRole === 'traveler' && (int)$booking['user_id'] !== $userId) {
                $this->db->rollBack();
                Response::json(403, "Forbidden: You do not have permission to cancel this booking.");
                return;
            }

            // Idempotency Check: Already cancelled?
            if ($booking['status'] === 'cancelled') {
                $this->db->rollBack();
                Response::json(409, "Conflict: Booking is already cancelled.");
                return;
            }

            $scheduleId     = (int)$booking['schedule_id'];
            $seatsToRestore = (int)$booking['seats_booked'];
            $userEmail      = $booking['user_email'];
            $userName       = $booking['user_name'];
            $bookingRef     = $booking['booking_reference'];
            $packageName    = $booking['package_title'];

            // 2. Lock & Fetch Target Package Schedule Row
            $schedSql = "SELECT id, total_seats, available_seats, status 
                         FROM package_schedules 
                         WHERE id = :schedule_id 
                         FOR UPDATE";

            $schedStmt = $this->db->prepare($schedSql);
            $schedStmt->execute([':schedule_id' => $scheduleId]);
            $schedule = $schedStmt->fetch();

            if (!$schedule) {
                $this->db->rollBack();
                Response::json(404, "Associated package schedule slot not found.");
                return;
            }

            $currentAvailable = (int)$schedule['available_seats'];
            $totalSeats       = (int)$schedule['total_seats'];

            // Calculate restored seat count (capped at total capacity)
            $newAvailable = min($totalSeats, $currentAvailable + $seatsToRestore);

            // 3. Update Booking Status to 'cancelled'
            $updateBookingSql = "UPDATE bookings SET status = 'cancelled' WHERE id = :id";
            $updateBookingStmt = $this->db->prepare($updateBookingSql);
            $updateBookingStmt->execute([':id' => $bookingId]);

            // 4. Restore Available Seats & Re-open Sold Out Schedule Slot
            $updateScheduleSql = "UPDATE package_schedules 
                                  SET available_seats = :available_seats, 
                                      status = 'open' 
                                  WHERE id = :schedule_id";

            $updateScheduleStmt = $this->db->prepare($updateScheduleSql);
            $updateScheduleStmt->execute([
                ':available_seats' => $newAvailable,
                ':schedule_id'     => $scheduleId
            ]);

            // --- COMMIT TRANSACTION ---
            $this->db->commit();

            // --- DISPATCH CANCELLATION EMAIL ---
            if (!empty($userEmail)) {
                $subject  = "Booking Cancellation Notice - [{$bookingRef}]";
                $htmlBody = Mailer::getCancellationTemplate($userName, $bookingRef, $packageName);
                Mailer::send($userEmail, $subject, $htmlBody);
            }

            Response::json(200, "Booking successfully cancelled and seat inventory restored.", [
                'booking_id'          => $bookingId,
                'status'              => 'cancelled',
                'seats_restored'      => $seatsToRestore,
                'new_available_seats' => $newAvailable
            ]);
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("Booking Cancellation Exception: " . $e->getMessage());
            Response::json(500, "Internal Server Error: Could not complete booking cancellation.");
        }
    }
}
