<?php
session_start();
header('Content-Type: application/json');

// Define standard log types/actions
define('LOG_ACTION_TYPE_CREATE_ABSTRACT', 'CREATE_ABSTRACT');
define('LOG_TYPE_ABSTRACT', 'ABSTRACT');

// --- Configuration ---
// Define the relative path to the upload directory from this script's location
// Adjust '../pdf/' if your directory structure is different.
$uploadDir = '../pdf/'; // Relative path: one level up, then into 'pdf/'
// Ensure the upload directory exists and is writable
if (!is_dir($uploadDir)) {
    // Attempt to create it (optional, might fail due to permissions)
    if (!mkdir($uploadDir, 0775, true)) { // Use 0775 for permissions, recursive true
         http_response_code(500);
         error_log("Upload directory '$uploadDir' does not exist and could not be created.");
         echo json_encode(['error' => 'Server configuration error: Upload directory missing.']);
         exit;
    }
}
if (!is_writable($uploadDir)) {
    http_response_code(500);
    error_log("Upload directory '$uploadDir' is not writable by the web server.");
    echo json_encode(['error' => 'Server configuration error: Upload directory not writable.']);
    exit;
}


// Assuming config.php provides the $conn PDO object
// Make sure the path is correct relative to *this* script file
include '../api-general/config.php'; // Check this path

$response = [];

// --- Authentication Check ---
if (!isset($_SESSION['role']) || !in_array($_SESSION['role'], ['admin', 'user']) || !isset($_SESSION['account_id'])) {
    http_response_code(403); // Forbidden
    echo json_encode(['error' => 'Access denied. You must be logged in to submit an abstract.']);
    exit;
}
$actor_account_id = $_SESSION['account_id'];

// --- Method Check ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['error' => 'Invalid request method. Only POST is allowed.']);
    exit;
}

// --- Input Retrieval and Validation ---
$errors = [];
$fileInfo = null; // To store file details after successful validation/move

// Use filter_input for basic text fields
$title = trim(filter_input(INPUT_POST, 'title', FILTER_DEFAULT) ?? '');
$description = trim(filter_input(INPUT_POST, 'description', FILTER_DEFAULT) ?? '');
$researchers = trim(filter_input(INPUT_POST, 'researchers', FILTER_DEFAULT) ?? '');
$citation = trim(filter_input(INPUT_POST, 'citation', FILTER_DEFAULT) ?? '');
$abstract_type_input = trim(filter_input(INPUT_POST, 'abstract_type', FILTER_DEFAULT) ?? ''); // 'Thesis' or 'Dissertation'

// --- File Validation and Handling ---
if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
    $errors[] = "No file was uploaded or the upload failed.";
} elseif ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    // Handle specific upload errors
    $upload_errors = [
        UPLOAD_ERR_INI_SIZE => "The uploaded file exceeds the server's maximum file size limit (upload_max_filesize).",
        UPLOAD_ERR_FORM_SIZE => "The uploaded file exceeds the form's maximum file size limit (MAX_FILE_SIZE).",
        UPLOAD_ERR_PARTIAL => "The uploaded file was only partially uploaded.",
        UPLOAD_ERR_NO_FILE => "No file was uploaded (redundant check, but safe).",
        UPLOAD_ERR_NO_TMP_DIR => "Server configuration error: Missing a temporary folder.",
        UPLOAD_ERR_CANT_WRITE => "Server configuration error: Failed to write file to disk.",
        UPLOAD_ERR_EXTENSION => "A PHP extension stopped the file upload.",
    ];
    $error_code = $_FILES['file']['error'];
    $errors[] = "File upload error: " . ($upload_errors[$error_code] ?? "Unknown error code: $error_code.");
} else {
    // Basic checks passed, now check size and type, then move
    $fileSize = $_FILES['file']['size'];
    $fileName = basename($_FILES['file']['name']); // Original filename (for extension)
    $fileTmpPath = $_FILES['file']['tmp_name'];
    $fileMimeType = mime_content_type($fileTmpPath); // More reliable type check

    if ($fileSize == 0) {
        $errors[] = "Uploaded file is empty.";
    } elseif ($fileSize > 20 * 1024 * 1024) { // Example: Limit to 20 MB
        $errors[] = "File is too large. Maximum size allowed is 20 MB.";
    } elseif ($fileMimeType !== 'application/pdf') {
        $errors[] = "Invalid file type. Only PDF files are allowed.";
    } else {
        // File seems valid, generate a unique name and move it
        $fileExtension = pathinfo($fileName, PATHINFO_EXTENSION);
        // Create a more unique filename to prevent overwrites and issues
        $newFilename = uniqid('abstract_', true) . '.' . strtolower($fileExtension);
        $targetFilePath = $uploadDir . $newFilename;

        // Attempt to move the uploaded file
        if (move_uploaded_file($fileTmpPath, $targetFilePath)) {
            // File moved successfully! Store details for DB insertion.
            $fileInfo = [
                'location' => $targetFilePath, // Store the path where it was saved
                'size' => $fileSize
            ];
            // Important: Consider what path to store. $targetFilePath is the absolute/relative
            // path *on the server*. You might want to store a path relative to your web root
            // or a base URL for easier linking later. For now, we store the path used for saving.
            // Example: If web root is /var/www/html and script saves to /var/www/pdf/file.pdf,
            // you might want to store '/pdf/file.pdf' instead of $targetFilePath.
            // Let's adjust to store a path relative to the upload dir base:
            // $fileInfo['location'] = 'pdf/' . $newFilename; // Assuming 'pdf/' is accessible via web
            // For this example, we stick with $targetFilePath but be aware of this.

        } else {
            $errors[] = "Failed to move uploaded file. Check server permissions for '$uploadDir'.";
            error_log("Failed to move uploaded file from $fileTmpPath to $targetFilePath");
        }
    }
}

