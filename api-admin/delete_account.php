<?php
session_start();
// Enable error reporting for debugging (remove/adjust in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Set content type to JSON
header('Content-Type: application/json');

// Assuming config.php establishes the PDO connection $conn
include '../api-general/config.php'; // Adjust path as necessary

$response = [];

// --- Authentication/Authorization Check ---
// Use consistent session variables
if (!isset($_SESSION['account_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Admin') {
    http_response_code(401); // Unauthorized
    $response['error'] = "Unauthorized access. Admin privileges required.";
    echo json_encode($response);
    exit;
}
$loggedInAdminId = $_SESSION['account_id']; // The admin performing the action

// --- Check Method ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    $response['error'] = 'Invalid request method.';
    echo json_encode($response);
    exit;
}

// --- Input Validation ---
// Check if the account_id to delete is provided and is a valid integer
$accountIdToDelete = filter_input(INPUT_POST, 'account_id', FILTER_VALIDATE_INT);

if ($accountIdToDelete === false || $accountIdToDelete <= 0) {
    http_response_code(400); // Bad Request
    $response['error'] = 'Valid Account ID to delete is required.';
    echo json_encode($response);
    exit;
}
// Cast to int for strict comparison
$accountIdToDelete = (int)$accountIdToDelete;

// --- Crucial Safety Check: Prevent self-deletion ---
if ($accountIdToDelete === (int)$loggedInAdminId) {
    http_response_code(403); // Forbidden
    $response['error'] = 'Operation forbidden: You cannot delete your own account.';
    echo json_encode($response);
    exit;
}
// --- End Safety Check ---

try {
    // Start transaction
    $conn->beginTransaction();

    // Step 1: Verify the account to be deleted exists
    $stmt_check = $conn->prepare("SELECT 1 FROM ACCOUNT WHERE account_id = :account_id");
    $stmt_check->bindParam(':account_id', $accountIdToDelete, PDO::PARAM_INT);
    $stmt_check->execute();

    if ($stmt_check->fetchColumn() === false) {
        // Account doesn't exist, no need to proceed or log attempt on non-existent ID
        $conn->rollBack();
        http_response_code(404); // Not Found
        $response['error'] = 'Account to delete not found.';
        echo json_encode($response);
        exit;
    }
    // $stmt_check->closeCursor(); // Not strictly needed after fetchColumn

    // Step 2: Log the delete action *before* performing it
    $action_type = 'DELETE_ACCOUNT';
    $log_type = 'ACCOUNT';

    // Insert into base LOG table
    $sql_log = "INSERT INTO LOG (actor_account_id, action_type, log_type) VALUES (:actor_id, :action_type, :log_type)";
    $stmt_log = $conn->prepare($sql_log);
    $stmt_log->bindParam(':actor_id', $loggedInAdminId, PDO::PARAM_INT);
    $stmt_log->bindParam(':action_type', $action_type);
    $stmt_log->bindParam(':log_type', $log_type);
    if (!$stmt_log->execute()) {
        throw new PDOException("Failed to insert into LOG table during account deletion.");
    }
    $log_id = $conn->lastInsertId(); // Get the ID of the log entry

    // Insert into LOG_ACCOUNT detail table
    $sql_log_account = "INSERT INTO LOG_ACCOUNT (log_id, target_account_id, admin_account_id) VALUES (:log_id, :target_id, :admin_id)";
    $stmt_log_account = $conn->prepare($sql_log_account);
    $stmt_log_account->bindParam(':log_id', $log_id, PDO::PARAM_INT);
    $stmt_log_account->bindParam(':target_id', $accountIdToDelete, PDO::PARAM_INT); // The account to be deleted
    $stmt_log_account->bindParam(':admin_id', $loggedInAdminId, PDO::PARAM_INT);  // The admin performing the action
    if (!$stmt_log_account->execute()) {
         throw new PDOException("Failed to insert into LOG_ACCOUNT table during account deletion.");
    }

    // Step 3: Delete the account from the ACCOUNT table.
    // Assumes ON DELETE CASCADE constraints are set correctly on USER and ADMIN tables.
    $stmt_delete = $conn->prepare("DELETE FROM ACCOUNT WHERE account_id = :account_id");
    $stmt_delete->bindParam(':account_id', $accountIdToDelete, PDO::PARAM_INT);
    $deleteSuccess = $stmt_delete->execute();
    $rowCount = $stmt_delete->rowCount(); // Check how many rows were actually deleted

    // $stmt_delete->closeCursor(); // Optional

    if ($deleteSuccess && $rowCount > 0) {
        // Commit transaction ONLY if delete was successful and affected 1 row
        $conn->commit();
        http_response_code(200); // OK
        $response['success'] = 'Account deleted successfully.';
        echo json_encode($response);
    } else {
        // Rollback if delete failed or affected 0 rows (shouldn't happen after check, but defensive)
        $conn->rollBack();
        // Log this specific scenario as it's unexpected after the initial check
        error_log("Account deletion failed or affected 0 rows for Account ID {$accountIdToDelete} after existence check passed. Deleted by Admin ID {$loggedInAdminId}.");
        http_response_code(500); // Internal Server Error (unexpected state)
        $response['error'] = 'Failed to delete the account after logging.'; // Slightly different message
        echo json_encode($response);
    }

} catch (PDOException $e) {
    // Rollback transaction on any database error (including logging failures)
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500); // Internal Server Error
    error_log("Database Error deleting account ID {$accountIdToDelete} by admin ID {$loggedInAdminId}: " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
    $response['error'] = 'An internal database error occurred while deleting the account.';
    echo json_encode($response);

} catch (Exception $e) { // Catch any other unexpected errors
     if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    error_log("General Error deleting account ID {$accountIdToDelete} by admin ID {$loggedInAdminId}: " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
    $response['error'] = 'An unexpected error occurred while deleting the account.';
    echo json_encode($response);
} finally {
    // Close connection
    $conn = null;
}
?>