<?php
// controllers/PackageController.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../middleware/auth.php';

class PackageController
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * GET /api/packages
     * Fetch paginated list of active tour packages
     */
    /**
     * GET /api/packages
     * Fetch paginated list of packages (with optional status filtering)
     */
    public function index()
    {
        $destinationId = isset($_GET['destination_id']) ? (int)$_GET['destination_id'] : null;
        $search        = isset($_GET['search']) ? trim($_GET['search']) : null;
        $status        = isset($_GET['status']) ? trim($_GET['status']) : null;
        $min_price     = isset($_GET['min_price']) ? (float)$_GET['min_price'] : null;
        $max_price     = isset($_GET['max_price']) ? (float)$_GET['max_price'] : null;

        $page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $limit  = isset($_GET['limit']) ? min(50, max(1, (int)$_GET['limit'])) : 10;
        $offset = ($page - 1) * $limit;

        $whereClauses = ["1=1"];
        $params = [];

        if ($status) {
            $whereClauses[] = "p.status = :status";
            $params[':status'] = $status;
        }

        if ($destinationId) {
            $whereClauses[] = "p.destination_id = :destination_id";
            $params[':destination_id'] = $destinationId;
        }

        if ($search) {
            $whereClauses[] = "(p.title LIKE :search_title OR p.description LIKE :search_desc OR d.city LIKE :search_city OR d.country LIKE :search_country)";
            $searchTerm = '%' . $search . '%';
            $params[':search_title']   = $searchTerm;
            $params[':search_desc']    = $searchTerm;
            $params[':search_city']    = $searchTerm;
            $params[':search_country'] = $searchTerm;
        }

        if ($min_price !== null) {
            $whereClauses[] = "p.base_price >= :min_price";
            $params[':min_price'] = $min_price;
        }

        if ($max_price !== null) {
            $whereClauses[] = "p.base_price <= :max_price";
            $params[':max_price'] = $max_price;
        }

        $whereSql = implode(' AND ', $whereClauses);

        try {
            $countSql = "SELECT COUNT(*) as total FROM packages p 
                         JOIN destinations d ON p.destination_id = d.id 
                         WHERE {$whereSql}";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($params);
            $totalItems = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['total'];

            // Added subqueries for total_capacity and booked_seats
            $sql = "SELECT 
                        p.id, 
                        p.title, 
                        p.description, 
                        p.base_price, 
                        p.duration_days, 
                        p.status,
                        d.id as destination_id, 
                        d.city as destination_name, 
                        d.country as destination_country,
                        (
                            SELECT photo_url FROM package_photos 
                            WHERE package_id = p.id AND photo_type = 'cover' 
                            LIMIT 1
                        ) as cover_photo,
                        COALESCE((
                            SELECT SUM(total_seats) 
                            FROM package_schedules 
                            WHERE package_id = p.id
                        ), 30) as total_capacity,
                        COALESCE((
                            SELECT SUM(total_seats - available_seats) 
                            FROM package_schedules 
                            WHERE package_id = p.id
                        ), 0) as booked_seats
                    FROM packages p
                    JOIN destinations d ON p.destination_id = d.id
                    WHERE {$whereSql}
                    ORDER BY p.id DESC
                    LIMIT :limit OFFSET :offset";

            $stmt = $this->db->prepare($sql);

            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

            $stmt->execute();
            $packages = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $formattedPackages = array_map(function ($item) {
                return [
                    'id'             => (int)$item['id'],
                    'title'          => $item['title'],
                    'description'    => $item['description'],
                    'base_price'     => (float)$item['base_price'],
                    'duration_days'  => (int)$item['duration_days'],
                    'status'         => $item['status'],
                    'total_capacity' => (int)$item['total_capacity'],
                    'booked_seats'   => (int)$item['booked_seats'],
                    'destination'    => [
                        'id'      => (int)$item['destination_id'],
                        'name'    => $item['destination_name'],
                        'country' => $item['destination_country']
                    ],
                    'cover_photo'    => $item['cover_photo'] ?: null
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
            error_log("Package Fetch Error: " . $e->getMessage());
            Response::json(500, "Database Error: " . $e->getMessage());
        }
    }

    /**
     * GET /api/packages/{id}
     * Fetch complete package details including schedules, itineraries, and photo gallery
     */
    public function show($id)
    {
        $packageId = (int)$id;

        if ($packageId <= 0) {
            Response::json(400, "Invalid package ID provided.");
            return;
        }

        try {
            $packageSql = "SELECT 
                        p.id, p.title, p.description, p.base_price, p.duration_days, p.status, p.created_at,
                        d.id as destination_id, d.city as destination_name, d.country as destination_country, d.description as destination_description
                       FROM packages p
                       JOIN destinations d ON p.destination_id = d.id
                       WHERE p.id = :id AND p.status = 'active'
                       LIMIT 1";

            $packageStmt = $this->db->prepare($packageSql);
            $packageStmt->execute([':id' => $packageId]);
            $package = $packageStmt->fetch(PDO::FETCH_ASSOC);

            if (!$package) {
                Response::json(404, "Tour package not found or inactive.");
                return;
            }

            // Fetch Schedules
            $scheduleSql = "SELECT id, start_date, end_date, total_seats, available_seats, price, status 
                        FROM package_schedules 
                        WHERE package_id = :package_id AND status = 'open' AND start_date >= CURDATE()
                        ORDER BY start_date ASC";
            $scheduleStmt = $this->db->prepare($scheduleSql);
            $scheduleStmt->execute([':package_id' => $packageId]);
            $schedules = $scheduleStmt->fetchAll(PDO::FETCH_ASSOC);

            $totalCapacity = 0;
            $bookedSeats = 0;

            $formattedSchedules = array_map(function ($s) use (&$totalCapacity, &$bookedSeats) {
                $total = (int)$s['total_seats'];
                $available = (int)$s['available_seats'];
                $booked = max(0, $total - $available);

                $totalCapacity += $total;
                $bookedSeats += $booked;

                return [
                    'id'              => (int)$s['id'],
                    'start_date'      => $s['start_date'],
                    'end_date'        => $s['end_date'],
                    'total_seats'     => $total,
                    'available_seats' => $available,
                    'booked_seats'    => $booked,
                    'price'           => (float)$s['price'],
                    'status'          => $s['status']
                ];
            }, $schedules);

            // Fetch Itineraries
            $itinerarySql = "SELECT id, day_number, title, description, activity_location 
                         FROM package_itineraries 
                         WHERE package_id = :package_id 
                         ORDER BY day_number ASC";
            $itineraryStmt = $this->db->prepare($itinerarySql);
            $itineraryStmt->execute([':package_id' => $packageId]);
            $itineraries = $itineraryStmt->fetchAll(PDO::FETCH_ASSOC);

            $formattedItineraries = array_map(function ($i) {
                return [
                    'id'                => (int)$i['id'],
                    'day_number'        => (int)$i['day_number'],
                    'title'             => $i['title'],
                    'description'       => $i['description'],
                    'activity_location' => $i['activity_location']
                ];
            }, $itineraries);

            // Fetch Photos
            $photoSql = "SELECT id, photo_url, caption, photo_type, created_at 
                     FROM package_photos 
                     WHERE package_id = :package_id AND is_approved = 1 
                     ORDER BY photo_type ASC, id DESC";
            $photoStmt = $this->db->prepare($photoSql);
            $photoStmt->execute([':package_id' => $packageId]);
            $photos = $photoStmt->fetchAll(PDO::FETCH_ASSOC);

            $formattedPhotos = array_map(function ($p) {
                return [
                    'id'         => (int)$p['id'],
                    'photo_url'  => $p['photo_url'],
                    'caption'    => $p['caption'],
                    'photo_type' => $p['photo_type'],
                    'created_at' => $p['created_at']
                ];
            }, $photos);

            // Construct Response Payload
            $responsePayload = [
                'id'             => (int)$package['id'],
                'title'          => $package['title'],
                'description'    => $package['description'],
                'base_price'     => (float)$package['base_price'],
                'duration_days'  => (int)$package['duration_days'],
                'status'         => $package['status'],
                'total_capacity' => $totalCapacity,
                'booked_seats'   => $bookedSeats,
                'occupancy_rate' => $totalCapacity > 0 ? round(($bookedSeats / $totalCapacity) * 100, 2) : 0,
                'destination'    => [
                    'id'          => (int)$package['destination_id'],
                    'name'        => $package['destination_name'],
                    'country'     => $package['destination_country'],
                    'description' => $package['destination_description']
                ],
                'schedules'      => $formattedSchedules,
                'itineraries'    => $formattedItineraries,
                'photos'         => $formattedPhotos
            ];

            Response::json(200, "Package details retrieved successfully.", $responsePayload);
        } catch (Exception $e) {
            error_log("Package Show Error: " . $e->getMessage());
            Response::json(500, "Internal Server Error: Unable to fetch package details.");
        }
    }

    /**
     * POST /api/admin/packages
     * Wizard Step 1: Create a new Master Tour Package
     */
    public function createPackage()
    {
        $currentUser = AuthMiddleware::authenticate(['admin', 'agent']);

        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        if (!$input) {
            Response::json(400, "Invalid JSON payload provided.");
            return;
        }

        // Accept both base_price and basePrice from Angular template
        $destinationId = (int)($input['destination_id'] ?? 0);
        $title         = trim($input['title'] ?? '');
        $description   = trim($input['description'] ?? '');
        $basePrice     = (float)($input['base_price'] ?? $input['basePrice'] ?? 0);
        $durationDays  = (int)($input['duration_days'] ?? 0);
        $status        = trim($input['status'] ?? 'draft');

        $errors = [];
        if ($destinationId <= 0) $errors['destination_id'] = "A valid destination ID is required.";
        if (empty($title))        $errors['title']          = "Package title is required.";
        if (empty($description))  $errors['description']    = "Package description is required.";
        if ($basePrice <= 0)      $errors['base_price']     = "Base price must be greater than zero.";
        if ($durationDays <= 0)   $errors['duration_days']  = "Duration days must be at least 1.";

        if (!empty($errors)) {
            Response::json(422, "Validation failed.", null, $errors);
            return;
        }

        try {
            $destCheck = $this->db->prepare("SELECT id FROM destinations WHERE id = :id LIMIT 1");
            $destCheck->execute([':id' => $destinationId]);
            if (!$destCheck->fetch()) {
                Response::json(404, "Destination not found.", null, ['destination_id' => "Selected destination does not exist."]);
                return;
            }

            $sql = "INSERT INTO packages (destination_id, title, description, base_price, duration_days, status) 
                    VALUES (:destination_id, :title, :description, :base_price, :duration_days, :status)";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':destination_id' => $destinationId,
                ':title'          => $title,
                ':description'    => $description,
                ':base_price'     => $basePrice,
                ':duration_days'  => $durationDays,
                ':status'         => $status
            ]);

            $packageId = (int)$this->db->lastInsertId();

            Response::json(201, "Package created successfully.", [
                'id'         => $packageId,
                'package_id' => $packageId,
                'title'      => $title,
                'base_price' => $basePrice,
                'status'     => $status
            ]);
        } catch (Exception $e) {
            error_log("Package Creation Exception: " . $e->getMessage());
            Response::json(500, "Internal Server Error: " . $e->getMessage());
        }
    }

    /**
     * POST /api/admin/packages/{id}/schedules
     * Wizard Step 2: Create date schedule slot
     */
    public function addSchedule($packageId)
    {
        $currentUser = AuthMiddleware::authenticate(['admin', 'agent']);
        $packageId = (int)$packageId;

        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        if (!$input) {
            Response::json(400, "Invalid JSON payload provided.");
            return;
        }

        $startDate  = trim($input['start_date'] ?? '');
        $endDate    = trim($input['end_date'] ?? '');
        $totalSeats = (int)($input['total_seats'] ?? $input['max_capacity'] ?? 0);
        $price      = isset($input['price']) ? (float)$input['price'] : null;

        $errors = [];
        if (empty($startDate) || !strtotime($startDate)) $errors['start_date']  = "Valid start date required (YYYY-MM-DD).";
        if (empty($endDate) || !strtotime($endDate))     $errors['end_date']    = "Valid end date required (YYYY-MM-DD).";
        if (strtotime($endDate) < strtotime($startDate)) $errors['date_range']  = "End date cannot be prior to start date.";
        if ($totalSeats <= 0)                            $errors['total_seats'] = "Total seats capacity must be greater than zero.";

        if (!empty($errors)) {
            Response::json(422, "Validation failed.", null, $errors);
            return;
        }

        try {
            $pkgStmt = $this->db->prepare("SELECT id, base_price FROM packages WHERE id = :id LIMIT 1");
            $pkgStmt->execute([':id' => $packageId]);
            $package = $pkgStmt->fetch(PDO::FETCH_ASSOC);

            if (!$package) {
                Response::json(404, "Package not found.");
                return;
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
                ':available_seats' => $totalSeats,
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
            Response::json(500, "Internal Server Error: " . $e->getMessage());
        }
    }

    /**
     * POST /api/admin/packages/{id}/itineraries
     * Wizard Step 3: Add day-by-day itinerary item
     */
    public function addItinerary($packageId)
    {
        $currentUser = AuthMiddleware::authenticate(['admin', 'agent']);
        $packageId = (int)$packageId;

        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        if (!$input) {
            Response::json(400, "Invalid JSON payload provided.");
            return;
        }

        // Support both single object and bulk { days: [...] } wrapper
        $items = isset($input['days']) && is_array($input['days']) ? $input['days'] : [$input];

        if (empty($items)) {
            Response::json(422, "Validation failed: No itinerary items provided.");
            return;
        }

        try {
            $this->db->beginTransaction();

            $sql = "INSERT INTO package_itineraries (package_id, day_number, title, description, activity_location) 
                    VALUES (:package_id, :day_number, :title, :description, :activity_location)
                    ON DUPLICATE KEY UPDATE 
                        title = VALUES(title), 
                        description = VALUES(description), 
                        activity_location = VALUES(activity_location)";

            $stmt = $this->db->prepare($sql);

            foreach ($items as $index => $item) {
                $dayNumber        = (int)($item['day_number'] ?? ($index + 1));
                $title            = trim($item['title'] ?? '');
                $description      = trim($item['description'] ?? '');
                $activityLocation = trim($item['activity_location'] ?? '');

                if ($dayNumber <= 0 || empty($title) || empty($description)) {
                    $this->db->rollBack();
                    Response::json(422, "Validation failed on day {$dayNumber}: Title and description are required.");
                    return;
                }

                $stmt->execute([
                    ':package_id'        => $packageId,
                    ':day_number'        => $dayNumber,
                    ':title'             => $title,
                    ':description'       => $description,
                    ':activity_location' => !empty($activityLocation) ? $activityLocation : null
                ]);
            }

            $this->db->commit();
            Response::json(201, "Itinerary items updated successfully.");
        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            error_log("Itinerary Add Exception: " . $e->getMessage());
            Response::json(500, "Internal Server Error: " . $e->getMessage());
        }
    }
    /**
     * POST /api/admin/packages/{id}/photos
     * Step 4a: Attach a cover or gallery photo to a package
     * Protected: Admin/Agent only
     */
    /**
     * POST /api/admin/packages/{id}/photos
     * Attach a photo to a package via direct file upload (Cloudinary) or raw image URL
     * Protected: Admin/Agent only
     */
    public function addPhotos($packageId)
    {
        require_once __DIR__ . '/../helpers/Cloudinary.php';

        $currentUser = AuthMiddleware::authenticate(['admin', 'agent']);
        $packageId = (int)$packageId;

        // Initialize defaults
        $photoUrl = null;
        $publicId = null;
        $photoType = $_POST['photo_type'] ?? 'cover'; // 'cover', 'marketing', or 'gallery'
        $caption   = trim($_POST['caption'] ?? '');

        // 1. Direct Binary File Upload via $_FILES (multipart/form-data)
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $tmpFilePath = $_FILES['photo']['tmp_name'];

            try {
                // Upload temporary binary file to Cloudinary
                $uploadResult = CloudinaryHelper::upload($tmpFilePath, 'packages');
                $photoUrl = $uploadResult['secure_url'];
                $publicId = $uploadResult['public_id'];
            } catch (Exception $e) {
                error_log("Cloudinary Upload Error: " . $e->getMessage());
                Response::json(500, "Image upload failed: " . $e->getMessage());
                return;
            }
        }
        // 2. Fallback: Parse raw JSON input body for pre-uploaded photo_url
        else {
            $rawInput = file_get_contents('php://input');
            $input = json_decode($rawInput, true) ?? [];

            $photoUrl  = trim($input['photo_url'] ?? $input['file_url'] ?? $input['image_url'] ?? '');
            $photoType = trim($input['photo_type'] ?? $photoType);
            $caption   = trim($input['caption'] ?? $caption);
            $publicId  = trim($input['cloudinary_public_id'] ?? $input['public_id'] ?? '');
        }

        // Validation
        if (empty($photoUrl)) {
            Response::json(422, "Validation failed.", null, ['photo' => "An image file or valid photo_url is required."]);
            return;
        }

        try {
            // Check package existence
            $pkgStmt = $this->db->prepare("SELECT id FROM packages WHERE id = :id LIMIT 1");
            $pkgStmt->execute([':id' => $packageId]);
            if (!$pkgStmt->fetch()) {
                Response::json(404, "Package not found.");
                return;
            }

            // If photo_type is 'cover', demote previous cover photos for this package to 'gallery'
            if ($photoType === 'cover') {
                $demoteStmt = $this->db->prepare("UPDATE package_photos SET photo_type = 'gallery' WHERE package_id = :package_id AND photo_type = 'cover'");
                $demoteStmt->execute([':package_id' => $packageId]);
            }

            $sql = "INSERT INTO package_photos (package_id, user_id, photo_url, cloudinary_public_id, caption, photo_type, is_approved) 
                    VALUES (:package_id, :user_id, :photo_url, :cloudinary_public_id, :caption, :photo_type, 1)";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':package_id'           => $packageId,
                ':user_id'              => $currentUser['id'] ?? null,
                ':photo_url'             => $photoUrl,
                ':cloudinary_public_id' => !empty($publicId) ? $publicId : null,
                ':caption'              => !empty($caption) ? $caption : null,
                ':photo_type'           => in_array($photoType, ['cover', 'marketing', 'gallery']) ? $photoType : 'gallery'
            ]);

            $photoId = (int)$this->db->lastInsertId();

            Response::json(201, "Package photo added successfully.", [
                'id'                   => $photoId,
                'package_id'           => $packageId,
                'photo_url'            => $photoUrl,
                'cloudinary_public_id' => $publicId,
                'photo_type'           => $photoType
            ]);
        } catch (Exception $e) {
            error_log("Photo Add Exception: " . $e->getMessage());
            Response::json(500, "Internal Server Error: " . $e->getMessage());
        }
    }
    /**
     * PUT /api/admin/packages/{id}
     * Update basic package details
     */
    public function updatePackage($id)
    {
        $currentUser = AuthMiddleware::authenticate(['admin', 'agent']);
        $packageId = (int)$id;

        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        if (!$input) {
            Response::json(400, "Invalid JSON payload provided.");
            return;
        }

        try {
            $pkgStmt = $this->db->prepare("SELECT id FROM packages WHERE id = :id LIMIT 1");
            $pkgStmt->execute([':id' => $packageId]);
            if (!$pkgStmt->fetch()) {
                Response::json(404, "Package not found.");
                return;
            }

            $destinationId = (int)($input['destination_id'] ?? 0);
            $title         = trim($input['title'] ?? '');
            $description   = trim($input['description'] ?? '');
            $basePrice     = (float)($input['base_price'] ?? $input['basePrice'] ?? 0);
            $durationDays  = (int)($input['duration_days'] ?? 0);

            $errors = [];
            if ($destinationId <= 0) $errors['destination_id'] = "A valid destination ID is required.";
            if (empty($title))        $errors['title']          = "Package title is required.";
            if (empty($description))  $errors['description']    = "Package description is required.";
            if ($basePrice <= 0)      $errors['base_price']     = "Base price must be greater than zero.";
            if ($durationDays <= 0)   $errors['duration_days']  = "Duration days must be at least 1.";

            if (!empty($errors)) {
                Response::json(422, "Validation failed.", null, $errors);
                return;
            }

            $sql = "UPDATE packages 
                    SET destination_id = :destination_id, 
                        title = :title, 
                        description = :description, 
                        base_price = :base_price, 
                        duration_days = :duration_days, 
                        updated_at = NOW() 
                    WHERE id = :id";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':destination_id' => $destinationId,
                ':title'          => $title,
                ':description'    => $description,
                ':base_price'     => $basePrice,
                ':duration_days'  => $durationDays,
                ':id'             => $packageId
            ]);

            Response::json(200, "Package updated successfully.");
        } catch (Exception $e) {
            error_log("Package Update Error: " . $e->getMessage());
            Response::json(500, "Internal Server Error: " . $e->getMessage());
        }
    }

    /**
     * PATCH /api/admin/packages/{id}/status
     * Update package status (active, inactive, draft, archived)
     */
    public function updateStatus($id)
    {
        $currentUser = AuthMiddleware::authenticate(['admin', 'agent']);
        $packageId = (int)$id;

        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        $status = trim($input['status'] ?? '');
        $allowedStatuses = ['active', 'inactive', 'draft', 'archived'];

        if (!in_array($status, $allowedStatuses, true)) {
            Response::json(422, "Invalid status provided. Must be active, inactive, draft, or archived.");
            return;
        }

        try {
            $stmt = $this->db->prepare("UPDATE packages SET status = :status, updated_at = NOW() WHERE id = :id");
            $stmt->execute([':status' => $status, ':id' => $packageId]);

            if ($stmt->rowCount() === 0) {
                Response::json(404, "Package not found or status unchanged.");
                return;
            }

            Response::json(200, "Package status updated to '{$status}'.");
        } catch (Exception $e) {
            error_log("Status Update Error: " . $e->getMessage());
            Response::json(500, "Internal Server Error: " . $e->getMessage());
        }
    }

    /**
     * DELETE /api/admin/packages/{id}
     * Remove or soft-archive a package
     */
    public function deletePackage($id)
    {
        $currentUser = AuthMiddleware::authenticate(['admin']);
        $packageId = (int)$id;

        try {
            // Check if active bookings exist on any schedules for this package
            $checkSql = "SELECT COUNT(*) as active_bookings 
                         FROM bookings b 
                         JOIN package_schedules ps ON b.schedule_id = ps.id 
                         WHERE ps.package_id = :package_id AND b.status IN ('pending', 'confirmed')";
            $checkStmt = $this->db->prepare($checkSql);
            $checkStmt->execute([':package_id' => $packageId]);
            $hasBookings = (int)$checkStmt->fetch(PDO::FETCH_ASSOC)['active_bookings'] > 0;

            if ($hasBookings) {
                // Perform a soft delete by marking as archived to protect booking integrity
                $archiveStmt = $this->db->prepare("UPDATE packages SET status = 'archived' WHERE id = :id");
                $archiveStmt->execute([':id' => $packageId]);
                Response::json(200, "Package has active bookings and was archived instead of deleted.");
                return;
            }

            // Perform hard delete if safe
            $deleteStmt = $this->db->prepare("DELETE FROM packages WHERE id = :id");
            $deleteStmt->execute([':id' => $packageId]);

            if ($deleteStmt->rowCount() === 0) {
                Response::json(404, "Package not found.");
                return;
            }

            Response::json(200, "Package deleted successfully.");
        } catch (Exception $e) {
            error_log("Package Delete Error: " . $e->getMessage());
            Response::json(500, "Internal Server Error: " . $e->getMessage());
        }
    }
    /**
     * POST /api/admin/packages/{id}/publish
     * Step 4b: Mark draft package as active and ready for booking
     * Protected: Admin/Agent only
     */
    public function publishPackage($packageId)
    {
        $currentUser = AuthMiddleware::authenticate(['admin', 'agent']);
        $packageId = (int)$packageId;

        try {
            // Verify package exists
            $pkgStmt = $this->db->prepare("SELECT id, status FROM packages WHERE id = :id LIMIT 1");
            $pkgStmt->execute([':id' => $packageId]);
            $package = $pkgStmt->fetch(PDO::FETCH_ASSOC);

            if (!$package) {
                Response::json(404, "Package not found.");
                return;
            }

            // Verify package has at least one schedule slot before activating
            $schedStmt = $this->db->prepare("SELECT COUNT(*) as total FROM package_schedules WHERE package_id = :package_id");
            $schedStmt->execute([':package_id' => $packageId]);
            $schedCount = (int)$schedStmt->fetch(PDO::FETCH_ASSOC)['total'];

            if ($schedCount === 0) {
                Response::json(422, "Cannot publish: Package must have at least one departure schedule added.");
                return;
            }

            // Update package status to active
            $updateStmt = $this->db->prepare("UPDATE packages SET status = 'active', updated_at = NOW() WHERE id = :id");
            $updateStmt->execute([':id' => $packageId]);

            Response::json(200, "Package published successfully and is now active on catalog.", [
                'id'     => $packageId,
                'status' => 'active'
            ]);
        } catch (Exception $e) {
            error_log("Publish Package Exception: " . $e->getMessage());
            Response::json(500, "Internal Server Error: " . $e->getMessage());
        }
    }
}
