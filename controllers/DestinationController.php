<?php
// controllers/DestinationController.php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../middleware/auth.php';

class DestinationController
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    /**
     * GET /api/destinations
     */
    public function index()
    {
        $search = isset($_GET['search']) ? trim($_GET['search']) : null;
        $page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $limit  = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 20;
        $offset = ($page - 1) * $limit;

        $whereClauses = [];
        $params = [];

        if (!empty($search)) {
            $whereClauses[] = "(d.city LIKE :search OR d.country LIKE :search OR d.region_tag LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }

        $whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

        try {
            $countSql = "SELECT COUNT(*) as total FROM destinations d {$whereSql}";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($params);
            $totalItems = (int)$countStmt->fetch(PDO::FETCH_ASSOC)['total'];

            $sql = "SELECT 
                        d.id, 
                        d.city AS name, 
                        d.country, 
                        d.region_tag AS region,
                        d.thumbnail_url AS image_url,
                        d.description, 
                        d.rating,
                        d.created_at,
                        (
                            SELECT COUNT(*) 
                            FROM packages p 
                            WHERE p.destination_id = d.id AND p.status = 'active'
                        ) as active_packages_count
                    FROM destinations d
                    {$whereSql}
                    ORDER BY d.city ASC
                    LIMIT :limit OFFSET :offset";

            $stmt = $this->db->prepare($sql);

            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

            $stmt->execute();
            $destinations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $formattedDestinations = array_map(function ($d) {
                return [
                    'id'                    => (int)$d['id'],
                    'name'                  => $d['name'],
                    'country'               => $d['country'],
                    'region'                => $d['region'],
                    'image_url'             => $d['image_url'],
                    'description'           => $d['description'],
                    'rating'                => (float)$d['rating'],
                    'package_count'         => (int)$d['active_packages_count'], // Added alias
                    'active_packages_count' => (int)$d['active_packages_count'],
                    'created_at'            => $d['created_at']
                ];
            }, $destinations);

            $totalPages = ceil($totalItems / $limit);

            Response::json(200, "Destinations retrieved successfully.", $formattedDestinations, null, [
                'pagination' => [
                    'total_items'  => $totalItems,
                    'total_pages'  => $totalPages,
                    'current_page' => $page,
                    'limit'        => $limit
                ]
            ]);
        } catch (Exception $e) {
            error_log("Destination Index Exception: " . $e->getMessage());
            Response::json(500, "Internal Server Error: " . $e->getMessage());
        }
    }

    /**
     * GET /api/destinations/public
     */
    public function publicIndex()
    {
        try {
            $region = $_GET['region'] ?? null;

            $sql = "SELECT 
                        d.id, 
                        d.city AS name, 
                        d.country, 
                        d.region_tag AS region, 
                        d.description,
                        d.thumbnail_url AS image_url,
                        d.rating,
                        (SELECT COUNT(*) FROM packages p WHERE p.destination_id = d.id) AS package_count
                    FROM destinations d";

            $params = [];
            if (!empty($region)) {
                $sql .= " WHERE d.region_tag = :region";
                $params[':region'] = $region;
            }

            $sql .= " ORDER BY d.city ASC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $destinations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $formatted = array_map(function ($d) {
                return [
                    'id'            => (int)$d['id'],
                    'name'          => $d['name'],
                    'country'       => $d['country'],
                    'region'        => $d['region'],
                    'description'   => $d['description'],
                    'image_url'     => $d['image_url'] ?? 'assets/images/placeholder-destination.jpg',
                    'rating'        => (float)($d['rating'] ?? 4.8),
                    'package_count' => (int)$d['package_count']
                ];
            }, $destinations);

            Response::json(200, "Destinations fetched successfully", $formatted);
        } catch (Exception $e) {
            error_log("Public Destination Exception: " . $e->getMessage());
            Response::json(500, "Database Error: " . $e->getMessage());
        }
    }

    /**
     * POST /api/admin/destinations
     */
    public function create()
    {
        try {
            // Validate authentication token
            $currentUser = AuthMiddleware::authenticate(['admin', 'agent']);

            $rawInput = file_get_contents('php://input');
            $input = json_decode($rawInput, true);

            if (!$input) {
                Response::json(400, "Invalid JSON payload provided.");
                return;
            }

            $city         = trim($input['name'] ?? $input['city'] ?? '');
            $country      = trim($input['country'] ?? '');
            $regionTag    = trim($input['region'] ?? $input['region_tag'] ?? 'General');
            $thumbnailUrl = trim($input['image_url'] ?? $input['thumbnail_url'] ?? '');
            $description  = trim($input['description'] ?? '');

            $errors = [];
            if (empty($city))         $errors['name']          = "City/Destination name is required.";
            if (empty($country))      $errors['country']       = "Country is required.";
            if (empty($thumbnailUrl)) $errors['thumbnail_url'] = "Thumbnail URL is required.";

            if (!empty($errors)) {
                Response::json(422, "Validation failed.", null, $errors);
                return;
            }

            // Check for duplicate destination (using schema columns: city & country)
            $checkStmt = $this->db->prepare("SELECT id FROM destinations WHERE LOWER(city) = LOWER(:city) AND LOWER(country) = LOWER(:country) LIMIT 1");
            $checkStmt->execute([
                ':city'    => $city,
                ':country' => $country
            ]);

            if ($checkStmt->fetch()) {
                Response::json(409, "Conflict: Destination '{$city}' in '{$country}' already exists.");
                return;
            }

            $sql = "INSERT INTO destinations (city, country, region_tag, thumbnail_url, description, rating) 
                    VALUES (:city, :country, :region_tag, :thumbnail_url, :description, 4.80)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':city'          => $city,
                ':country'       => $country,
                ':region_tag'    => $regionTag,
                ':thumbnail_url' => $thumbnailUrl,
                ':description'   => $description
            ]);

            $destinationId = (int)$this->db->lastInsertId();

            Response::json(201, "Destination created successfully.", [
                'id'            => $destinationId,
                'name'          => $city,
                'country'       => $country,
                'region'        => $regionTag,
                'image_url'     => $thumbnailUrl,
                'description'   => $description,
                'rating'        => 4.80
            ]);
        } catch (Exception $e) {
            error_log("Destination Creation Exception: " . $e->getMessage());
            Response::json(500, "Internal Server Error: " . $e->getMessage());
        }
    }
}
