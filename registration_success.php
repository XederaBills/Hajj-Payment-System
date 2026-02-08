<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'finance') {
    header('Location: login.php');
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registration Successful</title>
    <style>
        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: #0f172a;
            color: #e2e8f0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .success-card {
            background: rgba(30, 41, 59, 0.45);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(51, 65, 85, 0.5);
            border-radius: 16px;
            padding: 60px 48px;
            max-width: 600px;
            text-align: center;
            box-shadow: 0 12px 40px rgba(0,0,0,0.45);
        }
        .icon {
            font-size: 5rem;
            color: #34d399;
            margin-bottom: 24px;
        }
        h1 {
            color: #34d399;
            font-size: 2.4rem;
            margin-bottom: 16px;
        }
        p {
            font-size: 1.2rem;
            color: #94a3b8;
            margin-bottom: 32px;
        }
        .btn {
            display: inline-block;
            padding: 16px 40px;
            background: #3b82f6;
            color: white;
            text-decoration: none;
            border-radius: 12px;
            font-size: 1.15rem;
            font-weight: 600;
            transition: all 0.25s;
        }
        .btn:hover {
            background: #2563eb;
            transform: translateY(-2px);
        }
    </style>
</head>
<body>

<div class="success-card">
    <div class="icon">✓</div>
    <h1>Registration Successful!</h1>
    <p>Pilgrim has been successfully registered.</p>
    <?php if ($id > 0): ?>
        <p style="font-size: 1.1rem; color: #cbd5e1;">
            Pilgrim ID: <strong>#<?php echo $id; ?></strong>
        </p>
    <?php endif; ?>

    <div style="margin-top: 40px;">
        <a href="finance_register.php" class="btn">Register Another Pilgrim</a>
        <a href="finance_pilgrims.php" class="btn" style="margin-left: 16px; background: #475569;">View All Pilgrims</a>
    </div>
</div>

</body>
</html>