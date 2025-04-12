<?php
// api-general/change_password.php
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
        error_log("Failed to execute log_action in " . __FILE__ . ": " . $e->getMessage() . " | Params: actor=$actor_id, action=$action_type, type=$log_type, target=$target_id");
        return false; // Indicate failure
    }
}
// --- End of Logging Function Definition ---

// Security Check: Check if ANY user (User or Admin) is logged in
if (!isset($_SESSION['account_id'])) {
    http_response_code(401); // Unauthorized
    echo json_encode(['error' => 'Unauthorized: Not logged in.']);
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
$currentPassword = isset($_POST['current_password']) ? $_POST['current_password'] : '';
$newPassword = isset($_POST['new_password']) ? $_POST['new_password'] : '';
$confirmNewPassword = isset($_POST['confirm_new_password']) ? $_POST['confirm_new_password'] : '';

// IMPORTANT: Verify the posted account_id matches the session account_id
if ($postedAccountId !== $sessionAccountId) {
    http_response_code(403); // Forbidden
    $response['error'] = 'Permission denied: Account ID mismatch.';
    echo json_encode($response);
    exit;
}

// Basic Validation
if (empty($currentPassword) || empty($newPassword) || empty($confirmNewPassword)) {
    http_response_code(400); // Bad Request
    $response['error'] = 'All password fields are required.';
    echo json_encode($response);
    exit;
}
if ($newPassword !== $confirmNewPassword) {
    http_response_code(400);
    $response['error'] = 'New password and confirmation do not match.';
    echo json_encode($response);
    exit;
}
if (strlen($newPassword) < 6) { // Example minimum length
    http_response_code(400);
    $response['error'] = 'New password must be at least 6 characters long.';
    echo json_encode($response);
    exit;
}

// --- Database Update ---
try {
    // Make sure $conn is available
    if (!$conn) {
         throw new Exception("Database connection not established.");
    }

    // No transaction needed for single select + update, but can be added for consistency if preferred.

    // Get current hashed password
    $sqlGetPass = "SELECT password FROM account WHERE account_id = :account_id";
    $stmtGetPass = $conn->prepare($sqlGetPass);
    $stmtGetPass->bindParam(':account_id', $sessionAccountId, PDO::PARAM_INT);
    $stmtGetPass->execute();
    $userData = $stmtGetPass->fetch(PDO::FETCH_ASSOC);
    $stmtGetPass->closeCursor();

    if (!$userData) {
        http_response_code(404); // Not Found (Shouldn't happen if session is valid)
        $response['error'] = 'User account not found.';
        echo json_encode($response);
        exit;
    }
    $currentHashedPassword = $userData['password'];

    // Verify current password
    if (!password_verify($currentPassword, $currentHashedPassword)) {
        http_response_code(400); // Bad Request (Incorrect current password)
        $response['error'] = 'Incorrect current password.';
        echo json_encode($response);
        exit;
    }

    // Hash the new password
    $newHashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
    if ($newHashedPassword === false) {
         throw new Exception("Password hashing failed.");
    }

    // Update the password
    $sqlUpdatePass = "UPDATE account SET password = :new_password WHERE account_id = :account_id";
    $stmtUpdatePass = $conn->prepare($sqlUpdatePass);
    $stmtUpdatePass->bindParam(':new_password', $newHashedPassword, PDO::PARAM_STR);
    $stmtUpdatePass->bindParam(':account_id', $sessionAccountId, PDO::PARAM_INT);

    if ($stmtUpdatePass->execute()) {
        // Log the action *after* successful update
        log_action($sessionAccountId, 'CHANGE_PASSWORD', 'ACCOUNT', $sessionAccountId);

        http_response_code(200); // OK
        $response['success'] = 'Password changed successfully.';
    } else {
         throw new Exception("Failed to execute password update query.");
    }

} catch (PDOException $e) {
    error_log("Change Password PDOException in " . __FILE__ . ": " . $e->getMessage());
    http_response_code(500);
    $response['error'] = 'Database error during password change.';
} catch (Exception $e) {
    error_log("General Change Password Error in " . __FILE__ . ": " . $e->getMessage());
    http_response_code(500);
    $response['error'] = 'An unexpected error occurred: ' . $e->getMessage();
}

echo json_encode($response);
$conn = null; // Close connection
?>