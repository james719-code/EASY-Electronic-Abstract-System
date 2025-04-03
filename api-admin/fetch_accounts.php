<?php
// api-admin/fetch_accounts.php

// --- Session Handling (Crucial for checking authorization) ---
session_start(); // Start the session to access logged-in admin data

// Enable error reporting for debugging (remove/adjust in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Set content type to JSON
header('Content-Type: application/json');

// Assuming config.php establishes the PDO connection $conn
include '../api-general/config.php'; // Adjust path as necessary

// Response array
$response = [];

// --- Authentication/Authorization Check ---
if (!isset($_SESSION['account_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'Admin') {
    http_response_code(401); // Unauthorized
    $response['error'] = "Unauthorized: Admin access required.";
    echo json_encode($response);
    exit;
}

// --- Input Handling ---
$searchTerm = trim($_GET['search'] ?? '');
$programId = trim($_GET['program'] ?? ''); // Filter by program ID
$filterByType = trim($_GET['type'] ?? ''); // Filter by 'Admin' or 'User'
$sortBy = trim($_GET['sort'] ?? 'name'); // Default sort column
$sortDir = strtoupper(trim($_GET['dir'] ?? 'ASC')); // Default sort direction

// Validate sort direction
if ($sortDir !== 'ASC' && $sortDir !== 'DESC') {
    $sortDir = 'ASC';
}

// --- Build SQL Query with Positional Placeholders (?) ---
$sql = "SELECT
            acc.account_id,
            acc.username,
            acc.name,
            acc.sex,
            acc.account_type,
            p.program_name,         -- Will be NULL for Admins
            usr.academic_level,     -- Will be NULL for Admins
            adm.position            -- Will be NULL for Users
        FROM
            ACCOUNT acc
        LEFT JOIN
            USER usr ON acc.account_id = usr.account_id AND acc.account_type = 'User'
        LEFT JOIN
            PROGRAM p ON usr.program_id = p.program_id
        LEFT JOIN
            ADMIN adm ON acc.account_id = adm.account_id AND acc.account_type = 'Admin'
        WHERE 1=1"; // Start with 1=1 for easy AND appending

// Array to hold parameter values for positional placeholders IN ORDER
$queryParams = [];

// Apply search filter
if (!empty($searchTerm)) {
    // Append clause with positional placeholders
    $sql .= " AND (acc.username LIKE ? OR acc.name LIKE ?)";
    // Prepare the search term value
    $searchParamValue = '%' . $searchTerm . '%';
    // Add the value TWICE to the array, matching the two '?'
    $queryParams[] = $searchParamValue;
    $queryParams[] = $searchParamValue;
}

// Apply program filter
$programIdInt = filter_var($programId, FILTER_VALIDATE_INT); // Validate and get int value
if (!empty($programId) && $programIdInt !== false) {
    $sql .= " AND usr.program_id = ?";
    $queryParams[] = $programIdInt; // Add the integer program ID
}

// Apply account type filter
if ($filterByType === 'Admin' || $filterByType === 'User') {
    $sql .= " AND acc.account_type = ?";
    $queryParams[] = $filterByType;
}

// --- Sorting (No changes needed here, as it doesn't use parameters) ---
$allowedSortColumns = [
    'account_id' => 'acc.account_id',
    'username' => 'acc.username',
    'name' => 'acc.name',
    'account_type' => 'acc.account_type',
    'program_name' => 'p.program_name',
    'position' => 'adm.position'
];
$sortColumnSql = $allowedSortColumns['name']; // Default
if (array_key_exists($sortBy, $allowedSortColumns)) {
    $sortColumnSql = $allowedSortColumns[$sortBy];
}
$sql .= " ORDER BY " . $sortColumnSql . " " . $sortDir;
if ($sortBy !== 'name') {
    $sql .= ", acc.name ASC";
}

// --- Execute Query using Positional Parameters ---
try {
    // Log the final SQL and parameters before preparing
    error_log("Preparing SQL (Positional): " . $sql);
    $stmt = $conn->prepare($sql);

    // Execute by passing the array of ordered parameter values
    error_log("Executing (Positional) with params: " . print_r($queryParams, true));
    $stmt->execute($queryParams); // Pass the array directly
    error_log("Execution successful (Positional).");


    error_log("Fetching results (Positional)...");
    $accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Fetch successful (Positional). Row count: " . count($accounts));


    // Return the results as JSON
    http_response_code(200); // OK
    echo json_encode($accounts);

} catch (PDOException $e) {
    // Handle database errors gracefully
    http_response_code(500); // Internal Server Error
    error_log("Database Query Error fetching accounts list (Positional Attempt). SQL: [$sql]. Params: " . print_r($queryParams, true) . ". Error: " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
    echo json_encode(['error' => 'An error occurred while fetching account data (P). Check server logs.']); // User-friendly
} catch (Throwable $t) { // Catch other potential errors like in the example
     http_response_code(500);
     error_log("General Error Fetching Accounts List (Positional Attempt): " . $t->getMessage() . " Trace: " . $t->getTraceAsString());
     echo json_encode(['error' => 'An unexpected server error occurred (P). Check server logs.']);
} finally {
    // Close connection
    $conn = null;
}
?>