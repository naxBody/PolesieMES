<?php
/**
 * Модуль управления заказами - Главная страница
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

// Получение всех заказов с информацией о клиентах
$stmt = $db->query("
    SELECT o.*, c.name as customer_name, c.inn, c.phone as customer_phone, 
           e.first_name as manager_first_name, e.last_name as manager_last_name,
           DATEDIFF(o.delivery_date, NOW()) as days_until_delivery
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    LEFT JOIN employees e ON o.manager_id = e.id
    ORDER BY o.created_at DESC
");
$orders = $stmt->fetchAll();

// Статистика по статусам
$stmt = $db->query("
    SELECT 
        status,
        COUNT(*) as count,
        SUM(total_amount) as total_amount
    FROM orders
    GROUP BY status
");
$statusStats = [];
while ($row = $stmt->fetch()) {
    $statusStats[$row['status']] = $row;
}

// Просроченные заказы
$stmt = $db->query("
    SELECT o.*, c.name as customer_name, DATEDIFF(NOW(), o.delivery_date) as days_overdue
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    WHERE o.status NOT IN ('completed', 'cancelled')
    AND o.delivery_date < NOW()
    ORDER BY o.delivery_date ASC
");
$overdueOrders = $stmt->fetchAll();

// Заказы требующие внимания (новые или подтвержденные)
$stmt = $db->query("
    SELECT o.*, c.name as customer_name
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    WHERE o.status IN ('new', 'confirmed')
    ORDER BY o.created_at DESC
    LIMIT 10
");
$attentionOrders = $stmt->fetchAll();

$pageTitle = 'Управление заказами | ' . APP_NAME;
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
            <li><a href="<?= APP_URL ?>/modules/orders/index.php" class="nav-link active"><i class="fas fa-shopping-cart"></i> Заказы</a></li>
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
            <li><a href="<?= APP_URL ?>/modules/shipment/index.php" class="nav-link"><i class="fas fa-truck"></i> Отгрузка</a></li>
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
                <h1><i class="fas fa-shopping-cart"></i> Управление заказами</h1>
                <p>Полный контроль над заказами клиентов</p>
            </div>
            <?php if (hasRole(['admin', 'manager'])): ?>
            <a href="create.php" class="btn-primary-custom">
                <i class="fas fa-plus"></i> Новый заказ
            </a>
            <?php endif; ?>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= count($orders) ?></div>
                <div class="stat-label">Всего заказов</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $statusStats['new']['count'] ?? 0 ?></div>
                <div class="stat-label">Новые</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $statusStats['in_production']['count'] ?? 0 ?></div>
                <div class="stat-label">В производстве</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= $statusStats['ready']['count'] ?? 0 ?></div>
                <div class="stat-label">Готовы к отгрузке</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= count($overdueOrders) ?></div>
                <div class="stat-label" style="color: var(--danger-color);">Просрочены</div>
            </div>
        </div>

        <!-- Overdue Orders Alert -->
        <?php if (!empty($overdueOrders)): ?>
        <div class="alert-warning-custom">
            <i class="fas fa-exclamation-triangle"></i>
            <strong>Внимание!</strong> <?= count($overdueOrders) ?> заказов просрочено. Требуется немедленное вмешательство.
        </div>
        <?php endif; ?>

        <!-- Orders Table -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-list"></i> Все заказы
                </div>
                <div class="card-actions">
                    <button class="btn-action btn-view" onclick="location.reload()">
                        <i class="fas fa-sync"></i> Обновить
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>№ заказа</th>
                                <th>Клиент</th>
                                <th>Дата заказа</th>
                                <th>Срок поставки</th>
                                <th>Приоритет</th>
                                <th>Статус</th>
                                <th>Сумма (BYN)</th>
                                <th>Менеджер</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                            <tr>
                                <td><strong><?= e($order['order_number']) ?></strong></td>
                                <td><?= e($order['customer_name']) ?></td>
                                <td><?= date('d.m.Y', strtotime($order['order_date'])) ?></td>
                                <td>
                                    <?php 
                                    $daysDiff = $order['days_until_delivery'];
                                    if ($daysDiff < 0): ?>
                                        <span class="days-overdue"><?= abs($daysDiff) ?> дн. назад</span>
                                    <?php elseif ($daysDiff == 0): ?>
                                        <span class="days-overdue">Сегодня</span>
                                    <?php else: ?>
                                        <span class="days-left"><?= $daysDiff ?> дн.</span>
                                    <?php endif; ?>
                                    <br><small><?= date('d.m.Y', strtotime($order['delivery_date'])) ?></small>
                                </td>
                                <td>
                                    <span class="badge-priority badge-<?= e($order['priority']) ?>">
                                        <?= e($order['priority']) ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge-status badge-<?= e($order['status']) ?>">
                                        <?= e($order['status']) ?>
                                    </span>
                                </td>
                                <td><?= number_format($order['total_amount'], 2, ',', ' ') ?></td>
                                <td><?= e($order['manager_first_name'] . ' ' . $order['manager_last_name']) ?></td>
                                <td>
                                    <a href="view.php?id=<?= $order['id'] ?>" class="btn-action btn-view">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if (hasRole(['admin', 'manager'])): ?>
                                    <a href="edit.php?id=<?= $order['id'] ?>" class="btn-action btn-edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php endif; ?>
                                </td>
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
    
    <!-- Анимированный фон и скрипты из common-style.css -->
    <script>
        // Scroll effect for navbar
        window.addEventListener('scroll', function() {
            const navbar = document.getElementById('navbar') || document.querySelector('.navbar');
            if (navbar && window.scrollY > 50) {
                navbar.classList.add('scrolled');
            } else if (navbar) {
                navbar.classList.remove('scrolled');
            }
        });
    </script>
</body>
</html>
