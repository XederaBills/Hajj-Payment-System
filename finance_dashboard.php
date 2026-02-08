<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'finance') {
    header('Location: login.php');
    exit;
}

include 'config.php';

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Finance Officer';
$today_start = date('Y-m-d 00:00:00');
$today_end   = date('Y-m-d 23:59:59');

// This month
$this_month_start = date('Y-m-01 00:00:00');

// ── Today's stats (Finance Officer's personal stats) ──────────────────
$today_hajj = $conn->query("
    SELECT COALESCE(SUM(amount), 0) as total 
    FROM payments 
    WHERE recorded_by = $user_id 
      AND payment_date BETWEEN '$today_start' AND '$today_end'
      AND is_processing_fee = 0
")->fetch_assoc()['total'] ?? 0;

$today_processing = $conn->query("
    SELECT COALESCE(SUM(amount), 0) as total 
    FROM payments 
    WHERE recorded_by = $user_id 
      AND payment_date BETWEEN '$today_start' AND '$today_end'
      AND is_processing_fee = 1
")->fetch_assoc()['total'] ?? 0;

$today_count = $conn->query("
    SELECT COUNT(*) as cnt 
    FROM payments 
    WHERE recorded_by = $user_id 
      AND payment_date BETWEEN '$today_start' AND '$today_end'
")->fetch_assoc()['cnt'] ?? 0;

// ── This month stats (Finance Officer's personal stats) ───────────────
$month_hajj = $conn->query("
    SELECT COALESCE(SUM(amount), 0) as total 
    FROM payments 
    WHERE recorded_by = $user_id 
      AND payment_date >= '$this_month_start'
      AND is_processing_fee = 0
")->fetch_assoc()['total'] ?? 0;

$month_processing = $conn->query("
    SELECT COALESCE(SUM(amount), 0) as total 
    FROM payments 
    WHERE recorded_by = $user_id 
      AND payment_date >= '$this_month_start'
      AND is_processing_fee = 1
")->fetch_assoc()['total'] ?? 0;

$month_count = $conn->query("
    SELECT COUNT(*) as cnt 
    FROM payments 
    WHERE recorded_by = $user_id 
      AND payment_date >= '$this_month_start'
")->fetch_assoc()['cnt'] ?? 0;

// ── Overall system stats ───────────────────────────────────────────────
$total_pilgrims = $conn->query("SELECT COUNT(*) as cnt FROM pilgrims WHERE status != 'deferred'")->fetch_assoc()['cnt'] ?? 0;

$pending_hajj = $conn->query("SELECT COUNT(*) as cnt FROM pilgrims WHERE balance > 0 AND status != 'deferred'")->fetch_assoc()['cnt'] ?? 0;

$pending_processing = $conn->query("
    SELECT COUNT(*) as cnt 
    FROM pilgrims 
    WHERE processing_fee_paid = 0 AND status != 'deferred'
")->fetch_assoc()['cnt'] ?? 0;

$total_revenue = $conn->query("
    SELECT COALESCE(SUM(amount), 0) as total 
    FROM payments 
    WHERE is_processing_fee = 0
")->fetch_assoc()['total'] ?? 0;

$pending_balance = $conn->query("
    SELECT COALESCE(SUM(balance), 0) as total 
    FROM pilgrims 
    WHERE balance > 0 AND status != 'deferred'
")->fetch_assoc()['total'] ?? 0;

// ── Recent payments by this finance officer ────────────────────────────
$recent_payments = $conn->query("
    SELECT 
        pay.amount, 
        pay.payment_date, 
        pay.payment_method,
        pay.is_processing_fee,
        p.first_name, 
        p.surname, 
        p.file_number
    FROM payments pay
    JOIN pilgrims p ON pay.pilgrim_id = p.id
    WHERE pay.recorded_by = $user_id
    ORDER BY pay.payment_date DESC
    LIMIT 5
")->fetch_all(MYSQLI_ASSOC);

// ── Payment methods breakdown (today) ──────────────────────────────────
$payment_methods = $conn->query("
    SELECT payment_method, COUNT(*) as count, SUM(amount) as total 
    FROM payments 
    WHERE recorded_by = $user_id 
      AND payment_date BETWEEN '$today_start' AND '$today_end'
    GROUP BY payment_method
")->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finance Dashboard</title>
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

        .container { max-width: 1400px; margin:0 auto; padding:24px 16px; }

        header {
            background: linear-gradient(135deg, #1e293b 0%, #111827 100%);
            padding:16px 32px;
            box-shadow:0 6px 20px rgba(0,0,0,0.5);
            position:sticky; 
            top:0; 
            z-index:1000;
        }

        header .inner { 
            display:flex; 
            justify-content:space-between; 
            align-items:center; 
            flex-wrap:wrap; 
            gap:20px; 
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
            box-shadow: var(--shadow);
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

        .section-title {
            font-size: 1.3rem;
            color: #94a3b8;
            margin: 40px 0 20px;
            font-weight: 600;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
            margin-bottom: 40px;
        }

        .stat-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            box-shadow: var(--shadow);
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
            margin-bottom: 12px;
        }

        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(59,130,246,0.15);
            color: var(--accent, var(--primary));
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
            color: var(--text);
            margin-bottom: 4px;
        }

        .stat-trend {
            font-size: 0.8rem;
            color: var(--muted);
        }

        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 16px;
            margin-bottom: 40px;
        }

        .action-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
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
            width: 48px;
            height: 48px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(59,130,246,0.15);
            color: #60a5fa;
            flex-shrink: 0;
        }

        .action-content h3 {
            font-size: 1rem;
            margin-bottom: 4px;
            color: var(--text);
        }

        .action-content p {
            font-size: 0.85rem;
            color: var(--muted);
        }

        /* Payment Methods */
        .methods-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 40px;
        }

        .method-card {
            background: rgba(30,41,59,0.5);
            border: 1px solid var(--border);
            border-radius: 10px;
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

        /* Recent Activity */
        .activity-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 24px;
            box-shadow: var(--shadow);
        }

        .activity-card h3 {
            color: #60a5fa;
            margin-bottom: 20px;
            font-size: 1.2rem;
        }

        .activity-table {
            width: 100%;
            border-collapse: collapse;
        }

        .activity-table th {
            background: rgba(15,23,42,0.9);
            color: #cbd5e1;
            padding: 12px 16px;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.8rem;
            letter-spacing: 0.5px;
            text-align: left;
        }

        .activity-table td {
            padding: 12px 16px;
            border-top: 1px solid rgba(51,65,85,0.3);
            font-size: 0.9rem;
        }

        .activity-table tbody tr:hover {
            background: rgba(59,130,246,0.08);
        }

        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 6px;
            font-size: 0.75rem;
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

        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--muted);
            font-style: italic;
        }

        @media (max-width:768px) {
            .stats-grid { grid-template-columns: 1fr; }
            .quick-actions { grid-template-columns: 1fr; }
            .methods-grid { grid-template-columns: 1fr; }
            .activity-table { font-size: 0.85rem; }
        }
    </style>
</head>
<body>

<header>
    <div class="inner">
        <h1>Ustaz Hassan Labaika Travel Agency</h1>
        <nav>
            <a href="finance_dashboard.php" class="active">Dashboard</a>
            <a href="finance_register.php">New Pilgrim</a>
            <a href="finance_payments.php">Record Payment</a>
            <a href="finance_pilgrims.php">Pilgrims</a>
            <a href="logout.php" class="logout">Logout</a>
        </nav>
    </div>
</header>

<div class="container">

    <div class="welcome-banner">
        <h1>Welcome, <?php echo htmlspecialchars($user_name); ?>!</h1>
        <p>Here's your finance overview for <?php echo date('l, F j, Y'); ?></p>
    </div>

    <!-- Today's Personal Stats -->
    <h2 class="section-title">Your Today's Collections</h2>
    <div class="stats-grid">
        <div class="stat-card success">
            <div class="stat-label">Hajj Payments</div>
            <div class="stat-value">GHS <?php echo number_format($today_hajj, 0); ?></div>
            <div class="stat-trend"><?php echo $today_count; ?> transactions today</div>
        </div>

        <div class="stat-card info">
            <div class="stat-label">Processing Fees</div>
            <div class="stat-value">GHS <?php echo number_format($today_processing, 0); ?></div>
            <div class="stat-trend">Collected today</div>
        </div>

        <div class="stat-card primary">
            <div class="stat-label">Total Today</div>
            <div class="stat-value">GHS <?php echo number_format($today_hajj + $today_processing, 0); ?></div>
            <div class="stat-trend">Combined collections</div>
        </div>

        <div class="stat-card warning">
            <div class="stat-label">This Month</div>
            <div class="stat-value">GHS <?php echo number_format($month_hajj + $month_processing, 0); ?></div>
            <div class="stat-trend"><?php echo $month_count; ?> transactions</div>
        </div>
    </div>

    <!-- Payment Methods Breakdown (Today) -->
    <?php if (!empty($payment_methods)): ?>
    <h2 class="section-title">Today's Payment Methods</h2>
    <div class="methods-grid">
        <?php foreach ($payment_methods as $method): ?>
        <div class="method-card">
            <div class="method-name"><?php echo htmlspecialchars($method['payment_method']); ?></div>
            <div class="method-amount">GHS <?php echo number_format($method['total'], 0); ?></div>
            <div class="method-count"><?php echo $method['count']; ?> transactions</div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Quick Actions -->
    <h2 class="section-title">Quick Actions</h2>
    <div class="quick-actions">
        <a href="finance_register.php" class="action-card">
            <div class="action-icon">
                <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/>
                </svg>
            </div>
            <div class="action-content">
                <h3>Register New Pilgrim</h3>
                <p>Add a new pilgrim to the system</p>
            </div>
        </a>

        <a href="finance_payments.php" class="action-card">
            <div class="action-icon">
                <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                </svg>
            </div>
            <div class="action-content">
                <h3>Record Payment</h3>
                <p>Record a new payment transaction</p>
            </div>
        </a>

        <a href="finance_pilgrims.php" class="action-card">
            <div class="action-icon">
                <svg width="24" height="24" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
                </svg>
            </div>
            <div class="action-content">
                <h3>View Pilgrims</h3>
                <p>Browse all registered pilgrims</p>
            </div>
        </a>
    </div>

    <!-- System Overview -->
    <h2 class="section-title">System Overview</h2>
    <div class="stats-grid">
        <div class="stat-card primary">
            <div class="stat-label">Total Pilgrims</div>
            <div class="stat-value"><?php echo number_format($total_pilgrims); ?></div>
            <div class="stat-trend">Registered pilgrims</div>
        </div>

        <div class="stat-card warning">
            <div class="stat-label">Pending Balance</div>
            <div class="stat-value"><?php echo number_format($pending_hajj); ?></div>
            <div class="stat-trend">GHS <?php echo number_format($pending_balance, 0); ?> owed</div>
        </div>

        <div class="stat-card danger">
            <div class="stat-label">Processing Fee Due</div>
            <div class="stat-value"><?php echo number_format($pending_processing); ?></div>
            <div class="stat-trend">Pilgrims haven't paid</div>
        </div>

        <div class="stat-card success">
            <div class="stat-label">Total Revenue</div>
            <div class="stat-value">GHS <?php echo number_format($total_revenue, 0); ?></div>
            <div class="stat-trend">All-time collections</div>
        </div>
    </div>

    <!-- Recent Activity -->
    <h2 class="section-title">Your Recent Payments</h2>
    <div class="activity-card">
        <?php if (empty($recent_payments)): ?>
            <div class="empty-state">
                No payments recorded yet. Start by recording your first payment!
            </div>
        <?php else: ?>
            <table class="activity-table">
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>Pilgrim</th>
                        <th>File #</th>
                        <th>Method</th>
                        <th>Type</th>
                        <th>Amount</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_payments as $pay): ?>
                    <tr>
                        <td><?= date('d M, h:i A', strtotime($pay['payment_date'])) ?></td>
                        <td><strong><?= htmlspecialchars($pay['first_name'] . ' ' . $pay['surname']) ?></strong></td>
                        <td><?= htmlspecialchars($pay['file_number'] ?? '—') ?></td>
                        <td><?= htmlspecialchars($pay['payment_method']) ?></td>
                        <td>
                            <?php if ($pay['is_processing_fee']): ?>
                                <span class="badge badge-processing">Processing</span>
                            <?php else: ?>
                                <span class="badge badge-hajj">Hajj</span>
                            <?php endif; ?>
                        </td>
                        <td style="color: #34d399; font-weight: 600;">GHS <?= number_format($pay['amount'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</div>

</body>
</html>