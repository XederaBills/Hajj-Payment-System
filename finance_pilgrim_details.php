<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'finance') {
    header('Location: login.php');
    exit;
}

include 'config.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: finance_pilgrims.php');
    exit;
}

$pilgrim_id = (int)$_GET['id'];

// Fetch pilgrim details
$pilgrim_stmt = $conn->prepare("
    SELECT * FROM pilgrims WHERE id = ?
");
$pilgrim_stmt->bind_param("i", $pilgrim_id);
$pilgrim_stmt->execute();
$pilgrim = $pilgrim_stmt->get_result()->fetch_assoc();
$pilgrim_stmt->close();

if (!$pilgrim) {
    header('Location: finance_pilgrims.php');
    exit;
}

// Fetch payment history with recorder info
$payments_stmt = $conn->prepare("
    SELECT 
        p.amount, 
        p.payment_date, 
        p.payment_method, 
        p.receipt_number, 
        p.notes,
        p.is_processing_fee,
        u.username as recorded_by
    FROM payments p
    LEFT JOIN users u ON p.recorded_by = u.id
    WHERE p.pilgrim_id = ?
    ORDER BY p.payment_date DESC
");
$payments_stmt->bind_param("i", $pilgrim_id);
$payments_stmt->execute();
$payments = $payments_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$payments_stmt->close();

// Calculate payment stats
$total_paid = array_sum(array_column($payments, 'amount'));
$hajj_payments = array_filter($payments, fn($p) => !$p['is_processing_fee']);
$processing_payments = array_filter($payments, fn($p) => $p['is_processing_fee']);
$total_hajj_paid = array_sum(array_column($hajj_payments, 'amount'));
$total_processing_paid = array_sum(array_column($processing_payments, 'amount'));
$payment_count = count($payments);

// Calculate age
function calculateAge($dob) {
    if (!$dob) return '—';
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
    <title><?php echo htmlspecialchars($pilgrim['first_name'] . ' ' . $pilgrim['surname']); ?> - Details</title>
    <style>
        :root {
            --bg: #0f172a;
            --card: rgba(30,41,59,0.7);
            --border: rgba(51,65,85,0.5);
            --text: #e2e8f0;
            --muted: #94a3b8;
            --primary: #3b82f6;
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

        .container { 
            max-width: 1400px; 
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

        /* Breadcrumb */
        .breadcrumb {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 24px;
            font-size: 0.9rem;
            color: var(--muted);
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
            transition: color 0.2s;
        }

        .breadcrumb a:hover {
            color: #60a5fa;
        }

        .breadcrumb span {
            color: var(--muted);
        }

        /* Page Header */
        .page-header {
            background: var(--card);
            border-radius: 16px;
            padding: 32px;
            margin-bottom: 32px;
            border: 1px solid var(--border);
            box-shadow: 0 8px 32px rgba(0,0,0,0.35);
        }

        .page-header-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 20px;
            margin-bottom: 24px;
        }

        .pilgrim-name {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text);
            margin-bottom: 8px;
        }

        .pilgrim-meta {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            color: var(--muted);
            font-size: 0.9rem;
        }

        .pilgrim-meta-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .action-buttons {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: #2563eb;
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: rgba(71,85,105,0.5);
            color: var(--text);
            border: 1px solid var(--border);
        }

        .btn-secondary:hover {
            background: rgba(100,116,139,0.5);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #059669;
        }

        /* Status Badges */
        .badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
            text-transform: capitalize;
        }

        .badge-registered {
            background: rgba(59,130,246,0.2);
            color: #60a5fa;
        }

        .badge-paid {
            background: rgba(16,185,129,0.2);
            color: #34d399;
        }

        .badge-deferred {
            background: rgba(245,158,11,0.2);
            color: #fbbf24;
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
        }

        .stat-card {
            background: rgba(30,41,59,0.5);
            border-radius: 12px;
            padding: 20px;
            border: 1px solid var(--border);
            position: relative;
            overflow: hidden;
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

        .stat-label {
            font-size: 0.85rem;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }

        .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--text);
        }

        /* Section Card */
        .section-card {
            background: var(--card);
            border-radius: 16px;
            padding: 32px;
            margin-bottom: 24px;
            border: 1px solid var(--border);
            box-shadow: 0 8px 32px rgba(0,0,0,0.35);
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: 24px;
            padding-bottom: 12px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .section-title svg {
            width: 24px;
            height: 24px;
            color: var(--primary);
        }

        /* Detail Grid */
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 24px;
        }

        .detail-item {
            display: flex;
            flex-direction: column;
        }

        .detail-label {
            color: var(--muted);
            font-size: 0.85rem;
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 500;
        }

        .detail-value {
            font-size: 1rem;
            font-weight: 500;
            color: var(--text);
        }

        .detail-value.highlight {
            color: var(--primary);
            font-weight: 600;
        }

        /* Payments Table */
        .table-wrapper {
            overflow-x: auto;
            border-radius: 12px;
            background: rgba(30,41,59,0.5);
            border: 1px solid var(--border);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 16px;
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

        .payment-type-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .payment-type-hajj {
            background: rgba(16,185,129,0.2);
            color: #34d399;
        }

        .payment-type-processing {
            background: rgba(245,158,11,0.2);
            color: #fbbf24;
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

        .empty-state h3 {
            font-size: 1.25rem;
            margin-bottom: 8px;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .page-header {
                padding: 24px;
            }

            .pilgrim-name {
                font-size: 1.5rem;
            }

            .page-header-top {
                flex-direction: column;
            }

            .action-buttons {
                width: 100%;
            }

            .btn {
                flex: 1;
                justify-content: center;
            }

            .detail-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            th, td {
                padding: 12px;
                font-size: 0.85rem;
            }
        }

        /* Print Styles */
        @media print {
            header, .action-buttons, .breadcrumb {
                display: none;
            }

            body {
                background: white;
                color: black;
            }

            .page-header, .section-card, .table-wrapper {
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

    <div class="breadcrumb">
        <a href="finance_dashboard.php">Dashboard</a>
        <span>›</span>
        <a href="finance_pilgrims.php">Pilgrims</a>
        <span>›</span>
        <span><?php echo htmlspecialchars($pilgrim['first_name'] . ' ' . $pilgrim['surname']); ?></span>
    </div>

    <!-- Page Header -->
    <div class="page-header">
        <div class="page-header-top">
            <div>
                <div class="pilgrim-name">
                    <?php echo htmlspecialchars($pilgrim['first_name'] . ' ' . $pilgrim['surname']); ?>
                    <?php if (!empty($pilgrim['other_name'])): ?>
                        <span style="color: var(--muted); font-size: 1.25rem; font-weight: 400;">
                            (<?php echo htmlspecialchars($pilgrim['other_name']); ?>)
                        </span>
                    <?php endif; ?>
                </div>
                <div class="pilgrim-meta">
                    <div class="pilgrim-meta-item">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width:16px;height:16px">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/>
                        </svg>
                        File #: <strong><?php echo htmlspecialchars($pilgrim['file_number'] ?? 'Not assigned'); ?></strong>
                    </div>
                    <div class="pilgrim-meta-item">
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width:16px;height:16px">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                        </svg>
                        Registered: <?php echo date('d M Y', strtotime($pilgrim['registration_date'])); ?>
                    </div>
                    <div class="pilgrim-meta-item">
                        <span class="badge badge-<?php echo $pilgrim['status']; ?>">
                            <?php echo ucfirst($pilgrim['status']); ?>
                        </span>
                    </div>
                </div>
            </div>
            <div class="action-buttons">
                <a href="finance_payments.php?pilgrim_id=<?php echo $pilgrim_id; ?>" class="btn btn-success">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width:18px;height:18px">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"/>
                    </svg>
                    Record Payment
                </a>
                <button onclick="window.print()" class="btn btn-secondary">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width:18px;height:18px">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                    </svg>
                    Print
                </button>
                <a href="finance_pilgrims.php" class="btn btn-secondary">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" style="width:18px;height:18px">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                    </svg>
                    Back to List
                </a>
            </div>
        </div>

        <!-- Financial Summary Stats -->
        <div class="stats-grid">
            <div class="stat-card danger">
                <div class="stat-label">Outstanding Balance</div>
                <div class="stat-value">GHS <?php echo number_format($pilgrim['balance'], 2); ?></div>
            </div>
            <div class="stat-card success">
                <div class="stat-label">Total Paid</div>
                <div class="stat-value">GHS <?php echo number_format($total_paid, 2); ?></div>
            </div>
            <div class="stat-card primary">
                <div class="stat-label">Hajj Payments</div>
                <div class="stat-value">GHS <?php echo number_format($total_hajj_paid, 2); ?></div>
            </div>
            <div class="stat-card warning">
                <div class="stat-label">Processing Fees</div>
                <div class="stat-value">
                    <?php if ($pilgrim['processing_fee_paid']): ?>
                        <span style="color: #34d399; font-size: 1.25rem;">✓ Paid</span>
                    <?php else: ?>
                        <span style="color: #fbbf24; font-size: 1.25rem;">✗ Unpaid</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Personal Information -->
    <div class="section-card">
        <h2 class="section-title">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
            </svg>
            Personal Information
        </h2>
        <div class="detail-grid">
            <div class="detail-item">
                <span class="detail-label">First Name</span>
                <span class="detail-value"><?php echo htmlspecialchars($pilgrim['first_name'] ?? '—'); ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Surname</span>
                <span class="detail-value"><?php echo htmlspecialchars($pilgrim['surname'] ?? '—'); ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Other Name</span>
                <span class="detail-value"><?php echo htmlspecialchars($pilgrim['other_name'] ?? '—'); ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Date of Birth</span>
                <span class="detail-value">
                    <?php 
                    echo htmlspecialchars($pilgrim['date_of_birth'] ?? '—');
                    if ($pilgrim['date_of_birth']) {
                        echo ' <span style="color: var(--muted);">(' . calculateAge($pilgrim['date_of_birth']) . ' years)</span>';
                    }
                    ?>
                </span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Occupation</span>
                <span class="detail-value"><?php echo htmlspecialchars($pilgrim['occupation'] ?? '—'); ?></span>
            </div>
        </div>
    </div>

    <!-- Identification -->
    <div class="section-card">
        <h2 class="section-title">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V8a2 2 0 00-2-2h-5m-4 0V5a2 2 0 114 0v1m-4 0a2 2 0 104 0m-5 8a2 2 0 100-4 2 2 0 000 4zm0 0c1.306 0 2.417.835 2.83 2M9 14a3.001 3.001 0 00-2.83 2M15 11h3m-3 4h2"/>
            </svg>
            Identification Documents
        </h2>
        <div class="detail-grid">
            <div class="detail-item">
                <span class="detail-label">Ghana Card Number</span>
                <span class="detail-value highlight"><?php echo htmlspecialchars($pilgrim['ghana_card_number'] ?? '—'); ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Passport Number</span>
                <span class="detail-value highlight"><?php echo htmlspecialchars($pilgrim['passport_number'] ?? '—'); ?></span>
            </div>
        </div>
    </div>

    <!-- Family Information -->
    <div class="section-card">
        <h2 class="section-title">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>
            </svg>
            Family Information
        </h2>
        <div class="detail-grid">
            <div class="detail-item">
                <span class="detail-label">Father's Name</span>
                <span class="detail-value"><?php echo htmlspecialchars($pilgrim['fathers_name'] ?? '—'); ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Father's Occupation</span>
                <span class="detail-value"><?php echo htmlspecialchars($pilgrim['fathers_occupation'] ?? '—'); ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Mother's Name</span>
                <span class="detail-value"><?php echo htmlspecialchars($pilgrim['mothers_name'] ?? '—'); ?></span>
            </div>
        </div>
    </div>

    <!-- Hajj Details -->
    <div class="section-card">
        <h2 class="section-title">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
            </svg>
            Hajj Details
        </h2>
        <div class="detail-grid">
            <div class="detail-item">
                <span class="detail-label">Type of Hajj</span>
                <span class="detail-value highlight"><?php echo htmlspecialchars($pilgrim['hajj_type'] ?? '—'); ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Status</span>
                <span class="detail-value">
                    <span class="badge badge-<?php echo $pilgrim['status']; ?>">
                        <?php echo ucfirst($pilgrim['status']); ?>
                    </span>
                </span>
            </div>
        </div>
    </div>

    <!-- Contact & Address -->
    <div class="section-card">
        <h2 class="section-title">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            Contact & Address Information
        </h2>
        <div class="detail-grid">
            <div class="detail-item">
                <span class="detail-label">Contact Number</span>
                <span class="detail-value highlight"><?php echo htmlspecialchars($pilgrim['contact'] ?? '—'); ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Home Town</span>
                <span class="detail-value"><?php echo htmlspecialchars($pilgrim['home_town'] ?? '—'); ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">House Address</span>
                <span class="detail-value"><?php echo htmlspecialchars($pilgrim['house_address'] ?? '—'); ?></span>
            </div>
        </div>
    </div>

    <!-- Witnesses -->
    <div class="section-card">
        <h2 class="section-title">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
            </svg>
            Witness Information
        </h2>
        <div class="detail-grid">
            <div class="detail-item">
                <span class="detail-label">Witness 1 Name</span>
                <span class="detail-value"><?php echo htmlspecialchars($pilgrim['witness1_name'] ?? '—'); ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Witness 1 Contact</span>
                <span class="detail-value"><?php echo htmlspecialchars($pilgrim['witness1_contact'] ?? '—'); ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Witness 2 Name</span>
                <span class="detail-value"><?php echo htmlspecialchars($pilgrim['witness2_name'] ?? '—'); ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Witness 2 Contact</span>
                <span class="detail-value"><?php echo htmlspecialchars($pilgrim['witness2_contact'] ?? '—'); ?></span>
            </div>
        </div>
    </div>

    <!-- Payment History -->
    <div class="section-card">
        <h2 class="section-title">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/>
            </svg>
            Payment History (<?php echo $payment_count; ?> transaction<?php echo $payment_count !== 1 ? 's' : ''; ?>)
        </h2>
        
        <?php if (empty($payments)): ?>
            <div class="empty-state">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/>
                </svg>
                <h3>No Payments Recorded</h3>
                <p>This pilgrim hasn't made any payments yet.</p>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table>
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Amount (GHS)</th>
                            <th>Type</th>
                            <th>Method</th>
                            <th>Receipt #</th>
                            <th>Recorded By</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $pay): ?>
                            <tr>
                                <td><?php echo date('d M Y, h:i A', strtotime($pay['payment_date'])); ?></td>
                                <td style="font-weight: 600; color: #34d399;">
                                    <?php echo number_format($pay['amount'], 2); ?>
                                </td>
                                <td>
                                    <?php if ($pay['is_processing_fee']): ?>
                                        <span class="payment-type-badge payment-type-processing">Processing Fee</span>
                                    <?php else: ?>
                                        <span class="payment-type-badge payment-type-hajj">Hajj Payment</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($pay['payment_method']); ?></td>
                                <td><?php echo htmlspecialchars($pay['receipt_number'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($pay['recorded_by'] ?? '—'); ?></td>
                                <td style="color: var(--muted); font-size: 0.9rem;">
                                    <?php echo htmlspecialchars($pay['notes'] ?? '—'); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>

</body>
</html>