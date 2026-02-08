<?php
/**
 * Admin Pilgrims Management
 * 
 * Features:
 * - View all pilgrims with filters
 * - Defer pilgrims to future years
 * - Reactivate deferred pilgrims
 * - Delete pilgrims
 * - Export to CSV
 */

session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

include 'config.php';
// Add right after: include 'config.php';

// PROCESS DEFER/REACTIVATE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $pilgrim_id = intval($_POST['pilgrim_id'] ?? 0);
    
    // DEFER
    if ($action === 'defer' && $pilgrim_id > 0) {
        $defer_to_year = intval($_POST['defer_to_year'] ?? 0);
        
        if ($defer_to_year > date('Y')) {
            $stmt = $conn->prepare("SELECT first_name, surname, status FROM pilgrims WHERE id = ?");
            $stmt->bind_param("i", $pilgrim_id);
            $stmt->execute();
            $pilgrim = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            
            if ($pilgrim && $pilgrim['status'] !== 'deferred') {
                $conn->begin_transaction();
                try {
                    $update = $conn->prepare("UPDATE pilgrims SET status = 'deferred', deferred_to_year = ? WHERE id = ?");
                    $update->bind_param("ii", $defer_to_year, $pilgrim_id);
                    $update->execute();
                    $update->close();
                    $conn->commit();
                    
                    $full_name = $pilgrim['first_name'] . ' ' . $pilgrim['surname'];
                    header("Location: admin_pilgrims.php?success=deferred&name=" . urlencode($full_name) . "&year=" . $defer_to_year);
                    exit;
                } catch (Exception $e) {
                    $conn->rollback();
                }
            }
        }
    }
    
    // REACTIVATE
    if ($action === 'reactivate' && $pilgrim_id > 0) {
        $stmt = $conn->prepare("SELECT first_name, surname, status, balance FROM pilgrims WHERE id = ?");
        $stmt->bind_param("i", $pilgrim_id);
        $stmt->execute();
        $pilgrim = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        
        if ($pilgrim && $pilgrim['status'] === 'deferred') {
            $new_status = ($pilgrim['balance'] <= 0) ? 'paid' : 'registered';
            
            $conn->begin_transaction();
            try {
                $update = $conn->prepare("UPDATE pilgrims SET status = ?, deferred_to_year = NULL WHERE id = ?");
                $update->bind_param("si", $new_status, $pilgrim_id);
                $update->execute();
                $update->close();
                $conn->commit();
                
                $full_name = $pilgrim['first_name'] . ' ' . $pilgrim['surname'];
                header("Location: admin_pilgrims.php?success=reactivated&name=" . urlencode($full_name));
                exit;
            } catch (Exception $e) {
                $conn->rollback();
            }
        }
    }
}
// Success/Error messages
$message = '';
$success = false;

if (isset($_GET['success'])) {
    if ($_GET['success'] === 'deleted') {
        $name = $_GET['name'] ?? 'Pilgrim';
        $message = "$name has been successfully deleted!";
        $success = true;
    } elseif ($_GET['success'] === 'deferred') {
        $name = $_GET['name'] ?? 'Pilgrim';
        $year = $_GET['year'] ?? '';
        $message = "$name has been deferred to year $year successfully!";
        $success = true;
    } elseif ($_GET['success'] === 'reactivated') {
        $name = $_GET['name'] ?? 'Pilgrim';
        $message = "$name has been reactivated successfully!";
        $success = true;
    }
}

if (isset($_GET['error'])) {
    if ($_GET['error'] === 'defer_failed') {
        $msg = $_GET['message'] ?? 'Unknown error';
        $message = "Deferral failed: $msg";
        $success = false;
    } elseif ($_GET['error'] === 'reactivate_failed') {
        $msg = $_GET['message'] ?? 'Unknown error';
        $message = "Reactivation failed: $msg";
        $success = false;
    } elseif ($_GET['error'] === 'delete_failed') {
        $message = "Deletion failed. Please try again.";
        $success = false;
    } elseif ($_GET['error'] === 'unauthorized') {
        $message = "You don't have permission to perform this action.";
        $success = false;
    }
}

