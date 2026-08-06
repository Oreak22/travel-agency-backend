<?php
// controllers/ReviewController.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../middleware/auth.php';

class ReviewController
{

    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * POST /api/reviews
     * Submit a post-trip review (Restricted to travelers with confirmed bookings)
     */
    public function create()
    {
        $currentUser = AuthMiddleware::authenticate();
        $userId = (int)$currentUser['sub'];

        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        if (!$input) {
            Response::json(400, "Invalid JSON payload provided.");
        }

        $packageId = (int)($input['package_id'] ?? 0);
        $bookingId = (int)($input['booking_id'] ?? 0);
        $rating    = (int)($input['rating'] ?? 0);
        $comment   = trim($input['comment'] ?? '');

        $errors = [];
        if ($packageId <= 0) $errors['package_id'] = "Valid package ID is required.";
        if ($bookingId <= 0) $errors['booking_id'] = "Valid booking ID is required.";
        if ($rating < 1 || $rating > 5) $errors['rating'] = "Rating must be an integer between 1 and 5.";
        if (empty($comment) || strlen($comment) < 10) {
            $errors['comment'] = "Review comment must be at least 10 characters long.";
        }

        if (!empty($errors)) {
            Response::json(422, "Validation failed.", null, $errors);
        }

        try {
            // 1. Verify that the traveler has a confirmed booking for this specific package
            $verifySql = "SELECT b.id, b.status 
                          FROM bookings b
                          JOIN package_schedules ps ON b.schedule_id = ps.id
                          WHERE b.id = :booking_id 
                            AND b.user_id = :user_id 
                            AND ps.package_id = :package_id 
                          LIMIT 1";

            $verifyStmt = $this->db->prepare($verifySql);
            $verifyStmt->execute([
                ':booking_id' => $bookingId,
                ':user_id'    => $userId,
                ':package_id' => $packageId
            ]);

            $booking = $verifyStmt->fetch();

            if (!$booking) {
                Response::json(403, "Forbidden: No valid booking found linking this account to the requested package.");
            }

            if ($booking['status'] !== 'confirmed') {
                Response::json(400, "Bad Request: Reviews can only be submitted for confirmed, completed bookings.");
            }

            // 2. Prevent duplicate reviews for the same booking
            $dupStmt = $this->db->prepare("SELECT id FROM reviews WHERE booking_id = :booking_id LIMIT 1");
            $dupStmt->execute([':booking_id' => $bookingId]);

            if ($dupStmt->fetch()) {
                Response::json(409, "Conflict: You have already submitted a review for this booking.");
            }

            // 3. Insert Review
            $sql = "INSERT INTO reviews (package_id, user_id, booking_id, rating, comment, is_approved) 
                    VALUES (:package_id, :user_id, :booking_id, :rating, :comment, 1)";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':package_id' => $packageId,
                ':user_id'    => $userId,
                ':booking_id' => $bookingId,
                ':rating'     => $rating,
                ':comment'    => $comment
            ]);

            $reviewId = (int)$this->db->lastInsertId();

            Response::json(201, "Review submitted successfully.", [
                'id'         => $reviewId,
                'package_id' => $packageId,
                'rating'     => $rating,
                'comment'    => $comment
            ]);
        } catch (Exception $e) {
            error_log("Review Creation Exception: " . $e->getMessage());
            Response::json(500, "Internal Server Error: Unable to submit review.");
        }
    }

    /**
     * GET /api/packages/{id}/reviews
     * Public endpoint to fetch approved reviews for a specific package with rating summary
     */
    public function getPackageReviews($id)
    {
        $packageId = (int)$id;

        if ($packageId <= 0) {
            Response::json(400, "Invalid package ID.");
        }

        $page  = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 20;
        $offset = ($page - 1) * $limit;

        try {
            // Aggregate summary metrics
            $summaryStmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as total_reviews,
                    COALESCE(AVG(rating), 0.0) as average_rating
                FROM reviews 
                WHERE package_id = :package_id AND is_approved = 1
            ");
            $summaryStmt->execute([':package_id' => $packageId]);
            $summary = $summaryStmt->fetch();

            $totalItems    = (int)$summary['total_reviews'];
            $averageRating = round((float)$summary['average_rating'], 2);

            // Fetch paginated review records with reviewer names
            $sql = "SELECT 
                        r.id, r.rating, r.comment, r.created_at,
                        u.full_name as reviewer_name
                    FROM reviews r
                    JOIN users u ON r.user_id = u.id
                    WHERE r.package_id = :package_id AND r.is_approved = 1
                    ORDER BY r.created_at DESC
                    LIMIT :limit OFFSET :offset";

            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':package_id', $packageId, PDO::PARAM_INT);
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $stmt->execute();
            $reviews = $stmt->fetchAll();

            $formattedReviews = array_map(function ($r) {
                return [
                    'id'            => (int)$r['id'],
                    'rating'        => (int)$r['rating'],
                    'comment'       => $r['comment'],
                    'reviewer_name' => $r['reviewer_name'],
                    'created_at'    => $r['created_at']
                ];
            }, $reviews);

            $totalPages = ceil($totalItems / $limit);

            Response::json(200, "Package reviews retrieved successfully.", [
                'summary' => [
                    'total_reviews'  => $totalItems,
                    'average_rating' => $averageRating
                ],
                'reviews' => $formattedReviews
            ], null, [
                'pagination' => [
                    'total_items'  => $totalItems,
                    'total_pages'  => $totalPages,
                    'current_page' => $page,
                    'limit'        => $limit
                ]
            ]);
        } catch (Exception $e) {
            error_log("Get Package Reviews Exception: " . $e->getMessage());
            Response::json(500, "Internal Server Error: Could not fetch package reviews.");
        }
    }
}
