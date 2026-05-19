<?php
/**
 * Панель управления для Директора - PolesieMES
 * Современный интерфейс с обзором по всем модулям системы
 * 
 * Для каждого блока отображаются:
 * - Ключевые показатели
 * - Заявки/задачи требующие внимания
 * - Быстрый переход к просмотру
 * - Фильтры и навигация
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

// Проверка авторизации
requireAuth();

// Доступ только для директора и администратора
if (!hasRole(['director', 'admin'])) {
    redirectWithMessage(APP_URL . '/modules/dashboard/index.php', 'Доступ запрещён. Это раздел для руководителя.', 'error');
}

$db = getDB();
$user = getCurrentUser();
$userFirstName = !empty($user['full_name']) ? explode(' ', $user['full_name'])[0] : 'Руководитель';

// ==========================================
// ОБЩАЯ СТАТИСТИКА ПРЕДПРИЯТИЯ
// ==========================================

// Заказы - общая статистика
$stmt = $db->query("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) as new_orders,
        SUM(CASE WHEN status = 'in_production' THEN 1 ELSE 0 END) as production_orders,
        SUM(CASE WHEN status = 'quality_check' THEN 1 ELSE 0 END) as qc_orders,
        SUM(CASE WHEN status = 'ready' THEN 1 ELSE 0 END) as ready_orders,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
        COALESCE(SUM(total_amount), 0) as total_value,
        COALESCE(SUM(CASE WHEN status NOT IN ('completed', 'cancelled') THEN total_amount ELSE 0 END), 0) as active_value
    FROM orders
");
$ordersStats = $stmt->fetch();

// Выручка за месяц
$stmt = $db->query("
    SELECT COALESCE(SUM(total_amount), 0) as monthly_revenue
    FROM orders
    WHERE status = 'completed'
    AND MONTH(created_at) = MONTH(CURRENT_DATE())
    AND YEAR(created_at) = YEAR(CURRENT_DATE())
");
$monthlyRevenue = $stmt->fetch()['monthly_revenue'];

// Производство
$stmt = $db->query("
    SELECT
        COUNT(*) as total_tasks,
        SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'planned' THEN 1 ELSE 0 END) as planned,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        SUM(CASE WHEN status = 'paused' THEN 1 ELSE 0 END) as paused
    FROM production_tasks
");
$productionStats = $stmt->fetch();

// Оборудование
$stmt = $db->query("
    SELECT
        COUNT(*) as total_equipment,
        SUM(CASE WHEN status = 'operational' THEN 1 ELSE 0 END) as operational,
        SUM(CASE WHEN status = 'maintenance' THEN 1 ELSE 0 END) as maintenance,
        SUM(CASE WHEN status = 'broken' THEN 1 ELSE 0 END) as broken
    FROM items
    WHERE item_type = 'equipment'
");
$equipmentStats = $stmt->fetch();

// Сотрудники
$stmt = $db->query("SELECT role, COUNT(*) as count FROM staff GROUP BY role");
$employeesByRole = $stmt->fetchAll();
$stmt = $db->query("SELECT COUNT(*) as total FROM staff");
$totalEmployees = $stmt->fetch()['total'];

// ==========================================
// ДАННЫЕ ПО МОДУЛЯМ
// ==========================================

// ----- СКЛАД -----
$warehouseData = [];

// Статистика по материалам
$stmt = $db->query("
    SELECT 
        COUNT(*) as total_items,
        SUM(CASE WHEN current_stock <= 0 THEN 1 ELSE 0 END) as out_of_stock,
        SUM(CASE WHEN current_stock < min_stock AND current_stock > 0 THEN 1 ELSE 0 END) as low_stock,
        SUM(current_stock) as total_stock
    FROM items
    WHERE item_type = 'material'
");
$warehouseData['stats'] = $stmt->fetch();

// Материалы с низким запасом (топ-10)
$stmt = $db->query("
    SELECT i.id, i.name, i.item_code, i.current_stock, i.min_stock, u.name as unit_name
    FROM items i
    LEFT JOIN dictionaries u ON i.unit_id = u.id AND u.dict_type = 'unit'
    WHERE i.item_type = 'material' AND i.current_stock <= i.min_stock
    ORDER BY (i.min_stock - i.current_stock) DESC
    LIMIT 10
");
$warehouseData['lowStock'] = $stmt->fetchAll();

// Последние движения на складе
$stmt = $db->query("
    SELECT mvt.*, i.name as item_name, i.item_code,
           s.first_name, s.last_name,
           CASE mvt.movement_type
               WHEN 'receipt' THEN 'Поступление'
               WHEN 'consumption' THEN 'Расход'
               WHEN 'return' THEN 'Возврат'
               WHEN 'adjustment' THEN 'Корректировка'
               WHEN 'shipment' THEN 'Отгрузка'
               ELSE mvt.movement_type
           END as operation_name
    FROM movements mvt
    LEFT JOIN items i ON mvt.item_id = i.id
    LEFT JOIN staff s ON mvt.employee_id = s.id
    WHERE mvt.movement_type IN ('receipt', 'consumption', 'return', 'adjustment', 'shipment')
    ORDER BY mvt.movement_date DESC
    LIMIT 10
");
$warehouseData['recentMovements'] = $stmt->fetchAll();

// Заявки от кладовщика (заказы поставщикам ожидающие подтверждения)
$stmt = $db->query("
    SELECT po.id, po.order_number, p.name as supplier_name, 
           po.total_amount, po.expected_delivery, po.status,
           CASE po.status
               WHEN 'draft' THEN 'Черновик'
               WHEN 'pending_approval' THEN 'На согласовании'
               WHEN 'approved' THEN 'Согласован'
               WHEN 'sent' THEN 'Отправлен'
               WHEN 'confirmed' THEN 'Подтверждён'
               WHEN 'partial' THEN 'Частично'
               WHEN 'completed' THEN 'Выполнен'
               ELSE po.status
           END as status_name
    FROM purchase_orders po
    LEFT JOIN partners p ON po.supplier_id = p.id
    WHERE po.status IN ('draft', 'pending_approval')
    ORDER BY po.created_at DESC
    LIMIT 10
");
$warehouseData['pendingOrders'] = $stmt->fetchAll();

// ----- ПРОИЗВОДСТВО -----
$productionData = [];

// Активные производственные задания
$stmt = $db->query("
    SELECT pt.*, p.name as product_name, pt.stage_name,
           s.first_name, s.last_name,
           DATEDIFF(NOW(), pt.planned_end) as days_overdue
    FROM production_tasks pt
    LEFT JOIN items p ON pt.product_id = p.id
    LEFT JOIN staff s ON pt.assigned_to = s.id
    WHERE pt.status IN ('in_progress', 'planned', 'paused')
    ORDER BY 
        CASE pt.status WHEN 'paused' THEN 1 WHEN 'in_progress' THEN 2 ELSE 3 END,
        pt.planned_end ASC
    LIMIT 15
");
$productionData['activeTasks'] = $stmt->fetchAll();

// Просроченные задания
$stmt = $db->query("
    SELECT pt.*, p.name as product_name, s.first_name, s.last_name,
           DATEDIFF(NOW(), pt.planned_end) as days_overdue
    FROM production_tasks pt
    LEFT JOIN items p ON pt.product_id = p.id
    LEFT JOIN staff s ON pt.assigned_to = s.id
    WHERE pt.status NOT IN ('completed', 'rejected') AND pt.planned_end < NOW()
    ORDER BY pt.planned_end ASC
    LIMIT 10
");
$productionData['overdueTasks'] = $stmt->fetchAll();

// Эффективность по цехам/участкам (если есть разделение)
$stmt = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
        ROUND(SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) as efficiency_percent
    FROM production_tasks
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
");
$productionData['weeklyEfficiency'] = $stmt->fetch();

// ----- ЗАКАЗЫ -----
$ordersData = [];

// Новые заказы требующие обработки
$stmt = $db->query("
    SELECT o.*, p.name as customer_name, c.first_name as manager_first, c.last_name as manager_last
    FROM orders o
    LEFT JOIN partners p ON o.customer_id = p.id
    LEFT JOIN staff c ON o.manager_id = c.id
    WHERE o.status = 'new'
    ORDER BY o.created_at DESC
    LIMIT 10
");
$ordersData['newOrders'] = $stmt->fetchAll();

// Заказы в производстве
$stmt = $db->query("
    SELECT o.*, p.name as customer_name,
           (SELECT COUNT(*) FROM production_tasks pt WHERE pt.order_id = o.id) as tasks_count,
           (SELECT COUNT(*) FROM production_tasks pt WHERE pt.order_id = o.id AND pt.status = 'completed') as completed_tasks
    FROM orders o
    LEFT JOIN partners p ON o.customer_id = p.id
    WHERE o.status = 'in_production'
    ORDER BY o.created_at DESC
    LIMIT 10
");
$ordersData['inProduction'] = $stmt->fetchAll();

// Просроченные заказы
$stmt = $db->query("
    SELECT o.*, p.name as customer_name, DATEDIFF(NOW(), o.delivery_date) as days_overdue
    FROM orders o
    LEFT JOIN partners p ON o.customer_id = p.id
    WHERE o.status NOT IN ('completed', 'cancelled')
    AND o.delivery_date < NOW()
    ORDER BY o.delivery_date ASC
    LIMIT 10
");
$ordersData['overdueOrders'] = $stmt->fetchAll();

// ----- ОБОРУДОВАНИЕ -----
$equipmentData = [];

// Неисправное оборудование
$stmt = $db->query("
    SELECT id, name, item_code, status, location, 
           last_maintenance_date, next_maintenance_date
    FROM items
    WHERE item_type = 'equipment' AND status IN ('broken', 'maintenance')
    ORDER BY 
        CASE status WHEN 'broken' THEN 1 WHEN 'maintenance' THEN 2 END,
        name ASC
    LIMIT 10
");
$equipmentData['problemEquipment'] = $stmt->fetchAll();

// Оборудование требующее ТО
$stmt = $db->query("
    SELECT id, name, item_code, next_maintenance_date,
           DATEDIFF(next_maintenance_date, NOW()) as days_until_maintenance
    FROM items
    WHERE item_type = 'equipment' 
    AND status = 'operational'
    AND next_maintenance_date <= DATE_ADD(NOW(), INTERVAL 14 DAY)
    ORDER BY next_maintenance_date ASC
    LIMIT 10
");
$equipmentData['upcomingMaintenance'] = $stmt->fetchAll();

// ----- ОТГРУЗКА -----
$shipmentData = [];

// Готовые к отгрузке заказы
$stmt = $db->query("
    SELECT o.*, p.name as customer_name, o.delivery_date
    FROM orders o
    LEFT JOIN partners p ON o.customer_id = p.id
    WHERE o.status = 'ready'
    ORDER BY o.delivery_date ASC
    LIMIT 10
");
$shipmentData['readyToShip'] = $stmt->fetchAll();

// Последние отгрузки
$stmt = $db->query("
    SELECT DISTINCT o.id, o.order_number, o.delivery_date, p.name as customer_name,
           mvt.movement_date as shipment_date, mvt.notes
    FROM orders o
    LEFT JOIN partners p ON o.customer_id = p.id
    LEFT JOIN movements mvt ON mvt.reference_id = o.id AND mvt.movement_type = 'shipment'
    WHERE mvt.id IS NOT NULL
    ORDER BY mvt.movement_date DESC
    LIMIT 10
");
$shipmentData['recentShipments'] = $stmt->fetchAll();

// ----- ДОКУМЕНТЫ -----
$docsData = [];

// Заказы готовые к отгрузке (требуют документы)
$stmt = $db->query("
    SELECT o.*, p.name as customer_name
    FROM orders o
    LEFT JOIN partners p ON o.customer_id = p.id
    WHERE o.status IN ('ready', 'in_production')
    AND o.delivery_date <= DATE_ADD(NOW(), INTERVAL 7 DAY)
    ORDER BY o.delivery_date ASC
    LIMIT 10
");
$docsData['pendingDocs'] = $stmt->fetchAll();

$pageTitle = 'Панель руководителя | ' . APP_NAME;
$currentPage = 'director_dashboard';
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <!-- Common Style -->
    <link href="<?= APP_URL ?>/assets/css/common-style.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #FF6B6B;
            --secondary-color: #FF8E53;
            --success-color: #30d158;
            --warning-color: #ffd60a;
            --danger-color: #ff453a;
            --info-color: #5ac8fa;
            
            --bg-dark: #0f0f1a;
            --bg-card: rgba(25, 25, 40, 0.7);
            --bg-hover: rgba(35, 35, 55, 0.8);
            --border-color: rgba(255, 255, 255, 0.1);
            --text-primary: #ffffff;
            --text-secondary: rgba(255, 255, 255, 0.7);
            --text-muted: rgba(255, 255, 255, 0.5);
            
            --gradient-primary: linear-gradient(135deg, #FF6B6B 0%, #FF8E53 100%);
            --glass-bg: rgba(255, 255, 255, 0.03);
            --glass-border: rgba(255, 255, 255, 0.08);
            --backdrop-blur: blur(20px);
            --shadow-lg: 0 16px 48px rgba(0, 0, 0, 0.5);
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: linear-gradient(180deg, #0f0f1a 0%, #1a1a2e 100%);
            min-height: 100vh;
            color: var(--text-primary);
            overflow-x: hidden;
        }
        
        /* Navbar */
        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(15, 15, 26, 0.9);
            backdrop-filter: var(--backdrop-blur);
            border-bottom: 1px solid var(--border-color);
        }
        
        .nav-brand {
            display: flex;
            align-items: center;
            gap: 1rem;
            text-decoration: none;
            color: var(--text-primary);
        }
        
        .brand-logo {
            width: 45px;
            height: 45px;
            background: var(--gradient-primary);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .brand-logo svg { width: 26px; height: 26px; fill: white; }
        
        .brand-name {
            font-size: 1.3rem;
            font-weight: 700;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .nav-menu {
            display: flex;
            align-items: center;
            gap: 2rem;
            list-style: none;
        }
        
        .nav-link {
            color: var(--text-secondary);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .nav-link:hover, .nav-link.active {
            color: var(--text-primary);
        }
        
        .nav-link.active {
            background: rgba(255, 107, 107, 0.15);
            padding: 0.5rem 1rem;
            border-radius: 8px;
        }
        
        /* Main Content */
        .main-content {
            margin-top: 80px;
            padding: 2rem;
            max-width: 1600px;
            margin-left: auto;
            margin-right: auto;
        }
        
        .page-header {
            margin-bottom: 2rem;
        }
        
        .page-title {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .page-subtitle {
            color: var(--text-secondary);
            font-size: 1rem;
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
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1.5rem;
            backdrop-filter: var(--backdrop-blur);
            transition: all 0.3s ease;
        }
        
        .kpi-card:hover {
            transform: translateY(-4px);
            border-color: rgba(255, 107, 107, 0.3);
            box-shadow: var(--shadow-lg);
        }
        
        .kpi-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            margin-bottom: 1rem;
        }
        
        .kpi-icon.orders { background: rgba(90, 200, 250, 0.2); color: #5ac8fa; }
        .kpi-icon.revenue { background: rgba(48, 209, 88, 0.2); color: #30d158; }
        .kpi-icon.production { background: rgba(255, 214, 10, 0.2); color: #ffd60a; }
        .kpi-icon.equipment { background: rgba(255, 142, 83, 0.2); color: #FF8E53; }
        
        .kpi-value {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        
        .kpi-label {
            color: var(--text-secondary);
            font-size: 0.9rem;
        }
        
        .kpi-trend {
            font-size: 0.8rem;
            margin-top: 0.5rem;
        }
        
        .kpi-trend.positive { color: var(--success-color); }
        .kpi-trend.negative { color: var(--danger-color); }
        
        /* Module Sections */
        .module-section {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 2rem;
            backdrop-filter: var(--backdrop-blur);
        }
        
        .module-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }
        
        .module-title {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.3rem;
            font-weight: 700;
        }
        
        .module-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        
        .module-actions {
            display: flex;
            gap: 0.75rem;
        }
        
        .btn-module {
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-outline {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-primary);
        }
        
        .btn-outline:hover {
            background: var(--gradient-primary);
            border-color: transparent;
            color: white;
        }
        
        .btn-primary {
            background: var(--gradient-primary);
            border: none;
            color: white;
        }
        
        .btn-primary:hover {
            opacity: 0.9;
            color: white;
        }
        
        /* Data Tables */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th,
        .data-table td {
            padding: 0.75rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }
        
        .data-table th {
            color: var(--text-secondary);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .data-table tr:hover {
            background: var(--bg-hover);
        }
        
        .data-table tr:last-child td {
            border-bottom: none;
        }
        
        /* Status Badges */
        .badge {
            padding: 0.35rem 0.75rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
        
        .badge.success { background: rgba(48, 209, 88, 0.2); color: #30d158; }
        .badge.warning { background: rgba(255, 214, 10, 0.2); color: #ffd60a; }
        .badge.danger { background: rgba(255, 69, 58, 0.2); color: #ff453a; }
        .badge.info { background: rgba(90, 200, 250, 0.2); color: #5ac8fa; }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .stat-item {
            background: var(--glass-bg);
            padding: 1rem;
            border-radius: 10px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        
        .stat-label {
            color: var(--text-muted);
            font-size: 0.8rem;
        }
        
        /* Empty State */
        .empty-state {
            padding: 3rem;
            text-align: center;
            color: var(--text-muted);
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        /* Scrollable Container */
        .scroll-container {
            max-height: 400px;
            overflow-y: auto;
        }
        
        .scroll-container::-webkit-scrollbar {
            width: 6px;
        }
        
        .scroll-container::-webkit-scrollbar-track {
            background: var(--glass-bg);
            border-radius: 3px;
        }
        
        .scroll-container::-webkit-scrollbar-thumb {
            background: var(--border-color);
            border-radius: 3px;
        }
        
        /* Filter Bar */
        .filter-bar {
            display: flex;
            gap: 1rem;
            margin-bottom: 1rem;
            flex-wrap: wrap;
        }
        
        .filter-select {
            background: var(--glass-bg);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 0.5rem 1rem;
            border-radius: 8px;
            font-size: 0.85rem;
        }
        
        .filter-select:focus {
            outline: none;
            border-color: var(--primary-color);
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar">
        <a href="<?= APP_URL ?>" class="nav-brand">
            <div class="brand-logo">
                <svg viewBox="0 0 24 24"><path d="M12 2L2 7l10 5 10-5-10-5zm0 9l2.5-1.25L12 8.5l-2.5 1.25L12 11zm0 2.5l-5-2.5-5 2.5L12 22l10-8.5-5-2.5-5 2.5z"/></svg>
            </div>
            <span class="brand-name">PolesieMES</span>
        </a>
        
        <ul class="nav-menu">
            <li><a href="<?= APP_URL ?>/modules/dashboard/index.php" class="nav-link"><i class="fas fa-home"></i> Главная</a></li>
            <li><a href="<?= APP_URL ?>/modules/orders/index.php" class="nav-link"><i class="fas fa-shopping-cart"></i> Заказы</a></li>
            <li><a href="<?= APP_URL ?>/modules/production/index.php" class="nav-link"><i class="fas fa-cogs"></i> Производство</a></li>
            <li><a href="#warehouse-section" class="nav-link"><i class="fas fa-warehouse"></i> Склад</a></li>
            <li><a href="#equipment-section" class="nav-link"><i class="fas fa-tools"></i> Оборудование</a></li>
            <li><a href="#shipment-section" class="nav-link"><i class="fas fa-truck"></i> Отгрузка</a></li>
            <li><a href="<?= APP_URL ?>/modules/employees/index.php" class="nav-link"><i class="fas fa-users"></i> Сотрудники</a></li>
        </ul>
        
        <div style="display: flex; align-items: center; gap: 1rem;">
            <div style="text-align: right;">
                <div style="font-weight: 600;"><?= e($userFirstName) ?></div>
                <div style="font-size: 0.8rem; color: var(--text-muted);">Директор</div>
            </div>
            <a href="<?= APP_URL ?>/modules/auth/logout.php" class="btn btn-outline" style="padding: 0.5rem 1rem; border-radius: 8px;">
                <i class="fas fa-sign-out-alt"></i>
            </a>
        </div>
    </nav>
    
    <!-- Main Content -->
    <div class="main-content">
        <!-- Page Header -->
        <div class="page-header">
            <h1 class="page-title">Панель руководителя</h1>
            <p class="page-subtitle">Обзор ключевых показателей и оперативных данных предприятия</p>
        </div>
        
        <!-- KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="kpi-icon orders"><i class="fas fa-shopping-cart"></i></div>
                <div class="kpi-value"><?= $ordersStats['total'] ?? 0 ?></div>
                <div class="kpi-label">Всего заказов</div>
                <div class="kpi-trend positive">
                    <i class="fas fa-arrow-up"></i> <?= $ordersStats['new_orders'] ?? 0 ?> новых
                </div>
            </div>
            
            <div class="kpi-card">
                <div class="kpi-icon revenue"><i class="fas fa-money-bill-wave"></i></div>
                <div class="kpi-value"><?= number_format($monthlyRevenue, 0, '.', ' ') ?> р.</div>
                <div class="kpi-label">Выручка за месяц</div>
                <div class="kpi-trend">
                    Активных на сумму: <?= number_format($ordersStats['active_value'], 0, '.', ' ') ?> р.
                </div>
            </div>
            
            <div class="kpi-card">
                <div class="kpi-icon production"><i class="fas fa-cogs"></i></div>
                <div class="kpi-value"><?= $productionStats['in_progress'] ?? 0 ?></div>
                <div class="kpi-label">В производстве</div>
                <div class="kpi-trend">
                    План: <?= $productionStats['planned'] ?? 0 ?> | Пауза: <?= $productionStats['paused'] ?? 0 ?>
                </div>
            </div>
            
            <div class="kpi-card">
                <div class="kpi-icon equipment"><i class="fas fa-tools"></i></div>
                <div class="kpi-value"><?= $equipmentStats['operational'] ?? 0 ?></div>
                <div class="kpi-label">Оборудование в работе</div>
                <div class="kpi-trend negative">
                    <i class="fas fa-exclamation-triangle"></i> <?= $equipmentStats['broken'] ?? 0 ?> неисправно
                </div>
            </div>
        </div>
        
        <!-- СКЛАД -->
        <div class="module-section" id="warehouse-section">
            <div class="module-header">
                <div class="module-title">
                    <div class="module-icon" style="background: rgba(48, 209, 88, 0.2); color: #30d158;">
                        <i class="fas fa-warehouse"></i>
                    </div>
                    Склад
                </div>
                <div class="module-actions">
                    <a href="<?= APP_URL ?>/modules/warehouse/index.php" class="btn-module btn-outline">
                        <i class="fas fa-eye"></i> Просмотр склада
                    </a>
                    <a href="<?= APP_URL ?>/modules/warehouse/purchase_orders/" class="btn-module btn-outline">
                        <i class="fas fa-file-invoice"></i> Заказы поставщикам
                    </a>
                    <a href="<?= APP_URL ?>/modules/warehouse/orders/" class="btn-module btn-outline">
                        <i class="fas fa-clipboard-list"></i> Внутренние заявки
                    </a>
                </div>
            </div>
            
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-value"><?= $warehouseData['stats']['total_items'] ?? 0 ?></div>
                    <div class="stat-label">Всего позиций</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value" style="color: var(--danger-color);"><?= $warehouseData['stats']['out_of_stock'] ?? 0 ?></div>
                    <div class="stat-label">Нет на складе</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value" style="color: var(--warning-color);"><?= $warehouseData['stats']['low_stock'] ?? 0 ?></div>
                    <div class="stat-label">Низкий запас</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?= count($warehouseData['pendingOrders']) ?></div>
                    <div class="stat-label">Требуют внимания</div>
                </div>
            </div>
            
            <?php if (!empty($warehouseData['pendingOrders'])): ?>
            <div style="margin-bottom: 1.5rem;">
                <h4 style="font-size: 1rem; margin-bottom: 1rem; color: var(--text-secondary);">
                    <i class="fas fa-bell" style="color: var(--warning-color);"></i> Заявки на согласование
                </h4>
                <div class="scroll-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Номер</th>
                                <th>Поставщик</th>
                                <th>Сумма</th>
                                <th>Ожидаемая дата</th>
                                <th>Статус</th>
                                <th>Действие</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($warehouseData['pendingOrders'] as $order): ?>
                            <tr>
                                <td><?= e($order['order_number']) ?></td>
                                <td><?= e($order['supplier_name']) ?></td>
                                <td><?= number_format($order['total_amount'], 0, '.', ' ') ?> р.</td>
                                <td><?= formatDate($order['expected_delivery']) ?></td>
                                <td><span class="badge warning"><?= e($order['status_name']) ?></span></td>
                                <td>
                                    <a href="<?= APP_URL ?>/modules/warehouse/purchase_orders/?view=<?= $order['id'] ?>" class="btn-module btn-outline" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($warehouseData['lowStock'])): ?>
            <div>
                <h4 style="font-size: 1rem; margin-bottom: 1rem; color: var(--text-secondary);">
                    <i class="fas fa-triangle-exclamation" style="color: var(--warning-color);"></i> Материалы с низким запасом
                </h4>
                <div class="scroll-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Наименование</th>
                                <th>Артикул</th>
                                <th>Остаток</th>
                                <th>Минимум</th>
                                <th>Ед. изм.</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($warehouseData['lowStock'] as $item): ?>
                            <tr>
                                <td><?= e($item['name']) ?></td>
                                <td><?= e($item['item_code']) ?></td>
                                <td style="color: var(--danger-color);"><?= $item['current_stock'] ?></td>
                                <td><?= $item['min_stock'] ?></td>
                                <td><?= e($item['unit_name'] ?? 'шт.') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-check-circle"></i>
                <p>Все материалы в достаточном количестве</p>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- ПРОИЗВОДСТВО -->
        <div class="module-section" id="production-section">
            <div class="module-header">
                <div class="module-title">
                    <div class="module-icon" style="background: rgba(255, 214, 10, 0.2); color: #ffd60a;">
                        <i class="fas fa-cogs"></i>
                    </div>
                    Производство
                </div>
                <div class="module-actions">
                    <a href="<?= APP_URL ?>/modules/production/index.php" class="btn-module btn-outline">
                        <i class="fas fa-list"></i> Все задания
                    </a>
                    <a href="<?= APP_URL ?>/modules/production/create_task.php" class="btn-module btn-primary">
                        <i class="fas fa-plus"></i> Создать задание
                    </a>
                </div>
            </div>
            
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-value"><?= $productionStats['in_progress'] ?? 0 ?></div>
                    <div class="stat-label">В работе</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value" style="color: var(--info-color);"><?= $productionStats['planned'] ?? 0 ?></div>
                    <div class="stat-label">В плане</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value" style="color: var(--warning-color);"><?= $productionStats['paused'] ?? 0 ?></div>
                    <div class="stat-label">На паузе</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value" style="color: var(--success-color);"><?= $productionData['weeklyEfficiency']['efficiency_percent'] ?? 0 ?>%</div>
                    <div class="stat-label">Эффективность (неделя)</div>
                </div>
            </div>
            
            <?php if (!empty($productionData['overdueTasks'])): ?>
            <div style="margin-bottom: 1.5rem;">
                <h4 style="font-size: 1rem; margin-bottom: 1rem; color: var(--text-secondary);">
                    <i class="fas fa-exclamation-circle" style="color: var(--danger-color);"></i> Просроченные задания
                </h4>
                <div class="scroll-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Задание</th>
                                <th>Продукция</th>
                                <th>Ответственный</th>
                                <th>Плановая дата</th>
                                <th>Дней просрочки</th>
                                <th>Действие</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($productionData['overdueTasks'] as $task): ?>
                            <tr>
                                <td><?= e($task['task_number']) ?></td>
                                <td><?= e($task['product_name']) ?></td>
                                <td><?= e($task['first_name'] . ' ' . $task['last_name']) ?></td>
                                <td><?= formatDate($task['planned_end']) ?></td>
                                <td style="color: var(--danger-color);">+<?= abs($task['days_overdue']) ?></td>
                                <td>
                                    <a href="<?= APP_URL ?>/modules/production/?view=<?= $task['id'] ?>" class="btn-module btn-outline" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            
            <div>
                <h4 style="font-size: 1rem; margin-bottom: 1rem; color: var(--text-secondary);">
                    <i class="fas fa-tasks"></i> Активные задания
                </h4>
                <div class="scroll-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Задание</th>
                                <th>Продукция</th>
                                <th>Этап</th>
                                <th>Ответственный</th>
                                <th>Статус</th>
                                <th>Плановая дата</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($productionData['activeTasks'] as $task): ?>
                            <tr>
                                <td><?= e($task['task_number']) ?></td>
                                <td><?= e($task['product_name']) ?></td>
                                <td><?= e($task['stage_name']) ?></td>
                                <td><?= e($task['first_name'] . ' ' . $task['last_name']) ?></td>
                                <td>
                                    <?php
                                    $badgeClass = 'info';
                                    if ($task['status'] === 'paused') $badgeClass = 'warning';
                                    if ($task['status'] === 'in_progress') $badgeClass = 'success';
                                    ?>
                                    <span class="badge <?= $badgeClass ?>"><?= e($task['status']) ?></span>
                                </td>
                                <td><?= formatDate($task['planned_end']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- ЗАКАЗЫ -->
        <div class="module-section" id="orders-section">
            <div class="module-header">
                <div class="module-title">
                    <div class="module-icon" style="background: rgba(90, 200, 250, 0.2); color: #5ac8fa;">
                        <i class="fas fa-shopping-cart"></i>
                    </div>
                    Заказы
                </div>
                <div class="module-actions">
                    <a href="<?= APP_URL ?>/modules/orders/index.php" class="btn-module btn-outline">
                        <i class="fas fa-list"></i> Все заказы
                    </a>
                    <a href="<?= APP_URL ?>/modules/orders/create.php" class="btn-module btn-primary">
                        <i class="fas fa-plus"></i> Новый заказ
                    </a>
                </div>
            </div>
            
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-value"><?= $ordersStats['new_orders'] ?? 0 ?></div>
                    <div class="stat-label">Новые</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?= $ordersStats['production_orders'] ?? 0 ?></div>
                    <div class="stat-label">В производстве</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?= $ordersStats['ready_orders'] ?? 0 ?></div>
                    <div class="stat-label">Готовы</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value" style="color: var(--danger-color);"><?= count($ordersData['overdueOrders']) ?></div>
                    <div class="stat-label">Просрочены</div>
                </div>
            </div>
            
            <?php if (!empty($ordersData['overdueOrders'])): ?>
            <div style="margin-bottom: 1.5rem;">
                <h4 style="font-size: 1rem; margin-bottom: 1rem; color: var(--text-secondary);">
                    <i class="fas fa-exclamation-triangle" style="color: var(--danger-color);"></i> Просроченные заказы
                </h4>
                <div class="scroll-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Заказ</th>
                                <th>Клиент</th>
                                <th>Дата поставки</th>
                                <th>Дней просрочки</th>
                                <th>Сумма</th>
                                <th>Действие</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ordersData['overdueOrders'] as $order): ?>
                            <tr>
                                <td><?= e($order['order_number']) ?></td>
                                <td><?= e($order['customer_name']) ?></td>
                                <td><?= formatDate($order['delivery_date']) ?></td>
                                <td style="color: var(--danger-color);">+<?= abs($order['days_overdue']) ?></td>
                                <td><?= number_format($order['total_amount'], 0, '.', ' ') ?> р.</td>
                                <td>
                                    <a href="<?= APP_URL ?>/modules/orders/view.php?id=<?= $order['id'] ?>" class="btn-module btn-outline" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($ordersData['newOrders'])): ?>
            <div>
                <h4 style="font-size: 1rem; margin-bottom: 1rem; color: var(--text-secondary);">
                    <i class="fas fa-clock"></i> Новые заказы
                </h4>
                <div class="scroll-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Заказ</th>
                                <th>Клиент</th>
                                <th>Менеджер</th>
                                <th>Дата создания</th>
                                <th>Сумма</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ordersData['newOrders'] as $order): ?>
                            <tr>
                                <td><?= e($order['order_number']) ?></td>
                                <td><?= e($order['customer_name']) ?></td>
                                <td><?= e($order['manager_first'] . ' ' . $order['manager_last']) ?></td>
                                <td><?= formatDate($order['created_at']) ?></td>
                                <td><?= number_format($order['total_amount'], 0, '.', ' ') ?> р.</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- ОБОРУДОВАНИЕ -->
        <div class="module-section" id="equipment-section">
            <div class="module-header">
                <div class="module-title">
                    <div class="module-icon" style="background: rgba(255, 142, 83, 0.2); color: #FF8E53;">
                        <i class="fas fa-tools"></i>
                    </div>
                    Оборудование
                </div>
                <div class="module-actions">
                    <a href="<?= APP_URL ?>/modules/equipment/index.php" class="btn-module btn-outline">
                        <i class="fas fa-list"></i> Всё оборудование
                    </a>
                    <a href="<?= APP_URL ?>/modules/equipment/maintenance.php" class="btn-module btn-outline">
                        <i class="fas fa-wrench"></i> Обслуживание
                    </a>
                </div>
            </div>
            
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-value" style="color: var(--success-color);"><?= $equipmentStats['operational'] ?? 0 ?></div>
                    <div class="stat-label">В работе</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value" style="color: var(--warning-color);"><?= $equipmentStats['maintenance'] ?? 0 ?></div>
                    <div class="stat-label">На ТО</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value" style="color: var(--danger-color);"><?= $equipmentStats['broken'] ?? 0 ?></div>
                    <div class="stat-label">Неисправно</div>
                </div>
                <div class="stat-item">
                    <div class="stat-value"><?= count($equipmentData['upcomingMaintenance']) ?></div>
                    <div class="stat-label">Требуют ТО (14 дней)</div>
                </div>
            </div>
            
            <?php if (!empty($equipmentData['problemEquipment'])): ?>
            <div style="margin-bottom: 1.5rem;">
                <h4 style="font-size: 1rem; margin-bottom: 1rem; color: var(--text-secondary);">
                    <i class="fas fa-exclamation-triangle" style="color: var(--danger-color);"></i> Проблемное оборудование
                </h4>
                <div class="scroll-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Наименование</th>
                                <th>Код</th>
                                <th>Статус</th>
                                <th>Расположение</th>
                                <th>Последнее ТО</th>
                                <th>Действие</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($equipmentData['problemEquipment'] as $eq): ?>
                            <tr>
                                <td><?= e($eq['name']) ?></td>
                                <td><?= e($eq['item_code']) ?></td>
                                <td>
                                    <?php if ($eq['status'] === 'broken'): ?>
                                        <span class="badge danger">Неисправно</span>
                                    <?php else: ?>
                                        <span class="badge warning">На ТО</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($eq['location']) ?></td>
                                <td><?= $eq['last_maintenance_date'] ? formatDate($eq['last_maintenance_date']) : '-' ?></td>
                                <td>
                                    <a href="<?= APP_URL ?>/modules/equipment/?view=<?= $eq['id'] ?>" class="btn-module btn-outline" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($equipmentData['upcomingMaintenance'])): ?>
            <div>
                <h4 style="font-size: 1rem; margin-bottom: 1rem; color: var(--text-secondary);">
                    <i class="fas fa-calendar-alt"></i> Скоро требуется ТО
                </h4>
                <div class="scroll-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Наименование</th>
                                <th>Код</th>
                                <th>Дата ТО</th>
                                <th>Дней осталось</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($equipmentData['upcomingMaintenance'] as $eq): ?>
                            <tr>
                                <td><?= e($eq['name']) ?></td>
                                <td><?= e($eq['item_code']) ?></td>
                                <td><?= formatDate($eq['next_maintenance_date']) ?></td>
                                <td><?= $eq['days_until_maintenance'] ?> дн.</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-check-circle"></i>
                <p>Всё оборудование в норме</p>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- ОТГРУЗКА -->
        <div class="module-section" id="shipment-section">
            <div class="module-header">
                <div class="module-title">
                    <div class="module-icon" style="background: rgba(90, 200, 250, 0.2); color: #5ac8fa;">
                        <i class="fas fa-truck"></i>
                    </div>
                    Отгрузка
                </div>
                <div class="module-actions">
                    <a href="<?= APP_URL ?>/modules/shipment/index.php" class="btn-module btn-outline">
                        <i class="fas fa-list"></i> Все отгрузки
                    </a>
                </div>
            </div>
            
            <div class="stats-grid">
                <div class="stat-item">
                    <div class="stat-value"><?= $ordersStats['ready_orders'] ?? 0 ?></div>
                    <div class="stat-label">Готовы к отгрузке</div>
                </div>
            </div>
            
            <?php if (!empty($shipmentData['readyToShip'])): ?>
            <div>
                <h4 style="font-size: 1rem; margin-bottom: 1rem; color: var(--text-secondary);">
                    <i class="fas fa-clock"></i> Готовы к отгрузке
                </h4>
                <div class="scroll-container">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Заказ</th>
                                <th>Клиент</th>
                                <th>Дата поставки</th>
                                <th>Действие</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shipmentData['readyToShip'] as $order): ?>
                            <tr>
                                <td><?= e($order['order_number']) ?></td>
                                <td><?= e($order['customer_name']) ?></td>
                                <td><?= formatDate($order['delivery_date']) ?></td>
                                <td>
                                    <a href="<?= APP_URL ?>/modules/shipment/order_details.php?id=<?= $order['id'] ?>" class="btn-module btn-outline" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Smooth scroll for module sections
        document.querySelectorAll('.btn-module').forEach(btn => {
            btn.addEventListener('click', function(e) {
                if (this.getAttribute('href').startsWith('#')) {
                    e.preventDefault();
                    const targetId = this.getAttribute('href').substring(1);
                    const target = document.getElementById(targetId);
                    if (target) {
                        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                }
            });
        });
    </script>
</body>
</html>
