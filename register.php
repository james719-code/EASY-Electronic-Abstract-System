<?php
session_start(); // Start session at the very beginning
// Enable error reporting for debugging (remove/adjust in production)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

include 'api-general/config.php'; // Adjust path if needed

$error_message = null; // Initialize error message
$programs = []; // Initialize programs array

// --- Fetch Programs for Dropdown ---
try {
    $stmt_programs = $conn->query("SELECT program_id, program_name FROM PROGRAM ORDER BY program_name ASC");
    $programs = $stmt_programs->fetchAll(PDO::FETCH_ASSOC);
    // $stmt_programs->closeCursor(); // Not strictly needed with fetchAll
} catch (PDOException $e) {
    error_log("Error fetching programs: " . $e->getMessage());
    $error_message = "An error occurred while loading page data. Please try again later.";
}

// --- Handle Form Submission ---
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Get and sanitize input data (Using FILTER_DEFAULT instead of deprecated FILTER_SANITIZE_STRING)
    $username = trim(filter_input(INPUT_POST, 'username', FILTER_DEFAULT) ?? '');
    $password = $_POST['password'] ?? ''; // Don't trim passwords
    $retype_password = $_POST['retype_password'] ?? '';
    $name = trim(filter_input(INPUT_POST, 'name', FILTER_DEFAULT) ?? '');
    $sex = trim(filter_input(INPUT_POST, 'sex', FILTER_DEFAULT) ?? '');
    $program_id = trim($_POST['program'] ?? ''); // Will validate as int later
    $academic_level = trim(filter_input(INPUT_POST, 'academic_level', FILTER_DEFAULT) ?? '');

    // Basic Server-Side Validations
    if (empty($username) || empty($password) || empty($name) || empty($sex) || empty($program_id) || empty($academic_level)) {
        $error_message = "All fields are required.";
    } elseif ($password !== $retype_password) {
        $error_message = "Passwords do not match!";
    } elseif (strlen($password) < 6) { // Example: Minimum password length
         $error_message = "Password must be at least 6 characters long.";
    } elseif (!filter_var($program_id, FILTER_VALIDATE_INT)) {
        $error_message = "Invalid program selected.";
    } elseif (!in_array($sex, ['M', 'F'])) { // Validate Sex
        $error_message = "Invalid value for Sex (M or F allowed).";
    } else {
        // Validation passed, proceed with database operations
        $program_id = (int)$program_id; // Cast program_id to int

        try {
            // Start transaction
            $conn->beginTransaction();

            // 1. Check if username already exists in ACCOUNT table
            $stmt_check = $conn->prepare("SELECT 1 FROM ACCOUNT WHERE username = :username");
            $stmt_check->bindParam(':username', $username);
            $stmt_check->execute();

            if ($stmt_check->fetchColumn()) {
                // Username exists - rollback is not needed as nothing was inserted yet
                $error_message = "Username already exists!";
                // No need to rollback here
            } else {
                // $stmt_check->closeCursor(); // Optional

                // 2. Hash the password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                if ($hashed_password === false) {
                    // Handle hashing failure - not a DB error yet, so no rollback needed
                    error_log("Password hashing failed during registration for username: " . $username);
                    $error_message = "Could not process password.";
                    // Exit the try block or throw a general exception if preferred
                     throw new Exception("Password hashing failed."); // Throw to trigger catch block
                }

                // 3. Insert into ACCOUNT table
                $sql_account = "INSERT INTO ACCOUNT (username, password, name, sex, account_type) VALUES (:username, :password, :name, :sex, 'User')";
                $stmt_account = $conn->prepare($sql_account);
                $stmt_account->bindParam(':username', $username);
                $stmt_account->bindParam(':password', $hashed_password);
                $stmt_account->bindParam(':name', $name);
                $stmt_account->bindParam(':sex', $sex);
                if (!$stmt_account->execute()) {
                     throw new PDOException("Failed to create account record. Error: " . $stmt_account->errorInfo()[2]);
                }
                $new_account_id = $conn->lastInsertId(); // Get the new account ID
                // $stmt_account->closeCursor(); // Optional

                if (!$new_account_id) {
                     // Should not happen if execute succeeded, but defensive check
                      throw new PDOException("Failed to retrieve new account ID after insert.");
                }

                // 4. Insert into USER table
                $sql_user = "INSERT INTO USER (account_id, program_id, academic_level) VALUES (:account_id, :program_id, :academic_level)";
                $stmt_user = $conn->prepare($sql_user);
                $stmt_user->bindParam(':account_id', $new_account_id, PDO::PARAM_INT);
                $stmt_user->bindParam(':program_id', $program_id, PDO::PARAM_INT);
                $stmt_user->bindParam(':academic_level', $academic_level);
                if (!$stmt_user->execute()) {
                    throw new PDOException("Failed to finalize user registration details. Error: " . $stmt_user->errorInfo()[2]);
                }
                // $stmt_user->closeCursor(); // Optional

                // 5. Log the Registration Action in LOG table
                $action_type = 'CREATE_USER_ACCOUNT';
                $log_type = 'ACCOUNT';
                $sql_log = "INSERT INTO LOG (actor_account_id, action_type, log_type) VALUES (:actor_id, :action_type, :log_type)";
                $stmt_log = $conn->prepare($sql_log);
                // The actor is the user who just registered
                $stmt_log->bindParam(':actor_id', $new_account_id, PDO::PARAM_INT);
                $stmt_log->bindParam(':action_type', $action_type);
                $stmt_log->bindParam(':log_type', $log_type);
                if (!$stmt_log->execute()) {
                    throw new PDOException("Failed to log user registration action. Error: " . $stmt_log->errorInfo()[2]);
                }

                // 6. All inserts successful - Commit transaction
                $conn->commit();

                // Redirect to login page on successful registration
                // Clear potentially sensitive POST data before redirecting
                $_POST = array();
                header("Location: login.php?registered=success"); // Add query param for feedback
                exit;

            } // End of username check else block

        } catch (PDOException $e) {
            // Rollback on any database error during the transaction
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            error_log("Registration Database Error: " . $e->getMessage());
            // Check for specific unique constraint violations
             if ($e->getCode() == 23000 && stripos($e->getMessage(), 'ACCOUNT.username') !== false) {
                 $error_message = "Username already exists!";
                 http_response_code(409); // Conflict
             } else {
                $error_message = "An error occurred during registration. Please try again later.";
                http_response_code(500); // Internal Server Error
             }
        } catch (Exception $e) { // Catch other general exceptions (like hashing failure)
             if ($conn->inTransaction()) {
                $conn->rollBack();
            }
             error_log("Registration General Error: " . $e->getMessage());
             $error_message = "An unexpected error occurred. Please try again later.";
             http_response_code(500);
        }
    } // End validation check else block
} // End POST request check

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - Electronic Abstract System</title>
    <link rel="stylesheet" href="css/index.css"> <!-- Adjust path if needed -->
    <link rel="stylesheet" href="css/register.css"> <!-- Adjust path if needed -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        .error-message { color: #D8000C; background-color: #FFD2D2; border: 1px solid #D8000C; padding: 10px; margin-bottom: 15px; border-radius: 4px; text-align: center; }
        .success-message { color: #270; background-color: #DFF2BF; border: 1px solid #270; padding: 10px; margin-bottom: 15px; border-radius: 4px; text-align: center; }
        @media (max-width: 768px) { .form-group-container { flex-direction: column; } .form-group-column { width: 100%; } }
    </style>
</head>
<body>
    <section class="register-section">
        <div class="register-container">
            <h2>Register New User</h2>
            <?php if ($error_message): ?>
                <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            <?php if (isset($_GET['registered']) && $_GET['registered'] === 'success' && !$error_message): // Show success only if no new errors ?>
                <div class="success-message">Registration successful! You can now log in.</div>
            <?php endif; ?>

            <form method="POST" action="register.php" id="registrationForm">
                <div class="form-group-container">
                    <!-- Column 1 -->
                    <div class="form-group-column">
                        <div class="form-group"><label for="name">Full Name</label><input type="text" id="name" name="name" placeholder="Enter your full name" required value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>"></div>
                        <div class="form-group"><label for="sex">Sex</label><select id="sex" name="sex" required><option value="">Select Sex</option><option value="M" <?php echo (isset($_POST['sex']) && $_POST['sex'] === 'M') ? 'selected' : ''; ?>>Male</option><option value="F" <?php echo (isset($_POST['sex']) && $_POST['sex'] === 'F') ? 'selected' : ''; ?>>Female</option></select></div>
                        <div class="form-group"><label for="academic_level">Academic Level</label><input type="text" id="academic_level" name="academic_level" placeholder="e.g., Undergraduate, Master's Student" required value="<?php echo isset($_POST['academic_level']) ? htmlspecialchars($_POST['academic_level']) : ''; ?>"></div>
                        <div class="form-group"><label for="program">Program</label><select id="program" name="program" required><option value="">Select Program</option><?php if (!empty($programs)): ?><?php foreach ($programs as $program): ?><option value="<?php echo htmlspecialchars($program['program_id']); ?>" <?php echo (isset($_POST['program']) && $_POST['program'] == $program['program_id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($program['program_name']); ?></option><?php endforeach; ?><?php else: ?><option value="" disabled>Could not load programs</option><?php endif; ?></select></div>
                    </div>
                    <!-- Column 2 -->
                    <div class="form-group-column">
                        <div class="form-group"><label for="username">Username</label><input type="text" id="username" name="username" placeholder="Choose a username" required value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"></div>
                        <div class="form-group"><label for="password">Password (min 6 chars)</label><input type="password" id="password" name="password" placeholder="Enter your password" required></div>
                        <div class="form-group"><label for="retype_password">Retype Password</label><input type="password" id="retype_password" name="retype_password" placeholder="Retype your password" required></div>
                    </div>
                </div>
                <button type="submit" class="btn primary-btn">Register</button>
                <p>Already have an account? <a href="login.php">Login here</a></p>
            </form>
        </div>
    </section>
    <footer><p>© <?php echo date("Y"); ?> Electronic Abstract System. All rights reserved.</p></footer>
    <script>
        // Client-side password match check (enhancement, not replacement for server check)
        const registrationForm = document.getElementById('registrationForm');
        if(registrationForm) {
            registrationForm.addEventListener('submit', function(event) {
                const password = document.getElementById('password').value;
                const retypePassword = document.getElementById('retype_password').value;
                let errorContainer = document.querySelector('.error-message[data-type="passwordMismatch"]');

                if (password !== retypePassword) {
                    if (!errorContainer) {
                        errorContainer = document.createElement('div');
                        errorContainer.className = 'error-message';
                        errorContainer.dataset.type = 'passwordMismatch';
                        document.querySelector('.register-container').insertBefore(errorContainer, registrationForm);
                    }
                    errorContainer.textContent = 'Passwords do not match!';
                    event.preventDefault(); // Prevent form submission
                } else if (errorContainer) {
                    errorContainer.remove(); // Remove mismatch error if passwords now match
                }
            });
        }
        // Theme Toggle Script (unchanged)
        // ... (keep your theme toggle script here if needed) ...
    </script>
</body>
</html>