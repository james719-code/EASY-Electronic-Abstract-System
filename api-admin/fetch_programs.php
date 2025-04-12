<?php
session_start();
header('Content-Type: application/json');
include '../api-general/config.php';

$response = []; 
$queryParams = []; 

// --- Authentication/Authorization Check (Using 'role' as established) ---
if (!isset($_SESSION['account_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Admin') {
    http_response_code(403); // Use 403 Forbidden as per original script
    $response['error'] = "Access denied. Admin privileges required.";
    echo json_encode($response);
    exit;
}

try {
    // --- Input Retrieval ---
    $search = trim($_GET['search'] ?? '');

    // Use filter_input for integer validation
    $department_id_input = filter_input(INPUT_GET, 'department_id', FILTER_VALIDATE_INT);
    // Ensure it's a valid positive integer, otherwise null
    $department_id = ($department_id_input !== false && $department_id_input !== null && $department_id_input > 0) ? $department_id_input : null;


    // --- Build SQL Query with Positional Placeholders (?) ---
    $sql = "SELECT
                p.program_id,
                p.program_name,
                p.program_initials,
                p.department_id,
                d.department_name
            FROM
                program p
            LEFT JOIN
                department d ON p.department_id = d.department_id
            WHERE 1=1"; // Start WHERE clause, always true

    // Conditionally add search criteria
    if (!empty($search)) {
        // Append clause with positional placeholders
        $sql .= " AND (p.program_name LIKE ? OR p.program_initials LIKE ?)";
        // Prepare the search term ONCE
        $searchTerm = '%' . $search . '%';
        $queryParams[] = $searchTerm;
        $queryParams[] = $searchTerm;
    }

    // Conditionally add department filter
    if ($department_id !== null) {
        // Append clause with a positional placeholder
        $sql .= " AND p.department_id = ?";
        // Add the department ID to the array
        $queryParams[] = $department_id;
    }

    // Add ordering
    $sql .= " ORDER BY p.program_name ASC";

    // --- Prepare and Execute ---
    error_log("Preparing SQL (Programs - Positional): " . $sql); // Log the final SQL
    $stmt = $conn->prepare($sql);

    // Execute with the array of query parameters in the correct order
    error_log("Executing (Programs - Positional) with params: " . print_r($queryParams, true));
    $stmt->execute($queryParams); // Pass the indexed array
    error_log("Execution successful (Programs - Positional).");

    // --- Fetch and Output Results ---
    error_log("Fetching results (Programs - Positional)...");
    $programs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Fetch successful (Programs - Positional). Row count: " . count($programs));

    // Send the response - directly outputting the array is common for list endpoints
    echo json_encode($programs);

} catch (PDOException $e) {
    http_response_code(500);
    // Log detailed error information for debugging
    error_log("Fetch Programs Error (Positional Attempt). SQL was: [$sql]. Params: " . print_r($queryParams, true) . ". Error: " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
    // Send a generic error message to the client
    $response['error'] = "Database error fetching programs. Please check server logs.";
    echo json_encode($response);
} catch (Throwable $t) { // Catch other potential errors (e.g., during input processing)
     http_response_code(500);
     error_log("General Error Fetching Programs (Positional Attempt): " . $t->getMessage() . " Trace: " . $t->getTraceAsString());
     $response['error'] = 'An unexpected server error occurred while fetching programs. Please check server logs.';
     echo json_encode($response);
}
?>