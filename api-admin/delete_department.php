<?php
session_start();
header('Content-Type: application/json');
include '../api-general/config.php';

$response = [];

// Auth & Method Check
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Admin' || !isset($_SESSION['account_id'])) { http_response_code(403); echo json_encode(['error' => 'Access Denied.']); exit; }
$admin_actor_id = (int)$_SESSION['account_id'];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Invalid request method.']); exit; }

$department_id = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);

if ($department_id === false || $department_id <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'A valid Department ID is required.']);
    exit;
}

try {
    $conn->beginTransaction();

    // 1. Get department info (ensure it exists and get name for msg)
    $stmt_get = $conn->prepare("SELECT department_name FROM DEPARTMENT WHERE department_id = :id");
    $stmt_get->bindParam(':id', $department_id, PDO::PARAM_INT);
    $stmt_get->execute();
    $dept_info = $stmt_get->fetch(PDO::FETCH_ASSOC);

    if (!$dept_info) {
         throw new Exception("Department with ID " . $department_id . " not found.", 404);
    }
    $dept_name_for_log = $dept_info['department_name'];

    // --- PREPARE LOGGING ACTIONS (BEFORE DELETE) ---

    // 2. Insert into the main LOG table
    $log_action_type = 'DELETE_DEPARTMENT';
    $log_type = 'DEPARTMENT';
    $sql_log_main = "INSERT INTO LOG (actor_account_id, action_type, log_type) VALUES (:actor_id, :action_type, :log_type)";
    $stmt_log_main = $conn->prepare($sql_log_main);
    $stmt_log_main->bindParam(':actor_id', $admin_actor_id, PDO::PARAM_INT);
    $stmt_log_main->bindParam(':action_type', $log_action_type, PDO::PARAM_STR);
    $stmt_log_main->bindParam(':log_type', $log_type, PDO::PARAM_STR);

    if (!$stmt_log_main->execute()) {
        // If this fails, transaction rolls back automatically via exception
        throw new PDOException("Failed to insert into main LOG table during department delete prep.");
    }
    $new_log_id = $conn->lastInsertId();

    // 3. Insert into the specific LOG_DEPARTMENT table
    // Department ID still exists at this point in the transaction
    $sql_log_dept_detail = "INSERT INTO LOG_DEPARTMENT (log_id, department_id, admin_account_id) VALUES (:log_id, :dept_id, :admin_id)";
    $stmt_log_dept_detail = $conn->prepare($sql_log_dept_detail);
    $stmt_log_dept_detail->bindParam(':log_id', $new_log_id, PDO::PARAM_INT);
    $stmt_log_dept_detail->bindParam(':dept_id', $department_id, PDO::PARAM_INT);
    $stmt_log_dept_detail->bindParam(':admin_id', $admin_actor_id, PDO::PARAM_INT);

    if (!$stmt_log_dept_detail->execute()) {
        // If this fails, transaction rolls back automatically via exception
        throw new PDOException("Failed to insert into LOG_DEPARTMENT details table during department delete prep.");
    }

    // --- END LOGGING ACTIONS ---

    // 4. Now, attempt to delete the department
    $stmt_del = $conn->prepare("DELETE FROM DEPARTMENT WHERE department_id = :id");
    $stmt_del->bindParam(':id', $department_id, PDO::PARAM_INT);

    if (!$stmt_del->execute()) {
         // Let the PDOException handler below catch this and roll back the log inserts too
         throw new PDOException("Failed to execute department delete statement. Potential FK conflict?");
    }

    // 5. Check if deletion actually happened (rowCount check is crucial here)
    if ($stmt_del->rowCount() > 0) {
        // If delete succeeded (and previous steps did too), commit everything
        $conn->commit();

        // Success Response
        http_response_code(200);
        $response['success'] = "Department '" . htmlspecialchars($dept_name_for_log) . "' deleted successfully.";
        $response['department_id'] = $department_id;
        echo json_encode($response);
    } else {
         // SELECT found it, logging inserted, but DELETE affected 0 rows.
         // This implies it was deleted *between* the SELECT and DELETE by someone else.
         // We need to roll back the logging inserts.
         throw new Exception("Department with ID " . $department_id . " could not be deleted (possibly removed concurrently).", 409); // 409 Conflict
    }

} catch (PDOException $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    $httpStatusCode = 500;
    error_log("Delete Department PDOException for ID $department_id: " . $e->getMessage() . " Trace: " . $e->getTraceAsString()); // Add trace

    // Check for FK constraint on DELETE step this time
    if (($e->getCode() == '23000' || (isset($e->errorInfo[1]) && $e->errorInfo[1] == 1451)) && str_contains($e->getMessage(), "delete statement")) {
         $response['error'] = "Cannot delete department: It is still referenced by associated programs or dissertation abstracts. Please remove those associations first.";
         $httpStatusCode = 409;
    } elseif (str_contains($e->getMessage(), "insert into")) { // Check if it was the logging insert that failed
         $response['error'] = "Database error occurred during the logging preparation phase.";
         $httpStatusCode = 500;
    }
     else {
        $response['error'] = "A database error occurred while attempting to delete the department.";
        $httpStatusCode = 500;
    }
    http_response_code($httpStatusCode);
    echo json_encode($response);

} catch (Exception $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    $httpStatusCode = ($e->getCode() >= 400 && $e->getCode() < 600) ? $e->getCode() : 400;
    http_response_code($httpStatusCode);
    error_log("Delete Department Exception for ID $department_id: " . $e->getMessage());
    $response['error'] = $e->getMessage();
    echo json_encode($response);
}
// No finally block needed for $conn = null usually

?>