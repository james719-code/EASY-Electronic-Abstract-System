<?php
// api-admin/fetch_departments.php

// Enable error reporting for debugging (remove or adjust in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start(); // Start session for auth check
header('Content-Type: application/json');

// Assuming config.php establishes the PDO connection $conn
// Use require for critical includes
require '../api-general/config.php'; // Adjust path if needed

// Response array
$response = [];

// --- Authentication/Authorization Check (Using 'role' as established) ---
if (!isset($_SESSION['account_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Admin') {
    http_response_code(403); // Use 403 Forbidden as per original script
    $response['error'] = "Access denied. Admin privileges required.";
    echo json_encode($response);
    exit;
}

// --- Input Handling ---
$searchTerm = trim($_GET['search'] ?? '');
// No other filters needed for departments based on original script

// --- Build SQL Query with Positional Placeholders (?) ---
$sql = "SELECT
            department_id,
            department_name,
            department_initials
        FROM
            DEPARTMENT -- Correct table name
        WHERE 1=1"; // Start with 1=1 for easy AND appending

// Array to hold parameter values for positional placeholders IN ORDER
$queryParams = [];

// Apply search filter using positional placeholders
if (!empty($searchTerm)) {
    // Append clause with positional placeholders '?'
    $sql .= " AND (department_name LIKE ? OR department_initials LIKE ?)";
    // Prepare the search term value
    $searchParamValue = '%' . $searchTerm . '%';
    // Add the value TWICE to the array, matching the two '?'
    $queryParams[] = $searchParamValue;
    $queryParams[] = $searchParamValue;
}

// --- Sorting (Appended directly, no parameters needed) ---
$sql .= " ORDER BY department_name ASC";

// --- Execute Query using Positional Parameters ---
try {
    // Log the final SQL and parameters before preparing
    error_log("Preparing Department SQL (Positional): " . $sql);
    $stmt = $conn->prepare($sql);

    // Execute by passing the array of ordered parameter values
    error_log("Executing Department SQL (Positional) with params: " . print_r($queryParams, true));
    $stmt->execute($queryParams); // Pass the indexed array directly
    error_log("Execution successful (Positional).");

    error_log("Fetching department results (Positional)...");
    $departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Fetch successful (Positional). Row count: " . count($departments));

    // Return the results as JSON
    http_response_code(200); // OK
    echo json_encode($departments);

} catch (PDOException $e) {
    // Handle database errors gracefully
    http_response_code(500); // Internal Server Error
    // Log detailed error for admins
    error_log("Database Query Error fetching departments list (Positional Attempt). SQL: [$sql]. Params: " . print_r($queryParams, true) . ". Error: " . $e->getMessage());
    // Send user-friendly error
    echo json_encode(['error' => 'An error occurred while fetching department data (P). Check server logs.']);
} catch (Throwable $t) { // Catch other potential errors
     http_response_code(500);
     error_log("General Error Fetching Departments List (Positional Attempt): " . $t->getMessage() . " Trace: " . $t->getTraceAsString());
     echo json_encode(['error' => 'An unexpected server error occurred (P). Check server logs.']);
} finally {
    // Close connection
    $conn = null;
}
?>