// Filters
$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? '';
$balance_filter = $_GET['balance'] ?? '';
$hajj_type_filter = $_GET['hajj_type'] ?? '';

$where = [];
$params = [];
$types = '';

if ($search !== '') {
    $like = "%$search%";
    $where[] = "(file_number LIKE ? OR first_name LIKE ? OR surname LIKE ? OR contact LIKE ? OR ghana_card_number LIKE ? OR passport_number LIKE ?)";
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
    $types .= 'ssssss';
}

if ($status_filter !== '' && in_array($status_filter, ['registered', 'deferred', 'paid'])) {
    $where[] = "status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

if ($balance_filter === 'pending') {
    $where[] = "balance > 0";
} elseif ($balance_filter === 'paid') {
    $where[] = "balance <= 0";
}

if ($hajj_type_filter !== '' && in_array($hajj_type_filter, ['Tamatuo', 'Qiran', 'Ifraad'])) {
    $where[] = "hajj_type = ?";
    $params[] = $hajj_type_filter;
    $types .= 's';
}

$where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Get statistics
$total_pilgrims = $conn->query("SELECT COUNT(*) as cnt FROM pilgrims WHERE status != 'deferred'")->fetch_assoc()['cnt'];
$total_deferred = $conn->query("SELECT COUNT(*) as cnt FROM pilgrims WHERE status = 'deferred'")->fetch_assoc()['cnt'];
$total_paid = $conn->query("SELECT COUNT(*) as cnt FROM pilgrims WHERE balance <= 0 AND status != 'deferred'")->fetch_assoc()['cnt'];
$total_pending = $conn->query("SELECT COALESCE(SUM(balance), 0) as total FROM pilgrims WHERE balance > 0 AND status != 'deferred'")->fetch_assoc()['total'];

$query = "
    SELECT 
        p.id, 
        p.file_number, 
        p.first_name, 
        p.surname, 
        p.contact, 
        p.hajj_type, 
        p.status, 
        p.balance, 
        p.registration_date,
        p.deferred_to_year,
        COALESCE(SUM(CASE WHEN pay.is_processing_fee = 0 THEN pay.amount ELSE 0 END), 0) as total_paid,
        COUNT(pay.id) as payment_count
    FROM pilgrims p
    LEFT JOIN payments pay ON p.id = pay.pilgrim_id
    $where_clause
    GROUP BY p.id
    ORDER BY p.surname, p.first_name
";

$stmt = $conn->prepare($query);
if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$pilgrims = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Get available years for deferral
$available_years_query = $conn->query("
    SELECT hajj_year 
    FROM hajj_costs 
    WHERE hajj_year > YEAR(CURDATE())
    ORDER BY hajj_year ASC
");
$available_years = $available_years_query->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Pilgrims - Admin</title>
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
            --info: #06b6d4;
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

        .container { max-width: 1600px; margin: 0 auto; padding: 24px 16px; }

        /* Header */
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

        /* Page Title */
        h1.page-title {
            text-align: center;
            font-size: 2rem;
            color: #60a5fa;
            margin: 32px 0;
            font-weight: 600;
        }

        /* Messages */
        .message {
            padding: 16px 24px;
            border-radius: var(--radius);
            margin-bottom: 24px;
            font-weight: 500;
            border-left: 4px solid;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .message.success {
            background: rgba(16,185,129,0.15);
            color: #6ee7b7;
            border-color: var(--success);
        }

        .message.error {
            background: rgba(239,68,68,0.15);
            color: #fca5a5;
            border-color: var(--danger);
        }

        .message svg {
            flex-shrink: 0;
        }

        /* Statistics Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .stat-card {
            background: var(--card);
            backdrop-filter: blur(12px);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 20px;
            box-shadow: var(--shadow);
        }

        .stat-label {
            color: var(--muted);
            font-size: 0.85rem;
            margin-bottom: 8px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 4px;
        }

        .stat-card.primary .stat-value { color: var(--primary); }
        .stat-card.success .stat-value { color: var(--success); }
        .stat-card.warning .stat-value { color: var(--warning); }
        .stat-card.danger .stat-value { color: var(--danger); }

        /* Filter Bar */
        .filter-bar {
            background: var(--card);
            backdrop-filter: blur(12px);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: var(--shadow);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .filter-group label {
            color: var(--muted);
            font-size: 0.9rem;
            font-weight: 500;
        }

        input, select {
            padding: 10px 14px;
            background: rgba(15,23,42,0.5);
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.2s;
        }

        input:focus, select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
        }

        .filter-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
        }

        /* Buttons */
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 500;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59,130,246,0.4);
        }

        .btn-secondary {
            background: rgba(71,85,105,0.5);
            color: var(--text);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: rgba(71,85,105,0.8);
        }

        /* Table */
        .table-container {
            background: var(--card);
            backdrop-filter: blur(12px);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow-x: auto;
            box-shadow: var(--shadow);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid rgba(51,65,85,0.3);
        }

        th {
            background: rgba(15,23,42,0.8);
            color: #cbd5e1;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        tbody tr {
            transition: all 0.2s;
        }

        tbody tr:hover {
            background: rgba(59,130,246,0.08);
        }

        /* Status Badges */
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: capitalize;
        }

        .status-registered {
            background: rgba(245,158,11,0.2);
            color: #fbbf24;
        }

        .status-deferred {
            background: rgba(239,68,68,0.2);
            color: #fca5a5;
        }

        .status-paid {
            background: rgba(16,185,129,0.2);
            color: #6ee7b7;
        }

        .balance-paid { color: var(--success); font-weight: 600; }
        .balance-pending { color: var(--danger); font-weight: 600; }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn-action {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            text-decoration: none;
        }

        .btn-view {
            background: rgba(59,130,246,0.2);
            color: #60a5fa;
            border: 1px solid rgba(59,130,246,0.3);
        }

        .btn-view:hover {
            background: rgba(59,130,246,0.3);
        }

        .btn-defer {
            background: rgba(245,158,11,0.2);
            color: #fbbf24;
            border: 1px solid rgba(245,158,11,0.3);
        }

        .btn-defer:hover {
            background: rgba(245,158,11,0.3);
        }

        .btn-reactivate {
            background: rgba(16,185,129,0.2);
            color: #6ee7b7;
            border: 1px solid rgba(16,185,129,0.3);
        }

        .btn-reactivate:hover {
            background: rgba(16,185,129,0.3);
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            backdrop-filter: blur(4px);
        }

        .modal-content {
            background: var(--card);
            backdrop-filter: blur(12px);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            margin: 5% auto;
            padding: 0;
            max-width: 600px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
            animation: modalSlideIn 0.3s ease;
        }

        @keyframes modalSlideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 24px 32px;
            border-bottom: 1px solid var(--border);
        }

        .modal-header h2 {
            color: #60a5fa;
            font-size: 1.5rem;
        }

        .close {
            color: var(--muted);
            font-size: 2rem;
            font-weight: 300;
            cursor: pointer;
            transition: all 0.2s;
            line-height: 1;
        }

        .close:hover {
            color: var(--danger);
            transform: rotate(90deg);
        }

        .pilgrim-info {
            padding: 24px 32px;
            background: rgba(15,23,42,0.3);
            border-bottom: 1px solid var(--border);
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px dashed rgba(148,163,184,0.2);
        }

        .info-row:last-child {
            border-bottom: none;
        }

        .info-label {
            color: var(--muted);
            font-weight: 500;
        }

        .info-value {
            color: var(--text);
            font-weight: 600;
        }

        .form-group {
            padding: 24px 32px;
        }

        .form-group label {
            display: block;
            color: var(--muted);
            font-size: 0.9rem;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .modal-actions {
            display: flex;
            gap: 12px;
            padding: 24px 32px;
            justify-content: flex-end;
            border-top: 1px solid var(--border);
        }

        .btn-cancel {
            background: rgba(71,85,105,0.5);
            color: var(--text);
        }

        .btn-cancel:hover {
            background: rgba(71,85,105,0.8);
        }

        .btn-submit {
            background: linear-gradient(135deg, var(--warning), #d97706);
            color: white;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(245,158,11,0.4);
        }

        .btn-reactivate-submit {
            background: linear-gradient(135deg, var(--success), #059669);
            color: white;
        }

        .btn-reactivate-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16,185,129,0.4);
        }

        /* Empty State */
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
            margin-bottom: 12px;
            color: var(--text);
        }

        .result-info {
            text-align: center;
            color: var(--muted);
            margin: 24px 0;
            font-size: 1rem;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .filter-grid {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
            }

            .modal-content {
                margin: 10% 16px;
            }
        }
    </style>
