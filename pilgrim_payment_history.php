<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit;
}

include 'config.php';

if (!isset($_GET['pilgrim_id']) || !is_numeric($_GET['pilgrim_id'])) {
    die("Invalid pilgrim ID.");
}

$pilgrim_id = (int)$_GET['pilgrim_id'];

// Get pilgrim details
$pilgrim_stmt = $conn->prepare("
    SELECT first_name, surname, file_number, balance
    FROM pilgrims
    WHERE id = ?
");
$pilgrim_stmt->bind_param("i", $pilgrim_id);
$pilgrim_stmt->execute();
$pilgrim = $pilgrim_stmt->get_result()->fetch_assoc();
$pilgrim_stmt->close();

if (!$pilgrim) {
    die("Pilgrim not found.");
}

// Get all payments for this pilgrim
$payments_stmt = $conn->prepare("
    SELECT pay.id, pay.amount, pay.payment_date, pay.payment_method, pay.receipt_number, 
           u.username AS recorded_by
    FROM payments pay
    JOIN users u ON pay.recorded_by = u.id
    WHERE pay.pilgrim_id = ?
    ORDER BY pay.payment_date DESC
");
$payments_stmt->bind_param("i", $pilgrim_id);
$payments_stmt->execute();
$payments = $payments_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$payments_stmt->close();

// Calculate total paid
$total_paid = 0;
foreach ($payments as $pay) {
    $total_paid += $pay['amount'];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payment History for <?php echo htmlspecialchars($pilgrim['first_name'] . ' ' . $pilgrim['surname']); ?> - USTAZ HASSAN</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            min-height: 100vh;
            line-height: 1.6;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 24px 16px;
        }

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
            font-size: 1.6rem;
            font-weight: 600;
        }

        header nav a {
            color: #e2e8f0;
            text-decoration: none;
            font-weight: 500;
            padding: 8px 16px;
            border-radius: 8px;
            transition: 0.25s ease;
        }

        header nav a:hover,
        header nav a.active {
            background: rgba(255,255,255,0.15);
        }

        header nav a.logout {
            background: rgba(239,68,68,0.25);
            color: #fca5a5;
        }

        header nav a.logout:hover {
            background: rgba(239,68,68,0.45);
        }

        h1.main-title {
            text-align: center;
            font-size: 2.1rem;
            margin: 32px 0 40px;
            color: #60a5fa;
            font-weight: 600;
            letter-spacing: -0.5px;
        }

        .pilgrim-info {
            background: rgba(30, 41, 59, 0.45);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(51, 65, 85, 0.5);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 32px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.35);
            text-align: center;
        }

        .pilgrim-info h2 {
            color: #94a3b8;
            margin-bottom: 16px;
        }

        .pilgrim-info p {
            font-size: 1.2rem;
            margin: 8px 0;
        }

        .balance {
            color: #34d399;
            font-weight: 600;
        }

        .history-table {
            background: rgba(30, 41, 59, 0.45);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(51, 65, 85, 0.5);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 8px 32px rgba(0,0,0,0.35);
            width: 100%;
        }

        .history-table th {
            background: rgba(15, 23, 42, 0.7);
            color: #cbd5e1;
            padding: 16px 20px;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.9rem;
            letter-spacing: 0.4px;
            border-bottom: 2px solid #334155;
        }

        .history-table td {
            padding: 16px 20px;
            border-top: 1px solid rgba(51,65,85,0.4);
        }

        .history-table tr:hover td {
            background: rgba(59,130,246,0.12);
        }

        .print-btn {
            display: inline-block;
            padding: 8px 16px;
            background: #f59e0b;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .print-btn:hover {
            background: #d97706;
            transform: translateY(-1px);
        }

        .empty-state {
            background: rgba(30, 41, 59, 0.45);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(51, 65, 85, 0.5);
            border-radius: 16px;
            padding: 60px 30px;
            text-align: center;
            font-size: 1.25rem;
            color: #94a3b8;
            box-shadow: 0 8px 32px rgba(0,0,0,0.35);
        }

        .btn-back {
            display: inline-block;
            margin-top: 32px;
            padding: 12px 24px;
            background: #475569;
            color: #e2e8f0;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .btn-back:hover {
            background: #64748b;
            transform: translateY(-1px);
        }

        @media (max-width: 768px) {
            .history-table th, .history-table td {
                padding: 12px 14px;
            }
        }
    </style>
</head>
<body>

<header>
    <div class="inner">
        <div>
            <h1>USTAZ HASSAN TRAVEL AGENCY</h1>
        </div>
        <nav>
            <a href="admin_dashboard.php">Dashboard</a>
            <a href="admin_set_cost.php">Package Cost</a>
            <a href="admin_payments.php" class="active">Payments</a>
            <a href="admin_users.php">Users</a>
            <a href="logout.php" class="logout">Logout</a>
        </nav>
    </div>
</header>

<div class="container">

    <h1 class="main-title">Payment History for <?php echo htmlspecialchars($pilgrim['first_name'] . ' ' . $pilgrim['surname']); ?></h1>

    <div class="pilgrim-info">
        <h2>Pilgrim Details</h2>
        <p>File Number: <?php echo htmlspecialchars($pilgrim['file_number'] ?? '—'); ?></p>
        <p>Total Paid: GHS <?php echo number_format($total_paid, 2); ?></p>
        <p>Remaining Balance: GHS <?php echo number_format($pilgrim['balance'], 2); ?></p>
    </div>

    <?php if (empty($payments)): ?>
        <div class="empty-state">
            No payment history found for this pilgrim.
        </div>
    <?php else: ?>
        <table class="history-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Amount (GHS)</th>
                    <th>Method</th>
                    <th>Receipt #</th>
                    <th>Recorded By</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payments as $pay): ?>
                    <tr>
                        <td><?php echo date('d M Y H:i', strtotime($pay['payment_date'])); ?></td>
                        <td><?php echo number_format($pay['amount'], 2); ?></td>
                        <td><?php echo htmlspecialchars($pay['payment_method']); ?></td>
                        <td><?php echo htmlspecialchars($pay['receipt_number'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($pay['recorded_by']); ?></td>
                        <td>
                            <a href="print_receipt.php?id=<?php echo $pay['id']; ?>" target="_blank" class="print-btn">Print</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <div style="text-align: center; margin-top: 40px;">
        <a href="admin_payments.php" class="btn-back">Back to Payments</a>
    </div>

</div>

</body>
</html>