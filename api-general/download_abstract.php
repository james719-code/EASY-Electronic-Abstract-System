<?php

session_start();
header('Content-Type: application/json'); // Default header, overridden on success

// --- Configuration ---
$baseUploadDir = realpath('../pdf/'); // Adjust path if necessary
if ($baseUploadDir === false) {
    http_response_code(500);
    error_log("Server configuration error: Base upload directory '../pdf/' does not exist or is inaccessible.");
    echo json_encode(['error' => 'Server configuration error: File storage directory invalid.']);
    exit;
}

// Define standard log types/actions
define('LOG_ACTION_TYPE_DOWNLOAD_ABSTRACT', 'DOWNLOAD_ABSTRACT'); // Specific action type
define('LOG_TYPE_ABSTRACT', 'ABSTRACT');

// Assuming config.php provides the $conn PDO object
include '../api-general/config.php'; // Adjust path as needed

$response = [];

// --- Authentication Check ---
// Require login to download
if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], ['Admin', 'User']) || !isset($_SESSION['account_id'])) {
    http_response_code(403); // Forbidden
    echo json_encode(['error' => 'Access denied. You must be logged in to download abstracts.']);
    exit;
}
$actor_account_id = $_SESSION['account_id']; // The user or admin downloading

// --- Input Validation ---
$abstract_id_input = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);

if ($abstract_id_input === false || $abstract_id_input === null) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Invalid request: Abstract ID missing or invalid.']);
    exit;
}
$abstract_id = $abstract_id_input;

try {
    // --- Fetch File Location, Size, and Abstract Title ---
    $stmt_file = $conn->prepare("SELECT fd.file_location, fd.file_size, a.title
                                 FROM file_detail fd
                                 JOIN abstract a ON fd.abstract_id = a.abstract_id
                                 WHERE fd.abstract_id = :id
                                 LIMIT 1");
    $stmt_file->bindParam(':id', $abstract_id, PDO::PARAM_INT);
    $stmt_file->execute();

    $file_details = $stmt_file->fetch(PDO::FETCH_ASSOC);

    // --- Check if Abstract/File Record Exists ---
    if (!$file_details) {
        http_response_code(404); // Not Found
        echo json_encode(['error' => 'No abstract or file record found for the given ID.']);
        exit;
    }

    $file_location = $file_details['file_location'];
    $file_size = $file_details['file_size']; // Size stored in DB
    $abstract_title = $file_details['title'] ?? 'Untitled Abstract';

    // --- Security and File System Checks ---
    // Security: Ensure the path is within the designated upload directory
    $real_file_location = realpath($file_location);
    if ($real_file_location === false || strpos($real_file_location, $baseUploadDir) !== 0) {
         error_log("Security Alert: Download attempt outside designated upload directory or invalid path. DB Path: {$file_location}, Real Path Attempt: {$real_file_location}, Base Dir: {$baseUploadDir}, Abstract ID: {$abstract_id}, User: {$actor_account_id}");
         http_response_code(403); // Forbidden
         echo json_encode(['error' => 'Access denied to the requested file resource. Invalid path.']);
         exit;
    }

    // Existence Check
    if (!file_exists($real_file_location)) {
        error_log("File not found on disk for download at location: {$real_file_location} (DB Path: {$file_location}) for Abstract ID: {$abstract_id}");
        http_response_code(404);
        echo json_encode(['error' => 'Abstract file record exists, but the file is missing from storage.']);
        exit;
    }

    // 3. Readability Check
    if (!is_readable($real_file_location)) {
        error_log("File not readable for download at location: {$real_file_location} for Abstract ID: {$abstract_id}");
        http_response_code(500); // Server error (permissions likely)
        echo json_encode(['error' => 'Server error: Unable to read the abstract file due to permissions.']);
        exit;
    }

    // --- Logging (Perform *before* sending file headers) ---
    try {
        $conn->beginTransaction();

        // 1. Insert into LOG table
        $sql_log = "INSERT INTO log (actor_account_id, action_type, log_type) VALUES (:actor_id, :action_type, :log_type)";
        $stmt_log = $conn->prepare($sql_log);
        $stmt_log->bindParam(':actor_id', $actor_account_id, PDO::PARAM_INT);
        $stmt_log->bindValue(':action_type', LOG_ACTION_TYPE_DOWNLOAD_ABSTRACT, PDO::PARAM_STR); // Use specific action type
        $stmt_log->bindValue(':log_type', LOG_TYPE_ABSTRACT, PDO::PARAM_STR);
        $stmt_log->execute();
        $log_id = $conn->lastInsertId();

        // 2. Insert into LOG_ABSTRACT detail table
        $sql_log_detail = "INSERT INTO log_abstract (log_abstract_id, abstract_id, account_id) VALUES (:log_id, :abstract_id, :account_id)";
        $stmt_log_detail = $conn->prepare($sql_log_detail);
        $stmt_log_detail->bindParam(':log_id', $log_id, PDO::PARAM_INT);
        $stmt_log_detail->bindParam(':abstract_id', $abstract_id, PDO::PARAM_INT); // The abstract being downloaded
        $stmt_log_detail->bindParam(':account_id', $actor_account_id, PDO::PARAM_INT); // The downloader
        $stmt_log_detail->execute();

        $conn->commit();

    } catch (PDOException $log_e) {
         if ($conn->inTransaction()) {
            $conn->rollBack();
         }
        error_log("Failed to log abstract download (Abstract ID: {$abstract_id}, User ID: {$actor_account_id}): " . $log_e->getMessage());
    }

    // --- Send HTTP Headers for File Download ---
    if (ob_get_level()) {
        ob_end_clean();
    }

    // Set appropriate headers for forcing download
    header('Content-Description: File Transfer'); // Generic description
    header('Content-Type: application/pdf'); // Assuming only PDFs
    $safe_filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $abstract_title);
    $suggested_filename = 'abstract_' . $abstract_id . '_' . $safe_filename . '.pdf';
    // Key change: 'attachment' instead of 'inline'
    header('Content-Disposition: attachment; filename="' . $suggested_filename . '"');
    header('Content-Transfer-Encoding: binary'); // Indicate binary transfer
    header('Expires: 0'); // Prevent caching
    header('Cache-Control: must-revalidate');
    header('Pragma: public'); // For IE compatibility
    header('Content-Length: ' . $file_size); // Use size from DB

    // --- Output File Content ---
    readfile($real_file_location);

    // --- Stop Execution ---
    exit;

} catch (PDOException $e) {
    // Database error during initial fetch
    http_response_code(500);
    error_log("Download Abstract DB Error: " . $e->getMessage() . " | Abstract ID: " . $abstract_id);
    if (!headers_sent()) { // Check if headers already sent before outputting JSON
        header('Content-Type: application/json');
    }
    echo json_encode(['error' => 'A database error occurred while retrieving the abstract file details for download.']);
    exit;

} catch (Throwable $t) { // Catch any other unexpected errors
     http_response_code(500);
     error_log("General Error Downloading Abstract: " . $t->getMessage() . " | Abstract ID: " . $abstract_id);
     if (!headers_sent()) {
        header('Content-Type: application/json');
     }
     echo json_encode(['error' => 'An unexpected server error occurred during download.']);
     exit;
}

// Fallback error (should not be reached)
http_response_code(500);
if (!headers_sent()) {
    header('Content-Type: application/json');
}
echo json_encode(['error' => 'An unexpected script termination occurred.']);
?>