</head>
<body>

<header>
    <div class="inner">
        <h1>Hajj Management System - Admin</h1>
        <nav>
            <a href="admin_dashboard.php">Dashboard</a>
            <a href="admin_pilgrims.php" class="active">Pilgrims</a>
            <a href="admin_payments.php">Payments</a>
            <a href="admin_set_cost.php">Package Cost</a>
            <a href="admin_deferred.php">Deferred</a>
            <a href="admin_reports.php" >Reports</a>
            <a href="admin_users.php">Users</a>
            <a href="logout.php" class="logout">Logout</a>
        </nav>
    </div>
</header>

<div class="container">

    <h1 class="page-title">Manage Pilgrims</h1>

    <?php if ($message): ?>
        <div class="message <?php echo $success ? 'success' : 'error'; ?>">
            <?php if ($success): ?>
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
            <?php else: ?>
                <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            <?php endif; ?>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card primary">
            <div class="stat-label">Total Pilgrims</div>
            <div class="stat-value"><?= number_format($total_pilgrims) ?></div>
        </div>

        <div class="stat-card success">
            <div class="stat-label">Fully Paid</div>
            <div class="stat-value"><?= number_format($total_paid) ?></div>
        </div>

        <div class="stat-card danger">
            <div class="stat-label">Deferred</div>
            <div class="stat-value"><?= number_format($total_deferred) ?></div>
        </div>

        <div class="stat-card warning">
            <div class="stat-label">Pending Balance</div>
            <div class="stat-value">₵ <?= number_format($total_pending, 0) ?></div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-bar">
        <form method="GET">
            <div class="filter-grid">
                <div class="filter-group">
                    <label>Search</label>
                    <input type="text" name="search" placeholder="Name, file #, contact..." value="<?= htmlspecialchars($search) ?>">
                </div>

                <div class="filter-group">
                    <label>Status</label>
                    <select name="status">
                        <option value="">All Statuses</option>
                        <option value="registered" <?= $status_filter === 'registered' ? 'selected' : '' ?>>Registered</option>
                        <option value="deferred" <?= $status_filter === 'deferred' ? 'selected' : '' ?>>Deferred</option>
                        <option value="paid" <?= $status_filter === 'paid' ? 'selected' : '' ?>>Paid</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Balance</label>
                    <select name="balance">
                        <option value="">All Balances</option>
                        <option value="pending" <?= $balance_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="paid" <?= $balance_filter === 'paid' ? 'selected' : '' ?>>Paid</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Hajj Type</label>
                    <select name="hajj_type">
                        <option value="">All Types</option>
                        <option value="Tamatuo" <?= $hajj_type_filter === 'Tamatuo' ? 'selected' : '' ?>>Tamatuo</option>
                        <option value="Qiran" <?= $hajj_type_filter === 'Qiran' ? 'selected' : '' ?>>Qiran</option>
                        <option value="Ifraad" <?= $hajj_type_filter === 'Ifraad' ? 'selected' : '' ?>>Ifraad</option>
                    </select>
                </div>
            </div>

            <div class="filter-actions">
                <button type="button" class="btn btn-secondary" onclick="location.href='admin_pilgrims.php'">Clear</button>
                <button type="submit" class="btn btn-primary">Apply Filters</button>
            </div>
        </form>
    </div>

    <!-- Table -->
    <?php if (empty($pilgrims)): ?>
        <div class="table-container">
            <div class="empty-state">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                </svg>
                <h3>No Pilgrims Found</h3>
                <p>No pilgrims match your search criteria. Try adjusting your filters.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="table-container">
            <table id="pilgrimsTable">
                <thead>
                    <tr>
                        <th>File #</th>
                        <th>Name</th>
                        <th>Contact</th>
                        <th>Hajj Type</th>
                        <th>Status</th>
                        <th>Total Paid</th>
                        <th>Balance</th>
                        <th>Payments</th>
                        <th>Registered</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pilgrims as $p): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($p['file_number'] ?? '—') ?></strong></td>
                            <td><?= htmlspecialchars($p['first_name'] . ' ' . $p['surname']) ?></td>
                            <td><?= htmlspecialchars($p['contact'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($p['hajj_type'] ?? '—') ?></td>
                            <td>
                                <span class="badge status-<?= $p['status'] ?>">
                                    <?= ucfirst($p['status']) ?>
                                </span>
                                <?php if ($p['status'] === 'deferred' && $p['deferred_to_year']): ?>
                                    <br><small style="color: var(--muted); font-size: 0.75rem;">→ Year <?= $p['deferred_to_year'] ?></small>
                                <?php endif; ?>
                            </td>
                            <td style="color: #34d399;"><strong>GHS <?= number_format($p['total_paid'], 2) ?></strong></td>
                            <td class="<?= $p['balance'] <= 0 ? 'balance-paid' : 'balance-pending' ?>">
                                ₵ <?= number_format($p['balance'], 2) ?>
                            </td>
                            <td><?= $p['payment_count'] ?> payments</td>
                            <td><?= date('d M Y', strtotime($p['registration_date'])) ?></td>
                            <td>
                                <div class="action-buttons">
                                    <a href="admin_pilgrim_view.php?id=<?= $p['id'] ?>" class="btn-action btn-view">
                                        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                        </svg>
                                        View
                                    </a>
                                    <?php if ($p['status'] === 'deferred'): ?>
                                        <button onclick="openReactivateModal(<?php echo htmlspecialchars(json_encode($p)); ?>)" class="btn-action btn-reactivate">
                                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                            </svg>
                                            Reactivate
                                        </button>
                                    <?php else: ?>
                                        <button onclick="openDeferModal(<?php echo htmlspecialchars(json_encode($p)); ?>)" class="btn-action btn-defer">
                                            <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                            </svg>
                                            Defer
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="result-info">
            Showing <?= count($pilgrims) ?> pilgrim(s)
        </div>
    <?php endif; ?>

</div>

<!-- Defer Modal -->
<div id="deferModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Defer Pilgrim</h2>
            <span class="close" onclick="closeDeferModal()">&times;</span>
        </div>
        
        <form action="admin_pilgrims.php" method="POST" id="deferForm">
            <input type="hidden" name="action" value="defer">
            <input type="hidden" name="pilgrim_id" id="defer_pilgrim_id">
            
            <div class="pilgrim-info" id="deferPilgrimInfo">
                <!-- Will be populated by JavaScript -->
            </div>
            
            <div class="form-group">
                <label for="defer_to_year">Select Year to Defer To *</label>
                <select name="defer_to_year" id="defer_to_year" required>
                    <option value="">-- Select Year --</option>
                    <?php foreach ($available_years as $year): ?>
                        <option value="<?php echo $year['hajj_year']; ?>">Year <?php echo $year['hajj_year']; ?></option>
                    <?php endforeach; ?>
                    <?php if (empty($available_years)): ?>
                        <option value="<?php echo date('Y') + 1; ?>">Year <?php echo date('Y') + 1; ?></option>
                        <option value="<?php echo date('Y') + 2; ?>">Year <?php echo date('Y') + 2; ?></option>
                        <option value="<?php echo date('Y') + 3; ?>">Year <?php echo date('Y') + 3; ?></option>
                    <?php endif; ?>
                </select>
            </div>
            
            
            
            <div class="modal-actions">
                <button type="button" class="btn btn-cancel" onclick="closeDeferModal()">Cancel</button>
                <button type="submit" class="btn btn-submit">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    Confirm Deferral
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Reactivate Modal -->
<div id="reactivateModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Reactivate Pilgrim</h2>
            <span class="close" onclick="closeReactivateModal()">&times;</span>
        </div>
        
        <form action="handle_defer_reactivate.php" method="POST" id="reactivateForm">
            <input type="hidden" name="action" value="reactivate">
            <input type="hidden" name="pilgrim_id" id="reactivate_pilgrim_id">
            
            <div class="pilgrim-info" id="reactivatePilgrimInfo">
                <!-- Will be populated by JavaScript -->
            </div>
            
            <div style="background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.3); border-radius: 8px; padding: 16px; margin: 0 32px 24px;">
                <p style="font-size: 0.9rem; color: #6ee7b7; margin: 0;">
                    <strong>✓ Confirm:</strong> This will reactivate the pilgrim and restore their status to active. All payment history and balance will remain intact.
                </p>
            </div>
            
            <div class="modal-actions">
                <button type="button" class="btn btn-cancel" onclick="closeReactivateModal()">Cancel</button>
                <button type="submit" class="btn btn-reactivate-submit">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    Reactivate Pilgrim
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const deferModal = document.getElementById('deferModal');
const reactivateModal = document.getElementById('reactivateModal');

// Defer Modal Functions
function openDeferModal(pilgrim) {
    document.getElementById('defer_pilgrim_id').value = pilgrim.id;
    
    const pilgrimInfo = `
        <div class="info-row">
            <span class="info-label">Name:</span>
            <span class="info-value">${pilgrim.first_name} ${pilgrim.surname}</span>
        </div>
        <div class="info-row">
            <span class="info-label">File Number:</span>
            <span class="info-value">${pilgrim.file_number || '—'}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Contact:</span>
            <span class="info-value">${pilgrim.contact || '—'}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Total Paid:</span>
            <span class="info-value" style="color: #34d399;">GHS ${parseFloat(pilgrim.total_paid).toLocaleString('en-US', {minimumFractionDigits: 2})}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Current Balance:</span>
            <span class="info-value" style="color: #fbbf24;">GHS ${parseFloat(pilgrim.balance).toLocaleString('en-US', {minimumFractionDigits: 2})}</span>
        </div>
    `;
    
    document.getElementById('deferPilgrimInfo').innerHTML = pilgrimInfo;
    deferModal.style.display = 'block';
}

function closeDeferModal() {
    deferModal.style.display = 'none';
    document.getElementById('deferForm').reset();
}

// Reactivate Modal Functions
function openReactivateModal(pilgrim) {
    document.getElementById('reactivate_pilgrim_id').value = pilgrim.id;
    
    const pilgrimInfo = `
        <div class="info-row">
            <span class="info-label">Name:</span>
            <span class="info-value">${pilgrim.first_name} ${pilgrim.surname}</span>
        </div>
        <div class="info-row">
            <span class="info-label">File Number:</span>
            <span class="info-value">${pilgrim.file_number || '—'}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Contact:</span>
            <span class="info-value">${pilgrim.contact || '—'}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Was Deferred To:</span>
            <span class="info-value" style="color: #fca5a5;">Year ${pilgrim.deferred_to_year || '—'}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Total Paid:</span>
            <span class="info-value" style="color: #34d399;">GHS ${parseFloat(pilgrim.total_paid).toLocaleString('en-US', {minimumFractionDigits: 2})}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Current Balance:</span>
            <span class="info-value" style="color: #fbbf24;">GHS ${parseFloat(pilgrim.balance).toLocaleString('en-US', {minimumFractionDigits: 2})}</span>
        </div>
    `;
    
    document.getElementById('reactivatePilgrimInfo').innerHTML = pilgrimInfo;
    reactivateModal.style.display = 'block';
}

function closeReactivateModal() {
    reactivateModal.style.display = 'none';
    document.getElementById('reactivateForm').reset();
}

// Close modals when clicking outside
window.onclick = function(event) {
    if (event.target == deferModal) {
        closeDeferModal();
    }
    if (event.target == reactivateModal) {
        closeReactivateModal();
    }
}

// Form submission confirmations
document.getElementById('deferForm').addEventListener('submit', function(e) {
    const year = document.getElementById('defer_to_year').value;
    if (!confirm(`Are you sure you want to defer this pilgrim to year ${year}?`)) {
        e.preventDefault();
    }
});

document.getElementById('reactivateForm').addEventListener('submit', function(e) {
    if (!confirm('Are you sure you want to reactivate this pilgrim?')) {
        e.preventDefault();
    }
});
</script>

</body>
</html>