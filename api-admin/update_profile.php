<?php
session_start();
header('Content-Type: application/json');

// Include ONLY the part of config.php needed for the DB connection
// This assumes config.php sets up $conn but doesn't define log_action
require_once '../api-general/config.php'; // Adjust path as needed

// --- Logging Function Definition (Defined *locally* within this file) ---
function log_action($actor_id, $action_type, $log_type, $target_id = null) {
    global $conn; // Crucial: Make the database connection available inside the function

    if (!$conn) {
        error_log("log_action failed: Database connection is not available in " . __FILE__);
        return false; // Cannot log without DB connection
    }

    try {
        $sql_log = "INSERT INTO log (actor_account_id, action_type, log_type, time)
                    VALUES (:actor_id, :action_type, :log_type, NOW())";
        $stmt_log = $conn->prepare($sql_log);
        // Use PDO::PARAM_NULL if actor_id can be null, else PDO::PARAM_INT
        $stmt_log->bindValue(':actor_id', $actor_id, $actor_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt_log->bindParam(':action_type', $action_type, PDO::PARAM_STR);
        $stmt_log->bindParam(':log_type', $log_type, PDO::PARAM_STR);
        $stmt_log->execute();

        $log_id = $conn->lastInsertId();
        if ($log_type === 'ACCOUNT' && $target_id !== null && $actor_id !== null) {
             $sql_detail = "INSERT INTO log_account (log_account_id, account_id, admin_id)
                           VALUES (:log_id, :target_id, :actor_id)"; // Assuming actor is always admin for ACCOUNT logs
             $stmt_detail = $conn->prepare($sql_detail);
             $stmt_detail->bindParam(':log_id', $log_id, PDO::PARAM_INT);
             $stmt_detail->bindParam(':target_id', $target_id, PDO::PARAM_INT);
             $stmt_detail->bindParam(':actor_id', $actor_id, PDO::PARAM_INT);
             $stmt_detail->execute();
        }

        return true;

    } catch (PDOException $e) {
        // Log the logging error itself!
        error_log("Failed to execute log_action in " . __FILE__ . ": " . $e->getMessage() . " | Params: actor=$actor_id, action=$action_type, type=$log_type, target=$target_id");
        return false; // Indicate failure
    }
}
// --- End of Logging Function Definition ---


// Security Check (Keep this as before)
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Admin' || !isset($_SESSION['account_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access.']);
    exit;
}

$admin_id = $_SESSION['account_id'];
$response = ['error' => null, 'success' => null];

// Check if it's a POST request (Keep this as before)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['error' => 'Invalid request method.']);
    exit;
}

// --- Input Validation (Keep this as before) ---
$name = trim($_POST['name'] ?? '');
$sex = trim($_POST['sex'] ?? '');
$work_id = trim($_POST['work_id'] ?? null);
$position = trim($_POST['position'] ?? null);

if (empty($name)) {
    http_response_code(400);
    $response['error'] = 'Name cannot be empty.';
    echo json_encode($response);
    exit;
}
if (!in_array($sex, ['M', 'F', ''])) {
    http_response_code(400);
    $response['error'] = 'Invalid value for Sex.';
    echo json_encode($response);
    exit;
}

// --- Database Update ---
try {
    // Make sure $conn is available here (should be from require_once)
    if (!$conn) {
         throw new Exception("Database connection not established.");
    }

    $conn->beginTransaction();

    // 1. Update ACCOUNT table (Keep as before)
    $sql_account = "UPDATE account SET name = :name, sex = :sex WHERE account_id = :admin_id";
    $stmt_account = $conn->prepare($sql_account);
    $stmt_account->bindParam(':name', $name, PDO::PARAM_STR);
    $stmt_account->bindParam(':sex', $sex, PDO::PARAM_STR);
    $stmt_account->bindParam(':admin_id', $admin_id, PDO::PARAM_INT);
    $stmt_account->execute();

    // 2. Update ADMIN table (Keep as before)
    $sql_admin = "UPDATE admin SET work_id = :work_id, position = :position WHERE admin_id = :admin_id";
    $stmt_admin = $conn->prepare($sql_admin);
    $stmt_admin->bindParam(':work_id', $work_id, PDO::PARAM_STR);
    $stmt_admin->bindParam(':position', $position, PDO::PARAM_STR);
    $stmt_admin->bindParam(':admin_id', $admin_id, PDO::PARAM_INT);
    $stmt_admin->execute();

    // Commit transaction (Keep as before)
    $conn->commit();
    
    log_action($admin_id, 'UPDATE_SELF_PROFILE', 'ACCOUNT', $admin_id);

    $response['success'] = 'Profile updated successfully.';

} catch (PDOException $e) {
    // Error handling (Keep as before, ensure rollback happens)
    if ($conn && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Update Profile PDOException in " . __FILE__ . ": " . $e->getMessage());
    http_response_code(500);
    $response['error'] = 'Database error occurred during profile update.';
} catch (Exception $e) {
    // Error handling (Keep as before, ensure rollback happens)
    if ($conn && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("General Update Profile Error in " . __FILE__ . ": " . $e->getMessage());
    http_response_code(500);
    $response['error'] = 'An unexpected error occurred: ' . $e->getMessage();
}

echo json_encode($response);
$conn = null; // Close connection
?>