<?php
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
// ini_set('error_log', '/path/to/your/php-error.log'); // Optional: specify log file

session_start();
header('Content-Type: application/json'); // Default header, overridden on success

// --- Configuration ---
// Define the **base directory** where files are stored.
// This MUST match the logic used in the 'add abstract' script.
// Use realpath to get the canonicalized absolute path for security checks.
$baseUploadDir = realpath('../pdf/'); // Adjust path if necessary
if ($baseUploadDir === false) {
    http_response_code(500);
    error_log("Server configuration error: Base upload directory '../pdf/' does not exist or is inaccessible.");
    echo json_encode(['error' => 'Server configuration error: File storage directory invalid.']);
    exit;
}

// Define standard log types/actions
define('LOG_ACTION_TYPE_VIEW_ABSTRACT', 'VIEW_ABSTRACT');
define('LOG_TYPE_ABSTRACT', 'ABSTRACT');

// Assuming config.php provides the $conn PDO object
include '../api-general/config.php'; // Ensure this path is correct

$response = [];

// --- Authentication Check ---
if (!isset($_SESSION['user_type']) || !in_array($_SESSION['user_type'], ['Admin', 'User']) || !isset($_SESSION['account_id'])) {
    http_response_code(403);
    echo json_encode(['error' => 'Access denied. You must be logged in to view abstracts.']);
    exit;
}
$actor_account_id = $_SESSION['account_id'];

// --- Input Validation ---
$abstract_id_input = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ["options" => ["min_range" => 1]]);

if ($abstract_id_input === false || $abstract_id_input === null) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request: Abstract ID missing or invalid.']);
    exit;
}
$abstract_id = $abstract_id_input;

try {
    // --- Fetch File Location, Size, and Abstract Title ---
    $stmt_file = $conn->prepare("SELECT fd.file_location, fd.file_size, a.title
                                 FROM FILE_DETAIL fd
                                 JOIN ABSTRACT a ON fd.abstract_id = a.abstract_id
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
    // 1. Security: Ensure the path is within the designated upload directory
    $real_file_location = realpath($file_location);
    // Check if realpath resolved correctly and if it starts with the base upload directory path
    if ($real_file_location === false || strpos($real_file_location, $baseUploadDir) !== 0) {
         error_log("Security Alert: Attempted access outside designated upload directory or invalid path. DB Path: {$file_location}, Real Path Attempt: {$real_file_location}, Base Dir: {$baseUploadDir}, Abstract ID: {$abstract_id}, User: {$actor_account_id}");
         http_response_code(403); // Forbidden
         echo json_encode(['error' => 'Access denied to the requested file resource. Invalid path.']);
         exit;
    }

    // 2. Existence Check
    if (!file_exists($real_file_location)) {
        error_log("File not found on disk at location: {$real_file_location} (DB Path: {$file_location}) for Abstract ID: {$abstract_id}");
        http_response_code(404);
        echo json_encode(['error' => 'Abstract file record exists, but the file is missing from storage.']);
        exit;
    }

    // 3. Readability Check
    if (!is_readable($real_file_location)) {
        error_log("File not readable at location: {$real_file_location} for Abstract ID: {$abstract_id}");
        http_response_code(500); // Server error (permissions likely)
        echo json_encode(['error' => 'Server error: Unable to read the abstract file due to permissions.']);
        exit;
    }

    // --- Logging (Perform *before* sending file headers) ---
    try {
        $conn->beginTransaction();

        // 1. Insert into LOG table
        $sql_log = "INSERT INTO LOG (actor_account_id, action_type, log_type) VALUES (:actor_id, :action_type, :log_type)";
        $stmt_log = $conn->prepare($sql_log);
        $stmt_log->bindParam(':actor_id', $actor_account_id, PDO::PARAM_INT);
        $stmt_log->bindValue(':action_type', LOG_ACTION_TYPE_VIEW_ABSTRACT, PDO::PARAM_STR);
        $stmt_log->bindValue(':log_type', LOG_TYPE_ABSTRACT, PDO::PARAM_STR);
        $stmt_log->execute();
        $log_id = $conn->lastInsertId();

        // 2. Insert into LOG_ABSTRACT detail table
        $sql_log_detail = "INSERT INTO LOG_ABSTRACT (log_id, abstract_id, account_id) VALUES (:log_id, :abstract_id, :account_id)";
        $stmt_log_detail = $conn->prepare($sql_log_detail);
        $stmt_log_detail->bindParam(':log_id', $log_id, PDO::PARAM_INT);
        $stmt_log_detail->bindParam(':abstract_id', $abstract_id, PDO::PARAM_INT);
        $stmt_log_detail->bindParam(':account_id', $actor_account_id, PDO::PARAM_INT);
        $stmt_log_detail->execute();

        $conn->commit();

    } catch (PDOException $log_e) {
         if ($conn->inTransaction()) {
            $conn->rollBack();
         }
        error_log("Failed to log abstract view (Abstract ID: {$abstract_id}, User ID: {$actor_account_id}): " . $log_e->getMessage());
        // Continue serving the file even if logging fails.
    }

    // --- Send HTTP Headers for File ---
    // Clear any potential previous output
    if (ob_get_level()) {
        ob_end_clean();
    }

    // Set appropriate headers
    header('Content-Type: application/pdf'); // Assuming only PDFs for now
    $safe_filename = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $abstract_title);
    $suggested_filename = 'abstract_' . $abstract_id . '_' . $safe_filename . '.pdf';
    header('Content-Disposition: inline; filename="' . $suggested_filename . '"');
    header('Content-Length: ' . $file_size); // Use size from DB
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('Expires: Sat, 26 Jul 1997 05:00:00 GMT'); // Date in the past
    // header('Accept-Ranges: bytes'); // Optional: Only if server supports range requests efficiently

    // --- Output File Content ---
    // Use readfile() to output the file directly from the verified path
    // It's generally more memory efficient than file_get_contents for large files.
    readfile($real_file_location);

    // --- Stop Execution ---
    exit;

} catch (PDOException $e) {
    // Database error during initial fetch
    http_response_code(500);
    error_log("View Abstract DB Error: " . $e->getMessage() . " | Abstract ID: " . $abstract_id);
    // Ensure JSON is sent if no headers were output yet
    if (!headers_sent()) {
        header('Content-Type: application/json');
    }
    echo json_encode(['error' => 'A database error occurred while retrieving the abstract file details.']);
    exit;

} catch (Throwable $t) { // Catch any other unexpected errors
     http_response_code(500);
     error_log("General Error Viewing Abstract: " . $t->getMessage() . " | Abstract ID: " . $abstract_id);
     if (!headers_sent()) {
        header('Content-Type: application/json');
     }
     echo json_encode(['error' => 'An unexpected server error occurred.']);
     exit;
}

// Fallback error (should not be reached)
http_response_code(500);
if (!headers_sent()) {
    header('Content-Type: application/json');
}
echo json_encode(['error' => 'An unexpected script termination occurred.']);
?>