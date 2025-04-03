<?php
// api-general/functions.php

/**
 * This file contains shared functions used across the API endpoints.
 */

// --- Define Standard Log Types/Actions (Optional but good practice) ---
// You can define more constants here as needed for other actions/types
define('LOG_ACTION_TYPE_CREATE_ABSTRACT', 'CREATE_ABSTRACT');
define('LOG_ACTION_TYPE_UPDATE_ABSTRACT', 'UPDATE_ABSTRACT');
define('LOG_ACTION_TYPE_DELETE_ABSTRACT', 'DELETE_ABSTRACT');
// ... add others like CREATE_PROGRAM, UPDATE_ACCOUNT, etc.

define('LOG_TYPE_ABSTRACT', 'ABSTRACT');
define('LOG_TYPE_PROGRAM', 'PROGRAM');
define('LOG_TYPE_DEPARTMENT', 'DEPARTMENT');
define('LOG_TYPE_ACCOUNT', 'ACCOUNT');
define('LOG_TYPE_SYSTEM', 'SYSTEM');
// ... add others as needed


// --- LOG ACTION FUNCTION DEFINITION ---
/**
 * Logs an action to the LOG table and relevant detail table.
 * This function expects to be called WITHIN an existing database transaction
 * started by the calling script, especially for CUD operations, to ensure atomicity.
 * It will check if a transaction is active but will NOT commit or rollback itself
 * if the transaction was started externally.
 *
 * @param PDO $dbConn The PDO database connection object. MUST BE PASSED.
 * @param int $actor_id The ID of the account performing the action.
 * @param string $action_type e.g., 'UPDATE_ABSTRACT', 'CREATE_PROGRAM'. Matches LOG.action_type. Should use defined constants if possible.
 * @param string $log_type e.g., 'ABSTRACT', 'PROGRAM', 'ACCOUNT'. Matches LOG.log_type. Should use defined constants if possible.
 * @param int|null $target_entity_id The ID of the primary entity being acted upon (e.g., abstract_id, program_id). NULL for logs where it's not applicable/captured this way.
 * @param int|null $target_account_id For ACCOUNT logs, the ID of the account being modified.
 * @return bool True on success, False on failure. Logs errors internally.
 */
