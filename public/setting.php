<?php
// settings.php - User Settings and Preferences
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$username = $_SESSION['username'];
$email = $_SESSION['email'];
$user_id = $_SESSION['user_id'];

try {
    $db = new PDO('sqlite:' .__DIR__ . '/../db/database.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Create user_preferences table if not exists
$db->exec('CREATE TABLE IF NOT EXISTS user_preferences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL UNIQUE,
    currency TEXT DEFAULT "USD",
    date_format TEXT DEFAULT "Y-m-d",
    notifications_enabled INTEGER DEFAULT 1,
    budget_alerts INTEGER DEFAULT 1,
    savings_reminders INTEGER DEFAULT 1,
    theme TEXT DEFAULT "light",
    language TEXT DEFAULT "en",
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)');

// Get user data
$stmt = $db->prepare('SELECT * FROM users WHERE id = :uid');
$stmt->execute([':uid' => $user_id]);
$userData = $stmt->fetch(PDO::FETCH_ASSOC);

// Get or create user preferences
$stmt = $db->prepare('SELECT * FROM user_preferences WHERE user_id = :uid');
$stmt->execute([':uid' => $user_id]);
$preferences = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$preferences) {
    // Create default preferences
    $stmt = $db->prepare('INSERT INTO user_preferences (user_id) VALUES (:uid)');
    $stmt->execute([':uid' => $user_id]);
    
    $stmt = $db->prepare('SELECT * FROM user_preferences WHERE user_id = :uid');
    $stmt->execute([':uid' => $user_id]);
    $preferences = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Messages
$successMessage = '';
$errorMessage = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'update_profile':
                try {
                    $newUsername = trim($_POST['username']);
                    $newEmail = trim($_POST['email']);
                    
                    // Check if username/email already exists for other users
                    $stmt = $db->prepare('SELECT id FROM users WHERE (username = :user OR email = :email) AND id != :uid');
                    $stmt->execute([':user' => $newUsername, ':email' => $newEmail, ':uid' => $user_id]);
                    
                    if ($stmt->fetch()) {
                        $errorMessage = 'Username or email already exists!';
                    } else {
                        $stmt = $db->prepare('UPDATE users SET username = :user, email = :email WHERE id = :uid');
                        $stmt->execute([
                            ':user' => $newUsername,
                            ':email' => $newEmail,
                            ':uid' => $user_id
                        ]);
                        
                        $_SESSION['username'] = $newUsername;
                        $_SESSION['email'] = $newEmail;
                        $username = $newUsername;
                        $email = $newEmail;
                        
                        $successMessage = 'Profile updated successfully!';
                    }
                } catch(PDOException $e) {
                    $errorMessage = 'Error updating profile: ' . $e->getMessage();
                }
                break;
            
            case 'change_password':
                $currentPassword = $_POST['current_password'];
                $newPassword = $_POST['new_password'];
                $confirmPassword = $_POST['confirm_password'];
                
                if ($newPassword !== $confirmPassword) {
                    $errorMessage = 'New passwords do not match!';
                } elseif (strlen($newPassword) < 6) {
                    $errorMessage = 'Password must be at least 6 characters!';
                } else {
                    // Verify current password
                    if (password_verify($currentPassword, $userData['password'])) {
                        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                        $stmt = $db->prepare('UPDATE users SET password = :pass WHERE id = :uid');
                        $stmt->execute([':pass' => $hashedPassword, ':uid' => $user_id]);
                        
                        $successMessage = 'Password changed successfully!';
                    } else {
                        $errorMessage = 'Current password is incorrect!';
                    }
                }
                break;
            
            case 'update_preferences':
                try {
                    $stmt = $db->prepare('
                        UPDATE user_preferences 
                        SET currency = :curr,
                            date_format = :date_fmt,
                            notifications_enabled = :notif,
                            budget_alerts = :budget,
                            savings_reminders = :savings,
                            theme = :theme,
                            language = :lang
                        WHERE user_id = :uid
                    ');
                    $stmt->execute([
                        ':curr' => $_POST['currency'],
                        ':date_fmt' => $_POST['date_format'],
                        ':notif' => isset($_POST['notifications_enabled']) ? 1 : 0,
                        ':budget' => isset($_POST['budget_alerts']) ? 1 : 0,
                        ':savings' => isset($_POST['savings_reminders']) ? 1 : 0,
                        ':theme' => $_POST['theme'],
                        ':lang' => $_POST['language'],
                        ':uid' => $user_id
                    ]);
                    
                    // Refresh preferences
                    $stmt = $db->prepare('SELECT * FROM user_preferences WHERE user_id = :uid');
                    $stmt->execute([':uid' => $user_id]);
                    $preferences = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    $successMessage = 'Preferences updated successfully!';
                } catch(PDOException $e) {
                    $errorMessage = 'Error updating preferences: ' . $e->getMessage();
                }
                break;
            
            case 'delete_account':
                $confirmPassword = $_POST['delete_password'];
                
                if (password_verify($confirmPassword, $userData['password'])) {
                    // Delete user and all related data (CASCADE will handle it)
                    $stmt = $db->prepare('DELETE FROM users WHERE id = :uid');
                    $stmt->execute([':uid' => $user_id]);
                    
                    session_destroy();
                    header('Location: login.php?deleted=1');
                    exit();
                } else {
                    $errorMessage = 'Password is incorrect! Account not deleted.';
                }
                break;
            
            case 'export_data':
                // Generate CSV export of user data
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="statok_export_' . date('Y-m-d') . '.csv"');
                
                $output = fopen('php://output', 'w');
                
                // Export budgets
                fputcsv($output, ['=== BUDGETS ===']);
                fputcsv($output, ['Name', 'Category', 'Amount', 'Created Date']);
                
                $stmt = $db->prepare('SELECT * FROM budgets WHERE user_id = :uid');
                $stmt->execute([':uid' => $user_id]);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    fputcsv($output, [$row['name'], $row['category'], $row['budget_amount'], $row['created_date']]);
                }
                
                // Export expenses
                fputcsv($output, []);
                fputcsv($output, ['=== EXPENSES ===']);
                fputcsv($output, ['Budget ID', 'Amount', 'Description', 'Date']);
                
                $stmt = $db->prepare('
                    SELECT e.* FROM expenses e
                    INNER JOIN budgets b ON e.budget_id = b.id
                    WHERE b.user_id = :uid
                ');
                $stmt->execute([':uid' => $user_id]);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    fputcsv($output, [$row['budget_id'], $row['amount'], $row['description'], $row['date']]);
                }
                
                // Export savings goals
                fputcsv($output, []);
                fputcsv($output, ['=== SAVINGS GOALS ===']);
                fputcsv($output, ['Name', 'Category', 'Target', 'Current', 'Deadline', 'Created']);
                
                $stmt = $db->prepare('SELECT * FROM savings_goals WHERE user_id = :uid');
                $stmt->execute([':uid' => $user_id]);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    fputcsv($output, [$row['name'], $row['category'], $row['target_amount'], $row['current_amount'], $row['deadline'], $row['created_date']]);
                }
                
                fclose($output);
                exit();
                break;
        }
    }
}

