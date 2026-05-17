<?php
/**
 * Модуль управления складом - Главная страница (Dashboard)
 * PolesieMES - Система управления производством ОАО "Полесьеэлектромаш"
 * 
 * Современный интерфейс с карточками, оповещениями и визуализацией данных
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

// Проверка авторизации
requireAuth();

$db = getDB();
$user = getCurrentUser();

// Получение всех материалов с статусами
$stmt = $db->query("
    SELECT i.*, 
           d.name as category_name,
           d2.name as unit_name,
           CASE 
               WHEN i.current_stock <= 0 THEN 'critical'
               WHEN i.current_stock < i.min_stock THEN 'low'
               WHEN i.current_stock > (i.min_stock * 2) THEN 'overstock'
               ELSE 'normal'
           END as stock_status
    FROM items i
    LEFT JOIN dictionaries d ON i.category_id = d.id AND d.dict_type = 'category'
    LEFT JOIN dictionaries d2 ON i.unit_id = d2.id AND d2.dict_type = 'unit'
    WHERE i.item_type = 'material'
    ORDER BY stock_status, i.name ASC
");
$materials = $stmt->fetchAll();

// Статистика по материалам
$stmt = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN current_stock <= 0 THEN 1 ELSE 0 END) as out_of_stock,
        SUM(CASE WHEN current_stock < min_stock AND current_stock > 0 THEN 1 ELSE 0 END) as low_stock,
        SUM(CASE WHEN current_stock > (min_stock * 2) THEN 1 ELSE 0 END) as overstock,
        SUM(CASE WHEN current_stock >= min_stock AND current_stock <= (min_stock * 2) THEN 1 ELSE 0 END) as normal
    FROM items
    WHERE item_type = 'material'
");
$materialStats = $stmt->fetch();

// Материалы требующие пополнения (топ-5 для алертов)
$stmt = $db->query("
    SELECT i.*, d.name as category_name, (i.min_stock - i.current_stock) as shortage
    FROM items i
    LEFT JOIN dictionaries d ON i.category_id = d.id AND d.dict_type = 'category'
    WHERE i.item_type = 'material' AND i.current_stock < i.min_stock
    ORDER BY shortage DESC
    LIMIT 5
");
$reorderMaterials = $stmt->fetchAll();

// Последние движения на складе
$stmt = $db->query("
    SELECT mvt.*, i.name as material_name, s.first_name, s.last_name,
           mvt.movement_type as operation_type, mvt.quantity, mvt.movement_date as created_at
    FROM movements mvt
    LEFT JOIN items i ON mvt.item_id = i.id
    LEFT JOIN staff s ON mvt.employee_id = s.id
    WHERE mvt.movement_type IN ('receipt', 'consumption', 'return', 'adjustment')
    ORDER BY mvt.movement_date DESC
    LIMIT 8
");
$recentTransactions = $stmt->fetchAll();

// Готовая продукция на складе
$stmt = $db->query("
    SELECT i.*, d.name as category_name, 
           i.current_stock as available_stock
    FROM items i
    LEFT JOIN dictionaries d ON i.category_id = d.id AND d.dict_type = 'category'
    WHERE i.item_type = 'product' AND i.current_stock > 0
    ORDER BY i.name ASC
    LIMIT 8
");
$finishedGoods = $stmt->fetchAll();

// Проблемы склада
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

$pageTitle = 'Управление складом | ' . APP_NAME;
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
        /* Дополнительные стили для конкретной страницы */
        
        /* Alert Cards - Карточки оповещений */
        .alert-card {
            background: var(--bg-card);
            backdrop-filter: var(--backdrop-blur);
            border-radius: 16px;
            padding: 1.5rem;
            border: 1px solid var(--border);
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
        }
        
        .alert-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 24px rgba(0, 0, 0, 0.3);
        }
        
        .alert-card.critical {
            border-color: rgba(255, 69, 58, 0.4);
            background: linear-gradient(135deg, rgba(255, 69, 58, 0.1), transparent);
        }
        
        .alert-card.warning {
            border-color: rgba(255, 214, 10, 0.4);
            background: linear-gradient(135deg, rgba(255, 214, 10, 0.1), transparent);
        }
        
        .alert-card.info {
            border-color: rgba(90, 200, 250, 0.4);
            background: linear-gradient(135deg, rgba(90, 200, 250, 0.1), transparent);
        }
        
        .alert-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }
        
        .alert-card.critical .alert-icon {
            background: rgba(255, 69, 58, 0.2);
            color: var(--danger-color);
        }
        
        .alert-card.warning .alert-icon {
            background: rgba(255, 214, 10, 0.2);
            color: var(--warning-color);
        }
        
        .alert-card.info .alert-icon {
            background: rgba(90, 200, 250, 0.2);
            color: var(--info-color);
        }
        
        .alert-content h4 {
            font-size: 1.1rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .alert-content p {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin-bottom: 0.75rem;
        }
        
        .alert-recommendation {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.85rem;
            color: var(--text-muted);
            padding: 0.5rem 0.75rem;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 8px;
        }
        
        .alert-recommendation i {
            color: var(--primary-gradient-start);
        }
        
        /* Custom Buttons */
        .btn-warning-custom {
            background: linear-gradient(135deg, #ffd60a, #ff9f0a);
            border: none;
            color: #000;
            padding: 0.6rem 1.2rem;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-warning-custom:hover {
            background: linear-gradient(135deg, #ff9f0a, #ff8f00);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(255, 214, 10, 0.4);
            color: #000;
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
        
        .btn-secondary-custom {
            background: linear-gradient(135deg, #8e8e93, #636366);
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
        
        .btn-secondary-custom:hover {
            background: linear-gradient(135deg, #636366, #48484a);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(142, 142, 147, 0.4);
            color: white;
        }
        
        /* Transaction List */
        .transaction-list {
            list-style: none;
        }
        
        .transaction-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 12px;
            margin-bottom: 0.75rem;
            transition: all 0.3s ease;
            border: 1px solid transparent;
        }
        
        .transaction-item:hover {
            background: rgba(255, 255, 255, 0.05);
            border-color: var(--border);
            transform: translateX(5px);
        }
        
        .transaction-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        
        .transaction-icon.receipt {
            background: rgba(48, 209, 88, 0.2);
            color: var(--success-color);
        }
        
        .transaction-icon.consumption {
            background: rgba(255, 69, 58, 0.2);
            color: var(--danger-color);
        }
        
        .transaction-icon.return {
            background: rgba(255, 214, 10, 0.2);
            color: var(--warning-color);
        }
        
        .transaction-icon.adjustment {
            background: rgba(90, 200, 250, 0.2);
            color: var(--info-color);
        }
        
        .transaction-details {
            flex: 1;
        }
        
        .transaction-details h5 {
            font-size: 0.95rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .transaction-details p {
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        
        .transaction-amount {
            font-weight: 700;
            font-size: 1rem;
        }
        
        .transaction-amount.positive {
            color: var(--success-color);
        }
        
        .transaction-amount.negative {
            color: var(--danger-color);
        }
        
        .transaction-time {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-align: right;
        }
        
        /* Products Grid */
        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 1rem;
        }
        
        .product-card {
            background: var(--bg-card);
            backdrop-filter: var(--backdrop-blur);
            border-radius: 16px;
            padding: 1.25rem;
            border: 1px solid var(--border);
            transition: all 0.3s ease;
        }
        
        .product-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 32px rgba(0, 0, 0, 0.4);
            border-color: var(--border-glow);
        }
        
        .product-header {
            display: flex;
            align-items: flex-start;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .product-icon {
            width: 50px;
            height: 50px;
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.2), rgba(255, 142, 83, 0.2));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--primary-gradient-start);
            flex-shrink: 0;
        }
        
        .product-info h5 {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        
        .product-info p {
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        
        .product-stats {
            display: flex;
            justify-content: space-between;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
        }
        
        .product-stat {
            text-align: center;
        }
        
        .product-stat-value {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-gradient-start);
        }
        
        .product-stat-label {
            font-size: 0.7rem;
            color: var(--text-muted);
            text-transform: uppercase;
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
        <div class="particle"></div>
    </div>
    <div class="glow-overlay"></div>
    <div class="grid-overlay"></div>
    <!-- Navbar -->
    <nav class="navbar" id="navbar">
        <a href="<?= APP_URL ?>" class="nav-brand">
            <div class="brand-logo">
                <svg viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
            </div>
            <span class="brand-name">PolesieMES</span>
        </a>

        <ul class="nav-menu">
            <li>
                <a href="<?= APP_URL ?>/modules/dashboard/index.php" class="nav-link">
                    <i class="fas fa-chart-line"></i>
                    Главная
                </a>
            </li>

            <?php if (hasRole(['admin', 'manager'])): ?>
            <li>
                <a href="<?= APP_URL ?>/modules/orders/index.php" class="nav-link">
                    <i class="fas fa-shopping-cart"></i>
                    Заказы
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'manager', 'operator', 'warehouse_keeper'])): ?>
            <li>
                <a href="<?= APP_URL ?>/modules/production/index.php" class="nav-link">
                    <i class="fas fa-cogs"></i>
                    Производство
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'manager', 'warehouse_keeper'])): ?>
            <li>
                <a href="<?= APP_URL ?>/modules/warehouse/warehouse_dashboard.php" class="nav-link active">
                    <i class="fas fa-warehouse"></i>
                    Склад
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'manager', 'operator', 'warehouse_keeper'])): ?>
            <li>
                <a href="<?= APP_URL ?>/modules/equipment/index.php" class="nav-link">
                    <i class="fas fa-tools"></i>
                    Оборудование
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'manager', 'warehouse_keeper'])): ?>
            <li>
                <a href="<?= APP_URL ?>/modules/shipment/index.php" class="nav-link">
                    <i class="fas fa-truck-loading"></i>
                    Отгрузка
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'manager', 'technologist'])): ?>
            <li>
                <a href="<?= APP_URL ?>/modules/documents/index.php" class="nav-link">
                    <i class="fas fa-file-alt"></i>
                    Документы
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasRole('admin')): ?>
            <li>
                <a href="<?= APP_URL ?>/modules/employees/index.php" class="nav-link">
                    <i class="fas fa-users"></i>
                    Сотрудники
                </a>
            </li>
            <?php endif; ?>
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

    <!-- Main Content -->
    <div class="main-content">
        <div class="page-header">
            <div class="page-title">
                <h1><i class="fas fa-warehouse"></i> Управление складом</h1>
                <p>Контроль материалов и готовой продукции</p>
            </div>
            <?php if (hasRole(['admin', 'manager', 'warehouse_keeper'])): ?>
            <div style="display: flex; gap: 0.5rem;">
                <a href="receipt.php" class="btn-primary-custom"><i class="fas fa-truck-loading"></i> Поступление</a>
                <a href="consumption.php" class="btn-warning-custom"><i class="fas fa-dolly"></i> Расход</a>
                <a href="inventory.php" class="btn-info-custom"><i class="fas fa-boxes"></i> Остатки</a>
                <a href="history.php" class="btn-secondary-custom"><i class="fas fa-history"></i> История</a>
            </div>
            <?php endif; ?>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= $materialStats['total'] ?></div>
                <div class="stat-label">Всего позиций</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--success-color);"><?= $materialStats['normal'] ?></div>
                <div class="stat-label">Норма</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--warning-color);"><?= $materialStats['low_stock'] ?></div>
                <div class="stat-label">Низкий запас</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--danger-color);"><?= $materialStats['out_of_stock'] ?></div>
                <div class="stat-label">Нет на складе</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--info-color);"><?= $materialStats['overstock'] ?></div>
                <div class="stat-label">Избыток</div>
            </div>
        </div>

        <!-- Issues & Recommendations -->
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

        <!-- Materials Requiring Attention -->
        <?php if (!empty($reorderMaterials)): ?>
        <div class="card">
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
                                <th>Категория</th>
                                <th>Текущий остаток</th>
                                <th>Мин. запас</th>
                                <th>Недостает</th>
                                <th>Статус</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reorderMaterials as $material): ?>
                            <tr>
                                <td><strong><?= e($material['name']) ?></strong></td>
                                <td><?= e($material['category_name'] ?? '-') ?></td>
                                <td><?= number_format($material['current_stock'], 2) ?> <?= e($material['unit_name']) ?></td>
                                <td><?= number_format($material['min_stock'], 2) ?> <?= e($material['unit_name']) ?></td>
                                <td style="color: var(--danger-color);">-<?= number_format($material['shortage'], 2) ?> <?= e($material['unit_name']) ?></td>
                                <td>
                                    <span class="badge-stock badge-<?= $material['stock_status'] ?>">
                                        <?= $material['stock_status'] == 'critical' ? 'Критический' : 'Низкий' ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 0.5rem;">
                                        <a href="edit.php?id=<?= $material['id'] ?>" class="btn-primary-custom" style="padding: 0.5rem 1rem; font-size: 0.875rem;">
                                            <i class="fas fa-edit"></i> Редактировать
                                        </a>
                                        <a href="order.php?id=<?= $material['id'] ?>" class="btn-warning-custom" style="padding: 0.5rem 1rem; font-size: 0.875rem;">
                                            <i class="fas fa-cart-plus"></i> Заказать
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- All Materials -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-boxes"></i> Все материалы
                </div>
                <div>
                    <button class="btn-secondary-custom" onclick="location.reload()" style="padding: 0.5rem 1rem; font-size: 0.875rem;">
                        <i class="fas fa-sync"></i> Обновить
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Название</th>
                                <th>Категория</th>
                                <th>Остаток</th>
                                <th>Ед. изм.</th>
                                <th>Мин/Макс</th>
                                <th>Заполненность</th>
                                <th>Статус</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($materials as $material): ?>
                            <?php
                            $maxStock = $material['min_stock'] * 2;
                            $fillPercent = $maxStock > 0
                                ? min(100, ($material['current_stock'] / $maxStock) * 100)
                                : 0;
                            ?>
                            <tr>
                                <td><strong><?= e($material['name']) ?></strong></td>
                                <td><?= e($material['category_name'] ?? '-') ?></td>
                                <td><?= number_format($material['current_stock'], 2) ?></td>
                                <td><?= e($material['unit_name'] ?? 'шт.') ?></td>
                                <td><?= number_format($material['min_stock'], 0) ?> / <?= number_format($maxStock, 0) ?></td>
                                <td style="width: 150px;">
                                    <div class="progress-bar-custom">
                                        <div class="progress-fill <?= $material['stock_status'] ?>" style="width: <?= $fillPercent ?>%"></div>
                                    </div>
                                    <small style="color: var(--text-secondary);"><?= round($fillPercent) ?>%</small>
                                </td>
                                <td>
                                    <span class="badge-stock badge-<?= $material['stock_status'] ?>">
                                        <?= $material['stock_status'] == 'normal' ? 'Норма' : 
                                            ($material['stock_status'] == 'critical' ? 'Нет' : 
                                            ($material['stock_status'] == 'low' ? 'Низкий' : 'Избыток')) ?>
                                    </span>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 0.5rem;">
                                        <a href="view.php?id=<?= $material['id'] ?>" class="btn-info-custom" style="padding: 0.5rem 1rem; font-size: 0.875rem;">
                                            <i class="fas fa-eye"></i> Просмотр
                                        </a>
                                        <a href="edit.php?id=<?= $material['id'] ?>" class="btn-primary-custom" style="padding: 0.5rem 1rem; font-size: 0.875rem;">
                                            <i class="fas fa-edit"></i> Редактировать
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Recent Transactions -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-history"></i> Последние операции
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Дата</th>
                                <th>Материал</th>
                                <th>Тип операции</th>
                                <th>Количество</th>
                                <th>Пользователь</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentTransactions as $transaction): ?>
                            <tr>
                                <td><?= date('d.m.Y H:i', strtotime($transaction['created_at'])) ?></td>
                                <td><?= e($transaction['material_name']) ?></td>
                                <td>
                                    <span class="badge-stock badge-<?= $transaction['operation_type'] == 'in' ? 'normal' : 'warning' ?>">
                                        <?= $transaction['operation_type'] == 'in' ? 'Приход' : 'Расход' ?>
                                    </span>
                                </td>
                                <td style="color: <?= $transaction['operation_type'] == 'in' ? 'var(--success-color)' : 'var(--danger-color)' ?>;">
                                    <?= $transaction['operation_type'] == 'in' ? '+' : '-' ?><?= number_format($transaction['quantity'], 2) ?>
                                </td>
                                <td><?= e($transaction['first_name'] . ' ' . $transaction['last_name']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mobile menu toggle
        function toggleMobileMenu() {
            const navMenu = document.querySelector('.nav-menu');
            navMenu.style.display = navMenu.style.display === 'flex' ? 'none' : 'flex';
        }

        // Scroll effect for navbar
        window.addEventListener('scroll', () => {
            const navbar = document.getElementById('navbar');
            if (window.scrollY > 50) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });
    </script>
</body>
</html>
