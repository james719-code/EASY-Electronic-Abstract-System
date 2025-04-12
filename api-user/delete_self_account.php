<?php
// api-user/delete_self_account.php
session_start();
header('Content-Type: application/json');

// Include DB connection
require_once '../api-general/config.php'; // Adjust path as needed

// --- Logging Function Definition (Defined *locally* within this file) ---
function log_action($actor_id, $action_type, $log_type, $target_id = null) {
    global $conn; // Crucial: Make the database connection available

    if (!$conn) {
        error_log("log_action failed: Database connection is not available in " . __FILE__);
        return false;
    }

    // Special handling: Log before commit in delete operation, so needs careful error handling
    try {
        // Basic log entry
        $sql_log = "INSERT INTO log (actor_account_id, action_type, log_type, time)
                    VALUES (:actor_id, :action_type, :log_type, NOW())";
        $stmt_log = $conn->prepare($sql_log);
        $stmt_log->bindValue(':actor_id', $actor_id, $actor_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt_log->bindParam(':action_type', $action_type, PDO::PARAM_STR);
        $stmt_log->bindParam(':log_type', $log_type, PDO::PARAM_STR);
        $stmt_log->execute();

        return true; // Indicate success

    } catch (PDOException $e) {
        // Log the logging error, but DO NOT necessarily stop the delete operation.
        error_log("!!! Failed to execute log_action during DELETE operation in " . __FILE__ . ": " . $e->getMessage() . " | Params: actor=$actor_id, action=$action_type, type=$log_type, target=$target_id");
        return false; // Indicate logging failure (but the main process might continue)
    }
}
// --- End of Logging Function Definition ---


// Security Check: Ensure user is logged in and is a 'User'
if (!isset($_SESSION['account_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'User') {
    http_response_code(401); // Unauthorized
    echo json_encode(['error' => 'Unauthorized access.']);
    exit;
}

$sessionAccountId = $_SESSION['account_id'];
$response = ['error' => null, 'success' => null];

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['error' => 'Invalid request method.']);
    exit;
}

// --- Input Retrieval and Validation ---
$postedAccountId = isset($_POST['account_id']) ? (int)$_POST['account_id'] : null;

// Verify the posted account_id matches the session account_id
if ($postedAccountId !== $sessionAccountId) {
    http_response_code(403); // Forbidden
    $response['error'] = 'Permission denied: Account ID mismatch.';
    echo json_encode($response);
    exit;
}

// --- Database Deletion ---
try {
    // Make sure $conn is available
    if (!$conn) {
         throw new Exception("Database connection not established.");
    }

    $conn->beginTransaction();

    // Log the action *before* attempting delete
    log_action($sessionAccountId, 'DELETE_SELF_ACCOUNT', 'ACCOUNT', $sessionAccountId);

    // Delete the account
    $sqlDelete = "DELETE FROM account WHERE account_id = :account_id";
    $stmtDelete = $conn->prepare($sqlDelete);
    $stmtDelete->bindParam(':account_id', $sessionAccountId, PDO::PARAM_INT);
    $deleted = $stmtDelete->execute();
    $rowCount = $stmtDelete->rowCount();

    if ($deleted && $rowCount > 0) {
        // 3. Commit Transaction
        $conn->commit();

        // 4. Destroy the session *after* successful commit
        $_SESSION = array(); // Clear session variables
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();

        http_response_code(200); // OK
        $response['success'] = 'Your account has been successfully deleted.';

    } else if ($deleted && $rowCount === 0) {
         // Attempted delete, but row not found (maybe deleted in another request?)
         $conn->rollBack(); // Roll back the transaction (even though only logging happened)
         http_response_code(404); // Not Found
         $response['error'] = 'Account could not be deleted (not found or already deleted).';
    }
    else {
        // The execute() call itself failed
        $conn->rollBack();
        throw new Exception("Failed to execute account deletion query.");
    }

} catch (PDOException $e) {
    if ($conn && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Delete Account PDOException in " . __FILE__ . ": " . $e->getMessage());
    http_response_code(500);
     // Check for FK constraint issues if CASCADE didn't work as expected
     if ($e->getCode() == '23000') {
         $response['error'] = 'Could not delete account due to related data. Please contact an administrator.';
     } else {
        $response['error'] = 'Database error during account deletion.';
     }
} catch (Exception $e) {
     if ($conn && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("General Delete Account Error in " . __FILE__ . ": " . $e->getMessage());
    http_response_code(500);
    $response['error'] = 'An unexpected error occurred: ' . $e->getMessage();
}

echo json_encode($response);
$conn = null; // Close connection
?>