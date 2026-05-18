<?php
/**
 * Панель управления (Dashboard) системы PolesieMES
 * Обновленный дизайн - основные метрики и KPI
 * ОАО "Полесьеэлектромаш"
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

// Проверка авторизации
requireAuth();

// Перенаправление работников склада
if (isset($_SESSION['role']) && $_SESSION['role'] === 'warehouse_keeper' && $_SESSION['role'] !== 'admin' && $_SESSION['role'] !== 'director') {
    header('Location: ' . APP_URL . '/modules/warehouse/warehouse_dashboard.php');
    exit;
}

// Перенаправление директора
if (isset($_SESSION['role']) && $_SESSION['role'] === 'director') {
    header('Location: ' . APP_URL . '/modules/director/dashboard.php');
    exit;
}

$db = getDB();
$user = getCurrentUser();
$userFirstName = !empty($user['full_name']) ? explode(' ', $user['full_name'])[0] : 'Пользователь';

// ==========================================
// ОСНОВНАЯ СТАТИСТИКА ПО ОРГАНИЗАЦИИ
// ==========================================

// Заказы: всего, в работе, готовые, просроченные
$stmt = $db->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as new_orders,
    SUM(CASE WHEN status = 'in_production' THEN 1 ELSE 0 END) as in_production,
    SUM(CASE WHEN status = 'quality_check' THEN 1 ELSE 0 END) as qc_orders,
    SUM(CASE WHEN status = 'ready' THEN 1 ELSE 0 END) as ready_orders,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
    COALESCE(SUM(CASE WHEN status NOT IN ('completed', 'cancelled') THEN total_amount ELSE 0 END), 0) as active_value
FROM orders");
$ordersStats = $stmt->fetch();

// Просроченные заказы
$stmt = $db->query("SELECT COUNT(*) as count FROM orders WHERE status NOT IN ('completed', 'cancelled') AND delivery_date < NOW()");
$overdueOrders = $stmt->fetch()['count'] ?? 0;

// Выручка за месяц
$stmt = $db->query("SELECT COALESCE(SUM(total_amount), 0) as revenue FROM orders WHERE status = 'completed' AND MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())");
$monthlyRevenue = $stmt->fetch()['revenue'] ?? 0;

// Выполнено за сегодня
$stmt = $db->query("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as value FROM orders WHERE status = 'completed' AND DATE(updated_at) = CURDATE()");
$todayCompleted = $stmt->fetch();

// Производство: задания в работе, выполненные, эффективность
$stmt = $db->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
    SUM(CASE WHEN status = 'planned' THEN 1 ELSE 0 END) as planned,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN status = 'paused' THEN 1 ELSE 0 END) as paused
FROM production_tasks");
$productionStats = $stmt->fetch();

// Эффективность производства (за неделю)
$stmt = $db->query("SELECT ROUND(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) as efficiency FROM production_tasks WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
$weeklyEfficiency = $stmt->fetch()['efficiency'] ?? 0;

// Склад: материалы, низкий запас, отсутствующие
$stmt = $db->query("SELECT 
    COUNT(*) as total_materials,
    SUM(CASE WHEN current_stock < min_stock THEN 1 ELSE 0 END) as low_stock,
    SUM(CASE WHEN current_stock <= 0 THEN 1 ELSE 0 END) as out_of_stock
FROM items WHERE item_type = 'material'");
$warehouseStats = $stmt->fetch();

// Оборудование
$stmt = $db->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'operational' THEN 1 ELSE 0 END) as operational,
    SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance,
    SUM(CASE WHEN status = 'broken' THEN 1 ELSE 0 END) as broken
FROM items WHERE item_type = 'equipment'");
$equipmentStats = $stmt->fetch();

// Сотрудники онлайн
$stmt = $db->query("SELECT COUNT(DISTINCT user_id) as online FROM journal WHERE journal_type = 'activity' AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
$onlineUsers = $stmt->fetch()['online'] ?? 0;

// Всего сотрудников
$stmt = $db->query("SELECT COUNT(*) as total FROM staff");
$totalEmployees = $stmt->fetch()['total'] ?? 0;

// ==========================================
// ДАННЫЕ ДЛЯ ГРАФИКОВ
// ==========================================

// Заказы по статусам (для pie chart)
$ordersByStatus = [
    'new' => (int)($ordersStats['new_orders'] ?? 0),
    'in_production' => (int)($ordersStats['in_production'] ?? 0),
    'qc_orders' => (int)($ordersStats['qc_orders'] ?? 0),
    'ready' => (int)($ordersStats['ready_orders'] ?? 0),
    'completed' => (int)($ordersStats['completed_orders'] ?? 0)
];

// Заказы по месяцам (за последние 6 месяцев)
$stmt = $db->query("SELECT 
    DATE_FORMAT(created_at, '%Y-%m') as month,
    COUNT(*) as count,
    COALESCE(SUM(total_amount), 0) as revenue
FROM orders 
WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
GROUP BY DATE_FORMAT(created_at, '%Y-%m')
ORDER BY month ASC");
$monthlyOrders = $stmt->fetchAll();

// Производство по стадиям
$stmt = $db->query("SELECT stage_name, COUNT(*) as count FROM production_tasks WHERE status = 'in_progress' GROUP BY stage_name");
$tasksByStage = $stmt->fetchAll();

// ==========================================
// БЛОКИ С ПРОБЛЕМАМИ
// ==========================================

$alerts = [];

if ($overdueOrders > 0) {
    $alerts[] = ['type' => 'critical', 'module' => 'Заказы', 'title' => 'Просроченные заказы', 'count' => $overdueOrders, 'link' => APP_URL . '/modules/orders/index.php'];
}
if (($warehouseStats['out_of_stock'] ?? 0) > 0) {
    $alerts[] = ['type' => 'critical', 'module' => 'Склад', 'title' => 'Материалы отсутствуют', 'count' => $warehouseStats['out_of_stock'], 'link' => APP_URL . '/modules/warehouse/warehouse_dashboard.php'];
}
if (($equipmentStats['broken'] ?? 0) > 0) {
    $alerts[] = ['type' => 'critical', 'module' => 'Оборудование', 'title' => 'Неисправное оборудование', 'count' => $equipmentStats['broken'], 'link' => APP_URL . '/modules/equipment/index.php'];
}
if (($productionStats['paused'] ?? 0) > 0) {
    $alerts[] = ['type' => 'warning', 'module' => 'Производство', 'title' => 'Приостановленные задания', 'count' => $productionStats['paused'], 'link' => APP_URL . '/modules/production/index.php'];
}
if (($warehouseStats['low_stock'] ?? 0) > 0) {
    $alerts[] = ['type' => 'warning', 'module' => 'Склад', 'title' => 'Низкий запас материалов', 'count' => $warehouseStats['low_stock'], 'link' => APP_URL . '/modules/warehouse/warehouse_dashboard.php'];
}
if (($equipmentStats['maintenance'] ?? 0) > 0) {
    $alerts[] = ['type' => 'info', 'module' => 'Оборудование', 'title' => 'На обслуживании', 'count' => $equipmentStats['maintenance'], 'link' => APP_URL . '/modules/equipment/index.php'];
}

// Последние заказы
$stmt = $db->query("SELECT o.*, p.name as customer_name FROM orders o LEFT JOIN partners p ON o.customer_id = p.id ORDER BY o.created_at DESC LIMIT 5");
$recentOrders = $stmt->fetchAll();

$pageTitle = 'Панель управления | ' . APP_NAME;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --primary: #FF6B6B;
            --primary-dark: #FF5252;
            --secondary: #FF8E53;
            --success: #10B981;
            --warning: #F59E0B;
            --danger: #EF4444;
            --info: #3B82F6;
            --bg-dark: #0a0a0f;
            --bg-card: rgba(20, 20, 30, 0.7);
            --border: rgba(255, 255, 255, 0.1);
            --text-primary: #ffffff;
            --text-secondary: rgba(255, 255, 255, 0.7);
            --glass-bg: rgba(255, 255, 255, 0.03);
            --glass-border: rgba(255, 255, 255, 0.08);
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Outfit', sans-serif;
            background: linear-gradient(180deg, #0a0a0f 0%, #12121a 100%);
            min-height: 100vh;
            color: var(--text-primary);
        }
        
        .dashboard-container {
            padding: 2rem;
            max-width: 1600px;
            margin: 0 auto;
        }
        
        /* Header */
        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1.5rem;
            border-bottom: 1px solid var(--border);
        }
        
        .welcome-text h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        
        .welcome-text p {
            color: var(--text-secondary);
            font-size: 0.95rem;
        }
        
        .header-actions {
            display: flex;
            gap: 1rem;
            align-items: center;
        }
        
        .btn-refresh {
            background: var(--glass-bg);
            border: 1px solid var(--border);
            color: var(--text-primary);
            padding: 0.75rem 1.25rem;
            border-radius: 12px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .btn-refresh:hover {
            background: rgba(255, 255, 255, 0.08);
            border-color: var(--primary);
        }
        
        /* KPI Cards */
        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .kpi-card {
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 1.5rem;
            transition: all 0.3s ease;
        }
        
        .kpi-card:hover {
            transform: translateY(-4px);
            border-color: var(--primary);
            box-shadow: 0 8px 32px rgba(255, 107, 107, 0.15);
        }
        
        .kpi-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .kpi-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }
        
        .kpi-icon.orders { background: rgba(59, 130, 246, 0.2); color: #3B82F6; }
        .kpi-icon.revenue { background: rgba(16, 185, 129, 0.2); color: #10B981; }
        .kpi-icon.production { background: rgba(245, 158, 11, 0.2); color: #F59E0B; }
        .kpi-icon.warehouse { background: rgba(255, 142, 83, 0.2); color: #FF8E53; }
        .kpi-icon.equipment { background: rgba(239, 68, 68, 0.2); color: #EF4444; }
        .kpi-icon.employees { background: rgba(139, 92, 246, 0.2); color: #8B5CF6; }
        
        .kpi-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        
        .kpi-label {
            color: var(--text-secondary);
            font-size: 0.875rem;
        }
        
        .kpi-trend {
            display: flex;
            align-items: center;
            gap: 0.25rem;
            font-size: 0.8rem;
            margin-top: 0.5rem;
        }
        
        .kpi-trend.up { color: var(--success); }
        .kpi-trend.down { color: var(--danger); }
        
        /* Main Content Grid */
        .content-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        @media (max-width: 1200px) {
            .content-grid { grid-template-columns: 1fr; }
        }
        
        .card {
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 1.5rem;
        }
        
        .card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
        }
        
        .card-title {
            font-size: 1.125rem;
            font-weight: 600;
        }
        
        .card-link {
            color: var(--primary);
            text-decoration: none;
            font-size: 0.875rem;
            transition: all 0.3s ease;
        }
        
        .card-link:hover { color: var(--secondary); }
        
        /* Charts */
        .chart-container {
            position: relative;
            height: 300px;
        }
        
        /* Alerts */
        .alerts-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
        }
        
        .alert-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: var(--glass-bg);
            border-radius: 12px;
            border-left: 4px solid;
            transition: all 0.3s ease;
            text-decoration: none;
            color: inherit;
        }
        
        .alert-item:hover {
            background: rgba(255, 255, 255, 0.08);
            transform: translateX(4px);
        }
        
        .alert-item.critical { border-left-color: var(--danger); }
        .alert-item.warning { border-left-color: var(--warning); }
        .alert-item.info { border-left-color: var(--info); }
        
        .alert-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .alert-item.critical .alert-icon { background: rgba(239, 68, 68, 0.2); color: var(--danger); }
        .alert-item.warning .alert-icon { background: rgba(245, 158, 11, 0.2); color: var(--warning); }
        .alert-item.info .alert-icon { background: rgba(59, 130, 246, 0.2); color: var(--info); }
        
        .alert-content { flex: 1; }
        .alert-title { font-weight: 600; font-size: 0.95rem; margin-bottom: 0.25rem; }
        .alert-module { font-size: 0.75rem; color: var(--text-secondary); text-transform: uppercase; }
        
        .alert-count {
            background: var(--primary);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-weight: 600;
            font-size: 0.875rem;
        }
        
        /* Module Stats */
        .module-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
        }
        
        .module-stat {
            background: var(--glass-bg);
            padding: 1rem;
            border-radius: 12px;
            text-align: center;
        }
        
        .module-stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
        }
        
        .module-stat-label {
            font-size: 0.75rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
        }
        
        /* Recent Orders Table */
        .table-custom {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table-custom th {
            text-align: left;
            padding: 0.75rem 0.5rem;
            font-size: 0.75rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            border-bottom: 1px solid var(--border);
        }
        
        .table-custom td {
            padding: 1rem 0.5rem;
            border-bottom: 1px solid var(--border);
            font-size: 0.875rem;
        }
        
        .table-custom tr:last-child td { border-bottom: none; }
        
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .status-new { background: rgba(59, 130, 246, 0.2); color: #3B82F6; }
        .status-in_production { background: rgba(245, 158, 11, 0.2); color: #F59E0B; }
        .status-quality_check { background: rgba(139, 92, 246, 0.2); color: #8B5CF6; }
        .status-ready { background: rgba(16, 185, 129, 0.2); color: #10B981; }
        .status-completed { background: rgba(6, 182, 212, 0.2); color: #06B6D4; }
        
        /* No alerts state */
        .no-alerts {
            text-align: center;
            padding: 2rem;
            color: var(--text-secondary);
        }
        
        .no-alerts i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.3;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Header -->
        <header class="dashboard-header">
            <div class="welcome-text">
                <h1>Добрый день, <?= e($userFirstName) ?>!</h1>
                <p>Обзор ключевых показателей предприятия</p>
            </div>
            <div class="header-actions">
                <button class="btn-refresh" onclick="location.reload()">
                    <i class="fas fa-sync-alt"></i> Обновить
                </button>
                <a href="<?= APP_URL ?>/index.php" class="card-link">
                    <i class="fas fa-sign-out-alt"></i> Выход
                </a>
            </div>
        </header>

        <!-- KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-header">
                    <div>
                        <div class="kpi-value"><?= (int)$ordersStats['total'] ?></div>
                        <div class="kpi-label">Всего заказов</div>
                    </div>
                    <div class="kpi-icon orders"><i class="fas fa-shopping-cart"></i></div>
                </div>
                <div class="kpi-trend up">
                    <i class="fas fa-arrow-up"></i>
                    <span><?= (int)$ordersStats['in_production'] ?> в работе</span>
                </div>
            </div>

            <div class="kpi-card">
                <div class="kpi-header">
                    <div>
                        <div class="kpi-value"><?= number_format($monthlyRevenue, 0, '.', ' ') ?> ₽</div>
                        <div class="kpi-label">Выручка за месяц</div>
                    </div>
                    <div class="kpi-icon revenue"><i class="fas fa-ruble-sign"></i></div>
                </div>
                <div class="kpi-trend up">
                    <i class="fas fa-calendar-day"></i>
                    <span>Сегодня: <?= number_format($todayCompleted['value'], 0, '.', ' ') ?> ₽</span>
                </div>
            </div>

            <div class="kpi-card">
                <div class="kpi-header">
                    <div>
                        <div class="kpi-value"><?= (int)$productionStats['in_progress'] ?></div>
                        <div class="kpi-label">Заданий в работе</div>
                    </div>
                    <div class="kpi-icon production"><i class="fas fa-cogs"></i></div>
                </div>
                <div class="kpi-trend <?= $weeklyEfficiency >= 80 ? 'up' : 'down' ?>">
                    <i class="fas fa-chart-line"></i>
                    <span>Эффективность: <?= $weeklyEfficiency ?>%</span>
                </div>
            </div>

            <div class="kpi-card">
                <div class="kpi-header">
                    <div>
                        <div class="kpi-value"><?= (int)$warehouseStats['total_materials'] ?></div>
                        <div class="kpi-label">Материалов на складе</div>
                    </div>
                    <div class="kpi-icon warehouse"><i class="fas fa-boxes"></i></div>
                </div>
                <div class="kpi-trend <?= ($warehouseStats['low_stock'] ?? 0) > 0 ? 'down' : 'up' ?>">
                    <i class="fas fa-exclamation-triangle"></i>
                    <span><?= (int)$warehouseStats['low_stock'] ?> требуют внимания</span>
                </div>
            </div>

            <div class="kpi-card">
                <div class="kpi-header">
                    <div>
                        <div class="kpi-value"><?= (int)$equipmentStats['total'] ?></div>
                        <div class="kpi-label">Единиц оборудования</div>
                    </div>
                    <div class="kpi-icon equipment"><i class="fas fa-industry"></i></div>
                </div>
                <div class="kpi-trend <?= ($equipmentStats['broken'] ?? 0) > 0 ? 'down' : 'up' ?>">
                    <i class="fas fa-wrench"></i>
                    <span><?= (int)$equipmentStats['operational'] ?> исправно</span>
                </div>
            </div>

            <div class="kpi-card">
                <div class="kpi-header">
                    <div>
                        <div class="kpi-value"><?= $onlineUsers ?>/<wbr><?= $totalEmployees ?></div>
                        <div class="kpi-label">Сотрудников онлайн</div>
                    </div>
                    <div class="kpi-icon employees"><i class="fas fa-users"></i></div>
                </div>
                <div class="kpi-trend up">
                    <i class="fas fa-clock"></i>
                    <span>Активны сейчас</span>
                </div>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content-grid">
            <!-- Left Column -->
            <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                <!-- Orders Chart -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-chart-pie"></i> Структура заказов</h3>
                        <a href="<?= APP_URL ?>/modules/orders/index.php" class="card-link">Все заказы <i class="fas fa-arrow-right"></i></a>
                    </div>
                    <div class="chart-container">
                        <canvas id="ordersChart"></canvas>
                    </div>
                </div>

                <!-- Monthly Revenue Chart -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-chart-bar"></i> Динамика заказов (6 месяцев)</h3>
                        <a href="<?= APP_URL ?>/modules/orders/index.php" class="card-link">Подробно <i class="fas fa-arrow-right"></i></a>
                    </div>
                    <div class="chart-container">
                        <canvas id="revenueChart"></canvas>
                    </div>
                </div>

                <!-- Recent Orders -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-list"></i> Последние заказы</h3>
                        <a href="<?= APP_URL ?>/modules/orders/index.php" class="card-link">Все заказы <i class="fas fa-arrow-right"></i></a>
                    </div>
                    <table class="table-custom">
                        <thead>
                            <tr>
                                <th>№</th>
                                <th>Клиент</th>
                                <th>Сумма</th>
                                <th>Статус</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentOrders as $order): ?>
                            <tr>
                                <td>#<?= e($order['id']) ?></td>
                                <td><?= e($order['customer_name'] ?? 'Не указан') ?></td>
                                <td><?= number_format($order['total_amount'], 0, '.', ' ') ?> ₽</td>
                                <td><span class="status-badge status-<?= e($order['status']) ?>"><?= e($order['status']) ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Right Column -->
            <div style="display: flex; flex-direction: column; gap: 1.5rem;">
                <!-- Alerts -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-bell"></i> Требуют внимания</h3>
                    </div>
                    <?php if (empty($alerts)): ?>
                    <div class="no-alerts">
                        <i class="fas fa-check-circle"></i>
                        <p>Все показатели в норме</p>
                    </div>
                    <?php else: ?>
                    <div class="alerts-list">
                        <?php foreach ($alerts as $alert): ?>
                        <a href="<?= e($alert['link']) ?>" class="alert-item <?= e($alert['type']) ?>">
                            <div class="alert-icon">
                                <i class="fas fa-<?= $alert['type'] === 'critical' ? 'exclamation-circle' : ($alert['type'] === 'warning' ? 'exclamation-triangle' : 'info-circle') ?>"></i>
                            </div>
                            <div class="alert-content">
                                <div class="alert-module"><?= e($alert['module']) ?></div>
                                <div class="alert-title"><?= e($alert['title']) ?></div>
                            </div>
                            <div class="alert-count"><?= (int)$alert['count'] ?></div>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Production Stats -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-tasks"></i> Производство</h3>
                        <a href="<?= APP_URL ?>/modules/production/index.php" class="card-link">Подробнее <i class="fas fa-arrow-right"></i></a>
                    </div>
                    <div class="module-stats">
                        <div class="module-stat">
                            <div class="module-stat-value"><?= (int)$productionStats['planned'] ?></div>
                            <div class="module-stat-label">Запланировано</div>
                        </div>
                        <div class="module-stat">
                            <div class="module-stat-value"><?= (int)$productionStats['in_progress'] ?></div>
                            <div class="module-stat-label">В работе</div>
                        </div>
                        <div class="module-stat">
                            <div class="module-stat-value"><?= (int)$productionStats['completed'] ?></div>
                            <div class="module-stat-label">Выполнено</div>
                        </div>
                        <div class="module-stat">
                            <div class="module-stat-value"><?= $weeklyEfficiency ?>%</div>
                            <div class="module-stat-label">Эффективность</div>
                        </div>
                    </div>
                </div>

                <!-- Warehouse Stats -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-warehouse"></i> Склад</h3>
                        <a href="<?= APP_URL ?>/modules/warehouse/warehouse_dashboard.php" class="card-link">Подробнее <i class="fas fa-arrow-right"></i></a>
                    </div>
                    <div class="module-stats">
                        <div class="module-stat">
                            <div class="module-stat-value" style="color: var(--success)"><?= (int)$warehouseStats['total_materials'] - (int)$warehouseStats['low_stock'] ?></div>
                            <div class="module-stat-label">В норме</div>
                        </div>
                        <div class="module-stat">
                            <div class="module-stat-value" style="color: var(--warning)"><?= (int)$warehouseStats['low_stock'] ?></div>
                            <div class="module-stat-label">Мало</div>
                        </div>
                        <div class="module-stat">
                            <div class="module-stat-value" style="color: var(--danger)"><?= (int)$warehouseStats['out_of_stock'] ?></div>
                            <div class="module-stat-label">Отсутствует</div>
                        </div>
                        <div class="module-stat">
                            <div class="module-stat-value" style="color: var(--info)"><?= (int)$ordersStats['ready_orders'] ?></div>
                            <div class="module-stat-label">Готово к отгрузке</div>
                        </div>
                    </div>
                </div>

                <!-- Equipment Stats -->
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title"><i class="fas fa-tools"></i> Оборудование</h3>
                        <a href="<?= APP_URL ?>/modules/equipment/index.php" class="card-link">Подробнее <i class="fas fa-arrow-right"></i></a>
                    </div>
                    <div class="module-stats">
                        <div class="module-stat">
                            <div class="module-stat-value" style="color: var(--success)"><?= (int)$equipmentStats['operational'] ?></div>
                            <div class="module-stat-label">Исправно</div>
                        </div>
                        <div class="module-stat">
                            <div class="module-stat-value" style="color: var(--warning)"><?= (int)$equipmentStats['maintenance'] ?></div>
                            <div class="module-stat-label">На ТО</div>
                        </div>
                        <div class="module-stat">
                            <div class="module-stat-value" style="color: var(--danger)"><?= (int)$equipmentStats['broken'] ?></div>
                            <div class="module-stat-label">Неисправно</div>
                        </div>
                        <div class="module-stat">
                            <div class="module-stat-value" style="color: var(--info)"><?= round((int)$equipmentStats['operational'] / max(1, (int)$equipmentStats['total']) * 100) ?>%</div>
                            <div class="module-stat-label">Коэф. готовности</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Orders Pie Chart
        const ordersCtx = document.getElementById('ordersChart').getContext('2d');
        new Chart(ordersCtx, {
            type: 'doughnut',
            data: {
                labels: ['Новые', 'В производстве', 'Контроль качества', 'Готовы', 'Выполнены'],
                datasets: [{
                    data: [
                        <?= $ordersByStatus['new'] ?>,
                        <?= $ordersByStatus['in_production'] ?>,
                        <?= $ordersByStatus['qc_orders'] ?>,
                        <?= $ordersByStatus['ready'] ?>,
                        <?= $ordersByStatus['completed'] ?>
                    ],
                    backgroundColor: [
                        '#3B82F6',
                        '#F59E0B',
                        '#8B5CF6',
                        '#10B981',
                        '#06B6D4'
                    ],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'right',
                        labels: { color: 'rgba(255,255,255,0.7)', usePointStyle: true }
                    }
                }
            }
        });

        // Revenue Bar Chart
        const revenueCtx = document.getElementById('revenueChart').getContext('2d');
        new Chart(revenueCtx, {
            type: 'bar',
            data: {
                labels: <?= json_encode(array_column($monthlyOrders, 'month')) ?>,
                datasets: [{
                    label: 'Количество заказов',
                    data: <?= json_encode(array_column($monthlyOrders, 'count')) ?>,
                    backgroundColor: 'rgba(255, 107, 107, 0.8)',
                    borderRadius: 8
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
                        ticks: { color: 'rgba(255,255,255,0.5)' },
                        grid: { color: 'rgba(255,255,255,0.05)' }
                    },
                    x: {
                        ticks: { color: 'rgba(255,255,255,0.5)' },
                        grid: { display: false }
                    }
                }
            }
        });
    </script>
</body>
</html>
