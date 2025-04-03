<?php
session_start();

// Unset all session variables
session_unset();

// Destroy the session
session_destroy();

// Set a message in the session for the login page
$_SESSION['logout_message'] = "You have been successfully logged out.";

// Redirect to the login page
header("Location: login.php");
exit;
?>