<?php
/**
 * Модуль управления складом - Главная страница
 * PolesieMES - Система управления производством ОАО "Полесьеэлектромаш"
 * 
 * Контроль материалов, комплектующих, готовой продукции
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
    
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/common-style.css">
    <style>
        /* Дополнительные стили для конкретной страницы */
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
    <nav class="navbar">
        <a href="<?= APP_URL ?>/modules/dashboard/index.php" class="nav-brand">
            <div class="brand-logo">
                <svg viewBox="0 0 24 24" fill="white"><path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/></svg>
            </div>
            <span class="brand-name">PolesieMES</span>
        </a>
        
        <ul class="nav-menu">
            <li><a href="<?= APP_URL ?>/modules/dashboard/index.php" class="nav-link"><i class="fas fa-home"></i> Главная</a></li>
            <li><a href="<?= APP_URL ?>/modules/orders/index.php" class="nav-link"><i class="fas fa-shopping-cart"></i> Заказы</a></li>
            <li><a href="<?= APP_URL ?>/modules/production/index.php" class="nav-link"><i class="fas fa-industry"></i> Производство</a></li>
            <li><a href="<?= APP_URL ?>/modules/warehouse/index.php" class="nav-link active"><i class="fas fa-warehouse"></i> Склад</a></li>
            <li><a href="<?= APP_URL ?>/modules/equipment/index.php" class="nav-link"><i class="fas fa-tools"></i> Оборудование</a></li>
            <li><a href="<?= APP_URL ?>/modules/gost_docs/index.php" class="nav-link"><i class="fas fa-file-contract"></i> ГОСТ Документы</a></li>
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
