<?php
session_start();
header('Content-Type: application/json');

// Assuming config.php establishes the PDO connection $conn
// and sets appropriate error reporting for your environment
include '../api-general/config.php';

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

// --- Input Retrieval and Validation ---
// FILTER_SANITIZE_STRING is deprecated in PHP 8.1+. Use FILTER_SANITIZE_SPECIAL_CHARS.
// Using FILTER_DEFAULT as a fallback if SANITIZE_STRING isn't available/deprecated.
$department_id = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);
$department_name = trim(filter_input(INPUT_POST, 'department_name', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
$department_initials = trim(filter_input(INPUT_POST, 'department_initials', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');

if ($department_id === false || $department_id <= 0) {
    http_response_code(400);
    $response['error'] = "A valid Department ID is required.";
    echo json_encode($response);
    exit;
}
if (empty($department_name)) {
    http_response_code(400);
    $response['error'] = "Department Name is required.";
    echo json_encode($response);
    exit;
}
// Optional: Add length validation etc. if needed

// --- Database Operation ---
try {
    $conn->beginTransaction();

    // 1. Optional but good: Check if department exists before updating
    $stmt_check = $conn->prepare("SELECT 1 FROM DEPARTMENT WHERE department_id = :id");
    $stmt_check->bindParam(':id', $department_id, PDO::PARAM_INT);
    $stmt_check->execute();
    if ($stmt_check->fetchColumn() === false) {
        // Rollback not needed yet, but throw exception to be caught
        throw new Exception("Department with ID " . $department_id . " not found.", 404); // Use specific exception code
    }

    // 2. Update the department details
    $sql_update = "UPDATE DEPARTMENT SET department_name = :name, department_initials = :initials WHERE department_id = :id";
    $stmt_update = $conn->prepare($sql_update);
    $stmt_update->bindParam(':name', $department_name, PDO::PARAM_STR);
    // Bind initials or NULL
    if (!empty($department_initials)) {
        $stmt_update->bindParam(':initials', $department_initials, PDO::PARAM_STR);
    } else {
        $stmt_update->bindValue(':initials', null, PDO::PARAM_NULL);
    }
    $stmt_update->bindParam(':id', $department_id, PDO::PARAM_INT);

    if (!$stmt_update->execute()) {
        // Let the PDOException handler below catch this after rollback
        throw new PDOException("Failed to execute department update statement.");
    }

    // Check if any rows were actually changed (optional, but good feedback)
    if ($stmt_update->rowCount() === 0) {
        // No rows affected - maybe data was the same? Treat as success or specific message?
        // For simplicity, we'll treat it as success here, but you could add a specific response.
        error_log("Update Department: No rows affected for ID $department_id. Data might be identical.");
    }

    // --- NEW LOGGING ACTIONS (Replaces old log_action) ---

    // 3. Insert into the main LOG table
    $log_action_type = 'UPDATE_DEPARTMENT'; // Standardized action type
    $log_type = 'DEPARTMENT';            // Specific log category
    $sql_log_main = "INSERT INTO LOG (actor_account_id, action_type, log_type) VALUES (:actor_id, :action_type, :log_type)";
    $stmt_log_main = $conn->prepare($sql_log_main);
    $stmt_log_main->bindParam(':actor_id', $admin_actor_id, PDO::PARAM_INT);
    $stmt_log_main->bindParam(':action_type', $log_action_type, PDO::PARAM_STR);
    $stmt_log_main->bindParam(':log_type', $log_type, PDO::PARAM_STR);

    if (!$stmt_log_main->execute()) {
        throw new PDOException("Failed to insert into main LOG table during department update.");
    }
    $new_log_id = $conn->lastInsertId(); // Get the ID of the general log entry

    // 4. Insert into the specific LOG_DEPARTMENT table
    $sql_log_dept_detail = "INSERT INTO LOG_DEPARTMENT (log_id, department_id, admin_account_id) VALUES (:log_id, :dept_id, :admin_id)";
    $stmt_log_dept_detail = $conn->prepare($sql_log_dept_detail);
    $stmt_log_dept_detail->bindParam(':log_id', $new_log_id, PDO::PARAM_INT);
    $stmt_log_dept_detail->bindParam(':dept_id', $department_id, PDO::PARAM_INT); // The ID of the dept being updated
    $stmt_log_dept_detail->bindParam(':admin_id', $admin_actor_id, PDO::PARAM_INT); // Admin performing the action

    if (!$stmt_log_dept_detail->execute()) {
        throw new PDOException("Failed to insert into LOG_DEPARTMENT details table during department update.");
    }

    // --- End New Logging Actions ---

    // 5. Commit the transaction
    $conn->commit();

    // --- Success Response ---
    http_response_code(200); // 200 OK is standard for successful updates
    $response['success'] = "Department updated successfully.";
    $response['department_id'] = $department_id; // Optionally return the ID
    echo json_encode($response);

} catch (PDOException $e) {
    // --- Handle Database Errors ---
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500); // Default to 500 Internal Server Error for DB issues

    // Log the detailed error for the admin/developer
    error_log("Update Department PDOException: " . $e->getMessage() . " - Input ID: $department_id");

    // Check for specific constraint violations (like unique initials)
    if ($e->getCode() == 23000 || $e->getCode() == '23000') { // Check unique constraint (string/int)
       if (stripos($e->getMessage(), 'department_initials') !== false) {
             $response['error'] = "Database error: Department initials must be unique. Please choose different initials.";
             http_response_code(409); // 409 Conflict is more specific
        } elseif (stripos($e->getMessage(), 'department_name') !== false) {
             // Add if you have a unique constraint on name
             $response['error'] = "Database error: A department with this name might already exist.";
             http_response_code(409);
        } else {
             $response['error'] = "Database error: Could not update department due to a data conflict.";
             http_response_code(409);
        }
    } elseif (str_contains($e->getMessage(), "Failed to insert")) {
         $response['error'] = "Database error occurred during the logging process. Department update might have been rolled back.";
    } else {
        // General database error message for the user
        $response['error'] = "A database error occurred while updating the department. Please try again later.";
    }
    echo json_encode($response);

} catch (Exception $e) {
    // --- Handle other specific errors (like 'Not Found') ---
    if ($conn->inTransaction()) {
        $conn->rollBack(); // Rollback if transaction was started
    }
    $errorCode = $e->getCode() == 404 ? 404 : 400; // Use 404 if code is 404, else maybe 400 Bad Request
    http_response_code($errorCode);
    error_log("Update Department Exception: " . $e->getMessage() . " - Input ID: $department_id");
    $response['error'] = $e->getMessage(); // Send back the specific message (e.g., "Department ... not found.")
    echo json_encode($response);
} finally {
    // Close connection if necessary (often handled by script end)
    // $conn = null;
}
?>