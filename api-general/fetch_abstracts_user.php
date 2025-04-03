<?php
// api-user/fetch_abstracts.php

session_start();
header('Content-Type: application/json'); // Set response type to JSON

// --- Dependencies ---
// Adjust the path if your config file is located elsewhere
require_once '../api-general/config.php'; // Provides $conn (PDO connection)

// --- Authentication ---
// Ensure a user is logged in (Using 'account_type' based on important.txt schema)
if (!isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'User' || !isset($_SESSION['account_id'])) {
    http_response_code(403); // Forbidden
    echo json_encode(['error' => 'Access denied. User not logged in.']);
    exit;
}

// --- Input Handling ---
$searchTerm = $_GET['search'] ?? ''; // Default to empty string if not set
$programId = $_GET['program'] ?? null; // Default to null if not set
$sortBy = $_GET['sort'] ?? 'id_desc'; // Default sort order

// --- Parameter Validation & Query Building ---
$baseSql = "
    SELECT
        a.abstract_id,
        a.title,
        a.researchers,
        p.program_name
    FROM ABSTRACT a
    JOIN THESIS_ABSTRACT ta ON a.abstract_id = ta.abstract_id
    JOIN PROGRAM p ON ta.program_id = p.program_id
    WHERE a.abstract_type = 'Thesis' -- Ensure we only get Thesis abstracts
";

$whereClauses = [];
$bindings = []; // Use an indexed array for positional placeholders

// Add search term filter (searching title and researchers)
if (!empty($searchTerm)) {
    // IMPORTANT: One '?' for title LIKE, another for researchers LIKE
    $whereClauses[] = "(a.title LIKE ? OR a.researchers LIKE ?)";
    $searchTermWildcard = '%' . $searchTerm . '%';
    // Add the value twice to the bindings array, once for each '?'
    $bindings[] = $searchTermWildcard; // For title LIKE ?
    $bindings[] = $searchTermWildcard; // For researchers LIKE ?
}

// Add program filter
if (!empty($programId) && is_numeric($programId)) { // Basic validation
    $whereClauses[] = "ta.program_id = ?"; // One '?' for program_id = ?
    $bindings[] = (int)$programId; // Add the value to the bindings array
}

// Append WHERE clauses if any
if (!empty($whereClauses)) {
    $baseSql .= " AND " . implode(" AND ", $whereClauses);
}

// --- Sorting ---
// Whitelist allowed sort columns and directions (remains the same)
$allowedSorts = [
    'id_desc' => 'a.abstract_id DESC',
    'id_asc' => 'a.abstract_id ASC',
    'title_asc' => 'a.title ASC',
    'title_desc' => 'a.title DESC',
    'researchers_asc' => 'a.researchers ASC',
    'researchers_desc' => 'a.researchers DESC',
];

// Use the whitelisted value or default to 'id_desc'
$orderByClause = $allowedSorts[$sortBy] ?? $allowedSorts['id_desc'];
$baseSql .= " ORDER BY " . $orderByClause;

// Consider adding LIMIT and OFFSET for pagination in the future
// $baseSql .= " LIMIT ? OFFSET ?"; // If adding pagination, add bindings for limit/offset here

// --- Database Execution ---
try {
    $stmt = $conn->prepare($baseSql);

    // Execute the statement by passing the bindings array directly
    // PDO maps the array elements to the '?' placeholders in order
    $stmt->execute($bindings);

    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // --- Output Results ---
    echo json_encode($results); // Send the array of abstracts as JSON

} catch (PDOException $e) {
    // Log the detailed error server-side
    error_log("API Error (fetch_abstracts - positional): " . $e->getMessage() . " | SQL: " . $baseSql . " | Bindings: " . print_r($bindings, true));

    // Send a generic error message to the client
    http_response_code(500); // Internal Server Error
    echo json_encode(['error' => 'An error occurred while fetching abstracts. Please try again later.']);
} catch (Exception $e) {
    // Catch other potential errors
     error_log("API Error (fetch_abstracts general - positional): " . $e->getMessage());
     http_response_code(500);
     echo json_encode(['error' => 'An unexpected error occurred.']);
}

// Close connection explicitly? Usually not needed with PDO if script ends.
// $conn = null;

?>