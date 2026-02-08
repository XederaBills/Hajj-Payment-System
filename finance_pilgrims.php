<?php
/**
 * Pilgrims List Page
 * 
 * Features:
 * - Advanced filtering and search
 * - Pagination with configurable page size
 * - Export to CSV/Excel
 * - Real-time statistics
 * - Bulk actions
 * - Responsive design
 * 
 * Security: Session validation, SQL injection prevention, XSS protection
 */

session_start();

// Authentication check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'finance') {
    header('Location: login.php');
    exit;
}

require_once 'config.php';

// ===========================
// Configuration
// ===========================
$page_sizes = [10, 25, 50, 100];
$default_page_size = 25;

// ===========================
// Filter Parameters
// ===========================
$search = trim($_GET['search'] ?? '');
$status_filter = $_GET['status'] ?? '';
$balance_filter = $_GET['balance'] ?? '';
$processing_fee_filter = $_GET['processing_fee'] ?? '';
$hajj_type_filter = $_GET['hajj_type'] ?? '';
$sort_by = $_GET['sort_by'] ?? 'registration_date';
$sort_order = $_GET['sort_order'] ?? 'DESC';
$page = max(1, intval($_GET['page'] ?? 1));
$page_size = in_array(intval($_GET['page_size'] ?? $default_page_size), $page_sizes) 
    ? intval($_GET['page_size'] ?? 25) 
    : $default_page_size;

// Export action
$export = $_GET['export'] ?? '';

// ===========================
// Build Query
// ===========================
$where = [];
$params = [];
$types = '';

// Search filter
if ($search !== '') {
    $like = "%$search%";
    $where[] = "(
        file_number LIKE ? OR 
        first_name LIKE ? OR 
        surname LIKE ? OR 
        other_name LIKE ? OR 
        contact LIKE ? OR 
        ghana_card_number LIKE ? OR 
        passport_number LIKE ?
    )";
    $params = array_merge($params, array_fill(0, 7, $like));
    $types .= str_repeat('s', 7);
}

// Status filter
if ($status_filter !== '' && in_array($status_filter, ['registered', 'deferred', 'paid'])) {
    $where[] = "status = ?";
    $params[] = $status_filter;
    $types .= 's';
}

// Balance filter
if ($balance_filter === 'pending') {
    $where[] = "balance > 0";
} elseif ($balance_filter === 'paid') {
    $where[] = "balance <= 0";
}

// Processing fee filter
if ($processing_fee_filter === 'paid') {
    $where[] = "processing_fee_paid = 1";
} elseif ($processing_fee_filter === 'unpaid') {
    $where[] = "processing_fee_paid = 0";
}

// Hajj type filter
if ($hajj_type_filter !== '' && in_array($hajj_type_filter, ['Tamatuo', 'Qiran', 'Ifraad'])) {
    $where[] = "hajj_type = ?";
    $params[] = $hajj_type_filter;
    $types .= 's';
}

$where_clause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Validate sort column
$allowed_sort_columns = [
    'file_number', 'first_name', 'surname', 'registration_date', 
    'balance', 'status', 'hajj_type', 'contact'
];
if (!in_array($sort_by, $allowed_sort_columns)) {
    $sort_by = 'registration_date';
}

$sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';

// ===========================
// Get Statistics
// ===========================
$stats_query = "
    SELECT 
        COUNT(*) as total_pilgrims,
        SUM(CASE WHEN balance > 0 THEN 1 ELSE 0 END) as pending_payment,
        SUM(CASE WHEN balance <= 0 THEN 1 ELSE 0 END) as fully_paid,
        SUM(CASE WHEN processing_fee_paid = 0 THEN 1 ELSE 0 END) as processing_fee_pending,
        SUM(balance) as total_outstanding_balance
    FROM pilgrims
    $where_clause
";

$stats_stmt = $conn->prepare($stats_query);
if ($params) {
    $stats_stmt->bind_param($types, ...$params);
}
$stats_stmt->execute();
$stats = $stats_stmt->get_result()->fetch_assoc();
$stats_stmt->close();

