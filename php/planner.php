<?php
// Database setup using PDO
try {
    $db = new PDO('sqlite:' .__DIR__ . '/../db/database.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Create tables if not exist
$db->exec('CREATE TABLE IF NOT EXISTS budgets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    category TEXT NOT NULL,
    budget_amount REAL NOT NULL,
    created_date TEXT NOT NULL
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
                $stmt = $db->prepare('INSERT INTO budgets (name, category, budget_amount, created_date) VALUES (:name, :cat, :amt, :date)');
                $stmt->execute([
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
                $stmt = $db->prepare('UPDATE budgets SET name=:name, category=:cat, budget_amount=:amt WHERE id=:id');
                $stmt->execute([
                    ':name' => $_POST['name'],
                    ':cat' => $_POST['category'],
                    ':amt' => $_POST['budget_amount'],
                    ':id' => $_POST['id']
                ]);
                break;
            
            case 'delete_budget':
                $stmt = $db->prepare('DELETE FROM budgets WHERE id=:id');
                $stmt->execute([':id' => $_POST['id']]);
                break;
            
            case 'delete_expense':
                $stmt = $db->prepare('DELETE FROM expenses WHERE id=:id');
                $stmt->execute([':id' => $_POST['id']]);
                break;
        }
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Get all budgets with spent amounts
$stmt = $db->query('
    SELECT b.*, COALESCE(SUM(e.amount), 0) as spent
    FROM budgets b
    LEFT JOIN expenses e ON b.id = e.budget_id
    GROUP BY b.id
    ORDER BY b.created_date DESC
');
$budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get budget for editing
$editBudget = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare('SELECT * FROM budgets WHERE id=:id');
    $stmt->execute([':id' => $_GET['edit']]);
    $editBudget = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get expenses for a specific budget
$viewExpenses = null;
if (isset($_GET['view'])) {
    $stmt = $db->prepare('SELECT * FROM expenses WHERE budget_id=:id ORDER BY date DESC');
    $stmt->execute([':id' => $_GET['view']]);
    $viewExpenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $db->prepare('SELECT * FROM budgets WHERE id=:id');
    $stmt->execute([':id' => $_GET['view']]);
    $currentBudget = $stmt->fetch(PDO::FETCH_ASSOC);
}

$page = $_GET['page'] ?? 'overview';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Budget Planner</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f7fa; }
        
        .header { background: white; padding: 20px 40px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 28px; color: #2c3e50; display: flex; align-items: center; gap: 12px; }
        .icon { font-size: 32px; }
        
        .btn-create { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border: none; padding: 14px 28px; border-radius: 12px; cursor: pointer; font-size: 16px; font-weight: 600; display: flex; align-items: center; gap: 8px; transition: transform 0.2s; }
        .btn-create:hover { transform: translateY(-2px); }
        
        .container { max-width: 1400px; margin: 40px auto; padding: 0 40px; }
        
        .budget-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); gap: 24px; }
        
        .budget-card { background: white; border-radius: 16px; padding: 28px; box-shadow: 0 4px 20px rgba(0,0,0,0.08); transition: transform 0.2s; }
        .budget-card:hover { transform: translateY(-4px); }
        
        .card-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; }
        .card-title { font-size: 20px; font-weight: 600; color: #2c3e50; margin-bottom: 8px; }
        
        .category-badge { display: inline-block; padding: 6px 14px; border-radius: 20px; font-size: 13px; font-weight: 600; }
        .cat-food { background: #ffe5f1; color: #e91e63; }
        .cat-transport { background: #e3f2fd; color: #2196f3; }
        .cat-shopping { background: #f3e5f5; color: #9c27b0; }
        .cat-bills { background: #fff3e0; color: #ff9800; }
        .cat-entertainment { background: #e0f2f1; color: #009688; }
        .cat-healthcare { background: #ffebee; color: #f44336; }
        .cat-education { background: #e8f5e9; color: #4caf50; }
        .cat-other { background: #f5f5f5; color: #757575; }
        
        .action-btns { display: flex; gap: 8px; }
        .btn-icon { width: 40px; height: 40px; border-radius: 10px; border: none; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 18px; transition: all 0.2s; }
        .btn-edit { background: #4fc3f7; color: white; }
        .btn-delete { background: #ff4081; color: white; }
        .btn-view { background: #ab47bc; color: white; }
        .btn-icon:hover { transform: scale(1.1); }
        
        .budget-amounts { display: flex; justify-content: space-between; margin-bottom: 16px; font-size: 15px; color: #546e7a; }
        .amount-value { font-weight: 700; color: #2c3e50; }
        
        .progress-bar { height: 12px; background: #eceff1; border-radius: 10px; overflow: hidden; margin-bottom: 12px; }
        .progress-fill { height: 100%; background: linear-gradient(90deg, #667eea 0%, #764ba2 100%); transition: width 0.3s; border-radius: 10px; }
        .progress-over { background: linear-gradient(90deg, #f44336 0%, #e91e63 100%); }
        
        .budget-status { display: flex; align-items: center; justify-content: space-between; }
        .remaining { display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 16px; }
        .remaining.positive { color: #4caf50; }
        .remaining.warning { color: #ff9800; }
        .remaining.over { color: #f44336; }
        .usage { color: #90a4ae; font-size: 14px; }
        
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; }
        .modal.active { display: flex; }
        .modal-content { background: white; padding: 32px; border-radius: 16px; max-width: 500px; width: 90%; }
        .modal-header { font-size: 24px; font-weight: 600; margin-bottom: 24px; color: #2c3e50; }
        
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; color: #546e7a; font-weight: 600; font-size: 14px; }
        input, select, textarea { width: 100%; padding: 12px; border: 2px solid #eceff1; border-radius: 8px; font-size: 14px; transition: border 0.2s; }
        input:focus, select:focus, textarea:focus { outline: none; border-color: #667eea; }
        textarea { resize: vertical; min-height: 80px; }
        
        .form-actions { display: flex; gap: 12px; margin-top: 24px; }
        .btn { padding: 12px 24px; border-radius: 8px; border: none; cursor: pointer; font-size: 14px; font-weight: 600; transition: all 0.2s; }
        .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
        .btn-secondary { background: #eceff1; color: #546e7a; }
        .btn:hover { transform: translateY(-1px); }
        
        .expense-list { margin-top: 20px; }
        .expense-item { background: #f8f9fa; padding: 12px 16px; border-radius: 8px; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; }
        .expense-info { flex: 1; }
        .expense-amount { font-weight: 600; color: #2c3e50; margin-bottom: 4px; }
        .expense-desc { font-size: 13px; color: #90a4ae; }
        .expense-date { font-size: 12px; color: #b0bec5; }
        
        .empty-state { text-align: center; padding: 60px 20px; color: #b0bec5; }
        .empty-state-icon { font-size: 64px; margin-bottom: 16px; }
    </style>
</head>
<body>
    <div class="header">
        <h1><span class="icon">🧮</span> Budget Overview</h1>
        <button class="btn-create" onclick="openModal('createBudget')">
            <span style="font-size: 20px;">+</span> Create Budget
        </button>
    </div>

    <div class="container">
        <?php if (count($budgets) > 0): ?>
        <div class="budget-grid">
            <?php foreach ($budgets as $budget):
                $remaining = $budget['budget_amount'] - $budget['spent'];
                $percentage = ($budget['spent'] / $budget['budget_amount']) * 100;
                $statusClass = $percentage >= 100 ? 'over' : ($percentage >= 80 ? 'warning' : 'positive');
                $catClass = 'cat-' . strtolower(str_replace(['&', ' '], '', explode(' ', $budget['category'])[0]));
            ?>
            <div class="budget-card">
                <div class="card-header">
                    <div>
                        <div class="card-title"><?php echo htmlspecialchars($budget['name']); ?></div>
                        <span class="category-badge <?php echo $catClass; ?>"><?php echo $budget['category']; ?></span>
                    </div>
                    <div class="action-btns">
                        <button class="btn-icon btn-view" onclick="window.location.href='?view=<?php echo $budget['id']; ?>'" title="View Expenses">👁️</button>
                        <button class="btn-icon btn-edit" onclick="window.location.href='?edit=<?php echo $budget['id']; ?>&page=overview'" title="Edit">✏️</button>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this budget and all expenses?');">
                            <input type="hidden" name="action" value="delete_budget">
                            <input type="hidden" name="id" value="<?php echo $budget['id']; ?>">
                            <button type="submit" class="btn-icon btn-delete" title="Delete">🗑️</button>
                        </form>
                    </div>
                </div>
                
                <div class="budget-amounts">
                    <span>Spent: <span class="amount-value">$<?php echo number_format($budget['spent'], 2); ?></span></span>
                    <span>Budget: <span class="amount-value">$<?php echo number_format($budget['budget_amount'], 2); ?></span></span>
                </div>
                
                <div class="progress-bar">
                    <div class="progress-fill <?php echo $percentage >= 100 ? 'progress-over' : ''; ?>" style="width: <?php echo min($percentage, 100); ?>%"></div>
                </div>
                
                <div class="budget-status">
                    <div class="remaining <?php echo $statusClass; ?>">
                        <span><?php echo $statusClass == 'over' ? '⚠️' : ($statusClass == 'warning' ? '⚠️' : '✅'); ?></span>
                        $<?php echo number_format(abs($remaining), 2); ?> <?php echo $remaining >= 0 ? 'remaining' : 'over budget'; ?>
                    </div>
                    <div class="usage"><?php echo round($percentage); ?>% used</div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="empty-state">
            <div class="empty-state-icon">📊</div>
            <h2>No budgets yet</h2>
            <p>Create your first budget to start tracking your expenses!</p>
        </div>
        <?php endif; ?>
    </div>

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
                    <button type="button" class="btn btn-secondary" onclick="closeModal('createBudget')"><?php echo $editBudget ? 'Cancel' : 'Close'; ?></button>
                </div>
            </form>
        </div>
    </div>

    <!-- View Expenses Modal -->
    <?php if ($viewExpenses !== null): ?>
    <div id="viewExpenses" class="modal active">
        <div class="modal-content" style="max-width: 700px;">
            <div class="modal-header">
                <?php echo htmlspecialchars($currentBudget['name']); ?> - Expenses
                <button class="btn btn-primary" style="float: right; padding: 8px 16px; font-size: 14px;" onclick="openModal('addExpense')">+ Add Expense</button>
            </div>
            
            <?php if (count($viewExpenses) > 0): ?>
            <div class="expense-list">
                <?php foreach ($viewExpenses as $expense): ?>
                <div class="expense-item">
                    <div class="expense-info">
                        <div class="expense-amount">$<?php echo number_format($expense['amount'], 2); ?></div>
                        <div class="expense-desc"><?php echo htmlspecialchars($expense['description']); ?></div>
                        <div class="expense-date"><?php echo date('M d, Y', strtotime($expense['date'])); ?></div>
                    </div>
                    <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this expense?');">
                        <input type="hidden" name="action" value="delete_expense">
                        <input type="hidden" name="id" value="<?php echo $expense['id']; ?>">
                        <button type="submit" class="btn-icon btn-delete">🗑️</button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <p>No expenses added yet</p>
            </div>
            <?php endif; ?>
            
            <div class="form-actions" style="margin-top: 20px;">
                <button type="button" class="btn btn-secondary" onclick="window.location.href='?page=overview'">Close</button>
            </div>
        </div>
    </div>
    
    <!-- Add Expense Modal -->
    <div id="addExpense" class="modal">
        <div class="modal-content">
            <div class="modal-header">Add Expense</div>
            <form method="POST">
                <input type="hidden" name="action" value="add_expense">
                <input type="hidden" name="budget_id" value="<?php echo $_GET['view']; ?>">
                
                <div class="form-group">
                    <label>Amount ($)</label>
                    <input type="number" step="0.01" name="amount" placeholder="25.50" required>
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" placeholder="What did you spend on?" required></textarea>
                </div>
                
                <div class="form-group">
                    <label>Date</label>
                    <input type="date" name="date" value="<?php echo date('Y-m-d'); ?>" required>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">Add Expense</button>
                    <button type="button" class="btn btn-secondary" onclick="closeModal('addExpense')">Cancel</button>
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
                window.location.href = '?page=overview';
            }
        }
        
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.classList.remove('active');
            }
        }
    </script>
</body>
</html>