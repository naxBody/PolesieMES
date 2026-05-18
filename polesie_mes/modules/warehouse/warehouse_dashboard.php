<?php
/**
 * Панель управления для работника склада (Warehouse Keeper Dashboard)
 * PolesieMES - Система управления производством ОАО "Полесьеэлектромаш"
 * 
 * Специализированный интерфейс для кладовщика:
 * - Поступление материалов
 * - Расход материалов
 * - Просмотр остатков
 * - История движений
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

// Проверка авторизации
requireAuth();

// Проверка роли - только для складских работников
if (!hasRole(['admin', 'manager', 'warehouse_keeper'])) {
    redirectWithMessage(APP_URL . '/modules/dashboard/index.php', 'Доступ запрещён. Это раздел для складских работников.', 'error');
}

$db = getDB();
$user = getCurrentUser();

// ==========================================
// ПОЛУЧЕНИЕ ДАННЫХ ДЛЯ СКЛАДА
// ==========================================

// 1. Статистика по материалам (для компактного отображения)
$stmt = $db->query("
    SELECT 
        COUNT(*) as total_items,
        SUM(CASE WHEN current_stock <= 0 THEN 1 ELSE 0 END) as out_of_stock,
        SUM(CASE WHEN current_stock < min_stock AND current_stock > 0 THEN 1 ELSE 0 END) as low_stock,
        SUM(current_stock) as total_stock
    FROM items
    WHERE item_type = 'material'
");
$materialStats = $stmt->fetch();

// 2. Последние движения на складе
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
$recentTransactions = $stmt->fetchAll();

// 3. Материалы требующие внимания (критичные)
$stmt = $db->query("
    SELECT i.name, i.item_code, i.current_stock, i.min_stock, u.name as unit_name
    FROM items i
    LEFT JOIN dictionaries u ON i.unit_id = u.id AND u.dict_type = 'unit'
    WHERE i.item_type = 'material' AND i.current_stock <= i.min_stock
    ORDER BY i.current_stock ASC
    LIMIT 5
");
$criticalMaterials = $stmt->fetchAll();

// 4. Ближайшие поставки (заказы поставщикам в статусе "в пути" или "ожидается")
$stmt = $db->query("
    SELECT po.id, po.order_number, p.name as supplier_name, po.expected_delivery as expected_delivery_date, po.status
    FROM purchase_orders po
    LEFT JOIN partners p ON po.supplier_id = p.id
    WHERE po.status IN ('sent', 'confirmed', 'partial')
    ORDER BY po.expected_delivery ASC
    LIMIT 5
");
$upcomingDeliveries = $stmt->fetchAll();

// 5. Последние отгрузки (используем movements типа shipment)
$stmt = $db->query("
    SELECT mvt.id, mvt.reference_type, mvt.reference_id, mvt.movement_date as shipment_date, 
           p.name as customer_name, mvt.notes,
           i.name as item_name, mvt.quantity, u.name as unit_name
    FROM movements mvt
    LEFT JOIN items i ON mvt.item_id = i.id
    LEFT JOIN partners p ON mvt.partner_id = p.id
    LEFT JOIN dictionaries u ON i.unit_id = u.id AND u.dict_type = 'unit'
    WHERE mvt.movement_type = 'shipment'
    ORDER BY mvt.movement_date DESC
    LIMIT 5
");
$pendingShipments = $stmt->fetchAll();

// 6. Готовая продукция на складе
$stmt = $db->query("
    SELECT i.*, d.name as category_name, 
           u.name as unit_name,
           i.current_stock as available_stock
    FROM items i
    LEFT JOIN dictionaries d ON i.category_id = d.id AND d.dict_type = 'category'
    LEFT JOIN dictionaries u ON i.unit_id = u.id AND u.dict_type = 'unit'
    WHERE i.item_type = 'product' AND i.current_stock > 0
    ORDER BY i.name ASC
    LIMIT 10
");
$finishedGoods = $stmt->fetchAll();

// 7. Уведомления для склада (срочные задачи)
$notifications = [];

// Критически низкие запасы
if (($materialStats['out_of_stock'] ?? 0) > 0) {
    $notifications[] = [
        'type' => 'critical',
        'icon' => 'fa-exclamation-circle',
        'title' => 'Товары закончились!',
        'message' => ($materialStats['out_of_stock']) . ' позиций нет на складе',
        'color' => '#ff453a'
    ];
}

// Низкие запасы
if (($materialStats['low_stock'] ?? 0) > 0) {
    $notifications[] = [
        'type' => 'warning',
        'icon' => 'fa-exclamation-triangle',
        'title' => 'Заканчиваются материалы',
        'message' => ($materialStats['low_stock']) . ' позиций требуют пополнения',
        'color' => '#ffd60a'
    ];
}

// Поставки сегодня
$stmt = $db->query("
    SELECT COUNT(*) as count
    FROM purchase_orders po
    WHERE po.status IN ('sent', 'confirmed', 'partial')
    AND DATE(po.expected_delivery) = CURDATE()
");
$todayDeliveries = $stmt->fetch()['count'] ?? 0;
if ($todayDeliveries > 0) {
    $notifications[] = [
        'type' => 'info',
        'icon' => 'fa-truck',
        'title' => 'Поставки сегодня',
        'message' => "$todayDeliveries ожидаемых поставок на сегодня",
        'color' => '#5ac8fa'
    ];
}

// Поставки завтра
$stmt = $db->query("
    SELECT COUNT(*) as count
    FROM purchase_orders po
    WHERE po.status IN ('sent', 'confirmed', 'partial')
    AND DATE(po.expected_delivery) = DATE_ADD(CURDATE(), INTERVAL 1 DAY)
");
$tomorrowDeliveries = $stmt->fetch()['count'] ?? 0;
if ($tomorrowDeliveries > 0) {
    $notifications[] = [
        'type' => 'info',
        'icon' => 'fa-calendar-day',
        'title' => 'Поставки завтра',
        'message' => "$tomorrowDeliveries ожидаемых поставок на завтра",
        'color' => '#32ade6'
    ];
}

// Неподтверждённые отгрузки
$stmt = $db->query("
    SELECT COUNT(DISTINCT reference_id) as count
    FROM movements mvt
    WHERE mvt.movement_type = 'shipment'
    AND DATE(mvt.movement_date) = CURDATE()
");
$todayShipments = $stmt->fetch()['count'] ?? 0;
if ($todayShipments > 0) {
    $notifications[] = [
        'type' => 'success',
        'icon' => 'fa-shipping-fast',
        'title' => 'Отгрузки сегодня',
        'message' => "$todayShipments отгрузок выполнено сегодня",
        'color' => '#30d158'
    ];
}

$pageTitle = 'Склад | PolesieMES';
$currentPage = 'warehouse_dashboard';
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
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/common-style.css">
    <style>
        /* Стили для дашборда склада - обновленный дизайн */
        
        /* Панель быстрых действий - горизонтальная с выделенными кнопками */
        .quick-actions-bar {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
            align-items: stretch;
        }
        
        .btn-action-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            padding: 1.5rem 1rem;
            background: var(--glass-bg);
            backdrop-filter: var(--backdrop-blur);
            border: 2px solid var(--glass-border);
            border-radius: 16px;
            color: var(--text-primary);
            font-weight: 600;
            font-size: 1rem;
            text-decoration: none;
            transition: all 0.3s ease;
            cursor: pointer;
            min-height: 140px;
            position: relative;
            overflow: hidden;
        }
        
        .btn-action-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.1), transparent);
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        .btn-action-card:hover::before {
            opacity: 1;
        }
        
        .btn-action-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.3);
        }
        
        .btn-action-card i {
            font-size: 2.5rem;
            width: 60px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            transition: all 0.3s ease;
            z-index: 1;
        }
        
        .btn-action-card span {
            z-index: 1;
            text-align: center;
        }
        
        /* Выделенные стили для основных кнопок */
        .btn-action-card.receipt {
            border-color: rgba(48, 209, 88, 0.3);
            background: linear-gradient(135deg, rgba(48, 209, 88, 0.1), rgba(36, 169, 69, 0.05));
        }
        
        .btn-action-card.receipt i {
            background: linear-gradient(135deg, #30d158, #24a945);
            color: white;
            box-shadow: 0 4px 15px rgba(48, 209, 88, 0.4);
        }
        
        .btn-action-card.receipt:hover {
            border-color: rgba(48, 209, 88, 0.6);
            box-shadow: 0 8px 30px rgba(48, 209, 88, 0.3);
        }
        
        .btn-action-card.supply {
            border-color: rgba(90, 200, 250, 0.3);
            background: linear-gradient(135deg, rgba(90, 200, 250, 0.1), rgba(10, 132, 255, 0.05));
        }
        
        .btn-action-card.supply i {
            background: linear-gradient(135deg, #5ac8fa, #0a84ff);
            color: white;
            box-shadow: 0 4px 15px rgba(90, 200, 250, 0.4);
        }
        
        .btn-action-card.supply:hover {
            border-color: rgba(90, 200, 250, 0.6);
            box-shadow: 0 8px 30px rgba(90, 200, 250, 0.3);
        }
        
        .btn-action-card.consumption {
            border-color: rgba(255, 214, 10, 0.3);
            background: linear-gradient(135deg, rgba(255, 214, 10, 0.1), rgba(255, 159, 10, 0.05));
        }
        
        .btn-action-card.consumption i {
            background: linear-gradient(135deg, #ffd60a, #ff9f0a);
            color: #000;
            box-shadow: 0 4px 15px rgba(255, 214, 10, 0.4);
        }
        
        .btn-action-card.consumption:hover {
            border-color: rgba(255, 214, 10, 0.6);
            box-shadow: 0 8px 30px rgba(255, 214, 10, 0.3);
        }
        
        .btn-action-card.shipment {
            border-color: rgba(255, 69, 58, 0.3);
            background: linear-gradient(135deg, rgba(255, 69, 58, 0.1), rgba(255, 59, 48, 0.05));
        }
        
        .btn-action-card.shipment i {
            background: linear-gradient(135deg, #ff453a, #ff3b30);
            color: white;
            box-shadow: 0 4px 15px rgba(255, 69, 58, 0.4);
        }
        
        .btn-action-card.shipment:hover {
            border-color: rgba(255, 69, 58, 0.6);
            box-shadow: 0 8px 30px rgba(255, 69, 58, 0.3);
        }
        
        .btn-action-card.inventory {
            border-color: rgba(175, 82, 222, 0.3);
            background: linear-gradient(135deg, rgba(175, 82, 222, 0.1), rgba(191, 90, 242, 0.05));
        }
        
        .btn-action-card.inventory i {
            background: linear-gradient(135deg, #af52de, #bf5af2);
            color: white;
            box-shadow: 0 4px 15px rgba(175, 82, 222, 0.4);
        }
        
        .btn-action-card.inventory:hover {
            border-color: rgba(175, 82, 222, 0.6);
            box-shadow: 0 8px 30px rgba(175, 82, 222, 0.3);
        }
        
        .btn-action-card.history {
            border-color: rgba(142, 142, 147, 0.3);
            background: linear-gradient(135deg, rgba(142, 142, 147, 0.1), rgba(99, 99, 102, 0.05));
        }
        
        .btn-action-card.history i {
            background: linear-gradient(135deg, #8e8e93, #636366);
            color: white;
            box-shadow: 0 4px 15px rgba(142, 142, 147, 0.4);
        }
        
        .btn-action-card.history:hover {
            border-color: rgba(142, 142, 147, 0.6);
            box-shadow: 0 8px 30px rgba(142, 142, 147, 0.3);
        }
        
        /* Компактная статистика в строку */
        .stats-inline-row {
            display: flex;
            gap: 1.5rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            align-items: center;
            padding: 1rem 1.5rem;
            background: var(--glass-bg);
            backdrop-filter: var(--backdrop-blur);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
        }
        
        .stat-inline-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem 1rem;
            background: rgba(255,255,255,0.05);
            border-radius: 10px;
        }
        
        .stat-inline-value {
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        .stat-inline-label {
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        
        .materials-table-container {
            max-height: 500px;
            overflow-y: auto;
        }
        
        .badge-stock-critical {
            background: linear-gradient(135deg, #ff453a, #ff3b30);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-stock-low {
            background: linear-gradient(135deg, #ffd60a, #ff9f0a);
            color: #000;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-stock-normal {
            background: linear-gradient(135deg, #30d158, #24a945);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-stock-overstock {
            background: linear-gradient(135deg, #5ac8fa, #0a84ff);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .btn-info-custom {
            background: linear-gradient(135deg, #32ade6, #007aff);
            border: none;
            color: white;
            padding: 0.6rem 1.2rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-info-custom:hover {
            background: linear-gradient(135deg, #007aff, #005ecb);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(50, 173, 230, 0.4);
            color: white;
        }
        
        /* Секции с заголовками */
        .dashboard-section {
            margin-bottom: 2rem;
        }
        
        .section-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--glass-border);
        }
        
        .section-title {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .section-title i {
            color: var(--primary-glow);
        }
        
        /* Панель уведомлений */
        .notifications-panel {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .notification-card {
            background: var(--glass-bg);
            backdrop-filter: var(--backdrop-blur);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 1.25rem;
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        
        .notification-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(180deg, var(--notification-color), transparent);
        }
        
        .notification-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.3);
            border-color: var(--notification-color);
        }
        
        .notification-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }
        
        .notification-icon.critical {
            background: linear-gradient(135deg, #ff453a, #ff3b30);
            color: white;
            box-shadow: 0 4px 15px rgba(255, 69, 58, 0.4);
        }
        
        .notification-icon.warning {
            background: linear-gradient(135deg, #ffd60a, #ff9f0a);
            color: #000;
            box-shadow: 0 4px 15px rgba(255, 214, 10, 0.4);
        }
        
        .notification-icon.info {
            background: linear-gradient(135deg, #5ac8fa, #0a84ff);
            color: white;
            box-shadow: 0 4px 15px rgba(90, 200, 250, 0.4);
        }
        
        .notification-icon.success {
            background: linear-gradient(135deg, #30d158, #24a945);
            color: white;
            box-shadow: 0 4px 15px rgba(48, 209, 88, 0.4);
        }
        
        .notification-content {
            flex: 1;
        }
        
        .notification-title {
            font-weight: 600;
            font-size: 0.95rem;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }
        
        .notification-message {
            font-size: 0.85rem;
            color: var(--text-muted);
            line-height: 1.4;
        }
        
        .notification-badge {
            position: absolute;
            top: 1rem;
            right: 1rem;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--notification-color);
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.2); }
        }
    </style>
</head>
<body>
    <!-- Анимированный фон -->
    <div class="particles-container">
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
    </div>
    <div class="glow-overlay"></div>
    <div class="grid-overlay"></div>
    
    <!-- Навигация только для склада -->
    <nav class="navbar" id="navbar">
        <a href="<?= APP_URL ?>" class="nav-brand">
            <div class="brand-logo">
                <svg viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
            </div>
            <span class="brand-name">PolesieMES</span>
        </a>

        <ul class="nav-menu">
            <li>
                <a href="<?= APP_URL ?>/modules/warehouse/warehouse_dashboard.php" class="nav-link active">
                    <i class="fas fa-warehouse"></i>
                    Склад
                </a>
            </li>
        </ul>

        <div class="user-menu">
            <div class="user-avatar">
                <svg viewBox="0 0 24 24"><path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/></svg>
            </div>
            <div class="user-info">
                <span class="user-name"><?= e($_SESSION['full_name']) ?></span>
                <span class="user-role"><?= e(getRoleName($_SESSION['role'])) ?></span>
            </div>
            <a href="<?= APP_URL ?>/modules/auth/logout.php" class="btn-logout">
                <i class="fas fa-sign-out-alt"></i>
                Выход
            </a>
        </div>

        <button class="mobile-menu-btn" onclick="toggleMobileMenu()">
            <span></span>
            <span></span>
            <span></span>
        </button>
    </nav>

    <!-- Основной контент -->
    <div class="main-content">
        <div class="page-header">
            <div class="page-title">
                <h1><i class="fas fa-warehouse"></i> Панель управления складом</h1>
                <p>Учёт материалов и готовой продукции</p>
            </div>
        </div>

        <!-- Панель уведомлений -->
        <?php if (!empty($notifications)): ?>
        <div class="notifications-panel">
            <?php foreach ($notifications as $notification): ?>
            <div class="notification-card" style="--notification-color: <?= e($notification['color']) ?>;">
                <div class="notification-icon <?= e($notification['type']) ?>">
                    <i class="fas <?= e($notification['icon']) ?>"></i>
                </div>
                <div class="notification-content">
                    <div class="notification-title"><?= e($notification['title']) ?></div>
                    <div class="notification-message"><?= e($notification['message']) ?></div>
                </div>
                <div class="notification-badge"></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Быстрые действия - выделенные карточки -->
        <div class="quick-actions-bar">
            <a href="receipt.php" class="btn-action-card receipt">
                <i class="fas fa-truck-loading"></i>
                <span>Поступление<br><small style="font-size: 0.75rem; font-weight: 400; opacity: 0.8;">Принять товары от поставщика</small></span>
            </a>
            
            <a href="purchase_orders/index.php" class="btn-action-card supply">
                <i class="fas fa-shipping-fast"></i>
                <span>Поставки<br><small style="font-size: 0.75rem; font-weight: 400; opacity: 0.8;">Заказы поставщикам</small></span>
            </a>
            
            <a href="consumption.php" class="btn-action-card consumption">
                <i class="fas fa-dolly"></i>
                <span>Расход<br><small style="font-size: 0.75rem; font-weight: 400; opacity: 0.8;">Списание материалов</small></span>
            </a>
            
            <a href="<?= APP_URL ?>/modules/shipment/index.php" class="btn-action-card shipment">
                <i class="fas fa-truck"></i>
                <span>Отгрузка<br><small style="font-size: 0.75rem; font-weight: 400; opacity: 0.8;">Отгрузка клиентам</small></span>
            </a>
            
            <a href="inventory.php" class="btn-action-card inventory">
                <i class="fas fa-boxes"></i>
                <span>Остатки<br><small style="font-size: 0.75rem; font-weight: 400; opacity: 0.8;">Все материалы</small></span>
            </a>
            
            <a href="history.php" class="btn-action-card history">
                <i class="fas fa-history"></i>
                <span>История<br><small style="font-size: 0.75rem; font-weight: 400; opacity: 0.8;">Движения на складе</small></span>
            </a>
        </div>

        <!-- Быстрая статистика в строку -->
        <div class="stats-inline-row">
            <div class="stat-inline-item">
                <i class="fas fa-box" style="color: var(--text-muted);"></i>
                <div>
                    <div class="stat-inline-value"><?= $materialStats['total_items'] ?? 0 ?></div>
                    <div class="stat-inline-label">Всего позиций</div>
                </div>
            </div>
            <div class="stat-inline-item">
                <i class="fas fa-check-circle" style="color: var(--success-color);"></i>
                <div>
                    <div class="stat-inline-value" style="color: var(--success-color);"><?= number_format($materialStats['total_stock'] ?? 0, 0) ?></div>
                    <div class="stat-inline-label">Общий остаток</div>
                </div>
            </div>
            <div class="stat-inline-item">
                <i class="fas fa-exclamation-triangle" style="color: var(--warning-color);"></i>
                <div>
                    <div class="stat-inline-value" style="color: var(--warning-color);"><?= $materialStats['low_stock'] ?? 0 ?></div>
                    <div class="stat-inline-label">Требуют внимания</div>
                </div>
            </div>
            <div class="stat-inline-item">
                <i class="fas fa-times-circle" style="color: var(--danger-color);"></i>
                <div>
                    <div class="stat-inline-value" style="color: var(--danger-color);"><?= $materialStats['out_of_stock'] ?? 0 ?></div>
                    <div class="stat-inline-label">Нет на складе</div>
                </div>
            </div>
        </div>

        <!-- Материалы требующие внимания -->
        <?php if (!empty($criticalMaterials)): ?>
        <div class="card" id="attention-section">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-exclamation-triangle" style="color: var(--warning-color);"></i> Требуют внимания
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Наименование</th>
                                <th>Артикул</th>
                                <th>В наличии</th>
                                <th>Мин. запас</th>
                                <th>Ед. изм.</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($criticalMaterials as $material): ?>
                            <tr>
                                <td><?= e($material['name']) ?></td>
                                <td><code><?= e($material['item_code']) ?></code></td>
                                <td><strong style="color: var(--danger-color);"><?= number_format($material['current_stock'], 2) ?></strong></td>
                                <td><?= number_format($material['min_stock'], 2) ?></td>
                                <td><?= e($material['unit_name'] ?? '-') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Ближайшие поставки -->
        <?php if (!empty($upcomingDeliveries)): ?>
        <div class="card" id="deliveries-section">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-truck" style="color: var(--info-color);"></i> Ближайшие поставки
                </div>
                <a href="purchase_orders/index.php" class="btn-action">
                    <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>№ заказа</th>
                                <th>Поставщик</th>
                                <th>Ожидаемая дата</th>
                                <th>Статус</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($upcomingDeliveries as $delivery): ?>
                            <tr>
                                <td><strong><?= e($delivery['order_number']) ?></strong></td>
                                <td><?= e($delivery['supplier_name'] ?? 'Не указан') ?></td>
                                <td><?= $delivery['expected_delivery_date'] ? date('d.m.Y', strtotime($delivery['expected_delivery_date'])) : '-' ?></td>
                                <td>
                                    <span class="badge-stock badge-<?= $delivery['status'] == 'confirmed' ? 'normal' : 'warning' ?>">
                                        <?= $delivery['status'] == 'confirmed' ? 'Подтверждено' : ($delivery['status'] == 'partial' ? 'Частично' : 'В ожидании') ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="card" id="deliveries-section">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-truck" style="color: var(--info-color);"></i> Ближайшие поставки
                </div>
            </div>
            <div class="card-body" style="text-align: center; padding: 2rem;">
                <p style="color: var(--text-muted);"><i class="fas fa-check-circle" style="color: var(--success-color);"></i> Нет активных поставок</p>
                <a href="purchase_orders/index.php" class="btn-info-custom" style="margin-top: 1rem;">
                    <i class="fas fa-plus"></i> Создать заказ поставщику
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Ожидающие отгрузки -->
        <?php if (!empty($pendingShipments)): ?>
        <div class="card" id="shipments-section">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-shipping-fast" style="color: var(--danger-color);"></i> Ожидающие отгрузки
                </div>
                <a href="<?= APP_URL ?>/modules/shipment/index.php" class="btn-action">
                    <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Материал</th>
                                <th>Клиент</th>
                                <th>Количество</th>
                                <th>Дата отгрузки</th>
                                <th>Примечание</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pendingShipments as $shipment): ?>
                            <tr>
                                <td><?= e($shipment['item_name']) ?></td>
                                <td><?= e($shipment['customer_name'] ?? 'Не указан') ?></td>
                                <td><?= number_format($shipment['quantity'], 2) ?> <?= e($shipment['unit_name'] ?? 'шт.') ?></td>
                                <td><?= date('d.m.Y H:i', strtotime($shipment['shipment_date'])) ?></td>
                                <td><?= e(substr($shipment['notes'] ?? '', 0, 50)) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php else: ?>
        <div class="card" id="shipments-section">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-shipping-fast" style="color: var(--danger-color);"></i> Ожидающие отгрузки
                </div>
            </div>
            <div class="card-body" style="text-align: center; padding: 2rem;">
                <p style="color: var(--text-muted);"><i class="fas fa-check-circle" style="color: var(--success-color);"></i> Нет ожидающих отгрузок</p>
            </div>
        </div>
        <?php endif; ?>

        <!-- Готовая продукция -->
        <?php if (!empty($finishedGoods)): ?>
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-box-open"></i> Готовая продукция
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Наименование</th>
                                <th>Артикул</th>
                                <th>Категория</th>
                                <th>В наличии</th>
                                <th>Ед. изм.</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($finishedGoods as $product): ?>
                            <tr>
                                <td><?= e($product['name']) ?></td>
                                <td><code><?= e($product['item_code']) ?></code></td>
                                <td><?= e($product['category_name'] ?? '-') ?></td>
                                <td><strong><?= number_format($product['current_stock'], 2) ?></strong></td>
                                <td><?= e($product['unit_name'] ?? '-') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- История движений -->
        <div class="card" id="history-section">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-history"></i> Последние операции на складе
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Дата</th>
                                <th>Операция</th>
                                <th>Материал</th>
                                <th>Количество</th>
                                <th>Кладовщик</th>
                                <th>Примечание</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentTransactions as $transaction): ?>
                            <tr>
                                <td><?= formatDateTime($transaction['movement_date']) ?></td>
                                <td>
                                    <span class="badge bg-<?= 
                                        $transaction['movement_type'] == 'receipt' ? 'success' : 
                                        ($transaction['movement_type'] == 'consumption' ? 'warning' : 'info')
                                    ?>">
                                        <?= e($transaction['operation_name']) ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?= e($transaction['item_name']) ?></strong><br>
                                    <small class="text-muted"><?= e($transaction['item_code']) ?></small>
                                </td>
                                <td><?= number_format($transaction['quantity'], 2) ?></td>
                                <td><?= e(trim($transaction['last_name'] . ' ' . $transaction['first_name'])) ?></td>
                                <td><?= e($transaction['notes'] ?? '-') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Последние движения на складе -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-history" style="color: var(--info-color);"></i> Последние движения
                </div>
                <a href="history.php" class="btn-action">
                    <i class="fas fa-arrow-right"></i>
                </a>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Дата</th>
                                <th>Операция</th>
                                <th>Материал</th>
                                <th>Артикул</th>
                                <th>Кол-во</th>
                                <th>Сотрудник</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentTransactions as $transaction): ?>
                            <tr>
                                <td><?= date('d.m.Y H:i', strtotime($transaction['movement_date'])) ?></td>
                                <td>
                                    <span class="badge-stock badge-<?= 
                                        $transaction['movement_type'] == 'receipt' ? 'normal' : 
                                        ($transaction['movement_type'] == 'consumption' ? 'warning' : 
                                        ($transaction['movement_type'] == 'shipment' ? 'critical' : 'low')) 
                                    ?>">
                                        <?= e($transaction['operation_name']) ?>
                                    </span>
                                </td>
                                <td><?= e($transaction['item_name'] ?? '-') ?></td>
                                <td><code><?= e($transaction['item_code'] ?? '-') ?></code></td>
                                <td><strong><?= number_format($transaction['quantity'], 2) ?></strong></td>
                                <td><?= e($transaction['first_name'] . ' ' . $transaction['last_name']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

    </div> <!-- Конец main-content -->

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= APP_URL ?>/assets/js/main.js"></script>
    <script>
        // Плавная прокрутка
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({ behavior: 'smooth' });
                }
            });
        });
    </script>
</body>
</html>
