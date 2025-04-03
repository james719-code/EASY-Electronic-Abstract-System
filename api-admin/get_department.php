<?php
header('Content-Type: application/json');
include '../api-general/config.php';

$response = [];

if (!isset($_GET['department_id']) || !is_numeric($_GET['department_id'])) {
    http_response_code(400);
    $response['error'] = "Invalid request: Missing or non-numeric department_id.";
    echo json_encode($response);
    exit;
}
$department_id = intval($_GET['department_id']);

try {
    $sql = "SELECT department_id, department_name, department_initials FROM DEPARTMENT WHERE department_id = :id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':id', $department_id, PDO::PARAM_INT);
    $stmt->execute();
    $department = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($department) {
        echo json_encode($department);
    } else {
        http_response_code(404);
        $response['error'] = "Department not found.";
        echo json_encode($response);
    }

} catch (PDOException $e) {
    http_response_code(500);
    error_log("Get Department Error: " . $e->getMessage());
    $response['error'] = "Database error fetching department details.";
    echo json_encode($response);
}
?>