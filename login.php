<?php
session_start();

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    $redirect = $_SESSION['role'] === 'admin' ? 'admin_dashboard.php' : 'finance_dashboard.php';
    header('Location: ' . $redirect);
    exit;
}

include 'config.php';

$message = '';
$message_type = 'error'; // 'error' or 'success'

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $remember = isset($_POST['remember']);

    // Basic validation
    if (empty($username) || empty($password)) {
        $message = "Please fill in both username and password.";
        $message_type = 'error';
    } else {
        // Rate limiting check (simple implementation)
        $ip = $_SERVER['REMOTE_ADDR'];
        $login_attempts_key = 'login_attempts_' . md5($ip);
        
        if (!isset($_SESSION[$login_attempts_key])) {
            $_SESSION[$login_attempts_key] = ['count' => 0, 'time' => time()];
        }
        
        // Reset attempts after 15 minutes
        if (time() - $_SESSION[$login_attempts_key]['time'] > 900) {
            $_SESSION[$login_attempts_key] = ['count' => 0, 'time' => time()];
        }
        
        // Check if too many attempts
        if ($_SESSION[$login_attempts_key]['count'] >= 5) {
            $wait_time = 900 - (time() - $_SESSION[$login_attempts_key]['time']);
            $minutes = ceil($wait_time / 60);
            $message = "Too many failed login attempts. Please try again in {$minutes} minute(s).";
            $message_type = 'error';
        } else {
            // Attempt login
            $stmt = $conn->prepare("SELECT id, username, full_name, role, password FROM users WHERE username = ? AND status = 'active'");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                
                if (password_verify($password, $user['password'])) {
                    // Successful login - reset attempts
                    unset($_SESSION[$login_attempts_key]);
                    
                    // Set session variables
                    session_regenerate_id(true); // Prevent session fixation
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['full_name'] = $user['full_name'];
                    $_SESSION['role'] = $user['role'];
                    $_SESSION['login_time'] = time();
                    
                    // Handle remember me
                    if ($remember) {
                        // Create a secure token
                        $token = bin2hex(random_bytes(32));
                        $expires = time() + (30 * 24 * 60 * 60); // 30 days
                        
                        // Store token in database (you should create a remember_tokens table)
                        // For now, we'll use a simple cookie
                        setcookie('remember_token', $token, $expires, '/', '', true, true);
                        setcookie('remember_user', $user['id'], $expires, '/', '', true, true);
                    }
                    
                    // Redirect based on role
                    $redirect = $user['role'] === 'admin' ? 'admin_dashboard.php' : 'finance_dashboard.php';
                    header('Location: ' . $redirect);
                    exit;
                } else {
                    // Invalid password
                    $_SESSION[$login_attempts_key]['count']++;
                    $_SESSION[$login_attempts_key]['time'] = time();
                    $remaining = 5 - $_SESSION[$login_attempts_key]['count'];
                    $message = "Invalid username or password. {$remaining} attempt(s) remaining.";
                    $message_type = 'error';
                }
            } else {
                // User not found or inactive
                $_SESSION[$login_attempts_key]['count']++;
                $_SESSION[$login_attempts_key]['time'] = time();
                $remaining = 5 - $_SESSION[$login_attempts_key]['count'];
                $message = "Invalid username or password. {$remaining} attempt(s) remaining.";
                $message_type = 'error';
            }
            
            $stmt->close();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Hajj Management System</title>
    <style>
        :root {
            --bg-dark: #0f172a;
            --bg-card: rgba(30, 41, 59, 0.6);
            --border: rgba(51, 65, 85, 0.5);
            --text: #e2e8f0;
            --text-muted: #94a3b8;
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --error: #ef4444;
            --error-bg: rgba(239, 68, 68, 0.18);
            --success: #10b981;
            --success-bg: rgba(16, 185, 129, 0.18);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }

        /* Animated background particles */
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

        .login-container {
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 48px 40px;
            width: 100%;
            max-width: 480px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
            text-align: center;
            position: relative;
            z-index: 1;
            animation: slideUp 0.5s ease-out;
        }

        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .logo-section {
            margin-bottom: 40px;
        }

        .logo-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 20px;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 8px 24px rgba(59, 130, 246, 0.3);
        }

        .logo-icon svg {
            width: 48px;
            height: 48px;
            color: white;
        }

        .logo-title {
            font-size: 2rem;
            font-weight: 700;
            background: linear-gradient(135deg, #60a5fa, #3b82f6);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .subtitle {
            color: var(--text-muted);
            margin-bottom: 0;
            font-size: 1rem;
            font-weight: 500;
        }

        .message {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 28px;
            font-weight: 500;
            font-size: 0.95rem;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: shake 0.3s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-10px); }
            75% { transform: translateX(10px); }
        }

        .message-error {
            background: var(--error-bg);
            color: #fca5a5;
            border-left: 4px solid var(--error);
        }

        .message-success {
            background: var(--success-bg);
            color: #6ee7b7;
            border-left: 4px solid var(--success);
        }

        .message svg {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }

        .form-group {
            margin-bottom: 24px;
            text-align: left;
        }

        .form-group label {
            display: block;
            color: #cbd5e1;
            font-weight: 600;
            margin-bottom: 8px;
            font-size: 0.95rem;
        }

        .input-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            width: 20px;
            height: 20px;
        }

        .form-group input {
            width: 100%;
            padding: 14px 16px 14px 48px;
            font-size: 1rem;
            border: 2px solid #334155;
            border-radius: 12px;
            background: #1e293b;
            color: var(--text);
            transition: all 0.3s;
            font-family: inherit;
        }

        .form-group input::placeholder {
            color: #64748b;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(59, 130, 246, 0.15);
            background: #1e293b;
        }

        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
        }

        .remember-me {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            user-select: none;
        }

        .remember-me input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--primary);
        }

        .remember-me label {
            font-size: 0.9rem;
            color: var(--text-muted);
            cursor: pointer;
            margin: 0;
        }

        .forgot-password {
            color: var(--primary);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
            transition: color 0.2s;
        }

        .forgot-password:hover {
            color: #60a5fa;
        }

        .btn-login {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #3b82f6, #2563eb);
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 1.05rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 16px rgba(59, 130, 246, 0.3);
        }

        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 24px rgba(59, 130, 246, 0.4);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        .btn-login:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .btn-login.loading::after {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            animation: loading 1.5s infinite;
        }

        @keyframes loading {
            to { left: 100%; }
        }

        .footer-section {
            margin-top: 36px;
            padding-top: 24px;
            border-top: 1px solid rgba(51, 65, 85, 0.5);
        }

        .footer-text {
            color: #64748b;
            font-size: 0.9rem;
        }

        .system-info {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-top: 16px;
            flex-wrap: wrap;
        }

        .info-badge {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.85rem;
            color: var(--text-muted);
        }

        .info-badge svg {
            width: 16px;
            height: 16px;
        }

        @media (max-width: 480px) {
            .login-container {
                padding: 36px 24px;
            }
            
            .logo-title {
                font-size: 1.75rem;
            }

            .logo-icon {
                width: 64px;
                height: 64px;
            }

            .logo-icon svg {
                width: 36px;
                height: 36px;
            }

            .form-options {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
        }

        /* Password toggle */
        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--text-muted);
            transition: color 0.2s;
        }

        .password-toggle:hover {
            color: var(--primary);
        }

        .password-toggle svg {
            width: 20px;
            height: 20px;
        }
    </style>
