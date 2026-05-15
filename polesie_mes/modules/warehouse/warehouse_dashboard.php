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

// 1. Статистика по материалам
$stmt = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN current_stock <= 0 THEN 1 ELSE 0 END) as out_of_stock,
        SUM(CASE WHEN current_stock < min_stock AND current_stock > 0 THEN 1 ELSE 0 END) as low_stock,
        SUM(CASE WHEN current_stock >= min_stock AND current_stock <= (min_stock * 2) THEN 1 ELSE 0 END) as normal,
        SUM(CASE WHEN current_stock > (min_stock * 2) THEN 1 ELSE 0 END) as overstock
    FROM items
    WHERE item_type = 'material'
");
$materialStats = $stmt->fetch();

// 2. Материалы требующие пополнения (критичные и низкие)
$stmt = $db->query("
    SELECT i.*, d.name as category_name, u.name as unit_name, 
           (i.min_stock - i.current_stock) as shortage,
           CASE 
               WHEN i.current_stock <= 0 THEN 'critical'
               WHEN i.current_stock < i.min_stock THEN 'low'
               ELSE 'normal'
           END as stock_status
    FROM items i
    LEFT JOIN dictionaries d ON i.category_id = d.id AND d.dict_type = 'category'
    LEFT JOIN dictionaries u ON i.unit_id = u.id AND u.dict_type = 'unit'
    WHERE i.item_type = 'material' AND i.current_stock < i.min_stock
    ORDER BY shortage DESC
    LIMIT 15
");
$reorderMaterials = $stmt->fetchAll();

// 3. Последние движения на складе (только складские операции)
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
    LIMIT 20
");
$recentTransactions = $stmt->fetchAll();

// 4. Готовая продукция на складе
$stmt = $db->query("
    SELECT i.*, d.name as category_name, u.name as unit_name
    FROM items i
    LEFT JOIN dictionaries d ON i.category_id = d.id AND d.dict_type = 'category'
    LEFT JOIN dictionaries u ON i.unit_id = u.id AND u.dict_type = 'unit'
    WHERE i.item_type = 'product' AND i.current_stock > 0
    ORDER BY i.name ASC
    LIMIT 10
");
$finishedGoods = $stmt->fetchAll();

// 5. Все материалы с остатками
$stmt = $db->query("
    SELECT i.*, 
           d.name as category_name,
           u.name as unit_name,
           CASE 
               WHEN i.current_stock <= 0 THEN 'critical'
               WHEN i.current_stock < i.min_stock THEN 'low'
               WHEN i.current_stock > (i.min_stock * 2) THEN 'overstock'
               ELSE 'normal'
           END as stock_status
    FROM items i
    LEFT JOIN dictionaries d ON i.category_id = d.id AND d.dict_type = 'category'
    LEFT JOIN dictionaries u ON i.unit_id = u.id AND u.dict_type = 'unit'
    WHERE i.item_type = 'material'
    ORDER BY 
        CASE 
            WHEN i.current_stock <= 0 THEN 1
            WHEN i.current_stock < i.min_stock THEN 2
            ELSE 3
        END,
        i.name ASC
");
$allMaterials = $stmt->fetchAll();

// 6. Проблемы склада
$warehouseIssues = [];

if ($materialStats['out_of_stock'] > 0) {
    $warehouseIssues[] = [
        'type' => 'critical',
        'title' => 'Нет на складе',
        'count' => $materialStats['out_of_stock'],
        'message' => 'Материалы отсутствуют полностью',
        'recommendation' => 'Срочно оформить заказ поставщикам'
    ];
}

if ($materialStats['low_stock'] > 0) {
    $warehouseIssues[] = [
        'type' => 'warning',
        'title' => 'Низкий запас',
        'count' => $materialStats['low_stock'],
        'message' => 'Материалы ниже минимального уровня',
        'recommendation' => 'Запланировать пополнение в ближайшее время'
    ];
}

if ($materialStats['overstock'] > 0) {
    $warehouseIssues[] = [
        'type' => 'info',
        'title' => 'Избыток',
        'count' => $materialStats['overstock'],
        'message' => 'Материалы выше максимального уровня',
        'recommendation' => 'Пересмотреть нормативы или использовать в производстве'
    ];
}

// 7. Ожидаемые поставки (заказы поставщикам) с детализацией
$stmt = $db->query("
    SELECT po.*, p.name as supplier_name,
           CASE po.status
               WHEN 'draft' THEN 'Черновик'
               WHEN 'sent' THEN 'Отправлен'
               WHEN 'confirmed' THEN 'Подтвержден'
               WHEN 'partial' THEN 'Частично получен'
               WHEN 'received' THEN 'Получен'
               WHEN 'cancelled' THEN 'Отменен'
               ELSE po.status
           END as status_name,
           CASE po.priority
               WHEN 'low' THEN 'Низкий'
               WHEN 'normal' THEN 'Обычный'
               WHEN 'high' THEN 'Высокий'
               WHEN 'urgent' THEN 'Срочный'
               ELSE po.priority
           END as priority_name,
           s.first_name as created_by_first,
           s.last_name as created_by_last,
           JSON_LENGTH(po.items_json) as items_count
    FROM purchase_orders po
    LEFT JOIN partners p ON po.supplier_id = p.id
    LEFT JOIN staff s ON po.created_by = s.id
    WHERE po.status IN ('draft', 'sent', 'confirmed', 'partial')
    ORDER BY po.expected_delivery ASC
");
$incomingOrders = $stmt->fetchAll();

// 8. Поставки сегодня/на этой неделе
$stmt = $db->query("
    SELECT po.*, p.name as supplier_name,
           CASE 
               WHEN po.expected_delivery = CURDATE() THEN 'today'
               WHEN po.expected_delivery BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'week'
               ELSE 'later'
           END as delivery_time
    FROM purchase_orders po
    LEFT JOIN partners p ON po.supplier_id = p.id
    WHERE po.status IN ('confirmed', 'partial', 'sent')
      AND po.expected_delivery IS NOT NULL
      AND po.expected_delivery >= CURDATE()
    ORDER BY po.expected_delivery ASC
    LIMIT 10
");
$upcomingDeliveries = $stmt->fetchAll();

// 9. Заказы клиентов требующие отгрузки (готовая продукция)
$stmt = $db->query("
    SELECT o.*, c.name as customer_name,
           CASE o.status
               WHEN 'new' THEN 'Новый'
               WHEN 'confirmed' THEN 'Подтвержден'
               WHEN 'in_production' THEN 'В производстве'
               WHEN 'quality_check' THEN 'Контроль качества'
               WHEN 'ready' THEN 'Готов к отгрузке'
               WHEN 'shipped' THEN 'Отгружен'
               WHEN 'completed' THEN 'Завершен'
               WHEN 'cancelled' THEN 'Отменен'
               ELSE o.status
           END as status_name,
           s.first_name as manager_first,
           s.last_name as manager_last
    FROM orders o
    LEFT JOIN partners c ON o.customer_id = c.id
    LEFT JOIN staff s ON o.manager_id = s.id
    WHERE o.status IN ('ready', 'shipped')
    ORDER BY o.delivery_date ASC
    LIMIT 10
");
$readyForShipment = $stmt->fetchAll();

// 10. Производственные задания требующие материалы
$stmt = $db->query("
    SELECT pt.*, o.order_number, i.name as product_name,
           st.first_name as assigned_first, st.last_name as assigned_last,
           CASE pt.status
               WHEN 'planned' THEN 'Запланировано'
               WHEN 'in_progress' THEN 'В работе'
               WHEN 'paused' THEN 'Приостановлено'
               WHEN 'completed' THEN 'Завершено'
               WHEN 'rejected' THEN 'Отклонено'
               ELSE pt.status
           END as status_name
    FROM production_tasks pt
    LEFT JOIN orders o ON pt.order_id = o.id
    LEFT JOIN items i ON pt.product_id = i.id
    LEFT JOIN staff st ON pt.assigned_to = st.id
    WHERE pt.status IN ('planned', 'in_progress')
    ORDER BY pt.planned_start ASC
    LIMIT 10
");
$productionTasks = $stmt->fetchAll();

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
        /* Стили для дашборда склада */
        .warehouse-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .action-card {
            background: var(--glass-bg);
            backdrop-filter: var(--backdrop-blur);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 1.5rem;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
            text-decoration: none;
            color: var(--text-primary);
        }
        
        .action-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--glow-primary);
            border-color: var(--primary-glow);
        }
        
        .action-icon {
            width: 60px;
            height: 60px;
            margin: 0 auto 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 50%;
            font-size: 1.5rem;
        }
        
        .action-icon.receipt {
            background: linear-gradient(135deg, #30d158, #24a945);
        }
        
        .action-icon.consumption {
            background: linear-gradient(135deg, #ffd60a, #ff9f0a);
        }
        
        .action-icon.inventory {
            background: linear-gradient(135deg, #5ac8fa, #0a84ff);
        }
        
        .action-icon.history {
            background: linear-gradient(135deg, #bf5af2, #5e5ce6);
        }
        
        .action-title {
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .action-desc {
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
        
        .quick-stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 2rem;
        }
        
        .quick-stat-item {
            background: var(--glass-bg);
            backdrop-filter: var(--backdrop-blur);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 1rem;
            text-align: center;
        }
        
        .quick-stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        
        .quick-stat-label {
            font-size: 0.85rem;
            color: var(--text-muted);
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

        <!-- Быстрые действия -->
        <div class="warehouse-actions">
            <a href="receipt.php" class="action-card">
                <div class="action-icon receipt">
                    <i class="fas fa-truck-loading"></i>
                </div>
                <div class="action-title">Поступление</div>
                <div class="action-desc">Оприходовать материалы</div>
            </a>
            
            <a href="consumption.php" class="action-card">
                <div class="action-icon consumption">
                    <i class="fas fa-dolly"></i>
                </div>
                <div class="action-title">Расход</div>
                <div class="action-desc">Списание материалов</div>
            </a>
            
            <a href="#inventory" class="action-card" onclick="document.getElementById('inventory-section').scrollIntoView({behavior: 'smooth'})">
                <div class="action-icon inventory">
                    <i class="fas fa-boxes"></i>
                </div>
                <div class="action-title">Инвентаризация</div>
                <div class="action-desc">Просмотр остатков</div>
            </a>
            
            <a href="#history" class="action-card" onclick="document.getElementById('history-section').scrollIntoView({behavior: 'smooth'})">
                <div class="action-icon history">
                    <i class="fas fa-history"></i>
                </div>
                <div class="action-title">История</div>
                <div class="action-desc">Движения на складе</div>
            </a>
        </div>

        <!-- Быстрая статистика -->
        <div class="quick-stats-row">
            <div class="quick-stat-item">
                <div class="quick-stat-value"><?= $materialStats['total'] ?></div>
                <div class="quick-stat-label">Всего позиций</div>
            </div>
            <div class="quick-stat-item">
                <div class="quick-stat-value" style="color: var(--success-color);"><?= $materialStats['normal'] ?></div>
                <div class="quick-stat-label">Норма</div>
            </div>
            <div class="quick-stat-item">
                <div class="quick-stat-value" style="color: var(--warning-color);"><?= $materialStats['low_stock'] ?></div>
                <div class="quick-stat-label">Низкий запас</div>
            </div>
            <div class="quick-stat-item">
                <div class="quick-stat-value" style="color: var(--danger-color);"><?= $materialStats['out_of_stock'] ?></div>
                <div class="quick-stat-label">Нет на складе</div>
            </div>
            <div class="quick-stat-item">
                <div class="quick-stat-value" style="color: var(--info-color);"><?= $materialStats['overstock'] ?></div>
                <div class="quick-stat-label">Избыток</div>
            </div>
        </div>

        <!-- Проблемы и рекомендации -->
        <?php if (!empty($warehouseIssues)): ?>
        <div class="issues-section">
            <h2 class="section-title"><i class="fas fa-exclamation-triangle"></i> Проблемы и рекомендации</h2>
            <div class="issues-grid">
                <?php foreach ($warehouseIssues as $issue): ?>
                <div class="issue-card <?= $issue['type'] ?>">
                    <div class="issue-icon <?= $issue['type'] ?>">
                        <i class="fas fa-<?= $issue['type'] == 'critical' ? 'exclamation-circle' : ($issue['type'] == 'warning' ? 'exclamation-triangle' : 'info-circle') ?>"></i>
                    </div>
                    <div class="issue-content" style="flex: 1;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                            <h4><?= $issue['title'] ?></h4>
                            <span class="issue-count"><?= $issue['count'] ?></span>
                        </div>
                        <p><?= $issue['message'] ?></p>
                        <div class="issue-recommendation">
                            <i class="fas fa-lightbulb"></i> <?= $issue['recommendation'] ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Материалы требующие пополнения -->
        <?php if (!empty($reorderMaterials)): ?>
        <div class="card" id="attention-section">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-triangle-exclamation"></i> Требуют пополнения
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Материал</th>
                                <th>Артикул</th>
                                <th>Категория</th>
                                <th>Текущий остаток</th>
                                <th>Мин. запас</th>
                                <th>Недостает</th>
                                <th>Статус</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reorderMaterials as $material): ?>
                            <tr>
                                <td><strong><?= e($material['name']) ?></strong></td>
                                <td><code><?= e($material['item_code']) ?></code></td>
                                <td><?= e($material['category_name'] ?? '-') ?></td>
                                <td><?= number_format($material['current_stock'], 2) ?> <?= e($material['unit_name']) ?></td>
                                <td><?= number_format($material['min_stock'], 2) ?> <?= e($material['unit_name']) ?></td>
                                <td style="color: var(--danger-color);">-<?= number_format($material['shortage'], 2) ?> <?= e($material['unit_name']) ?></td>
                                <td>
                                    <span class="badge-stock-<?= $material['stock_status'] ?>">
                                        <?= $material['stock_status'] == 'critical' ? 'Критический' : 'Низкий' ?>
                                    </span>
                                </td>
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
