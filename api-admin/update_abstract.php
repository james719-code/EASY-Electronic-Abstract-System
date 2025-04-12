<?php
session_start();

// Set content type to JSON
header('Content-Type: application/json');

require '../api-general/config.php';
require '../api-general/functions.php'; 

$response = []; // Initialize response array

// --- Define Upload Directory (MUST be writable by the web server) ---
define('UPLOAD_DIR', __DIR__ . '/../pdf/');
if (!is_dir(UPLOAD_DIR)) {
    // Attempt to create directory recursively with appropriate permissions
    if (!mkdir(UPLOAD_DIR, 0775, true)) { // Adjust permissions (e.g., 0755) as per your server setup
         http_response_code(500);
         $response['error'] = 'Configuration error: Failed to create upload directory.';
         error_log('CRITICAL: Failed to create upload directory: '.UPLOAD_DIR);
         echo json_encode($response);
         exit;
    }
}
if (!is_writable(UPLOAD_DIR)) {
    http_response_code(500);
    $response['error'] = 'Configuration error: Upload directory is not writable.';
    error_log('CRITICAL: Upload directory not writable: '.UPLOAD_DIR);
    echo json_encode($response);
    exit;
}


// --- Authentication and Authorization ---
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Admin' || !isset($_SESSION['account_id'])) {
    http_response_code(403); // Forbidden
    $response['error'] = "Access denied. Admin privileges required.";
    echo json_encode($response);
    exit;
}
$admin_actor_id = (int)$_SESSION['account_id']; // Get the admin performing the action

// --- Method Check ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    $response['error'] = 'Invalid request method.';
    echo json_encode($response);
    exit;
}

// --- Input Retrieval and Basic Sanitization/Validation ---
$abstract_id = filter_input(INPUT_POST, 'abstract_id', FILTER_VALIDATE_INT);
$title = trim(filter_input(INPUT_POST, 'title', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
$researchers = trim(filter_input(INPUT_POST, 'researchers', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
$citation = trim(filter_input(INPUT_POST, 'citation', FILTER_SANITIZE_SPECIAL_CHARS) ?? '');
$description = trim(filter_input(INPUT_POST, 'description', FILTER_SANITIZE_SPECIAL_CHARS) ?? ''); 

// Validate abstract_type explicitly
$new_abstract_type_input = filter_input(INPUT_POST, 'abstract_type', FILTER_DEFAULT); // Get raw value
$new_abstract_type = null;
if (in_array($new_abstract_type_input, ['Thesis', 'Dissertation'])) {
    $new_abstract_type = $new_abstract_type_input;
}

// Validate IDs
$program_id_input = filter_input(INPUT_POST, 'program_id', FILTER_VALIDATE_INT);
$department_id_input = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);

// Assign based on type AFTER validation
$program_id = ($new_abstract_type === 'Thesis' && $program_id_input) ? $program_id_input : null;
$department_id = ($new_abstract_type === 'Dissertation' && $department_id_input) ? $department_id_input : null;


// --- Comprehensive Validation ---
if ($abstract_id === false || $abstract_id <= 0) {
    http_response_code(400); $response['error'] = 'Valid Abstract ID is required.'; echo json_encode($response); exit;
}
if (empty($title) || empty($researchers) || empty($citation) || empty($description)) {
    http_response_code(400); $response['error'] = 'Title, Researchers, Citation, and Description are required.'; echo json_encode($response); exit;
}
if ($new_abstract_type === null) { // Check if type was valid after filtering/checking
    http_response_code(400); $response['error'] = 'Invalid Abstract Type selected.'; echo json_encode($response); exit;
}
// Check required subtype ID based on type
if ($new_abstract_type === 'Thesis' && ($program_id === null || $program_id <= 0)) {
    http_response_code(400); $response['error'] = 'A valid Program must be selected for a Thesis.'; echo json_encode($response); exit;
}
if ($new_abstract_type === 'Dissertation' && ($department_id === null || $department_id <= 0)) {
    http_response_code(400); $response['error'] = 'A valid Department must be selected for a Dissertation.'; echo json_encode($response); exit;
}

// --- File Handling Preparations ---
$new_file_location = null; // Path where the new file is saved
$new_file_size = null;     // Size of the new file
$new_file_uploaded = false; // Flag indicating if a new file was processed
$old_file_path = null;     // To store the path of the file being replaced (for deletion after commit)

// --- Process File Upload (if present) ---
if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
    $new_file_uploaded = true; // Mark that we are processing an upload

    if (!is_uploaded_file($_FILES['file']['tmp_name'])) {
        http_response_code(400); $response['error'] = 'Invalid file upload mechanism.'; echo json_encode($response); exit;
    }

    // MIME type check (more reliable than extension)
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime_type = $finfo->file($_FILES['file']['tmp_name']);
    if ($mime_type !== 'application/pdf') {
        http_response_code(400); $response['error'] = 'Invalid file type. Only PDF files are allowed.'; echo json_encode($response); exit;
    }

    // Check file size (e.g., max 10MB)
    $max_file_size = 10 * 1024 * 1024; // 10 MB
    if ($_FILES['file']['size'] > $max_file_size) {
        http_response_code(400); $response['error'] = 'File size exceeds the limit (' . ($max_file_size / 1024 / 1024) . 'MB).'; echo json_encode($response); exit;
    }
    if ($_FILES['file']['size'] === 0) {
        http_response_code(400); $response['error'] = 'Uploaded file is empty.'; echo json_encode($response); exit;
    }

    // Generate unique filename to prevent collisions and handle special characters
    $original_filename = basename($_FILES['file']['name']);
    $file_extension = pathinfo($original_filename, PATHINFO_EXTENSION);
    // Basic sanitization for filename base (replace non-alphanumeric/hyphen/underscore)
    $safe_filename_base = preg_replace('/[^\pL\pN_-]/u', '_', pathinfo($original_filename, PATHINFO_FILENAME));
    $unique_filename = uniqid($safe_filename_base . '_', true) . '.' . strtolower($file_extension);
    $target_path = UPLOAD_DIR . $unique_filename;

    // Move the uploaded file to the final destination
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $target_path)) {
        http_response_code(500);
        $response['error'] = 'Failed to save uploaded file.';
        error_log("Failed move_uploaded_file from {$_FILES['file']['tmp_name']} to: " . $target_path);
        echo json_encode($response);
        exit;
    }

    // Store the path and size for DB insertion
    $new_file_location = $target_path; // Store the full path, or relative path/URL depending on how you retrieve it
    $new_file_size = $_FILES['file']['size'];

} elseif (isset($_FILES['file']) && $_FILES['file']['error'] !== UPLOAD_ERR_NO_FILE) {
    // Handle other specific upload errors
    http_response_code(400);
    $response['error'] = 'File upload error code: ' . $_FILES['file']['error'];
    error_log("File upload error for abstract ID {$abstract_id}: Code {$_FILES['file']['error']}");
    echo json_encode($response);
    exit;
}
// Note: No error if no file was uploaded (UPLOAD_ERR_NO_FILE)


