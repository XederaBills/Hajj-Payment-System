<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

include 'config.php';

$message = '';
$success = false;
$edit_mode = false;
$edit_user = null;

// Handle edit request
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $edit_stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND role = 'finance'");
    $edit_stmt->bind_param("i", $edit_id);
    $edit_stmt->execute();
    $result = $edit_stmt->get_result();
    if ($result->num_rows > 0) {
        $edit_mode = true;
        $edit_user = $result->fetch_assoc();
    }
    $edit_stmt->close();
}

// Handle add/update user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_user' || $_POST['action'] === 'update_user') {
        $username   = trim($_POST['username']);
        $full_name  = trim($_POST['full_name']);
        $email      = trim($_POST['email'] ?? '');
        $password   = trim($_POST['password'] ?? '');
        $user_id    = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

        if (strlen($username) < 4) {
            $message = "Username must be at least 4 characters.";
            $success = false;
        } elseif ($_POST['action'] === 'add_user' && strlen($password) < 6) {
            $message = "Password must be at least 6 characters.";
            $success = false;
        } else {
            // Check username uniqueness (exclude current user if updating)
            $check_query = $user_id > 0 
                ? "SELECT id FROM users WHERE username = ? AND id != ?" 
                : "SELECT id FROM users WHERE username = ?";
            $check = $conn->prepare($check_query);
            
            if ($user_id > 0) {
                $check->bind_param("si", $username, $user_id);
            } else {
                $check->bind_param("s", $username);
            }
            $check->execute();
            $check->store_result();

            if ($check->num_rows > 0) {
                $message = "Username '$username' already exists.";
                $success = false;
            } else {
                if ($_POST['action'] === 'add_user') {
                    // Add new user
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("
                        INSERT INTO users (username, password, full_name, email, role, status) 
                        VALUES (?, ?, ?, ?, 'finance', 'active')
                    ");
                    $stmt->bind_param("ssss", $username, $hashed_password, $full_name, $email);

                    if ($stmt->execute()) {
                        $success = true;
                        $message = "New finance officer '$username' created successfully.";
                    } else {
                        $message = "Error creating user: " . $stmt->error;
                        $success = false;
                    }
                } else {
                    // Update existing user
                    if (!empty($password)) {
                        // Update with new password
                        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $conn->prepare("
                            UPDATE users 
                            SET username = ?, password = ?, full_name = ?, email = ?
                            WHERE id = ?
                        ");
                        $stmt->bind_param("ssssi", $username, $hashed_password, $full_name, $email, $user_id);
                    } else {
                        // Update without changing password
                        $stmt = $conn->prepare("
                            UPDATE users 
                            SET username = ?, full_name = ?, email = ?
                            WHERE id = ?
                        ");
                        $stmt->bind_param("sssi", $username, $full_name, $email, $user_id);
                    }

                    if ($stmt->execute()) {
                        $success = true;
                        $message = "Finance officer '$username' updated successfully.";
                        header('Location: admin_users.php?success=updated');
                        exit;
                    } else {
                        $message = "Error updating user: " . $stmt->error;
                        $success = false;
                    }
                }
                $stmt->close();
            }
            $check->close();
        }
    }
}

// Handle reset password
if (isset($_GET['reset']) && is_numeric($_GET['reset'])) {
    $user_id = (int)$_GET['reset'];
    $new_pass = bin2hex(random_bytes(8));
    $hashed = password_hash($new_pass, PASSWORD_DEFAULT);

    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    $stmt->bind_param("si", $hashed, $user_id);
    if ($stmt->execute()) {
        $message = "Password reset successfully.<br>New password: <strong>$new_pass</strong><br>(Give this to the user and instruct them to change it immediately)";
        $success = true;
    } else {
        $message = "Error resetting password.";
        $success = false;
    }
    $stmt->close();
}

// Handle toggle status
if (isset($_GET['toggle_status']) && is_numeric($_GET['toggle_status'])) {
    $user_id = (int)$_GET['toggle_status'];
    
    $current = $conn->query("SELECT status FROM users WHERE id = $user_id")->fetch_assoc();
    $new_status = ($current['status'] === 'active') ? 'inactive' : 'active';

    $stmt = $conn->prepare("UPDATE users SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $new_status, $user_id);
    if ($stmt->execute()) {
        $message = "User status updated to " . ucfirst($new_status) . ".";
        $success = true;
    } else {
        $message = "Error updating status.";
        $success = false;
    }
    $stmt->close();
}

// Handle delete user
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $user_id = (int)$_GET['delete'];
    
    // Get user info first
    $user_info = $conn->query("SELECT username FROM users WHERE id = $user_id")->fetch_assoc();
    
    $stmt = $conn->prepare("DELETE FROM users WHERE id = ? AND role = 'finance'");
    $stmt->bind_param("i", $user_id);
    if ($stmt->execute()) {
        $message = "Finance officer '" . $user_info['username'] . "' deleted successfully.";
        $success = true;
    } else {
        $message = "Error deleting user.";
        $success = false;
    }
    $stmt->close();
}

// Check for success message
if (isset($_GET['success']) && $_GET['success'] === 'updated') {
    $message = "Finance officer updated successfully!";
    $success = true;
}

// Get all finance officers with payment activity
$users = $conn->query("
    SELECT 
        u.id, 
        u.username, 
        u.full_name, 
        u.email, 
        u.role, 
        u.status, 
        u.created_at,
        COUNT(p.id) as payment_count,
        COALESCE(SUM(p.amount), 0) as total_recorded
    FROM users u
    LEFT JOIN payments p ON u.id = p.recorded_by
    WHERE u.role = 'finance'
    GROUP BY u.id
    ORDER BY u.created_at DESC
")->fetch_all(MYSQLI_ASSOC);

// Get statistics
$total_users = count($users);
$active_users = count(array_filter($users, fn($u) => $u['status'] === 'active'));
$inactive_users = $total_users - $active_users;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Finance Officers - Admin</title>
    <style>
        :root {
            --bg: #0f172a;
            --card: rgba(30,41,59,0.7);
            --border: rgba(51,65,85,0.5);
            --text: #e2e8f0;
            --muted: #94a3b8;
            --primary: #3b82f6;
            --primary-dark: #2563eb;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --radius: 12px;
            --shadow: 0 8px 32px rgba(0,0,0,0.35);
        }

        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: var(--text);
            min-height: 100vh;
            line-height: 1.6;
        }

        .container { max-width: 1400px; margin: 0 auto; padding: 24px 16px; }

        header {
            background: linear-gradient(135deg, #1e293b 0%, #111827 100%);
            padding: 16px 32px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.5);
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        header .inner {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }

        header h1 {
            font-size: 1.5rem;
            font-weight: 600;
            background: linear-gradient(135deg, #60a5fa, #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        header nav {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        header nav a {
            color: var(--text);
            text-decoration: none;
            font-weight: 500;
            padding: 8px 16px;
            border-radius: 6px;
            transition: all 0.2s;
            font-size: 0.9rem;
        }

        header nav a:hover,
        header nav a.active {
            background: rgba(59,130,246,0.15);
            color: #60a5fa;
        }

        header nav a.logout {
            background: rgba(239,68,68,0.25);
            color: #fca5a5;
        }

        header nav a.logout:hover {
            background: rgba(239,68,68,0.45);
            color: #fff;
        }

        h1.main-title {
            text-align: center;
            font-size: 2rem;
            margin: 32px 0 40px;
            background: linear-gradient(135deg, #60a5fa, #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-weight: 700;
        }

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            box-shadow: var(--shadow);
            transition: all 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0,0,0,0.45);
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            color: #60a5fa;
        }

        .message {
            padding: 16px 24px;
            border-radius: 12px;
            margin: 0 0 32px;
            text-align: center;
            font-size: 1rem;
            font-weight: 500;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .success {
            background: rgba(16,185,129,0.15);
            color: #6ee7b7;
            border: 1px solid rgba(16,185,129,0.3);
        }

        .error {
            background: rgba(239,68,68,0.15);
            color: #fca5a5;
            border: 1px solid rgba(239,68,68,0.3);
        }

        .form-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 32px;
            box-shadow: var(--shadow);
            margin-bottom: 48px;
        }

        .form-card h2 {
            margin-bottom: 24px;
            text-align: center;
            color: #60a5fa;
            font-size: 1.4rem;
        }

        .edit-badge {
            display: inline-block;
            background: rgba(245,158,11,0.2);
            color: #fbbf24;
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 0.9rem;
            margin-left: 12px;
            font-weight: 600;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .form-group label {
            color: var(--muted);
            font-weight: 500;
            font-size: 0.9rem;
        }

        .form-group input {
            padding: 10px 14px;
            font-size: 1rem;
            border: 1px solid #475569;
            border-radius: 8px;
            background: #1e293b;
            color: var(--text);
            transition: all 0.25s;
        }

        .form-group input:-webkit-autofill,
        .form-group input:-webkit-autofill:hover, 
        .form-group input:-webkit-autofill:focus,
        .form-group input:-webkit-autofill:active {
            -webkit-box-shadow: 0 0 0 30px #1e293b inset !important;
            -webkit-text-fill-color: #e2e8f0 !important;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
        }

        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }

        .btn {
            flex: 1;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.25s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-decoration: none;
        }

        .btn-save {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
        }

        .btn-save:hover {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            transform: translateY(-1px);
        }

        .btn-cancel {
            background: rgba(71,85,105,0.3);
            color: var(--text);
        }

        .btn-cancel:hover {
            background: rgba(71,85,105,0.5);
        }

        .table-container {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .users-table {
            width: 100%;
            border-collapse: collapse;
        }

        .users-table th {
            background: rgba(15,23,42,0.9);
            color: #cbd5e1;
            padding: 14px 18px;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
            text-align: left;
        }

        .users-table td {
            padding: 14px 18px;
            border-top: 1px solid rgba(51,65,85,0.3);
        }

        .users-table tbody tr {
            transition: all 0.2s;
        }

        .users-table tbody tr:hover {
            background: rgba(59,130,246,0.08);
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .status-active {
            background: rgba(16,185,129,0.2);
            color: #34d399;
        }

        .status-inactive {
            background: rgba(239,68,68,0.2);
            color: #f87171;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .action-btn {
            padding: 6px 12px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .btn-edit {
            background: rgba(59,130,246,0.2);
            color: #60a5fa;
        }
        .btn-edit:hover {
            background: rgba(59,130,246,0.3);
            transform: translateY(-1px);
        }

        .btn-reset {
            background: rgba(245,158,11,0.2);
            color: #fbbf24;
        }
        .btn-reset:hover {
            background: rgba(245,158,11,0.3);
            transform: translateY(-1px);
        }

        .btn-deactivate {
            background: rgba(239,68,68,0.2);
            color: #f87171;
        }
        .btn-deactivate:hover {
            background: rgba(239,68,68,0.3);
            transform: translateY(-1px);
        }

        .btn-activate {
            background: rgba(16,185,129,0.2);
            color: #34d399;
        }
        .btn-activate:hover {
            background: rgba(16,185,129,0.3);
            transform: translateY(-1px);
        }

        .btn-delete {
            background: rgba(239,68,68,0.2);
            color: #f87171;
        }
        .btn-delete:hover {
            background: rgba(239,68,68,0.3);
            transform: translateY(-1px);
        }

        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: var(--muted);
        }

        .empty-state svg {
            width: 80px;
            height: 80px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .empty-state h3 {
            font-size: 1.5rem;
            margin-bottom: 8px;
        }

        .result-info {
            text-align: center;
            color: var(--muted);
            margin: 24px 0;
            font-size: 0.95rem;
        }

        @media (max-width: 768px) {
            .form-grid { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: 1fr; }
            .action-buttons { flex-direction: column; }
            .table-container { overflow-x: auto; }
            .users-table { min-width: 900px; }
        }
    </style>
</head>
<body>

<header>
    <div class="inner">
        <h1>Ustaz Hassan Labaika Travel Agency</h1>
        <nav>
            <a href="admin_dashboard.php">Dashboard</a>
            <a href="admin_pilgrims.php">Pilgrims</a>
            <a href="admin_payments.php">Payments</a>
            <a href="admin_set_cost.php">Package Cost</a>            
            <a href="admin_deferred.php">Deferred</a>
            <a href="admin_reports.php" >Reports</a>
            <a href="admin_users.php" >Users</a>
            <a href="logout.php" class="logout">Logout</a>
        </nav>
    </div>
</header>

<div class="container">

    <h1 class="main-title">Manage Finance Officers</h1>

    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Total Officers</div>
            <div class="stat-value"><?php echo $total_users; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Active Officers</div>
            <div class="stat-value" style="color: #34d399;"><?php echo $active_users; ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Inactive Officers</div>
            <div class="stat-value" style="color: #f87171;"><?php echo $inactive_users; ?></div>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="message <?php echo $success ? 'success' : 'error'; ?>">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <div class="form-card">
        <h2>
            <?php echo $edit_mode ? 'Edit Finance Officer' : 'Add New Finance Officer'; ?>
            <?php if ($edit_mode): ?>
                <span class="edit-badge">Editing: <?php echo htmlspecialchars($edit_user['username']); ?></span>
            <?php endif; ?>
        </h2>

        <form method="POST">
            <input type="hidden" name="action" value="<?php echo $edit_mode ? 'update_user' : 'add_user'; ?>">
            <?php if ($edit_mode): ?>
                <input type="hidden" name="user_id" value="<?php echo $edit_user['id']; ?>">
            <?php endif; ?>

            <div class="form-grid">
                <div class="form-group">
                    <label for="username">Username *</label>
                    <input type="text" name="username" id="username" required minlength="4" 
                           placeholder="Enter username" 
                           value="<?php echo $edit_mode ? htmlspecialchars($edit_user['username']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="full_name">Full Name *</label>
                    <input type="text" name="full_name" id="full_name" required 
                           placeholder="Enter full name"
                           value="<?php echo $edit_mode ? htmlspecialchars($edit_user['full_name']) : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" name="email" id="email" 
                           placeholder="Enter email address"
                           value="<?php echo $edit_mode ? htmlspecialchars($edit_user['email'] ?? '') : ''; ?>">
                </div>

                <div class="form-group">
                    <label for="password">Password <?php echo $edit_mode ? '(Leave blank to keep current)' : '*'; ?></label>
                    <input type="password" name="password" id="password" 
                           <?php echo $edit_mode ? '' : 'required'; ?> 
                           minlength="6" 
                           placeholder="<?php echo $edit_mode ? 'Leave blank to keep current password' : 'Enter password'; ?>">
                </div>
            </div>

            <div class="form-actions">
                <?php if ($edit_mode): ?>
                    <a href="admin_users.php" class="btn btn-cancel">
                        <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                        Cancel
                    </a>
                <?php endif; ?>
                <button type="submit" class="btn btn-save">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    <?php echo $edit_mode ? 'Update Officer' : 'Create Officer'; ?>
                </button>
            </div>
        </form>
    </div>

    <h2 style="margin:48px 0 24px; text-align:center; color:#94a3b8;">Current Finance Officers</h2>

    <?php if (empty($users)): ?>
        <div class="table-container">
            <div class="empty-state">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                </svg>
                <h3>No Finance Officers</h3>
                <p>Create your first finance officer using the form above.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="table-container">
            <table class="users-table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Full Name</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Activity</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($user['username']) ?></strong></td>
                            <td><?= htmlspecialchars($user['full_name'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($user['email'] ?? '—') ?></td>
                            <td>
                                <span class="badge status-<?= $user['status'] ?>">
                                    <?= ucfirst($user['status']) ?>
                                </span>
                            </td>
                            <td>
                                <div style="font-size: 0.85rem;">
                                    <div style="color: #34d399; font-weight: 600;">GHS <?= number_format($user['total_recorded'], 0) ?></div>
                                    <div style="color: var(--muted);"><?= $user['payment_count'] ?> payments</div>
                                </div>
                            </td>
                            <td><?= date('d M Y', strtotime($user['created_at'])) ?></td>
                            <td>
                                <div class="action-buttons">
                                    <a href="?edit=<?= $user['id'] ?>" class="action-btn btn-edit">
                                        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                        </svg>
                                        Edit
                                    </a>
                                    <a href="?reset=<?= $user['id'] ?>" 
                                       class="action-btn btn-reset"
                                       onclick="return confirm('Reset password for <?= htmlspecialchars($user['username']) ?>?\nNew password will be shown.')">
                                        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                                        </svg>
                                        Reset
                                    </a>

                                    <?php if ($user['status'] === 'active'): ?>
                                        <a href="?toggle_status=<?= $user['id'] ?>" 
                                           class="action-btn btn-deactivate"
                                           onclick="return confirm('Deactivate <?= htmlspecialchars($user['username']) ?>?')">
                                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
                                            </svg>
                                            Deactivate
                                        </a>
                                    <?php else: ?>
                                        <a href="?toggle_status=<?= $user['id'] ?>" 
                                           class="action-btn btn-activate"
                                           onclick="return confirm('Activate <?= htmlspecialchars($user['username']) ?>?')">
                                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                            </svg>
                                            Activate
                                        </a>
                                    <?php endif; ?>

                                    <?php if ($user['payment_count'] == 0): ?>
                                        <a href="?delete=<?= $user['id'] ?>" 
                                           class="action-btn btn-delete"
                                           onclick="return confirm('Delete <?= htmlspecialchars($user['username']) ?>?\nThis action cannot be undone.')">
                                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                            </svg>
                                            Delete
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="result-info">
            Showing <?= count($users) ?> finance officer(s)
        </div>
    <?php endif; ?>

</div>

</body>
</html>