// Validate required text fields
if (empty($title)) $errors[] = "Title is required.";
if (empty($description)) $errors[] = "Description is required.";
if (empty($researchers)) $errors[] = "Researchers field is required.";
if (empty($citation)) $errors[] = "Citation is required.";
if (empty($abstract_type_input)) {
    $errors[] = "Abstract Type ('Thesis' or 'Dissertation') is required.";
} else {
    $abstract_type = ucfirst(strtolower($abstract_type_input));
    if ($abstract_type !== 'Thesis' && $abstract_type !== 'Dissertation') {
        $errors[] = "Invalid Abstract Type. Must be 'Thesis' or 'Dissertation'.";
    }
}

// Validate subtype-specific fields
$program_id = null;
$department_id = null;
if (isset($abstract_type)) {
    if ($abstract_type === 'Thesis') {
        $program_id_input = filter_input(INPUT_POST, 'program_id', FILTER_VALIDATE_INT);
        if ($program_id_input === false || $program_id_input === null || $program_id_input <= 0) {
            $errors[] = "A valid Program ID is required for Thesis abstracts.";
        } else {
            $program_id = $program_id_input;
        }
    } elseif ($abstract_type === 'Dissertation') {
        $department_id_input = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);
        if ($department_id_input === false || $department_id_input === null || $department_id_input <= 0) {
            $errors[] = "A valid Department ID is required for Dissertation abstracts.";
        } else {
            $department_id = $department_id_input;
        }
    }
}

// If validation errors (including file errors), return Bad Request
if (!empty($errors)) {
    http_response_code(400);
    $response['error'] = implode(' ', $errors);
    // If file was moved successfully but other errors occurred, we might want to delete it.
    // For simplicity, we don't add that cleanup here. The file will remain in the pdf/ folder.
    // Consider implementing cleanup if this is undesirable.
    echo json_encode($response);
    exit;
}

// At this point, file should be successfully moved to $fileInfo['location']
// and all other text inputs are validated. Proceed with database operations.

