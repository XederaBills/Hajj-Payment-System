<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'finance') {
    header('Location: login.php');
    exit;
}

include 'config.php';

// ────────────────────────────────────────────────
// AJAX GET PILGRIM DETAILS
// ────────────────────────────────────────────────
if (isset($_GET['get_pilgrim']) && is_numeric($_GET['get_pilgrim'])) {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    $pilgrim_id = (int)$_GET['get_pilgrim'];
    
    // Get pilgrim details
    $stmt = $conn->prepare("
        SELECT 
            id, first_name, surname, file_number, balance,
            processing_fee_paid, processing_fee_amount, contact
        FROM pilgrims 
        WHERE id = ?
    ");
    $stmt->bind_param("i", $pilgrim_id);
    $stmt->execute();
    $pilgrim = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    // Get payment history
    $payments_stmt = $conn->prepare("
        SELECT amount, payment_date, payment_method, is_processing_fee
        FROM payments
        WHERE pilgrim_id = ?
        ORDER BY payment_date DESC
        LIMIT 5
    ");
    $payments_stmt->bind_param("i", $pilgrim_id);
    $payments_stmt->execute();
    $payments = $payments_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $payments_stmt->close();
    
    // Get total paid
    $total_paid_stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) as total
        FROM payments
        WHERE pilgrim_id = ? AND is_processing_fee = 0
    ");
    $total_paid_stmt->bind_param("i", $pilgrim_id);
    $total_paid_stmt->execute();
    $total_paid = $total_paid_stmt->get_result()->fetch_assoc()['total'];
    $total_paid_stmt->close();
    
    $response = [
        'pilgrim' => $pilgrim,
        'payments' => $payments,
        'total_paid' => $total_paid
    ];
    
    echo json_encode($response);
    exit;
}

// ────────────────────────────────────────────────
// AJAX SEARCH FOR PILGRIMS
// ────────────────────────────────────────────────
if (isset($_GET['q']) && $_SERVER['REQUEST_METHOD'] === 'GET') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');

    $search = '%' . trim($_GET['q']) . '%';

    $stmt = $conn->prepare("
        SELECT id, first_name, surname, file_number, balance,
               processing_fee_paid, processing_fee_amount, contact, status
        FROM pilgrims 
        WHERE status != 'deferred'
          AND (balance > 0 OR processing_fee_paid = 0)
          AND (
              CONCAT(first_name, ' ', surname) LIKE ? 
              OR file_number LIKE ? 
              OR ghana_card_number LIKE ? 
              OR passport_number LIKE ?
              OR contact LIKE ?
          )
        ORDER BY surname, first_name
        LIMIT 10
    ");
    $stmt->bind_param("sssss", $search, $search, $search, $search, $search);
    $stmt->execute();
    $results = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    echo json_encode($results);
    exit;
}

// ────────────────────────────────────────────────
// NORMAL PAGE LOAD - HANDLE PAYMENT SUBMISSION
// ────────────────────────────────────────────────

$message = '';
$success = false;
$payment_id = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pilgrim_id     = (int)($_POST['pilgrim_id'] ?? 0);
    $amount         = (float)($_POST['amount'] ?? 0);
    $payment_method = trim($_POST['payment_method'] ?? '');
    $receipt_number = trim($_POST['receipt_number'] ?? '');
    $notes          = trim($_POST['notes'] ?? '');

    if ($pilgrim_id <= 0 || $amount <= 0) {
        $message = "Please select a pilgrim and enter a valid amount greater than zero.";
    } elseif (empty($payment_method)) {
        $message = "Please select a payment method.";
    } else {
        // Get pilgrim info
        $info_stmt = $conn->prepare("
            SELECT first_name, surname, balance, processing_fee_paid, processing_fee_amount 
            FROM pilgrims 
            WHERE id = ?
        ");
        $info_stmt->bind_param("i", $pilgrim_id);
        $info_stmt->execute();
        $info_stmt->bind_result($first_name, $surname, $current_balance, $fee_paid, $fee_amount);
        $info_stmt->fetch();
        $info_stmt->close();

        $is_processing_payment = isset($_POST['is_processing_fee']) && $_POST['is_processing_fee'] === '1';

        if (!$fee_paid && !$is_processing_payment) {
            $message = "Processing fee of GHS " . number_format($fee_amount, 2) . " must be paid first.";
        } elseif ($is_processing_payment) {
            // Processing fee payment
            if (abs($amount - $fee_amount) > 0.01) {
                $message = "You must pay exactly GHS " . number_format($fee_amount, 2) . " for the processing fee.";
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO payments 
                    (pilgrim_id, amount, payment_method, receipt_number, recorded_by, notes, is_processing_fee) 
                    VALUES (?, ?, ?, ?, ?, ?, 1)
                ");
                $user_id = $_SESSION['user_id'];
                $stmt->bind_param("idssis", $pilgrim_id, $amount, $payment_method, $receipt_number, $user_id, $notes);

                if ($stmt->execute()) {
                    $payment_id = $stmt->insert_id;
                    
                    // Mark fee as paid
                    $update = $conn->prepare("UPDATE pilgrims SET processing_fee_paid = 1 WHERE id = ?");
                    $update->bind_param("i", $pilgrim_id);
                    $update->execute();
                    $update->close();

                    $success = true;
                    $message = "Processing fee of GHS " . number_format($amount, 2) . " paid successfully for $first_name $surname!";
                } else {
                    $message = "Error recording processing fee: " . $stmt->error;
                }
                $stmt->close();
            }
        } else {
            // Normal Hajj payment
            if ($amount > $current_balance) {
                $message = "Amount cannot exceed remaining Hajj balance (GHS " . number_format($current_balance, 2) . ")";
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO payments 
                    (pilgrim_id, amount, payment_method, receipt_number, recorded_by, notes, is_processing_fee) 
                    VALUES (?, ?, ?, ?, ?, ?, 0)
                ");
                $user_id = $_SESSION['user_id'];
                $stmt->bind_param("idssis", $pilgrim_id, $amount, $payment_method, $receipt_number, $user_id, $notes);

                if ($stmt->execute()) {
                    $payment_id = $stmt->insert_id;
                    
                    $new_balance = $current_balance - $amount;
                    $status = ($new_balance <= 0) ? 'paid' : 'registered';

                    $update_stmt = $conn->prepare("UPDATE pilgrims SET balance = ?, status = ? WHERE id = ?");
                    $update_stmt->bind_param("dsi", $new_balance, $status, $pilgrim_id);
                    $update_stmt->execute();
                    $update_stmt->close();

                    $success = true;
                    $message = "Hajj payment of GHS " . number_format($amount, 2) . " recorded successfully for $first_name $surname! New balance: GHS " . number_format($new_balance, 2);
                } else {
                    $message = "Error recording Hajj payment: " . $stmt->error;
                }
                $stmt->close();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Record Payment</title>
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
        }

        * { margin:0; padding:0; box-sizing:border-box; }
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: var(--text);
            min-height: 100vh;
            line-height: 1.6;
        }

        .container { max-width: 1200px; margin:0 auto; padding:24px 16px; }

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

        h1.main-title {
            text-align:center; 
            font-size:2rem; 
            margin:32px 0 32px; 
            background: linear-gradient(135deg, #60a5fa, #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-weight:700;
        }

        .message {
            padding: 16px 24px;
            border-radius: 12px;
            margin-bottom: 24px;
            text-align: center;
            font-weight: 500;
            animation: slideDown 0.3s ease;
        }

        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .success {
            background: rgba(16,185,129,0.15);
            color: #6ee7b7;
            border: 1px solid rgba(16,185,129,0.3);
        }

        .error {
            background: rgba(239,68,68,0.15);
            color: #fca5a5;
            border: 1px solid rgba(239,68,68,0.3);
        }

        .receipt-link {
            display: block;
            margin-top: 12px;
            color: #60a5fa;
            text-decoration: none;
            font-weight: 600;
        }

        .receipt-link:hover {
            text-decoration: underline;
        }

        .main-grid {
            display: grid;
            grid-template-columns: 1fr 350px;
            gap: 24px;
            align-items: start;
        }

        .payment-form-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 32px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.35);
        }

        .pilgrim-details-card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.35);
            position: sticky;
            top: 100px;
        }

        .pilgrim-details-card h3 {
            color: #60a5fa;
            margin-bottom: 16px;
            font-size: 1.2rem;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid rgba(51,65,85,0.3);
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            color: var(--muted);
            font-size: 0.9rem;
        }

        .detail-value {
            font-weight: 600;
            text-align: right;
        }

        .payment-history {
            margin-top: 24px;
            padding-top: 24px;
            border-top: 2px solid rgba(51,65,85,0.5);
        }

        .payment-history h4 {
            color: #60a5fa;
            margin-bottom: 12px;
            font-size: 1rem;
        }

        .history-item {
            padding: 8px 0;
            font-size: 0.85rem;
            border-bottom: 1px solid rgba(51,65,85,0.2);
        }

        .history-item:last-child {
            border-bottom: none;
        }

        .empty-details {
            text-align: center;
            padding: 40px 20px;
            color: var(--muted);
            font-style: italic;
        }

        .form-group {
            margin-bottom: 20px;
            position: relative;
        }

        .form-group label {
            display: block;
            color: var(--muted);
            font-weight: 500;
            margin-bottom: 8px;
            font-size: 0.9rem;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            background: #1e293b;
            color: var(--text);
            border: 1px solid #475569;
            border-radius: 8px;
            font-size: 1rem;
            transition: all 0.2s;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
        }

        .suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: #1e293b;
            border: 1px solid #475569;
            border-radius: 8px;
            max-height: 300px;
            overflow-y: auto;
            z-index: 100;
            box-shadow: 0 8px 24px rgba(0,0,0,0.4);
            display: none;
        }

        .suggestion-item {
            padding: 12px 16px;
            cursor: pointer;
            border-bottom: 1px solid rgba(71,85,105,0.5);
            transition: all 0.2s;
        }

        .suggestion-item:hover {
            background: rgba(59,130,246,0.15);
        }

        .suggestion-item:last-child {
            border-bottom: none;
        }

        .suggestion-name {
            font-weight: 600;
            margin-bottom: 4px;
        }

        .suggestion-details {
            font-size: 0.85rem;
            color: var(--muted);
        }

        .fee-notice {
            background: rgba(245,158,11,0.15);
            border: 1px solid rgba(245,158,11,0.3);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
            color: #fbbf24;
        }

        .quick-amounts {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-bottom: 16px;
        }

        .quick-amount-btn {
            padding: 10px;
            background: rgba(59,130,246,0.15);
            border: 1px solid rgba(59,130,246,0.3);
            border-radius: 6px;
            color: #60a5fa;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.2s;
            font-size: 0.9rem;
        }

        .quick-amount-btn:hover {
            background: rgba(59,130,246,0.25);
            transform: translateY(-2px);
        }

        .btn-record {
            width: 100%;
            padding: 14px 24px;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 1.1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 8px;
        }

        .btn-record:hover {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(59,130,246,0.4);
        }

        .btn-record:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }

        @media (max-width: 968px) {
            .main-grid {
                grid-template-columns: 1fr;
            }

            .pilgrim-details-card {
                position: static;
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
            <a href="finance_payments.php" class="active">Record Payment</a>
            <a href="finance_pilgrims.php">Pilgrims</a>
            <a href="logout.php" class="logout">Logout</a>
        </nav>
    </div>
</header>

<div class="container">

    <h1 class="main-title">Record Payment</h1>

    <?php if ($message): ?>
        <div class="message <?php echo $success ? 'success' : 'error'; ?>">
            <?php echo $message; ?>
            <?php if ($success && $payment_id > 0): ?>
                <a href="print_receipt.php?id=<?php echo $payment_id; ?>" target="_blank" class="receipt-link">
                    📄 Print Receipt
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="main-grid">
        <!-- Payment Form -->
        <div class="payment-form-card">
            <form method="POST" id="paymentForm">
                <div class="form-group">
                    <label for="pilgrim_search">Search Pilgrim *</label>
                    <input 
                        type="text" 
                        id="pilgrim_search" 
                        placeholder="Search by name, file number, ID, or phone..."
                        autocomplete="off"
                    >
                    <input type="hidden" name="pilgrim_id" id="pilgrim_id" required>
                    <div class="suggestions" id="suggestions"></div>
                </div>

                <!-- Processing Fee Notice -->
                <div id="processing-fee-notice" class="fee-notice" style="display:none;">
                    <strong>⚠️ Processing Fee Required First</strong><br>
                    <span id="fee-amount-display"></span><br>
                    Please pay <strong>exactly</strong> the amount shown above.
                    <input type="hidden" name="is_processing_fee" id="is_processing_fee" value="0">
                </div>

                <!-- Quick Amount Buttons -->
                <div id="quick-amounts" style="display:none;">
                    <label style="color: var(--muted); font-size: 0.9rem; margin-bottom: 8px; display: block;">Quick Amount Selection:</label>
                    <div class="quick-amounts" id="quick-amounts-grid"></div>
                </div>

                <div class="form-group">
                    <label for="amount">Amount Paid (GHS) *</label>
                    <input 
                        type="number" 
                        name="amount" 
                        id="amount" 
                        step="0.01" 
                        min="0.01" 
                        required 
                        placeholder="Enter amount"
                    >
                </div>

                <div class="form-group">
                    <label for="payment_method">Payment Method *</label>
                    <select name="payment_method" id="payment_method" required>
                        <option value="">-- Select Method --</option>
                        <option value="Cash">Cash</option>
                        <option value="Bank Transfer">Bank Transfer</option>
                        <option value="Mobile Money">Mobile Money</option>
                        <option value="Other">Other</option>
                    </select>
                </div>

                <div class="form-group">
                    <label for="receipt_number">Receipt Number (Optional)</label>
                    <input type="text" name="receipt_number" id="receipt_number" placeholder="e.g. REC-123456">
                </div>

                <div class="form-group">
                    <label for="notes">Notes (Optional)</label>
                    <textarea name="notes" id="notes" rows="3" placeholder="Any additional information..."></textarea>
                </div>

                <button type="submit" class="btn-record" id="submitBtn">
                    <span id="submitText">Record Payment</span>
                </button>
            </form>
        </div>

        <!-- Pilgrim Details Sidebar -->
        <div class="pilgrim-details-card">
            <h3>Pilgrim Details</h3>
            <div id="pilgrim-details-content">
                <div class="empty-details">
                    Select a pilgrim to view details
                </div>
            </div>
        </div>
    </div>

</div>

<script>
const searchInput = document.getElementById('pilgrim_search');
const suggestionsDiv = document.getElementById('suggestions');
const pilgrimIdInput = document.getElementById('pilgrim_id');
const amountInput = document.getElementById('amount');
const feeNotice = document.getElementById('processing-fee-notice');
const feeAmountDisplay = document.getElementById('fee-amount-display');
const isProcessingInput = document.getElementById('is_processing_fee');
const detailsContent = document.getElementById('pilgrim-details-content');
const quickAmountsDiv = document.getElementById('quick-amounts');
const quickAmountsGrid = document.getElementById('quick-amounts-grid');
const submitBtn = document.getElementById('submitBtn');
const submitText = document.getElementById('submitText');

let searchTimeout;
let selectedPilgrim = null;

// Search pilgrim
searchInput.addEventListener('input', function() {
    clearTimeout(searchTimeout);
    const query = this.value.trim();

    if (query.length < 2) {
        suggestionsDiv.innerHTML = '';
        suggestionsDiv.style.display = 'none';
        pilgrimIdInput.value = '';
        resetForm();
        return;
    }

    searchTimeout = setTimeout(() => {
        fetch(`finance_payments.php?q=${encodeURIComponent(query)}`)
            .then(r => r.json())
            .then(data => {
                suggestionsDiv.innerHTML = '';
                if (data.length === 0) {
                    suggestionsDiv.style.display = 'none';
                    return;
                }

                data.forEach(item => {
                    const div = document.createElement('div');
                    div.className = 'suggestion-item';
                    div.innerHTML = `
                        <div class="suggestion-name">${item.first_name} ${item.surname}</div>
                        <div class="suggestion-details">
                            File: ${item.file_number || 'N/A'} • Balance: GHS ${parseFloat(item.balance).toFixed(2)}
                            ${item.processing_fee_paid == 0 ? ' • <span style="color:#fbbf24">⚠️ Processing fee pending</span>' : ''}
                        </div>
                    `;
                    div.onclick = () => selectPilgrim(item);
                    suggestionsDiv.appendChild(div);
                });

                suggestionsDiv.style.display = 'block';
            })
            .catch(err => console.error(err));
    }, 300);
});

function selectPilgrim(item) {
    selectedPilgrim = item;
    searchInput.value = `${item.first_name} ${item.surname} (File: ${item.file_number || 'N/A'})`;
    pilgrimIdInput.value = item.id;
    suggestionsDiv.innerHTML = '';
    suggestionsDiv.style.display = 'none';

    // Load full details
    loadPilgrimDetails(item.id);

    // Show processing fee notice if unpaid
    if (item.processing_fee_paid == 0 && parseFloat(item.processing_fee_amount) > 0) {
        feeNotice.style.display = 'block';
        feeAmountDisplay.textContent = `Required: GHS ${parseFloat(item.processing_fee_amount).toFixed(2)}`;
        isProcessingInput.value = '1';
        amountInput.value = parseFloat(item.processing_fee_amount).toFixed(2);
        amountInput.setAttribute('readonly', 'readonly');
        quickAmountsDiv.style.display = 'none';
        submitText.textContent = 'Pay Processing Fee';
    } else {
        feeNotice.style.display = 'none';
        isProcessingInput.value = '0';
        amountInput.removeAttribute('readonly');
        amountInput.value = '';
        generateQuickAmounts(parseFloat(item.balance));
        submitText.textContent = 'Record Payment';
    }
}

function loadPilgrimDetails(pilgrimId) {
    fetch(`finance_payments.php?get_pilgrim=${pilgrimId}`)
        .then(r => r.json())
        .then(data => {
            const p = data.pilgrim;
            const payments = data.payments;
            const totalPaid = data.total_paid;

            let html = `
                <div class="detail-row">
                    <span class="detail-label">Name:</span>
                    <span class="detail-value">${p.first_name} ${p.surname}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">File Number:</span>
                    <span class="detail-value">${p.file_number || '—'}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Contact:</span>
                    <span class="detail-value">${p.contact || '—'}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Total Paid:</span>
                    <span class="detail-value" style="color: #34d399;">GHS ${parseFloat(totalPaid).toFixed(2)}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Balance:</span>
                    <span class="detail-value" style="color: #fbbf24;">GHS ${parseFloat(p.balance).toFixed(2)}</span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">Processing Fee:</span>
                    <span class="detail-value" style="color: ${p.processing_fee_paid == 1 ? '#34d399' : '#f87171'};">
                        ${p.processing_fee_paid == 1 ? '✓ Paid' : '✗ Pending'}
                    </span>
                </div>
            `;

            if (payments.length > 0) {
                html += `
                    <div class="payment-history">
                        <h4>Recent Payments</h4>
                `;
                payments.forEach(pay => {
                    html += `
                        <div class="history-item">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 2px;">
                                <span style="color: #34d399; font-weight: 600;">GHS ${parseFloat(pay.amount).toFixed(2)}</span>
                                <span style="color: var(--muted); font-size: 0.8rem;">${new Date(pay.payment_date).toLocaleDateString()}</span>
                            </div>
                            <div style="font-size: 0.8rem; color: var(--muted);">
                                ${pay.payment_method} • ${pay.is_processing_fee == 1 ? 'Processing Fee' : 'Hajj Payment'}
                            </div>
                        </div>
                    `;
                });
                html += `</div>`;
            }

            detailsContent.innerHTML = html;
        })
        .catch(err => {
            console.error(err);
            detailsContent.innerHTML = '<div class="empty-details">Error loading details</div>';
        });
}

function generateQuickAmounts(balance) {
    if (balance <= 0) {
        quickAmountsDiv.style.display = 'none';
        return;
    }

    const amounts = [];
    
    // Add common amounts based on balance
    if (balance >= 1000) amounts.push(1000);
    if (balance >= 2000) amounts.push(2000);
    if (balance >= 5000) amounts.push(5000);
    if (balance >= 10000) amounts.push(10000);
    
    // Always add 50% and full balance
    amounts.push(Math.round(balance / 2));
    amounts.push(balance);

    // Remove duplicates and sort
    const uniqueAmounts = [...new Set(amounts)].sort((a, b) => a - b).slice(-6);

    quickAmountsGrid.innerHTML = '';
    uniqueAmounts.forEach(amt => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'quick-amount-btn';
        btn.textContent = `GHS ${amt.toLocaleString()}`;
        btn.onclick = () => {
            amountInput.value = amt.toFixed(2);
        };
        quickAmountsGrid.appendChild(btn);
    });

    quickAmountsDiv.style.display = 'block';
}

function resetForm() {
    detailsContent.innerHTML = '<div class="empty-details">Select a pilgrim to view details</div>';
    feeNotice.style.display = 'none';
    isProcessingInput.value = '0';
    amountInput.removeAttribute('readonly');
    amountInput.value = '';
    quickAmountsDiv.style.display = 'none';
    submitText.textContent = 'Record Payment';
    selectedPilgrim = null;
}

// Hide suggestions on outside click
document.addEventListener('click', e => {
    if (!searchInput.contains(e.target) && !suggestionsDiv.contains(e.target)) {
        suggestionsDiv.style.display = 'none';
    }
});

// Form validation
document.getElementById('paymentForm').addEventListener('submit', function(e) {
    if (!pilgrimIdInput.value) {
        alert('Please select a pilgrim from the suggestions.');
        e.preventDefault();
        return;
    }

    submitBtn.disabled = true;
    submitText.textContent = 'Processing...';
});
</script>

</body>
</html>