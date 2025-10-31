<?php
// savings.php - Savings Goals CRUD Operations
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

// Create tables
$db->exec('CREATE TABLE IF NOT EXISTS savings_goals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    target_amount REAL NOT NULL,
    current_amount REAL DEFAULT 0,
    deadline TEXT,
    category TEXT NOT NULL,
    description TEXT,
    created_date TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)');

$db->exec('CREATE TABLE IF NOT EXISTS savings_transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    goal_id INTEGER NOT NULL,
    amount REAL NOT NULL,
    transaction_type TEXT NOT NULL,
    description TEXT,
    date TEXT NOT NULL,
    FOREIGN KEY (goal_id) REFERENCES savings_goals(id) ON DELETE CASCADE
)');

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_goal':
                $stmt = $db->prepare('INSERT INTO savings_goals (user_id, name, target_amount, current_amount, deadline, category, description, created_date) VALUES (:uid, :name, :target, :current, :deadline, :cat, :desc, :date)');
                $stmt->execute([
                    ':uid' => $user_id,
                    ':name' => $_POST['name'],
                    ':target' => $_POST['target_amount'],
                    ':current' => $_POST['current_amount'] ?? 0,
                    ':deadline' => $_POST['deadline'] ?? null,
                    ':cat' => $_POST['category'],
                    ':desc' => $_POST['description'] ?? '',
                    ':date' => date('Y-m-d')
                ]);
                break;
            
            case 'add_transaction':
                $amount = floatval($_POST['amount']);
                $type = $_POST['transaction_type'];
                
                // Add transaction
                $stmt = $db->prepare('INSERT INTO savings_transactions (goal_id, amount, transaction_type, description, date) VALUES (:gid, :amt, :type, :desc, :date)');
                $stmt->execute([
                    ':gid' => $_POST['goal_id'],
                    ':amt' => $amount,
                    ':type' => $type,
                    ':desc' => $_POST['description'],
                    ':date' => $_POST['date']
                ]);
                
                // Update goal current amount
                $adjustment = $type === 'deposit' ? $amount : -$amount;
                $stmt = $db->prepare('UPDATE savings_goals SET current_amount = current_amount + :amt WHERE id = :id AND user_id = :uid');
                $stmt->execute([':amt' => $adjustment, ':id' => $_POST['goal_id'], ':uid' => $user_id]);
                break;
            
            case 'update_goal':
                $stmt = $db->prepare('UPDATE savings_goals SET name=:name, target_amount=:target, deadline=:deadline, category=:cat, description=:desc WHERE id=:id AND user_id=:uid');
                $stmt->execute([
                    ':name' => $_POST['name'],
                    ':target' => $_POST['target_amount'],
                    ':deadline' => $_POST['deadline'] ?? null,
                    ':cat' => $_POST['category'],
                    ':desc' => $_POST['description'] ?? '',
                    ':id' => $_POST['id'],
                    ':uid' => $user_id
                ]);
                break;
            
            case 'delete_goal':
                $stmt = $db->prepare('DELETE FROM savings_goals WHERE id=:id AND user_id=:uid');
                $stmt->execute([':id' => $_POST['id'], ':uid' => $user_id]);
                break;
            
            case 'delete_transaction':
                // Get transaction details before deleting
                $stmt = $db->prepare('SELECT st.*, sg.user_id FROM savings_transactions st JOIN savings_goals sg ON st.goal_id = sg.id WHERE st.id=:id AND sg.user_id=:uid');
                $stmt->execute([':id' => $_POST['id'], ':uid' => $user_id]);
                $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($transaction) {
                    // Reverse the amount
                    $adjustment = $transaction['transaction_type'] === 'deposit' ? -$transaction['amount'] : $transaction['amount'];
                    $stmt = $db->prepare('UPDATE savings_goals SET current_amount = current_amount + :amt WHERE id = :id');
                    $stmt->execute([':amt' => $adjustment, ':id' => $transaction['goal_id']]);
                    
                    // Delete transaction
                    $stmt = $db->prepare('DELETE FROM savings_transactions WHERE id=:id');
                    $stmt->execute([':id' => $_POST['id']]);
                }
                break;
        }
        header('Location: ' . $_SERVER['PHP_SELF'] . (isset($_GET['view']) ? '?view=' . $_GET['view'] : ''));
        exit;
    }
}

