<?php
// config.php - Database Configuration

// Enable error reporting for debugging (remove or adjust in production)
// Configure in php.ini or server config ideally
// ini_set('display_errors', 1);
// ini_set('display_startup_errors', 1);
// error_reporting(E_ALL);

// Database credentials
$host = "localhost";
$dbname = "electronic_abstract_system_database";
$username = "root"; // Replace with your database username
$password = "";     // Replace with your database password

// --- Database Connection Setup ---
try {
    // Create a new PDO instance
    $dsn = "mysql:host=$host;dbname=$dbname;charset=utf8mb4"; // Use utf8mb4
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,      // Throw exceptions on error
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,          // Fetch associative arrays
        PDO::ATTR_EMULATE_PREPARES   => false,                       // Use native prepared statements
    ];

    $conn = new PDO($dsn, $username, $password, $options);

} catch (PDOException $e) {
    // Handle connection errors critically
    error_log("FATAL: Database connection failed: " . $e->getMessage());

    // Send a generic error response ONLY IF headers haven't been sent yet
    // (Crucial for scripts like view_abstract which change headers later)
    if (!headers_sent()) {
        http_response_code(500); // Internal Server Error
        header('Content-Type: application/json'); // Ensure JSON header even on error
        echo json_encode(['error' => 'Database connection error. Please contact support.']);
    }
    // Stop script execution regardless
    exit;
}