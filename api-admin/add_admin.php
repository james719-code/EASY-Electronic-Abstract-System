<?php
// api-admin/add_admin.php (Assuming this path)

session_start();
header('Content-Type: application/json');
include '../api-general/config.php'; // Adjust path if necessary

$response = [];

// --- Authentication & Authorization ---
// Use the consistent session variables from login.php and other API scripts
if (!isset($_SESSION['account_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Admin') {
    http_response_code(401); // Unauthorized (use 401 or 403 as appropriate)
    $response['error'] = "Access denied. Admin privileges required.";
    echo json_encode($response);
    exit;
}
$loggedInAdminId = $_SESSION['account_id']; // The admin *performing* this action

// --- Check Method ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    $response['error'] = "Invalid request method. Only POST is accepted.";
    echo json_encode($response);
    exit;
}

// --- Input Retrieval and Basic Sanitization ---
// Replace FILTER_SANITIZE_STRING with FILTER_DEFAULT or remove filter if only trimming
$username = trim(filter_input(INPUT_POST, 'username', FILTER_DEFAULT) ?? '');
$name = trim(filter_input(INPUT_POST, 'name', FILTER_DEFAULT) ?? '');
$password = $_POST['password'] ?? ''; // Get password directly for hashing
$confirm_password = $_POST['confirm_password'] ?? '';
$sex = filter_input(INPUT_POST, 'sex', FILTER_DEFAULT); // Use FILTER_DEFAULT
$work_id = trim(filter_input(INPUT_POST, 'work_id', FILTER_DEFAULT) ?? ''); // Use FILTER_DEFAULT
$position = trim(filter_input(INPUT_POST, 'position', FILTER_DEFAULT) ?? ''); // Use FILTER_DEFAULT


// --- Basic Validation ---
if (empty($username) || empty($name) || empty($password) || empty($sex)) {
     http_response_code(400);
     $response['error'] = "Username, Name, Password, and Sex are required.";
     echo json_encode($response);
     exit;
}
if ($password !== $confirm_password) {
    http_response_code(400);
    $response['error'] = "Passwords do not match.";
    echo json_encode($response);
    exit;
}
 if (!in_array($sex, ['M', 'F'])) {
    http_response_code(400);
    $response['error'] = "Invalid value for Sex (M or F allowed).";
    echo json_encode($response);
    exit;
}

// --- Hash Password ---
$hashed_password = password_hash($password, PASSWORD_DEFAULT);
if ($hashed_password === false) {
    http_response_code(500);
    $response['error'] = "Failed to hash password.";
    error_log("Password hashing failed for admin creation attempt, username: ".$username); // Log server-side
    echo json_encode($response);
    exit;
}

// --- Database Operation with Transaction and Logging ---
try {
    $conn->beginTransaction();

    // 1. Insert into ACCOUNT table
    $sql_account = "INSERT INTO ACCOUNT (username, name, password, sex, account_type) VALUES (:username, :name, :password, :sex, 'Admin')";
    $stmt_account = $conn->prepare($sql_account);
    $stmt_account->bindParam(':username', $username);
    $stmt_account->bindParam(':name', $name);
    $stmt_account->bindParam(':password', $hashed_password);
    $stmt_account->bindParam(':sex', $sex);

    if (!$stmt_account->execute()) {
        // Error code 23000 is integrity constraint violation (like unique index)
        if($stmt_account->errorCode() == 23000) {
             throw new PDOException("Username already exists.", 23000);
        } else {
            throw new PDOException("Failed to insert into ACCOUNT table. Error: " . $stmt_account->errorInfo()[2]);
        }
    }
    $new_account_id = $conn->lastInsertId(); // Get the ID of the account just created

    // 2. Insert into ADMIN table (Subtype)
    $sql_admin = "INSERT INTO ADMIN (account_id, work_id, position) VALUES (:account_id, :work_id, :position)";
    $stmt_admin = $conn->prepare($sql_admin);
    $stmt_admin->bindParam(':account_id', $new_account_id, PDO::PARAM_INT);
    // Bind parameters that might be empty/null
    $stmt_admin->bindParam(':work_id', $work_id, !empty($work_id) ? PDO::PARAM_STR : PDO::PARAM_NULL);
    $stmt_admin->bindParam(':position', $position, !empty($position) ? PDO::PARAM_STR : PDO::PARAM_NULL);


    if (!$stmt_admin->execute()) {
         // Error code 23000 is integrity constraint violation (like unique index)
        if($stmt_admin->errorCode() == 23000) {
             throw new PDOException("Work ID already exists.", 23000);
        } else {
            throw new PDOException("Failed to insert into ADMIN table. Error: " . $stmt_admin->errorInfo()[2]);
        }
    }

    // 3. Log the Action (Insert into LOG and LOG_ACCOUNT)
    $action_type = 'CREATE_ADMIN_ACCOUNT'; // Be specific
    $log_type = 'ACCOUNT';                 // Matches the detail table focus

    // Insert into base LOG table
    $sql_log = "INSERT INTO LOG (actor_account_id, action_type, log_type) VALUES (:actor_id, :action_type, :log_type)";
    $stmt_log = $conn->prepare($sql_log);
    $stmt_log->bindParam(':actor_id', $loggedInAdminId, PDO::PARAM_INT); // The admin performing the action
    $stmt_log->bindParam(':action_type', $action_type);
    $stmt_log->bindParam(':log_type', $log_type);
    if (!$stmt_log->execute()) {
        throw new PDOException("Failed to insert into LOG table during admin creation.");
    }
    $log_id = $conn->lastInsertId(); // Get the ID of the log entry

    // Insert into LOG_ACCOUNT detail table
    $sql_log_account = "INSERT INTO LOG_ACCOUNT (log_id, target_account_id, admin_account_id) VALUES (:log_id, :target_id, :admin_id)";
    $stmt_log_account = $conn->prepare($sql_log_account);
    $stmt_log_account->bindParam(':log_id', $log_id, PDO::PARAM_INT);
    $stmt_log_account->bindParam(':target_id', $new_account_id, PDO::PARAM_INT); // The account that was created
    $stmt_log_account->bindParam(':admin_id', $loggedInAdminId, PDO::PARAM_INT);  // The admin who performed the action
    if (!$stmt_log_account->execute()) {
         throw new PDOException("Failed to insert into LOG_ACCOUNT table during admin creation.");
    }

    // --- Commit Transaction ---
    // If all inserts (ACCOUNT, ADMIN, LOG, LOG_ACCOUNT) were successful
    $conn->commit();

    http_response_code(201); // 201 Created is suitable for successful resource creation
    $response['success'] = "Admin account created successfully.";
    echo json_encode($response);

} catch (PDOException $e) {
    // Rollback transaction on any error during the process
    if ($conn->inTransaction()) $conn->rollBack();

    error_log("Add Admin DB Error: " . $e->getMessage()); // Log detailed error

    // Handle specific known errors (like unique constraints) or provide a general DB error
    if ($e->getCode() == 23000) { // Integrity constraint violation
         http_response_code(409); // Conflict status code
         if (stripos($e->getMessage(), 'ACCOUNT.username') !== false || stripos($e->getMessage(), "'username'") !== false) {
             $response['error'] = "Username already exists.";
         } elseif (stripos($e->getMessage(), 'ADMIN.work_id') !== false || stripos($e->getMessage(), "'work_id'") !== false) {
             $response['error'] = "Work ID already exists.";
         } else {
            $response['error'] = "Data conflict: A unique value constraint was violated."; // More specific than just "DB error"
         }
    } else {
        http_response_code(500); // Internal server error for other DB issues
        $response['error'] = "Database error occurred while adding admin account."; // User-friendly message
    }
    echo json_encode($response);

} catch (Exception $e) { // Catch any other non-PDO exceptions
     if ($conn->inTransaction()) $conn->rollBack(); // Ensure rollback on general errors too
     http_response_code(500);
     error_log("Add Admin General Error: " . $e->getMessage());
     $response['error'] = "An unexpected server error occurred.";
     echo json_encode($response);
} finally {
    // Close connection
    $conn = null;
}
?>