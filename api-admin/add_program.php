<?php
session_start();
header('Content-Type: application/json');

// Define standard log types/actions for consistency
define('LOG_ACTION_TYPE_CREATE_PROGRAM', 'CREATE_PROGRAM');
define('LOG_TYPE_PROGRAM', 'PROGRAM');

include '../api-general/config.php';

$response = [];

// Auth Check - Ensure admin is logged in
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Admin' || !isset($_SESSION['account_id'])) {
    http_response_code(403);
    $response['error'] = "Access denied. Admin privileges required.";
    echo json_encode($response);
    exit;
}
$admin_actor_id = $_SESSION['account_id'];

// Method Check
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $response['error'] = "Invalid request method. Only POST is allowed.";
    echo json_encode($response);
    exit;
}

// --- Input Retrieval and Basic Validation ---
$program_name_input = filter_input(INPUT_POST, 'program_name', FILTER_DEFAULT) ?? '';
$program_name = trim(strip_tags($program_name_input));

$program_initials_input = filter_input(INPUT_POST, 'program_initials', FILTER_DEFAULT) ?? '';
$program_initials = trim(strip_tags($program_initials_input));
$department_id_input = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);

// Strict validation
if (empty($program_name)) {
    http_response_code(400);
    $response['error'] = "Program Name is required.";
    echo json_encode($response);
    exit;
}
if (empty($program_initials)) {
    http_response_code(400);
    $response['error'] = "Program Initials are required.";
    echo json_encode($response);
    exit;
}
// Check if department_id is a valid positive integer
if ($department_id_input === false || $department_id_input === null || $department_id_input <= 0) {
    http_response_code(400);
    $response['error'] = "A valid Department ID is required.";
    echo json_encode($response);
    exit;
}
$department_id = $department_id_input; // Assign validated ID

// --- Database Operations ---
try {
    $conn->beginTransaction();

    //Check if department exists
    $stmt_check_dept = $conn->prepare("SELECT 1 FROM department WHERE department_id = :dept_id");
    $stmt_check_dept->bindParam(':dept_id', $department_id, PDO::PARAM_INT);
    $stmt_check_dept->execute();
    if ($stmt_check_dept->fetchColumn() === false) {
        $conn->rollBack();
        http_response_code(400);
        $response['error'] = "Selected Department (ID: {$department_id}) does not exist.";
        echo json_encode($response);
        exit;
    }

    //Insert Program
    $sql_insert_program = "INSERT INTO program (program_name, program_initials, department_id) VALUES (:name, :initials, :dept_id)";
    $stmt_insert_program = $conn->prepare($sql_insert_program);
    $stmt_insert_program->bindParam(':name', $program_name, PDO::PARAM_STR);
    $stmt_insert_program->bindParam(':initials', $program_initials, PDO::PARAM_STR);
    $stmt_insert_program->bindParam(':dept_id', $department_id, PDO::PARAM_INT);

    if (!$stmt_insert_program->execute()) {
        // Throw exception to be caught by the main catch block, which will handle rollback
        throw new PDOException("Failed to insert program into PROGRAM table.");
    }

    $new_program_id = $conn->lastInsertId();

    //Insert into LOG table
    $sql_log = "INSERT INTO log (actor_account_id, action_type, log_type) VALUES (:actor_id, :action_type, :log_type)";
    $stmt_log = $conn->prepare($sql_log);
    $stmt_log->bindParam(':actor_id', $admin_actor_id, PDO::PARAM_INT);
    $stmt_log->bindValue(':action_type', LOG_ACTION_TYPE_CREATE_PROGRAM, PDO::PARAM_STR);
    $stmt_log->bindValue(':log_type', LOG_TYPE_PROGRAM, PDO::PARAM_STR);

    if (!$stmt_log->execute()) {
        throw new PDOException("Failed to insert base log entry into LOG table.");
    }
    $log_id = $conn->lastInsertId();

    //Insert into LOG_PROGRAM detail table
    $sql_log_detail = "INSERT INTO log_program (log_program_id, program_id, admin_id) VALUES (:log_id, :program_id, :admin_id)";
    $stmt_log_detail = $conn->prepare($sql_log_detail);
    $stmt_log_detail->bindParam(':log_id', $log_id, PDO::PARAM_INT);
    $stmt_log_detail->bindParam(':program_id', $new_program_id, PDO::PARAM_INT);
    $stmt_log_detail->bindParam(':admin_id', $admin_actor_id, PDO::PARAM_INT);

    if (!$stmt_log_detail->execute()) {
        // Log this specific failure, although the transaction rollback will undo the LOG entry too.
        error_log("Failed to insert log detail for log_id $log_id, program_id $new_program_id. Rolling back transaction.");
        throw new PDOException("Failed to insert program log details into LOG_PROGRAM table.");
    }

    //If all inserts were successful
    $conn->commit();
    http_response_code(201); // 201 Created is often more appropriate for successful resource creation
    $response['success'] = "Program added successfully.";
    $response['program_id'] = $new_program_id;
    echo json_encode($response);

} catch (PDOException $e) {
    // Rollback transaction if any PDO operation failed
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500); // Internal Server Error for database issues
    error_log("Database Error in Add Program: " . $e->getMessage() . " | SQL State: " . $e->getCode());

    // Provide a user-friendly error message
    if ($e->getCode() == 23000) {
        if (strpos(strtolower($e->getMessage()), 'program_initials') !== false) {
             $response['error'] = "Database error: Program initials must be unique. Please choose different initials.";
        } else if (strpos(strtolower($e->getMessage()), 'foreign key constraint fails') !== false) {
             $response['error'] = "Database error: A related record constraint failed.";
             error_log("Possible FK violation involving admin_id: $admin_actor_id or program_id: $new_program_id during logging.");
        } else {
             $response['error'] = "Database error: A data constraint was violated.";
        }
    } else {
        $response['error'] = "A database error occurred while adding the program. Please try again later.";
    }
    echo json_encode($response);

} catch (Exception $e) {
    // Catch any other non-PDO exceptions
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    error_log("General Error in Add Program: " . $e->getMessage());
    $response['error'] = "An unexpected error occurred: " . $e->getMessage();
    echo json_encode($response);
} finally {
    // Close connection if necessary (often handled by PDO object lifecycle)
    // $conn = null; // Uncomment if your config doesn't handle connection closing
}
?>