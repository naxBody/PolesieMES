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

        <!-- Быстрые действия - выделенные карточки -->
        <div class="quick-actions-bar">
            <a href="receipt.php" class="btn-action-card receipt">
                <i class="fas fa-truck-loading"></i>
                <span>Поступление<br><small style="font-size: 0.75rem; font-weight: 400; opacity: 0.8;">Принять товары от поставщика</small></span>
            </a>
            
            <a href="#incoming" class="btn-action-card supply" onclick="document.getElementById('incoming-orders-section').scrollIntoView({behavior: 'smooth'})">
                <i class="fas fa-shipping-fast"></i>
                <span>Поставки<br><small style="font-size: 0.75rem; font-weight: 400; opacity: 0.8;">Ожидаемые поставки</small></span>
            </a>
            
            <a href="consumption.php" class="btn-action-card consumption">
                <i class="fas fa-dolly"></i>
                <span>Расход<br><small style="font-size: 0.75rem; font-weight: 400; opacity: 0.8;">Списание материалов</small></span>
            </a>
            
            <a href="#shipment" class="btn-action-card shipment" onclick="document.getElementById('ready-shipment-section').scrollIntoView({behavior: 'smooth'})">
                <i class="fas fa-truck"></i>
                <span>Отгрузка<br><small style="font-size: 0.75rem; font-weight: 400; opacity: 0.8;">Отгрузка клиентам</small></span>
            </a>
            
            <a href="#inventory" class="btn-action-card inventory" onclick="document.getElementById('inventory-section').scrollIntoView({behavior: 'smooth'})">
                <i class="fas fa-boxes"></i>
                <span>Остатки<br><small style="font-size: 0.75rem; font-weight: 400; opacity: 0.8;">Все материалы</small></span>
            </a>
            
            <a href="#history" class="btn-action-card history" onclick="document.getElementById('history-section').scrollIntoView({behavior: 'smooth'})">
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

        <!-- Все материалы -->
        <div class="card" id="inventory-section">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-boxes"></i> Все материалы на складе
                </div>
                <div>
                    <button class="btn-action" onclick="location.reload()">
                        <i class="fas fa-sync"></i>
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="materials-table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Название</th>
                                <th>Артикул</th>
                                <th>Категория</th>
                                <th>Остаток</th>
                                <th>Ед. изм.</th>
                                <th>Мин. запас</th>
                                <th>Статус</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allMaterials as $material): ?>
                            <tr>
                                <td><?= e($material['name']) ?></td>
                                <td><code><?= e($material['item_code']) ?></code></td>
                                <td><?= e($material['category_name'] ?? '-') ?></td>
                                <td><strong><?= number_format($material['current_stock'], 2) ?></strong></td>
                                <td><?= e($material['unit_name'] ?? '-') ?></td>
                                <td><?= number_format($material['min_stock'], 2) ?></td>
                                <td>
                                    <span class="badge-stock-<?= $material['stock_status'] ?>">
                                        <?php
                                        switch($material['stock_status']) {
                                            case 'critical': echo 'Нет на складе'; break;
                                            case 'low': echo 'Низкий запас'; break;
                                            case 'overstock': echo 'Избыток'; break;
                                            default: echo 'Норма';
                                        }
                                        ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

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

        <!-- Ожидаемые поставки -->
        <?php if (!empty($incomingOrders)): ?>
        <div class="card" id="incoming-section">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-truck-moving"></i> Ожидаемые поставки
                </div>
                <span class="badge bg-info"><?= count($incomingOrders) ?> заказов</span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>№ заказа</th>
                                <th>Поставщик</th>
                                <th>Статус</th>
                                <th>Приоритет</th>
                                <th>Ожидается</th>
                                <th>Создан</th>
                                <th>Примечание</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($incomingOrders as $order): ?>
                            <tr>
                                <td><strong><?= e($order['order_number']) ?></strong></td>
                                <td><?= e($order['supplier_name']) ?></td>
                                <td>
                                    <span class="badge bg-<?=
                                        $order['status'] == 'confirmed' ? 'success' :
                                        ($order['status'] == 'partial' ? 'warning' :
                                        ($order['status'] == 'sent' ? 'info' : 'secondary'))
                                    ?>">
                                        <?= e($order['status_name']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?=
                                        $order['priority'] == 'urgent' ? 'danger' :
                                        ($order['priority'] == 'high' ? 'warning' : 'secondary')
                                    ?>">
                                        <?= e($order['priority_name']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                    $deliveryDate = strtotime($order['expected_delivery']);
                                    $today = time();
                                    $diffDays = floor(($deliveryDate - $today) / 86400);
                                    ?>
                                    <strong><?= date('d.m.Y', $deliveryDate) ?></strong>
                                    <?php if ($diffDays == 0): ?>
                                        <span class="badge bg-danger">Сегодня!</span>
                                    <?php elseif ($diffDays == 1): ?>
                                        <span class="badge bg-warning">Завтра</span>
                                    <?php elseif ($diffDays > 0 && $diffDays <= 7): ?>
                                        <span class="badge bg-info">Через <?= $diffDays ?> дн.</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= date('d.m.Y', strtotime($order['order_date'])) ?></td>
                                <td><?= e($order['notes'] ?? '-') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Ближайшие доставки (сегодня и на неделе) -->
        <?php if (!empty($upcomingDeliveries)): ?>
        <div class="card" id="deliveries-section">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-calendar-check"></i> Ближайшие доставки
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Дата</th>
                                <th>Заказ</th>
                                <th>Поставщик</th>
                                <th>Статус</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($upcomingDeliveries as $delivery): ?>
                            <tr class="<?= $delivery['delivery_time'] == 'today' ? 'table-warning' : '' ?>">
                                <td>
                                    <strong><?= date('d.m.Y', strtotime($delivery['expected_delivery'])) ?></strong>
                                    <?php if ($delivery['delivery_time'] == 'today'): ?>
                                        <br><span class="badge bg-danger">СЕГОДНЯ</span>
                                    <?php elseif ($delivery['delivery_time'] == 'week'): ?>
                                        <br><small class="text-muted">на этой неделе</small>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($delivery['order_number']) ?></td>
                                <td><?= e($delivery['supplier_name']) ?></td>
                                <td>
                                    <span class="badge bg-<?=
                                        $delivery['status'] == 'confirmed' ? 'success' :
                                        ($delivery['status'] == 'partial' ? 'warning' : 'info')
                                    ?>">
                                        <?= e($delivery['status_name']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($delivery['delivery_time'] == 'today' || $delivery['status'] == 'partial'): ?>
                                    <a href="receipt.php?order_id=<?= $delivery['id'] ?>" class="btn-action btn-sm">
                                        <i class="fas fa-download"></i> Принять
                                    </a>
                                    <?php else: ?>
                                    <span class="text-muted">Ожидание</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Заказы готовые к отгрузке -->
        <?php if (!empty($readyForShipment)): ?>
        <div class="card" id="shipment-section">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-shipping-fast"></i> Готовы к отгрузке
                </div>
                <span class="badge bg-success"><?= count($readyForShipment) ?> заказов</span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>№ заказа</th>
                                <th>Клиент</th>
                                <th>Статус</th>
                                <th>Дата отгрузки</th>
                                <th>Менеджер</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($readyForShipment as $order): ?>
                            <tr>
                                <td><strong><?= e($order['order_number']) ?></strong></td>
                                <td><?= e($order['customer_name']) ?></td>
                                <td>
                                    <span class="badge bg-<?=
                                        $order['status'] == 'ready' ? 'success' : 'info'
                                    ?>">
                                        <?= e($order['status_name']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                    $shipDate = strtotime($order['delivery_date']);
                                    $today = time();
                                    $diffDays = floor(($shipDate - $today) / 86400);
                                    ?>
                                    <strong><?= date('d.m.Y', $shipDate) ?></strong>
                                    <?php if ($diffDays == 0): ?>
                                        <span class="badge bg-danger">Сегодня!</span>
                                    <?php elseif ($diffDays == 1): ?>
                                        <span class="badge bg-warning">Завтра</span>
                                    <?php elseif ($diffDays > 0 && $diffDays <= 7): ?>
                                        <span class="badge bg-info">Через <?= $diffDays ?> дн.</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= e(trim($order['manager_last'] . ' ' . $order['manager_first'])) ?></td>
                                <td>
                                    <a href="<?= APP_URL ?>/modules/shipment/ship.php?order_id=<?= $order['id'] ?>" class="btn-action btn-sm">
                                        <i class="fas fa-truck-loading"></i> Отгрузить
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Производственные задания -->
        <?php if (!empty($productionTasks)): ?>
        <div class="card" id="production-section">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-cogs"></i> Активные производственные задания
                </div>
                <span class="badge bg-primary"><?= count($productionTasks) ?> заданий</span>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>№ задания</th>
                                <th>Заказ</th>
                                <th>Продукция</th>
                                <th>Этап</th>
                                <th>Статус</th>
                                <th>План начало</th>
                                <th>Ответственный</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($productionTasks as $task): ?>
                            <tr>
                                <td><strong><?= e($task['task_number']) ?></strong></td>
                                <td><?= e($task['order_number'] ?? '-') ?></td>
                                <td><?= e($task['product_name'] ?? '-') ?></td>
                                <td><?= e($task['stage_name'] ?? '-') ?></td>
                                <td>
                                    <span class="badge bg-<?=
                                        $task['status'] == 'in_progress' ? 'warning' : 'secondary'
                                    ?>">
                                        <?= e($task['status_name']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($task['planned_start']): ?>
                                    <?= date('d.m.Y H:i', strtotime($task['planned_start'])) ?>
                                    <?php else: ?>
                                    -
                                    <?php endif; ?>
                                </td>
                                <td><?= e(trim($task['assigned_last'] . ' ' . $task['assigned_first']) ?? '-') ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Мобильное меню -->
    <div class="mobile-menu" id="mobileMenu">
        <a href="<?= APP_URL ?>/modules/warehouse/warehouse_dashboard.php" class="mobile-nav-link active">
            <i class="fas fa-warehouse"></i> Склад
        </a>
        <a href="receipt.php" class="mobile-nav-link">
            <i class="fas fa-truck-loading"></i> Поступление
        </a>
        <a href="consumption.php" class="mobile-nav-link">
            <i class="fas fa-dolly"></i> Расход
        </a>
        <a href="<?= APP_URL ?>/modules/auth/logout.php" class="mobile-nav-link">
            <i class="fas fa-sign-out-alt"></i> Выход
        </a>
    </div>

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
