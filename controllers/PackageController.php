<?php
// controllers/PackageController.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/Response.php';

class PackageController
{

    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * GET /api/packages
     * Fetch paginated list of active tour packages with optional filtering
     */
    public function index()
    {
        // Query parameters
        $destinationId = isset($_GET['destination_id']) ? (int)$_GET['destination_id'] : null;
        $search        = isset($_GET['search']) ? trim($_GET['search']) : null;
        $minPrice      = isset($_GET['min_price']) ? (float)$_GET['min_price'] : null;
        $maxPrice      = isset($_GET['max_price']) ? (float)$_GET['max_price'] : null;

        $page          = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $limit         = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 10;
        $offset        = ($page - 1) * $limit;

        $whereClauses = ["p.status = 'active'"];
        $params = [];

        if ($destinationId) {
            $whereClauses[] = "p.destination_id = :destination_id";
            $params[':destination_id'] = $destinationId;
        }

        if ($search) {
            $whereClauses[] = "(p.title LIKE :search OR p.description LIKE :search OR d.name LIKE :search OR d.country LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }

        if ($minPrice !== null) {
            $whereClauses[] = "p.base_price >= :min_price";
            $params[':min_price'] = $minPrice;
        }

        if ($maxPrice !== null) {
            $whereClauses[] = "p.base_price <= :max_price";
            $params[':max_price'] = $maxPrice;
        }

        $whereSql = implode(' AND ', $whereClauses);

        try {
            // Count total matching records for pagination meta
            $countSql = "SELECT COUNT(*) as total FROM packages p 
                         JOIN destinations d ON p.destination_id = d.id 
                         WHERE {$whereSql}";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($params);
            $totalItems = (int)$countStmt->fetch()['total'];

            // Fetch package records with cover photo
            $sql = "SELECT 
                        p.id, 
                        p.title, 
                        p.description, 
                        p.base_price, 
                        p.duration_days, 
                        d.id as destination_id, 
                        d.name as destination_name, 
                        d.country as destination_country,
                        (
                            SELECT photo_url FROM package_photos 
                            WHERE package_id = p.id AND photo_type = 'cover' 
                            LIMIT 1
                        ) as cover_photo
                    FROM packages p
                    JOIN destinations d ON p.destination_id = d.id
                    WHERE {$whereSql}
                    ORDER BY p.id DESC
                    LIMIT :limit OFFSET :offset";

            $stmt = $this->db->prepare($sql);

            // Bind parameters
            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

            $stmt->execute();
            $packages = $stmt->fetchAll();

            // Format numerical types
            $formattedPackages = array_map(function ($item) {
                return [
                    'id'            => (int)$item['id'],
                    'title'         => $item['title'],
                    'description'   => $item['description'],
                    'base_price'    => (float)$item['base_price'],
                    'duration_days' => (int)$item['duration_days'],
                    'destination'   => [
                        'id'      => (int)$item['destination_id'],
                        'name'    => $item['destination_name'],
                        'country' => $item['destination_country']
                    ],
                    'cover_photo'   => $item['cover_photo'] ?: null
                ];
            }, $packages);

            $totalPages = ceil($totalItems / $limit);

            Response::json(200, "Packages retrieved successfully.", $formattedPackages, null, [
                'pagination' => [
                    'total_items'  => $totalItems,
                    'total_pages'  => $totalPages,
                    'current_page' => $page,
                    'limit'        => $limit
                ]
            ]);
        } catch (Exception $e) {
            error_log("Package Index Error: " . $e->getMessage());
            Response::json(500, "Internal Server Error: Unable to fetch packages.");
        }
    }

    /**
     * GET /api/packages/{id}
     * Fetch complete package details including schedules, day-by-day itineraries, and photo gallery
     */
    public function show($id)
    {
        $packageId = (int)$id;

        if ($packageId <= 0) {
            Response::json(400, "Invalid package ID provided.");
        }

        try {
            // 1. Master Package & Destination Info
            $packageSql = "SELECT 
                            p.id, p.title, p.description, p.base_price, p.duration_days, p.status, p.created_at,
                            d.id as destination_id, d.name as destination_name, d.country as destination_country, d.description as destination_description
                           FROM packages p
                           JOIN destinations d ON p.destination_id = d.id
                           WHERE p.id = :id AND p.status = 'active'
                           LIMIT 1";

            $packageStmt = $this->db->prepare($packageSql);
            $packageStmt->execute([':id' => $packageId]);
            $package = $packageStmt->fetch();

            if (!$package) {
                Response::json(404, "Tour package not found or inactive.");
            }

            // 2. Schedules (Available upcoming travel slots)
            $scheduleSql = "SELECT id, start_date, end_date, total_seats, available_seats, price, status 
                            FROM package_schedules 
                            WHERE package_id = :package_id AND status = 'open' AND start_date >= CURDATE()
                            ORDER BY start_date ASC";
            $scheduleStmt = $this->db->prepare($scheduleSql);
            $scheduleStmt->execute([':package_id' => $packageId]);
            $schedules = $scheduleStmt->fetchAll();

            $formattedSchedules = array_map(function ($s) {
                return [
                    'id'              => (int)$s['id'],
                    'start_date'      => $s['start_date'],
                    'end_date'        => $s['end_date'],
                    'total_seats'     => (int)$s['total_seats'],
                    'available_seats' => (int)$s['available_seats'],
                    'price'           => (float)$s['price'],
                    'status'          => $s['status']
                ];
            }, $schedules);

            // 3. Day-by-day Itinerary Breakdown
            $itinerarySql = "SELECT id, day_number, title, description, activity_location 
                             FROM package_itineraries 
                             WHERE package_id = :package_id 
                             ORDER BY day_number ASC";
            $itineraryStmt = $this->db->prepare($itinerarySql);
            $itineraryStmt->execute([':package_id' => $packageId]);
            $itineraries = $itineraryStmt->fetchAll();

            $formattedItineraries = array_map(function ($i) {
                return [
                    'id'                => (int)$i['id'],
                    'day_number'        => (int)$i['day_number'],
                    'title'             => $i['title'],
                    'description'       => $i['description'],
                    'activity_location' => $i['activity_location']
                ];
            }, $itineraries);

            // 4. Photos Gallery (Cover, Marketing, Traveler Memories)
            $photoSql = "SELECT id, photo_url, caption, photo_type, created_at 
                         FROM package_photos 
                         WHERE package_id = :package_id AND is_approved = 1 
                         ORDER BY photo_type ASC, id DESC";
            $photoStmt = $this->db->prepare($photoSql);
            $photoStmt->execute([':package_id' => $packageId]);
            $photos = $photoStmt->fetchAll();

            $formattedPhotos = array_map(function ($p) {
                return [
                    'id'         => (int)$p['id'],
                    'photo_url'  => $p['photo_url'],
                    'caption'    => $p['caption'],
                    'photo_type' => $p['photo_type'],
                    'created_at' => $p['created_at']
                ];
            }, $photos);

            // Assemble Comprehensive Package Payload
            $responsePayload = [
                'id'            => (int)$package['id'],
                'title'         => $package['title'],
                'description'   => $package['description'],
                'base_price'    => (float)$package['base_price'],
                'duration_days' => (int)$package['duration_days'],
                'status'        => $package['status'],
                'destination'   => [
                    'id'          => (int)$package['destination_id'],
                    'name'        => $package['destination_name'],
                    'country'     => $package['destination_country'],
                    'description' => $package['destination_description']
                ],
                'schedules'     => $formattedSchedules,
                'itineraries'   => $formattedItineraries,
                'photos'        => $formattedPhotos
            ];

            Response::json(200, "Package details retrieved successfully.", $responsePayload);
        } catch (Exception $e) {
            error_log("Package Show Error: " . $e->getMessage());
            Response::json(500, "Internal Server Error: Unable to fetch package details.");
        }
    }
    /**
     * POST /api/admin/packages
     * Step 3.2a: Create a new Master Tour Package
     * Protected: Admin/Agent only
     */
    public function createPackage()
    {
        // Enforce RBAC
        $currentUser = AuthMiddleware::authenticate(['admin', 'agent']);

        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        if (!$input) {
            Response::json(400, "Invalid JSON payload provided.");
        }

        $destinationId = (int)($input['destination_id'] ?? 0);
        $title         = trim($input['title'] ?? '');
        $description   = trim($input['description'] ?? '');
        $basePrice     = (float)($input['base_price'] ?? 0);
        $durationDays  = (int)($input['duration_days'] ?? 0);

        $errors = [];
        if ($destinationId <= 0) $errors['destination_id'] = "A valid destination ID is required.";
        if (empty($title))        $errors['title']          = "Package title is required.";
        if (empty($description))  $errors['description']    = "Package description is required.";
        if ($basePrice <= 0)      $errors['base_price']     = "Base price must be greater than zero.";
        if ($durationDays <= 0)   $errors['duration_days']  = "Duration days must be at least 1.";

        if (!empty($errors)) {
            Response::json(422, "Validation failed.", null, $errors);
        }

        try {
            // Verify destination exists
            $destCheck = $this->db->prepare("SELECT id FROM destinations WHERE id = :id LIMIT 1");
            $destCheck->execute([':id' => $destinationId]);
            if (!$destCheck->fetch()) {
                Response::json(404, "Destination not found.", null, ['destination_id' => "Selected destination does not exist."]);
            }

            $sql = "INSERT INTO packages (destination_id, title, description, base_price, duration_days, status) 
                    VALUES (:destination_id, :title, :description, :base_price, :duration_days, 'active')";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':destination_id' => $destinationId,
                ':title'          => $title,
                ':description'    => $description,
                ':base_price'     => $basePrice,
                ':duration_days'  => $durationDays
            ]);

            $packageId = (int)$this->db->lastInsertId();

            Response::json(201, "Package created successfully.", [
                'package_id' => $packageId,
                'title'      => $title
            ]);
        } catch (Exception $e) {
            error_log("Package Creation Exception: " . $e->getMessage());
            Response::json(500, "Internal Server Error: Could not create package.");
        }
    }

    /**
     * POST /api/admin/packages/{id}/schedules
     * Step 3.2b: Create a dynamic date schedule slot for a package
     * Protected: Admin/Agent only
     */
    public function addSchedule($packageId)
    {
        $currentUser = AuthMiddleware::authenticate(['admin', 'agent']);
        $packageId = (int)$packageId;

        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        if (!$input) {
            Response::json(400, "Invalid JSON payload provided.");
        }

        $startDate  = trim($input['start_date'] ?? '');
        $endDate    = trim($input['end_date'] ?? '');
        $totalSeats = (int)($input['total_seats'] ?? 0);
        $price      = isset($input['price']) ? (float)$input['price'] : null;

        $errors = [];
        if (empty($startDate) || !strtotime($startDate)) $errors['start_date']  = "Valid start date required (YYYY-MM-DD).";
        if (empty($endDate) || !strtotime($endDate))     $errors['end_date']    = "Valid end date required (YYYY-MM-DD).";
        if (strtotime($endDate) < strtotime($startDate)) $errors['date_range']  = "End date cannot be prior to start date.";
        if ($totalSeats <= 0)                            $errors['total_seats'] = "Total seats must be greater than zero.";

        if (!empty($errors)) {
            Response::json(422, "Validation failed.", null, $errors);
        }

        try {
            // Verify package exists and fetch base price if dynamic price not passed
            $pkgStmt = $this->db->prepare("SELECT id, base_price FROM packages WHERE id = :id LIMIT 1");
            $pkgStmt->execute([':id' => $packageId]);
            $package = $pkgStmt->fetch();

            if (!$package) {
                Response::json(404, "Package not found.");
            }

            $finalPrice = ($price !== null && $price > 0) ? $price : (float)$package['base_price'];

            $sql = "INSERT INTO package_schedules (package_id, start_date, end_date, total_seats, available_seats, price, status) 
                    VALUES (:package_id, :start_date, :end_date, :total_seats, :available_seats, :price, 'open')";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':package_id'      => $packageId,
                ':start_date'      => $startDate,
                ':end_date'        => $endDate,
                ':total_seats'     => $totalSeats,
                ':available_seats' => $totalSeats, // Initial available seats equals total capacity
                ':price'           => $finalPrice
            ]);

            $scheduleId = (int)$this->db->lastInsertId();

            Response::json(201, "Package schedule added successfully.", [
                'schedule_id'     => $scheduleId,
                'package_id'      => $packageId,
                'available_seats' => $totalSeats,
                'price'           => $finalPrice
            ]);
        } catch (Exception $e) {
            error_log("Schedule Add Exception: " . $e->getMessage());
            Response::json(500, "Internal Server Error: Could not add package schedule.");
        }
    }

    /**
     * POST /api/admin/packages/{id}/itineraries
     * Step 3.2c: Add a day-by-day itinerary item
     * Protected: Admin/Agent only
     */
    public function addItinerary($packageId)
    {
        $currentUser = AuthMiddleware::authenticate(['admin', 'agent']);
        $packageId = (int)$packageId;

        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        if (!$input) {
            Response::json(400, "Invalid JSON payload provided.");
        }

        $dayNumber        = (int)($input['day_number'] ?? 0);
        $title            = trim($input['title'] ?? '');
        $description      = trim($input['description'] ?? '');
        $activityLocation = trim($input['activity_location'] ?? '');

        $errors = [];
        if ($dayNumber <= 0)    $errors['day_number']  = "Day number must be 1 or greater.";
        if (empty($title))       $errors['title']       = "Itinerary step title is required.";
        if (empty($description)) $errors['description'] = "Itinerary description is required.";

        if (!empty($errors)) {
            Response::json(422, "Validation failed.", null, $errors);
        }

        try {
            $sql = "INSERT INTO package_itineraries (package_id, day_number, title, description, activity_location) 
                    VALUES (:package_id, :day_number, :title, :description, :activity_location)";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':package_id'        => $packageId,
                ':day_number'        => $dayNumber,
                ':title'             => $title,
                ':description'       => $description,
                ':activity_location' => !empty($activityLocation) ? $activityLocation : null
            ]);

            $itineraryId = (int)$this->db->lastInsertId();

            Response::json(201, "Itinerary item added successfully.", [
                'itinerary_id' => $itineraryId,
                'day_number'   => $dayNumber,
                'title'        => $title
            ]);
        } catch (PDOException $e) {
            if ($e->getCode() == '23000') { // SQL Duplicate entry for unique constraint
                Response::json(409, "Conflict: Day number {$dayNumber} already exists for this package.");
            }
            error_log("Itinerary Add Exception: " . $e->getMessage());
            Response::json(500, "Internal Server Error: Could not add itinerary.");
        }
    }
}