</head>
<body>

<div class="login-container">
    <div class="logo-section">
        <div class="logo-icon">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/>
            </svg>
        </div>
        <h1 class="logo-title">USTAZ HASSAN</h1>
        <div class="subtitle">Hajj & Umrah Management System</div>
    </div>

    <?php if ($message): ?>
        <div class="message message-<?php echo $message_type; ?>">
            <?php if ($message_type === 'error'): ?>
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            <?php else: ?>
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            <?php endif; ?>
            <span><?php echo htmlspecialchars($message); ?></span>
        </div>
    <?php endif; ?>

    <form method="POST" id="loginForm">
        <div class="form-group">
            <label for="username">Username</label>
            <div class="input-wrapper">
                <svg class="input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                </svg>
                <input 
                    type="text" 
                    name="username" 
                    id="username" 
                    placeholder="Enter your username" 
                    required 
                    autofocus
                    autocomplete="username"
                    value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                >
            </div>
        </div>

        <div class="form-group">
            <label for="password">Password</label>
            <div class="input-wrapper">
                <svg class="input-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                </svg>
                <input 
                    type="password" 
                    name="password" 
                    id="password" 
                    placeholder="Enter your password" 
                    required
                    autocomplete="current-password"
                >
                <span class="password-toggle" onclick="togglePassword()">
                    <svg id="eyeIcon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                    </svg>
                </span>
            </div>
        </div>

        <div class="form-options">
            <div class="remember-me">
                <input type="checkbox" name="remember" id="remember">
                <label for="remember">Remember me</label>
            </div>
            <!-- <a href="forgot_password.php" class="forgot-password">Forgot password?</a> -->
        </div>

        <button type="submit" class="btn-login" id="loginBtn">
            Sign In
        </button>
    </form>

    <div class="footer-section">
        <div class="footer-text">
            © <?php echo date('Y'); ?> USTAZ HASSAN TRAVEL AGENCY
        </div>
        <div class="system-info">
            <div class="info-badge">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/>
                </svg>
                Secure Login
            </div>
            <div class="info-badge">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                v1.0
            </div>
        </div>
    </div>
</div>

<script>
// Password toggle
function togglePassword() {
    const passwordInput = document.getElementById('password');
    const eyeIcon = document.getElementById('eyeIcon');
    
    if (passwordInput.type === 'password') {
        passwordInput.type = 'text';
        eyeIcon.innerHTML = `
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21"/>
        `;
    } else {
        passwordInput.type = 'password';
        eyeIcon.innerHTML = `
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
        `;
    }
}

// Form loading state
document.getElementById('loginForm').addEventListener('submit', function() {
    const btn = document.getElementById('loginBtn');
    btn.disabled = true;
    btn.classList.add('loading');
    btn.textContent = 'Signing in...';
});

// Auto-focus on username if empty
window.addEventListener('load', function() {
    const username = document.getElementById('username');
    if (!username.value) {
        username.focus();
    }
});
</script>

</body>
</html>