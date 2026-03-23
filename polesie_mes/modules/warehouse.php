<?php
/**
 * Модуль управления складом - Главная страница
 * PolesieMES - Система управления производством ОАО "Полесьеэлектромаш"
 * 
 * Контроль материалов, комплектующих, готовой продукции
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/helpers.php';

// Проверка авторизации
requireAuth();

$db = getDB();
$user = getCurrentUser();

// Получение всех материалов с статусами
$stmt = $db->query("
    SELECT m.*, 
           c.name as category_name,
           u.name as unit_name,
           CASE 
               WHEN m.current_stock <= 0 THEN 'critical'
               WHEN m.current_stock < m.min_stock THEN 'low'
               WHEN m.current_stock > m.max_stock THEN 'overstock'
               ELSE 'normal'
           END as stock_status
    FROM materials m
    LEFT JOIN material_categories c ON m.category_id = c.id
    LEFT JOIN units u ON m.unit_id = u.id
    ORDER BY stock_status, m.name ASC
");
$materials = $stmt->fetchAll();

// Статистика по материалам
$stmt = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN current_stock <= 0 THEN 1 ELSE 0 END) as out_of_stock,
        SUM(CASE WHEN current_stock < min_stock AND current_stock > 0 THEN 1 ELSE 0 END) as low_stock,
        SUM(CASE WHEN current_stock > max_stock THEN 1 ELSE 0 END) as overstock,
        SUM(CASE WHEN current_stock >= min_stock AND current_stock <= max_stock THEN 1 ELSE 0 END) as normal
    FROM materials
");
$materialStats = $stmt->fetch();

// Материалы требующие пополнения
$stmt = $db->query("
    SELECT m.*, c.name as category_name, (m.min_stock - m.current_stock) as shortage
    FROM materials m
    LEFT JOIN material_categories c ON m.category_id = c.id
    WHERE m.current_stock < m.min_stock
    ORDER BY shortage DESC
    LIMIT 10
");
$reorderMaterials = $stmt->fetchAll();

// Последние движения на складе
$stmt = $db->query("
    SELECT mt.*, m.name as material_name, e.first_name, e.last_name,
           mt.operation_type, mt.quantity, mt.created_at
    FROM material_transactions mt
    LEFT JOIN materials m ON mt.material_id = m.id
    LEFT JOIN employees e ON mt.user_id = e.id
    ORDER BY mt.created_at DESC
    LIMIT 10
");
$recentTransactions = $stmt->fetchAll();

// Готовая продукция на складе
$stmt = $db->query("
    SELECT p.*, c.name as category_name, 
           SUM(oi.quantity - COALESCE(shipped.quantity, 0)) as available_stock
    FROM products p
    LEFT JOIN product_categories c ON p.category_id = c.id
    LEFT JOIN order_items oi ON p.id = oi.product_id
    LEFT JOIN orders o ON oi.order_id = o.id
    LEFT JOIN (
        SELECT order_item_id, SUM(quantity) as quantity
        FROM shipments
        GROUP BY order_item_id
    ) shipped ON oi.id = shipped.order_item_id
    WHERE o.status IN ('completed', 'ready')
    GROUP BY p.id
    HAVING available_stock > 0
    LIMIT 10
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
    
    <style>
        :root {
            --primary-gradient-start: #FF6B6B;
            --primary-gradient-end: #FF8E53;
            --primary-glow: rgba(255, 107, 107, 0.4);
            --bg-dark: #0a0a0f;
            --bg-card: rgba(20, 20, 30, 0.6);
            --border: rgba(255, 255, 255, 0.1);
            --text-primary: #ffffff;
            --text-secondary: rgba(255, 255, 255, 0.7);
            --gradient-primary: linear-gradient(135deg, #FF6B6B 0%, #FF8E53 100%);
            --glass-bg: rgba(255, 255, 255, 0.03);
            --backdrop-blur: blur(20px);
            --success-color: #30d158;
            --warning-color: #ffd60a;
            --danger-color: #ff453a;
            --info-color: #5ac8fa;
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Outfit', sans-serif;
            background: linear-gradient(180deg, #0a0a0f 0%, #12121a 100%);
            min-height: 100vh;
            color: var(--text-primary);
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
            background: rgba(10, 10, 15, 0.8);
            backdrop-filter: var(--backdrop-blur);
            border-bottom: 1px solid var(--border);
        }
        
        .nav-brand {
            display: flex;
            align-items: center;
            gap: 1rem;
            text-decoration: none;
            color: var(--text-primary);
        }
        
        .brand-logo {
            width: 48px;
            height: 48px;
            background: var(--gradient-primary);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 0 30px var(--primary-glow);
        }
        
        .brand-logo svg { width: 28px; height: 28px; fill: white; }
        
        .brand-name {
            font-size: 1.4rem;
            font-weight: 700;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .nav-menu {
            display: flex;
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
        
        .nav-link:hover, .nav-link.active { color: var(--text-primary); }
        
        .user-menu {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .btn-logout {
            padding: 0.5rem 1rem;
            background: var(--glass-bg);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--text-primary);
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .btn-logout:hover {
            background: rgba(255, 107, 107, 0.2);
            border-color: var(--primary-gradient-start);
        }
        
        /* Main Content */
        .main-content {
            padding: 6rem 2rem 2rem;
            max-width: 1600px;
            margin: 0 auto;
        }
        
        .page-header {
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .page-title h1 {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
        }
        
        .page-title p { color: var(--text-secondary); }
        
        .btn-primary-custom {
            padding: 0.75rem 1.5rem;
            background: var(--gradient-primary);
            border: none;
            border-radius: 12px;
            color: white;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }
        
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(255, 107, 107, 0.5);
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: var(--bg-card);
            border-radius: 16px;
            padding: 1.5rem;
            border: 1px solid var(--border);
            backdrop-filter: var(--backdrop-blur);
        }
        
        .stat-value {
            font-size: 2.5rem;
            font-weight: 800;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .stat-label { color: var(--text-secondary); font-size: 0.9rem; }
        
        /* Issues Section */
        .issues-section {
            margin-bottom: 2rem;
        }
        
        .section-title {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .issues-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
        }
        
        .issue-card {
            background: var(--bg-card);
            border-radius: 12px;
            padding: 1.25rem;
            border: 1px solid var(--border);
            display: flex;
            gap: 1rem;
            align-items: flex-start;
        }
        
        .issue-card.critical { border-left: 4px solid var(--danger-color); }
        .issue-card.warning { border-left: 4px solid var(--warning-color); }
        .issue-card.info { border-left: 4px solid var(--info-color); }
        
        .issue-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .issue-icon.critical { background: rgba(255, 69, 58, 0.2); color: var(--danger-color); }
        .issue-icon.warning { background: rgba(255, 214, 10, 0.2); color: var(--warning-color); }
        .issue-icon.info { background: rgba(90, 200, 250, 0.2); color: var(--info-color); }
        
        .issue-content h4 {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .issue-content p {
            color: var(--text-secondary);
            font-size: 0.875rem;
            margin-bottom: 0.5rem;
        }
        
        .issue-recommendation {
            background: var(--glass-bg);
            padding: 0.5rem 0.75rem;
            border-radius: 8px;
            font-size: 0.8rem;
            color: var(--text-secondary);
        }
        
        .issue-count {
            background: var(--gradient-primary);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.875rem;
            font-weight: 700;
        }
        
        /* Cards */
        .card {
            background: var(--bg-card);
            border-radius: 16px;
            border: 1px solid var(--border);
            overflow: hidden;
            margin-bottom: 2rem;
        }
        
        .card-header {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-title {
            font-size: 1.1rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .card-body { padding: 1.5rem; }
        
        /* Table */
        .table-responsive { overflow-x: auto; }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table th, .table td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--border);
        }
        
        .table th {
            color: var(--text-secondary);
            font-weight: 600;
            font-size: 0.875rem;
            text-transform: uppercase;
        }
        
        .table tr:hover { background: var(--glass-bg); }
        
        /* Badges */
        .badge-stock {
            padding: 0.35rem 0.75rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-stock.critical { background: rgba(255, 69, 58, 0.2); color: var(--danger-color); }
        .badge-stock.low { background: rgba(255, 214, 10, 0.2); color: var(--warning-color); }
        .badge-stock.normal { background: rgba(48, 209, 88, 0.2); color: var(--success-color); }
        .badge-stock.overstock { background: rgba(90, 200, 250, 0.2); color: var(--info-color); }
        
        .progress-bar-custom {
            height: 8px;
            background: var(--glass-bg);
            border-radius: 4px;
            overflow: hidden;
            margin-top: 0.5rem;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }
        
        .progress-fill.critical { background: var(--danger-color); }
        .progress-fill.warning { background: var(--warning-color); }
        .progress-fill.normal { background: var(--success-color); }
        
        .btn-action {
            padding: 0.5rem;
            background: var(--glass-bg);
            border: 1px solid var(--border);
            border-radius: 6px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 32px;
            height: 32px;
        }
        
        .btn-action:hover {
            background: var(--gradient-primary);
            border-color: var(--primary-gradient-start);
            color: white;
        }
    </style>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar">
        <a href="<?= APP_URL ?>/modules/dashboard.php" class="nav-brand">
            <div class="brand-logo">
                <svg viewBox="0 0 24 24" fill="white"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
            </div>
            <span class="brand-name">PolesieMES</span>
        </a>
        
        <ul class="nav-menu">
            <li><a href="<?= APP_URL ?>/modules/dashboard.php" class="nav-link"><i class="fas fa-home"></i> Главная</a></li>
            <li><a href="<?= APP_URL ?>/modules/orders.php" class="nav-link"><i class="fas fa-shopping-cart"></i> Заказы</a></li>
            <li><a href="<?= APP_URL ?>/modules/production.php" class="nav-link"><i class="fas fa-industry"></i> Производство</a></li>
            <li><a href="<?= APP_URL ?>/modules/warehouse.php" class="nav-link active"><i class="fas fa-warehouse"></i> Склад</a></li>
            <li><a href="<?= APP_URL ?>/modules/equipment.php" class="nav-link"><i class="fas fa-tools"></i> Оборудование</a></li>
            <li><a href="<?= APP_URL ?>/modules/gost_docs.php" class="nav-link"><i class="fas fa-file-contract"></i> ГОСТ Документы</a></li>
        </ul>
        
        <div class="user-menu">
            <span style="color: var(--text-secondary);"><?= e(getCurrentUser()['username']) ?></span>
            <a href="<?= APP_URL ?>/modules/auth/logout.php" class="btn-logout">Выход</a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <div class="page-header">
            <div class="page-title">
                <h1><i class="fas fa-warehouse"></i> Управление складом</h1>
                <p>Контроль материалов и готовой продукции</p>
            </div>
            <?php if (hasRole(['admin', 'manager', 'storekeeper'])): ?>
            <a href="create.php" class="btn-primary-custom">
                <i class="fas fa-plus"></i> Поступление
            </a>
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
                                    <a href="edit.php?id=<?= $material['id'] ?>" class="btn-action">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="order.php?id=<?= $material['id'] ?>" class="btn-action">
                                        <i class="fas fa-cart-plus"></i>
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

        <!-- All Materials -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-boxes"></i> Все материалы
                </div>
                <div>
                    <button class="btn-action" onclick="location.reload()">
                        <i class="fas fa-sync"></i>
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
                            $fillPercent = $material['max_stock'] > 0 
                                ? min(100, ($material['current_stock'] / $material['max_stock']) * 100) 
                                : 0;
                            ?>
                            <tr>
                                <td><strong><?= e($material['name']) ?></strong></td>
                                <td><?= e($material['category_name'] ?? '-') ?></td>
                                <td><?= number_format($material['current_stock'], 2) ?></td>
                                <td><?= e($material['unit_name'] ?? 'шт.') ?></td>
                                <td><?= number_format($material['min_stock'], 0) ?> / <?= number_format($material['max_stock'], 0) ?></td>
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
                                    <a href="view.php?id=<?= $material['id'] ?>" class="btn-action">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <a href="edit.php?id=<?= $material['id'] ?>" class="btn-action">
                                        <i class="fas fa-edit"></i>
                                    </a>
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
</body>
</html>