// Get all savings goals
$stmt = $db->prepare('
    SELECT * FROM savings_goals 
    WHERE user_id = :uid
    ORDER BY created_date DESC
');
$stmt->execute([':uid' => $user_id]);
$goals = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get goal for editing
$editGoal = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare('SELECT * FROM savings_goals WHERE id=:id AND user_id=:uid');
    $stmt->execute([':id' => $_GET['edit'], ':uid' => $user_id]);
    $editGoal = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get transactions for viewing
$viewTransactions = null;
$currentGoal = null;
if (isset($_GET['view'])) {
    $stmt = $db->prepare('SELECT * FROM savings_transactions WHERE goal_id=:id AND goal_id IN (SELECT id FROM savings_goals WHERE user_id=:uid) ORDER BY date DESC');
    $stmt->execute([':id' => $_GET['view'], ':uid' => $user_id]);
    $viewTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $db->prepare('SELECT * FROM savings_goals WHERE id=:id AND user_id=:uid');
    $stmt->execute([':id' => $_GET['view'], ':uid' => $user_id]);
    $currentGoal = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Savings Goals - Statok</title>
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
        
        .top-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
        }
        
        .page-title {
            font-size: 28px;
            font-weight: 700;
            color: #1a202c;
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
        
        .goals-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 24px;
        }
        
        .goal-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            border: 1px solid #e8eaf0;
            transition: all 0.2s;
            position: relative;
            overflow: hidden;
        }
        
        .goal-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
        }
        
        .goal-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.1);
        }
        
        .goal-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
        }
        
        .goal-info h3 {
            font-size: 18px;
            font-weight: 700;
            color: #1a202c;
            margin-bottom: 8px;
        }
        
        .category-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .cat-vacation { background: #fff3e0; color: #f57c00; }
        .cat-emergency { background: #ffebee; color: #d32f2f; }
        .cat-house { background: #e8f5e9; color: #388e3c; }
        .cat-car { background: #e3f2fd; color: #1976d2; }
        .cat-education { background: #f3e5f5; color: #7b1fa2; }
        .cat-retirement { background: #e0f2f1; color: #00796b; }
        .cat-wedding { background: #fce4ec; color: #c2185b; }
        .cat-gadget { background: #e8eaf6; color: #5e35b1; }
        .cat-other { background: #f5f5f5; color: #757575; }
        
        .goal-actions {
            display: flex;
            gap: 8px;
        }
        
        .btn-mini {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            transition: all 0.2s;
        }
        
        .btn-edit { background: #e3f2fd; color: #2196f3; }
        .btn-delete { background: #ffebee; color: #f44336; }
        .btn-view { background: #f3e5f5; color: #9c27b0; }
        .btn-add { background: #e8f5e9; color: #4caf50; }
        .btn-mini:hover { transform: scale(1.1); }
        
        .goal-amounts {
            display: flex;
            justify-content: space-between;
            margin-bottom: 16px;
            font-size: 14px;
        }
        
        .amount-item {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .amount-label {
            color: #64748b;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .amount-value {
            font-size: 20px;
            font-weight: 700;
            color: #1a202c;
        }
        
        .progress-bar {
            height: 10px;
            background: #e8eaf0;
            border-radius: 5px;
            overflow: hidden;
            margin-bottom: 16px;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #10b981 0%, #059669 100%);
            border-radius: 5px;
            transition: width 0.3s;
        }
        
        .progress-complete {
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
        }
        
        .goal-status {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
            font-weight: 600;
        }
        
        .status-remaining { color: #64748b; }
        .status-complete { color: #10b981; }
        
        .deadline-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 8px;
            background: #f1f5f9;
            border-radius: 6px;
            font-size: 12px;
            color: #64748b;
            margin-top: 8px;
        }
        
        .deadline-urgent {
            background: #fef3c7;
            color: #92400e;
        }
        
        .fab {
            position: fixed;
            bottom: 32px;
            right: 32px;
            width: 64px;
            height: 64px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            font-size: 28px;
            cursor: pointer;
            box-shadow: 0 4px 20px rgba(102, 126, 234, 0.5);
            transition: all 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .fab:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 28px rgba(102, 126, 234, 0.6);
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
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        
        .modal-content.large { max-width: 900px; }
        
        .modal-header {
            font-size: 24px;
            font-weight: 700;
            margin-bottom: 24px;
            color: #1a202c;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: #64748b;
            font-weight: 600;
            font-size: 14px;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #e8eaf0;
            border-radius: 8px;
            font-size: 14px;
            transition: border 0.2s;
        }
        
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #667eea;
        }
        
        textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        .form-actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }
        
        .btn-secondary {
            background: #f1f5f9;
            color: #64748b;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        th {
            background: #f8f9fc;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #64748b;
            border-bottom: 2px solid #e8eaf0;
            font-size: 13px;
        }
        
        td {
            padding: 12px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 14px;
        }
        
        tr:hover {
            background: #f8f9fc;
        }
        
        .transaction-deposit {
            color: #10b981;
            font-weight: 600;
        }
        
        .transaction-withdrawal {
            color: #ef4444;
            font-weight: 600;
        }
        
        .empty-state {
            text-align: center;
            padding: 80px 20px;
            color: #94a3b8;
        }
        
        .empty-icon {
            font-size: 72px;
            margin-bottom: 16px;
        }
        
        .empty-state h3 {
            color: #64748b;
            font-size: 20px;
            margin-bottom: 8px;
        }
        
        .stats-row {
            display: flex;
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            flex: 1;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            padding: 16px;
            border-radius: 12px;
            border: 1px solid rgba(102, 126, 234, 0.2);
        }
        
        .stat-label {
            font-size: 12px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            margin-bottom: 8px;
        }
        
        .stat-value {
            font-size: 24px;
            font-weight: 700;
            color: #1a202c;
        }
        
        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main-content { margin-left: 0; padding: 16px; }
            .goals-grid { grid-template-columns: 1fr; }
            .stats-row { flex-direction: column; }
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
                <a href="savings.php" class="nav-link active">
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
                <a href="setting.php" class="nav-link">
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
        <div class="top-bar">
            <div class="page-title">🎯 Your Savings Goals</div>
            <button class="btn btn-primary" onclick="openModal('createGoal')">
                + Create Goal
            </button>
        </div>

        <?php if (count($goals) > 0): 
            $totalTarget = array_sum(array_column($goals, 'target_amount'));
            $totalSaved = array_sum(array_column($goals, 'current_amount'));
            $overallProgress = $totalTarget > 0 ? ($totalSaved / $totalTarget) * 100 : 0;
        ?>
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-label">Total Target</div>
                <div class="stat-value">$<?php echo number_format($totalTarget, 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Total Saved</div>
                <div class="stat-value">$<?php echo number_format($totalSaved, 2); ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Overall Progress</div>
                <div class="stat-value"><?php echo round($overallProgress); ?>%</div>
            </div>
        </div>
        
        <div class="goals-grid">
            <?php foreach ($goals as $goal):
                $percentage = $goal['target_amount'] > 0 ? ($goal['current_amount'] / $goal['target_amount']) * 100 : 0;
                $remaining = $goal['target_amount'] - $goal['current_amount'];
                $isComplete = $percentage >= 100;
                $catClass = 'cat-' . strtolower(str_replace([' ', '&'], '', $goal['category']));
                
                // Calculate days until deadline
                $daysRemaining = null;
                $isUrgent = false;
                if ($goal['deadline']) {
                    $deadline = new DateTime($goal['deadline']);
                    $today = new DateTime();
                    $diff = $today->diff($deadline);
                    $daysRemaining = $diff->days;
                    if ($deadline < $today) {
                        $daysRemaining = -$daysRemaining;
                    }
                    $isUrgent = $daysRemaining <= 30 && $daysRemaining >= 0;
                }
            ?>
            <div class="goal-card">
                <div class="goal-header">
                    <div class="goal-info">
                        <h3><?php echo htmlspecialchars($goal['name']); ?></h3>
                        <span class="category-badge <?php echo $catClass; ?>"><?php echo htmlspecialchars($goal['category']); ?></span>
                        <?php if ($goal['deadline']): ?>
                        <div class="deadline-badge <?php echo $isUrgent ? 'deadline-urgent' : ''; ?>">
                            📅 <?php 
                                if ($daysRemaining < 0) {
                                    echo 'Overdue by ' . abs($daysRemaining) . ' days';
                                } elseif ($daysRemaining == 0) {
                                    echo 'Due today';
                                } else {
                                    echo $daysRemaining . ' days left';
                                }
                            ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="goal-actions">
                        <button class="btn-mini btn-add" onclick="openAddTransaction(<?php echo $goal['id']; ?>)" title="Add Transaction">➕</button>
                        <button class="btn-mini btn-view" onclick="window.location.href='?view=<?php echo $goal['id']; ?>'" title="View Transactions">👁️</button>
                        <button class="btn-mini btn-edit" onclick="window.location.href='?edit=<?php echo $goal['id']; ?>'" title="Edit">✏️</button>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this goal and all its transactions?');">
                            <input type="hidden" name="action" value="delete_goal">
                            <input type="hidden" name="id" value="<?php echo $goal['id']; ?>">
                            <button type="submit" class="btn-mini btn-delete" title="Delete">🗑️</button>
                        </form>
                    </div>
                </div>
                
                <div class="goal-amounts">
                    <div class="amount-item">
                        <span class="amount-label">Saved</span>
                        <span class="amount-value">$<?php echo number_format($goal['current_amount'], 2); ?></span>
                    </div>
                    <div class="amount-item">
                        <span class="amount-label">Target</span>
                        <span class="amount-value">$<?php echo number_format($goal['target_amount'], 2); ?></span>
                    </div>
                </div>
                
                <div class="progress-bar">
                    <div class="progress-fill <?php echo $isComplete ? 'progress-complete' : ''; ?>" style="width: <?php echo min($percentage, 100); ?>%"></div>
                </div>
                
                <div class="goal-status">
                    <span class="<?php echo $isComplete ? 'status-complete' : 'status-remaining'; ?>">
                        <?php if ($isComplete): ?>
                            🎉 Goal Achieved!
                        <?php else: ?>
                            $<?php echo number_format($remaining, 2); ?> remaining
                        <?php endif; ?>
                    </span>
                    <span style="color: #94a3b8;"><?php echo round($percentage); ?>%</span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <div class="empty-icon">🎯</div>
            <h3>No savings goals yet</h3>
            <p style="margin-top: 8px;">Create your first savings goal and start achieving your dreams!</p>
        </div>
        <?php endif; ?>
    </main>

    <button class="fab" onclick="openModal('createGoal')" title="Create New Goal">+</button>

    <!-- Create/Edit Goal Modal -->
    <div id="createGoal" class="modal <?php echo $editGoal ? 'active' : ''; ?>">
        <div class="modal-content">
            <div class="modal-header"><?php echo $editGoal ? 'Edit Savings Goal' : 'Create New Savings Goal'; ?></div>
            <form method="POST">
                <input type="hidden" name="action" value="<?php echo $editGoal ? 'update_goal' : 'create_goal'; ?>">
                <?php if ($editGoal): ?>
                    <input type="hidden" name="id" value="<?php echo $editGoal['id']; ?>">
                <?php endif; ?>
                
                <div class="form-group">
                    <label>Goal Name</label>
                    <input type="text" name="name" value="<?php echo $editGoal['name'] ?? ''; ?>" placeholder="e.g., Dream Vacation to Japan" required>
                </div>
                
                <div class="form-group">
                    <label>Category</label>
                    <select name="category" required>
                        <option value="">Choose a category...</option>
                        <option value="Vacation" <?php echo (isset($editGoal['category']) && $editGoal['category'] == 'Vacation') ? 'selected' : ''; ?>>Vacation</option>
                        <option value="Emergency Fund" <?php echo (isset($editGoal['category']) && $editGoal['category'] == 'Emergency Fund') ? 'selected' : ''; ?>>Emergency Fund</option>
                        <option value="House" <?php echo (isset($editGoal['category']) && $editGoal['category'] == 'House') ? 'selected' : ''; ?>>House</option>
                        <option value="Car" <?php echo (isset($editGoal['category']) && $editGoal['category'] == 'Car') ? 'selected' : ''; ?>>Car</option>
                        <option value="Education" <?php echo (isset($editGoal['category']) && $editGoal['category'] == 'Education') ? 'selected' : ''; ?>>Education</option>
                        <option value="Retirement" <?php echo (isset($editGoal['category']) && $editGoal['category'] == 'Retirement') ? 'selected' : ''; ?>>Retirement</option>
                        <option value="Wedding" <?php echo (isset($editGoal['category']) && $editGoal['category'] == 'Wedding') ? 'selected' : ''; ?>>Wedding</option>
                        <option value="Gadget" <?php echo (isset($editGoal['category']) && $editGoal['category'] == 'Gadget') ? 'selected' : ''; ?>>Gadget</option>
                        <option value="Other" <?php echo (isset($editGoal['category']) && $editGoal['category'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Target Amount ($)</label>
                    <input type="number" step="0.01" name="target_amount" value="<?php echo $editGoal['target_amount'] ?? ''; ?>" placeholder="5000.00" required>
                </div>
                
                <?php if (!$editGoal): ?>
                <div class="form-group">
                    <label>Current Amount ($) - Optional</label>
                    <input type="number" step="0.01" name="current_amount" value="0" placeholder="0.00">
                </div>
                <?php endif; ?>
                
                <div class="form-group">
                    <label>Deadline (Optional)</label>
                    <input type="date" name="deadline" value="<?php echo $editGoal['deadline'] ?? ''; ?>">
                </div>
                
                <div class="form-group">
                    <label>Description (Optional)</label>
                    <textarea name="description" placeholder="Why is this goal important to you?"><?php echo $editGoal['description'] ?? ''; ?></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?php echo $editGoal ? 'Update' : 'Create'; ?> Goal</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal('createGoal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Add Transaction Modal -->
    <div id="addTransaction" class="modal">
        <div class="modal-content">
            <div class="modal-header">Add Transaction</div>
            <form method="POST">
                <input type="hidden" name="action" value="add_transaction">
                <input type="hidden" name="goal_id" id="transaction_goal_id" value="">
                
                <div class="form-group">
                    <label>Transaction Type</label>
                    <select name="transaction_type" required>
                        <option value="deposit">💰 Deposit (Add Money)</option>
                        <option value="withdrawal">💸 Withdrawal (Remove Money)</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Amount ($)</label>
                    <input type="number" step="0.01" name="amount" placeholder="100.00" required>
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" placeholder="What is this transaction for?" required></textarea>
                </div>
                
                <div class="form-group">
                    <label>Date</label>
                    <input type="date" name="date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Add Transaction</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addTransaction')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Transactions Modal -->
    <?php if ($viewTransactions !== null && $currentGoal): ?>
    <div id="viewTransactions" class="modal active">
        <div class="modal-content large">
            <div class="modal-header">
                <?php echo htmlspecialchars($currentGoal['name']); ?> - Transactions
                <button class="btn btn-primary" style="float: right; padding: 8px 16px; font-size: 13px;" onclick="openAddTransaction(<?php echo $currentGoal['id']; ?>)">+ Add Transaction</button>
            </div>
            
            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-label">Current Amount</div>
                    <div class="stat-value">$<?php echo number_format($currentGoal['current_amount'], 2); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Target Amount</div>
                    <div class="stat-value">$<?php echo number_format($currentGoal['target_amount'], 2); ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Progress</div>
                    <div class="stat-value"><?php echo round(($currentGoal['current_amount'] / $currentGoal['target_amount']) * 100); ?>%</div>
                </div>
            </div>
            
            <?php if (count($viewTransactions) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Type</th>
                        <th>Description</th>
                        <th>Amount</th>
                        <th style="text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($viewTransactions as $transaction): ?>
                    <tr>
                        <td><?php echo date('M d, Y', strtotime($transaction['date'])); ?></td>
                        <td>
                            <?php if ($transaction['transaction_type'] === 'deposit'): ?>
                                <span style="color: #10b981; font-weight: 600;">💰 Deposit</span>
                            <?php else: ?>
                                <span style="color: #ef4444; font-weight: 600;">💸 Withdrawal</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($transaction['description']); ?></td>
                        <td class="<?php echo $transaction['transaction_type'] === 'deposit' ? 'transaction-deposit' : 'transaction-withdrawal'; ?>">
                            <?php echo $transaction['transaction_type'] === 'deposit' ? '+' : '-'; ?>$<?php echo number_format($transaction['amount'], 2); ?>
                        </td>
                        <td style="text-align: center;">
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this transaction? This will adjust your goal amount.');">
                                <input type="hidden" name="action" value="delete_transaction">
                                <input type="hidden" name="id" value="<?php echo $transaction['id']; ?>">
                                <button type="submit" class="btn-mini btn-delete" title="Delete">🗑️</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon">💰</div>
                <h3>No transactions yet</h3>
                <p>Start adding deposits to reach your savings goal!</p>
            </div>
            <?php endif; ?>
            
            <div class="form-actions" style="margin-top: 20px;">
                <button type="button" class="btn btn-secondary" onclick="window.location.href='savings.php'">Close</button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
        function openModal(id) {
            document.getElementById(id).classList.add('active');
        }
        
        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
            if (id === 'createGoal' && window.location.search.includes('edit=')) {
                window.location.href = 'savings.php';
            }
        }
        
        function openAddTransaction(goalId) {
            document.getElementById('transaction_goal_id').value = goalId;
            closeModal('viewTransactions');
            openModal('addTransaction');
        }
        
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }
        
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const modals = document.querySelectorAll('.modal.active');
                modals.forEach(modal => modal.classList.remove('active'));
            }
        });
    </script>
</body>
</html>