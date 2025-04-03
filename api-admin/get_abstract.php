<?php
// api-admin/get_abstract.php

session_start();
header('Content-Type: application/json'); // Set header for JSON response

// --- Security Check: Ensure user is Admin ---
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Admin') {
    http_response_code(403); // Forbidden
    echo json_encode(['error' => 'Access denied. Admin privileges required.']);
    exit;
}
// -------------------------------------------

// --- Include Database Configuration ---
// Adjust the path as needed based on your file structure
require '../api-general/config.php';
// ------------------------------------

// --- Input Validation ---
if (!isset($_GET['abstract_id']) || !filter_var($_GET['abstract_id'], FILTER_VALIDATE_INT)) {
    http_response_code(400); // Bad Request
    echo json_encode(['error' => 'Invalid or missing abstract_id parameter.']);
    exit;
}
$abstract_id = (int)$_GET['abstract_id'];
// ------------------------

try {
    // --- Prepare SQL Query ---
    // We LEFT JOIN all potential related tables (subtypes and file)
    // to get all possible information in one go.
    $sql = "SELECT
                a.abstract_id,
                a.title,
                a.description,
                a.researchers,
                a.citation,
                a.abstract_type,
                ta.program_id,        -- Fetched if it's a Thesis
                da.department_id,     -- Fetched if it's a Dissertation
                fd.file_location,     -- Fetched if a file exists
                fd.file_size          -- Also fetch file size if needed later
                -- p.program_name,    -- Optional: Fetch program name directly (add JOIN below)
                -- dpt.department_name -- Optional: Fetch department name directly (add JOIN below)
            FROM ABSTRACT a
            LEFT JOIN THESIS_ABSTRACT ta ON a.abstract_id = ta.abstract_id
            LEFT JOIN DISSERTATION_ABSTRACT da ON a.abstract_id = da.abstract_id
            LEFT JOIN FILE_DETAIL fd ON a.abstract_id = fd.abstract_id -- Assuming one file detail per abstract for edit context
            -- Optional JOINs if you want names directly (might slightly slow down query)
            -- LEFT JOIN PROGRAM p ON ta.program_id = p.program_id
            -- LEFT JOIN DEPARTMENT dpt ON da.department_id = dpt.department_id
            WHERE a.abstract_id = :abstract_id
            LIMIT 1"; // Ensure only one row even if DB inconsistencies exist (e.g., multiple files)

    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':abstract_id', $abstract_id, PDO::PARAM_INT);
    $stmt->execute();

    // --- Fetch the Result ---
    $abstract = $stmt->fetch(PDO::FETCH_ASSOC);

    // --- Check if Abstract Found ---
    if (!$abstract) {
        http_response_code(404); // Not Found
        echo json_encode(['error' => 'Abstract not found.']);
        exit;
    }

    // --- Process Result (e.g., get filename from path) ---
    // The JS expects 'file_name'. Let's extract it from 'file_location'.
    $abstract['file_name'] = null;
    if (!empty($abstract['file_location'])) {
        $abstract['file_name'] = basename($abstract['file_location']);
    }
    // Optionally remove the full path if the frontend doesn't need it
    // unset($abstract['file_location']);


    // --- Output the JSON Result ---
    echo json_encode($abstract);

} catch (PDOException $e) {
    // --- Handle Database Errors ---
    error_log("Database Error in get_abstract.php: " . $e->getMessage()); // Log error for server admin
    http_response_code(500); // Internal Server Error
    echo json_encode(['error' => 'An internal database error occurred. Please try again later.']);

} catch (Exception $e) {
    // --- Handle Other Unexpected Errors ---
    error_log("General Error in get_abstract.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'An unexpected error occurred.']);

} finally {
    // --- Close Connection (optional but good practice) ---
    $stmt = null;
    $conn = null;
}

?>