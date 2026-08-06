<?php
// controllers/AdminController.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../middleware/auth.php';

class AdminController
{

    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * GET /api/admin/stats
     * Aggregated metrics for administrative and management dashboards
     * Protected: Admin/Agent only
     */
    public function stats()
    {
        // Enforce RBAC Security
        $currentUser = AuthMiddleware::authenticate(['admin', 'agent']);

        try {
            // 1. Overview Counts
            $usersCountStmt = $this->db->query("SELECT COUNT(*) as total FROM users WHERE role = 'traveler'");
            $totalTravelers = (int)$usersCountStmt->fetch()['total'];

            $pkgCountStmt = $this->db->query("SELECT COUNT(*) as total FROM packages WHERE status = 'active'");
            $activePackages = (int)$pkgCountStmt->fetch()['total'];

            $destCountStmt = $this->db->query("SELECT COUNT(*) as total FROM destinations");
            $totalDestinations = (int)$destCountStmt->fetch()['total'];

            // 2. Financial Metrics (Total Settled Revenue & Pending Revenue)
            $revStmt = $this->db->query("
                SELECT 
                    COALESCE(SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END), 0.00) as total_revenue,
                    COALESCE(SUM(CASE WHEN status = 'pending' THEN amount ELSE 0 END), 0.00) as pending_revenue
                FROM payments
            ");
            $financials = $revStmt->fetch();

            // 3. Booking Volume Breakdown by Status
            $bookingStatusStmt = $this->db->query("
                SELECT 
                    status, 
                    COUNT(*) as count,
                    COALESCE(SUM(total_amount), 0.00) as volume_amount
                FROM bookings 
                GROUP BY status
            ");
            $bookingRows = $bookingStatusStmt->fetchAll();

            $bookingMetrics = [
                'total_bookings' => 0,
                'confirmed'      => 0,
                'pending'        => 0,
                'cancelled'      => 0
            ];

            foreach ($bookingRows as $row) {
                $status = $row['status'];
                $count  = (int)$row['count'];
                $bookingMetrics['total_bookings'] += $count;
                if (isset($bookingMetrics[$status])) {
                    $bookingMetrics[$status] = $count;
                }
            }

            // 4. Occupancy Rate Metrics across Package Schedules
            $occupancyStmt = $this->db->query("
                SELECT 
                    COALESCE(SUM(total_seats), 0) as total_capacity,
                    COALESCE(SUM(total_seats - available_seats), 0) as total_booked_seats
                FROM package_schedules 
                WHERE status != 'cancelled'
            ");
            $occupancy = $occupancyStmt->fetch();

            $totalCapacity    = (int)$occupancy['total_capacity'];
            $totalBookedSeats = (int)$occupancy['total_booked_seats'];
            $occupancyRate    = $totalCapacity > 0 ? round(($totalBookedSeats / $totalCapacity) * 100, 2) : 0.00;

            // 5. Top 5 Popular Destinations by Booking Volume
            $topDestStmt = $this->db->query("
                SELECT 
                    d.id, 
                    d.name, 
                    d.country, 
                    COUNT(b.id) as total_bookings
                FROM destinations d
                JOIN packages p ON p.destination_id = d.id
                JOIN package_schedules ps ON ps.package_id = p.id
                JOIN bookings b ON b.schedule_id = ps.id
                WHERE b.status = 'confirmed'
                GROUP BY d.id, d.name, d.country
                ORDER BY total_bookings DESC
                LIMIT 5
            ");
            $topDestinations = $topDestStmt->fetchAll();

            $formattedTopDestinations = array_map(function ($d) {
                return [
                    'id'             => (int)$d['id'],
                    'name'           => $d['name'],
                    'country'        => $d['country'],
                    'total_bookings' => (int)$d['total_bookings']
                ];
            }, $topDestinations);

            // Response Payload Assembly
            Response::json(200, "Dashboard analytics retrieved successfully.", [
                'overview' => [
                    'total_travelers'    => $totalTravelers,
                    'active_packages'    => $activePackages,
                    'total_destinations' => $totalDestinations
                ],
                'financials' => [
                    'total_revenue'   => (float)$financials['total_revenue'],
                    'pending_revenue' => (float)$financials['pending_revenue']
                ],
                'bookings' => [
                    'total_bookings' => $bookingMetrics['total_bookings'],
                    'confirmed'      => $bookingMetrics['confirmed'],
                    'pending'        => $bookingMetrics['pending'],
                    'cancelled'      => $bookingMetrics['cancelled']
                ],
                'occupancy' => [
                    'total_capacity'     => $totalCapacity,
                    'total_booked_seats' => $totalBookedSeats,
                    'occupancy_rate_pct' => $occupancyRate
                ],
                'top_destinations' => $formattedTopDestinations
            ]);
        } catch (Exception $e) {
            error_log("Admin Stats Exception: " . $e->getMessage());
            Response::json(500, "Internal Server Error: Unable to fetch dashboard analytics.");
        }
    }
}
