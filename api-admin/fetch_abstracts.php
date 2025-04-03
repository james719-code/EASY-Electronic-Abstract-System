<?php
// Enable error reporting for debugging (remove/adjust in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Ensure errors are logged but not necessarily displayed in production
// ini_set('display_errors', 0);
// ini_set('log_errors', 1); // Make sure logging is enabled

// Set content type to JSON
header('Content-Type: application/json');

// Assuming config.php establishes the PDO connection $conn
include '../api-general/config.php'; // Adjust path as necessary

$response = []; // Initialize response array

// --- Input Handling ---
$searchTerm = trim($_GET['search'] ?? '');
$filterByType = trim($_GET['filterByType'] ?? ''); // e.g., 'Thesis', 'Dissertation'

// --- Query Parameters Array ---
// This array will hold the values for the positional placeholders (?) in order.
$queryParams = [];

// --- Build SQL Query using Positional Placeholders ---
try {
    $sql = "SELECT
                a.abstract_id,
                a.title,
                a.researchers,
                a.citation,
                a.abstract_type,
                a.description,
                CASE
                    WHEN a.abstract_type = 'Thesis' THEN p.program_name
                    WHEN a.abstract_type = 'Dissertation' THEN dpt.department_name
                    ELSE NULL
                END AS related_entity_name,
                CASE
                    WHEN a.abstract_type = 'Thesis' THEN p.program_initials
                    WHEN a.abstract_type = 'Dissertation' THEN dpt.department_initials
                    ELSE NULL
                END AS related_entity_initials
            FROM
                ABSTRACT a
            LEFT JOIN
                THESIS_ABSTRACT t ON a.abstract_id = t.abstract_id AND a.abstract_type = 'Thesis'
            LEFT JOIN
                PROGRAM p ON t.program_id = p.program_id
            LEFT JOIN
                DISSERTATION_ABSTRACT d ON a.abstract_id = d.abstract_id AND a.abstract_type = 'Dissertation'
            LEFT JOIN
                DEPARTMENT dpt ON d.department_id = dpt.department_id
            WHERE 1=1"; // Start with 1=1 for easy appending

    // Apply search filter if a search term is provided
    if (!empty($searchTerm)) {
        // Append the clause with four positional placeholders (?)
        $sql .= " AND (a.title LIKE ? OR a.researchers LIKE ? OR a.citation LIKE ? OR a.description LIKE ?)";
        // Prepare the search value ONCE
        $searchValue = '%' . $searchTerm . '%';
        // Add the value to the params array FOUR times, matching the placeholders
        $queryParams[] = $searchValue; // for title LIKE ?
        $queryParams[] = $searchValue; // for researchers LIKE ?
        $queryParams[] = $searchValue; // for citation LIKE ?
        $queryParams[] = $searchValue; // for description LIKE ?
    }

    // Apply type filter if specified
    if ($filterByType === 'Thesis' || $filterByType === 'Dissertation') {
        // Append the clause with one positional placeholder (?)
        $sql .= " AND a.abstract_type = ?";
        // Add the value to the params array ONCE
        $queryParams[] = $filterByType; // for abstract_type = ?
    }

    // --- Sorting ---
    // Apply default sorting (or implement dynamic sorting carefully)
    // No placeholders needed for static ORDER BY
    $sql .= " ORDER BY a.title ASC";

    // --- Prepare and Execute ---
    error_log("Preparing Abstract SQL (Positional): " . $sql); // Log the final SQL
    $stmt = $conn->prepare($sql);

    // Execute with the array of query parameters. The order matters!
    error_log("Executing Abstract SQL (Positional) with params: " . print_r($queryParams, true));
    $stmt->execute($queryParams); // Pass the indexed array directly
    error_log("Execution successful (Positional).");

    // --- Fetch and Return Results ---
    error_log("Fetching abstract results (Positional)...");
    $abstracts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    error_log("Fetch successful (Positional). Row count: " . count($abstracts));

    // Send the response
    echo json_encode($abstracts);

} catch (PDOException $e) {
    http_response_code(500); // Internal Server Error
    // Log detailed error for debugging
    error_log("Fetch Abstracts Error (Positional Attempt). SQL was: [$sql]. Search term was: [$searchTerm]. Type Filter was: [$filterByType]. Query Params: " . print_r($queryParams, true) . ". Error: " . $e->getMessage() . " Trace: " . $e->getTraceAsString());
    $response['error'] = "Database error fetching abstracts (P). Check server logs."; // User-friendly message
    echo json_encode($response);

} catch (Throwable $t) { // Catch other potential errors (e.g., includes failing)
     http_response_code(500);
     error_log("General Error Fetching Abstracts (Positional Attempt): " . $t->getMessage() . " Trace: " . $t->getTraceAsString());
     $response['error'] = 'An unexpected server error occurred (P). Check server logs.';
     echo json_encode($response);
} finally {
    // Optional: Close connection if not persistent
    // $conn = null;
}
?>