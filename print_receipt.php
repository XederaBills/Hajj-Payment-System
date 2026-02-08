<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

include 'config.php';

if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("Invalid payment ID.");
}

$payment_id = (int)$_GET['id'];

$stmt = $conn->prepare("
    SELECT 
        pay.id, 
        pay.amount, 
        pay.payment_date, 
        pay.payment_method, 
        pay.receipt_number, 
        pay.notes,
        p.id AS pilgrim_id,
        p.file_number, 
        p.first_name, 
        p.surname, 
        p.contact,
        u.username AS recorded_by
    FROM payments pay
    JOIN pilgrims p ON pay.pilgrim_id = p.id
    JOIN users u ON pay.recorded_by = u.id
    WHERE pay.id = ?
");
$stmt->bind_param("i", $payment_id);
$stmt->execute();
$receipt = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$receipt) {
    die("Payment record not found.");
}

// Get total amount paid for this pilgrim (optional - only if you want to show it)
$total_paid = 0;
if (!empty($receipt['pilgrim_id'])) {
    $pilgrim_id = $receipt['pilgrim_id'];
    $total_stmt = $conn->prepare("SELECT SUM(amount) AS total_paid FROM payments WHERE pilgrim_id = ?");
    $total_stmt->bind_param("i", $pilgrim_id);
    $total_stmt->execute();
    $total_result = $total_stmt->get_result()->fetch_assoc();
    $total_paid = $total_result['total_paid'] ?? 0;
    $total_stmt->close();
}

// Get current Hajj cost (for reference)
$cost_row = $conn->query("SELECT value FROM configurations WHERE key_name = 'current_hajj_cost' LIMIT 1");
$hajj_cost = $cost_row->num_rows > 0 ? (float)$cost_row->fetch_assoc()['value'] : 0.00;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt #<?php echo $receipt['id']; ?> | USTAZ HASSAN LABAIKA</title>
    <style>
        @page {
            size: A4;
            margin: 18mm 15mm;
        }

        body {
            font-family: 'Segoe UI', Arial, sans-serif;
            color: #111827;
            background: #f9fafb;
            margin: 0;
            padding: 0;
        }

        .receipt-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }

        .header {
            text-align: center;
            padding-bottom: 20px;
            border-bottom: 2px solid #e5e7eb;
            margin-bottom: 30px;
        }

        .logo {
            font-size: 2.4rem;
            font-weight: bold;
            color: #1d4ed8;
            margin: 0;
        }

        .agency {
            font-size: 1.1rem;
            color: #4b5563;
            margin-top: 4px;
        }

        .title {
            text-align: center;
            color: #111827;
            font-size: 1.8rem;
            margin: 30px 0 8px;
            font-weight: 600;
        }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px 40px;
            margin: 30px 0;
        }

        .info-item strong {
            display: block;
            color: #4b5563;
            font-size: 0.95rem;
            margin-bottom: 4px;
        }

        .amount-section {
            background: #ecfdf5;
            border: 2px dashed #10b981;
            border-radius: 12px;
            padding: 32px;
            text-align: center;
            margin: 40px 0;
        }

        .amount-label {
            font-size: 1.3rem;
            color: #065f46;
            margin-bottom: 12px;
        }

        .amount-value {
            font-size: 3.5rem;
            font-weight: 700;
            color: #059669;
        }

        .notes-section {
            background: #eff6ff;
            border-left: 4px solid #3b82f6;
            padding: 20px;
            border-radius: 8px;
            margin: 24px 0;
        }

        .footer {
            margin-top: 60px;
            text-align: center;
            color: #6b7280;
            font-size: 0.95rem;
            border-top: 1px solid #e5e7eb;
            padding-top: 24px;
        }

        .print-button {
            display: block;
            margin: 40px auto 0;
            padding: 14px 48px;
            background: #1d4ed8;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 1.15rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.25s;
        }

        .print-button:hover {
            background: #1e40af;
            transform: translateY(-2px);
        }

        @media print {
            body { background: white; }
            .receipt-container {
                box-shadow: none;
                border: 1px solid #e5e7eb;
            }
            .print-button { display: none; }
            .amount-section {
                background: #f0fdfa;
                border-color: #059669;
            }
            .notes-section {
                background: #eff6ff;
            }
        }

        @media (max-width: 600px) {
            .info-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
        }
    </style>
</head>
<body>

<div class="receipt-container">
    <div class="header">
        <h1 class="logo">LABI LABBI TRAVEL AGENCY</h1>
        <div class="agency">Hajj & Umrah Services • Official Receipt</div>
    </div>

    <h2 class="title">PAYMENT RECEIPT</h2>

    <div class="info-grid">
        <div class="info-item">
            <strong>Receipt No:</strong>
            <?php echo htmlspecialchars($receipt['receipt_number'] ?: 'N/A'); ?>
        </div>
        <div class="info-item">
            <strong>Date & Time:</strong>
            <?php echo date('d M Y  H:i', strtotime($receipt['payment_date'])); ?>
        </div>
        <div class="info-item">
            <strong>Pilgrim Name:</strong>
            <?php echo htmlspecialchars($receipt['first_name'] . ' ' . $receipt['surname']); ?>
        </div>
        <div class="info-item">
            <strong>File Number:</strong>
            <?php echo htmlspecialchars($receipt['file_number'] ?: '—'); ?>
        </div>
        <div class="info-item">
            <strong>Payment Method:</strong>
            <?php echo htmlspecialchars($receipt['payment_method']); ?>
        </div>
        <div class="info-item">
            <strong>Recorded By:</strong>
            <?php echo htmlspecialchars($receipt['recorded_by']); ?>
        </div>
    </div>

    <div class="amount-section">
        <div class="amount-label">Amount Paid</div>
        <div class="amount-value">GHS <?php echo number_format($receipt['amount'], 2); ?></div>
    </div>

    <?php if (!empty($receipt['notes'])): ?>
    <div class="notes-section">
        <strong>Notes / Remarks:</strong><br>
        <?php echo nl2br(htmlspecialchars($receipt['notes'])); ?>
    </div>
    <?php endif; ?>

    <div class="footer">
        Thank you for your payment.<br>
        This is an official receipt – please keep for your records.<br>
        LABI LABBI TRAVEL AGENCY • Contact: +233 541 225 369 <br>
        Your Most Trusted And Reliable Travel Agency
    </div>

    <button class="print-button" onclick="window.print()">Print Receipt</button>
</div>

</body>
</html>