<?php
session_start();

// Allow only admin role to delete
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: admin_pilgrims.php?error=unauthorized');
    exit;
}

include 'config.php';

// Check if request is POST and pilgrim_id is provided
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['pilgrim_id']) || !is_numeric($_POST['pilgrim_id'])) {
    header('Location: admin_pilgrims.php?error=invalid_request');
    exit;
}

$pilgrim_id = (int)$_POST['pilgrim_id'];

// Start transaction for safe deletion
$conn->begin_transaction();

try {
    // First, get pilgrim details for logging (optional)
    $stmt = $conn->prepare("SELECT first_name, surname FROM pilgrims WHERE id = ?");
    $stmt->bind_param("i", $pilgrim_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $pilgrim = $result->fetch_assoc();
    $stmt->close();
    
    if (!$pilgrim) {
        throw new Exception("Pilgrim not found");
    }
    
    // Delete associated payments (CASCADE should handle this, but we do it explicitly for safety)
    $stmt = $conn->prepare("DELETE FROM payments WHERE pilgrim_id = ?");
    $stmt->bind_param("i", $pilgrim_id);
    $stmt->execute();
    $stmt->close();
    
    // Delete the pilgrim
    $stmt = $conn->prepare("DELETE FROM pilgrims WHERE id = ?");
    $stmt->bind_param("i", $pilgrim_id);
    $stmt->execute();
    $stmt->close();
    
    // Commit the transaction
    $conn->commit();
    
    // Redirect with success message
    header('Location: admin_pilgrims.php?success=deleted&name=' . urlencode($pilgrim['first_name'] . ' ' . $pilgrim['surname']));
    exit;
    
} catch (Exception $e) {
    // Rollback on error
    $conn->rollback();
    
    // Redirect with error message
    header('Location: admin_pilgrims.php?error=delete_failed&message=' . urlencode($e->getMessage()));
    exit;
}
?>