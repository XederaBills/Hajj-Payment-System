<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

include 'config.php';

// ── Core Statistics ──────────────────────────────────────────────────────
$total_pilgrims = $conn->query("SELECT COUNT(*) as cnt FROM pilgrims")->fetch_assoc()['cnt'] ?? 0;
$paid_pilgrims  = $conn->query("SELECT COUNT(*) as cnt FROM pilgrims WHERE status = 'paid'")->fetch_assoc()['cnt'] ?? 0;
$deferred       = $conn->query("SELECT COUNT(*) as cnt FROM pilgrims WHERE status = 'deferred'")->fetch_assoc()['cnt'] ?? 0;
$pending_hajj   = $conn->query("SELECT COUNT(*) as cnt FROM pilgrims WHERE balance > 0 AND status != 'deferred'")->fetch_assoc()['cnt'] ?? 0;
$registered_status = $conn->query("SELECT COUNT(*) as cnt FROM pilgrims WHERE status = 'registered'")->fetch_assoc()['cnt'] ?? 0;

// Financial stats
$total_revenue  = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE is_processing_fee = 0")->fetch_assoc()['total'] ?? 0;
$total_processing_collected = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE is_processing_fee = 1")->fetch_assoc()['total'] ?? 0;
$pending_processing = $conn->query("SELECT COUNT(*) as cnt FROM pilgrims WHERE processing_fee_paid = 0 AND status != 'deferred'")->fetch_assoc()['cnt'] ?? 0;

// Calculate pending balance
$pending_balance = $conn->query("SELECT COALESCE(SUM(balance), 0) as total FROM pilgrims WHERE balance > 0 AND status != 'deferred'")->fetch_assoc()['total'] ?? 0;

// Today's stats
$today_start = date('Y-m-d 00:00:00');
$today_end   = date('Y-m-d 23:59:59');
$today_revenue = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments 
                               WHERE payment_date BETWEEN '$today_start' AND '$today_end'")->fetch_assoc()['total'] ?? 0;

