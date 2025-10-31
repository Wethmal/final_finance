<?php
// dashboard.php - Modern Dashboard with Real Charts
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

// Get total budget stats
$stmt = $db->prepare('
    SELECT b.*, COALESCE(SUM(e.amount), 0) as spent
    FROM budgets b
    LEFT JOIN expenses e ON b.id = e.budget_id
    WHERE b.user_id = :uid
    GROUP BY b.id
');
$stmt->execute([':uid' => $user_id]);
$budgets = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalBudget = 0;
$totalSpent = 0;
foreach ($budgets as $budget) {
    $totalBudget += $budget['budget_amount'];
    $totalSpent += $budget['spent'];
}
$totalRemaining = $totalBudget - $totalSpent;
$usagePercentage = $totalBudget > 0 ? round(($totalSpent / $totalBudget) * 100) : 0;

// Get recent expenses
$stmt = $db->prepare('
    SELECT e.*, b.category, b.name as budget_name
    FROM expenses e
    JOIN budgets b ON e.budget_id = b.id
    WHERE b.user_id = :uid
    ORDER BY e.date DESC
    LIMIT 5
');
$stmt->execute([':uid' => $user_id]);
$recentExpenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get spending by category
$stmt = $db->prepare('
    SELECT b.category, SUM(e.amount) as total, b.budget_amount
    FROM budgets b
    LEFT JOIN expenses e ON b.id = e.budget_id
    WHERE b.user_id = :uid
    GROUP BY b.category
    ORDER BY total DESC
');
$stmt->execute([':uid' => $user_id]);
$categorySpending = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get monthly spending trend (last 6 months)
$stmt = $db->prepare("
    SELECT strftime('%Y-%m', e.date) as month, SUM(e.amount) as total
    FROM expenses e
    JOIN budgets b ON e.budget_id = b.id
    WHERE b.user_id = :uid
    AND e.date >= date('now', '-6 months')
    GROUP BY month
    ORDER BY month
");
$stmt->execute([':uid' => $user_id]);
$monthlyTrend = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get daily spending for current month
$stmt = $db->prepare("
    SELECT DATE(e.date) as day, SUM(e.amount) as total
    FROM expenses e
    JOIN budgets b ON e.budget_id = b.id
    WHERE b.user_id = :uid
    AND strftime('%Y-%m', e.date) = strftime('%Y-%m', 'now')
    GROUP BY day
    ORDER BY day
");
$stmt->execute([':uid' => $user_id]);
$dailySpending = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Statok</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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
        
        .welcome-title {
            font-size: 24px;
            font-weight: 700;
            color: #1a202c;
        }
        
        .welcome-subtitle {
            font-size: 14px;
            color: #64748b;
            margin-top: 4px;
        }
        
        .top-actions {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            cursor: pointer;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: white;
            padding: 24px;
            border-radius: 16px;
            border: 1px solid #e8eaf0;
        }
        
        .stat-label {
            font-size: 13px;
            color: #64748b;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #1a202c;
            margin-top: 8px;
        }
        
        .stat-change {
            font-size: 13px;
            margin-top: 8px;
            color: #10b981;
        }
        
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(12, 1fr);
            gap: 24px;
        }
        
        .widget {
            background: white;
            border-radius: 16px;
            padding: 24px;
            border: 1px solid #e8eaf0;
        }
        
        .widget-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .widget-title {
            font-size: 16px;
            font-weight: 600;
            color: #1a202c;
        }
        
        .chart-container {
            position: relative;
            height: 300px;
        }
        
        .col-8 { grid-column: span 8; }
        .col-4 { grid-column: span 4; }
        .col-6 { grid-column: span 6; }
        
        .payment-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .payment-item:last-child {
            border-bottom: none;
        }
        
        .payment-info {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        
        .payment-name {
            font-size: 14px;
            font-weight: 600;
            color: #1a202c;
        }
        
        .payment-date {
            font-size: 12px;
            color: #94a3b8;
        }
        
        .payment-amount {
            font-size: 15px;
            font-weight: 700;
            color: #1a202c;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #94a3b8;
        }
        
        .empty-icon {
            font-size: 48px;
            margin-bottom: 12px;
        }
        
        @media (max-width: 1200px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .col-8, .col-6, .col-4 { grid-column: span 12; }
        }
        
        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main-content { margin-left: 0; padding: 16px; }
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
                <a href="dashboard.php" class="nav-link active">
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
                <a href="#" class="nav-link">
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
            <div>
                <div class="welcome-title">Welcome back, <?php echo htmlspecialchars($username); ?>! 👋</div>
                <div class="welcome-subtitle">Here's your financial overview</div>
            </div>
            
            <div class="top-actions">
                <div class="user-avatar" title="<?php echo htmlspecialchars($email); ?>">
                    <?php echo strtoupper(substr($username, 0, 1)); ?>
                </div>
            </div>

            <!-- <form action="sync.php" method="POST">
    <button type="submit">🔄 Sync to Oracle</button>
</form> -->

        </div>

        <!-- Stats Cards -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Budget</div>
                <div class="stat-value">$<?php echo number_format($totalBudget, 2); ?></div>
                <div class="stat-change">↗ Monthly budget</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Total Spent</div>
                <div class="stat-value">$<?php echo number_format($totalSpent, 2); ?></div>
                <div class="stat-change"><?php echo $usagePercentage; ?>% of budget</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Remaining</div>
                <div class="stat-value">$<?php echo number_format($totalRemaining, 2); ?></div>
                <div class="stat-change" style="color: <?php echo $totalRemaining >= 0 ? '#10b981' : '#ef4444'; ?>">
                    <?php echo $totalRemaining >= 0 ? 'Under budget' : 'Over budget'; ?>
                </div>
            </div>
            
            <div class="stat-card">
                <div class="stat-label">Active Budgets</div>
                <div class="stat-value"><?php echo count($budgets); ?></div>
                <div class="stat-change">Budget categories</div>
            </div>
        </div>

        <!-- Charts Grid -->
        <div class="dashboard-grid">
            <!-- Spending Trend Chart -->
            <div class="widget col-8">
                <div class="widget-header">
                    <div class="widget-title">Spending Trend (Last 6 Months)</div>
                </div>
                <div class="chart-container">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>

            <!-- Category Breakdown -->
            <div class="widget col-4">
                <div class="widget-header">
                    <div class="widget-title">Spending by Category</div>
                </div>
                <div class="chart-container">
                    <canvas id="categoryChart"></canvas>
                </div>
            </div>

            <!-- Daily Spending Chart -->
            <div class="widget col-6">
                <div class="widget-header">
                    <div class="widget-title">Daily Spending (This Month)</div>
                </div>
                <div class="chart-container">
                    <canvas id="dailyChart"></canvas>
                </div>
            </div>

            <!-- Recent Expenses -->
            <div class="widget col-6">
                <div class="widget-header">
                    <div class="widget-title">Recent Expenses</div>
                </div>
                
                <?php if (count($recentExpenses) > 0): ?>
                    <?php foreach ($recentExpenses as $expense): ?>
                    <div class="payment-item">
                        <div class="payment-info">
                            <div class="payment-name"><?php echo htmlspecialchars($expense['description']); ?></div>
                            <div class="payment-date">
                                <?php echo htmlspecialchars($expense['budget_name']); ?> • 
                                <?php echo date('M d, Y', strtotime($expense['date'])); ?>
                            </div>
                        </div>
                        <div class="payment-amount">$<?php echo number_format($expense['amount'], 2); ?></div>
                    </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-icon">📝</div>
                        <h3>No expenses yet</h3>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <script>
        // Spending Trend Chart
        const trendCtx = document.getElementById('trendChart').getContext('2d');
        new Chart(trendCtx, {
            type: 'line',
            data: {
                labels: <?php echo json_encode(array_map(function($item) {
                    return date('M Y', strtotime($item['month'] . '-01'));
                }, $monthlyTrend)); ?>,
                datasets: [{
                    label: 'Spending',
                    data: <?php echo json_encode(array_map(function($item) {
                        return $item['total'];
                    }, $monthlyTrend)); ?>,
                    borderColor: '#667eea',
                    backgroundColor: 'rgba(102, 126, 234, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '$' + value;
                            }
                        }
                    }
                }
            }
        });

        // Category Chart
        const categoryCtx = document.getElementById('categoryChart').getContext('2d');
        new Chart(categoryCtx, {
            type: 'doughnut',
            data: {
                labels: <?php echo json_encode(array_map(function($item) {
                    return $item['category'];
                }, $categorySpending)); ?>,
                datasets: [{
                    data: <?php echo json_encode(array_map(function($item) {
                        return $item['total'];
                    }, $categorySpending)); ?>,
                    backgroundColor: [
                        '#e91e63',
                        '#2196f3',
                        '#9c27b0',
                        '#ff9800',
                        '#009688',
                        '#f44336',
                        '#4caf50',
                        '#757575'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // Daily Spending Chart
        const dailyCtx = document.getElementById('dailyChart').getContext('2d');
        new Chart(dailyCtx, {
            type: 'bar',
            data: {
                labels: <?php echo json_encode(array_map(function($item) {
                    return date('d', strtotime($item['day']));
                }, $dailySpending)); ?>,
                datasets: [{
                    label: 'Daily Spending',
                    data: <?php echo json_encode(array_map(function($item) {
                        return $item['total'];
                    }, $dailySpending)); ?>,
                    backgroundColor: '#764ba2'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) {
                                return '$' + value;
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
<!-- <?php
require_once "db/database.php";
$db = new Database();
$conn = $db->getConnection();

$lastSync = $conn->query("SELECT last_sync FROM sync_status ORDER BY id DESC LIMIT 1")->fetchColumn();
$now = new DateTime();
$last = new DateTime($lastSync);

$diff = $now->diff($last)->days;

if ($diff >= 1) {
    include "sync.php";  // auto run sync once per day
}
?> -->

</html>