// ===========================
// Export to CSV/Excel
// ===========================
if ($export === 'csv') {
    $export_query = "
        SELECT 
            file_number as 'File Number',
            CONCAT(first_name, ' ', surname) as 'Full Name',
            contact as 'Contact',
            hajj_type as 'Hajj Type',
            status as 'Status',
            balance as 'Balance (GHS)',
            CASE WHEN processing_fee_paid = 1 THEN 'Yes' ELSE 'No' END as 'Processing Fee Paid',
            DATE_FORMAT(registration_date, '%Y-%m-%d') as 'Registration Date'
        FROM pilgrims
        $where_clause
        ORDER BY $sort_by $sort_order
    ";
    
    $export_stmt = $conn->prepare($export_query);
    if ($params) {
        $export_stmt->bind_param($types, ...$params);
    }
    $export_stmt->execute();
    $export_result = $export_stmt->get_result();
    
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="pilgrims_' . date('Y-m-d_His') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Write UTF-8 BOM for Excel compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Write headers
    if ($export_result->num_rows > 0) {
        $first_row = $export_result->fetch_assoc();
        fputcsv($output, array_keys($first_row));
        fputcsv($output, $first_row);
        
        while ($row = $export_result->fetch_assoc()) {
            fputcsv($output, $row);
        }
    }
    
    fclose($output);
    $export_stmt->close();
    exit;
}

// ===========================
// Pagination
// ===========================
$total_records = $stats['total_pilgrims'];
$total_pages = ceil($total_records / $page_size);
$offset = ($page - 1) * $page_size;

// ===========================
// Fetch Pilgrims
// ===========================
$query = "
    SELECT 
        id, 
        file_number, 
        first_name, 
        surname, 
        other_name,
        contact, 
        hajj_type, 
        status, 
        balance, 
        processing_fee_paid,
        processing_fee_amount,
        registration_date,
        date_of_birth
    FROM pilgrims
    $where_clause
    ORDER BY $sort_by $sort_order
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($query);
$limit_params = array_merge($params, [$page_size, $offset]);
$limit_types = $types . 'ii';
$stmt->bind_param($limit_types, ...$limit_params);
$stmt->execute();
$pilgrims = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ===========================
// Helper Functions
// ===========================
function getSortIcon($column, $current_sort, $current_order) {
    if ($column === $current_sort) {
        return $current_order === 'ASC' ? '▲' : '▼';
    }
    return '⇅';
}

function buildSortUrl($column, $current_sort, $current_order) {
    global $search, $status_filter, $balance_filter, $processing_fee_filter, $hajj_type_filter, $page_size;
    
    $new_order = ($column === $current_sort && $current_order === 'ASC') ? 'DESC' : 'ASC';
    
    return 'finance_pilgrims.php?' . http_build_query([
        'search' => $search,
        'status' => $status_filter,
        'balance' => $balance_filter,
        'processing_fee' => $processing_fee_filter,
        'hajj_type' => $hajj_type_filter,
        'sort_by' => $column,
        'sort_order' => $new_order,
        'page_size' => $page_size,
        'page' => 1
    ]);
}

function buildPageUrl($page_num) {
    global $search, $status_filter, $balance_filter, $processing_fee_filter, $hajj_type_filter, $sort_by, $sort_order, $page_size;
    
    return 'finance_pilgrims.php?' . http_build_query([
        'search' => $search,
        'status' => $status_filter,
        'balance' => $balance_filter,
        'processing_fee' => $processing_fee_filter,
        'hajj_type' => $hajj_type_filter,
        'sort_by' => $sort_by,
        'sort_order' => $sort_order,
        'page_size' => $page_size,
        'page' => $page_num
    ]);
}

