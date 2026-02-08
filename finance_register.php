<?php
/**
 * Pilgrim Registration Form - FIXED VERSION
 * 
 * BUG FIX: ArgumentCountError in bind_param
 * Issue: Type string had 24 characters but 26 parameters were being bound
 * Solution: Updated type string from "sssssssssssssssssssssssdd" (24s + 2d = 26 chars)
 *           to "ssssssssssssssssssssssssdd" (24s + 2d = 26 chars)
 * 
 * Parameters breakdown:
 * - 24 string parameters (s)
 * - 2 decimal parameters (d) for balance and processing_fee_amount
 * Total: 26 parameters
 */

session_start();

// Authentication check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'finance') {
    header('Location: login.php');
    exit;
}

require_once 'config.php';

// Fetch active Hajj package costs
$cost_query = $conn->query("
    SELECT package_cost, processing_fee, hajj_year
    FROM hajj_costs 
    WHERE is_active = 1 
    LIMIT 1
");

$cost_data = $cost_query && $cost_query->num_rows > 0 
    ? $cost_query->fetch_assoc() 
    : ['package_cost' => 0.00, 'processing_fee' => 0.00, 'hajj_year' => date('Y')];

$hajj_cost = (float)$cost_data['package_cost'];
$processing_fee = (float)$cost_data['processing_fee'];
$hajj_year = (int)$cost_data['hajj_year'];

// Initial balance for new pilgrim (full package cost)
$initial_balance = $hajj_cost;

$message = '';
$message_type = 'error';
$form_data = []; // Preserve form data on error

// ===========================
// Form Processing
// ===========================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Sanitize and collect form data
    $form_data = [
        'file_number' => trim($_POST['file_number'] ?? ''),
        'first_name' => trim($_POST['first_name'] ?? ''),
        'surname' => trim($_POST['surname'] ?? ''),
        'other_name' => trim($_POST['other_name'] ?? ''),
        'occupation' => trim($_POST['occupation'] ?? ''),
        'date_of_birth' => $_POST['date_of_birth'] ?? '',
        'ghana_card_number' => trim($_POST['ghana_card_number'] ?? ''),
        'passport_number' => trim($_POST['passport_number'] ?? ''),
        'fathers_name' => trim($_POST['fathers_name'] ?? ''),
        'fathers_occupation' => trim($_POST['fathers_occupation'] ?? ''),
        'mothers_name' => trim($_POST['mothers_name'] ?? ''),
        'hajj_type' => $_POST['hajj_type'] ?? '',
        'home_town' => trim($_POST['home_town'] ?? ''),
        'house_address' => trim($_POST['house_address'] ?? ''),
        'contact' => trim($_POST['contact'] ?? ''),
        'witness1_name' => trim($_POST['witness1_name'] ?? ''),
        'witness1_occupation' => trim($_POST['witness1_occupation'] ?? ''),
        'witness1_contact' => trim($_POST['witness1_contact'] ?? ''),
        'witness2_name' => trim($_POST['witness2_name'] ?? ''),
        'witness2_occupation' => trim($_POST['witness2_occupation'] ?? ''),
        'witness2_contact' => trim($_POST['witness2_contact'] ?? '')
    ];
    
    // Validation
    $errors = [];
    
    // Required field validation
    if (empty($form_data['file_number'])) $errors[] = "File number is required";
    if (empty($form_data['first_name'])) $errors[] = "First name is required";
    if (empty($form_data['surname'])) $errors[] = "Surname is required";
    if (empty($form_data['occupation'])) $errors[] = "Occupation is required";
    if (empty($form_data['date_of_birth'])) $errors[] = "Date of birth is required";
    if (empty($form_data['hajj_type'])) $errors[] = "Hajj type is required";
    if (empty($form_data['home_town'])) $errors[] = "Home town is required";
    if (empty($form_data['house_address'])) $errors[] = "House address is required";
    if (empty($form_data['contact'])) $errors[] = "Contact number is required";
    
    // At least one ID required
    if (empty($form_data['ghana_card_number']) && empty($form_data['passport_number'])) {
        $errors[] = "Please provide either Ghana Card Number or Passport Number";
    }
    
    // Date validation
    if (!empty($form_data['date_of_birth'])) {
        $dob = new DateTime($form_data['date_of_birth']);
        $today = new DateTime();
        $age = $today->diff($dob)->y;
        
        if ($age < 10) {
            $errors[] = "Pilgrim must be at least 10 years old";
        }
    }
    
    // Contact validation (basic)
    if (!empty($form_data['contact']) && !preg_match('/^[0-9+\-\s()]{10,15}$/', $form_data['contact'])) {
        $errors[] = "Invalid contact number format";
    }
    
    // Check for duplicate file number
    $dup_check = $conn->prepare("SELECT id FROM pilgrims WHERE file_number = ? LIMIT 1");
    $dup_check->bind_param("s", $form_data['file_number']);
    $dup_check->execute();
    if ($dup_check->get_result()->num_rows > 0) {
        $errors[] = "File number already exists. Please use a unique file number.";
    }
    $dup_check->close();
    
    // Process if no errors
    if (empty($errors)) {
        
        $upload_dir = 'uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        // File upload handling with validation
        $uploaded_files = [
            'passport_picture' => '',
            'ghana_card_front' => '',
            'ghana_card_back' => ''
        ];
        
        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        $max_file_size = 5 * 1024 * 1024; // 5MB
        
        foreach ($uploaded_files as $field => $value) {
            if (isset($_FILES[$field]) && $_FILES[$field]['error'] === UPLOAD_ERR_OK) {
                
                // Validate file type
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $_FILES[$field]['tmp_name']);
                finfo_close($finfo);
                
                if (!in_array($mime, $allowed_types)) {
                    $errors[] = ucfirst(str_replace('_', ' ', $field)) . " must be an image file";
                    continue;
                }
                
                // Validate file size
                if ($_FILES[$field]['size'] > $max_file_size) {
                    $errors[] = ucfirst(str_replace('_', ' ', $field)) . " must be less than 5MB";
                    continue;
                }
                
                $ext = pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION);
                $suffix = ($field === 'ghana_card_front') ? '_front' : (($field === 'ghana_card_back') ? '_back' : '');
                $filename = $upload_dir . uniqid() . $suffix . '.' . $ext;
                
                if (move_uploaded_file($_FILES[$field]['tmp_name'], $filename)) {
                    $uploaded_files[$field] = $filename;
                } else {
                    $errors[] = "Failed to upload " . str_replace('_', ' ', $field);
                }
            }
        }
        
        // Final check before insert
        if (empty($errors)) {
            
            $stmt = $conn->prepare("
                INSERT INTO pilgrims (
                    file_number, passport_picture, first_name, surname, other_name, 
                    occupation, date_of_birth, ghana_card_number, passport_number, 
                    ghana_card_front, ghana_card_back, fathers_name, fathers_occupation, 
                    mothers_name, hajj_type, home_town, house_address, contact,
                    witness1_name, witness1_occupation, witness1_contact,
                    witness2_name, witness2_occupation, witness2_contact,
                    balance, processing_fee_amount, processing_fee_paid, status
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'registered'
                )
            ");
            
            /**
             * CRITICAL FIX: bind_param type string
             * 
             * Parameters in order:
             * 1-24: String parameters (24 total)
             *   1. file_number              (s)
             *   2. passport_picture         (s)
             *   3. first_name               (s)
             *   4. surname                  (s)
             *   5. other_name               (s)
             *   6. occupation               (s)
             *   7. date_of_birth            (s)
             *   8. ghana_card_number        (s)
             *   9. passport_number          (s)
             *   10. ghana_card_front        (s)
             *   11. ghana_card_back         (s)
             *   12. fathers_name            (s)
             *   13. fathers_occupation      (s)
             *   14. mothers_name            (s)
             *   15. hajj_type               (s)
             *   16. home_town               (s)
             *   17. house_address           (s)
             *   18. contact                 (s)
             *   19. witness1_name           (s)
             *   20. witness1_occupation     (s)
             *   21. witness1_contact        (s)
             *   22. witness2_name           (s)
             *   23. witness2_occupation     (s)
             *   24. witness2_contact        (s)
             * 
             * 25-26: Decimal parameters (2 total)
             *   25. initial_balance         (d)
             *   26. processing_fee          (d)
             * 
             * Type string: "ssssssssssssssssssssssssdd"
             * Character count: 24 's' + 2 'd' = 26 characters total
             */
            $stmt->bind_param(
                "ssssssssssssssssssssssssdd",  // 24 strings + 2 decimals = 26 total
                $form_data['file_number'],          // 1
                $uploaded_files['passport_picture'], // 2
                $form_data['first_name'],            // 3
                $form_data['surname'],               // 4
                $form_data['other_name'],            // 5
                $form_data['occupation'],            // 6
                $form_data['date_of_birth'],         // 7
                $form_data['ghana_card_number'],     // 8
                $form_data['passport_number'],       // 9
                $uploaded_files['ghana_card_front'], // 10
                $uploaded_files['ghana_card_back'],  // 11
                $form_data['fathers_name'],          // 12
                $form_data['fathers_occupation'],    // 13
                $form_data['mothers_name'],          // 14
                $form_data['hajj_type'],             // 15
                $form_data['home_town'],             // 16
                $form_data['house_address'],         // 17
                $form_data['contact'],               // 18
                $form_data['witness1_name'],         // 19
                $form_data['witness1_occupation'],   // 20
                $form_data['witness1_contact'],      // 21
                $form_data['witness2_name'],         // 22
                $form_data['witness2_occupation'],   // 23
                $form_data['witness2_contact'],      // 24
                $initial_balance,                    // 25 (decimal)
                $processing_fee                      // 26 (decimal)
            );
            
            if ($stmt->execute()) {
                $new_pilgrim_id = $conn->insert_id;
                $_SESSION['success_message'] = "Pilgrim registered successfully! File Number: {$form_data['file_number']}";
                header("Location: finance_pilgrims.php?highlight=$new_pilgrim_id");
                exit;
            } else {
                $message = "Database error: " . $stmt->error;
                $message_type = 'error';
            }
            
            $stmt->close();
        } else {
            $message = implode('<br>', $errors);
            $message_type = 'error';
        }
        
    } else {
        $message = implode('<br>', $errors);
        $message_type = 'error';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register New Pilgrim - Hajj Management System</title>
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
            --radius: 12px;
            --shadow: 0 8px 32px rgba(0,0,0,0.35);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: var(--text);
            min-height: 100vh;
            line-height: 1.6;
        }

        .container {
            max-width: 1200px;
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
            margin-bottom: 24px;
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

        /* Page Title */
        .page-title {
            text-align: center;
            font-size: 2rem;
            margin-bottom: 32px;
            color: #60a5fa;
            font-weight: 600;
        }

        /* Cost Info Banner */
        .cost-banner {
            background: linear-gradient(135deg, rgba(59,130,246,0.15) 0%, rgba(30,41,59,0.5) 100%);
            border: 1px solid rgba(59,130,246,0.3);
            border-radius: var(--radius);
            padding: 24px;
            margin-bottom: 32px;
            text-align: center;
        }

        .cost-banner h3 {
            color: #60a5fa;
            margin-bottom: 16px;
            font-size: 1.2rem;
        }

        .cost-info {
            display: flex;
            justify-content: center;
            gap: 40px;
            flex-wrap: wrap;
        }

        .cost-item {
            text-align: center;
        }

        .cost-label {
            color: var(--muted);
            font-size: 0.9rem;
            margin-bottom: 4px;
        }

        .cost-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--success);
        }

        .cost-note {
            margin-top: 12px;
            font-size: 0.85rem;
            color: var(--muted);
            font-style: italic;
        }

        /* Form Card */
        .form-card {
            background: var(--card);
            backdrop-filter: blur(12px);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 40px 32px;
            box-shadow: var(--shadow);
        }

        /* Message Alert */
        .alert {
            padding: 16px 24px;
            border-radius: var(--radius);
            margin-bottom: 24px;
            font-weight: 500;
            border-left: 4px solid;
        }

        .alert-error {
            background: rgba(239,68,68,0.15);
            color: #fca5a5;
            border-color: var(--danger);
        }

        .alert-success {
            background: rgba(16,185,129,0.15);
            color: #6ee7b7;
            border-color: var(--success);
        }

        /* Section Title */
        .section-title {
            color: #94a3b8;
            font-size: 1.2rem;
            margin: 32px 0 20px;
            padding-bottom: 12px;
            border-bottom: 1px solid rgba(51,65,85,0.5);
            font-weight: 600;
        }

        .section-title:first-of-type {
            margin-top: 0;
        }

        /* Form Grid */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 24px;
            margin-bottom: 24px;
        }

        .form-group {
            display: flex;
            flex-direction: column;
        }

        .form-group.full-width {
            grid-column: 1 / -1;
        }

        .form-group label {
            color: var(--muted);
            font-size: 0.9rem;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .form-group label .required {
            color: var(--danger);
            margin-left: 2px;
        }

        .form-group input[type="text"],
        .form-group input[type="date"],
        .form-group input[type="tel"],
        .form-group input[type="file"] {
            background: rgba(15,23,42,0.5);
            border: 1px solid var(--border);
            color: var(--text);
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 0.95rem;
            transition: all 0.2s;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(59,130,246,0.15);
        }

        .form-group input::placeholder {
            color: #64748b;
        }

        /* File input styling */
        .form-group input[type="file"] {
            padding: 10px 16px;
            cursor: pointer;
        }

        .form-group input[type="file"]::file-selector-button {
            background: rgba(59,130,246,0.2);
            border: 1px solid var(--primary);
            color: #60a5fa;
            padding: 6px 12px;
            border-radius: 6px;
            cursor: pointer;
            margin-right: 12px;
            font-weight: 500;
            transition: all 0.2s;
        }

        .form-group input[type="file"]::file-selector-button:hover {
            background: rgba(59,130,246,0.3);
        }

        /* Radio Group */
        .radio-group {
            display: flex;
            gap: 24px;
            flex-wrap: wrap;
            padding: 12px 0;
        }

        .radio-group label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
            color: var(--text);
            font-size: 0.95rem;
        }

        .radio-group input[type="radio"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
            accent-color: var(--primary);
        }

        /* Submit Button */
        .btn-submit {
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            color: white;
            border: none;
            padding: 14px 32px;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            margin-top: 32px;
            width: 100%;
            transition: all 0.3s;
            box-shadow: 0 4px 12px rgba(59,130,246,0.3);
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(59,130,246,0.4);
        }

        .btn-submit:active {
            transform: translateY(0);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .cost-info {
                flex-direction: column;
                gap: 20px;
            }
            
            header h1 {
                font-size: 1.2rem;
            }
            
            .page-title {
                font-size: 1.5rem;
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
            <a href="finance_register.php" class="active">New Pilgrim</a>
            <a href="finance_payments.php">Record Payment</a>
            <a href="finance_pilgrims.php">Pilgrims</a>
            <a href="logout.php" class="logout">Logout</a>
        </nav>
    </div>
</header>

<div class="container">

    <h1 class="page-title">Register New Pilgrim</h1>

    <!-- Cost Information Banner -->
    <div class="cost-banner">
        <h3>Hajj <?php echo $hajj_year; ?> Package Information</h3>
        <div class="cost-info">
            <div class="cost-item">
                <div class="cost-label">Full Package Cost</div>
                <div class="cost-value">GHS <?php echo number_format($hajj_cost, 2); ?></div>
            </div>
            <div class="cost-item">
                <div class="cost-label">Processing Fee (One-time)</div>
                <div class="cost-value">GHS <?php echo number_format($processing_fee, 2); ?></div>
            </div>
        </div>
        <p class="cost-note">
            Processing fee must be paid before Hajj package payments can commence
        </p>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?php echo $message_type; ?>">
            <?php echo $message; ?>
        </div>
    <?php endif; ?>

    <div class="form-card">
        <form method="POST" enctype="multipart/form-data" id="registrationForm">
            
            <!-- Personal Information -->
            <div class="section-title">Personal Information</div>
            <div class="form-grid">
                <div class="form-group">
                    <label for="file_number">File Number <span class="required">*</span></label>
                    <input type="text" 
                           name="file_number" 
                           id="file_number" 
                           value="<?php echo htmlspecialchars($form_data['file_number'] ?? ''); ?>"
                           required 
                           placeholder="e.g., 2026-001">
                </div>
                
                <div class="form-group">
                    <label for="first_name">First Name <span class="required">*</span></label>
                    <input type="text" 
                           name="first_name" 
                           id="first_name"
                           value="<?php echo htmlspecialchars($form_data['first_name'] ?? ''); ?>"
                           required>
                </div>
                
                <div class="form-group">
                    <label for="surname">Surname <span class="required">*</span></label>
                    <input type="text" 
                           name="surname" 
                           id="surname"
                           value="<?php echo htmlspecialchars($form_data['surname'] ?? ''); ?>"
                           required>
                </div>
                
                <div class="form-group">
                    <label for="other_name">Other Name</label>
                    <input type="text" 
                           name="other_name" 
                           id="other_name"
                           value="<?php echo htmlspecialchars($form_data['other_name'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label for="occupation">Occupation <span class="required">*</span></label>
                    <input type="text" 
                           name="occupation" 
                           id="occupation"
                           value="<?php echo htmlspecialchars($form_data['occupation'] ?? ''); ?>"
                           required>
                </div>
                
                <div class="form-group">
                    <label for="date_of_birth">Date of Birth <span class="required">*</span></label>
                    <input type="date" 
                           name="date_of_birth" 
                           id="date_of_birth"
                           value="<?php echo htmlspecialchars($form_data['date_of_birth'] ?? ''); ?>"
                           max="<?php echo date('Y-m-d', strtotime('-10 years')); ?>"
                           required>
                </div>
            </div>

            <!-- Identification Documents -->
            <div class="section-title">Identification Documents</div>
            <div class="form-grid">
                <div class="form-group">
                    <label for="ghana_card_number">Ghana Card Number</label>
                    <input type="text" 
                           name="ghana_card_number" 
                           id="ghana_card_number"
                           value="<?php echo htmlspecialchars($form_data['ghana_card_number'] ?? ''); ?>"
                           placeholder="e.g., GHA-000000000-0">
                </div>
                
                <div class="form-group">
                    <label for="passport_number">Passport Number</label>
                    <input type="text" 
                           name="passport_number" 
                           id="passport_number"
                           value="<?php echo htmlspecialchars($form_data['passport_number'] ?? ''); ?>"
                           placeholder="e.g., G1234567">
                </div>
                
                <div class="form-group">
                    <label for="passport_picture">Passport Photo / Bio-data Page</label>
                    <input type="file" 
                           name="passport_picture" 
                           id="passport_picture"
                           accept="image/jpeg,image/jpg,image/png,image/gif,image/webp">
                </div>
                
                <div class="form-group">
                    <label for="ghana_card_front">Ghana Card (Front)</label>
                    <input type="file" 
                           name="ghana_card_front" 
                           id="ghana_card_front"
                           accept="image/jpeg,image/jpg,image/png,image/gif,image/webp">
                </div>
                
                <div class="form-group">
                    <label for="ghana_card_back">Ghana Card (Back)</label>
                    <input type="file" 
                           name="ghana_card_back" 
                           id="ghana_card_back"
                           accept="image/jpeg,image/jpg,image/png,image/gif,image/webp">
                </div>
            </div>

            <!-- Family Information -->
            <div class="section-title">Family Information</div>
            <div class="form-grid">
                <div class="form-group">
                    <label for="fathers_name">Father's Name <span class="required">*</span></label>
                    <input type="text" 
                           name="fathers_name" 
                           id="fathers_name"
                           value="<?php echo htmlspecialchars($form_data['fathers_name'] ?? ''); ?>"
                           required>
                </div>
                
                <div class="form-group">
                    <label for="fathers_occupation">Father's Occupation <span class="required">*</span></label>
                    <input type="text" 
                           name="fathers_occupation" 
                           id="fathers_occupation"
                           value="<?php echo htmlspecialchars($form_data['fathers_occupation'] ?? ''); ?>"
                           required>
                </div>
                
                <div class="form-group">
                    <label for="mothers_name">Mother's Name <span class="required">*</span></label>
                    <input type="text" 
                           name="mothers_name" 
                           id="mothers_name"
                           value="<?php echo htmlspecialchars($form_data['mothers_name'] ?? ''); ?>"
                           required>
                </div>
            </div>

            <!-- Hajj Details -->
            <div class="section-title">Hajj Details</div>
            <div class="form-grid">
                <div class="form-group full-width">
                    <label>Type of Hajj <span class="required">*</span></label>
                    <div class="radio-group">
                        <label>
                            <input type="radio" 
                                   name="hajj_type" 
                                   value="Tamatuo"
                                   <?php echo ($form_data['hajj_type'] ?? '') === 'Tamatuo' ? 'checked' : ''; ?>
                                   required>
                            Tamatuo (Umrah then Hajj)
                        </label>
                        <label>
                            <input type="radio" 
                                   name="hajj_type" 
                                   value="Qiran"
                                   <?php echo ($form_data['hajj_type'] ?? '') === 'Qiran' ? 'checked' : ''; ?>>
                            Qiran (Combined)
                        </label>
                        <label>
                            <input type="radio" 
                                   name="hajj_type" 
                                   value="Ifraad"
                                   <?php echo ($form_data['hajj_type'] ?? '') === 'Ifraad' ? 'checked' : ''; ?>>
                            Ifraad (Hajj only)
                        </label>
                    </div>
                </div>
            </div>

            <!-- Address & Contact -->
            <div class="section-title">Address & Contact Information</div>
            <div class="form-grid">
                <div class="form-group">
                    <label for="home_town">Home Town <span class="required">*</span></label>
                    <input type="text" 
                           name="home_town" 
                           id="home_town"
                           value="<?php echo htmlspecialchars($form_data['home_town'] ?? ''); ?>"
                           required>
                </div>
                
                <div class="form-group">
                    <label for="contact">Contact Number <span class="required">*</span></label>
                    <input type="tel" 
                           name="contact" 
                           id="contact"
                           value="<?php echo htmlspecialchars($form_data['contact'] ?? ''); ?>"
                           placeholder="e.g., 0240000000"
                           required>
                </div>
                
                <div class="form-group full-width">
                    <label for="house_address">House Address <span class="required">*</span></label>
                    <input type="text" 
                           name="house_address" 
                           id="house_address"
                           value="<?php echo htmlspecialchars($form_data['house_address'] ?? ''); ?>"
                           required>
                </div>
            </div>

            <!-- Witness Information -->
            <div class="section-title">Witness Information</div>
            <div class="form-grid">
                <div class="form-group">
                    <label for="witness1_name">Witness 1 Name <span class="required">*</span></label>
                    <input type="text" 
                           name="witness1_name" 
                           id="witness1_name"
                           value="<?php echo htmlspecialchars($form_data['witness1_name'] ?? ''); ?>"
                           required>
                </div>
                
                <div class="form-group">
                    <label for="witness1_occupation">Witness 1 Occupation</label>
                    <input type="text" 
                           name="witness1_occupation" 
                           id="witness1_occupation"
                           value="<?php echo htmlspecialchars($form_data['witness1_occupation'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label for="witness1_contact">Witness 1 Contact <span class="required">*</span></label>
                    <input type="tel" 
                           name="witness1_contact" 
                           id="witness1_contact"
                           value="<?php echo htmlspecialchars($form_data['witness1_contact'] ?? ''); ?>"
                           required>
                </div>
                
                <div class="form-group">
                    <label for="witness2_name">Witness 2 Name <span class="required">*</span></label>
                    <input type="text" 
                           name="witness2_name" 
                           id="witness2_name"
                           value="<?php echo htmlspecialchars($form_data['witness2_name'] ?? ''); ?>"
                           required>
                </div>
                
                <div class="form-group">
                    <label for="witness2_occupation">Witness 2 Occupation</label>
                    <input type="text" 
                           name="witness2_occupation" 
                           id="witness2_occupation"
                           value="<?php echo htmlspecialchars($form_data['witness2_occupation'] ?? ''); ?>">
                </div>
                
                <div class="form-group">
                    <label for="witness2_contact">Witness 2 Contact <span class="required">*</span></label>
                    <input type="tel" 
                           name="witness2_contact" 
                           id="witness2_contact"
                           value="<?php echo htmlspecialchars($form_data['witness2_contact'] ?? ''); ?>"
                           required>
                </div>
            </div>

            <button type="submit" class="btn-submit">Register Pilgrim</button>
        </form>
    </div>

</div>

<script>
// Client-side validation enhancement
document.getElementById('registrationForm').addEventListener('submit', function(e) {
    const ghanaCard = document.getElementById('ghana_card_number').value.trim();
    const passport = document.getElementById('passport_number').value.trim();
    
    if (!ghanaCard && !passport) {
        e.preventDefault();
        alert('Please provide either Ghana Card Number or Passport Number');
        return false;
    }
    
    // Optional: Show loading state
    const submitBtn = this.querySelector('.btn-submit');
    submitBtn.textContent = 'Registering...';
    submitBtn.disabled = true;
});

// File size validation (5MB limit)
document.querySelectorAll('input[type="file"]').forEach(input => {
    input.addEventListener('change', function() {
        const file = this.files[0];
        if (file && file.size > 5 * 1024 * 1024) {
            alert('File size must be less than 5MB');
            this.value = '';
        }
    });
});
</script>

</body>
</html>