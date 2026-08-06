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
     * Public endpoint to list destinations with optional search and total active package counts
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
            $whereClauses[] = "(d.name LIKE :search OR d.country LIKE :search)";
            $params[':search'] = '%' . $search . '%';
        }

        $whereSql = !empty($whereClauses) ? 'WHERE ' . implode(' AND ', $whereClauses) : '';

        try {
            // Count total matching destinations
            $countSql = "SELECT COUNT(*) as total FROM destinations d {$whereSql}";
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($params);
            $totalItems = (int)$countStmt->fetch()['total'];

            // Query destinations with active packages count
            $sql = "SELECT 
                        d.id, 
                        d.name, 
                        d.country, 
                        d.description, 
                        d.created_at,
                        (
                            SELECT COUNT(*) 
                            FROM packages p 
                            WHERE p.destination_id = d.id AND p.status = 'active'
                        ) as active_packages_count
                    FROM destinations d
                    {$whereSql}
                    ORDER BY d.name ASC
                    LIMIT :limit OFFSET :offset";

            $stmt = $this->db->prepare($sql);

            foreach ($params as $key => $val) {
                $stmt->bindValue($key, $val);
            }
            $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

            $stmt->execute();
            $destinations = $stmt->fetchAll();

            $formattedDestinations = array_map(function ($d) {
                return [
                    'id'                    => (int)$d['id'],
                    'name'                  => $d['name'],
                    'country'               => $d['country'],
                    'description'           => $d['description'],
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
            Response::json(500, "Internal Server Error: Unable to fetch destinations.");
        }
    }

    /**
     * POST /api/admin/destinations
     * Admin/Agent endpoint to create a new destination
     */
    public function create()
    {
        // Enforce Role-Based Access Control
        $currentUser = AuthMiddleware::authenticate(['admin', 'agent']);

        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        if (!$input) {
            Response::json(400, "Invalid JSON payload provided.");
        }

        $name        = trim($input['name'] ?? '');
        $country     = trim($input['country'] ?? '');
        $description = trim($input['description'] ?? '');

        $errors = [];
        if (empty($name))        $errors['name']        = "Destination name is required.";
        if (empty($country))     $errors['country']     = "Country is required.";
        if (empty($description)) $errors['description'] = "Description is required.";

        if (!empty($errors)) {
            Response::json(422, "Validation failed.", null, $errors);
        }

        try {
            // Check for existing duplicate destination in the same country
            $checkStmt = $this->db->prepare("SELECT id FROM destinations WHERE LOWER(name) = LOWER(:name) AND LOWER(country) = LOWER(:country) LIMIT 1");
            $checkStmt->execute([
                ':name'    => $name,
                ':country' => $country
            ]);

            if ($checkStmt->fetch()) {
                Response::json(409, "Conflict: Destination '{$name}' in '{$country}' already exists.");
            }

            $sql = "INSERT INTO destinations (name, country, description) VALUES (:name, :country, :description)";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':name'        => $name,
                ':country'     => $country,
                ':description' => $description
            ]);

            $destinationId = (int)$this->db->lastInsertId();

            Response::json(201, "Destination created successfully.", [
                'id'          => $destinationId,
                'name'        => $name,
                'country'     => $country,
                'description' => $description
            ]);
        } catch (Exception $e) {
            error_log("Destination Creation Exception: " . $e->getMessage());
            Response::json(500, "Internal Server Error: Could not create destination.");
        }
    }
}
