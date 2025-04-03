<?php
session_start();
header('Content-Type: application/json');

// Assuming config.php establishes the PDO connection $conn
// Make sure error reporting is suitable for production/development
// error_reporting(0); // For production
// ini_set('display_errors', 0); // For production
include '../api-general/config.php'; // Includes DB connection ($conn)

$response = [];

// --- Authentication & Authorization ---
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Admin' || !isset($_SESSION['account_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access Denied. You must be an admin to perform this action.']);
    exit;
}
$admin_actor_id = (int)$_SESSION['account_id']; // Ensure it's an integer

// --- Method Check ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Invalid request method. Only POST is allowed.']);
    exit;
}

// --- Input Retrieval and Basic Validation ---
// Use FILTER_SANITIZE_SPECIAL_CHARS for strings that will be displayed or stored,
// as FILTER_SANITIZE_STRING is deprecated in PHP 8.1+
$department_name = trim(filter_input(INPUT_POST, 'department_name', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
$department_initials = trim(filter_input(INPUT_POST, 'department_initials', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');

// --- Stricter Validation ---
if (empty($department_name)) {
    http_response_code(400);
    $response['error'] = "Department Name is required.";
    echo json_encode($response);
    exit;
}
// Optional: Add validation for initials length or format if needed
// if (!empty($department_initials) && strlen($department_initials) > 10) {
//     http_response_code(400);
//     $response['error'] = "Department Initials cannot exceed 10 characters.";
//     echo json_encode($response);
//     exit;
// }


// --- Database Operation ---
try {
    $conn->beginTransaction();

    // 1. Insert the new department
    $sql_insert_dept = "INSERT INTO DEPARTMENT (department_name, department_initials) VALUES (:name, :initials)";
    $stmt_insert_dept = $conn->prepare($sql_insert_dept);
    $stmt_insert_dept->bindParam(':name', $department_name, PDO::PARAM_STR);

    // Bind initials only if not empty, otherwise bind NULL
    if (!empty($department_initials)) {
        $stmt_insert_dept->bindParam(':initials', $department_initials, PDO::PARAM_STR);
    } else {
        $stmt_insert_dept->bindValue(':initials', null, PDO::PARAM_NULL);
    }

    if (!$stmt_insert_dept->execute()) {
        // Throw exception to be caught below, triggering rollback
        throw new PDOException("Failed to insert department data.");
    }
    $new_dept_id = $conn->lastInsertId();

    // --- Logging Actions (New Structure) ---

    // 2. Insert into the main LOG table
    $log_action_type = 'CREATE_DEPARTMENT'; // Standardized action type
    $log_type = 'DEPARTMENT';            // Specific log category
    $sql_log_main = "INSERT INTO LOG (actor_account_id, action_type, log_type) VALUES (:actor_id, :action_type, :log_type)";
    $stmt_log_main = $conn->prepare($sql_log_main);
    $stmt_log_main->bindParam(':actor_id', $admin_actor_id, PDO::PARAM_INT);
    $stmt_log_main->bindParam(':action_type', $log_action_type, PDO::PARAM_STR);
    $stmt_log_main->bindParam(':log_type', $log_type, PDO::PARAM_STR);

    if (!$stmt_log_main->execute()) {
        throw new PDOException("Failed to insert into main LOG table.");
    }
    $new_log_id = $conn->lastInsertId(); // Get the ID of the general log entry

    // 3. Insert into the specific LOG_DEPARTMENT table
    $sql_log_dept_detail = "INSERT INTO LOG_DEPARTMENT (log_id, department_id, admin_account_id) VALUES (:log_id, :dept_id, :admin_id)";
    $stmt_log_dept_detail = $conn->prepare($sql_log_dept_detail);
    $stmt_log_dept_detail->bindParam(':log_id', $new_log_id, PDO::PARAM_INT);
    $stmt_log_dept_detail->bindParam(':dept_id', $new_dept_id, PDO::PARAM_INT);
    $stmt_log_dept_detail->bindParam(':admin_id', $admin_actor_id, PDO::PARAM_INT); // Admin performing the action

    if (!$stmt_log_dept_detail->execute()) {
        throw new PDOException("Failed to insert into LOG_DEPARTMENT details table.");
    }

    // --- Commit Transaction ---
    $conn->commit();

    // --- Success Response ---
    http_response_code(201); // 201 Created is appropriate for successful creation
    $response['success'] = "Department added successfully.";
    $response['department_id'] = $new_dept_id; // Optionally return the new ID
    echo json_encode($response);

} catch (PDOException $e) {
    // --- Error Handling & Rollback ---
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    // Log the detailed error internally for debugging
    error_log("Add Department Error: " . $e->getMessage() . " - Input Data: name=" . $department_name . ", initials=" . $department_initials);

    // Provide a user-friendly error message
    http_response_code(500); // Internal Server Error is a common choice
    if ($e->getCode() == 23000 || $e->getCode() == '23000') { // Check for unique constraint violation (string or int depending on driver)
        if (stripos($e->getMessage(), 'department_initials') !== false) {
             $response['error'] = "Database error: Department initials must be unique. Please choose different initials.";
             http_response_code(409); // 409 Conflict might be more specific here
        } elseif (stripos($e->getMessage(), 'department_name') !== false) {
             // Assuming department_name also has a unique constraint (though not in the schema provided)
             $response['error'] = "Database error: A department with this name might already exist.";
             http_response_code(409);
        } else {
             $response['error'] = "Database error: Could not add department due to a data conflict (e.g., duplicate entry).";
             http_response_code(409);
        }
    } elseif (str_contains($e->getMessage(), "Failed to insert")) {
        // Catch specific exceptions thrown above for logging failures
         $response['error'] = "Database error occurred during the logging process. Department might not have been added.";
    } else {
        // General database error
        $response['error'] = "A database error occurred while attempting to add the department. Please try again later.";
    }
    echo json_encode($response);

} finally {
    // Close connection if necessary (often handled by framework or script end)
    // $conn = null;
}
?>