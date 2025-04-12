<?php
session_start();
header('Content-Type: application/json');

require_once '../api-general/config.php';

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
        $stmt_log->bindValue(':actor_id', $actor_id, $actor_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt_log->bindParam(':action_type', $action_type, PDO::PARAM_STR);
        $stmt_log->bindParam(':log_type', $log_type, PDO::PARAM_STR);
        $stmt_log->execute();

        $log_id = $conn->lastInsertId();
        if ($log_type === 'ACCOUNT' && $target_id !== null && $actor_id !== null) {
             $sql_detail = "INSERT INTO log_account (log_id, account_id, admin_id)
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

// Security Check
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Admin' || !isset($_SESSION['account_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access.']);
    exit;
}

$admin_id = $_SESSION['account_id']; // Get ID *before* potential deletion
$response = ['error' => null, 'success' => null];

// Check if it's a POST request (recommended for delete actions)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Invalid request method. Use POST.']);
    exit;
}

// --- Database Deletion ---
try {
    
    // Delete from ACCOUNT (relies on ON DELETE CASCADE for ADMIN table)
    $sql = "DELETE FROM account WHERE account_id = :admin_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':admin_id', $admin_id, PDO::PARAM_INT);

    if ($stmt->execute()) {
        if ($stmt->rowCount() > 0) {
            // Log the successful deletion
            log_action($admin_id, 'DELETE_SELF_ACCOUNT', 'ACCOUNT', $admin_id);

            // IMPORTANT: Destroy the session completely after successful deletion
            session_unset();    // Unset $_SESSION variable for the run-time
            session_destroy();  // Destroy session data in storage
            setcookie(session_name(), '', time() - 3600, '/'); // Clear session cookie

            $response['success'] = 'Account deleted successfully. You have been logged out.';
        } else {
             // This case means the account didn't exist, which is strange if session was valid
            http_response_code(404);
            $response['error'] = 'Account not found for deletion.';
        }
    } else {
        http_response_code(500);
        $response['error'] = 'Failed to delete account from the database.';
    }

} catch (PDOException $e) {
    error_log("Delete Self Account DB Error: " . $e->getMessage());
    http_response_code(500);
    // Check for foreign key constraints if ON DELETE RESTRICT was used elsewhere unexpectedly
    if (strpos($e->getMessage(), 'foreign key constraint fails') !== false) {
         $response['error'] = 'Cannot delete account due to related records. Please contact support.';
    } else {
         $response['error'] = 'Database error occurred during account deletion.';
    }
} catch (Exception $e) {
    error_log("General Delete Self Account Error: " . $e->getMessage());
    http_response_code(500);
    $response['error'] = 'An unexpected error occurred.';
}

echo json_encode($response);
$conn = null;
?>