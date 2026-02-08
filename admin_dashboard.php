<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

include 'config.php';

// === Key Statistics ===
$total_pilgrims = $conn->query("SELECT COUNT(*) as cnt FROM pilgrims")->fetch_assoc()['cnt'];
$active_pilgrims = $conn->query("SELECT COUNT(*) as cnt FROM pilgrims WHERE status = 'registered'")->fetch_assoc()['cnt'];
$deferred = $conn->query("SELECT COUNT(*) as cnt FROM pilgrims WHERE status = 'deferred'")->fetch_assoc()['cnt'];
$paid = $conn->query("SELECT COUNT(*) as cnt FROM pilgrims WHERE balance <= 0")->fetch_assoc()['cnt'];
$total_revenue = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE is_processing_fee = 0")->fetch_assoc()['total'];
$total_processing = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE is_processing_fee = 1")->fetch_assoc()['total'];

$today_start = date('Y-m-d 00:00:00');
$today_end = date('Y-m-d 23:59:59');
$today_payments = $conn->query("SELECT COALESCE(SUM(amount), 0) as today FROM payments 
                                WHERE payment_date BETWEEN '$today_start' AND '$today_end'")->fetch_assoc()['today'];

$this_month_start = date('Y-m-01 00:00:00');
$this_month_payments = $conn->query("SELECT COALESCE(SUM(amount), 0) as month FROM payments 
                                     WHERE payment_date >= '$this_month_start'")->fetch_assoc()['month'];

$pending_balance = $conn->query("SELECT COALESCE(SUM(balance), 0) as pending FROM pilgrims WHERE balance > 0 AND status != 'deferred'")->fetch_assoc()['pending'];

// Get active Hajj year costs
$active_cost_query = $conn->query("SELECT hajj_year, package_cost, processing_fee FROM hajj_costs WHERE is_active = 1 LIMIT 1");
$active_cost = $active_cost_query->num_rows > 0 ? $active_cost_query->fetch_assoc() : null;
$current_year = $active_cost ? $active_cost['hajj_year'] : date('Y');
$current_package = $active_cost ? (float)$active_cost['package_cost'] : 0.00;
$current_processing = $active_cost ? (float)$active_cost['processing_fee'] : 0.00;

// Payment stats by method
$payment_methods = $conn->query("
    SELECT payment_method, COUNT(*) as count, SUM(amount) as total 
    FROM payments 
    GROUP BY payment_method
")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Hajj Management</title>
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

        .welcome-banner {
            background: linear-gradient(135deg, rgba(59,130,246,0.2) 0%, rgba(30,41,59,0.8) 100%);
            border: 1px solid rgba(59,130,246,0.3);
            border-radius: 16px;
            padding: 32px;
            margin-bottom: 32px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.35);
        }

        .welcome-banner h1 {
            font-size: 2rem;
            margin-bottom: 8px;
            background: linear-gradient(135deg, #60a5fa, #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        .welcome-banner p {
            color: var(--muted);
            font-size: 1.1rem;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 24px;
            margin-bottom: 48px;
        }

        .stat-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.35);
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: var(--accent, var(--primary));
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0,0,0,0.45);
            border-color: var(--accent, var(--primary));
        }

        .stat-card.primary { --accent: #3b82f6; }
        .stat-card.success { --accent: #10b981; }
        .stat-card.warning { --accent: #f59e0b; }
        .stat-card.danger { --accent: #ef4444; }
        .stat-card.info { --accent: #06b6d4; }

        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(59,130,246,0.15);
            color: var(--accent, var(--primary));
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 500;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text);
            margin: 8px 0;
            letter-spacing: -0.5px;
        }

        .stat-trend {
            font-size: 0.85rem;
            color: var(--muted);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 48px 0 24px;
        }

        .section-title {
            font-size: 1.5rem;
            color: #94a3b8;
            font-weight: 600;
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 48px;
        }

        .action-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 24px;
            text-decoration: none;
            color: var(--text);
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .action-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0,0,0,0.45);
            border-color: var(--primary);
        }

        .action-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(59,130,246,0.15);
            color: #60a5fa;
            flex-shrink: 0;
        }

        .action-content h3 {
            font-size: 1.1rem;
            margin-bottom: 4px;
            color: var(--text);
        }

        .action-content p {
            font-size: 0.9rem;
            color: var(--muted);
            line-height: 1.4;
        }

        .recent-table {
            width: 100%;
            border-collapse: collapse;
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 8px 32px rgba(0,0,0,0.35);
        }

        .recent-table th {
            background: rgba(15,23,42,0.9);
            color: #cbd5e1;
            padding: 16px 20px;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
            text-align: left;
        }

        .recent-table td {
            padding: 16px 20px;
            border-top: 1px solid rgba(51,65,85,0.3);
        }

        .recent-table tbody tr {
            transition: all 0.2s;
        }

        .recent-table tbody tr:hover {
            background: rgba(59,130,246,0.08);
        }

        .empty-state {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 60px 30px;
            text-align: center;
            font-size: 1.1rem;
            color: var(--muted);
            box-shadow: 0 8px 32px rgba(0,0,0,0.35);
        }

        .payment-methods {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 48px;
        }

        .method-card {
            background: rgba(30,41,59,0.5);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
            text-align: center;
        }

        .method-name {
            font-size: 0.9rem;
            color: var(--muted);
            margin-bottom: 8px;
        }

        .method-amount {
            font-size: 1.4rem;
            font-weight: 700;
            color: #34d399;
        }

        .method-count {
            font-size: 0.8rem;
            color: var(--muted);
            margin-top: 4px;
        }

        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr; }
            .quick-actions { grid-template-columns: 1fr; }
            .stat-value { font-size: 1.8rem; }
            .welcome-banner h1 { font-size: 1.5rem; }
        }
    </style>