// --- Database Operations ---
try {
    $conn->beginTransaction();

    // Fetch Current Abstract State (Type, Subtype ID, and potentially old file path)
    $sql_get_current = "SELECT
                           a.abstract_type,
                           ta.program_id AS current_program_id,
                           da.department_id AS current_department_id,
                           fd.file_location AS current_file_location
                       FROM abstract a
                       LEFT JOIN thesis_abstract ta ON a.abstract_id = ta.thesis_id
                       LEFT JOIN dissertation_abstract da ON a.abstract_id = da.dissertation_id
                       LEFT JOIN file_detail fd ON a.abstract_id = fd.abstract_id
                       WHERE a.abstract_id = :abstract_id";
    $stmt_get = $conn->prepare($sql_get_current);
    $stmt_get->bindParam(':abstract_id', $abstract_id, PDO::PARAM_INT);
    $stmt_get->execute();
    $current_state = $stmt_get->fetch(PDO::FETCH_ASSOC);
    $stmt_get->closeCursor();

    if (!$current_state) {
        // Rollback not needed as nothing written yet
        http_response_code(404); $response['error'] = 'Abstract not found.'; echo json_encode($response); exit;
    }
    $old_abstract_type = $current_state['abstract_type'];
    $old_program_id = $current_state['current_program_id'];
    $old_department_id = $current_state['current_department_id'];

    // Store the old file path *only if* a new file was successfully uploaded
    if ($new_file_uploaded && !empty($current_state['current_file_location'])) {
        $old_file_path = $current_state['current_file_location'];
    }


    // Update the main ABSTRACT table
    $sql_update_abstract = "UPDATE abstract SET
                                title = :title,
                                description = :description,
                                researchers = :researchers,
                                citation = :citation,
                                abstract_type = :abstract_type
                            WHERE abstract_id = :abstract_id";
    $stmt_update = $conn->prepare($sql_update_abstract);
    $stmt_update->bindParam(':title', $title, PDO::PARAM_STR);
    $stmt_update->bindParam(':description', $description, PDO::PARAM_STR);
    $stmt_update->bindParam(':researchers', $researchers, PDO::PARAM_STR);
    $stmt_update->bindParam(':citation', $citation, PDO::PARAM_STR);
    $stmt_update->bindParam(':abstract_type', $new_abstract_type, PDO::PARAM_STR);
    $stmt_update->bindParam(':abstract_id', $abstract_id, PDO::PARAM_INT);

    if (!$stmt_update->execute()) {
        throw new PDOException("Failed to update abstract core details.");
    }


    // Handle Subtype Changes (Delete/Insert or Update)
    if ($new_abstract_type != $old_abstract_type) {

        // Delete from old subtype table
        $old_subtype_table = ($old_abstract_type === 'Thesis') ? 'thesis_abstract' : (($old_abstract_type === 'Dissertation') ? 'dissertation_abstract' : null);
        $old_type = ($old_abstract_type === 'Thesis') ? 'thesis_id' : (($old_abstract_type === 'Dissertation') ? 'dissertation_id' : null);
        if ($old_subtype_table) {
            $stmt_del_old = $conn->prepare("DELETE FROM {$old_subtype_table} WHERE {$old_type} = :abstract_id");
            $stmt_del_old->bindParam(':abstract_id', $abstract_id, PDO::PARAM_INT);
            if (!$stmt_del_old->execute()) {
                throw new PDOException("Failed to remove old subtype record from {$old_subtype_table}.");
            }
        }

        // Insert into new subtype table
        if ($new_abstract_type === 'Thesis') {
            $stmt_ins_new = $conn->prepare("INSERT INTO thesis_abstract (thesis_id, program_id) VALUES (:abstract_id, :program_id)");
            $stmt_ins_new->bindParam(':abstract_id', $abstract_id, PDO::PARAM_INT);
            $stmt_ins_new->bindParam(':program_id', $program_id, PDO::PARAM_INT); // Use validated $program_id
            if (!$stmt_ins_new->execute()) {
                 // Check for FK violation explicitly
                 if ($stmt_ins_new->errorCode() == '23000') throw new PDOException("Failed to insert new thesis subtype: Program ID {$program_id} likely does not exist.", 23000);
                 throw new PDOException("Failed to insert new thesis subtype record. Error: " . $stmt_ins_new->errorInfo()[2]);
            }
        } elseif ($new_abstract_type === 'Dissertation') {
            $stmt_ins_new = $conn->prepare("INSERT INTO dissertation_abstract (dissertation_id, department_id) VALUES (:abstract_id, :department_id)");
            $stmt_ins_new->bindParam(':abstract_id', $abstract_id, PDO::PARAM_INT);
            $stmt_ins_new->bindParam(':department_id', $department_id, PDO::PARAM_INT); // Use validated $department_id
            if (!$stmt_ins_new->execute()) {
                 if ($stmt_ins_new->errorCode() == '23000') throw new PDOException("Failed to insert new dissertation subtype: Department ID {$department_id} likely does not exist.", 23000);
                 throw new PDOException("Failed to insert new dissertation subtype record. Error: " . $stmt_ins_new->errorInfo()[2]);
            }
        }

    } elseif ($new_abstract_type === 'Thesis' && $program_id != $old_program_id) {
        // Type is Thesis and hasn't changed, but Program ID has changed: Update
        $stmt_upd_subtype = $conn->prepare("UPDATE thesis_abstract SET program_id = :program_id WHERE thesis_id = :abstract_id");
        $stmt_upd_subtype->bindParam(':program_id', $program_id, PDO::PARAM_INT);
        $stmt_upd_subtype->bindParam(':abstract_id', $abstract_id, PDO::PARAM_INT);
        if (!$stmt_upd_subtype->execute()) {
             if ($stmt_upd_subtype->errorCode() == '23000') throw new PDOException("Failed to update thesis subtype: Program ID {$program_id} likely does not exist.", 23000);
             throw new PDOException("Failed to update thesis program ID. Error: " . $stmt_upd_subtype->errorInfo()[2]);
        }
    } elseif ($new_abstract_type === 'Dissertation' && $department_id != $old_department_id) {
        // Type is Dissertation and hasn't changed, but Department ID has changed: Update
         $stmt_upd_subtype = $conn->prepare("UPDATE dissertation_abstract SET department_id = :department_id WHERE dissertation_id = :abstract_id");
         $stmt_upd_subtype->bindParam(':department_id', $department_id, PDO::PARAM_INT);
         $stmt_upd_subtype->bindParam(':abstract_id', $abstract_id, PDO::PARAM_INT);
        if (!$stmt_upd_subtype->execute()) {
            if ($stmt_upd_subtype->errorCode() == '23000') throw new PDOException("Failed to update dissertation subtype: Department ID {$department_id} likely does not exist.", 23000);
            throw new PDOException("Failed to update dissertation department ID. Error: " . $stmt_upd_subtype->errorInfo()[2]);
        }
    }


    // Handle File Detail Update in DB (if a new file was uploaded)
    if ($new_file_uploaded) {
        // Delete existing file record(s) from DB first
        $stmt_del_file_db = $conn->prepare("DELETE FROM file_detail WHERE abstract_id = :abstract_id");
        $stmt_del_file_db->bindParam(':abstract_id', $abstract_id, PDO::PARAM_INT);
        $stmt_del_file_db->execute();

        // Insert the new file record into DB
        $sql_ins_file = "INSERT INTO file_detail (file_location, file_size, abstract_id) VALUES (:location, :size, :abstract_id)";
        $stmt_ins_file_db = $conn->prepare($sql_ins_file);
        $stmt_ins_file_db->bindParam(':location', $new_file_location, PDO::PARAM_STR);
        $stmt_ins_file_db->bindParam(':size', $new_file_size, PDO::PARAM_INT);
        $stmt_ins_file_db->bindParam(':abstract_id', $abstract_id, PDO::PARAM_INT);
        if (!$stmt_ins_file_db->execute()) {
            throw new PDOException("Failed to insert new file DB record.");
        }
    }


    // Log the Update Action 
    $logged = false; 
    if (function_exists('log_action')) { 
        $logged = log_action(
            $conn,                  // PDO connection object
            $admin_actor_id,        // Actor ID (admin performing the action)
            'UPDATE_ABSTRACT',      // Specific action type identifier
            'ABSTRACT',             // Log type category
            $abstract_id            // Target entity ID (the abstract being updated)
            // target_account_id is null for this log type and function handles default
        );
        if (!$logged) {
            error_log("CRITICAL: log_action() function returned false for abstract update ID: {$abstract_id} by admin ID: {$admin_actor_id}. Check log_action implementation and logs.");
        }
    } else {
        error_log("CRITICAL: log_action() function not found or not included. Cannot log abstract update for ID: {$abstract_id}.");
    }


    // Commit Transaction
    $conn->commit();

    // Delete Old Physical File (AFTER successful commit)
    if ($new_file_uploaded && $old_file_path) {
        // Check if the file exists before attempting deletion
        if (file_exists($old_file_path)) {
            if (!unlink($old_file_path)) {
                 // Log failure to delete old file, but don't fail the overall operation now
                 error_log("Warning: Failed to delete old physical file after successful update: " . $old_file_path);
            } else {
                 error_log("Info: Successfully deleted old physical file: " . $old_file_path);
            }
        } else {
             error_log("Info: Old physical file path recorded but file not found for deletion: " . $old_file_path);
        }
    }

    $response['success'] = 'Abstract updated successfully.';
    echo json_encode($response);


} catch (PDOException $e) {
    // --- Handle Database Errors ---
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    // Cleanup potentially uploaded file if transaction failed after move
    if ($new_file_uploaded && $new_file_location && file_exists($new_file_location)) {
        unlink($new_file_location); // Attempt to delete orphaned file
        error_log("Info: Rolled back transaction, deleted orphaned uploaded file: " . $new_file_location);
    }

    http_response_code(500); // Internal Server Error (default for DB issues)
    error_log("Database Error during abstract update (ID: {$abstract_id}): " . $e->getMessage() . " | SQL State: " . $e->getCode());

    // Provide more specific user feedback for constraint violations if possible
    if ($e->getCode() == '23000' || str_contains($e->getMessage(), 'constraint violation')) {
         http_response_code(400); // Bad request because input violates constraints
         $response['error'] = 'Database constraint violation. Ensure the selected Program or Department exists and is valid.';
    } else {
        $response['error'] = 'An internal database error occurred while updating the abstract. Please try again later.';
    }
    echo json_encode($response);

} catch (Exception $e) {
    // --- Handle Other General Errors ---
     if ($conn->inTransaction()) {
        $conn->rollBack();
    }
     // Cleanup potentially uploaded file if transaction failed after move
    if ($new_file_uploaded && $new_file_location && file_exists($new_file_location)) {
        unlink($new_file_location); // Attempt to delete orphaned file
        error_log("Info: Rolled back transaction due to general error, deleted orphaned uploaded file: " . $new_file_location);
    }

    http_response_code(500); // Default for unexpected errors
    error_log("General Error during abstract update (ID: {$abstract_id}): " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
    $response['error'] = 'An unexpected error occurred: ' . $e->getMessage(); // Avoid leaking too much detail in production
    echo json_encode($response);

} finally {
    // Close cursor and connection (optional, PDO usually handles this, but explicit can be good)
    if (isset($stmt_update)) $stmt_update = null;
    if (isset($stmt_del_old)) $stmt_del_old = null;
    if (isset($stmt_ins_new)) $stmt_ins_new = null;
    if (isset($stmt_upd_subtype)) $stmt_upd_subtype = null;
    if (isset($stmt_del_file_db)) $stmt_del_file_db = null;
    if (isset($stmt_ins_file_db)) $stmt_ins_file_db = null;
    $conn = null;
}

?>
