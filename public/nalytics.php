<?php
// analytics.php - Financial Analytics and Reports
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$username = $_SESSION['username'];
$user_id = $_SESSION['user_id'];

try {
    $db = new PDO('sqlite:' .__DIR__ . '/../db/database.db');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Get selected period (default: last 12 months)
$period = $_GET['period'] ?? '12';
$startDate = date('Y-m-d', strtotime("-$period months"));
$endDate = date('Y-m-d');

// ==========================================
// REPORT 1: Monthly Expenditure Analysis
// Using: GROUP BY, ORDER BY, WHERE
// ==========================================
$monthlyExpenditure = $db->prepare("
    SELECT 
        strftime('%Y-%m', e.date) as month,
        strftime('%Y', e.date) as year,
        strftime('%m', e.date) as month_num,
        COUNT(e.id) as transaction_count,
        SUM(e.amount) as total_spent,
        AVG(e.amount) as avg_transaction,
        MIN(e.amount) as min_transaction,
        MAX(e.amount) as max_transaction
    FROM expenses e
    INNER JOIN budgets b ON e.budget_id = b.id
    WHERE b.user_id = :uid 
        AND e.date >= :start_date 
        AND e.date <= :end_date
    GROUP BY strftime('%Y-%m', e.date)
    ORDER BY month DESC
");
$monthlyExpenditure->execute([
    ':uid' => $user_id,
    ':start_date' => $startDate,
    ':end_date' => $endDate
]);
$monthlyData = $monthlyExpenditure->fetchAll(PDO::FETCH_ASSOC);

// Calculate month-over-month growth
$monthlyGrowth = [];
for ($i = 0; $i < count($monthlyData) - 1; $i++) {
    $current = $monthlyData[$i]['total_spent'];
    $previous = $monthlyData[$i + 1]['total_spent'];
    $growth = $previous > 0 ? (($current - $previous) / $previous) * 100 : 0;
    $monthlyGrowth[$monthlyData[$i]['month']] = $growth;
}

// ==========================================
// REPORT 2: Budget Adherence Tracking
// Using: CASE, GROUP BY, HAVING, WHERE
// ==========================================
$budgetAdherence = $db->prepare("
    SELECT 
        b.id,
        b.name,
        b.category,
        b.budget_amount,
        COALESCE(SUM(e.amount), 0) as spent,
        b.budget_amount - COALESCE(SUM(e.amount), 0) as remaining,
        CASE 
            WHEN COALESCE(SUM(e.amount), 0) = 0 THEN 0
            ELSE (COALESCE(SUM(e.amount), 0) / b.budget_amount) * 100 
        END as usage_percentage,
        CASE 
            WHEN COALESCE(SUM(e.amount), 0) > b.budget_amount THEN 'Over Budget'
            WHEN (COALESCE(SUM(e.amount), 0) / b.budget_amount) * 100 >= 90 THEN 'Critical'
            WHEN (COALESCE(SUM(e.amount), 0) / b.budget_amount) * 100 >= 75 THEN 'Warning'
            ELSE 'Healthy'
        END as status,
        COUNT(e.id) as expense_count
    FROM budgets b
    LEFT JOIN expenses e ON b.id = e.budget_id 
        AND e.date >= :start_date 
        AND e.date <= :end_date
    WHERE b.user_id = :uid
    GROUP BY b.id, b.name, b.category, b.budget_amount
    ORDER BY usage_percentage DESC
");
$budgetAdherence->execute([
    ':uid' => $user_id,
    ':start_date' => $startDate,
    ':end_date' => $endDate
]);
$adherenceData = $budgetAdherence->fetchAll(PDO::FETCH_ASSOC);

// Calculate overall budget health
$totalBudget = array_sum(array_column($adherenceData, 'budget_amount'));
$totalSpent = array_sum(array_column($adherenceData, 'spent'));
$overallAdherence = $totalBudget > 0 ? ($totalSpent / $totalBudget) * 100 : 0;

// ==========================================
// REPORT 3: Savings Goal Progress
// Using: CASE, GROUP BY, ORDER BY
// ==========================================
$savingsProgress = $db->prepare("
    SELECT 
        sg.id,
        sg.name,
        sg.category,
        sg.target_amount,
        sg.current_amount,
        sg.deadline,
        sg.target_amount - sg.current_amount as remaining,
        CASE 
            WHEN sg.target_amount = 0 THEN 0
            ELSE (sg.current_amount / sg.target_amount) * 100 
        END as progress_percentage,
        CASE 
            WHEN sg.current_amount >= sg.target_amount THEN 'Completed'
            WHEN sg.deadline IS NOT NULL AND date(sg.deadline) < date('now') THEN 'Overdue'
            WHEN sg.deadline IS NOT NULL AND julianday(sg.deadline) - julianday('now') <= 30 THEN 'Urgent'
            WHEN (sg.current_amount / sg.target_amount) * 100 >= 75 THEN 'On Track'
            WHEN (sg.current_amount / sg.target_amount) * 100 >= 50 THEN 'Moderate'
            ELSE 'Slow Progress'
        END as status,
        CASE 
            WHEN sg.deadline IS NOT NULL THEN julianday(sg.deadline) - julianday('now')
            ELSE NULL 
        END as days_remaining,
        (SELECT COUNT(*) FROM savings_transactions WHERE goal_id = sg.id) as transaction_count,
        (SELECT SUM(amount) FROM savings_transactions WHERE goal_id = sg.id AND transaction_type = 'deposit') as total_deposits,
        (SELECT SUM(amount) FROM savings_transactions WHERE goal_id = sg.id AND transaction_type = 'withdrawal') as total_withdrawals
    FROM savings_goals sg
    WHERE sg.user_id = :uid
    GROUP BY sg.id
    ORDER BY progress_percentage DESC, sg.deadline ASC
");
$savingsProgress->execute([':uid' => $user_id]);
$savingsData = $savingsProgress->fetchAll(PDO::FETCH_ASSOC);

// Calculate savings velocity (average monthly savings)
$savingsVelocity = [];
foreach ($savingsData as $goal) {
    $monthlyQuery = $db->prepare("
        SELECT 
            strftime('%Y-%m', date) as month,
            SUM(CASE WHEN transaction_type = 'deposit' THEN amount ELSE -amount END) as net_amount
        FROM savings_transactions
        WHERE goal_id = :gid
        GROUP BY strftime('%Y-%m', date)
        ORDER BY month DESC
        LIMIT 3
    ");
    $monthlyQuery->execute([':gid' => $goal['id']]);
    $monthlyContributions = $monthlyQuery->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($monthlyContributions) > 0) {
        $avgMonthly = array_sum(array_column($monthlyContributions, 'net_amount')) / count($monthlyContributions);
        $savingsVelocity[$goal['id']] = $avgMonthly;
    } else {
        $savingsVelocity[$goal['id']] = 0;
    }
}

// ==========================================
// REPORT 4: Category-wise Expense Distribution
// Using: GROUP BY, CASE, ORDER BY, HAVING
// ==========================================
$categoryDistribution = $db->prepare("
    SELECT 
        b.category,
        COUNT(DISTINCT b.id) as budget_count,
        COUNT(e.id) as transaction_count,
        SUM(e.amount) as total_spent,
        AVG(e.amount) as avg_expense,
        MIN(e.amount) as min_expense,
        MAX(e.amount) as max_expense,
        SUM(b.budget_amount) as total_budget,
        CASE 
            WHEN SUM(b.budget_amount) = 0 THEN 0
            ELSE (SUM(e.amount) / SUM(b.budget_amount)) * 100 
        END as budget_utilization,
        CASE 
            WHEN COUNT(e.id) = 0 THEN 0
            ELSE SUM(e.amount) 
        END as category_total
    FROM budgets b
    LEFT JOIN expenses e ON b.id = e.budget_id 
        AND e.date >= :start_date 
        AND e.date <= :end_date
    WHERE b.user_id = :uid
    GROUP BY b.category
    HAVING COUNT(e.id) > 0
    ORDER BY total_spent DESC
");
$categoryDistribution->execute([
    ':uid' => $user_id,
    ':start_date' => $startDate,
    ':end_date' => $endDate
]);
$categoryData = $categoryDistribution->fetchAll(PDO::FETCH_ASSOC);

// Calculate category percentages
$totalCategorySpending = array_sum(array_column($categoryData, 'total_spent'));
foreach ($categoryData as &$cat) {
    $cat['percentage'] = $totalCategorySpending > 0 ? ($cat['total_spent'] / $totalCategorySpending) * 100 : 0;
}

// ==========================================
// REPORT 5: Forecasted Savings Trends
// Using: Complex calculations, CASE, WHERE
// ==========================================

// Calculate historical monthly income vs expenses
$historicalTrends = $db->prepare("
    SELECT 
        strftime('%Y-%m', e.date) as month,
        SUM(e.amount) as monthly_expenses
    FROM expenses e
    INNER JOIN budgets b ON e.budget_id = b.id
    WHERE b.user_id = :uid 
        AND e.date >= :start_date
    GROUP BY strftime('%Y-%m', e.date)
    ORDER BY month ASC
");
$historicalTrends->execute([
    ':uid' => $user_id,
    ':start_date' => date('Y-m-d', strtotime('-6 months'))
]);
$trendData = $historicalTrends->fetchAll(PDO::FETCH_ASSOC);

// Calculate savings deposits trend
$savingsTrends = $db->prepare("
    SELECT 
        strftime('%Y-%m', st.date) as month,
        SUM(CASE WHEN st.transaction_type = 'deposit' THEN st.amount ELSE 0 END) as deposits,
        SUM(CASE WHEN st.transaction_type = 'withdrawal' THEN st.amount ELSE 0 END) as withdrawals,
        SUM(CASE WHEN st.transaction_type = 'deposit' THEN st.amount ELSE -st.amount END) as net_savings
    FROM savings_transactions st
    INNER JOIN savings_goals sg ON st.goal_id = sg.id
    WHERE sg.user_id = :uid 
        AND st.date >= :start_date
    GROUP BY strftime('%Y-%m', st.date)
    ORDER BY month ASC
");
$savingsTrends->execute([
    ':uid' => $user_id,
    ':start_date' => date('Y-m-d', strtotime('-6 months'))
]);
$savingsTrendData = $savingsTrends->fetchAll(PDO::FETCH_ASSOC);

// Calculate forecast using linear regression
function calculateForecast($data, $months = 3) {
    if (count($data) < 2) return [];
    
    $n = count($data);
    $sumX = 0;
    $sumY = 0;
    $sumXY = 0;
    $sumX2 = 0;
    
    foreach ($data as $i => $point) {
        $x = $i;
        $y = $point['value'];
        $sumX += $x;
        $sumY += $y;
        $sumXY += $x * $y;
        $sumX2 += $x * $x;
    }
    
    $slope = ($n * $sumXY - $sumX * $sumY) / ($n * $sumX2 - $sumX * $sumX);
    $intercept = ($sumY - $slope * $sumX) / $n;
    
    $forecast = [];
    for ($i = 0; $i < $months; $i++) {
        $futureX = $n + $i;
        $forecastValue = $slope * $futureX + $intercept;
        $forecast[] = max(0, $forecastValue); // Prevent negative forecasts
    }
    
    return $forecast;
}

// Prepare expense data for forecasting
$expenseForecasting = [];
foreach ($trendData as $trend) {
    $expenseForecasting[] = ['value' => $trend['monthly_expenses']];
}
$expenseForecast = calculateForecast($expenseForecasting, 3);

// Prepare savings data for forecasting
$savingsForecasting = [];
foreach ($savingsTrendData as $trend) {
    $savingsForecasting[] = ['value' => $trend['net_savings']];
}
$savingsForecast = calculateForecast($savingsForecasting, 3);

// Calculate average metrics
$avgMonthlyExpense = count($trendData) > 0 ? array_sum(array_column($trendData, 'monthly_expenses')) / count($trendData) : 0;
$avgMonthlySavings = count($savingsTrendData) > 0 ? array_sum(array_column($savingsTrendData, 'net_savings')) / count($savingsTrendData) : 0;

// Generate insights using PL/SQL-like logic
$insights = [];

// Insight 1: Budget adherence
if ($overallAdherence > 100) {
    $insights[] = [
        'type' => 'danger',
        'icon' => '⚠️',
        'title' => 'Budget Overspending Alert',
        'message' => 'You are spending ' . round($overallAdherence - 100, 1) . '% over your total budget. Consider reducing expenses.'
    ];
} elseif ($overallAdherence > 90) {
    $insights[] = [
        'type' => 'warning',
        'icon' => '⚡',
        'title' => 'Budget Usage High',
        'message' => 'You have used ' . round($overallAdherence, 1) . '% of your budget. Monitor your spending closely.'
    ];
} else {
    $insights[] = [
        'type' => 'success',
        'icon' => '✅',
        'title' => 'Budget On Track',
        'message' => 'Great job! You are at ' . round($overallAdherence, 1) . '% of your budget allocation.'
    ];
}

// Insight 2: Savings progress
$completedGoals = 0;
$totalGoals = count($savingsData);
foreach ($savingsData as $goal) {
    if ($goal['status'] === 'Completed') $completedGoals++;
}

if ($totalGoals > 0) {
    $completionRate = ($completedGoals / $totalGoals) * 100;
    if ($completionRate >= 50) {
        $insights[] = [
            'type' => 'success',
            'icon' => '🎯',
            'title' => 'Savings Champion',
            'message' => "You've completed $completedGoals out of $totalGoals savings goals ($completionRate%)!"
        ];
    }
}

// Insight 3: Top spending category
if (count($categoryData) > 0) {
    $topCategory = $categoryData[0];
    $insights[] = [
        'type' => 'info',
        'icon' => '📊',
        'title' => 'Top Spending Category',
        'message' => $topCategory['category'] . ' accounts for ' . round($topCategory['percentage'], 1) . '% of your spending ($' . number_format($topCategory['total_spent'], 2) . ')'
    ];
}

// Insight 4: Forecast prediction
if (count($expenseForecast) > 0 && count($savingsForecast) > 0) {
    $projectedExpense = $expenseForecast[0];
    $projectedSavings = $savingsForecast[0];
    $netProjection = $projectedSavings - $projectedExpense;
    
    if ($netProjection > 0) {
        $insights[] = [
            'type' => 'success',
            'icon' => '📈',
            'title' => 'Positive Forecast',
            'message' => 'Based on trends, you could save $' . number_format($netProjection, 2) . ' next month!'
        ];
    } else {
        $insights[] = [
            'type' => 'warning',
            'icon' => '📉',
            'title' => 'Forecast Alert',
            'message' => 'Projected expenses may exceed savings by $' . number_format(abs($netProjection), 2) . ' next month.'
        ];
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics - Statok</title>
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
        
        .top-bar-actions {
            display: flex;
            gap: 12px;
            align-items: center;
        }
        
        .btn-print {
            padding: 10px 20px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        
        .btn-print:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        .period-selector {
            display: flex;
            gap: 8px;
            background: white;
            padding: 4px;
            border-radius: 8px;
            border: 1px solid #e8eaf0;
        }
        
        .period-btn {
            padding: 8px 16px;
            border: none;
            background: transparent;
            color: #64748b;
            font-size: 14px;
            font-weight: 600;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .period-btn.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .insights-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }
        
        .insight-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            border-left: 4px solid;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        }
        
        .insight-card.success { border-color: #10b981; }
        .insight-card.warning { border-color: #f59e0b; }
        .insight-card.danger { border-color: #ef4444; }
        .insight-card.info { border-color: #3b82f6; }
        
        .insight-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 8px;
        }
        
        .insight-icon {
            font-size: 24px;
        }
        
        .insight-title {
            font-size: 15px;
            font-weight: 700;
            color: #1a202c;
        }
        
        .insight-message {
            font-size: 13px;
            color: #64748b;
            line-height: 1.5;
        }
        
        .report-section {
            background: white;
            border-radius: 16px;
            padding: 28px;
            margin-bottom: 24px;
            border: 1px solid #e8eaf0;
        }
        
        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        
        .report-title {
            font-size: 20px;
            font-weight: 700;
            color: #1a202c;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .report-icon {
            font-size: 24px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        
        .stat-box {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.05) 0%, rgba(118, 75, 162, 0.05) 100%);
            padding: 16px;
            border-radius: 12px;
            border: 1px solid rgba(102, 126, 234, 0.1);
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
        
        .stat-change {
            font-size: 12px;
            margin-top: 4px;
            font-weight: 600;
        }
        
        .stat-change.positive { color: #10b981; }
        .stat-change.negative { color: #ef4444; }
        
        table {
            width: 100%;
            border-collapse: collapse;
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
            padding: 14px 12px;
            border-bottom: 1px solid #f1f5f9;
            font-size: 14px;
        }
        
        tr:hover {
            background: #f8f9fc;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-healthy { background: #d1fae5; color: #065f46; }
        .status-warning { background: #fef3c7; color: #92400e; }
        .status-critical { background: #fee2e2; color: #991b1b; }
        .status-over { background: #fce7f3; color: #9f1239; }
        .status-completed { background: #ddd6fe; color: #5b21b6; }
        .status-ontrack { background: #dbeafe; color: #1e40af; }
        .status-moderate { background: #fef3c7; color: #92400e; }
        .status-slow { background: #fee2e2; color: #991b1b; }
        .status-urgent { background: #fed7aa; color: #9a3412; }
        .status-overdue { background: #fecaca; color: #991b1b; }
        
        .progress-bar-container {
            width: 100%;
            height: 8px;
            background: #e8eaf0;
            border-radius: 4px;
            overflow: hidden;
            margin-top: 4px;
        }
        
        .progress-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            border-radius: 4px;
            transition: width 0.3s;
        }
        
        .progress-bar-fill.over {
            background: linear-gradient(90deg, #ef4444 0%, #dc2626 100%);
        }
        
        .progress-bar-fill.complete {
            background: linear-gradient(90deg, #10b981 0%, #059669 100%);
        }
        
        .chart-container {
            margin-top: 20px;
            padding: 16px;
            background: #f8f9fc;
            border-radius: 12px;
        }
        
        .category-bar {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 16px;
        }
        
        .category-label {
            min-width: 140px;
            font-size: 13px;
            font-weight: 600;
            color: #1a202c;
        }
        
        .category-progress {
            flex: 1;
            height: 32px;
            background: #e8eaf0;
            border-radius: 8px;
            position: relative;
            overflow: hidden;
        }
        
        .category-progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding-right: 12px;
            color: white;
            font-size: 12px;
            font-weight: 700;
            transition: width 0.5s ease;
        }
        
        .category-amount {
            min-width: 100px;
            text-align: right;
            font-size: 14px;
            font-weight: 700;
            color: #1a202c;
        }
        
        .forecast-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
            margin-top: 20px;
        }
        
        .forecast-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
        }
        
        .forecast-month {
            font-size: 13px;
            opacity: 0.9;
            margin-bottom: 8px;
            font-weight: 600;
        }
        
        .forecast-amount {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .forecast-label {
            font-size: 12px;
            opacity: 0.8;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #94a3b8;
        }
        
        .empty-icon {
            font-size: 48px;
            margin-bottom: 16px;
        }
        
        @media (max-width: 768px) {
            .sidebar { display: none; }
            .main-content { margin-left: 0; padding: 16px; }
            .stats-grid { grid-template-columns: 1fr; }
            .forecast-grid { grid-template-columns: 1fr; }
        }
        
        /* Print Styles */
        @media print {
            body {
                background: white;
                display: block;
            }
            
            .sidebar,
            .btn-print,
            .period-selector,
            .nav-link,
            .fab {
                display: none !important;
            }
            
            .main-content {
                margin-left: 0;
                padding: 0;
                max-width: 100%;
            }
            
            .page-title {
                font-size: 24px;
                margin-bottom: 20px;
                color: #000;
            }
            
            .report-section {
                page-break-inside: avoid;
                border: 1px solid #ddd;
                padding: 20px;
                margin-bottom: 20px;
                background: white;
            }
            
            .report-title {
                font-size: 18px;
                color: #000;
                border-bottom: 2px solid #000;
                padding-bottom: 10px;
                margin-bottom: 15px;
            }
            
            .insights-grid {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
                page-break-inside: avoid;
            }
            
            .insight-card {
                border: 1px solid #ddd;
                padding: 15px;
                page-break-inside: avoid;
            }
            
            .stats-grid {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 10px;
                page-break-inside: avoid;
            }
            
            .stat-box {
                border: 1px solid #ddd;
                background: #f9f9f9;
                padding: 12px;
            }
            
            table {
                page-break-inside: avoid;
                border: 1px solid #ddd;
            }
            
            th {
                background: #f0f0f0 !important;
                color: #000;
                border: 1px solid #ddd;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            td {
                border: 1px solid #ddd;
                color: #000;
            }
            
            .progress-bar-container,
            .progress-bar-fill {
                border: 1px solid #ddd;
                background: white;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .progress-bar-fill {
                background: #667eea !important;
            }
            
            .status-badge {
                border: 1px solid #000;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .chart-container {
                page-break-inside: avoid;
            }
            
            .category-bar {
                page-break-inside: avoid;
            }
            
            .category-progress {
                border: 1px solid #ddd;
            }
            
            .category-progress-fill {
                background: #667eea !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .forecast-grid {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 10px;
                page-break-inside: avoid;
            }
            
            .forecast-card {
                background: #667eea !important;
                color: white !important;
                border: 2px solid #667eea;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .print-header {
                display: block !important;
                text-align: center;
                margin-bottom: 30px;
                padding-bottom: 20px;
                border-bottom: 3px solid #667eea;
            }
            
            .print-logo {
                font-size: 32px;
                font-weight: 700;
                color: #667eea;
                margin-bottom: 10px;
            }
            
            .print-date {
                font-size: 14px;
                color: #666;
            }
            
            .print-footer {
                display: block !important;
                text-align: center;
                margin-top: 30px;
                padding-top: 20px;
                border-top: 2px solid #ddd;
                font-size: 12px;
                color: #666;
            }
            
            /* Force page breaks */
            .report-section:nth-child(3) {
                page-break-before: always;
            }
            
            .report-section:nth-child(5) {
                page-break-before: always;
            }
        }
    </style>
</head>
<body>
    <!-- Print Header (hidden on screen) -->
    <div class="print-header" style="display: none;">
        <div class="print-logo">💰 Statok Financial Analytics Report</div>
        <div class="print-date">
            Generated on <?php echo date('F d, Y'); ?> | 
            Period: <?php echo date('M d, Y', strtotime($startDate)); ?> - <?php echo date('M d, Y', strtotime($endDate)); ?>
        </div>
        <div class="print-date">User: <?php echo htmlspecialchars($username); ?></div>
    </div>
    
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
                <a href="analytics.php" class="nav-link active">
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
            <div class="page-title">📈 Financial Analytics</div>
            <div class="top-bar-actions">
                <button class="btn-print" onclick="window.print()">
                    🖨️ Print Report
                </button>
                <div class="period-selector">
                    <button class="period-btn <?php echo $period == '3' ? 'active' : ''; ?>" onclick="window.location.href='?period=3'">3 Months</button>
                    <button class="period-btn <?php echo $period == '6' ? 'active' : ''; ?>" onclick="window.location.href='?period=6'">6 Months</button>
                    <button class="period-btn <?php echo $period == '12' ? 'active' : ''; ?>" onclick="window.location.href='?period=12'">12 Months</button>
                </div>
            </div>
        </div>

        <!-- AI-Generated Insights -->
        <?php if (count($insights) > 0): ?>
        <div class="insights-grid">
            <?php foreach ($insights as $insight): ?>
            <div class="insight-card <?php echo $insight['type']; ?>">
                <div class="insight-header">
                    <span class="insight-icon"><?php echo $insight['icon']; ?></span>
                    <span class="insight-title"><?php echo $insight['title']; ?></span>
                </div>
                <div class="insight-message"><?php echo $insight['message']; ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- REPORT 1: Monthly Expenditure Analysis -->
        <div class="report-section">
            <div class="report-header">
                <div class="report-title">
                    <span class="report-icon">📊</span>
                    Monthly Expenditure Analysis
                </div>
            </div>

            <?php if (count($monthlyData) > 0): ?>
            <div class="stats-grid">
                <div class="stat-box">
                    <div class="stat-label">Average Monthly</div>
                    <div class="stat-value">$<?php echo number_format($avgMonthlyExpense, 2); ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Highest Month</div>
                    <div class="stat-value">$<?php echo number_format(max(array_column($monthlyData, 'total_spent')), 2); ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Lowest Month</div>
                    <div class="stat-value">$<?php echo number_format(min(array_column($monthlyData, 'total_spent')), 2); ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Total Transactions</div>
                    <div class="stat-value"><?php echo array_sum(array_column($monthlyData, 'transaction_count')); ?></div>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Transactions</th>
                        <th>Total Spent</th>
                        <th>Average</th>
                        <th>Min/Max</th>
                        <th>Change</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($monthlyData as $month): 
                        $monthName = date('F Y', strtotime($month['month'] . '-01'));
                        $growth = $monthlyGrowth[$month['month']] ?? 0;
                    ?>
                    <tr>
                        <td style="font-weight: 600;"><?php echo $monthName; ?></td>
                        <td><?php echo $month['transaction_count']; ?></td>
                        <td style="font-weight: 700; color: #1a202c;">$<?php echo number_format($month['total_spent'], 2); ?></td>
                        <td>$<?php echo number_format($month['avg_transaction'], 2); ?></td>
                        <td style="font-size: 12px; color: #64748b;">
                            $<?php echo number_format($month['min_transaction'], 2); ?> / 
                            $<?php echo number_format($month['max_transaction'], 2); ?>
                        </td>
                        <td>
                            <span class="stat-change <?php echo $growth >= 0 ? 'positive' : 'negative'; ?>">
                                <?php echo $growth >= 0 ? '▲' : '▼'; ?> <?php echo number_format(abs($growth), 1); ?>%
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon">📊</div>
                <h3>No expenditure data available</h3>
            </div>
            <?php endif; ?>
        </div>

        <!-- REPORT 2: Budget Adherence Tracking -->
        <div class="report-section">
            <div class="report-header">
                <div class="report-title">
                    <span class="report-icon">🎯</span>
                    Budget Adherence Tracking
                </div>
            </div>

            <?php if (count($adherenceData) > 0): ?>
            <div class="stats-grid">
                <div class="stat-box">
                    <div class="stat-label">Total Budget</div>
                    <div class="stat-value">$<?php echo number_format($totalBudget, 2); ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Total Spent</div>
                    <div class="stat-value">$<?php echo number_format($totalSpent, 2); ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Overall Adherence</div>
                    <div class="stat-value"><?php echo round($overallAdherence); ?>%</div>
                    <div class="progress-bar-container">
                        <div class="progress-bar-fill <?php echo $overallAdherence > 100 ? 'over' : ''; ?>" 
                             style="width: <?php echo min($overallAdherence, 100); ?>%"></div>
                    </div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Remaining Budget</div>
                    <div class="stat-value">$<?php echo number_format($totalBudget - $totalSpent, 2); ?></div>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Budget Name</th>
                        <th>Category</th>
                        <th>Budget Amount</th>
                        <th>Spent</th>
                        <th>Remaining</th>
                        <th>Usage</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($adherenceData as $budget): ?>
                    <tr>
                        <td style="font-weight: 600;"><?php echo htmlspecialchars($budget['name']); ?></td>
                        <td><?php echo htmlspecialchars($budget['category']); ?></td>
                        <td>$<?php echo number_format($budget['budget_amount'], 2); ?></td>
                        <td style="font-weight: 700;">$<?php echo number_format($budget['spent'], 2); ?></td>
                        <td style="color: <?php echo $budget['remaining'] < 0 ? '#ef4444' : '#10b981'; ?>;">
                            $<?php echo number_format($budget['remaining'], 2); ?>
                        </td>
                        <td>
                            <?php echo round($budget['usage_percentage']); ?>%
                            <div class="progress-bar-container">
                                <div class="progress-bar-fill <?php echo $budget['usage_percentage'] > 100 ? 'over' : ''; ?>" 
                                     style="width: <?php echo min($budget['usage_percentage'], 100); ?>%"></div>
                            </div>
                        </td>
                        <td>
                            <span class="status-badge status-<?php echo strtolower(str_replace(' ', '', $budget['status'])); ?>">
                                <?php echo $budget['status']; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon">🎯</div>
                <h3>No budget data available</h3>
            </div>
            <?php endif; ?>
        </div>

        <!-- REPORT 3: Savings Goal Progress -->
        <div class="report-section">
            <div class="report-header">
                <div class="report-title">
                    <span class="report-icon">💎</span>
                    Savings Goal Progress
                </div>
            </div>

            <?php if (count($savingsData) > 0): 
                $totalTargetSavings = array_sum(array_column($savingsData, 'target_amount'));
                $totalCurrentSavings = array_sum(array_column($savingsData, 'current_amount'));
                $overallSavingsProgress = $totalTargetSavings > 0 ? ($totalCurrentSavings / $totalTargetSavings) * 100 : 0;
            ?>
            <div class="stats-grid">
                <div class="stat-box">
                    <div class="stat-label">Total Target</div>
                    <div class="stat-value">$<?php echo number_format($totalTargetSavings, 2); ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Total Saved</div>
                    <div class="stat-value">$<?php echo number_format($totalCurrentSavings, 2); ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Overall Progress</div>
                    <div class="stat-value"><?php echo round($overallSavingsProgress); ?>%</div>
                    <div class="progress-bar-container">
                        <div class="progress-bar-fill <?php echo $overallSavingsProgress >= 100 ? 'complete' : ''; ?>" 
                             style="width: <?php echo min($overallSavingsProgress, 100); ?>%"></div>
                    </div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Avg Monthly Savings</div>
                    <div class="stat-value">$<?php echo number_format($avgMonthlySavings, 2); ?></div>
                </div>
            </div>

            <table>
                <thead>
                    <tr>
                        <th>Goal Name</th>
                        <th>Category</th>
                        <th>Current / Target</th>
                        <th>Progress</th>
                        <th>Velocity</th>
                        <th>Deadline</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($savingsData as $goal): 
                        $velocity = $savingsVelocity[$goal['id']];
                        $monthsToGoal = $velocity > 0 ? ceil($goal['remaining'] / $velocity) : 0;
                    ?>
                    <tr>
                        <td style="font-weight: 600;"><?php echo htmlspecialchars($goal['name']); ?></td>
                        <td><?php echo htmlspecialchars($goal['category']); ?></td>
                        <td>
                            <div style="font-weight: 700;">$<?php echo number_format($goal['current_amount'], 2); ?></div>
                            <div style="font-size: 12px; color: #64748b;">of $<?php echo number_format($goal['target_amount'], 2); ?></div>
                        </td>
                        <td>
                            <?php echo round($goal['progress_percentage']); ?>%
                            <div class="progress-bar-container">
                                <div class="progress-bar-fill <?php echo $goal['progress_percentage'] >= 100 ? 'complete' : ''; ?>" 
                                     style="width: <?php echo min($goal['progress_percentage'], 100); ?>%"></div>
                            </div>
                        </td>
                        <td style="color: #10b981; font-weight: 600;">
                            <?php if ($velocity > 0): ?>
                                +$<?php echo number_format($velocity, 0); ?>/mo
                                <div style="font-size: 11px; color: #64748b;">~<?php echo $monthsToGoal; ?> months</div>
                            <?php else: ?>
                                <span style="color: #94a3b8;">No data</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($goal['deadline']): ?>
                                <?php echo date('M d, Y', strtotime($goal['deadline'])); ?>
                                <?php if ($goal['days_remaining'] !== null): ?>
                                    <div style="font-size: 11px; color: <?php echo $goal['days_remaining'] < 0 ? '#ef4444' : '#64748b'; ?>;">
                                        <?php echo abs($goal['days_remaining']); ?> days <?php echo $goal['days_remaining'] < 0 ? 'overdue' : 'left'; ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color: #94a3b8;">No deadline</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="status-badge status-<?php echo strtolower(str_replace(' ', '', $goal['status'])); ?>">
                                <?php echo $goal['status']; ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon">💎</div>
                <h3>No savings goals available</h3>
            </div>
            <?php endif; ?>
        </div>

        <!-- REPORT 4: Category-wise Expense Distribution -->
        <div class="report-section">
            <div class="report-header">
                <div class="report-title">
                    <span class="report-icon">🎨</span>
                    Category-wise Expense Distribution
                </div>
            </div>

            <?php if (count($categoryData) > 0): ?>
            <div class="stats-grid">
                <div class="stat-box">
                    <div class="stat-label">Total Categories</div>
                    <div class="stat-value"><?php echo count($categoryData); ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Total Spending</div>
                    <div class="stat-value">$<?php echo number_format($totalCategorySpending, 2); ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Top Category</div>
                    <div class="stat-value" style="font-size: 16px;">
                        <?php echo $categoryData[0]['category']; ?>
                        <div style="font-size: 20px; margin-top: 4px;">$<?php echo number_format($categoryData[0]['total_spent'], 2); ?></div>
                    </div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Avg per Category</div>
                    <div class="stat-value">$<?php echo number_format($totalCategorySpending / count($categoryData), 2); ?></div>
                </div>
            </div>

            <div class="chart-container">
                <?php foreach ($categoryData as $cat): ?>
                <div class="category-bar">
                    <div class="category-label"><?php echo htmlspecialchars($cat['category']); ?></div>
                    <div class="category-progress">
                        <div class="category-progress-fill" style="width: <?php echo $cat['percentage']; ?>%">
                            <?php if ($cat['percentage'] > 15): ?>
                                <?php echo round($cat['percentage'], 1); ?>%
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="category-amount">$<?php echo number_format($cat['total_spent'], 2); ?></div>
                </div>
                <?php endforeach; ?>
            </div>

            <table style="margin-top: 24px;">
                <thead>
                    <tr>
                        <th>Category</th>
                        <th>Budgets</th>
                        <th>Transactions</th>
                        <th>Total Spent</th>
                        <th>Average</th>
                        <th>Budget Used</th>
                        <th>Share</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categoryData as $cat): ?>
                    <tr>
                        <td style="font-weight: 600;"><?php echo htmlspecialchars($cat['category']); ?></td>
                        <td><?php echo $cat['budget_count']; ?></td>
                        <td><?php echo $cat['transaction_count']; ?></td>
                        <td style="font-weight: 700; color: #1a202c;">$<?php echo number_format($cat['total_spent'], 2); ?></td>
                        <td>$<?php echo number_format($cat['avg_expense'], 2); ?></td>
                        <td><?php echo round($cat['budget_utilization']); ?>%</td>
                        <td style="font-weight: 600; color: #667eea;"><?php echo round($cat['percentage'], 1); ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon">🎨</div>
                <h3>No category data available</h3>
            </div>
            <?php endif; ?>
        </div>

        <!-- REPORT 5: Forecasted Savings Trends -->
        <div class="report-section">
            <div class="report-header">
                <div class="report-title">
                    <span class="report-icon">🔮</span>
                    Forecasted Savings Trends
                </div>
            </div>

            <?php if (count($trendData) > 0 || count($savingsTrendData) > 0): ?>
            <div class="stats-grid">
                <div class="stat-box">
                    <div class="stat-label">Avg Monthly Expense</div>
                    <div class="stat-value">$<?php echo number_format($avgMonthlyExpense, 2); ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Avg Monthly Savings</div>
                    <div class="stat-value">$<?php echo number_format($avgMonthlySavings, 2); ?></div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Net Average</div>
                    <div class="stat-value" style="color: <?php echo ($avgMonthlySavings - $avgMonthlyExpense) > 0 ? '#10b981' : '#ef4444'; ?>">
                        $<?php echo number_format($avgMonthlySavings - $avgMonthlyExpense, 2); ?>
                    </div>
                </div>
                <div class="stat-box">
                    <div class="stat-label">Forecast Accuracy</div>
                    <div class="stat-value" style="font-size: 20px;">
                        <?php echo count($trendData) >= 3 ? 'High' : (count($trendData) >= 2 ? 'Medium' : 'Low'); ?>
                    </div>
                </div>
            </div>

            <h3 style="margin: 24px 0 16px; font-size: 16px; color: #1a202c;">📊 3-Month Forecast</h3>
            <div class="forecast-grid">
                <?php 
                $forecastMonths = [];
                for ($i = 1; $i <= 3; $i++) {
                    $forecastMonths[] = date('F Y', strtotime("+$i month"));
                }
                
                for ($i = 0; $i < 3; $i++): 
                    $expenseForecastValue = $expenseForecast[$i] ?? 0;
                    $savingsForecastValue = $savingsForecast[$i] ?? 0;
                    $netForecast = $savingsForecastValue - $expenseForecastValue;
                ?>
                <div class="forecast-card">
                    <div class="forecast-month"><?php echo $forecastMonths[$i]; ?></div>
                    <div class="forecast-amount">$<?php echo number_format($netForecast, 0); ?></div>
                    <div class="forecast-label">Projected Net</div>
                    <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid rgba(255,255,255,0.2);">
                        <div style="font-size: 12px; margin-bottom: 4px;">
                            💰 Savings: $<?php echo number_format($savingsForecastValue, 0); ?>
                        </div>
                        <div style="font-size: 12px;">
                            💸 Expenses: $<?php echo number_format($expenseForecastValue, 0); ?>
                        </div>
                    </div>
                </div>
                <?php endfor; ?>
            </div>

            <h3 style="margin: 24px 0 16px; font-size: 16px; color: #1a202c;">📈 Historical Trends (Last 6 Months)</h3>
            <table>
                <thead>
                    <tr>
                        <th>Month</th>
                        <th>Expenses</th>
                        <th>Savings Deposits</th>
                        <th>Savings Withdrawals</th>
                        <th>Net Savings</th>
                        <th>Net Position</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    // Merge expense and savings data
                    $mergedData = [];
                    foreach ($trendData as $trend) {
                        $mergedData[$trend['month']] = [
                            'expenses' => $trend['monthly_expenses'],
                            'deposits' => 0,
                            'withdrawals' => 0,
                            'net_savings' => 0
                        ];
                    }
                    foreach ($savingsTrendData as $trend) {
                        if (!isset($mergedData[$trend['month']])) {
                            $mergedData[$trend['month']] = ['expenses' => 0];
                        }
                        $mergedData[$trend['month']]['deposits'] = $trend['deposits'];
                        $mergedData[$trend['month']]['withdrawals'] = $trend['withdrawals'];
                        $mergedData[$trend['month']]['net_savings'] = $trend['net_savings'];
                    }
                    
                    krsort($mergedData);
                    foreach ($mergedData as $month => $data): 
                        $netPosition = $data['net_savings'] - $data['expenses'];
                        $monthName = date('F Y', strtotime($month . '-01'));
                    ?>
                    <tr>
                        <td style="font-weight: 600;"><?php echo $monthName; ?></td>
                        <td style="color: #ef4444; font-weight: 600;">$<?php echo number_format($data['expenses'], 2); ?></td>
                        <td style="color: #10b981;">$<?php echo number_format($data['deposits'], 2); ?></td>
                        <td style="color: #f59e0b;">$<?php echo number_format($data['withdrawals'], 2); ?></td>
                        <td style="font-weight: 700;">$<?php echo number_format($data['net_savings'], 2); ?></td>
                        <td style="font-weight: 700; color: <?php echo $netPosition >= 0 ? '#10b981' : '#ef4444'; ?>">
                            <?php echo $netPosition >= 0 ? '+' : ''; ?>$<?php echo number_format($netPosition, 2); ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-icon">🔮</div>
                <h3>Insufficient data for forecasting</h3>
                <p style="margin-top: 8px;">Add more expenses and savings transactions to generate accurate forecasts</p>
            </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- Print Footer (hidden on screen) -->
    <div class="print-footer" style="display: none;">
        <p>This is a confidential financial report generated by Statok</p>
        <p>© <?php echo date('Y'); ?> Statok - Personal Finance Management System</p>
        <p style="margin-top: 10px;">Page printed on <?php echo date('F d, Y \a\t h:i A'); ?></p>
    </div>

    <script>
        // Animate progress bars on load
        window.addEventListener('load', function() {
            const progressBars = document.querySelectorAll('.category-progress-fill');
            progressBars.forEach(bar => {
                const width = bar.style.width;
                bar.style.width = '0%';
                setTimeout(() => {
                    bar.style.width = width;
                }, 100);
            });
        });
        
        // Print optimization
        window.addEventListener('beforeprint', function() {
            // Ensure all progress bars are visible before printing
            const progressBars = document.querySelectorAll('.category-progress-fill, .progress-bar-fill');
            progressBars.forEach(bar => {
                bar.style.transition = 'none';
            });
        });
        
        window.addEventListener('afterprint', function() {
            // Restore transitions after printing
            const progressBars = document.querySelectorAll('.category-progress-fill, .progress-bar-fill');
            progressBars.forEach(bar => {
                bar.style.transition = '';
            });
        });
    </script>
</body>
</html>