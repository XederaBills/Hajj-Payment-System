<?php
session_start();

// Allow both admin and finance roles
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'finance'])) {
    header('Location: login.php');
    exit;
}

include 'config.php';

// Validate pilgrim ID
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header('Location: admin_pilgrims.php');
    exit;
}

$pilgrim_id = (int)$_GET['id'];

// Fetch pilgrim details with prepared statement
$stmt = $conn->prepare("SELECT * FROM pilgrims WHERE id = ?");
$stmt->bind_param("i", $pilgrim_id);
$stmt->execute();
$result = $stmt->get_result();
$pilgrim = $result->fetch_assoc();
$stmt->close();

if (!$pilgrim) {
    header('Location: admin_pilgrims.php');
    exit;
}

// Fetch payment history
$payments_stmt = $conn->prepare("
    SELECT 
        amount,
        payment_date,
        payment_method,
        receipt_number,
        notes,
        is_processing_fee
    FROM payments 
    WHERE pilgrim_id = ?
    ORDER BY payment_date DESC
");
$payments_stmt->bind_param("i", $pilgrim_id);
$payments_stmt->execute();
$payments = $payments_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$payments_stmt->close();

// Calculate total payments
$total_paid = 0;
$total_processing = 0;
foreach ($payments as $pay) {
    if ($pay['is_processing_fee']) {
        $total_processing += $pay['amount'];
    } else {
        $total_paid += $pay['amount'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pilgrim Details - <?= htmlspecialchars($pilgrim['first_name'] . ' ' . $pilgrim['surname']) ?></title>
    <style>
        :root {
            --bg: #0f172a;
            --text: #e2e8f0;
            --muted: #94a3b8;
            --primary: #3b82f6;
            --primary-light: #60a5fa;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
        }

        * { 
            margin: 0; 
            padding: 0; 
            box-sizing: border-box; 
        }

        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: var(--text);
            min-height: 100vh;
            line-height: 1.6;
        }

        .container { 
            max-width: 1200px; 
            margin: 0 auto; 
            padding: 20px; 
        }

        /* Header Styles */
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
            color: var(--primary-light);
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
        h1.main-title {
            text-align: center;
            font-size: 1.8rem;
            margin: 24px 0;
            background: linear-gradient(135deg, #60a5fa, #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-weight: 700;
        }

        /* Profile Header - Picture on Right */
        .profile-header {
            background: rgba(30,41,59,0.5);
            padding: 25px;
            margin-bottom: 20px;
            display: flex;
            gap: 30px;
            align-items: flex-start;
        }

        .profile-info-left {
            flex: 1;
        }

        .pilgrim-name {
            font-size: 1.6rem;
            font-weight: 700;
            color: var(--primary-light);
            margin-bottom: 20px;
        }

        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px 30px;
        }

        .info-item {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .info-label {
            font-size: 0.8rem;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .info-value {
            font-size: 1rem;
            font-weight: 600;
            color: var(--text);
        }

        /* Picture Container - Right Side */
        .profile-picture-container {
            flex-shrink: 0;
            display: flex;
            align-items: flex-start;
        }

        .profile-picture {
            width: 140px;
            height: 160px;
            object-fit: cover;
            display: block;
        }

        .no-photo {
            width: 140px;
            height: 160px;
            background: rgba(59,130,246,0.15);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--muted);
            font-size: 0.85rem;
            text-align: center;
            padding: 15px;
        }

        /* Content Card - No Borders */
        .detail-card {
            background: rgba(30,41,59,0.3);
            padding: 25px;
        }

        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--primary-light);
            margin: 25px 0 15px 0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }

        .section-title:first-child {
            margin-top: 0;
        }

        /* Two Column Grid - No Borders */
        .detail-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px 40px;
            margin-bottom: 20px;
        }

        .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
        }

        .detail-label {
            font-size: 0.9rem;
            color: var(--muted);
            font-weight: 500;
        }

        .detail-value {
            font-size: 0.95rem;
            color: var(--text);
            font-weight: 600;
            text-align: right;
        }

        /* Payments Table - Simplified */
        .payments-table-wrapper {
            overflow-x: auto;
            margin: 20px 0;
        }

        .payments-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        .payments-table th {
            background: rgba(59,130,246,0.15);
            color: var(--text);
            font-weight: 600;
            text-align: left;
            padding: 10px 12px;
        }

        .payments-table td {
            padding: 10px 12px;
            color: var(--text);
        }

        .payments-table tbody tr {
            background: rgba(30,41,59,0.3);
        }

        .payments-table tbody tr:hover {
            background: rgba(59,130,246,0.1);
        }

        .payment-type-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .type-processing {
            background: rgba(245,158,11,0.2);
            color: #fbbf24;
        }

        .type-hajj {
            background: rgba(16,185,129,0.2);
            color: #34d399;
        }

        .empty-state {
            text-align: center;
            padding: 40px;
            color: var(--muted);
            font-style: italic;
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 12px;
            margin-top: 30px;
            justify-content: flex-end;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            border-radius: 6px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
        }

        .btn-back {
            background: rgba(71,85,105,0.3);
            color: var(--text);
        }

        .btn-back:hover {
            background: rgba(71,85,105,0.5);
        }

        .btn-print {
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
        }

        .btn-print:hover {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59,130,246,0.4);
        }

        /* Print Styles - Clean & Simple */
        @media print {
            body {
                background: white;
                color: black;
            }

            header, .action-buttons {
                display: none !important;
            }

            .container {
                max-width: 100%;
                padding: 0;
            }

            h1.main-title {
                color: black;
                font-size: 20pt;
                margin: 0 0 15pt 0;
                -webkit-text-fill-color: initial;
                background: none;
                text-align: center;
            }

            .profile-header {
                background: white;
                padding: 0;
                margin-bottom: 15pt;
                page-break-inside: avoid;
            }

            .pilgrim-name {
                color: black;
                font-size: 16pt;
                margin-bottom: 10pt;
            }

            .info-label {
                color: #666;
                font-size: 8pt;
            }

            .info-value {
                color: black;
                font-size: 10pt;
            }

            .profile-picture, .no-photo {
                width: 120px;
                height: 140px;
            }

            .profile-picture {
                border: 1px solid #000;
            }

            .no-photo {
                background: #f5f5f5;
                border: 1px solid #ccc;
                color: #999;
            }

            .detail-card {
                background: white;
                padding: 0;
            }

            .section-title {
                color: #000;
                font-size: 11pt;
                margin: 15pt 0 8pt 0;
                border-bottom: 1px solid #000;
                padding-bottom: 3pt;
            }

            .detail-item {
                padding: 5pt 0;
            }

            .detail-label {
                color: #666;
                font-size: 9pt;
            }

            .detail-value {
                color: black;
                font-size: 9pt;
            }

            .payments-table {
                font-size: 8pt;
            }

            .payments-table th {
                background: #f0f0f0;
                color: black;
                border: 1px solid #ddd;
            }

            .payments-table td {
                color: black;
                border: 1px solid #ddd;
            }

            .payments-table tbody tr {
                background: white;
            }

            .payment-type-badge {
                background: white;
                color: black;
                border: 1px solid #000;
            }
        }

        /* Signature Section - Hidden on screen, visible on print */
        .signature-section {
            display: none;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 60px;
            margin: 50px 0 30px;
            padding: 40px 20px 20px;
            border-top: 2px dashed #ddd;
        }

        .signature-box {
            text-align: center;
        }

        .signature-line {
            border-top: 2px solid #000;
            margin: 60px auto 12px;
            width: 180px;
        }

        .signature-label {
            font-size: 10pt;
            color: #666;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .signature-name {
            font-size: 11pt;
            color: #000;
            font-weight: 600;
            margin-top: 4px;
        }

        .signature-date {
            font-size: 9pt;
            color: #666;
            margin-top: 8px;
        }

        @media print {
            /* Show signature section on print */
            .signature-section {
                display: grid !important;
            }

        @media screen and (max-width: 768px) {
            .profile-header {
                flex-direction: column-reverse;
                align-items: center;
            }

            .profile-picture-container {
                margin-bottom: 20px;
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .detail-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>

<!-- Header -->
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
    
    <h1 class="main-title">Pilgrim Profile</h1>

    <!-- Profile Header - Picture on Right -->
    <div class="profile-header">
        <div class="profile-info-left">
            <div class="pilgrim-name">
                <?= htmlspecialchars($pilgrim['first_name'] . ' ' . ($pilgrim['other_name'] ? $pilgrim['other_name'] . ' ' : '') . $pilgrim['surname']) ?>
            </div>
            
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Pilgrim ID</span>
                    <span class="info-value">#<?= str_pad($pilgrim['id'], 5, '0', STR_PAD_LEFT) ?></span>
                </div>
                
                <div class="info-item">
                    <span class="info-label">File Number</span>
                    <span class="info-value"><?= htmlspecialchars($pilgrim['file_number'] ?? 'N/A') ?></span>
                </div>
                
                <div class="info-item">
                    <span class="info-label">Date of Birth</span>
                    <span class="info-value"><?= htmlspecialchars($pilgrim['date_of_birth'] ?? '—') ?></span>
                </div>
                
                <div class="info-item">
                    <span class="info-label">Hajj Type</span>
                    <span class="info-value"><?= htmlspecialchars($pilgrim['hajj_type'] ?? 'N/A') ?></span>
                </div>
                
                <div class="info-item">
                    <span class="info-label">Registration Date</span>
                    <span class="info-value"><?= date('d M Y', strtotime($pilgrim['registration_date'])) ?></span>
                </div>
                
                <div class="info-item">
                    <span class="info-label">Contact Number</span>
                    <span class="info-value"><?= htmlspecialchars($pilgrim['contact'] ?? '—') ?></span>
                </div>
            </div>
        </div>
        
        <div class="profile-picture-container">
            <?php 
            // Use passport_picture column from database
            $photo_path = $pilgrim['passport_picture'] ?? '';
            $photo_exists = !empty($photo_path) && file_exists($photo_path);
            
            if ($photo_exists): 
            ?>
                <img src="<?= htmlspecialchars($photo_path) ?>" alt="Passport Photo" class="profile-picture">
            <?php else: ?>
                <div class="no-photo">No Photo Available</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Main Details Card -->
    <div class="detail-card">

        <!-- Personal Information -->
        <div class="section-title">Personal Information</div>
        <div class="detail-grid">
            <div class="detail-item">
                <span class="detail-label">Occupation</span>
                <span class="detail-value"><?= htmlspecialchars($pilgrim['occupation'] ?? '—') ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Home Town</span>
                <span class="detail-value"><?= htmlspecialchars($pilgrim['home_town'] ?? '—') ?></span>
            </div>
        </div>

        <!-- Identification -->
        <div class="section-title">Identification Documents</div>
        <div class="detail-grid">
            <div class="detail-item">
                <span class="detail-label">Ghana Card Number</span>
                <span class="detail-value"><?= htmlspecialchars($pilgrim['ghana_card_number'] ?? '—') ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Passport Number</span>
                <span class="detail-value"><?= htmlspecialchars($pilgrim['passport_number'] ?? '—') ?></span>
            </div>
        </div>

        <!-- Family Information -->
        <div class="section-title">Family Information</div>
        <div class="detail-grid">
            <div class="detail-item">
                <span class="detail-label">Father's Name</span>
                <span class="detail-value"><?= htmlspecialchars($pilgrim['fathers_name'] ?? '—') ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Father's Occupation</span>
                <span class="detail-value"><?= htmlspecialchars($pilgrim['fathers_occupation'] ?? '—') ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Mother's Name</span>
                <span class="detail-value"><?= htmlspecialchars($pilgrim['mothers_name'] ?? '—') ?></span>
            </div>
        </div>

        <!-- Contact & Address -->
        <div class="section-title">Contact & Address</div>
        <div class="detail-grid">
            <div class="detail-item">
                <span class="detail-label">House Address</span>
                <span class="detail-value"><?= htmlspecialchars($pilgrim['house_address'] ?? '—') ?></span>
            </div>
        </div>

        <!-- Witnesses -->
        <div class="section-title">Witnesses</div>
        <div class="detail-grid">
            <div class="detail-item">
                <span class="detail-label">Witness 1 Name</span>
                <span class="detail-value"><?= htmlspecialchars($pilgrim['witness1_name'] ?? '—') ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Witness 1 Occupation</span>
                <span class="detail-value"><?= htmlspecialchars($pilgrim['witness1_occupation'] ?? '—') ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Witness 1 Contact</span>
                <span class="detail-value"><?= htmlspecialchars($pilgrim['witness1_contact'] ?? '—') ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Witness 2 Name</span>
                <span class="detail-value"><?= htmlspecialchars($pilgrim['witness2_name'] ?? '—') ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Witness 2 Occupation</span>
                <span class="detail-value"><?= htmlspecialchars($pilgrim['witness2_occupation'] ?? '—') ?></span>
            </div>
            <div class="detail-item">
                <span class="detail-label">Witness 2 Contact</span>
                <span class="detail-value"><?= htmlspecialchars($pilgrim['witness2_contact'] ?? '—') ?></span>
            </div>
        </div>

        <!-- Payment History -->
        <div class="section-title">Payment History</div>
        <?php if (empty($payments)): ?>
            <div class="empty-state">No payments recorded for this pilgrim yet.</div>
        <?php else: ?>
            <div class="payments-table-wrapper">
                <table class="payments-table">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Amount (GHS)</th>
                            <th>Method</th>
                            <th>Receipt #</th>
                            <th>Type</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $pay): ?>
                            <tr>
                                <td><?= date('d M Y, h:i A', strtotime($pay['payment_date'])) ?></td>
                                <td><strong><?= number_format($pay['amount'], 2) ?></strong></td>
                                <td><?= htmlspecialchars($pay['payment_method']) ?></td>
                                <td><?= htmlspecialchars($pay['receipt_number'] ?? '—') ?></td>
                                <td>
                                    <?php if ($pay['is_processing_fee']): ?>
                                        <span class="payment-type-badge type-processing">Processing Fee</span>
                                    <?php else: ?>
                                        <span class="payment-type-badge type-hajj">Hajj Payment</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($pay['notes'] ?? '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- Signature Section (Print Only) -->
        <div class="signature-section">
            <div class="signature-box">
                
                <div class="signature-label">Signature</div>
                <div class="signature-name">............. </div>
                <div class="signature-name">Finance Officer</div>
                <div class="signature-date">Date: _________________</div>
            </div>
            
        </div>
        <!-- Action Buttons -->
        <div class="action-buttons">
            <a href="admin_pilgrims.php" class="btn btn-back">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
                </svg>
                Back to Pilgrims List
            </a>
            <button onclick="window.print()" class="btn btn-print">
                <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/>
                </svg>
                Print Profile
            </button>
        </div>
    </div>

</div>

</body>
</html>