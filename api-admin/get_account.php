<?php
// api-admin/get_account.php

// Set header to return JSON
header('Content-Type: application/json');

// Include database configuration
include '../api-general/config.php'; // Adjust path if necessary

// Response array
$response = [];

// Check if account_id is provided and is numeric
if (isset($_GET['account_id']) && is_numeric($_GET['account_id'])) {
    $account_id = intval($_GET['account_id']);

    try {
        // Prepare the SQL query to fetch account details along with subtype info
        // We use LEFT JOINs to get subtype details if they exist
        $sql = "SELECT
                    a.account_id,
                    a.username,
                    a.name,
                    a.sex,
                    a.account_type,
                    -- User specific fields (will be NULL for Admins)
                    u.academic_level,
                    u.program_id,
                    p.program_name, -- Get program name for Users
                    -- Admin specific fields (will be NULL for Users)
                    adm.work_id,
                    adm.position
                FROM
                    ACCOUNT a
                LEFT JOIN
                    USER u ON a.account_id = u.account_id AND a.account_type = 'User'
                LEFT JOIN
                    PROGRAM p ON u.program_id = p.program_id -- Join PROGRAM via USER table
                LEFT JOIN
                    ADMIN adm ON a.account_id = adm.account_id AND a.account_type = 'Admin'
                WHERE
                    a.account_id = :account_id"; // Use named placeholder

        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':account_id', $account_id, PDO::PARAM_INT);
        $stmt->execute();

        // Fetch the account details
        $account = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($account) {
            // Account found, return its details
            // No need to set $response = $account, just echo it directly
             echo json_encode($account);
        } else {
            // Account not found
            http_response_code(404); // Not Found status
            $response['error'] = "Account not found";
            echo json_encode($response);
        }

    } catch (PDOException $e) {
        // Database error
        error_log("Database Error in get_account.php: " . $e->getMessage()); // Log the detailed error
        http_response_code(500); // Internal Server Error status
        $response['error'] = "Database error occurred: " . $e->getMessage(); // Provide error in response (consider simplifying for production)
        echo json_encode($response);
    }

} else {
    // Invalid or missing account_id
    http_response_code(400); // Bad Request status
    $response['error'] = "Invalid request: Missing or non-numeric account_id";
    echo json_encode($response);
}

// No further output needed as JSON is echoed directly
?>