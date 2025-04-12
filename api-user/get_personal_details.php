<?php
// api-user/get_personal_details.php
header('Content-Type: application/json');
require_once '../api-general/config.php'; // Adjust path if needed

// Ensure session is started (might be redundant if config.php does it)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$response = ['error' => null, 'data' => null];

// Check if user is logged in and is a 'User'
if (!isset($_SESSION['account_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'User') {
    $response['error'] = 'Unauthorized: User not logged in or not a User.';
    echo json_encode($response);
    exit;
}

$accountId = $_SESSION['account_id'];

try {
    $sql = "SELECT
                a.account_id, a.username, a.name, a.sex,
                u.academic_level, u.program_id,
                p.program_name
            FROM account a
            JOIN user u ON a.account_id = u.user_id
            LEFT JOIN program p ON u.program_id = p.program_id
            WHERE a.account_id = :account_id AND a.account_type = 'User'";

    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':account_id', $accountId, PDO::PARAM_INT);
    $stmt->execute();

    $user_details = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user_details) {
        $response['data'] = $user_details;
    } else {
        // This case should ideally not happen if session is valid, but good to handle
        error_log("Failed to fetch details for logged-in user ID: " . $accountId);
        $response['error'] = 'Could not retrieve user details.';
    }

} catch (PDOException $e) {
    error_log("Database Error in get_personal_details.php: " . $e->getMessage());
    $response['error'] = 'Database error occurred.';
}

echo json_encode($response);
exit;
?>