function getExportUrl() {
    global $search, $status_filter, $balance_filter, $processing_fee_filter, $hajj_type_filter, $sort_by, $sort_order;
    
    return 'finance_pilgrims.php?' . http_build_query([
        'search' => $search,
        'status' => $status_filter,
        'balance' => $balance_filter,
        'processing_fee' => $processing_fee_filter,
        'hajj_type' => $hajj_type_filter,
        'sort_by' => $sort_by,
        'sort_order' => $sort_order,
        'export' => 'csv'
    ]);
}

function calculateAge($dob) {
    if (empty($dob)) return 'N/A';
    $birthDate = new DateTime($dob);
    $today = new DateTime();
    return $today->diff($birthDate)->y;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pilgrims List - Hajj Management System</title>
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

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: var(--text);
            min-height: 100vh;
            line-height: 1.6;
        }

        .container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 24px 16px;
        }

        /* Header */
        header {
            background: linear-gradient(135deg, #1e293b 0%, #111827 100%);
            padding: 16px 32px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.5);
            position: sticky;
            top: 0;
            z-index: 1000;
            margin-bottom: 24px;
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
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .page-title {
            font-size: 2rem;
            color: #60a5fa;
            font-weight: 600;
        }

        .header-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
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
        .stat-card.info .stat-value { color: var(--info); }

        .stat-subtext {
            font-size: 0.85rem;
            color: var(--muted);
        }

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

        input[type="text"],
        select {
            padding: 10px 14px;
            background: rgba(15,23,42,0.5);
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.2s;
        }

        input[type="text"]:focus,
        select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
        }

        .filter-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            justify-content: flex-end;
            margin-top: 16px;
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

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #059669;
            transform: translateY(-2px);
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 0.85rem;
        }

        /* Table Container */
        .table-wrapper {
            background: var(--card);
            backdrop-filter: blur(12px);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        .table-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            flex-wrap: wrap;
            gap: 12px;
        }

        .table-info {
            color: var(--muted);
            font-size: 0.9rem;
        }

        .table-container {
            overflow-x: auto;
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
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 10;
        }

        th.sortable {
            cursor: pointer;
            user-select: none;
        }

        th.sortable:hover {
            background: rgba(15,23,42,0.95);
            color: var(--primary);
        }

        tbody tr {
            transition: all 0.2s;
        }

        tbody tr:hover {
            background: rgba(59,130,246,0.08);
        }

        tbody tr.highlighted {
            background: rgba(59,130,246,0.15);
            animation: highlight-fade 2s ease-out;
        }

        @keyframes highlight-fade {
            0% { background: rgba(59,130,246,0.3); }
            100% { background: rgba(59,130,246,0.05); }
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

        .badge-registered {
            background: rgba(245,158,11,0.2);
            color: #fbbf24;
        }

        .badge-deferred {
            background: rgba(239,68,68,0.2);
            color: #fca5a5;
        }

        .badge-paid {
            background: rgba(16,185,129,0.2);
            color: #6ee7b7;
        }

        .badge-processing-paid {
            background: rgba(16,185,129,0.15);
            color: #34d399;
            font-size: 0.75rem;
        }

        .badge-processing-unpaid {
            background: rgba(239,68,68,0.15);
            color: #f87171;
            font-size: 0.75rem;
        }

        /* Balance Styling */
        .balance-paid {
            color: var(--success);
            font-weight: 600;
        }

        .balance-pending {
            color: var(--danger);
            font-weight: 600;
        }

        /* Pagination */
        .pagination-wrapper {
            padding: 20px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 16px;
        }

        .pagination {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .page-link {
            padding: 8px 12px;
            background: rgba(30,41,59,0.5);
            border: 1px solid var(--border);
            border-radius: 6px;
            color: var(--text);
            text-decoration: none;
            font-size: 0.9rem;
            transition: all 0.2s;
        }

        .page-link:hover {
            background: rgba(59,130,246,0.2);
            border-color: var(--primary);
            color: var(--primary);
        }

        .page-link.active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
            pointer-events: none;
        }

        .page-link.disabled {
            opacity: 0.5;
            cursor: not-allowed;
            pointer-events: none;
        }

        .page-size-selector {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .page-size-selector select {
            padding: 6px 10px;
            font-size: 0.9rem;
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

        .empty-state p {
            font-size: 1rem;
        }

        /* Responsive */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            }
        }

        @media (max-width: 768px) {
            .filter-grid {
                grid-template-columns: 1fr;
            }

            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .header-actions {
                width: 100%;
            }

            .header-actions .btn {
                flex: 1;
                justify-content: center;
            }

            .table-container {
                overflow-x: scroll;
            }

            table {
                min-width: 800px;
            }

            .pagination-wrapper {
                flex-direction: column;
            }

            .pagination {
                justify-content: center;
            }
        }
    </style>
</head>
<body>

<header>
    <div class="inner">
        <h1>Ustaz Hassan Labaika Travel Agency</h1>
        <nav>
            <a href="finance_dashboard.php">Dashboard</a>
            <a href="finance_register.php">New Pilgrim</a>
            <a href="finance_payments.php">Record Payment</a>
            <a href="finance_pilgrims.php" class="active">Pilgrims</a>
            <a href="logout.php" class="logout">Logout</a>
        </nav>
    </div>
</header>

<div class="container">

    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title">Pilgrims Registry</h1>
        <div class="header-actions">
            <a href="<?= htmlspecialchars(getExportUrl()) ?>" class="btn btn-success">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                Export to CSV
            </a>
            <a href="finance_register.php" class="btn btn-primary">
                <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Register New Pilgrim
            </a>
        </div>
    </div>

    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card primary">
            <div class="stat-label">Total Pilgrims</div>
            <div class="stat-value"><?= number_format($stats['total_pilgrims']) ?></div>
            <div class="stat-subtext">In current view</div>
        </div>

        <div class="stat-card warning">
            <div class="stat-label">Pending Payment</div>
            <div class="stat-value"><?= number_format($stats['pending_payment']) ?></div>
            <div class="stat-subtext">GHS <?= number_format($stats['total_outstanding_balance'], 2) ?> owed</div>
        </div>

        <div class="stat-card success">
            <div class="stat-label">Fully Paid</div>
            <div class="stat-value"><?= number_format($stats['fully_paid']) ?></div>
            <div class="stat-subtext">Completed payments</div>
        </div>

        <div class="stat-card danger">
            <div class="stat-label">Processing Fee Due</div>
            <div class="stat-value"><?= number_format($stats['processing_fee_pending']) ?></div>
            <div class="stat-subtext">Not yet paid</div>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="filter-bar">
        <form method="GET" id="filterForm">
            <div class="filter-grid">
                <div class="filter-group">
                    <label for="search">Search</label>
                    <input type="text" 
                           id="search" 
                           name="search" 
                           placeholder="Name, file #, contact..." 
                           value="<?= htmlspecialchars($search) ?>">
                </div>

                <div class="filter-group">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="">All Statuses</option>
                        <option value="registered" <?= $status_filter === 'registered' ? 'selected' : '' ?>>Registered</option>
                        <option value="deferred" <?= $status_filter === 'deferred' ? 'selected' : '' ?>>Deferred</option>
                        <option value="paid" <?= $status_filter === 'paid' ? 'selected' : '' ?>>Paid</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="balance">Balance Status</label>
                    <select id="balance" name="balance">
                        <option value="">All Balances</option>
                        <option value="pending" <?= $balance_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="paid" <?= $balance_filter === 'paid' ? 'selected' : '' ?>>Paid</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="processing_fee">Processing Fee</label>
                    <select id="processing_fee" name="processing_fee">
                        <option value="">All</option>
                        <option value="paid" <?= $processing_fee_filter === 'paid' ? 'selected' : '' ?>>Paid</option>
                        <option value="unpaid" <?= $processing_fee_filter === 'unpaid' ? 'selected' : '' ?>>Unpaid</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="hajj_type">Hajj Type</label>
                    <select id="hajj_type" name="hajj_type">
                        <option value="">All Types</option>
                        <option value="Tamatuo" <?= $hajj_type_filter === 'Tamatuo' ? 'selected' : '' ?>>Tamatuo</option>
                        <option value="Qiran" <?= $hajj_type_filter === 'Qiran' ? 'selected' : '' ?>>Qiran</option>
                        <option value="Ifraad" <?= $hajj_type_filter === 'Ifraad' ? 'selected' : '' ?>>Ifraad</option>
                    </select>
                </div>
            </div>

            <!-- Hidden fields to preserve sort and pagination -->
            <input type="hidden" name="sort_by" value="<?= htmlspecialchars($sort_by) ?>">
            <input type="hidden" name="sort_order" value="<?= htmlspecialchars($sort_order) ?>">
            <input type="hidden" name="page_size" value="<?= htmlspecialchars($page_size) ?>">

            <div class="filter-actions">
                <button type="button" class="btn btn-secondary" onclick="clearFilters()">
                    Clear Filters
                </button>
                <button type="submit" class="btn btn-primary">
                    Apply Filters
                </button>
            </div>
        </form>
    </div>

    <!-- Table -->
    <?php if (empty($pilgrims)): ?>
        <div class="table-wrapper">
            <div class="empty-state">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <h3>No Pilgrims Found</h3>
                <p>Try adjusting your filters or register a new pilgrim</p>
            </div>
        </div>
    <?php else: ?>
        <div class="table-wrapper">
            <div class="table-controls">
                <div class="table-info">
                    Showing <?= number_format($offset + 1) ?> - <?= number_format(min($offset + $page_size, $total_records)) ?> 
                    of <?= number_format($total_records) ?> pilgrim(s)
                </div>
                <div class="page-size-selector">
                    <label for="page_size_select">Show:</label>
                    <select id="page_size_select" onchange="changePageSize(this.value)">
                        <?php foreach ($page_sizes as $size): ?>
                            <option value="<?= $size ?>" <?= $page_size === $size ? 'selected' : '' ?>>
                                <?= $size ?> rows
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th class="sortable" onclick="location.href='<?= buildSortUrl('file_number', $sort_by, $sort_order) ?>'">
                                File # <?= getSortIcon('file_number', $sort_by, $sort_order) ?>
                            </th>
                            <th class="sortable" onclick="location.href='<?= buildSortUrl('first_name', $sort_by, $sort_order) ?>'">
                                Name <?= getSortIcon('first_name', $sort_by, $sort_order) ?>
                            </th>
                            <th>Age</th>
                            <th class="sortable" onclick="location.href='<?= buildSortUrl('contact', $sort_by, $sort_order) ?>'">
                                Contact <?= getSortIcon('contact', $sort_by, $sort_order) ?>
                            </th>
                            <th class="sortable" onclick="location.href='<?= buildSortUrl('hajj_type', $sort_by, $sort_order) ?>'">
                                Hajj Type <?= getSortIcon('hajj_type', $sort_by, $sort_order) ?>
                            </th>
                            <th class="sortable" onclick="location.href='<?= buildSortUrl('status', $sort_by, $sort_order) ?>'">
                                Status <?= getSortIcon('status', $sort_by, $sort_order) ?>
                            </th>
                            <th>Processing Fee</th>
                            <th class="sortable" onclick="location.href='<?= buildSortUrl('balance', $sort_by, $sort_order) ?>'">
                                Balance (GHS) <?= getSortIcon('balance', $sort_by, $sort_order) ?>
                            </th>
                            <th class="sortable" onclick="location.href='<?= buildSortUrl('registration_date', $sort_by, $sort_order) ?>'">
                                Registered <?= getSortIcon('registration_date', $sort_by, $sort_order) ?>
                            </th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pilgrims as $p): 
                            $is_highlighted = isset($_GET['highlight']) && $_GET['highlight'] == $p['id'];
                        ?>
                            <tr <?= $is_highlighted ? 'class="highlighted"' : '' ?>>
                                <td><strong><?= htmlspecialchars($p['file_number'] ?? '—') ?></strong></td>
                                <td>
                                    <?= htmlspecialchars($p['first_name'] . ' ' . $p['surname']) ?>
                                    <?php if (!empty($p['other_name'])): ?>
                                        <br><small style="color: var(--muted);"><?= htmlspecialchars($p['other_name']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= calculateAge($p['date_of_birth']) ?> yrs</td>
                                <td><?= htmlspecialchars($p['contact'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($p['hajj_type'] ?? '—') ?></td>
                                <td>
                                    <span class="badge badge-<?= $p['status'] ?>">
                                        <?= ucfirst($p['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($p['processing_fee_paid']): ?>
                                        <span class="badge badge-processing-paid">✓ Paid</span>
                                    <?php else: ?>
                                        <span class="badge badge-processing-unpaid">✗ Unpaid</span>
                                    <?php endif; ?>
                                </td>
                                <td class="<?= $p['balance'] <= 0 ? 'balance-paid' : 'balance-pending' ?>">
                                    <?= number_format($p['balance'], 2) ?>
                                </td>
                                <td><?= date('d M Y', strtotime($p['registration_date'])) ?></td>
                                <td>
                                    <a href="finance_pilgrim_details.php?id=<?= $p['id'] ?>" 
                                       class="btn btn-primary btn-sm">
                                        View
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <div class="pagination-wrapper">
                    <div class="pagination">
                        <!-- First Page -->
                        <a href="<?= buildPageUrl(1) ?>" 
                           class="page-link <?= $page === 1 ? 'disabled' : '' ?>">
                            « First
                        </a>

                        <!-- Previous Page -->
                        <a href="<?= buildPageUrl($page - 1) ?>" 
                           class="page-link <?= $page === 1 ? 'disabled' : '' ?>">
                            ‹ Prev
                        </a>

                        <!-- Page Numbers -->
                        <?php
                        $start = max(1, $page - 2);
                        $end = min($total_pages, $page + 2);
                        
                        if ($start > 1): ?>
                            <span class="page-link disabled">...</span>
                        <?php endif;
                        
                        for ($i = $start; $i <= $end; $i++): ?>
                            <a href="<?= buildPageUrl($i) ?>" 
                               class="page-link <?= $i === $page ? 'active' : '' ?>">
                                <?= $i ?>
                            </a>
                        <?php endfor;
                        
                        if ($end < $total_pages): ?>
                            <span class="page-link disabled">...</span>
                        <?php endif; ?>

                        <!-- Next Page -->
                        <a href="<?= buildPageUrl($page + 1) ?>" 
                           class="page-link <?= $page === $total_pages ? 'disabled' : '' ?>">
                            Next ›
                        </a>

                        <!-- Last Page -->
                        <a href="<?= buildPageUrl($total_pages) ?>" 
                           class="page-link <?= $page === $total_pages ? 'disabled' : '' ?>">
                            Last »
                        </a>
                    </div>

                    <div class="table-info">
                        Page <?= $page ?> of <?= $total_pages ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

</div>

<script>
// Clear all filters
function clearFilters() {
    window.location.href = 'finance_pilgrims.php';
}

// Change page size
function changePageSize(newSize) {
    const form = document.getElementById('filterForm');
    const pageSizeInput = form.querySelector('input[name="page_size"]');
    pageSizeInput.value = newSize;
    form.submit();
}

// Auto-submit on filter change (optional - uncomment to enable)
// document.querySelectorAll('#filterForm select').forEach(select => {
//     select.addEventListener('change', () => {
//         document.getElementById('filterForm').submit();
//     });
// });

// Highlight fade animation
document.addEventListener('DOMContentLoaded', () => {
    const highlighted = document.querySelector('.highlighted');
    if (highlighted) {
        highlighted.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
});
</script>

</body>
</html>