<?php
session_start(); // Must be the very first thing before any output
include 'api-general/config.php'; // Adjust path if needed

$error_message = null; // Initialize error message

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username'] ?? ''); // Use null coalescing and trim
    $password = $_POST['password'] ?? ''; // Use null coalescing

    if (empty($username) || empty($password)) {
        $error_message = "Username and password are required.";
    } else {
        try {
            // Prepare a single query to get account details and potentially program_id for users
            // This query matches the database structure
            $sql = "SELECT
                        acc.account_id,
                        acc.name,
                        acc.username,
                        acc.password, -- The hashed password from ACCOUNT table
                        acc.account_type, -- 'Admin' or 'User' from ACCOUNT table
                        usr.program_id -- Get program_id from USER table, will be NULL if not a User
                    FROM
                        ACCOUNT acc
                    LEFT JOIN
                        USER usr ON acc.account_id = usr.account_id AND acc.account_type = 'User'
                    WHERE
                        acc.username = :username";

            $stmt = $conn->prepare($sql);
            $stmt->execute([':username' => $username]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC); // Fetch the potential account

            // Verify if account exists and password matches
            if ($account && password_verify($password, $account['password'])) {

                // --- Session Variable Update ---
                // Login successful, set session variables based on the API scripts' expectations
                $_SESSION['account_id'] = $account['account_id']; // Needed by API scripts
                $_SESSION['username'] = $account['username'];   // Good to have for display purposes
                $_SESSION['user_type'] = $account['account_type']; // *** CRITICAL: Use 'user_type' and the value directly from DB ('Admin' or 'User') ***

                // Check account type and redirect appropriately
                if ($account['account_type'] === 'Admin') {
                    // No specific admin details needed in session for basic auth check,
                    // but you could add position/work_id if needed elsewhere.
                    // $_SESSION['position'] = $admin_details['position']; // Example if querying ADMIN table separately

                    // Redirect to admin dashboard
                    header("Location: dashboard.php"); // Adjust filename if needed
                    exit;
                } elseif ($account['account_type'] === 'User') {
                    // Store program_id specifically for the user role
                    $_SESSION['program_id'] = $account['program_id']; // Correctly stored
                    $_SESSION['name'] = $account['name'];

                    // Redirect to user main menu
                    header("Location: main_menu.php"); // Adjust filename if needed
                    exit;
                } else {
                    // Should not happen with valid data in ACCOUNT table
                    $error_message = "Invalid account type found in database.";
                    // Clear potentially partially set session variables
                    session_unset();
                    session_destroy();
                }
                // --- End Session Variable Update ---

            } else {
                // Invalid username or password
                $error_message = "Invalid username or password!";
            }
        } catch (PDOException $e) {
            // Log the detailed error for the admin/developer
            error_log("Login Database Error: " . $e->getMessage());
            // Set a generic error message for the user
            $error_message = "An internal error occurred. Please try again later.";
        } catch (Exception $e) {
            // Catch any other unexpected errors
            error_log("Login General Error: " . $e->getMessage());
            $error_message = "An unexpected error occurred. Please try again later.";
        } finally {
            // Close connection if needed, though often not required with PDO persistency or script end
             $conn = null;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Electronic Abstract System</title>
    <link rel="stylesheet" href="css/index.css"> <!-- Adjust path if needed -->
    <link rel="stylesheet" href="css/login.css"> <!-- Adjust path if needed -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
</head>
<body>
    <section class="login-section">
        <div class="login-container">
            <h2>Login</h2>
            <?php if ($error_message): // Check if error message is set ?>
                <div class="error-message"><?php echo htmlspecialchars($error_message); ?></div>
            <?php endif; ?>
            <form method="POST" action="login.php"> <!-- Action points to self -->
                <div class="form-group">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" placeholder="Enter your username" required value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username'], ENT_QUOTES, 'UTF-8') : ''; ?>"> <!-- Retain username on error, added ENT_QUOTES -->
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Enter your password" required>
                </div>
                <button type="submit" class="btn primary-btn">Login</button>
                <p>Don't have an account? <a href="register.php">Register here</a></p>
            </form>
        </div>
    </section>
    <footer>
        <p>© <?php echo date("Y"); ?> Electronic Abstract System. All rights reserved.</p> <!-- Dynamic year -->
    </footer>

    <!-- Theme Toggle JS (unchanged) -->
    <script>
        const themeToggleBtn = document.getElementById('theme-toggle');
        const themeIcon = document.getElementById('themeIcon');

        if (themeToggleBtn && themeIcon) {
            const currentTheme = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', currentTheme);
            if (currentTheme === 'dark') {
                themeIcon.classList.remove('fa-sun');
                themeIcon.classList.add('fa-moon');
            } else {
                 themeIcon.classList.remove('fa-moon');
                 themeIcon.classList.add('fa-sun');
            }

            themeToggleBtn.addEventListener('click', () => {
                const currentTheme = document.documentElement.getAttribute('data-theme');
                const switchToTheme = currentTheme === 'dark' ? 'light' : 'dark';
                document.documentElement.setAttribute('data-theme', switchToTheme);
                localStorage.setItem('theme', switchToTheme);

                if (switchToTheme === 'dark') {
                    themeIcon.classList.remove('fa-sun');
                    themeIcon.classList.add('fa-moon');
                } else {
                    themeIcon.classList.remove('fa-moon');
                    themeIcon.classList.add('fa-sun');
                }
            });
        }
    </script>
</body>
</html>