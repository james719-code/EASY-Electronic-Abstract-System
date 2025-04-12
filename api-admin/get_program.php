<?php
header('Content-Type: application/json');
include '../api-general/config.php';

// No login required usually, add if needed
$response = [];

if (!isset($_GET['program_id']) || !is_numeric($_GET['program_id'])) {
    http_response_code(400);
    $response['error'] = "Invalid request: Missing or non-numeric program_id.";
    echo json_encode($response);
    exit;
}
$program_id = intval($_GET['program_id']);

try {
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
            WHERE p.program_id = :program_id";

    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':program_id', $program_id, PDO::PARAM_INT);
    $stmt->execute();
    $program = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($program) {
        echo json_encode($program);
    } else {
        http_response_code(404);
        $response['error'] = "Program not found.";
        echo json_encode($response);
    }

} catch (PDOException $e) {
    http_response_code(500);
    error_log("Get Program Error: " . $e->getMessage());
    $response['error'] = "Database error fetching program details.";
    echo json_encode($response);
}
?>