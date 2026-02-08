<?php
session_start();

// Allow both admin and finance roles
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'finance'])) {
    header('Location: login.php');
    exit;
}

include 'config.php';

// Fetch all deferred pilgrims
$deferred_query = $conn->query("
    SELECT 
        p.*,
        COALESCE(SUM(CASE WHEN pay.is_processing_fee = 0 THEN pay.amount ELSE 0 END), 0) as total_paid,
        COUNT(pay.id) as payment_count
    FROM pilgrims p
    LEFT JOIN payments pay ON p.id = pay.pilgrim_id
    WHERE p.status = 'deferred'
    GROUP BY p.id
    ORDER BY p.deferred_to_year ASC, p.registration_date DESC
");

$deferred_pilgrims = $deferred_query->fetch_all(MYSQLI_ASSOC);

// Get available years for reactivation
$available_years_query = $conn->query("
    SELECT hajj_year, package_cost, processing_fee 
    FROM hajj_costs 
    WHERE hajj_year >= YEAR(CURDATE())
    ORDER BY hajj_year ASC
");
$available_years = $available_years_query->fetch_all(MYSQLI_ASSOC);

// Success/Error messages
$message = '';
$success = false;

if (isset($_GET['success'])) {
    if ($_GET['success'] === 'reactivated') {
        $name = $_GET['name'] ?? 'Pilgrim';
        $year = $_GET['year'] ?? '';
        $message = "$name has been successfully reactivated for Hajj year $year!";
        $success = true;
    } elseif ($_GET['success'] === 'deferred') {
        $name = $_GET['name'] ?? 'Pilgrim';
        $year = $_GET['year'] ?? '';
        $message = "$name has been successfully deferred to year $year!";
        $success = true;
    }
}

if (isset($_GET['error'])) {
    if ($_GET['error'] === 'reactivate_failed') {
        $msg = $_GET['message'] ?? 'Unknown error';
        $message = "Reactivation failed: $msg";
        $success = false;
    } elseif ($_GET['error'] === 'invalid_data') {
        $message = "Invalid data provided. Please try again.";
        $success = false;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Deferred Pilgrims - Management</title>
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

        .message {
            padding: 16px 24px;
            border-radius: 12px;
            margin: 0 0 32px;
            text-align: center;
            font-size: 1rem;
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

        .stats-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 32px;
        }

        .stat-box {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            text-align: center;
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

        .table-container {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 8px 32px rgba(0,0,0,0.35);
        }

        .pilgrims-table {
            width: 100%;
            border-collapse: collapse;
        }

        .pilgrims-table th {
            background: rgba(15,23,42,0.9);
            color: #cbd5e1;
            padding: 16px 20px;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
            text-align: left;
        }

        .pilgrims-table td {
            padding: 16px 20px;
            border-top: 1px solid rgba(51,65,85,0.3);
        }

        .pilgrims-table tbody tr {
            transition: all 0.2s;
        }

        .pilgrims-table tbody tr:hover {
            background: rgba(59,130,246,0.08);
        }

        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .badge-year {
            background: rgba(245,158,11,0.2);
            color: #fbbf24;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-reactivate {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
        }

        .btn-reactivate:hover {
            background: linear-gradient(135deg, #059669, #047857);
            transform: translateY(-1px);
        }

        .btn-view {
            background: rgba(59,130,246,0.2);
            color: #60a5fa;
        }

        .btn-view:hover {
            background: rgba(59,130,246,0.3);
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

        .empty-state p {
            font-size: 1rem;
        }

        /* Modal Styles */
        .modal {
            display: none;
            position: fixed;
            z-index: 2000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.7);
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }

        .modal-content {
            background: linear-gradient(135deg, rgba(30,41,59,0.95) 0%, rgba(15,23,42,0.95) 100%);
            margin: 10% auto;
            padding: 32px;
            border: 1px solid var(--border);
            border-radius: 16px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(50px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }

        .modal-header h2 {
            color: #60a5fa;
            font-size: 1.5rem;
        }

        .close {
            color: var(--muted);
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.2s;
        }

        .close:hover {
            color: var(--text);
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: var(--muted);
            font-weight: 500;
        }

        .form-group select {
            width: 100%;
            padding: 12px 16px;
            font-size: 1rem;
            border: 1px solid #475569;
            border-radius: 8px;
            background: #1e293b;
            color: var(--text);
        }

        .form-group select:focus {
            outline: none;
            border-color: var(--primary);
        }

        .pilgrim-info {
            background: rgba(59,130,246,0.1);
            border: 1px solid rgba(59,130,246,0.3);
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 20px;
        }

        .info-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }

        .info-label {
            color: var(--muted);
        }

        .info-value {
            color: var(--text);
            font-weight: 600;
        }

        .modal-actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }

        .btn-submit {
            flex: 1;
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            padding: 12px;
            font-size: 1rem;
        }

        .btn-submit:hover {
            background: linear-gradient(135deg, #059669, #047857);
        }

        .btn-cancel {
            flex: 1;
            background: rgba(71,85,105,0.3);
            color: var(--text);
            padding: 12px;
            font-size: 1rem;
        }

        .btn-cancel:hover {
            background: rgba(71,85,105,0.5);
        }

        @media (max-width: 768px) {
            .pilgrims-table {
                font-size: 0.85rem;
            }
            
            .pilgrims-table th,
            .pilgrims-table td {
                padding: 12px 10px;
            }

            .stats-summary {
                grid-template-columns: 1fr;
            }
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
            <a href="admin_payments.php">Payments</a>
            <a href="admin_set_cost.php">Package Cost</a>
            <a href="admin_deferred.php" >Deferred</a>
             <a href="admin_reports.php" >Reports</a>
            <a href="admin_users.php">Users</a>
            <a href="logout.php" class="logout">Logout</a>
        </nav>
    </div>
</header>

<div class="container">

    <h1 class="main-title">Deferred Pilgrims Management</h1>

    <?php if ($message): ?>
        <div class="message <?php echo $success ? 'success' : 'error'; ?>">
            <?php echo htmlspecialchars($message); ?>
        </div>
    <?php endif; ?>

    <!-- Statistics Summary -->
    <div class="stats-summary">
        <div class="stat-box">
            <div class="stat-label">Total Deferred</div>
            <div class="stat-value"><?php echo count($deferred_pilgrims); ?></div>
        </div>
        <?php
        $total_paid_deferred = array_sum(array_column($deferred_pilgrims, 'total_paid'));
        $total_balance_deferred = array_sum(array_column($deferred_pilgrims, 'balance'));
        ?>
        <div class="stat-box">
            <div class="stat-label">Total Payments</div>
            <div class="stat-value" style="color: #34d399;">₵ <?php echo number_format($total_paid_deferred, 0); ?></div>
        </div>
        <div class="stat-box">
            <div class="stat-label">Total Balance</div>
            <div class="stat-value" style="color: #fbbf24;">₵ <?php echo number_format($total_balance_deferred, 0); ?></div>
        </div>
    </div>

    <!-- Deferred Pilgrims Table -->
    <?php if (empty($deferred_pilgrims)): ?>
        <div class="table-container">
            <div class="empty-state">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <h3>No Deferred Pilgrims</h3>
                <p>All pilgrims are currently active. Deferred pilgrims will appear here.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="table-container">
            <table class="pilgrims-table">
                <thead>
                    <tr>
                        <th>File #</th>
                        <th>Pilgrim Name</th>
                        <th>Contact</th>
                        <th>Deferred To</th>
                        <th>Total Paid</th>
                        <th>Balance</th>
                        <th>Payments</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($deferred_pilgrims as $pilgrim): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($pilgrim['file_number'] ?? '—'); ?></strong></td>
                        <td><?php echo htmlspecialchars($pilgrim['first_name'] . ' ' . $pilgrim['surname']); ?></td>
                        <td><?php echo htmlspecialchars($pilgrim['contact'] ?? '—'); ?></td>
                        <td><span class="badge badge-year">Year <?php echo $pilgrim['deferred_to_year']; ?></span></td>
                        <td style="color: #34d399;"><strong>₵ <?php echo number_format($pilgrim['total_paid'], 2); ?></strong></td>
                        <td style="color: #fbbf24;"><strong>₵ <?php echo number_format($pilgrim['balance'], 2); ?></strong></td>
                        <td><?php echo $pilgrim['payment_count']; ?> payments</td>
                        <td>
                            <button onclick="openReactivateModal(<?php echo htmlspecialchars(json_encode($pilgrim)); ?>)" class="btn btn-reactivate">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                </svg>
                                Reactivate
                            </button>
                            <a href="admin_pilgrim_view.php?id=<?php echo $pilgrim['id']; ?>" class="btn btn-view">
                                <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                </svg>
                                View
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

</div>

<!-- Reactivation Modal -->
<div id="reactivateModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Reactivate Pilgrim</h2>
            <span class="close" onclick="closeReactivateModal()">&times;</span>
        </div>
        
        <form action="handle_defer_reactivate.php" method="POST" id="reactivateForm">
            <input type="hidden" name="action" value="reactivate">
            <input type="hidden" name="pilgrim_id" id="modal_pilgrim_id">
            
            <div class="pilgrim-info" id="pilgrimInfo">
                <!-- Will be populated by JavaScript -->
            </div>
            
            <div class="form-group">
                <label for="reactivate_year">Select Hajj Year to Reactivate For *</label>
                <select name="reactivate_year" id="reactivate_year" required>
                    <option value="">-- Select Year --</option>
                    <?php foreach ($available_years as $year): ?>
                        <option value="<?php echo $year['hajj_year']; ?>">
                            Year <?php echo $year['hajj_year']; ?> 
                            (Package: GHS <?php echo number_format($year['package_cost'], 0); ?>, 
                            Fee: GHS <?php echo number_format($year['processing_fee'], 0); ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="modal-actions">
                <button type="button" class="btn btn-cancel" onclick="closeReactivateModal()">Cancel</button>
                <button type="submit" class="btn btn-submit">
                    <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    Reactivate Pilgrim
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const modal = document.getElementById('reactivateModal');

function openReactivateModal(pilgrim) {
    document.getElementById('modal_pilgrim_id').value = pilgrim.id;
    
    const pilgrimInfo = `
        <div class="info-row">
            <span class="info-label">Name:</span>
            <span class="info-value">${pilgrim.first_name} ${pilgrim.surname}</span>
        </div>
        <div class="info-row">
            <span class="info-label">File Number:</span>
            <span class="info-value">${pilgrim.file_number || '—'}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Currently Deferred To:</span>
            <span class="info-value">Year ${pilgrim.deferred_to_year}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Total Paid:</span>
            <span class="info-value" style="color: #34d399;">GHS ${parseFloat(pilgrim.total_paid).toLocaleString('en-US', {minimumFractionDigits: 2})}</span>
        </div>
        <div class="info-row">
            <span class="info-label">Current Balance:</span>
            <span class="info-value" style="color: #fbbf24;">GHS ${parseFloat(pilgrim.balance).toLocaleString('en-US', {minimumFractionDigits: 2})}</span>
        </div>
    `;
    
    document.getElementById('pilgrimInfo').innerHTML = pilgrimInfo;
    modal.style.display = 'block';
}

function closeReactivateModal() {
    modal.style.display = 'none';
}

window.onclick = function(event) {
    if (event.target == modal) {
        closeReactivateModal();
    }
}
</script>

</body>
</html>