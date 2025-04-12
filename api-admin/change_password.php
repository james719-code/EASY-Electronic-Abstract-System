<?php
session_start();
header('Content-Type: application/json');

require_once '../api-general/config.php';

// --- Logging Function Definition ---
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

        //Insert into detail log table
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

// Security Check
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Admin' || !isset($_SESSION['account_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized access.']);
    exit;
}

$admin_id = $_SESSION['account_id'];
$response = ['error' => null, 'success' => null];

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Invalid request method.']);
    exit;
}

// --- Input Validation ---
$current_password = $_POST['current_password'] ?? '';
$new_password = $_POST['new_password'] ?? '';
$confirm_password = $_POST['confirm_new_password'] ?? '';

if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
    http_response_code(400);
    $response['error'] = 'All password fields are required.';
    echo json_encode($response);
    exit;
}

if ($new_password !== $confirm_password) {
    http_response_code(400);
    $response['error'] = 'New password and confirmation password do not match.';
    echo json_encode($response);
    exit;
}

// Add password strength validation if desired (e.g., minimum length)
if (strlen($new_password) < 8) { // Example: minimum 8 characters
     http_response_code(400);
     $response['error'] = 'New password must be at least 8 characters long.';
     echo json_encode($response);
     exit;
}


// --- Database Interaction ---
try {
    //Fetch current hashed password
    $sql_fetch = "SELECT password FROM account WHERE account_id = :admin_id";
    $stmt_fetch = $conn->prepare($sql_fetch);
    $stmt_fetch->bindParam(':admin_id', $admin_id, PDO::PARAM_INT);
    $stmt_fetch->execute();
    $account = $stmt_fetch->fetch(PDO::FETCH_ASSOC);

    if (!$account) {
        http_response_code(404);
        $response['error'] = 'Account not found.';
        echo json_encode($response);
        exit;
    }

    //Verify current password
    if (!password_verify($current_password, $account['password'])) {
        http_response_code(400);
        $response['error'] = 'Incorrect current password.';
        echo json_encode($response);
        exit;
    }

    //Hash the new password
    $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);
    if ($hashed_new_password === false) {
        throw new Exception("Password hashing failed."); // Handle hashing error
    }

    //Update the password in the database
    $sql_update = "UPDATE account SET password = :new_password WHERE account_id = :admin_id";
    $stmt_update = $conn->prepare($sql_update);
    $stmt_update->bindParam(':new_password', $hashed_new_password, PDO::PARAM_STR);
    $stmt_update->bindParam(':admin_id', $admin_id, PDO::PARAM_INT);

    if ($stmt_update->execute()) {
        // Log the action
        log_action($admin_id, 'CHANGE_SELF_PASSWORD', 'ACCOUNT', $admin_id);
        $response['success'] = 'Password changed successfully.';
    } else {
        http_response_code(500);
        $response['error'] = 'Failed to update password in the database.';
    }

} catch (PDOException $e) {
    error_log("Change Password DB Error: " . $e->getMessage());
    http_response_code(500);
    $response['error'] = 'Database error occurred while changing password.';
} catch (Exception $e) {
    error_log("General Change Password Error: " . $e->getMessage());
    http_response_code(500);
    $response['error'] = 'An unexpected error occurred: ' . $e->getMessage();
}

echo json_encode($response);
$conn = null;
?>