// --- Database Operations ---
try {
    $conn->beginTransaction();

    // 1. Insert into ABSTRACT table
    $sql_abstract = "INSERT INTO ABSTRACT (title, description, researchers, citation, abstract_type) VALUES (:title, :description, :researchers, :citation, :type)";
    $stmt_abstract = $conn->prepare($sql_abstract);
    $stmt_abstract->bindParam(':title', $title, PDO::PARAM_STR);
    $stmt_abstract->bindParam(':description', $description, PDO::PARAM_STR);
    $stmt_abstract->bindParam(':researchers', $researchers, PDO::PARAM_STR);
    $stmt_abstract->bindParam(':citation', $citation, PDO::PARAM_STR);
    $stmt_abstract->bindParam(':type', $abstract_type, PDO::PARAM_STR);

    if (!$stmt_abstract->execute()) {
        throw new PDOException("Failed to insert base abstract data.");
    }
    $new_abstract_id = $conn->lastInsertId();

    // 2. Insert into FILE_DETAIL table (Using stored file path and size)
    $sql_file = "INSERT INTO FILE_DETAIL (file_location, file_size, abstract_id) VALUES (:location, :size, :abstract_id)";
    $stmt_file = $conn->prepare($sql_file);
    // Use the file info captured earlier
    $stmt_file->bindParam(':location', $fileInfo['location'], PDO::PARAM_STR);
    $stmt_file->bindParam(':size', $fileInfo['size'], PDO::PARAM_INT); // Use PDO::PARAM_STR if file_size is BIGINT and > PHP_INT_MAX
    $stmt_file->bindParam(':abstract_id', $new_abstract_id, PDO::PARAM_INT);

    if (!$stmt_file->execute()) {
        throw new PDOException("Failed to insert file details.");
    }

    // 3. Insert into THESIS_ABSTRACT or DISSERTATION_ABSTRACT table
    if ($abstract_type === 'Thesis') {
        $stmt_check_prog = $conn->prepare("SELECT 1 FROM PROGRAM WHERE program_id = :pid");
        $stmt_check_prog->bindParam(':pid', $program_id, PDO::PARAM_INT);
        $stmt_check_prog->execute();
        if ($stmt_check_prog->fetchColumn() === false) {
             throw new Exception("Invalid Program ID provided for Thesis.");
        }

        $sql_thesis = "INSERT INTO THESIS_ABSTRACT (abstract_id, program_id) VALUES (:abstract_id, :program_id)";
        $stmt_thesis = $conn->prepare($sql_thesis);
        $stmt_thesis->bindParam(':abstract_id', $new_abstract_id, PDO::PARAM_INT);
        $stmt_thesis->bindParam(':program_id', $program_id, PDO::PARAM_INT);
        if (!$stmt_thesis->execute()) {
            throw new PDOException("Failed to insert thesis details.");
        }
    } elseif ($abstract_type === 'Dissertation') {
        $stmt_check_dept = $conn->prepare("SELECT 1 FROM DEPARTMENT WHERE department_id = :did");
        $stmt_check_dept->bindParam(':did', $department_id, PDO::PARAM_INT);
        $stmt_check_dept->execute();
        if ($stmt_check_dept->fetchColumn() === false) {
             throw new Exception("Invalid Department ID provided for Dissertation.");
        }

        $sql_diss = "INSERT INTO DISSERTATION_ABSTRACT (abstract_id, department_id) VALUES (:abstract_id, :department_id)";
        $stmt_diss = $conn->prepare($sql_diss);
        $stmt_diss->bindParam(':abstract_id', $new_abstract_id, PDO::PARAM_INT);
        $stmt_diss->bindParam(':department_id', $department_id, PDO::PARAM_INT);
        if (!$stmt_diss->execute()) {
            throw new PDOException("Failed to insert dissertation details.");
        }
    }

    // 4. Insert into LOG table
    $sql_log = "INSERT INTO LOG (actor_account_id, action_type, log_type) VALUES (:actor_id, :action_type, :log_type)";
    $stmt_log = $conn->prepare($sql_log);
    $stmt_log->bindParam(':actor_id', $actor_account_id, PDO::PARAM_INT);
    $stmt_log->bindValue(':action_type', LOG_ACTION_TYPE_CREATE_ABSTRACT, PDO::PARAM_STR);
    $stmt_log->bindValue(':log_type', LOG_TYPE_ABSTRACT, PDO::PARAM_STR);

    if (!$stmt_log->execute()) {
        throw new PDOException("Failed to insert base log entry.");
    }
    $log_id = $conn->lastInsertId();

    // 5. Insert into LOG_ABSTRACT detail table
    $sql_log_detail = "INSERT INTO LOG_ABSTRACT (log_id, abstract_id, account_id) VALUES (:log_id, :abstract_id, :account_id)";
    $stmt_log_detail = $conn->prepare($sql_log_detail);
    $stmt_log_detail->bindParam(':log_id', $log_id, PDO::PARAM_INT);
    $stmt_log_detail->bindParam(':abstract_id', $new_abstract_id, PDO::PARAM_INT);
    $stmt_log_detail->bindParam(':account_id', $actor_account_id, PDO::PARAM_INT);

    if (!$stmt_log_detail->execute()) {
        throw new PDOException("Failed to insert abstract log details into LOG_ABSTRACT table.");
    }

    // 6. Commit Transaction
    $conn->commit();
    http_response_code(201); // Created
    $response['success'] = "Abstract and file uploaded successfully.";
    $response['abstract_id'] = $new_abstract_id;
    $response['file_location'] = $fileInfo['location']; // Optionally return the saved file path
    echo json_encode($response);

} catch (PDOException $e) {
    if ($conn->inTransaction()) $conn->rollBack();
    // Clean up the uploaded file if the transaction failed
    if (isset($fileInfo['location']) && file_exists($fileInfo['location'])) {
        unlink($fileInfo['location']);
        error_log("Rolled back transaction and deleted uploaded file: " . $fileInfo['location']);
    }
    http_response_code(500);
    error_log("Database Error in Add Abstract: " . $e->getMessage() . " | Actor ID: " . $actor_account_id);

    if ($e->getCode() == 23000) {
        $response['error'] = "Database error: A data constraint was violated. Ensure Program/Department exists.";
    } else {
        $response['error'] = "A database error occurred while adding the abstract.";
    }
    echo json_encode($response);

} catch (Exception $e) { // Catch manual exceptions (like invalid program/dept ID or file move failure caught earlier)
    if ($conn->inTransaction()) $conn->rollBack();
     // Clean up the uploaded file if the transaction failed (or never started but file was moved)
    if (isset($fileInfo['location']) && file_exists($fileInfo['location'])) {
        unlink($fileInfo['location']);
        error_log("Caught Exception, rolled back transaction (if any) and deleted uploaded file: " . $fileInfo['location']);
    }
    http_response_code(400); // Bad Request for validation exceptions
    error_log("Validation Error in Add Abstract: " . $e->getMessage() . " | Actor ID: " . $actor_account_id);
    $response['error'] = $e->getMessage();
    echo json_encode($response);

} finally {
    // Close connection if necessary (often handled by PDO automatically)
    // $conn = null;
}
?>