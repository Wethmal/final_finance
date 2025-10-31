<?php
// budgets.php - Budget and Expense CRUD Operations
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

// Check and add user_id column if needed
try {
    $result = $db->query("PRAGMA table_info(budgets)");
    $columns = $result->fetchAll(PDO::FETCH_ASSOC);
    $hasUserId = false;
    
    foreach ($columns as $column) {
        if ($column['name'] === 'user_id') {
            $hasUserId = true;
            break;
        }
    }
    
    if (!$hasUserId && count($columns) > 0) {
        $db->exec('ALTER TABLE budgets ADD COLUMN user_id INTEGER DEFAULT 1');
        $stmt = $db->prepare('UPDATE budgets SET user_id = :uid WHERE user_id IS NULL OR user_id = 1');
        $stmt->execute([':uid' => $user_id]);
    }
} catch(PDOException $e) {}

// Create tables
$db->exec('CREATE TABLE IF NOT EXISTS budgets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    category TEXT NOT NULL,
    budget_amount REAL NOT NULL,
    created_date TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
)');

$db->exec('CREATE TABLE IF NOT EXISTS expenses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    budget_id INTEGER NOT NULL,
    amount REAL NOT NULL,
    description TEXT,
    date TEXT NOT NULL,
    FOREIGN KEY (budget_id) REFERENCES budgets(id) ON DELETE CASCADE
)');

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create_budget':
                $stmt = $db->prepare('INSERT INTO budgets (user_id, name, category, budget_amount, created_date) VALUES (:uid, :name, :cat, :amt, :date)');
                $stmt->execute([
                    ':uid' => $user_id,
                    ':name' => $_POST['name'],
                    ':cat' => $_POST['category'],
                    ':amt' => $_POST['budget_amount'],
                    ':date' => date('Y-m-d')
                ]);
                break;
            
            case 'add_expense':
                $stmt = $db->prepare('INSERT INTO expenses (budget_id, amount, description, date) VALUES (:bid, :amt, :desc, :date)');
                $stmt->execute([
                    ':bid' => $_POST['budget_id'],
                    ':amt' => $_POST['amount'],
                    ':desc' => $_POST['description'],
                    ':date' => $_POST['date']
                ]);
                break;
            
            case 'update_budget':
                $stmt = $db->prepare('UPDATE budgets SET name=:name, category=:cat, budget_amount=:amt WHERE id=:id AND user_id=:uid');
                $stmt->execute([
                    ':name' => $_POST['name'],
                    ':cat' => $_POST['category'],
                    ':amt' => $_POST['budget_amount'],
                    ':id' => $_POST['id'],
                    ':uid' => $user_id
                ]);
                break;
            
            case 'delete_budget':
                $stmt = $db->prepare('DELETE FROM budgets WHERE id=:id AND user_id=:uid');
                $stmt->execute([':id' => $_POST['id'], ':uid' => $user_id]);
                break;
            
            case 'delete_expense':
                $stmt = $db->prepare('DELETE FROM expenses WHERE id=:id AND budget_id IN (SELECT id FROM budgets WHERE user_id=:uid)');
                $stmt->execute([':id' => $_POST['id'], ':uid' => $user_id]);
                break;
            
            case 'update_expense':
                $stmt = $db->prepare('UPDATE expenses SET amount=:amt, description=:desc, date=:date WHERE id=:id AND budget_id IN (SELECT id FROM budgets WHERE user_id=:uid)');
                $stmt->execute([
                    ':amt' => $_POST['amount'],
                    ':desc' => $_POST['description'],
                    ':date' => $_POST['date'],
                    ':id' => $_POST['id'],
                    ':uid' => $user_id
                ]);
                break;
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Get all budgets
$stmt = $db->prepare('
    SELECT b.*, COALESCE(SUM(e.amount), 0) as spent
    FROM budgets b
    LEFT JOIN expenses e ON b.id = e.budget_id
    WHERE b.user_id = :uid
    GROUP BY b.id
    ORDER BY b.created_date DESC
');
$stmt->execute([':uid' => $user_id]);
$budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get budget for editing
$editBudget = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare('SELECT * FROM budgets WHERE id=:id AND user_id=:uid');
    $stmt->execute([':id' => $_GET['edit'], ':uid' => $user_id]);
    $editBudget = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get expenses for viewing
$viewExpenses = null;
$currentBudget = null;
$editExpense = null;
if (isset($_GET['view'])) {
    $stmt = $db->prepare('SELECT * FROM expenses WHERE budget_id=:id AND budget_id IN (SELECT id FROM budgets WHERE user_id=:uid) ORDER BY date DESC');
    $stmt->execute([':id' => $_GET['view'], ':uid' => $user_id]);
    $viewExpenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $db->prepare('SELECT * FROM budgets WHERE id=:id AND user_id=:uid');
    $stmt->execute([':id' => $_GET['view'], ':uid' => $user_id]);
    $currentBudget = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (isset($_GET['edit_expense'])) {
        $stmt = $db->prepare('SELECT * FROM expenses WHERE id=:id AND budget_id IN (SELECT id FROM budgets WHERE user_id=:uid)');
        $stmt->execute([':id' => $_GET['edit_expense'], ':uid' => $user_id]);
        $editExpense = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budgets - Statok</title>
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
        
        .budget-cards {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 24px;
        }
        
        .budget-card {
            background: white;
            border-radius: 16px;
            padding: 24px;
            border: 1px solid #e8eaf0;
            transition: all 0.2s;
        }
        
        .budget-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 24px rgba(0,0,0,0.1);
        }
        
        .budget-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 20px;
        }
        
        .budget-info h3 {
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
        
        .cat-food { background: #ffe5f1; color: #e91e63; }
        .cat-transport { background: #e3f2fd; color: #2196f3; }
        .cat-shopping { background: #f3e5f5; color: #9c27b0; }
        .cat-bills { background: #fff3e0; color: #ff9800; }
        .cat-entertainment { background: #e0f2f1; color: #009688; }
        .cat-healthcare { background: #ffebee; color: #f44336; }
        .cat-education { background: #e8f5e9; color: #4caf50; }
        .cat-other { background: #f5f5f5; color: #757575; }
        
        .budget-actions {
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
        .btn-mini:hover { transform: scale(1.1); }
        
        .budget-amounts {
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
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            border-radius: 5px;
            transition: width 0.3s;
        }
        
        .progress-over {
            background: linear-gradient(90deg, #f44336 0%, #e91e63 100%);
        }
        
        .budget-status {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
            font-weight: 600;
        }
        
        .status-positive { color: #10b981; }
        .status-warning { color: #f59e0b; }
        .status-over { color: #ef4444; }
        
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
        
        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main-content { margin-left: 0; padding: 16px; }
            .budget-cards { grid-template-columns: 1fr; }
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
                <a href="budgets.php" class="nav-link active">
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
            <div class="page-title">💰 Your Budgets</div>
            <button class="btn btn-primary" onclick="openModal('createBudget')">
                + Create Budget
            </button>
        </div>

        <?php if (count($budgets) > 0): ?>
        <div class="budget-cards">
            <?php foreach ($budgets as $budget):
                $remaining = $budget['budget_amount'] - $budget['spent'];
                $percentage = $budget['budget_amount'] > 0 ? ($budget['spent'] / $budget['budget_amount']) * 100 : 0;
                $statusClass = $percentage >= 100 ? 'status-over' : ($percentage >= 80 ? 'status-warning' : 'status-positive');
                $catClass = 'cat-' . strtolower(str_replace(['&', ' '], '', explode(' ', $budget['category'])[0]));
            ?>
            <div class="budget-card">
                <div class="budget-header">
                    <div class="budget-info">
                        <h3><?php echo htmlspecialchars($budget['name']); ?></h3>
                        <span class="category-badge <?php echo $catClass; ?>"><?php echo htmlspecialchars($budget['category']); ?></span>
                    </div>
                    <div class="budget-actions">
                        <button class="btn-mini btn-view" onclick="window.location.href='?view=<?php echo $budget['id']; ?>'" title="View Expenses">👁️</button>
                        <button class="btn-mini btn-edit" onclick="window.location.href='?edit=<?php echo $budget['id']; ?>'" title="Edit">✏️</button>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this budget and all its expenses?');">
                            <input type="hidden" name="action" value="delete_budget">
                            <input type="hidden" name="id" value="<?php echo $budget['id']; ?>">
                            <button type="submit" class="btn-mini btn-delete" title="Delete">🗑️</button>
                        </form>
                    </div>
                </div>
                
                <div class="budget-amounts">
                    <div class="amount-item">
                        <span class="amount-label">Spent</span>
                        <span class="amount-value">$<?php echo number_format($budget['spent'], 2); ?></span>
                    </div>
                    <div class="amount-item">
                        <span class="amount-label">Budget</span>
                        <span class="amount-value">$<?php echo number_format($budget['budget_amount'], 2); ?></span>
                    </div>
                </div>
                
                <div class="progress-bar">
                    <div class="progress-fill <?php echo $percentage >= 100 ? 'progress-over' : ''; ?>" style="width: <?php echo min($percentage, 100); ?>%"></div>
                </div>
                
                <div class="budget-status">
                    <span class="<?php echo $statusClass; ?>">
                        $<?php echo number_format(abs($remaining), 2); ?> <?php echo $remaining >= 0 ? 'remaining' : 'over budget'; ?>
                    </span>
                    <span style="color: #94a3b8;"><?php echo round($percentage); ?>% used</span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <div class="empty-icon">💰</div>
            <h3>No budgets yet</h3>
            <p style="margin-top: 8px;">Create your first budget to start tracking your spending!</p>
        </div>
        <?php endif; ?>
    </main>

    <button class="fab" onclick="openModal('createBudget')" title="Create New Budget">+</button>

    <!-- Create/Edit Budget Modal -->
    <div id="createBudget" class="modal <?php echo $editBudget ? 'active' : ''; ?>">
        <div class="modal-content">
            <div class="modal-header"><?php echo $editBudget ? 'Edit Budget' : 'Create New Budget'; ?></div>
            <form method="POST">
                <input type="hidden" name="action" value="<?php echo $editBudget ? 'update_budget' : 'create_budget'; ?>">
                <?php if ($editBudget): ?>
                    <input type="hidden" name="id" value="<?php echo $editBudget['id']; ?>">
                <?php endif; ?>
                
                <div class="form-group">
                    <label>Budget Name</label>
                    <input type="text" name="name" value="<?php echo $editBudget['name'] ?? ''; ?>" placeholder="e.g., Monthly Food Budget" required>
                </div>
                
                <div class="form-group">
                    <label>Category</label>
                    <select name="category" required>
                        <option value="">Choose a category...</option>
                        <option value="Food & Dining" <?php echo (isset($editBudget['category']) && $editBudget['category'] == 'Food & Dining') ? 'selected' : ''; ?>>Food & Dining</option>
                        <option value="Transportation" <?php echo (isset($editBudget['category']) && $editBudget['category'] == 'Transportation') ? 'selected' : ''; ?>>Transportation</option>
                        <option value="Shopping" <?php echo (isset($editBudget['category']) && $editBudget['category'] == 'Shopping') ? 'selected' : ''; ?>>Shopping</option>
                        <option value="Bills & Utilities" <?php echo (isset($editBudget['category']) && $editBudget['category'] == 'Bills & Utilities') ? 'selected' : ''; ?>>Bills & Utilities</option>
                        <option value="Entertainment" <?php echo (isset($editBudget['category']) && $editBudget['category'] == 'Entertainment') ? 'selected' : ''; ?>>Entertainment</option>
                        <option value="Healthcare" <?php echo (isset($editBudget['category']) && $editBudget['category'] == 'Healthcare') ? 'selected' : ''; ?>>Healthcare</option>
                        <option value="Education" <?php echo (isset($editBudget['category']) && $editBudget['category'] == 'Education') ? 'selected' : ''; ?>>Education</option>
                        <option value="Other" <?php echo (isset($editBudget['category']) && $editBudget['category'] == 'Other') ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Budget Amount ($)</label>
                    <input type="number" step="0.01" name="budget_amount" value="<?php echo $editBudget['budget_amount'] ?? ''; ?>" placeholder="500.00" required>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?php echo $editBudget ? 'Update' : 'Create'; ?> Budget</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal('createBudget')">Cancel</button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Expenses Modal -->
    <?php if ($viewExpenses !== null && $currentBudget): ?>
    <div id="viewExpenses" class="modal active">
        <div class="modal-content large">
            <div class="modal-header">
                <?php echo htmlspecialchars($currentBudget['name']); ?> - Expenses
                <button class="btn btn-primary" style="float: right; padding: 8px 16px; font-size: 13px;" onclick="openModal('addExpense')">+ Add Expense</button>
            </div>
            
            <?php if (count($viewExpenses) > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Description</th>
                        <th>Amount</th>
                        <th style="text-align: center;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($viewExpenses as $expense): ?>
                    <tr>
                        <td><?php echo date('M d, Y', strtotime($expense['date'])); ?></td>
                        <td><?php echo htmlspecialchars($expense['description']); ?></td>
                        <td style="font-weight: 700; color: #1a202c;">$<?php echo number_format($expense['amount'], 2); ?></td>
                        <td style="text-align: center;">
                            <div style="display: flex; gap: 8px; justify-content: center;">
                                <button class="btn-mini btn-edit" onclick="window.location.href='?view=<?php echo $_GET['view']; ?>&edit_expense=<?php echo $expense['id']; ?>'" title="Edit">✏️</button>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this expense?');">
                                    <input type="hidden" name="action" value="delete_expense">
                                    <input type="hidden" name="id" value="<?php echo $expense['id']; ?>">
                                    <button type="submit" class="btn-mini btn-delete" title="Delete">🗑️</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon">📝</div>
                <h3>No expenses added yet</h3>
                <p>Start tracking your spending by adding expenses</p>
            </div>
            <?php endif; ?>
            
            <div class="form-actions" style="margin-top: 20px;">
                <button type="button" class="btn btn-secondary" onclick="window.location.href='budgets.php'">Close</button>
            </div>
        </div>
    </div>
    
    <!-- Add/Edit Expense Modal -->
    <div id="addExpense" class="modal <?php echo $editExpense ? 'active' : ''; ?>">
        <div class="modal-content">
            <div class="modal-header"><?php echo $editExpense ? 'Edit Expense' : 'Add Expense'; ?></div>
            <form method="POST">
                <input type="hidden" name="action" value="<?php echo $editExpense ? 'update_expense' : 'add_expense'; ?>">
                <input type="hidden" name="budget_id" value="<?php echo $_GET['view']; ?>">
                <?php if ($editExpense): ?>
                    <input type="hidden" name="id" value="<?php echo $editExpense['id']; ?>">
                <?php endif; ?>
                
                <div class="form-group">
                    <label>Amount ($)</label>
                    <input type="number" step="0.01" name="amount" value="<?php echo $editExpense['amount'] ?? ''; ?>" placeholder="25.50" required>
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" placeholder="What did you spend on?" required><?php echo $editExpense['description'] ?? ''; ?></textarea>
                </div>
                
                <div class="form-group">
                    <label>Date</label>
                    <input type="date" name="date" value="<?php echo $editExpense['date'] ?? date('Y-m-d'); ?>" required>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary"><?php echo $editExpense ? 'Update' : 'Add'; ?> Expense</button>
                    <button type="button" class="btn btn-secondary" onclick="window.location.href='?view=<?php echo $_GET['view']; ?>'">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        function openModal(id) {
            document.getElementById(id).classList.add('active');
        }
        
        function closeModal(id) {
            document.getElementById(id).classList.remove('active');
            if (id === 'createBudget' && window.location.search.includes('edit=')) {
                window.location.href = 'budgets.php';
            }
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