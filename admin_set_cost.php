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
$edit_data = null;

// Get current active year and costs
$active_query = $conn->query("
    SELECT hajj_year, package_cost, processing_fee 
    FROM hajj_costs 
    WHERE is_active = 1 
    LIMIT 1
");
$active = $active_query->num_rows > 0 ? $active_query->fetch_assoc() : null;

$current_year       = $active ? $active['hajj_year'] : date('Y');
$current_package    = $active ? (float)$active['package_cost'] : 0.00;
$current_processing = $active ? (float)$active['processing_fee'] : 0.00;

// Check if editing
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_year = (int)$_GET['edit'];
    $edit_stmt = $conn->prepare("SELECT * FROM hajj_costs WHERE hajj_year = ?");
    $edit_stmt->bind_param("i", $edit_year);
    $edit_stmt->execute();
    $edit_result = $edit_stmt->get_result();
    if ($edit_result->num_rows > 0) {
        $edit_mode = true;
        $edit_data = $edit_result->fetch_assoc();
    }
    $edit_stmt->close();
}

// Get all years for display
$all_costs = $conn->query("SELECT * FROM hajj_costs ORDER BY hajj_year DESC")->fetch_all(MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $year           = (int)($_POST['hajj_year'] ?? 0);
    $package_cost   = (float)($_POST['package_cost'] ?? 0);
    $processing_fee = (float)($_POST['processing_fee'] ?? 0);
    $make_active    = isset($_POST['make_active']) ? 1 : 0;

    if ($year < 2000 || $package_cost <= 0) {
        $message = "Please enter a valid year (≥ 2000) and package cost greater than 0.";
        $success = false;
    } else {
        $stmt = $conn->prepare("
            INSERT INTO hajj_costs (hajj_year, package_cost, processing_fee) 
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                package_cost = VALUES(package_cost), 
                processing_fee = VALUES(processing_fee), 
                updated_at = NOW()
        ");
        $stmt->bind_param("idd", $year, $package_cost, $processing_fee);

        if ($stmt->execute()) {
            $success = true;
            $message = "Costs for Hajj year $year updated successfully.";

            if ($make_active) {
                $conn->query("UPDATE hajj_costs SET is_active = 0");
                $active_stmt = $conn->prepare("UPDATE hajj_costs SET is_active = 1 WHERE hajj_year = ?");
                $active_stmt->bind_param("i", $year);
                $active_stmt->execute();
                $active_stmt->close();
                $message .= " (Year $year is now the active year)";
            }
            
            // Redirect to clear form after successful save
            header('Location: admin_set_cost.php?success=1');
            exit;
        } else {
            $message = "Error saving costs: " . $stmt->error;
            $success = false;
        }
        $stmt->close();
    }
}

// Handle delete request
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $delete_year = (int)$_GET['delete'];
    
    // Check if it's the active year
    $check_stmt = $conn->prepare("SELECT is_active FROM hajj_costs WHERE hajj_year = ?");
    $check_stmt->bind_param("i", $delete_year);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $check_data = $check_result->fetch_assoc();
    $check_stmt->close();
    
    if ($check_data && $check_data['is_active'] == 1) {
        header('Location: admin_set_cost.php?error=cannot_delete_active');
        exit;
    }
    
    $delete_stmt = $conn->prepare("DELETE FROM hajj_costs WHERE hajj_year = ?");
    $delete_stmt->bind_param("i", $delete_year);
    if ($delete_stmt->execute()) {
        header('Location: admin_set_cost.php?deleted=1');
        exit;
    }
    $delete_stmt->close();
}

