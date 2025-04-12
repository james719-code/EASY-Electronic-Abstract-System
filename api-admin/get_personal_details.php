<?php
// api-admin/get_personal_details.php
session_start();
header('Content-Type: application/json');

// Include DB connection
require_once '../api-general/config.php'; // Adjust path as needed

// Security Check: Ensure user is logged in and is an 'Admin'
if (!isset($_SESSION['account_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Admin') {
    http_response_code(401); // Unauthorized
    echo json_encode(['error' => 'Unauthorized access. Admin login required.']);
    exit;
}

$adminAccountId = $_SESSION['account_id'];
$response = ['error' => null, 'data' => null];

// Check if it's a GET request
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['error' => 'Invalid request method. Use GET.']);
    exit;
}

// --- Database Fetch ---
try {
    // Make sure $conn is available
    if (!$conn) {
         throw new Exception("Database connection not established.");
    }

    // No transaction needed for SELECT
    // Join ACCOUNT and ADMIN tables to get all relevant details
    $sql = "SELECT
                a.account_id, a.username, a.name, a.sex,
                ad.work_id, ad.position
            FROM account a
            LEFT JOIN admin ad ON a.account_id = ad.admin_id 
            WHERE a.account_id = :account_id AND a.account_type = 'Admin'"; 

    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':account_id', $adminAccountId, PDO::PARAM_INT);
    $stmt->execute();

    $admin_details = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($admin_details) {
        http_response_code(200); // OK
        $response['data'] = $admin_details;
    } else {
        // This case indicates an inconsistency (Admin session exists, but DB record doesn't match)
        error_log("Failed to fetch details for logged-in admin ID: " . $adminAccountId . " in " . __FILE__);
        http_response_code(404); // Not Found
        $response['error'] = 'Could not retrieve admin details. Account inconsistency detected.';
    }

} catch (PDOException $e) {
    error_log("Get Admin Details PDOException in " . __FILE__ . ": " . $e->getMessage());
    http_response_code(500); // Internal Server Error
    $response['error'] = 'Database error occurred while fetching admin details.';
} catch (Exception $e) {
    error_log("General Get Admin Details Error in " . __FILE__ . ": " . $e->getMessage());
    http_response_code(500);
    $response['error'] = 'An unexpected error occurred: ' . $e->getMessage();
}

echo json_encode($response);
$conn = null; // Close connection
?>