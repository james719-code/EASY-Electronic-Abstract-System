<?php
// api-user/update_profile.php
session_start();
header('Content-Type: application/json');

// Include DB connection
require_once '../api-general/config.php'; // Adjust path as needed

// --- Logging Function Definition (Defined *locally* within this file) ---
function log_action($actor_id, $action_type, $log_type, $target_id = null) {
    global $conn; // Crucial: Make the database connection available

    if (!$conn) {
        error_log("log_action failed: Database connection is not available in " . __FILE__);
        return false;
    }

    try {
        // Basic log entry
        $sql_log = "INSERT INTO log (actor_account_id, action_type, log_type, time)
                    VALUES (:actor_id, :action_type, :log_type, NOW())";
        $stmt_log = $conn->prepare($sql_log);
        $stmt_log->bindValue(':actor_id', $actor_id, $actor_id === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt_log->bindParam(':action_type', $action_type, PDO::PARAM_STR);
        $stmt_log->bindParam(':log_type', $log_type, PDO::PARAM_STR);
        $stmt_log->execute();

        return true; // Indicate success

    } catch (PDOException $e) {
        error_log("Failed to execute log_action in " . __FILE__ . ": " . $e->getMessage() . " | Params: actor=$actor_id, action=$action_type, type=$log_type, target=$target_id");
        return false; // Indicate failure
    }
}
// --- End of Logging Function Definition ---


// Security Check: Ensure user is logged in and is a 'User'
if (!isset($_SESSION['account_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'User') {
    http_response_code(401); // Unauthorized
    echo json_encode(['error' => 'Unauthorized access.']);
    exit;
}

$sessionAccountId = $_SESSION['account_id'];
$response = ['error' => null, 'success' => null];

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // Method Not Allowed
    echo json_encode(['error' => 'Invalid request method.']);
    exit;
}

// --- Input Retrieval and Validation ---
$postedAccountId = isset($_POST['account_id']) ? (int)$_POST['account_id'] : null;
$username = trim($_POST['username'] ?? '');
$name = trim($_POST['name'] ?? '');
$sex = trim($_POST['sex'] ?? '');
$academicLevel = trim($_POST['academic_level'] ?? null); // Can be null
$programId = isset($_POST['program_id']) ? (int)$_POST['program_id'] : null;

// Verify the posted account_id matches the session account_id
if ($postedAccountId !== $sessionAccountId) {
    http_response_code(403); // Forbidden
    $response['error'] = 'Permission denied: Account ID mismatch.';
    echo json_encode($response);
    exit;
}

// Basic Validation
if (empty($username) || empty($name) || empty($sex) || empty($programId)) {
    http_response_code(400); // Bad Request
    $response['error'] = 'Missing required fields (Username, Name, Sex, Program).';
    echo json_encode($response);
    exit;
}
if (!in_array($sex, ['M', 'F'])) {
    http_response_code(400);
    $response['error'] = 'Invalid value for Sex.';
    echo json_encode($response);
    exit;
}

// --- Database Update ---
try {
    // Make sure $conn is available
    if (!$conn) {
         throw new Exception("Database connection not established.");
    }

    $conn->beginTransaction();

    // Check username uniqueness (if changed)
    $stmtCheckUser = $conn->prepare("SELECT account_id FROM account WHERE username = :username AND account_id != :account_id");
    $stmtCheckUser->bindParam(':username', $username, PDO::PARAM_STR);
    $stmtCheckUser->bindParam(':account_id', $sessionAccountId, PDO::PARAM_INT);
    $stmtCheckUser->execute();
    if ($stmtCheckUser->fetch()) {
        $conn->rollBack();
        http_response_code(409); // Conflict
        $response['error'] = 'Username already taken by another account.';
        echo json_encode($response);
        exit;
    }
    $stmtCheckUser->closeCursor();

    // Check if Program ID is valid
    $stmtCheckProg = $conn->prepare("SELECT program_id FROM program WHERE program_id = :program_id");
    $stmtCheckProg->bindParam(':program_id', $programId, PDO::PARAM_INT);
    $stmtCheckProg->execute();
    if (!$stmtCheckProg->fetch()) {
        $conn->rollBack();
        http_response_code(400);
        $response['error'] = 'Invalid Program selected.';
        echo json_encode($response);
        exit;
    }
    $stmtCheckProg->closeCursor();

    // Update ACCOUNT table
    $sqlAccount = "UPDATE account SET username = :username, name = :name, sex = :sex WHERE account_id = :account_id";
    $stmtAccount = $conn->prepare($sqlAccount);
    $stmtAccount->bindParam(':username', $username, PDO::PARAM_STR);
    $stmtAccount->bindParam(':name', $name, PDO::PARAM_STR);
    $stmtAccount->bindParam(':sex', $sex, PDO::PARAM_STR);
    $stmtAccount->bindParam(':account_id', $sessionAccountId, PDO::PARAM_INT);
    $stmtAccount->execute();

    // Update USER table
    $sqlUser = "UPDATE user SET academic_level = :academic_level, program_id = :program_id WHERE user_id = :account_id";
    $stmtUser = $conn->prepare($sqlUser);
    $stmtUser->bindParam(':academic_level', $academicLevel, PDO::PARAM_STR); // Allow null
    $stmtUser->bindParam(':program_id', $programId, PDO::PARAM_INT);
    $stmtUser->bindParam(':account_id', $sessionAccountId, PDO::PARAM_INT);
    $stmtUser->execute();

    // Commit Transaction
    $conn->commit();

    // Log the action *after* successful commit
    log_action($sessionAccountId, 'UPDATE_PROFILE', 'ACCOUNT', $sessionAccountId); // Log self-update

    // Update session variable if name changed
    $_SESSION['name'] = $name;

    http_response_code(200); // OK
    $response['success'] = 'Profile updated successfully.';

} catch (PDOException $e) {
    if ($conn && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Update Profile PDOException in " . __FILE__ . ": " . $e->getMessage());
    http_response_code(500);
    $response['error'] = 'Database error occurred during profile update.';
     // More specific error for unique constraint violation
    if ($e->getCode() == '23000') {
         if (strpos(strtolower($e->getMessage()), 'username') !== false) {
              http_response_code(409);
              $response['error'] = 'Username already exists.';
         } else {
              $response['error'] = 'Data integrity error during update.';
         }
    }
} catch (Exception $e) {
    if ($conn && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("General Update Profile Error in " . __FILE__ . ": " . $e->getMessage());
    http_response_code(500);
    $response['error'] = 'An unexpected error occurred: ' . $e->getMessage();
}

echo json_encode($response);
$conn = null; // Close connection
?>