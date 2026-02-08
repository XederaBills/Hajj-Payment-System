<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

include 'config.php';

// Filters
$search = trim($_GET['search'] ?? '');
$payment_method = $_GET['payment_method'] ?? '';
$payment_type = $_GET['payment_type'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

$where = ['1=1']; // Always true condition to simplify logic
$params = [];
$types = '';

if ($search !== '') {
    $like = "%$search%";
    $where[] = "(p.first_name LIKE ? OR p.surname LIKE ? OR p.file_number LIKE ? OR pay.receipt_number LIKE ?)";
    $params = array_merge($params, [$like, $like, $like, $like]);
    $types .= 'ssss';
}

if ($payment_method !== '' && in_array($payment_method, ['Cash', 'Bank Transfer', 'Mobile Money', 'Other'])) {
    $where[] = "pay.payment_method = ?";
    $params[] = $payment_method;
    $types .= 's';
}

if ($payment_type !== '') {
    if ($payment_type === 'hajj') {
        $where[] = "pay.is_processing_fee = 0";
    } elseif ($payment_type === 'processing') {
        $where[] = "pay.is_processing_fee = 1";
    }
}

if ($date_from !== '') {
    $where[] = "DATE(pay.payment_date) >= ?";
    $params[] = $date_from;
    $types .= 's';
}

if ($date_to !== '') {
    $where[] = "DATE(pay.payment_date) <= ?";
    $params[] = $date_to;
    $types .= 's';
}

$where_clause = 'WHERE ' . implode(' AND ', $where);

// Get statistics
$total_payments = $conn->query("SELECT COUNT(*) as cnt FROM payments")->fetch_assoc()['cnt'];
$total_revenue = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE is_processing_fee = 0")->fetch_assoc()['total'];
$total_processing = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE is_processing_fee = 1")->fetch_assoc()['total'];

$today_start = date('Y-m-d 00:00:00');
$today_end = date('Y-m-d 23:59:59');
$today_payments = $conn->query("SELECT COALESCE(SUM(amount), 0) as today FROM payments 
                                WHERE payment_date BETWEEN '$today_start' AND '$today_end'")->fetch_assoc()['today'];

// Payment methods breakdown
$methods_query = $conn->query("
    SELECT payment_method, COUNT(*) as count, SUM(amount) as total 
    FROM payments 
    GROUP BY payment_method
");
$payment_methods_stats = $methods_query->fetch_all(MYSQLI_ASSOC);

// Main query
$stmt = $conn->prepare("
    SELECT 
        pay.id, 
        pay.amount, 
        pay.payment_date, 
        pay.payment_method, 
        pay.receipt_number, 
        pay.notes,
        pay.is_processing_fee,
        p.id AS pilgrim_id, 
        p.first_name, 
        p.surname, 
        p.file_number, 
        u.username AS recorded_by,
        u.full_name AS recorded_by_name
    FROM payments pay
    JOIN pilgrims p ON pay.pilgrim_id = p.id
    JOIN users u ON pay.recorded_by = u.id
    $where_clause
    ORDER BY pay.payment_date DESC
");

if ($params) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$payments = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Calculate filtered totals
$filtered_total = array_sum(array_column($payments, 'amount'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment Records - Admin</title>
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

        /* Payment Methods Breakdown */
        .methods-breakdown {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 32px;
            box-shadow: var(--shadow);
        }

        .methods-breakdown h3 {
            color: #60a5fa;
            margin-bottom: 20px;
            font-size: 1.2rem;
        }

        .methods-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
        }

        .method-item {
            background: rgba(30,41,59,0.5);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 16px;
            text-align: center;
        }

        .method-name {
            font-size: 0.85rem;
            color: var(--muted);
            margin-bottom: 8px;
        }

        .method-amount {
            font-size: 1.3rem;
            font-weight: 700;
            color: #34d399;
            margin-bottom: 4px;
        }

        .method-count {
            font-size: 0.75rem;
            color: var(--muted);
        }

        /* Filter Bar */
        .filter-bar {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 24px;
            margin-bottom: 32px;
            box-shadow: var(--shadow);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }

        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .filter-group label {
            color: var(--muted);
            font-size: 0.9rem;
            font-weight: 500;
        }

        input, select {
            padding: 10px 14px;
            background: #1e293b;
            color: var(--text);
            border: 1px solid #475569;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.2s;
        }

        input:focus, select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
        }

        .btn-group {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.25s;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
        }
        .btn-primary:hover {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            transform: translateY(-1px);
        }

        .btn-secondary {
            background: rgba(71,85,105,0.3);
            color: var(--text);
        }
        .btn-secondary:hover {
            background: rgba(71,85,105,0.5);
        }

        .btn-export {
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            color: white;
        }
        .btn-export:hover {
            background: linear-gradient(135deg, #7c3aed, #6d28d9);
            transform: translateY(-1px);
        }

        /* Table */
        .table-container {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 14px 18px;
            text-align: left;
        }

        th {
            background: rgba(15,23,42,0.9);
            color: #cbd5e1;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }

        td {
            border-top: 1px solid rgba(51,65,85,0.3);
        }

        tbody tr {
            transition: all 0.2s;
        }

        tbody tr:hover {
            background: rgba(59,130,246,0.08);
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .badge-hajj {
            background: rgba(16,185,129,0.2);
            color: #34d399;
        }

        .badge-processing {
            background: rgba(245,158,11,0.2);
            color: #fbbf24;
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .btn-action {
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

        .btn-print {
            background: rgba(16,185,129,0.2);
            color: #34d399;
        }
        .btn-print:hover {
            background: rgba(16,185,129,0.3);
            transform: translateY(-1px);
        }

        .btn-view {
            background: rgba(59,130,246,0.2);
            color: #60a5fa;
        }
        .btn-view:hover {
            background: rgba(59,130,246,0.3);
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
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: var(--muted);
            margin: 24px 0;
            font-size: 0.95rem;
            flex-wrap: wrap;
            gap: 12px;
        }

        .filtered-total {
            background: rgba(16,185,129,0.15);
            border: 1px solid rgba(16,185,129,0.3);
            padding: 8px 16px;
            border-radius: 8px;
            color: #34d399;
            font-weight: 600;
        }

        @media (max-width: 768px) {
            .filter-grid { grid-template-columns: 1fr; }
            .table-container { overflow-x: auto; }
            table { min-width: 1000px; }
            .stats-grid { grid-template-columns: 1fr; }
            .methods-grid { grid-template-columns: 1fr; }
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
            <a href="admin_payments.php" class="active">Payments</a>
            <a href="admin_set_cost.php">Package Cost</a>
            <a href="admin_deferred.php">Deferred</a>
             <a href="admin_reports.php">Reports</a>
            <a href="admin_users.php">Users</a>
            <a href="logout.php" class="logout">Logout</a>
        </nav>
    </div>
</header>

<div class="container">

    <h1 class="main-title">Payment Records</h1>

    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-label">Total Payments</div>
            <div class="stat-value"><?php echo number_format($total_payments); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Today's Collection</div>
            <div class="stat-value" style="color: #3e34d3ff;">₵ <?php echo number_format($today_payments, 0); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Revenue</div>
            <div class="stat-value" style="color: #34d399;">₵ <?php echo number_format($total_revenue, 0); ?></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Processing Fees</div>
            <div class="stat-value" style="color: #fbbf24;">₵ <?php echo number_format($total_processing, 0); ?></div>
        </div>
    </div>

    <!-- Payment Methods Breakdown -->
    <?php if (!empty($payment_methods_stats)): ?>
    <div class="methods-breakdown">
        <h3>Payment Methods Breakdown</h3>
        <div class="methods-grid">
            <?php foreach ($payment_methods_stats as $method): ?>
            <div class="method-item">
                <div class="method-name"><?php echo htmlspecialchars($method['payment_method']); ?></div>
                <div class="method-amount">GHS <?php echo number_format($method['total'], 0); ?></div>
                <div class="method-count"><?php echo number_format($method['count']); ?> transactions</div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filter Bar -->
    <div class="filter-bar">
        <form method="GET">
            <div class="filter-grid">
                <div class="filter-group">
                    <label>Search</label>
                    <input type="text" name="search" placeholder="Pilgrim name, file #, receipt #" value="<?= htmlspecialchars($search) ?>">
                </div>

                <div class="filter-group">
                    <label>Payment Method</label>
                    <select name="payment_method">
                        <option value="">All Methods</option>
                        <option value="Cash" <?= $payment_method === 'Cash' ? 'selected' : '' ?>>Cash</option>
                        <option value="Bank Transfer" <?= $payment_method === 'Bank Transfer' ? 'selected' : '' ?>>Bank Transfer</option>
                        <option value="Mobile Money" <?= $payment_method === 'Mobile Money' ? 'selected' : '' ?>>Mobile Money</option>
                        <option value="Other" <?= $payment_method === 'Other' ? 'selected' : '' ?>>Other</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Payment Type</label>
                    <select name="payment_type">
                        <option value="">All Types</option>
                        <option value="hajj" <?= $payment_type === 'hajj' ? 'selected' : '' ?>>Hajj Payment</option>
                        <option value="processing" <?= $payment_type === 'processing' ? 'selected' : '' ?>>Processing Fee</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label>Date From</label>
                    <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>">
                </div>

                <div class="filter-group">
                    <label>Date To</label>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>">
                </div>
            </div>

            <div class="btn-group">
                <button type="submit" class="btn btn-primary">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.293A1 1 0 013 6.586V4z"/>
                    </svg>
                    Filter
                </button>
                <button type="button" class="btn btn-secondary" onclick="location.href='admin_payments.php'">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                    </svg>
                    Clear
                </button>
                <button type="button" class="btn btn-export" onclick="exportTableToCSV('payments_report.csv')">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                    Export CSV
                </button>
            </div>
        </form>
    </div>

    <!-- Payments Table -->
    <?php if (empty($payments)): ?>
        <div class="table-container">
            <div class="empty-state">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                </svg>
                <h3>No Payments Found</h3>
                <p>No payment records match your search criteria.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="table-container">
            <table id="paymentsTable">
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>Pilgrim Name</th>
                        <th>File #</th>
                        <th>Amount (GHS)</th>
                        <th>Method</th>
                        <th>Type</th>
                        <th>Receipt #</th>
                        <th>Recorded By</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $pay): ?>
                        <tr>
                            <td><?= date('d M Y, h:i A', strtotime($pay['payment_date'])) ?></td>
                            <td><strong><?= htmlspecialchars($pay['first_name'] . ' ' . $pay['surname']) ?></strong></td>
                            <td><?= htmlspecialchars($pay['file_number'] ?? '—') ?></td>
                            <td style="color: #34d399; font-weight: 600;">₵ <?= number_format($pay['amount'], 2) ?></td>
                            <td><?= htmlspecialchars($pay['payment_method']) ?></td>
                            <td>
                                <?php if ($pay['is_processing_fee']): ?>
                                    <span class="badge badge-processing">Processing Fee</span>
                                <?php else: ?>
                                    <span class="badge badge-hajj">Hajj Payment</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($pay['receipt_number'] ?? '—') ?></td>
                            <td><?= htmlspecialchars($pay['recorded_by_name'] ?? $pay['recorded_by']) ?></td>
                            <td>
                                <div class="action-buttons">
                                    <a href="print_receipt.php?id=<?= $pay['id'] ?>" 
                                       target="_blank" 
                                       class="btn-action btn-print">
                                        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                                        </svg>
                                        Receipt
                                    </a>
                                    <a href="admin_pilgrim_view.php?id=<?= $pay['pilgrim_id'] ?>" 
                                       class="btn-action btn-view">
                                        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                        </svg>
                                        View
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="result-info">
            <span>Showing <?= count($payments) ?> payment record(s)</span>
            <span class="filtered-total">Filtered Total: GHS <?= number_format($filtered_total, 2) ?></span>
        </div>
    <?php endif; ?>

</div>

<script>
function exportTableToCSV(filename) {
    const table = document.getElementById('paymentsTable');
    if (!table) return;

    let csv = [];
    const rows = table.querySelectorAll('tr');
    
    rows.forEach(row => {
        const rowData = [];
        const cols = row.querySelectorAll('th, td');
        cols.forEach((col, index) => {
            // Skip the last column (Actions)
            if (index === cols.length - 1) return;
            
            let text = col.innerText.trim().replace(/(\r\n|\n|\r)/gm, ' ');
            if (text.includes(',') || text.includes('"')) {
                text = '"' + text.replace(/"/g, '""') + '"';
            }
            rowData.push(text);
        });
        if (rowData.length > 0) {
            csv.push(rowData.join(','));
        }
    });

    // Add summary row
    csv.push('');
    csv.push(`Total Records,<?= count($payments) ?>`);
    csv.push(`Total Amount,₵ <?= number_format($filtered_total, 2) ?>`);

    const csvContent = csv.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = filename;
    link.style.display = 'none';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

</body>
</html>