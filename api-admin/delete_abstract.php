<?php
session_start();

// Set content type to JSON
header('Content-Type: application/json');

// --- Configuration ---
$baseUploadDir = realpath('../pdf/');
if ($baseUploadDir === false) {
    http_response_code(500);
    error_log("Server configuration error: Base upload directory '../pdf/' does not exist or is inaccessible for deletion script.");
    echo json_encode(['error' => 'Server configuration error: File storage directory invalid.']);
    exit;
}

// Define standard log types/actions
define('LOG_ACTION_TYPE_DELETE_ABSTRACT', 'DELETE_ABSTRACT');
define('LOG_TYPE_ABSTRACT', 'ABSTRACT');

include '../api-general/config.php';

$response = [];

// --- Authentication and Authorization ---
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Admin' || !isset($_SESSION['account_id'])) {
    http_response_code(403); // Forbidden
    $response['error'] = "Access denied. Admin privileges required to delete abstracts.";
    echo json_encode($response);
    exit;
}
$admin_actor_id = $_SESSION['account_id'];

// --- Method Check ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    $response['error'] = 'Invalid request method. Use POST to delete.';
    echo json_encode($response);
    exit;
}

// --- Input Validation ---
$abstract_id_input = filter_input(INPUT_POST, 'abstract_id', FILTER_VALIDATE_INT);

if ($abstract_id_input === false || $abstract_id_input <= 0) {
    http_response_code(400); // Bad Request
    $response['error'] = 'Valid Abstract ID is required.';
    echo json_encode($response);
    exit;
}

$abstractId = $abstract_id_input;

// Variables to store fetched data
$title_deleted = null;
$file_location_to_delete = null;
$real_file_location = null;