function log_action(PDO $dbConn, int $actor_id, string $action_type, string $log_type, ?int $target_entity_id, ?int $target_account_id = null): bool {
    // Check if the connection object is valid
    if (!$dbConn) {
        error_log("LOGGING Error [log_action function]: Invalid PDO connection object provided.");
        return false;
    }

    // Check if we are operating within a transaction started by the caller
    $isExternalTransaction = $dbConn->inTransaction();

    try {
        // If not in an external transaction, we might choose to start one for logging atomicity,
        // but the primary design assumes the caller handles the main transaction.
        // For simplicity here, we proceed assuming the caller manages the transaction for CUD.
        // if (!$isExternalTransaction) { $dbConn->beginTransaction(); } // Optional: Uncomment if standalone logging transaction is needed

        // 1. Insert into main LOG table
        $sql_log = "INSERT INTO LOG (actor_account_id, action_type, log_type, time) VALUES (:actor_id, :action_type, :log_type, CURRENT_TIMESTAMP)";
        $stmt_log = $dbConn->prepare($sql_log);
        $stmt_log->bindParam(':actor_id', $actor_id, PDO::PARAM_INT);
        $stmt_log->bindParam(':action_type', $action_type, PDO::PARAM_STR);
        $stmt_log->bindParam(':log_type', $log_type, PDO::PARAM_STR);

        if (!$stmt_log->execute()) {
             // Throw exception to be caught below, providing context
             throw new PDOException("Failed to execute insert into LOG table. Error: " . implode(' | ', $stmt_log->errorInfo()));
        }

        $log_id = $dbConn->lastInsertId();

        // It's possible lastInsertId returns 0 or false if the table doesn't have AUTO_INCREMENT or upon error
        if (!$log_id || $log_id <= 0) {
            throw new Exception("Failed to get a valid last insert ID for LOG table after execution. Actor: {$actor_id}, Action: {$action_type}");
        }

        // 2. Insert into detail table based on log_type
        $detail_sql = "";
        $stmt_detail = null;

        switch ($log_type) {
            case LOG_TYPE_ABSTRACT: // Using constant
                if ($target_entity_id === null || $target_entity_id <= 0) throw new InvalidArgumentException("Target Entity ID (abstract_id) is required and must be positive for ABSTRACT log type.");
                $detail_sql = "INSERT INTO LOG_ABSTRACT (log_id, abstract_id, account_id) VALUES (:log_id, :abstract_id, :account_id)";
                $stmt_detail = $dbConn->prepare($detail_sql);
                $stmt_detail->bindParam(':log_id', $log_id, PDO::PARAM_INT);
                $stmt_detail->bindParam(':abstract_id', $target_entity_id, PDO::PARAM_INT);
                $stmt_detail->bindParam(':account_id', $actor_id, PDO::PARAM_INT); // Actor is the one modifying
                break;

            case LOG_TYPE_PROGRAM: // Using constant
                 if ($target_entity_id === null || $target_entity_id <= 0) throw new InvalidArgumentException("Target Entity ID (program_id) is required and must be positive for PROGRAM log type.");
                 // Ensure $actor_id IS an admin here (or adjust based on requirements)
                 $detail_sql = "INSERT INTO LOG_PROGRAM (log_id, program_id, admin_account_id) VALUES (:log_id, :program_id, :admin_account_id)";
                 $stmt_detail = $dbConn->prepare($detail_sql);
                 $stmt_detail->bindParam(':log_id', $log_id, PDO::PARAM_INT);
                 $stmt_detail->bindParam(':program_id', $target_entity_id, PDO::PARAM_INT);
                 $stmt_detail->bindParam(':admin_account_id', $actor_id, PDO::PARAM_INT); // Assumes actor is admin
                break;

             case LOG_TYPE_DEPARTMENT: // Using constant
                if ($target_entity_id === null || $target_entity_id <= 0) throw new InvalidArgumentException("Target Entity ID (department_id) is required and must be positive for DEPARTMENT log type.");
                // Ensure $actor_id IS an admin here
                $detail_sql = "INSERT INTO LOG_DEPARTMENT (log_id, department_id, admin_account_id) VALUES (:log_id, :department_id, :admin_account_id)";
                $stmt_detail = $dbConn->prepare($detail_sql);
                $stmt_detail->bindParam(':log_id', $log_id, PDO::PARAM_INT);
                $stmt_detail->bindParam(':department_id', $target_entity_id, PDO::PARAM_INT);
                $stmt_detail->bindParam(':admin_account_id', $actor_id, PDO::PARAM_INT); // Assumes actor is admin
                break;

            case LOG_TYPE_ACCOUNT: // Using constant
                 if ($target_account_id === null || $target_account_id <= 0) throw new InvalidArgumentException("Target Account ID is required and must be positive for ACCOUNT log type.");
                 // Ensure $actor_id IS an admin here
                 $detail_sql = "INSERT INTO LOG_ACCOUNT (log_id, target_account_id, admin_account_id) VALUES (:log_id, :target_account_id, :admin_account_id)";
                 $stmt_detail = $dbConn->prepare($detail_sql);
                 $stmt_detail->bindParam(':log_id', $log_id, PDO::PARAM_INT);
                 $stmt_detail->bindParam(':target_account_id', $target_account_id, PDO::PARAM_INT);
                 $stmt_detail->bindParam(':admin_account_id', $actor_id, PDO::PARAM_INT); // Assumes actor is admin
                break;

             case LOG_TYPE_SYSTEM: // Using constant
                // System logs might not need a detail entry
                break;

            default:
                // Log type not recognized or doesn't require a detail entry
                error_log("Notice [log_action function]: Log type '{$log_type}' does not have a specific detail table insert defined.");
                break;
        }

        // Execute the detail insert statement if one was prepared
        if ($stmt_detail) {
             if (!$stmt_detail->execute()) {
                  // Throw exception to be caught below, providing context
                  throw new PDOException("Failed to execute insert into detail log table for log_type '{$log_type}'. Error: " . implode(' | ', $stmt_detail->errorInfo()));
             }
        }

        // If we created a transaction locally, commit it.
        // Typically, we rely on the caller's commit.
        // if (!$isExternalTransaction) { $dbConn->commit(); }

        return true; // Logging successful

    } catch (PDOException | Exception | InvalidArgumentException $e) {
        // Rollback *only if* this function started the transaction (which it currently doesn't)
        // if (!$isExternalTransaction && $dbConn->inTransaction()) { $dbConn->rollBack(); }

        // Log the error that occurred within the logging function
        error_log("LOGGING Error [log_action function]: " . $e->getMessage() . " | Input Params: actor={$actor_id}, action={$action_type}, type={$log_type}, entity={$target_entity_id}, account={$target_account_id}");

        // Do NOT re-throw the exception here, as it would likely cause the main transaction
        // in the calling script to roll back just because logging failed (unless that's desired).
        // Return false to indicate logging failed.
        return false;
    }
}


// --- Add other shared functions below if needed ---
// function sanitize_input($data) { ... }
// function check_permissions($role, $required_role) { ... }

?>