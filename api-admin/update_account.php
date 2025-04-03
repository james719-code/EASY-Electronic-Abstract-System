<?php
// api-admin/update_account.php

// --- Session Handling (Crucial for identifying the actor) ---
session_start(); // Start the session to access logged-in admin data

header('Content-Type: application/json');
include '../api-general/config.php'; // Adjust path if necessary

$response = [];

// --- Authentication Check ---
// Ensure an admin is logged in. Adjust 'admin_account_id' and 'user_type' session variable names as needed.
if (!isset($_SESSION['account_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Admin') {
    http_response_code(401); // Unauthorized
    $response['error'] = "Unauthorized: Admin access required.";
    echo json_encode($response);
    exit;
}
$loggedInAdminId = $_SESSION['account_id']; // Get the ID of the admin performing the action

// Check if the request method is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    $response['error'] = "Invalid request method. Only POST is accepted.";
    echo json_encode($response);
    exit;
}

// --- Input Retrieval and Basic Sanitization ---
$account_id_to_edit = filter_input(INPUT_POST, 'editAccountId', FILTER_VALIDATE_INT); // The account being edited
$username = trim(filter_input(INPUT_POST, 'username', FILTER_SANITIZE_STRING) ?? '');
$name = trim(filter_input(INPUT_POST, 'name', FILTER_SANITIZE_STRING) ?? '');
$sex = filter_input(INPUT_POST, 'sex', FILTER_SANITIZE_STRING);

// User specific
$academic_level = trim(filter_input(INPUT_POST, 'academic_level', FILTER_SANITIZE_STRING) ?? '');
$program_id = filter_input(INPUT_POST, 'program_id', FILTER_VALIDATE_INT);

// Admin specific
$work_id = trim(filter_input(INPUT_POST, 'work_id', FILTER_SANITIZE_STRING) ?? '');
$position = trim(filter_input(INPUT_POST, 'position', FILTER_SANITIZE_STRING) ?? '');


// --- Basic Validation ---
if ($account_id_to_edit === false || $account_id_to_edit <= 0) {
    http_response_code(400);
    $response['error'] = "Invalid or missing Account ID to edit.";
    echo json_encode($response);
    exit;
}
if (empty($username)) {
    http_response_code(400);
    $response['error'] = "Username is required.";
    echo json_encode($response);
    exit;
}
if (empty($name)) {
    http_response_code(400);
    $response['error'] = "Name is required.";
    echo json_encode($response);
    exit;
}
if ($sex !== null && $sex !== '' && !in_array($sex, ['M', 'F'])) {
    http_response_code(400);
    $response['error'] = "Invalid value for Sex.";
    echo json_encode($response);
    exit;
}

// --- Database Operations ---
try {
    $conn->beginTransaction();

    // 1. Fetch the current account_type
    $stmt_check = $conn->prepare("SELECT account_type FROM ACCOUNT WHERE account_id = :account_id");
    $stmt_check->bindParam(':account_id', $account_id_to_edit, PDO::PARAM_INT);
    $stmt_check->execute();
    $account_details = $stmt_check->fetch(PDO::FETCH_ASSOC);

    if (!$account_details) {
        $conn->rollBack();
        http_response_code(404);
        $response['error'] = "Account to be edited not found.";
        echo json_encode($response);
        exit;
    }
    $account_type = $account_details['account_type'];

    // 2. Update the ACCOUNT table
    $sql_account = "UPDATE ACCOUNT SET username = :username, name = :name, sex = :sex WHERE account_id = :account_id";
    $stmt_account = $conn->prepare($sql_account);
    $stmt_account->bindParam(':username', $username);
    $stmt_account->bindParam(':name', $name);
    $stmt_account->bindParam(':sex', $sex, $sex === null || $sex === '' ? PDO::PARAM_NULL : PDO::PARAM_STR);
    $stmt_account->bindParam(':account_id', $account_id_to_edit, PDO::PARAM_INT);

    if (!$stmt_account->execute()) {
        throw new PDOException("Failed to update ACCOUNT table.");
    }

    // 3. Conditionally update USER or ADMIN table
    if ($account_type === 'User') {
        // User specific validation
        if ($program_id === false || $program_id <= 0) {
            throw new Exception("Program is required for User accounts.");
        }
        // Update USER table
        $sql_user = "UPDATE USER SET academic_level = :academic_level, program_id = :program_id WHERE account_id = :account_id";
        $stmt_user = $conn->prepare($sql_user);
        $stmt_user->bindParam(':academic_level', $academic_level);
        $stmt_user->bindParam(':program_id', $program_id, PDO::PARAM_INT);
        $stmt_user->bindParam(':account_id', $account_id_to_edit, PDO::PARAM_INT);

        if (!$stmt_user->execute()) {
             throw new PDOException("Failed to update USER table.");
        }

    } elseif ($account_type === 'Admin') {
         // Update ADMIN table
         $sql_admin = "UPDATE ADMIN SET work_id = :work_id, position = :position WHERE account_id = :account_id";
         $stmt_admin = $conn->prepare($sql_admin);
         $stmt_admin->bindParam(':work_id', $work_id); // Allow empty/null based on schema
         $stmt_admin->bindParam(':position', $position); // Allow empty/null based on schema
         $stmt_admin->bindParam(':account_id', $account_id_to_edit, PDO::PARAM_INT);

         if (!$stmt_admin->execute()) {
              throw new PDOException("Failed to update ADMIN table.");
         }
    }

    // ---- If we reach here, the primary updates were successful ----

    // 4. Commit the transaction for the account update
    $conn->commit();

    // ---- Account Update successful, now perform logging ----
    try {
        // Define Log Details
        $action_type = 'UPDATE_ACCOUNT';
        $log_type = 'ACCOUNT';

        // Insert into LOG table
        $sql_log = "INSERT INTO LOG (actor_account_id, action_type, log_type) VALUES (:actor_account_id, :action_type, :log_type)";
        $stmt_log = $conn->prepare($sql_log);
        $stmt_log->bindParam(':actor_account_id', $loggedInAdminId, PDO::PARAM_INT); // Admin performing the action
        $stmt_log->bindParam(':action_type', $action_type);
        $stmt_log->bindParam(':log_type', $log_type);
        $stmt_log->execute();

        // Get the ID of the log entry just created
        $log_id = $conn->lastInsertId();

        // Insert into LOG_ACCOUNT detail table
        $sql_log_account = "INSERT INTO LOG_ACCOUNT (log_id, target_account_id, admin_account_id) VALUES (:log_id, :target_account_id, :admin_account_id)";
        $stmt_log_account = $conn->prepare($sql_log_account);
        $stmt_log_account->bindParam(':log_id', $log_id, PDO::PARAM_INT);
        $stmt_log_account->bindParam(':target_account_id', $account_id_to_edit, PDO::PARAM_INT); // The account that was modified
        $stmt_log_account->bindParam(':admin_account_id', $loggedInAdminId, PDO::PARAM_INT); // The admin performing the action
        $stmt_log_account->execute();

    } catch (PDOException $logE) {
        // Log the logging error, but don't necessarily fail the overall request
        // since the primary action (account update) succeeded.
        error_log("Logging Error in update_account.php after successful update: " . $logE->getMessage());
        // You could potentially add a flag to the success response indicating a logging issue, if needed.
        // $response['warning'] = "Account updated, but logging failed.";
    }

    // Send success response (even if logging had an issue, the main task is done)
    $response['success'] = "Account updated successfully.";
    echo json_encode($response);

} catch (PDOException $e) {
    // Rollback transaction on database error during account update
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Database Error in update_account.php: " . $e->getMessage());
    http_response_code(500);
    // Check for specific constraint violations
    if ($e->getCode() == 23000) {
         if (strpos($e->getMessage(), 'ACCOUNT.username') !== false) {
             $response['error'] = "Database error: Username already exists.";
         } elseif (strpos($e->getMessage(), 'ADMIN.work_id') !== false) {
             $response['error'] = "Database error: Work ID already exists.";
         } else {
             $response['error'] = "Database error: A unique value constraint was violated."; // More generic
         }
    } else {
        $response['error'] = "Database error occurred during update.";
    }
    echo json_encode($response);

} catch (Exception $e) {
    // Rollback transaction on validation or other general errors within the try block
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(400); // Bad request for validation errors
    $response['error'] = $e->getMessage();
    echo json_encode($response);
} finally {
    // Close connection
    $conn = null;
}
?>