</head>
<body>

<header>
    <div class="inner">
        <h1>Ustaz Hassan Labaika Travel Agency</h1>
        <nav>
            <a href="admin_dashboard.php" class="active">Dashboard</a>
            <a href="admin_pilgrims.php">Pilgrims</a>
            <a href="admin_payments.php">Payments</a>
            <a href="admin_set_cost.php">Package Cost</a>
            <a href="admin_deferred.php">Deferred</a>
             <a href="admin_reports.php">Reports</a>
            <a href="admin_users.php">Users</a>
            <a href="logout.php" class="logout">Logout</a>
        </nav>
    </div>
</header>

<div class="container">

    <div class="welcome-banner">
        <h1>Welcome, <?php echo htmlspecialchars($_SESSION['full_name'] ?? 'Admin'); ?>!</h1>
        <p>Overview of Hajj payment for Year <?php echo $current_year; ?></p>
    </div>

    <!-- Statistics Grid -->
    <div class="stats-grid">
        <div class="stat-card primary">
            <div class="stat-header">
                <div>
                    <div class="stat-label">Total Pilgrims</div>
                    <div class="stat-value"><?php echo number_format($total_pilgrims); ?></div>
                </div>
                <div class="stat-icon">
                    <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                    </svg>
                </div>
            </div>
            <div class="stat-trend"><?php echo number_format($active_pilgrims); ?> active registrations</div>
        </div>

        <div class="stat-card success">
            <div class="stat-header">
                <div>
                    <div class="stat-label">Today's Collection</div>
                    <div class="stat-value">₵ <?php echo number_format($today_payments, 0); ?></div>
                </div>
                <div class="stat-icon">
                    <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
            <div class="stat-trend">This month: ₵ <?php echo number_format($this_month_payments, 0); ?></div>
        </div>

        <div class="stat-card success">
            <div class="stat-header">
                <div>
                    <div class="stat-label">Total Revenue</div>
                    <div class="stat-value">₵ <?php echo number_format($total_revenue, 0); ?></div>
                </div>
                <div class="stat-icon">
                    <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/>
                    </svg>
                </div>
            </div>
            <div class="stat-trend">Processing fees: ₵ <?php echo number_format($total_processing, 0); ?></div>
        </div>

        <div class="stat-card warning">
            <div class="stat-header">
                <div>
                    <div class="stat-label">Pending Balance</div>
                    <div class="stat-value">₵ <?php echo number_format($pending_balance, 0); ?></div>
                </div>
                <div class="stat-icon">
                    <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
            <div class="stat-trend">Outstanding from active pilgrims</div>
        </div>

        <div class="stat-card success">
            <div class="stat-header">
                <div>
                    <div class="stat-label">Fully Paid</div>
                    <div class="stat-value"><?php echo number_format($paid); ?></div>
                </div>
                <div class="stat-icon">
                    <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
            </div>
            <div class="stat-trend">Completed payments</div>
        </div>

        <div class="stat-card danger">
            <div class="stat-header">
                <div>
                    <div class="stat-label">Deferred Pilgrims</div>
                    <div class="stat-value"><?php echo number_format($deferred); ?></div>
                </div>
                <div class="stat-icon">
                    <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                    </svg>
                </div>
            </div>
            <div class="stat-trend">Postponed to future years</div>
        </div>

        <div class="stat-card info">
            <div class="stat-header">
                <div>
                    <div class="stat-label">Package Cost (<?php echo $current_year; ?>)</div>
                    <div class="stat-value">₵ <?php echo number_format($current_package, 0); ?></div>
                </div>
                <div class="stat-icon">
                    <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                    </svg>
                </div>
            </div>
            <div class="stat-trend">Processing fee: GHS <?php echo number_format($current_processing, 0); ?></div>
        </div>
    </div>

    <!-- Payment Methods Breakdown -->
    <?php if (!empty($payment_methods)): ?>
    <div class="section-header">
        <h2 class="section-title">Payment Methods Breakdown</h2>
    </div>
    <div class="payment-methods">
        <?php foreach ($payment_methods as $method): ?>
        <div class="method-card">
            <div class="method-name"><?php echo htmlspecialchars($method['payment_method']); ?></div>
            <div class="method-amount">GHS <?php echo number_format($method['total'], 0); ?></div>
            <div class="method-count"><?php echo number_format($method['count']); ?> transactions</div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Quick Actions -->
    <div class="section-header">
        <h2 class="section-title">Quick Actions</h2>
    </div>

    <div class="quick-actions">
        <a href="admin_pilgrims.php" class="action-card">
            <div class="action-icon">
                <svg width="28" height="28" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                </svg>
            </div>
            <div class="action-content">
                <h3>Manage Pilgrims</h3>
                <p>View, search, and manage all registered pilgrims</p>
            </div>
        </a>

        <a href="admin_payments.php" class="action-card">
            <div class="action-icon">
                <svg width="28" height="28" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                </svg>
            </div>
            <div class="action-content">
                <h3>Record Payments</h3>
                <p>View all payments and payment history</p>
            </div>
        </a>

        <a href="admin_deferred.php" class="action-card">
            <div class="action-icon">
                <svg width="28" height="28" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                </svg>
            </div>
            <div class="action-content">
                <h3>Deferred Pilgrims</h3>
                <p>Manage pilgrims postponed to future years</p>
            </div>
        </a>

        <a href="admin_set_cost.php" class="action-card">
            <div class="action-icon">
                <svg width="28" height="28" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
            </div>
            <div class="action-content">
                <h3>Manage Costs</h3>
                <p>Update Hajj package and processing fee costs</p>
            </div>
        </a>
    </div>

    <!-- Recent Payments -->
    <div class="section-header">
        <h2 class="section-title">Recent Payments</h2>
        <a href="admin_payments.php" style="color: #60a5fa; text-decoration: none; font-size: 0.95rem;">View All →</a>
    </div>

    <?php
    $recent = $conn->query("SELECT pay.amount, pay.payment_date, pay.payment_method, pay.is_processing_fee,
                            p.first_name, p.surname, p.file_number 
                            FROM payments pay 
                            JOIN pilgrims p ON pay.pilgrim_id = p.id 
                            ORDER BY pay.payment_date DESC LIMIT 8");
    if ($recent->num_rows > 0):
    ?>
    <table class="recent-table">
        <thead>
            <tr>
                <th>Date & Time</th>
                <th>Pilgrim Name</th>
                <th>File Number</th>
                <th>Method</th>
                <th>Type</th>
                <th>Amount</th>
            </tr>
        </thead>
        <tbody>
            <?php while ($row = $recent->fetch_assoc()): ?>
            <tr>
                <td><?php echo date('d M Y, h:i A', strtotime($row['payment_date'])); ?></td>
                <td><?php echo htmlspecialchars($row['first_name'] . ' ' . $row['surname']); ?></td>
                <td><?php echo htmlspecialchars($row['file_number'] ?? '—'); ?></td>
                <td><?php echo htmlspecialchars($row['payment_method']); ?></td>
                <td>
                    <?php if ($row['is_processing_fee']): ?>
                        <span style="color: #fbbf24;">Processing Fee</span>
                    <?php else: ?>
                        <span style="color: #34d399;">Hajj Payment</span>
                    <?php endif; ?>
                </td>
                <td><strong>GHS <?php echo number_format($row['amount'], 2); ?></strong></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
    <?php else: ?>
    <div class="empty-state">
        No payments recorded yet.
    </div>
    <?php endif; ?>

</div>

</body>
</html>