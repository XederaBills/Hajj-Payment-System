<?php
session_start();

// Store user info before clearing session (for display purposes)
$username = $_SESSION['username'] ?? 'User';
$role = $_SESSION['role'] ?? '';

// Clear all session variables
$_SESSION = [];

// Destroy the session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

// Clear remember me cookies if they exist
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', '', time() - 3600, '/', '', true, true);
}
if (isset($_COOKIE['remember_user'])) {
    setcookie('remember_user', '', time() - 3600, '/', '', true, true);
}

// Destroy the session
session_destroy();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logging Out - Hajj Management System</title>
    <meta http-equiv="refresh" content="3;url=login.php">
    <style>
        :root {
            --bg-dark: #0f172a;
            --bg-card: rgba(30, 41, 59, 0.7);
            --border: rgba(51, 65, 85, 0.5);
            --text: #e2e8f0;
            --text-muted: #94a3b8;
            --primary: #3b82f6;
            --success: #10b981;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', system-ui, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }

        /* Animated background */
        body::before {
            content: '';
            position: absolute;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.03) 1px, transparent 1px);
            background-size: 50px 50px;
            animation: float 20s linear infinite;
        }

        @keyframes float {
            0% { transform: translate(0, 0); }
            100% { transform: translate(-50px, -50px); }
        }

        .logout-container {
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 60px 48px;
            max-width: 520px;
            width: 100%;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
            position: relative;
            z-index: 1;
            animation: fadeIn 0.5s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: scale(0.95);
            }
            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .icon-wrapper {
            width: 100px;
            height: 100px;
            margin: 0 auto 32px;
            background: linear-gradient(135deg, #10b981, #059669);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 32px rgba(16, 185, 129, 0.3);
            animation: scaleIn 0.6s cubic-bezier(0.68, -0.55, 0.265, 1.55);
        }

        @keyframes scaleIn {
            0% {
                transform: scale(0) rotate(-180deg);
                opacity: 0;
            }
            100% {
                transform: scale(1) rotate(0deg);
                opacity: 1;
            }
        }

        .checkmark {
            width: 50px;
            height: 50px;
            stroke: white;
            stroke-width: 3;
            stroke-linecap: round;
            stroke-linejoin: round;
            fill: none;
            stroke-dasharray: 100;
            stroke-dashoffset: 100;
            animation: drawCheck 0.8s ease-out 0.3s forwards;
        }

        @keyframes drawCheck {
            to {
                stroke-dashoffset: 0;
            }
        }

        h1 {
            font-size: 2rem;
            background: linear-gradient(135deg, #60a5fa, #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 16px;
            font-weight: 700;
        }

        .user-info {
            color: var(--text-muted);
            font-size: 1rem;
            margin-bottom: 24px;
        }

        .user-info strong {
            color: var(--text);
            font-weight: 600;
        }

        .message {
            color: var(--text-muted);
            font-size: 1.05rem;
            line-height: 1.6;
            margin-bottom: 32px;
        }

        .progress-bar {
            width: 100%;
            height: 4px;
            background: rgba(51, 65, 85, 0.5);
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 16px;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #3b82f6, #10b981);
            border-radius: 4px;
            animation: progress 3s linear forwards;
        }

        @keyframes progress {
            from {
                width: 0%;
            }
            to {
                width: 100%;
            }
        }

        .redirect-info {
            color: var(--text-muted);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }

        .redirect-info svg {
            width: 16px;
            height: 16px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .manual-link {
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid var(--border);
        }

        .manual-link a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 600;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .manual-link a:hover {
            color: #60a5fa;
            gap: 10px;
        }

        .manual-link svg {
            width: 18px;
            height: 18px;
        }

        .security-note {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 24px;
            padding: 12px 16px;
            background: rgba(16, 185, 129, 0.1);
            border-radius: 10px;
            color: #6ee7b7;
            font-size: 0.85rem;
        }

        .security-note svg {
            width: 16px;
            height: 16px;
            flex-shrink: 0;
        }

        @media (max-width: 480px) {
            .logout-container {
                padding: 48px 32px;
            }

            h1 {
                font-size: 1.75rem;
            }

            .icon-wrapper {
                width: 80px;
                height: 80px;
            }

            .checkmark {
                width: 40px;
                height: 40px;
            }
        }

        /* Fade out animation before redirect */
        @keyframes fadeOut {
            to {
                opacity: 0;
                transform: scale(0.95);
            }
        }

        .fade-out {
            animation: fadeOut 0.3s ease-out forwards;
        }
    </style>
</head>
<body>
    <div class="logout-container" id="logoutContainer">
        <div class="icon-wrapper">
            <svg class="checkmark" viewBox="0 0 52 52">
                <path d="M14 27l7.5 7.5L38 18"/>
            </svg>
        </div>

        <h1>Successfully Logged Out</h1>
        
        <?php if ($username !== 'User'): ?>
        <div class="user-info">
            Goodbye, <strong><?php echo htmlspecialchars($username); ?></strong>!
        </div>
        <?php endif; ?>

        <p class="message">
            Thank you for using the Hajj Management System.<br>
            Your session has been securely terminated.
        </p>

        <div class="progress-bar">
            <div class="progress-fill"></div>
        </div>

        <div class="redirect-info">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            Redirecting to login page...
        </div>

        <div class="security-note">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
            </svg>
            All sessions and cookies have been cleared
        </div>

        <div class="manual-link">
            <a href="login.php">
                Click here if you're not redirected automatically
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"/>
                </svg>
            </a>
        </div>
    </div>

    <script>
        // Fade out before redirect
        setTimeout(() => {
            document.getElementById('logoutContainer').classList.add('fade-out');
        }, 2700);

        // Redirect to login
        setTimeout(() => {
            window.location.href = 'login.php';
        }, 3000);
    </script>
</body>
</html>