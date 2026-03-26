<?php
/**
 * Модуль отгрузки - Главная страница
 * PolesieMES - Система управления производством ОАО "Полесьеэлектромаш"
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

// Проверка авторизации
requireAuth();

$db = getDB();
$user = getCurrentUser();

// Получение готовых к отгрузке заказов
$stmt = $db->query("
    SELECT o.*, c.name as customer_name, c.inn, c.address, c.phone as customer_phone, c.email,
           s.first_name as manager_first_name, s.last_name as manager_last_name,
           DATEDIFF(NOW(), o.delivery_date) as days_since_delivery
    FROM orders o
    LEFT JOIN partners c ON o.customer_id = c.id
    LEFT JOIN staff s ON o.manager_id = s.id
    WHERE o.status IN ('ready', 'shipped')
    ORDER BY o.delivery_date ASC
");
$shipmentOrders = $stmt->fetchAll();

// Статистика по отгрузкам
$stmt = $db->query("
    SELECT 
        status,
        COUNT(*) as count,
        SUM(total_amount) as total_amount
    FROM orders
    WHERE status IN ('ready', 'shipped', 'completed')
    GROUP BY status
");
$shipmentStats = [];
while ($row = $stmt->fetch()) {
    $shipmentStats[$row['status']] = $row;
}

// Завершенные отгрузки за месяц
$stmt = $db->query("
    SELECT COUNT(*) as completed_count, SUM(total_amount) as total_amount
    FROM orders
    WHERE status = 'completed'
    AND MONTH(updated_at) = MONTH(CURRENT_DATE())
    AND YEAR(updated_at) = YEAR(CURRENT_DATE())
");
$monthlyStats = $stmt->fetch();

// Все отгрузки с историей
$stmt = $db->query("
    SELECT o.*, c.name as customer_name, 
           DATE_FORMAT(o.updated_at, '%d.%m.%Y %H:%i') as last_update
    FROM orders o
    LEFT JOIN partners c ON o.customer_id = c.id
    WHERE o.status IN ('ready', 'shipped', 'completed')
    ORDER BY o.updated_at DESC
    LIMIT 50
");
$shipmentHistory = $stmt->fetchAll();

$pageTitle = 'Отгрузка продукции | ' . APP_NAME;
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
            <li><a href="<?= APP_URL ?>/modules/dashboard/index.php" class="nav-link"><i class="fas fa-chart-line"></i> Главная</a></li>
            <?php if (hasRole(['admin', 'manager'])): ?>
            <li><a href="<?= APP_URL ?>/modules/orders/index.php" class="nav-link"><i class="fas fa-shopping-cart"></i> Заказы</a></li>
            <?php endif; ?>
            <?php if (hasRole(['admin', 'manager', 'technologist', 'operator'])): ?>
            <li><a href="<?= APP_URL ?>/modules/production/index.php" class="nav-link"><i class="fas fa-cogs"></i> Производство</a></li>
            <?php endif; ?>
            <?php if (hasRole(['admin', 'manager', 'warehouse_manager'])): ?>
            <li><a href="<?= APP_URL ?>/modules/warehouse/index.php" class="nav-link"><i class="fas fa-warehouse"></i> Склад</a></li>
            <?php endif; ?>
            <?php if (hasRole(['admin', 'manager', 'technologist'])): ?>
            <li><a href="<?= APP_URL ?>/modules/equipment/index.php" class="nav-link"><i class="fas fa-tools"></i> Оборудование</a></li>
            <?php endif; ?>
            <?php if (hasRole(['admin', 'manager', 'logistician'])): ?>
            <li><a href="<?= APP_URL ?>/modules/shipment/index.php" class="nav-link active"><i class="fas fa-truck"></i> Отгрузка</a></li>
            <?php endif; ?>
            <?php if (hasRole(['admin', 'manager', 'technologist'])): ?>
            <li><a href="<?= APP_URL ?>/modules/documents/index.php" class="nav-link"><i class="fas fa-file-contract"></i> Документы</a></li>
            <?php endif; ?>
            <?php if (hasRole('admin')): ?>
            <li><a href="<?= APP_URL ?>/modules/employees/index.php" class="nav-link"><i class="fas fa-users"></i> Сотрудники</a></li>
            <?php endif; ?>
        </ul>
        
        <div class="user-menu">
            <span style="color: var(--text-secondary);"><?= e(getCurrentUser()['username']) ?></span>
            <a href="<?= APP_URL ?>/modules/auth/logout.php" class="btn-logout" style="padding: 0.5rem 1rem; background: var(--glass-bg); border: 1px solid var(--border); border-radius: 8px; color: var(--text-primary); text-decoration: none;">Выход</a>
        </div>
    </nav>

    <!-- Main Content -->
    <div class="main-content">
        <div class="page-header">
            <div class="page-title">
                <h1><i class="fas fa-truck"></i> Отгрузка продукции</h1>
                <p>Управление отгрузкой и доставкой заказов</p>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= $shipmentStats['ready']['count'] ?? 0 ?></div>
                <div class="stat-label">Готовы к отгрузке</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $shipmentStats['shipped']['count'] ?? 0 ?></div>
                <div class="stat-label">В пути</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $monthlyStats['completed_count'] ?? 0 ?></div>
                <div class="stat-label">Отгружено за месяц</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= number_format($monthlyStats['total_amount'] ?? 0, 0, ',', ' ') ?></div>
                <div class="stat-label">Сумма за месяц (BYN)</div>
            </div>
        </div>

        <!-- Ready for Shipment -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-boxes"></i> Готовы к отгрузке
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($shipmentOrders)): ?>
                <p style="color: var(--text-secondary);">Нет заказов, готовых к отгрузке</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>№ заказа</th>
                                <th>Клиент</th>
                                <th>Адрес доставки</th>
                                <th>Контакты</th>
                                <th>Дата поставки</th>
                                <th>Статус</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shipmentOrders as $order): ?>
                            <tr>
                                <td><strong><?= e($order['order_number']) ?></strong></td>
                                <td><?= e($order['customer_name']) ?></td>
                                <td><?= e($order['address']) ?></td>
                                <td>
                                    <?= e($order['customer_phone']) ?><br>
                                    <small style="color: var(--text-secondary);"><?= e($order['email']) ?></small>
                                </td>
                                <td>
                                    <?= date('d.m.Y', strtotime($order['delivery_date'])) ?>
                                    <?php if ($order['days_since_delivery'] > 0): ?>
                                        <br><small style="color: var(--warning-color);">+<?= $order['days_since_delivery'] ?> дн.</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge-status badge-<?= e($order['status']) ?>">
                                        <?= e($order['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($order['status'] == 'ready'): ?>
                                    <a href="ship.php?id=<?= $order['id'] ?>" class="btn-action btn-ship">
                                        <i class="fas fa-truck"></i> Отгрузить
                                    </a>
                                    <?php elseif ($order['status'] == 'shipped'): ?>
                                    <a href="complete.php?id=<?= $order['id'] ?>" class="btn-action btn-complete">
                                        <i class="fas fa-check"></i> Завершить
                                    </a>
                                    <?php endif; ?>
                                    <a href="../documents/index.php?order_id=<?= $order['id'] ?>" class="btn-action btn-docs">
                                        <i class="fas fa-file-alt"></i> Документы
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Shipment History -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-history"></i> История отгрузок
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>№ заказа</th>
                                <th>Клиент</th>
                                <th>Сумма (BYN)</th>
                                <th>Статус</th>
                                <th>Последнее обновление</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($shipmentHistory as $order): ?>
                            <tr>
                                <td><strong><?= e($order['order_number']) ?></strong></td>
                                <td><?= e($order['customer_name']) ?></td>
                                <td><?= number_format($order['total_amount'], 2, ',', ' ') ?></td>
                                <td>
                                    <span class="badge-status badge-<?= e($order['status']) ?>">
                                        <?= e($order['status']) ?>
                                    </span>
                                </td>
                                <td><?= e($order['last_update']) ?></td>
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