$today_payments_count = $conn->query("SELECT COUNT(*) as cnt FROM payments 
                                      WHERE payment_date BETWEEN '$today_start' AND '$today_end'")->fetch_assoc()['cnt'] ?? 0;

// This month stats
$this_month_start = date('Y-m-01 00:00:00');
$month_revenue = $conn->query("SELECT COALESCE(SUM(amount), 0) as total FROM payments 
                               WHERE payment_date >= '$this_month_start'")->fetch_assoc()['total'] ?? 0;

// Payment methods breakdown
$payment_methods = $conn->query("
    SELECT payment_method, COUNT(*) as count, SUM(amount) as total 
    FROM payments 
    GROUP BY payment_method 
    ORDER BY total DESC
")->fetch_all(MYSQLI_ASSOC);

// Hajj type breakdown
$hajj_types = $conn->query("
    SELECT hajj_type, COUNT(*) as count 
    FROM pilgrims 
    WHERE status != 'deferred'
    GROUP BY hajj_type
")->fetch_all(MYSQLI_ASSOC);

// Recent registrations (last 7 days)
$week_ago = date('Y-m-d 00:00:00', strtotime('-7 days'));
$recent_registrations = $conn->query("SELECT COUNT(*) as cnt FROM pilgrims 
                                      WHERE registration_date >= '$week_ago'")->fetch_assoc()['cnt'] ?? 0;

// Current package cost
$cost_row = $conn->query("SELECT value FROM configurations WHERE key_name = 'current_hajj_cost' LIMIT 1");
$current_cost = $cost_row->num_rows > 0 ? (float)$cost_row->fetch_assoc()['value'] : 0.00;

// Finance officer stats
$finance_officers = $conn->query("
    SELECT u.username, u.full_name, COUNT(p.id) as payment_count, COALESCE(SUM(p.amount), 0) as total_collected
    FROM users u
    LEFT JOIN payments p ON u.id = p.recorded_by
    WHERE u.role = 'finance' AND u.status = 'active'
    GROUP BY u.id, u.username, u.full_name
    ORDER BY total_collected DESC
")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Reports - Hajj Management System</title>
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

        .page-header {
            text-align: center;
            margin: 32px 0 40px;
        }

        .page-header h1 {
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(135deg, #60a5fa, #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 8px;
        }

        .page-header p {
            color: var(--muted);
            font-size: 1rem;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 24px;
            box-shadow: var(--shadow);
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
        }

        .stat-card.primary::before {
            background: linear-gradient(90deg, var(--primary), var(--info));
        }

        .stat-card.success::before {
            background: linear-gradient(90deg, #10b981, #34d399);
        }

        .stat-card.warning::before {
            background: linear-gradient(90deg, #f59e0b, #fbbf24);
        }

        .stat-card.danger::before {
            background: linear-gradient(90deg, #ef4444, #f87171);
        }

        .stat-card.info::before {
            background: linear-gradient(90deg, #06b6d4, #22d3ee);
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
            font-weight: 500;
        }

        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 4px;
        }

        .stat-trend {
            font-size: 0.8rem;
            color: var(--muted);
        }

        /* Section */
        .section {
            margin: 48px 0;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 16px;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title svg {
            width: 24px;
            height: 24px;
            color: var(--primary);
        }

        /* Quick Links */
        .quick-links {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 48px;
        }

        .link-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 28px 24px;
            text-decoration: none;
            color: var(--text);
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
        }

        .link-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0,0,0,0.45);
            border-color: var(--primary);
        }

        .link-card-icon {
            width: 64px;
            height: 64px;
            background: rgba(59,130,246,0.15);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 16px;
        }

        .link-card-icon svg {
            width: 32px;
            height: 32px;
            color: var(--primary);
        }

        .link-card h3 {
            font-size: 1.2rem;
            margin-bottom: 8px;
            color: var(--text);
        }

        .link-card p {
            color: var(--muted);
            font-size: 0.9rem;
        }

        /* Tables */
        .table-wrapper {
            overflow-x: auto;
            border-radius: var(--radius);
            background: var(--card);
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 16px 20px;
            text-align: left;
            border-bottom: 1px solid rgba(51,65,85,0.3);
        }

        th {
            background: rgba(15,23,42,0.7);
            color: var(--muted);
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.5px;
        }

        tbody tr {
            transition: background 0.2s;
        }

        tbody tr:hover {
            background: rgba(59,130,246,0.08);
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--muted);
        }

        .empty-state svg {
            width: 64px;
            height: 64px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        /* Breakdown Cards */
        .breakdown-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 24px;
            margin-bottom: 40px;
        }

        .breakdown-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 24px;
            box-shadow: var(--shadow);
        }

        .breakdown-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 20px;
            color: var(--text);
        }

        .breakdown-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid rgba(51,65,85,0.3);
        }

        .breakdown-item:last-child {
            border-bottom: none;
        }

        .breakdown-label {
            color: var(--muted);
            font-size: 0.95rem;
        }

        .breakdown-value {
            font-weight: 600;
            color: var(--text);
        }

        /* Export Button */
        .btn-export {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: linear-gradient(135deg, #8b5cf6, #7c3aed);
            color: white;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
        }

        .btn-export:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(139, 92, 246, 0.4);
        }

        .btn-export svg {
            width: 18px;
            height: 18px;
        }

        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr; }
            .quick-links { grid-template-columns: 1fr; }
            .breakdown-grid { grid-template-columns: 1fr; }
            .stat-value { font-size: 1.75rem; }
        }

        @media print {
            header, .quick-links, .btn-export {
                display: none;
            }
            body {
                background: white;
                color: black;
            }
            .stat-card, .table-wrapper, .breakdown-card {
                background: white;
                border: 1px solid #ddd;
                box-shadow: none;
            }
        }
    </style>
</head>
<body>

<header>
    <div class="inner">
        <h1>Hajj Management System</h1>
        <nav>
            <a href="admin_dashboard.php">Dashboard</a>
            <a href="admin_set_cost.php">Package Cost</a>
            <a href="admin_payments.php">Payments</a>
            <a href="admin_pilgrims.php">Pilgrims</a>
            <a href="admin_deferred.php">Deferred</a>
            <a href="admin_reports.php" >Reports</a>
            <a href="admin_users.php">Users</a>           
            <a href="logout.php" class="logout">Logout</a>
        </nav>
    </div>
</header>

<div class="container">

    <div class="page-header">
        <h1>Admin Reports & Analytics</h1>
        <p>Comprehensive overview of all system metrics and statistics</p>
    </div>

    <!-- Overall Statistics -->
    <div class="section">
        <div class="section-header">
            <h2 class="section-title">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
                </svg>
                Overall Statistics
            </h2>
            <button onclick="window.print()" class="btn-export">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                </svg>
                Print Report
            </button>
        </div>

        <div class="stats-grid">
            <div class="stat-card primary">
                <div class="stat-label">Total Pilgrims</div>
                <div class="stat-value"><?php echo number_format($total_pilgrims); ?></div>
                <div class="stat-trend">All registered pilgrims</div>
            </div>

            <div class="stat-card success">
                <div class="stat-label">Fully Paid</div>
                <div class="stat-value"><?php echo number_format($paid_pilgrims); ?></div>
                <div class="stat-trend"><?php echo $total_pilgrims > 0 ? round(($paid_pilgrims/$total_pilgrims)*100, 1) : 0; ?>% completion rate</div>
            </div>

            <div class="stat-card info">
                <div class="stat-label">Registered Status</div>
                <div class="stat-value"><?php echo number_format($registered_status); ?></div>
                <div class="stat-trend">Active registrations</div>
            </div>

            <div class="stat-card danger">
                <div class="stat-label">Deferred Pilgrims</div>
                <div class="stat-value"><?php echo number_format($deferred); ?></div>
                <div class="stat-trend">Postponed to next year</div>
            </div>

            <div class="stat-card warning">
                <div class="stat-label">Pending Balance</div>
                <div class="stat-value">₵ <?php echo number_format($pending_balance, 0); ?></div>
                <div class="stat-trend"><?php echo number_format($pending_hajj); ?> pilgrim(s) with balance</div>
            </div>

            <div class="stat-card success">
                <div class="stat-label">Total Revenue</div>
                <div class="stat-value">₵ <?php echo number_format($total_revenue, 0); ?></div>
                <div class="stat-trend">All-time hajj payments</div>
            </div>

            <div class="stat-card success">
                <div class="stat-label">Processing Fees</div>
                <div class="stat-value">₵ <?php echo number_format($total_processing_collected, 0); ?></div>
                <div class="stat-trend">Total fees collected</div>
            </div>

            <div class="stat-card warning">
                <div class="stat-label">Pending Processing Fees</div>
                <div class="stat-value"><?php echo number_format($pending_processing); ?></div>
                <div class="stat-trend">Pilgrims haven't paid</div>
            </div>

            <div class="stat-card primary">
                <div class="stat-label">Today's Revenue</div>
                <div class="stat-value">₵ <?php echo number_format($today_revenue, 0); ?></div>
                <div class="stat-trend"><?php echo $today_payments_count; ?> payment(s) today</div>
            </div>

            <div class="stat-card info">
                <div class="stat-label">This Month</div>
                <div class="stat-value">₵ <?php echo number_format($month_revenue, 0); ?></div>
                <div class="stat-trend">Month-to-date revenue</div>
            </div>

            <div class="stat-card primary">
                <div class="stat-label">Recent Registrations</div>
                <div class="stat-value"><?php echo number_format($recent_registrations); ?></div>
                <div class="stat-trend">Last 7 days</div>
            </div>

            <div class="stat-card info">
                <div class="stat-label">Package Cost</div>
                <div class="stat-value">₵ <?php echo number_format($current_cost, 0); ?></div>
                <div class="stat-trend">Current hajj package</div>
            </div>
        </div>
    </div>

    <!-- Breakdowns -->
    <div class="section">
        <h2 class="section-title">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 3.055A9.001 9.001 0 1020.945 13H11V3.055z"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z"/>
            </svg>
            Detailed Breakdowns
        </h2>

        <div class="breakdown-grid">
            <!-- Payment Methods -->
            <div class="breakdown-card">
                <div class="breakdown-title">Payment Methods</div>
                <?php if (!empty($payment_methods)): ?>
                    <?php foreach ($payment_methods as $method): ?>
                        <div class="breakdown-item">
                            <span class="breakdown-label"><?php echo htmlspecialchars($method['payment_method']); ?></span>
                            <span class="breakdown-value">
                                ₵ <?php echo number_format($method['total'], 0); ?>
                                <small style="color: var(--muted);">(<?php echo $method['count']; ?>)</small>
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="breakdown-item">
                        <span class="breakdown-label">No data available</span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Hajj Types -->
            <div class="breakdown-card">
                <div class="breakdown-title">Hajj Types</div>
                <?php if (!empty($hajj_types)): ?>
                    <?php foreach ($hajj_types as $type): ?>
                        <div class="breakdown-item">
                            <span class="breakdown-label"><?php echo htmlspecialchars($type['hajj_type']); ?></span>
                            <span class="breakdown-value">
                                <?php echo number_format($type['count']); ?> pilgrims
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="breakdown-item">
                        <span class="breakdown-label">No data available</span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Finance Officers Performance -->
            <div class="breakdown-card">
                <div class="breakdown-title">Finance Officers Performance</div>
                <?php if (!empty($finance_officers)): ?>
                    <?php foreach ($finance_officers as $officer): ?>
                        <div class="breakdown-item">
                            <span class="breakdown-label"><?php echo htmlspecialchars($officer['full_name'] ?? $officer['username']); ?></span>
                            <span class="breakdown-value">
                                GHS <?php echo number_format($officer['total_collected'], 0); ?>
                                <small style="color: var(--muted);">(<?php echo $officer['payment_count']; ?>)</small>
                            </span>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="breakdown-item">
                        <span class="breakdown-label">No officers found</span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Quick Navigation -->
    <div class="section">
        <h2 class="section-title">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
            </svg>
            Quick Actions
        </h2>

        <div class="quick-links">
            <a href="admin_pilgrims.php" class="link-card">
                <div class="link-card-icon">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                    </svg>
                </div>
                <h3>View All Pilgrims</h3>
                <p>Search, filter, and manage pilgrim records</p>
            </a>

            <a href="admin_deferred.php" class="link-card">
                <div class="link-card-icon">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <h3>Deferred Pilgrims</h3>
                <p>Review and reassign deferred registrations</p>
            </a>

            <a href="admin_payments.php" class="link-card">
                <div class="link-card-icon">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                    </svg>
                </div>
                <h3>Payment Records</h3>
                <p>View all payment transactions</p>
            </a>

            <a href="admin_users.php" class="link-card">
                <div class="link-card-icon">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                    </svg>
                </div>
                <h3>Manage Users</h3>
                <p>Add and manage finance officers</p>
            </a>
        </div>
    </div>

    <!-- Recent Payments -->
    <div class="section">
        <h2 class="section-title">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            Recent Payments (Last 10)
        </h2>

        <?php
        $recent = $conn->query("
            SELECT pay.id, pay.amount, pay.payment_date, pay.payment_method, pay.is_processing_fee,
                   p.first_name, p.surname, p.file_number
            FROM payments pay
            JOIN pilgrims p ON pay.pilgrim_id = p.id
            ORDER BY pay.payment_date DESC
            LIMIT 10
        ");

        if ($recent && $recent->num_rows > 0):
        ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Pilgrim</th>
                            <th>File #</th>
                            <th>Type</th>
                            <th>Amount (GHS)</th>
                            <th>Method</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while ($row = $recent->fetch_assoc()): ?>
                        <tr>
                            <td><?= date('d M Y, h:i A', strtotime($row['payment_date'])) ?></td>
                            <td><?= htmlspecialchars($row['first_name'] . ' ' . $row['surname']) ?></td>
                            <td><?= htmlspecialchars($row['file_number'] ?? '—') ?></td>
                            <td>
                                <?php if ($row['is_processing_fee']): ?>
                                    <span style="color: #fbbf24; font-weight: 500;">Processing</span>
                                <?php else: ?>
                                    <span style="color: #34d399; font-weight: 500;">Hajj</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight: 600; color: #34d399;"><?= number_format($row['amount'], 2) ?></td>
                            <td><?= htmlspecialchars($row['payment_method']) ?></td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <div class="empty-state">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/>
                    </svg>
                    <h3>No Payments Yet</h3>
                    <p>No payment records found in the system</p>
                </div>
            </div>
        <?php endif; ?>
    </div>

</div>

</body>
</html>