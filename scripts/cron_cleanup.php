<?php
// scripts/cron_cleanup.php

// Ensure execution is restricted to CLI mode only
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Forbidden: This maintenance script can only be executed via the command line (CLI).\n";
    exit(1);
}

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';

// Initialize environment configuration
EnvLoader::load(__DIR__ . '/../.env');

$logPrefix = "[" . date('Y-m-d H:i:s') . "] [CRON CLEANUP] ";
echo "{$logPrefix}Starting automated database maintenance script...\n";

try {
    $db = Database::getInstance()->getConnection();

    // ------------------------------------------------------------------
    // TASK 1: Expire Pending Bookings > 24 Hours & Restore Seat Inventory
    // ------------------------------------------------------------------
    echo "{$logPrefix}Scanning for stale pending bookings (> 24 hours old)...\n";

    // 1. Fetch expired pending bookings
    $staleSql = "SELECT id, schedule_id, seats_booked 
                 FROM bookings 
                 WHERE status = 'pending' 
                   AND created_at < NOW() - INTERVAL 24 HOUR";

    $staleStmt = $db->query($staleSql);
    $staleBookings = $staleStmt->fetchAll(PDO::FETCH_ASSOC);

    $expiredCount = 0;

    foreach ($staleBookings as $booking) {
        $bookingId   = (int)$booking['id'];
        $scheduleId  = (int)$booking['schedule_id'];
        $seatsBooked = (int)$booking['seats_booked'];

        try {
            $db->beginTransaction();

            // Row-lock target schedule slot
            $schedSql = "SELECT total_seats, available_seats FROM package_schedules WHERE id = :id FOR UPDATE";
            $schedStmt = $db->prepare($schedSql);
            $schedStmt->execute([':id' => $scheduleId]);
            $schedule = $schedStmt->fetch(PDO::FETCH_ASSOC);

            if ($schedule) {
                $newAvailable = min((int)$schedule['total_seats'], (int)$schedule['available_seats'] + $seatsBooked);

                // Update schedule seat inventory & ensure status is open
                $updateSched = $db->prepare("UPDATE package_schedules SET available_seats = :available, status = 'open' WHERE id = :id");
                $updateSched->execute([
                    ':available' => $newAvailable,
                    ':id'        => $scheduleId
                ]);
            }

            // Update booking status to 'cancelled'
            $updateBooking = $db->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = :id");
            $updateBooking->execute([':id' => $bookingId]);

            $db->commit();
            $expiredCount++;

            echo "{$logPrefix}Expired Booking #{$bookingId}: Restored {$seatsBooked} seat(s) to Schedule #{$scheduleId}.\n";
        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("{$logPrefix}Failed to cancel stale booking #{$bookingId}: " . $e->getMessage());
        }
    }

    echo "{$logPrefix}Task 1 Complete: Expired {$expiredCount} stale booking(s).\n";

    // ------------------------------------------------------------------
    // TASK 2: Auto-Close Past Package Schedules
    // ------------------------------------------------------------------
    echo "{$logPrefix}Scanning for expired package schedules (start_date in the past)...\n";

    $closeSchedSql = "UPDATE package_schedules 
                      SET status = 'closed' 
                      WHERE status = 'open' 
                        AND start_date < CURRENT_DATE()";

    $closeStmt = $db->prepare($closeSchedSql);
    $closeStmt->execute();
    $closedCount = $closeStmt->rowCount();

    echo "{$logPrefix}Task 2 Complete: Auto-closed {$closedCount} past package schedule(s).\n";

    echo "{$logPrefix}Maintenance script completed successfully.\n";
    exit(0);
} catch (Exception $e) {
    error_log("{$logPrefix}Critical Maintenance Script Exception: " . $e->getMessage());
    echo "{$logPrefix}FATAL ERROR: Maintenance script aborted.\n";
    exit(1);
}
