<?php
session_start();
header('Content-Type: application/json');

// Define standard log types/actions for consistency
define('LOG_ACTION_TYPE_UPDATE_PROGRAM', 'UPDATE_PROGRAM');
define('LOG_TYPE_PROGRAM', 'PROGRAM');

// Assuming config.php provides the $conn PDO object
include '../api-general/config.php';

$response = [];

// --- Auth & Method Check ---
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Admin' || !isset($_SESSION['account_id'])) {
    http_response_code(403); // Forbidden
    echo json_encode(['error' => 'Access denied. Admin privileges required.']);
    exit;
}
$admin_actor_id = $_SESSION['account_id']; // The admin performing the action

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['error' => 'Invalid request method. Only POST is allowed.']);
    exit;
}

// --- Input Retrieval and Validation ---
$program_id = filter_input(INPUT_POST, 'program_id', FILTER_VALIDATE_INT);
// Replace deprecated FILTER_SANITIZE_STRING with FILTER_DEFAULT
$program_name = trim(filter_input(INPUT_POST, 'program_name', FILTER_DEFAULT) ?? '');
$program_initials = trim(filter_input(INPUT_POST, 'program_initials', FILTER_DEFAULT) ?? '');
$department_id = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);

// Validate inputs
$errors = [];
if ($program_id === false || $program_id === null || $program_id <= 0) {
    $errors[] = "A valid Program ID is required.";
}
if (empty($program_name)) {
    $errors[] = "Program Name is required.";
}
if (empty($program_initials)) {
    $errors[] = "Program Initials are required.";
}
if ($department_id === false || $department_id === null || $department_id <= 0) {
    $errors[] = "A valid Department ID is required.";
}

if (!empty($errors)) {
    http_response_code(400); // Bad Request
    $response['error'] = implode(' ', $errors);
    echo json_encode($response);
    exit;
}

// --- Database Operations ---
try {
    $conn->beginTransaction();

    // 1. Check if the program to update actually exists
    $stmt_check_prog = $conn->prepare("SELECT 1 FROM PROGRAM WHERE program_id = :prog_id FOR UPDATE"); // Lock row
    $stmt_check_prog->bindParam(':prog_id', $program_id, PDO::PARAM_INT);
    $stmt_check_prog->execute();
    if ($stmt_check_prog->fetchColumn() === false) {
        $conn->rollBack();
        http_response_code(404); // Not Found
        $response['error'] = "Program with ID {$program_id} not found.";
        echo json_encode($response);
        exit;
    }

    // 2. Check if the *new* selected department exists
    $stmt_check_dept = $conn->prepare("SELECT 1 FROM DEPARTMENT WHERE department_id = :dept_id");
    $stmt_check_dept->bindParam(':dept_id', $department_id, PDO::PARAM_INT);
    $stmt_check_dept->execute();
    if ($stmt_check_dept->fetchColumn() === false) {
        $conn->rollBack();
        http_response_code(400); // Bad request because the referenced department doesn't exist
        $response['error'] = "Selected Department (ID: {$department_id}) does not exist.";
        echo json_encode($response);
        exit;
    }

    // 3. Update Program
    $sql_update = "UPDATE PROGRAM SET program_name = :name, program_initials = :initials, department_id = :dept_id WHERE program_id = :program_id";
    $stmt_update = $conn->prepare($sql_update);
    $stmt_update->bindParam(':name', $program_name, PDO::PARAM_STR);
    $stmt_update->bindParam(':initials', $program_initials, PDO::PARAM_STR);
    $stmt_update->bindParam(':dept_id', $department_id, PDO::PARAM_INT);
    $stmt_update->bindParam(':program_id', $program_id, PDO::PARAM_INT);

    if (!$stmt_update->execute()) {
        // Let the generic PDOException handler catch this
        throw new PDOException("Failed to execute program update statement.");
    }

    // Optional: Check if any rows were actually affected.
    // If rowCount is 0, it means the program existed but no data was changed.
    // You might choose to still log this as an attempted update or return a specific message.
    // For simplicity here, we proceed even if 0 rows affected, assuming execute() succeeded.
    // if ($stmt_update->rowCount() == 0) {
    //     // Handle case where no data actually changed
    // }

    // 4. Insert into LOG table
    $sql_log = "INSERT INTO LOG (actor_account_id, action_type, log_type) VALUES (:actor_id, :action_type, :log_type)";
    $stmt_log = $conn->prepare($sql_log);
    $stmt_log->bindParam(':actor_id', $admin_actor_id, PDO::PARAM_INT);
    $stmt_log->bindValue(':action_type', LOG_ACTION_TYPE_UPDATE_PROGRAM, PDO::PARAM_STR);
    $stmt_log->bindValue(':log_type', LOG_TYPE_PROGRAM, PDO::PARAM_STR);

    if (!$stmt_log->execute()) {
        throw new PDOException("Failed to insert base log entry into LOG table.");
    }
    $log_id = $conn->lastInsertId();

    // 5. Insert into LOG_PROGRAM detail table
    $sql_log_detail = "INSERT INTO LOG_PROGRAM (log_id, program_id, admin_account_id) VALUES (:log_id, :program_id, :admin_id)";
    $stmt_log_detail = $conn->prepare($sql_log_detail);
    $stmt_log_detail->bindParam(':log_id', $log_id, PDO::PARAM_INT);
    $stmt_log_detail->bindParam(':program_id', $program_id, PDO::PARAM_INT); // The program that was affected
    $stmt_log_detail->bindParam(':admin_id', $admin_actor_id, PDO::PARAM_INT); // The admin who performed the action

    if (!$stmt_log_detail->execute()) {
        error_log("Failed to insert log detail for log_id $log_id, program_id $program_id. Rolling back transaction.");
        throw new PDOException("Failed to insert program log details into LOG_PROGRAM table.");
    }

    // 6. Commit Transaction
    $conn->commit();
    http_response_code(200); // OK for successful update
    $response['success'] = "Program updated successfully.";
    echo json_encode($response);

} catch (PDOException $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    http_response_code(500); // Internal Server Error
    error_log("Database Error in Update Program: " . $e->getMessage() . " | SQL State: " . $e->getCode());

    if ($e->getCode() == 23000) { // Integrity constraint violation
        if (strpos(strtolower($e->getMessage()), 'program_initials') !== false) {
            $response['error'] = "Database error: Program initials must be unique. Please choose different initials.";
        } else if (strpos(strtolower($e->getMessage()), 'foreign key constraint fails') !== false) {
             $response['error'] = "Database error: A related record constraint failed (e.g., Department or Admin ID).";
             error_log("Possible FK violation involving admin_id: $admin_actor_id, program_id: $program_id, or dept_id: $department_id during update/logging.");
        }
        else {
            $response['error'] = "Database error: A data constraint was violated during the update.";
        }
    } else {
        $response['error'] = "A database error occurred while updating the program.";
    }
    echo json_encode($response);

} catch (Exception $e) {
    // Catch non-PDO exceptions (like the ones we threw manually for not found)
    // Note: Our specific "not found" exceptions are now handled *before* this catch block.
    // This block would catch other unexpected general exceptions.
    if ($conn->inTransaction()) $conn->rollBack();
    http_response_code(500); // Treat unexpected exceptions as server errors
    error_log("General Error in Update Program: " . $e->getMessage());
    $response['error'] = "An unexpected error occurred: " . $e->getMessage();
    echo json_encode($response);
} finally {
    // Optional: Close connection if needed
    // $conn = null;
}
?>