<?php
session_start();
header('Content-Type: application/json');

// Define standard log types/actions for consistency
define('LOG_ACTION_TYPE_DELETE_PROGRAM', 'DELETE_PROGRAM');
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

// --- Input Validation ---
$program_id = filter_input(INPUT_POST, 'program_id', FILTER_VALIDATE_INT);

if ($program_id === false || $program_id === null || $program_id <= 0) {
    http_response_code(400); // Bad Request
    $response['error'] = "A valid Program ID is required.";
    echo json_encode($response);
    exit;
}

// --- Database Operations ---
$program_name_deleted = 'ID:' . $program_id; // Default name for logging context

try {
    $conn->beginTransaction();

    // 1. Check if program exists and get its name (lock row optionally)
    $stmt_get = $conn->prepare("SELECT program_name FROM PROGRAM WHERE program_id = :id FOR UPDATE");
    $stmt_get->bindParam(':id', $program_id, PDO::PARAM_INT);
    $stmt_get->execute();
    $program_info = $stmt_get->fetch(PDO::FETCH_ASSOC);

    if (!$program_info) {
        $conn->rollBack();
        http_response_code(404); // Not Found
        $response['error'] = "Program with ID {$program_id} not found.";
        echo json_encode($response);
        exit;
    }
    $program_name_deleted = $program_info['program_name']; // Get actual name

    // 2. Attempt to Delete Program
    // This is where a FK constraint violation (like 1451) might occur if other tables reference this program.
    $stmt_del = $conn->prepare("DELETE FROM PROGRAM WHERE program_id = :id");
    $stmt_del->bindParam(':id', $program_id, PDO::PARAM_INT);

    if (!$stmt_del->execute()) {
        // This primarily catches non-exception execution failures.
        // FK violations usually throw PDOExceptions caught below.
        throw new PDOException("Failed to execute delete statement for program ID {$program_id}, but no exception was thrown.");
    }

    // 3. Verify deletion happened (rowCount)
    if ($stmt_del->rowCount() === 0) {
        // Should ideally not happen due to the initial check + FOR UPDATE, but handles edge cases.
        throw new Exception("Program found initially but could not be deleted (rowCount is 0). It might have been deleted by another process.");
    }

    // --- If deletion was successful (rowCount > 0), proceed to log ---

    // 4. Insert *only* into the main LOG table
    // Store essential info here. Consider adding a `target_entity_id` column to LOG
    // or a `details` column in the future if more info is needed directly in the log.
    $log_action_details = "Deleted Program: Name='{$program_name_deleted}', ID={$program_id}"; // Example detail
    $sql_log = "INSERT INTO LOG (actor_account_id, action_type, log_type) VALUES (:actor_id, :action_type, :log_type)";
    // Consider adding columns to LOG like: , target_entity_id, details
    // And values like: , :target_id, :details
    $stmt_log = $conn->prepare($sql_log);
    $stmt_log->bindParam(':actor_id', $admin_actor_id, PDO::PARAM_INT);
    $stmt_log->bindValue(':action_type', LOG_ACTION_TYPE_DELETE_PROGRAM, PDO::PARAM_STR);
    $stmt_log->bindValue(':log_type', LOG_TYPE_PROGRAM, PDO::PARAM_STR);
    // If adding columns:
    // $stmt_log->bindParam(':target_id', $program_id, PDO::PARAM_INT);
    // $stmt_log->bindParam(':details', $log_action_details, PDO::PARAM_STR);


    if (!$stmt_log->execute()) {
        // If logging fails, roll back the deletion.
        throw new PDOException("Successfully deleted program ID {$program_id} but failed to insert the base log entry. Rolling back deletion.");
    }
    // $log_id = $conn->lastInsertId(); // We get the log_id but don't use it further here.

    // 5. *** REMOVED INSERT INTO LOG_PROGRAM ***
    // We do not insert into LOG_PROGRAM for delete actions because the program_id no longer exists,
    // which would violate the foreign key constraint `log_program_ibfk_2`.

    // 6. Commit Transaction - Only if delete AND main logging succeeded
    $conn->commit();
    http_response_code(200); // OK
    $response['success'] = "Program '{$program_name_deleted}' (ID: {$program_id}) deleted successfully.";
    // Include the logged detail for confirmation if desired
    // $response['log_details'] = $log_action_details;
    echo json_encode($response);

} catch (PDOException $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Database Error in Delete Program: " . $e->getMessage() . " | SQL State: " . $e->getCode() . " | Program ID: " . $program_id);

    // Check specifically for Foreign Key constraint violation *on the DELETE statement itself* (SQLSTATE 23000 / MySQL error 1451)
    $isForeignKeyConstraintOnDelete = ($e->getCode() == '23000' && (isset($e->errorInfo[1]) && $e->errorInfo[1] == 1451));

    if ($isForeignKeyConstraintOnDelete) {
        http_response_code(409); // Conflict
        $response['error'] = "Cannot delete program '{$program_name_deleted}'. It is currently referenced by active users or thesis abstracts. Please reassign or remove these references first.";
    } else {
        // Handle other database errors (including potential failure during LOG insert)
        http_response_code(500); // Internal Server Error
        $response['error'] = "A database error occurred while attempting to delete the program or log the action.";
    }
    echo json_encode($response);

} catch (Exception $e) {
    // Catch non-PDO exceptions
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500); // Treat unexpected exceptions as server errors
    error_log("General Error in Delete Program: " . $e->getMessage() . " | Program ID: " . $program_id);
    $response['error'] = "An unexpected error occurred: " . $e->getMessage();
    echo json_encode($response);
} finally {
    // $conn = null;
}
?>