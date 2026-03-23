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
           e.first_name as manager_first_name, e.last_name as manager_last_name,
           DATEDIFF(NOW(), o.delivery_date) as days_since_delivery
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    LEFT JOIN employees e ON o.manager_id = e.id
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
    LEFT JOIN customers c ON o.customer_id = c.id
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
    
    <style>
        :root {
            --primary-gradient-start: #FF6B6B;
            --primary-gradient-end: #FF8E53;
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
        }
        
        .brand-name {
            font-size: 1.4rem;
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
        
        .main-content {
            padding: 6rem 2rem 2rem;
            max-width: 1600px;
            margin: 0 auto;
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .page-title h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .page-title p {
            color: var(--text-secondary);
        }
        
        .btn-primary-custom {
            padding: 0.75rem 1.5rem;
            background: var(--gradient-primary);
            border: none;
            border-radius: 12px;
            font-weight: 600;
            color: white;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            transition: all 0.3s ease;
        }
        
        .btn-primary-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(255, 107, 107, 0.4);
            color: white;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1.5rem;
            backdrop-filter: var(--backdrop-blur);
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .stat-label {
            color: var(--text-secondary);
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }
        
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            margin-bottom: 2rem;
        }
        
        .card-header {
            background: transparent;
            border-bottom: 1px solid var(--border);
            padding: 1.25rem 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-title {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-size: 1.125rem;
            font-weight: 600;
        }
        
        .card-body {
            padding: 1.5rem;
        }
        
        .table {
            color: var(--text-primary);
            margin-bottom: 0;
        }
        
        .table thead th {
            background: var(--glass-bg);
            border-bottom: 1px solid var(--border);
            color: var(--text-secondary);
            font-weight: 600;
            font-size: 0.875rem;
            padding: 1rem;
        }
        
        .table tbody td {
            border-bottom: 1px solid var(--border);
            padding: 1rem;
            vertical-align: middle;
        }
        
        .table tbody tr:hover {
            background: var(--glass-bg);
        }
        
        .badge-status {
            padding: 0.35rem 0.75rem;
            border-radius: 8px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-ready { background: rgba(48, 209, 88, 0.2); color: var(--success-color); }
        .badge-shipped { background: rgba(90, 200, 250, 0.2); color: var(--info-color); }
        .badge-completed { background: rgba(48, 209, 88, 0.2); color: var(--success-color); }
        
        .btn-action {
            padding: 0.4rem 0.75rem;
            border-radius: 8px;
            font-size: 0.8rem;
            margin-right: 0.25rem;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            transition: all 0.3s ease;
        }
        
        .btn-ship {
            background: rgba(90, 200, 250, 0.2);
            color: var(--info-color);
            border: 1px solid rgba(90, 200, 250, 0.3);
        }
        
        .btn-complete {
            background: rgba(48, 209, 88, 0.2);
            color: var(--success-color);
            border: 1px solid rgba(48, 209, 88, 0.3);
        }
        
        .btn-docs {
            background: rgba(255, 214, 10, 0.2);
            color: var(--warning-color);
            border: 1px solid rgba(255, 214, 10, 0.3);
        }
    </style>
</head>
<body>
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
            <li><a href="<?= APP_URL ?>/modules/gost_docs/index.php" class="nav-link"><i class="fas fa-file-contract"></i> ГОСТ Документы</a></li>
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
                                    <a href="../gost_docs/index.php?order_id=<?= $order['id'] ?>" class="btn-action btn-docs">
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