try {
    $conn->beginTransaction();

    // Get Abstract Title AND File Location
    $stmt_get_info = $conn->prepare("SELECT a.title, fd.file_location
                                     FROM abstract a
                                     LEFT JOIN file_detail fd ON a.abstract_id = fd.abstract_id
                                     WHERE a.abstract_id = :abstract_id
                                     LIMIT 1");
    $stmt_get_info->bindParam(':abstract_id', $abstractId, PDO::PARAM_INT);
    $stmt_get_info->execute();
    $abstract_info = $stmt_get_info->fetch(PDO::FETCH_ASSOC);
    $stmt_get_info->closeCursor();

    if (!$abstract_info) {
        $conn->rollBack();
        http_response_code(404);
        $response['error'] = 'Abstract not found.';
        echo json_encode($response);
        exit;
    }

    $title_deleted = $abstract_info['title'];
    $file_location_to_delete = $abstract_info['file_location'];

    // Validate File Path
    if ($file_location_to_delete) {
        $real_file_location = realpath($file_location_to_delete);
        if ($real_file_location === false || strpos($real_file_location, $baseUploadDir) !== 0) {
            error_log("Security Alert during Delete: Attempted access outside designated directory. DB Path: {$file_location_to_delete}, Abstract ID: {$abstractId}, Admin: {$admin_actor_id}");
            $conn->rollBack();
            http_response_code(403);
            $response['error'] = 'Access denied to the associated file resource due to invalid path.';
            echo json_encode($response);
            exit;
        }
    }

    // Log the Deletion Action *BEFORE* deleting the abstract
    $log_id = null;
    try {
        // Insert into LOG table
        $sql_log = "INSERT INTO log (actor_account_id, action_type, log_type) VALUES (:actor_id, :action_type, :log_type)";
        $stmt_log = $conn->prepare($sql_log);
        $stmt_log->bindParam(':actor_id', $admin_actor_id, PDO::PARAM_INT);
        $stmt_log->bindValue(':action_type', LOG_ACTION_TYPE_DELETE_ABSTRACT, PDO::PARAM_STR);
        $stmt_log->bindValue(':log_type', LOG_TYPE_ABSTRACT, PDO::PARAM_STR);
        if (!$stmt_log->execute()) {
             throw new PDOException("Failed to insert base log entry for abstract deletion.");
        }
        $log_id = $conn->lastInsertId();
        $stmt_log->closeCursor();

        // Insert into LOG_ABSTRACT detail table
        $sql_log_detail = "INSERT INTO log_abstract (log_abstract_id, abstract_id, account_id) VALUES (:log_id, :abstract_id, :account_id)";
        $stmt_log_detail = $conn->prepare($sql_log_detail);
        $stmt_log_detail->bindParam(':log_id', $log_id, PDO::PARAM_INT);
        $stmt_log_detail->bindParam(':abstract_id', $abstractId, PDO::PARAM_INT);
        $stmt_log_detail->bindParam(':account_id', $admin_actor_id, PDO::PARAM_INT);
         if (!$stmt_log_detail->execute()) {
             throw new PDOException("Failed to insert abstract log details for abstract deletion.");
        }
         $stmt_log_detail->closeCursor();

         error_log("Successfully logged deletion intent for abstract ID: {$abstractId}, Log ID: {$log_id}");

    } catch (PDOException $log_e) {
        // Logging failed - Rollback immediately
         error_log("CRITICAL: Database logging failed BEFORE deleting abstract ID: " . $abstractId . " by admin ID: " . $admin_actor_id . ". Error: " . $log_e->getMessage() . ". Rolling back transaction.");
         throw new Exception("Database logging failed. Operation cancelled.", 0, $log_e); // Trigger rollback in main catch
    }

    // Delete from the main ABSTRACT table.
    $stmt_delete_abstract = $conn->prepare("DELETE FROM abstract WHERE abstract_id = :abstract_id");
    $stmt_delete_abstract->bindParam(':abstract_id', $abstractId, PDO::PARAM_INT);
    $deleteSuccess = $stmt_delete_abstract->execute();

    if (!$deleteSuccess) {
        throw new PDOException("Failed to execute database delete statement for abstract ID {$abstractId}.");
    }

    $rowCount = $stmt_delete_abstract->rowCount();
    $stmt_delete_abstract->closeCursor();

    if ($rowCount > 0) {
        // DB Deletion successful

        // Delete the actual file from filesystem 
        if ($real_file_location) {
            if (file_exists($real_file_location)) {
                 if (is_writable($real_file_location)) {
                     if (!unlink($real_file_location)) {
                        error_log("CRITICAL: Failed to unlink file: {$real_file_location} after DB delete for abstract ID: {$abstractId}. Rolling back transaction.");
                        throw new Exception("Failed to delete the associated file from storage. Permissions may be incorrect.");
                     } else {
                        error_log("Successfully unlinked file: {$real_file_location} for deleted abstract ID: {$abstractId}");
                     }
                 } else {
                     error_log("CRITICAL: File not writable, cannot unlink: {$real_file_location} after DB delete for abstract ID: {$abstractId}. Rolling back transaction.");
                     throw new Exception("Cannot delete the associated file from storage due to file permissions.");
                 }
            } else {
                error_log("Notice: File path found in DB but file did not exist on disk for deletion: {$real_file_location} for abstract ID: {$abstractId}. DB record deleted.");
            }
        } else {
             error_log("No valid file location associated with abstract ID: {$abstractId}, skipping file unlink.");
        }

        // Commit transaction
        $conn->commit();
        $response['success'] = "Abstract ID {$abstractId} ('{$title_deleted}') and associated data/file deleted successfully.";
        echo json_encode($response);

    } else {
        error_log("Failed to delete abstract ID {$abstractId}. rowCount was 0 during DELETE, rolling back log entry.");
        throw new Exception("Abstract could not be deleted. It might have been deleted by another process after logging.");
    }

} catch (PDOException $e) {
    // Rollback on any database error
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    error_log("Database Error during abstract deletion (ID: {$abstractId}): " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
    $response['error'] = 'An internal database error occurred while deleting the abstract.';
    echo json_encode($response);

} catch (Exception $e) { // Catch specific file unlink or logging exceptions that trigger rollback
     if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    error_log("General Error during abstract deletion (ID: {$abstractId}): " . $e->getMessage() . "\nTrace: " . $e->getTraceAsString());
    // Provide a more specific message if possible (e.g., from file unlink failure)
    $response['error'] = 'An unexpected error occurred during deletion: ' . $e->getMessage();
    echo json_encode($response);
}
?>