// Check for success messages
if (isset($_GET['success'])) {
    $message = "Cost settings saved successfully!";
    $success = true;
}
if (isset($_GET['deleted'])) {
    $message = "Hajj year deleted successfully!";
    $success = true;
}
if (isset($_GET['error']) && $_GET['error'] === 'cannot_delete_active') {
    $message = "Cannot delete the active year. Please set another year as active first.";
    $success = false;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Set Hajj Costs - Admin</title>
    <style>
        :root {
            --bg: #0f172a;
            --card: rgba(30,41,59,0.65);
            --border: rgba(51,65,85,0.5);
            --text: #e2e8f0;
            --muted: #94a3b8;
            --primary: #3b82f6;
            --primary-dark: #2563eb;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
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

        .container { max-width: 1200px; margin: 0 auto; padding: 24px 16px; }

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

        .current-summary {
            background: linear-gradient(135deg, rgba(59,130,246,0.15) 0%, rgba(30,41,59,0.8) 100%);
            border: 1px solid rgba(59,130,246,0.3);
            border-radius: var(--radius);
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: var(--shadow);
            text-align: center;
        }

        .current-summary h3 {
            font-size: 1.2rem;
            margin-bottom: 20px;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .summary-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 24px;
            margin-top: 20px;
        }

        .stat-item {
            background: rgba(30,41,59,0.5);
            padding: 20px;
            border-radius: 8px;
            border: 1px solid rgba(51,65,85,0.3);
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

        .form-card {
            background: rgba(30,41,59,0.7);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 40px 32px;
            box-shadow: var(--shadow);
            margin-bottom: 48px;
        }

        .form-card h2 {
            margin-bottom: 32px;
            text-align: center;
            color: #60a5fa;
            font-size: 1.5rem;
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
            gap: 24px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .form-group label {
            color: var(--muted);
            font-weight: 500;
            font-size: 0.95rem;
        }

        .form-group input {
            padding: 12px 16px;
            font-size: 1rem;
            border: 1px solid #475569;
            border-radius: 8px;
            background: #1e293b;
            color: var(--text);
            transition: all 0.25s;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
        }

        .checkbox-group {
            margin: 24px 0;
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 16px;
            background: rgba(59,130,246,0.1);
            border-radius: 8px;
            border: 1px solid rgba(59,130,246,0.2);
        }

        .checkbox-group input[type="checkbox"] {
            width: 20px;
            height: 20px;
            cursor: pointer;
        }

        .checkbox-group label {
            font-size: 1rem;
            cursor: pointer;
            color: var(--text);
        }

        .form-buttons {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }

        .btn {
            flex: 1;
            padding: 14px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.25s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .btn-save {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
        }

        .btn-save:hover {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59,130,246,0.4);
        }

        .btn-cancel {
            background: rgba(71,85,105,0.3);
            color: var(--text);
        }

        .btn-cancel:hover {
            background: rgba(71,85,105,0.5);
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

        .table-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 48px 0 24px;
        }

        .table-header h2 {
            color: #94a3b8;
            font-size: 1.3rem;
        }

        .table-container {
            background: rgba(30,41,59,0.7);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .years-table {
            width: 100%;
            border-collapse: collapse;
        }

        .years-table th {
            background: rgba(15,23,42,0.9);
            color: #cbd5e1;
            padding: 16px 20px;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
            text-align: left;
        }

        .years-table td {
            padding: 16px 20px;
            border-top: 1px solid rgba(51,65,85,0.3);
        }

        .years-table tbody tr {
            transition: all 0.2s;
        }

        .years-table tbody tr:hover {
            background: rgba(59,130,246,0.08);
        }

        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .badge-active {
            background: rgba(16,185,129,0.2);
            color: #34d399;
        }

        .badge-inactive {
            background: rgba(71,85,105,0.2);
            color: #94a3b8;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
        }

        .btn-icon {
            padding: 8px 12px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            text-decoration: none;
        }

        .btn-edit {
            background: rgba(59,130,246,0.2);
            color: #60a5fa;
        }

        .btn-edit:hover {
            background: rgba(59,130,246,0.3);
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
            padding: 60px 20px;
            color: var(--muted);
            font-size: 1.1rem;
        }

        @media (max-width: 768px) {
            .form-grid { grid-template-columns: 1fr; }
            .form-card { padding: 32px 24px; }
            .summary-stats { grid-template-columns: 1fr; }
            .form-buttons { flex-direction: column; }
            
            .years-table {
                font-size: 0.85rem;
            }
            
            .years-table th,
            .years-table td {
                padding: 12px 10px;
            }
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
            <a href="admin_set_cost.php" class="active">Package Cost</a>
            <a href="admin_deferred.php">Deferred</a>
            <a href="admin_reports.php" >Reports</a>
            <a href="admin_users.php">Users</a>
            <a href="logout.php" class="logout">Logout</a>
        </nav>
    </div>
</header>

<div class="container">

    <h1 class="main-title">Manage Hajj Package Costs</h1>

    <!-- Current Active Year Summary -->
    <div class="current-summary">
        <h3>Current Active Hajj Year</h3>
        <div class="summary-stats">
            <div class="stat-item">
                <div class="stat-label">Year</div>
                <div class="stat-value"><?php echo $current_year; ?></div>
            </div>
            <div class="stat-item">
                <div class="stat-label">Package Cost</div>
                <div class="stat-value" style="color: #34d399;">₵ <?php echo number_format($current_package, 2); ?></div>
            </div>
            <div class="stat-item">
                <div class="stat-label">Processing Fee</div>
                <div class="stat-value" style="color: #34d399;">₵ <?php echo number_format($current_processing, 2); ?></div>
            </div>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="message <?php echo $success ? 'success' : 'error'; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <div class="form-card">
        <h2>
            <?php echo $edit_mode ? 'Edit Hajj Year Costs' : 'Add New Hajj Year Costs'; ?>
            <?php if ($edit_mode): ?>
                <span class="edit-badge">Editing Year <?php echo $edit_data['hajj_year']; ?></span>
            <?php endif; ?>
        </h2>

        <form method="POST">
            <div class="form-grid">
                <div class="form-group">
                    <label for="hajj_year">Hajj Year *</label>
                    <input type="number" name="hajj_year" id="hajj_year" min="2000" 
                           value="<?php echo $edit_mode ? $edit_data['hajj_year'] : $current_year; ?>" 
                           <?php echo $edit_mode ? 'readonly' : ''; ?> required>
                </div>

                <div class="form-group">
                    <label for="package_cost">Hajj Package Cost (₵) *</label>
                    <input type="number" name="package_cost" id="package_cost" step="0.01" min="0" 
                           value="<?php echo $edit_mode ? $edit_data['package_cost'] : $current_package; ?>" required>
                </div>

                <div class="form-group">
                    <label for="processing_fee">Processing Fee per Pilgrim (₵)</label>
                    <input type="number" name="processing_fee" id="processing_fee" step="0.01" min="0" 
                           value="<?php echo $edit_mode ? $edit_data['processing_fee'] : $current_processing; ?>">
                </div>
            </div>

            <div class="checkbox-group">
                <input type="checkbox" name="make_active" id="make_active" value="1" 
                       <?php echo ($edit_mode && $edit_data['is_active']) || (!$edit_mode && $active) ? 'checked' : ''; ?>>
                <label for="make_active">Make this year the active/current year</label>
            </div>

            <div class="form-buttons">
                <?php if ($edit_mode): ?>
                    <a href="admin_set_cost.php" class="btn btn-cancel">
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
                    <?php echo $edit_mode ? 'Update Cost Settings' : 'Save Cost Settings'; ?>
                </button>
            </div>
        </form>
    </div>

    <div class="table-header">
        <h2>All Configured Hajj Years</h2>
    </div>

    <?php if (empty($all_costs)): ?>
        <div class="empty-state">
            No Hajj years configured yet.
        </div>
    <?php else: ?>
        <div class="table-container">
            <table class="years-table">
                <thead>
                    <tr>
                        <th>Year</th>
                        <th>Package Cost</th>
                        <th>Processing Fee</th>
                        <th>Status</th>
                        <th>Last Updated</th>
                        <th style="text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_costs as $c): ?>
                        <tr>
                            <td><strong><?php echo $c['hajj_year']; ?></strong></td>
                            <td>GHS <?php echo number_format($c['package_cost'], 2); ?></td>
                            <td>GHS <?php echo number_format($c['processing_fee'], 2); ?></td>
                            <td>
                                <?php if ($c['is_active']): ?>
                                    <span class="badge badge-active">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-inactive">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('M d, Y', strtotime($c['updated_at'])); ?></td>
                            <td>
                                <div class="action-buttons">
                                    <a href="admin_set_cost.php?edit=<?php echo $c['hajj_year']; ?>" class="btn-icon btn-edit">
                                        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                        </svg>
                                        Edit
                                    </a>
                                    <?php if (!$c['is_active']): ?>
                                        <button onclick="confirmDelete(<?php echo $c['hajj_year']; ?>)" class="btn-icon btn-delete">
                                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                            </svg>
                                            Delete
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

</div>

<script>
function confirmDelete(year) {
    if (confirm(`Are you sure you want to delete Hajj year ${year}?\n\nThis action cannot be undone.`)) {
        window.location.href = `admin_set_cost.php?delete=${year}`;
    }
}
</script>

</body>
</html>