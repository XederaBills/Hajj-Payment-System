<?php
/**
 * Handle Defer and Reactivate Actions
 * 
 * IMPORTANT: Place this file in the same directory as admin_pilgrims.php
 * 
 * This script processes:
 * 1. Deferring pilgrims to future years
 * 2. Reactivating deferred pilgrims
 */

session_start();

// Authentication check - Admin only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php?error=unauthorized');
    exit;
}

require_once 'config.php';

// Validate request method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: admin_pilgrims.php?error=invalid_request');
    exit;
}

$action = $_POST['action'] ?? '';
$pilgrim_id = intval($_POST['pilgrim_id'] ?? 0);

if (empty($action) || $pilgrim_id <= 0) {
    header('Location: admin_pilgrims.php?error=invalid_parameters');
    exit;
}

// ===========================
// DEFER ACTION
// ===========================
if ($action === 'defer') {
    
    $defer_to_year = intval($_POST['defer_to_year'] ?? 0);
    
    // Validation
    if ($defer_to_year <= date('Y')) {
        header('Location: admin_pilgrims.php?error=defer_failed&message=' . urlencode('Invalid year selected'));
        exit;
    }
    
    // Get pilgrim details
    $stmt = $conn->prepare("
        SELECT id, first_name, surname, file_number, status, balance 
        FROM pilgrims 
        WHERE id = ? 
        LIMIT 1
    ");
    $stmt->bind_param("i", $pilgrim_id);
    $stmt->execute();
    $pilgrim = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$pilgrim) {
        header('Location: admin_pilgrims.php?error=defer_failed&message=' . urlencode('Pilgrim not found'));
        exit;
    }
    
    // Check if already deferred
    if ($pilgrim['status'] === 'deferred') {
        header('Location: admin_pilgrims.php?error=defer_failed&message=' . urlencode('Pilgrim is already deferred'));
        exit;
    }
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Update pilgrim status
        $update_stmt = $conn->prepare("
            UPDATE pilgrims 
            SET status = 'deferred', 
                deferred_to_year = ?
            WHERE id = ?
        ");
        $update_stmt->bind_param("ii", $defer_to_year, $pilgrim_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update pilgrim status");
        }
        $update_stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        $full_name = $pilgrim['first_name'] . ' ' . $pilgrim['surname'];
        header("Location: admin_pilgrims.php?success=deferred&name=" . urlencode($full_name) . "&year=" . $defer_to_year);
        exit;
        
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        
        error_log("Defer failed for pilgrim ID $pilgrim_id: " . $e->getMessage());
        header('Location: admin_pilgrims.php?error=defer_failed&message=' . urlencode($e->getMessage()));
        exit;
    }
}

// ===========================
// REACTIVATE ACTION
// ===========================
elseif ($action === 'reactivate') {
    
    // Get pilgrim details
    $stmt = $conn->prepare("
        SELECT id, first_name, surname, file_number, status, balance, deferred_to_year 
        FROM pilgrims 
        WHERE id = ? 
        LIMIT 1
    ");
    $stmt->bind_param("i", $pilgrim_id);
    $stmt->execute();
    $pilgrim = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if (!$pilgrim) {
        header('Location: admin_pilgrims.php?error=reactivate_failed&message=' . urlencode('Pilgrim not found'));
        exit;
    }
    
    // Check if status is deferred
    if ($pilgrim['status'] !== 'deferred') {
        header('Location: admin_pilgrims.php?error=reactivate_failed&message=' . urlencode('Pilgrim is not deferred'));
        exit;
    }
    
    // Determine new status based on balance
    $new_status = ($pilgrim['balance'] <= 0) ? 'paid' : 'registered';
    
    // Start transaction
    $conn->begin_transaction();
    
    try {
        // Update pilgrim status
        $update_stmt = $conn->prepare("
            UPDATE pilgrims 
            SET status = ?, 
                deferred_to_year = NULL
            WHERE id = ?
        ");
        $update_stmt->bind_param("si", $new_status, $pilgrim_id);
        
        if (!$update_stmt->execute()) {
            throw new Exception("Failed to reactivate pilgrim");
        }
        $update_stmt->close();
        
        // Commit transaction
        $conn->commit();
        
        $full_name = $pilgrim['first_name'] . ' ' . $pilgrim['surname'];
        header("Location: admin_pilgrims.php?success=reactivated&name=" . urlencode($full_name));
        exit;
        
    } catch (Exception $e) {
        // Rollback on error
        $conn->rollback();
        
        error_log("Reactivate failed for pilgrim ID $pilgrim_id: " . $e->getMessage());
        header('Location: admin_pilgrims.php?error=reactivate_failed&message=' . urlencode($e->getMessage()));
        exit;
    }
}

// ===========================
// INVALID ACTION
// ===========================
else {
    header('Location: admin_pilgrims.php?error=invalid_action');
    exit;
}
?>