// Get statistics
$stmt = $db->prepare('SELECT COUNT(*) as count FROM budgets WHERE user_id = :uid');
$stmt->execute([':uid' => $user_id]);
$budgetCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

$stmt = $db->prepare('SELECT COUNT(*) as count FROM expenses e INNER JOIN budgets b ON e.budget_id = b.id WHERE b.user_id = :uid');
$stmt->execute([':uid' => $user_id]);
$expenseCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

$stmt = $db->prepare('SELECT COUNT(*) as count FROM savings_goals WHERE user_id = :uid');
$stmt->execute([':uid' => $user_id]);
$savingsCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Calculate account age
$accountCreated = new DateTime($userData['created_at']);
$now = new DateTime();
$accountAge = $accountCreated->diff($now)->days;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Settings - Statok</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', 'Inter', Roboto, sans-serif; 
            background: #f8f9fc; 
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar {
            width: 260px;
            background: white;
            border-right: 1px solid #e8eaf0;
            padding: 24px 0;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }
        
        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 24px;
            margin-bottom: 32px;
        }
        
        .logo-icon {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        
        .logo-text {
            font-size: 20px;
            font-weight: 700;
            color: #1a202c;
        }
        
        .nav-menu {
            list-style: none;
            padding: 0 12px;
        }
        
        .nav-item {
            margin-bottom: 4px;
        }
        
        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            color: #64748b;
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.2s;
            font-size: 14px;
            font-weight: 500;
        }
        
        .nav-link:hover {
            background: #f8f9fc;
            color: #667eea;
        }
        
        .nav-link.active {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            color: #667eea;
        }
        
        .nav-icon {
            width: 20px;
            font-size: 18px;
        }
        
        .main-content {
            margin-left: 260px;
            flex: 1;
            padding: 24px 32px;
        }
        
        .page-title {
            font-size: 28px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 32px;
        }
        
        .alert {
            padding: 16px 20px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            font-weight: 500;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #10b981;
        }
        
        .alert-error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #ef4444;
        }
        
        .settings-container {
            max-width: 900px;
        }
        
        .settings-section {
            background: white;
            border-radius: 16px;
            padding: 28px;
            margin-bottom: 24px;
            border: 1px solid #e8eaf0;
        }
        
        .section-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 2px solid #f1f5f9;
        }
        
        .section-icon {
            font-size: 24px;
        }
        
        .section-title {
            font-size: 20px;
            font-weight: 700;
            color: #1a202c;
        }
        
        .profile-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .profile-avatar {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 36px;
            color: white;
            font-weight: 700;
        }
        
        .profile-info h2 {
            font-size: 24px;
            color: #1a202c;
            margin-bottom: 4px;
        }
        
        .profile-info p {
            color: #64748b;
            font-size: 14px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.05) 0%, rgba(118, 75, 162, 0.05) 100%);
            padding: 16px;
            border-radius: 12px;
            border: 1px solid rgba(102, 126, 234, 0.1);
            text-align: center;
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 4px;
        }
        
        .stat-label {
            font-size: 12px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 16px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: #64748b;
            font-weight: 600;
            font-size: 14px;
        }
        
        input, select {
            width: 100%;
            padding: 12px;
            border: 2px solid #e8eaf0;
            border-radius: 8px;
            font-size: 14px;
            transition: border 0.2s;
        }
        
        input:focus, select:focus {
            outline: none;
            border-color: #667eea;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 12px;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: auto;
            cursor: pointer;
        }
        
        .checkbox-group label {
            margin: 0;
            cursor: pointer;
            font-weight: 500;
        }
        
        .btn {
            padding: 12px 24px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        .btn-secondary {
            background: #f1f5f9;
            color: #64748b;
        }
        
        .btn-danger {
            background: #ef4444;
            color: white;
        }
        
        .btn-danger:hover {
            background: #dc2626;
        }
        
        .btn-export {
            background: #10b981;
            color: white;
        }
        
        .btn-export:hover {
            background: #059669;
        }
        
        .danger-zone {
            background: #fef2f2;
            border: 2px solid #fecaca;
            border-radius: 12px;
            padding: 20px;
        }
        
        .danger-zone h3 {
            color: #991b1b;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .danger-zone p {
            color: #64748b;
            font-size: 14px;
            margin-bottom: 16px;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            align-items: center;
            justify-content: center;
            backdrop-filter: blur(4px);
        }
        
        .modal.active { display: flex; }
        
        .modal-content {
            background: white;
            padding: 32px;
            border-radius: 20px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        
        .modal-header {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 16px;
            color: #1a202c;
        }
        
        .modal-text {
            color: #64748b;
            margin-bottom: 24px;
            line-height: 1.6;
        }
        
        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }
        
        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main-content { margin-left: 0; padding: 16px; }
            .form-row { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="logo">
            <div class="logo-icon">💰</div>
            <div class="logo-text">Statok</div>
        </div>
        
        <ul class="nav-menu">
            <li class="nav-item">
                <a href="dashboard.php" class="nav-link">
                    <span class="nav-icon">📊</span>
                    Dashboard
                </a>
            </li>
            <li class="nav-item">
                <a href="budgets.php" class="nav-link">
                    <span class="nav-icon">💰</span>
                    Budgets
                </a>
            </li>
            <li class="nav-item">
                <a href="savings.php" class="nav-link">
                    <span class="nav-icon">🎯</span>
                    Savings Goals
                </a>
            </li>
            <li class="nav-item">
                <a href="nalytics.php" class="nav-link">
                    <span class="nav-icon">📈</span>
                    Analytics
                </a>
            </li>
            <li class="nav-item">
                <a href="settings.php" class="nav-link active">
                    <span class="nav-icon">⚙️</span>
                    Settings
                </a>
            </li>
            <li class="nav-item">
                <a href="logout.php" class="nav-link">
                    <span class="nav-icon">🚪</span>
                    Logout
                </a>
            </li>
        </ul>
    </aside>

    <main class="main-content">
        <div class="page-title">⚙️ Settings</div>

        <?php if ($successMessage): ?>
        <div class="alert alert-success">
            ✅ <?php echo $successMessage; ?>
        </div>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
        <div class="alert alert-error">
            ❌ <?php echo $errorMessage; ?>
        </div>
        <?php endif; ?>

        <div class="settings-container">
            <!-- Profile Section -->
            <div class="settings-section">
                <div class="profile-header">
                    <div class="profile-avatar">
                        <?php echo strtoupper(substr($username, 0, 1)); ?>
                    </div>
                    <div class="profile-info">
                        <h2><?php echo htmlspecialchars($username); ?></h2>
                        <p><?php echo htmlspecialchars($email); ?></p>
                        <p style="margin-top: 4px; font-size: 12px;">Member for <?php echo $accountAge; ?> days</p>
                    </div>
                </div>

                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $budgetCount; ?></div>
                        <div class="stat-label">Budgets</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $expenseCount; ?></div>
                        <div class="stat-label">Expenses</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-value"><?php echo $savingsCount; ?></div>
                        <div class="stat-label">Savings Goals</div>
                    </div>
                </div>

                <div class="section-header">
                    <span class="section-icon">👤</span>
                    <span class="section-title">Profile Information</span>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="update_profile">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Username</label>
                            <input type="text" name="username" value="<?php echo htmlspecialchars($username); ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Email</label>
                            <input type="email" name="email" value="<?php echo htmlspecialchars($email); ?>" required>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Update Profile</button>
                </form>
            </div>

            <!-- Security Section -->
            <div class="settings-section">
                <div class="section-header">
                    <span class="section-icon">🔒</span>
                    <span class="section-title">Security</span>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="change_password">
                    
                    <div class="form-group">
                        <label>Current Password</label>
                        <input type="password" name="current_password" required>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>New Password</label>
                            <input type="password" name="new_password" required>
                        </div>
                        
                        <div class="form-group">
                            <label>Confirm New Password</label>
                            <input type="password" name="confirm_password" required>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Change Password</button>
                </form>
            </div>

            <!-- Preferences Section -->
            <div class="settings-section">
                <div class="section-header">
                    <span class="section-icon">🎨</span>
                    <span class="section-title">Preferences</span>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="update_preferences">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Currency</label>
                            <select name="currency">
                                <option value="USD" <?php echo $preferences['currency'] == 'USD' ? 'selected' : ''; ?>>USD - US Dollar ($)</option>
                                <option value="EUR" <?php echo $preferences['currency'] == 'EUR' ? 'selected' : ''; ?>>EUR - Euro (€)</option>
                                <option value="GBP" <?php echo $preferences['currency'] == 'GBP' ? 'selected' : ''; ?>>GBP - British Pound (£)</option>
                                <option value="JPY" <?php echo $preferences['currency'] == 'JPY' ? 'selected' : ''; ?>>JPY - Japanese Yen (¥)</option>
                                <option value="LKR" <?php echo $preferences['currency'] == 'LKR' ? 'selected' : ''; ?>>LKR - Sri Lankan Rupee (Rs)</option>
                                <option value="INR" <?php echo $preferences['currency'] == 'INR' ? 'selected' : ''; ?>>INR - Indian Rupee (₹)</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Date Format</label>
                            <select name="date_format">
                                <option value="Y-m-d" <?php echo $preferences['date_format'] == 'Y-m-d' ? 'selected' : ''; ?>>YYYY-MM-DD</option>
                                <option value="m/d/Y" <?php echo $preferences['date_format'] == 'm/d/Y' ? 'selected' : ''; ?>>MM/DD/YYYY</option>
                                <option value="d/m/Y" <?php echo $preferences['date_format'] == 'd/m/Y' ? 'selected' : ''; ?>>DD/MM/YYYY</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label>Theme</label>
                            <select name="theme">
                                <option value="light" <?php echo $preferences['theme'] == 'light' ? 'selected' : ''; ?>>Light</option>
                                <option value="dark" <?php echo $preferences['theme'] == 'dark' ? 'selected' : ''; ?>>Dark</option>
                                <option value="auto" <?php echo $preferences['theme'] == 'auto' ? 'selected' : ''; ?>>Auto</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Language</label>
                            <select name="language">
                                <option value="en" <?php echo $preferences['language'] == 'en' ? 'selected' : ''; ?>>English</option>
                                <option value="es" <?php echo $preferences['language'] == 'es' ? 'selected' : ''; ?>>Spanish</option>
                                <option value="fr" <?php echo $preferences['language'] == 'fr' ? 'selected' : ''; ?>>French</option>
                                <option value="de" <?php echo $preferences['language'] == 'de' ? 'selected' : ''; ?>>German</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label style="margin-bottom: 12px;">Notifications</label>
                        <div class="checkbox-group">
                            <input type="checkbox" id="notifications" name="notifications_enabled" <?php echo $preferences['notifications_enabled'] ? 'checked' : ''; ?>>
                            <label for="notifications">Enable notifications</label>
                        </div>
                        <div class="checkbox-group">
                            <input type="checkbox" id="budget_alerts" name="budget_alerts" <?php echo $preferences['budget_alerts'] ? 'checked' : ''; ?>>
                            <label for="budget_alerts">Budget alerts when nearing limit</label>
                        </div>
                        <div class="checkbox-group">
                            <input type="checkbox" id="savings_reminders" name="savings_reminders" <?php echo $preferences['savings_reminders'] ? 'checked' : ''; ?>>
                            <label for="savings_reminders">Savings goal reminders</label>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Save Preferences</button>
                </form>
            </div>

            <!-- Data Export Section -->
            <div class="settings-section">
                <div class="section-header">
                    <span class="section-icon">📦</span>
                    <span class="section-title">Data Export</span>
                </div>

                <p style="color: #64748b; margin-bottom: 20px;">
                    Download all your Statok data including budgets, expenses, and savings goals in CSV format.
                </p>

                <form method="POST">
                    <input type="hidden" name="action" value="export_data">
                    <button type="submit" class="btn btn-export">📥 Export All Data</button>
                </form>
            </div>

            <!-- Danger Zone -->
            <div class="settings-section">
                <div class="section-header">
                    <span class="section-icon">⚠️</span>
                    <span class="section-title">Danger Zone</span>
                </div>

                <div class="danger-zone">
                    <h3>⚠️ Delete Account</h3>
                    <p>
                        Once you delete your account, there is no going back. This will permanently delete your account,
                        all your budgets, expenses, and savings goals. Please be certain.
                    </p>
                    <button type="button" class="btn btn-danger" onclick="showDeleteModal()">
                        Delete My Account
                    </button>
                </div>
            </div>
        </div>
    </main>

    <!-- Delete Account Modal -->
    <div id="deleteModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">⚠️ Confirm Account Deletion</div>
            <p class="modal-text">
                This action cannot be undone. All your data will be permanently deleted from our servers.
                Please enter your password to confirm.
            </p>
            
            <form method="POST">
                <input type="hidden" name="action" value="delete_account">
                <div class="form-group">
                    <label>Enter your password to confirm</label>
                    <input type="password" name="delete_password" required placeholder="Your password">
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary" onclick="closeDeleteModal()">Cancel</button>
                    <button type="submit" class="btn btn-danger">Yes, Delete My Account</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function showDeleteModal() {
            document.getElementById('deleteModal').classList.add('active');
        }
        
        function closeDeleteModal() {
            document.getElementById('deleteModal').classList.remove('active');
        }
        
        // Close modal when clicking outside
        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeDeleteModal();
            }
        });
        
        // Auto-hide alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                alert.style.transition = 'opacity 0.5s';
                alert.style.opacity = '0';
                setTimeout(function() {
                    alert.remove();
                }, 500);
            });
        }, 5000);
    </script>
</body>
</html>