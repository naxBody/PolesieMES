<?php
/**
 * Модуль документации ГОСТ (Беларусь) - Главная страница
 * PolesieMES - Система управления производством ОАО "Полесьеэлектромаш"
 * 
 * Формирование сопроводительной документации по стандартам Республики Беларусь:
 * - Паспорт изделия (ПС)
 * - Руководство по эксплуатации (РЭ)
 * - Сертификат соответствия
 * - Товарно-транспортная накладная (ТТН)
 * - Счет-фактура
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

// Проверка авторизации
requireAuth();

$db = getDB();
$user = getCurrentUser();

// Получение заказов для формирования документов
$order_id = $_GET['order_id'] ?? null;

if ($order_id) {
    // Детали конкретного заказа
    $stmt = $db->prepare("
        SELECT o.*, c.name as customer_name, c.inn, c.address, c.phone as customer_phone, c.email,
               e.first_name as manager_first_name, e.last_name as manager_last_name
        FROM orders o
        LEFT JOIN customers c ON o.customer_id = c.id
        LEFT JOIN employees e ON o.manager_id = e.id
        WHERE o.id = ?
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch();
    
    // Состав заказа
    $stmt = $db->prepare("
        SELECT oi.*, p.name as product_name, p.product_code, p.description, p.category
        FROM order_items oi
        LEFT JOIN products p ON oi.product_id = p.id
        WHERE oi.order_id = ?
    ");
    $stmt->execute([$order_id]);
    $orderItems = $stmt->fetchAll();
} else {
    $order = null;
    $orderItems = [];
}

// Все заказы с завершенным производством
$stmt = $db->query("
    SELECT o.*, c.name as customer_name, c.inn,
           DATE_FORMAT(o.updated_at, '%d.%m.%Y') as last_update
    FROM orders o
    LEFT JOIN customers c ON o.customer_id = c.id
    WHERE o.status IN ('ready', 'shipped', 'completed')
    ORDER BY o.updated_at DESC
");
$availableOrders = $stmt->fetchAll();

// Шаблоны документов ГОСТ
$gostTemplates = [
    ['id' => 'passport', 'name' => 'Паспорт изделия (ПС)', 'gost' => 'ГОСТ 2.601-2019'],
    ['id' => 'manual', 'name' => 'Руководство по эксплуатации (РЭ)', 'gost' => 'ГОСТ 2.601-2019'],
    ['id' => 'certificate', 'name' => 'Сертификат соответствия', 'gost' => 'ТР ТС 004/2011'],
    ['id' => 'ttn', 'name' => 'Товарно-транспортная накладная', 'gost' => 'Постановление Минфина РБ №44'],
    ['id' => 'invoice', 'name' => 'Счет-фактура', 'gost' => 'Постановление МНС РБ №30'],
    ['id' => 'act', 'name' => 'Акт приема-передачи', 'gost' => 'ГОСТ 2.601-2019'],
];

$pageTitle = 'ГОСТ Документы | ' . APP_NAME;
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

            <?php if (hasRole(['admin', 'manager', 'technologist', 'operator'])): ?>
            <li>
                <a href="<?= APP_URL ?>/modules/production/index.php" class="nav-link">
                    <i class="fas fa-cogs"></i>
                    Производство
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'manager', 'warehouse_manager'])): ?>
            <li>
                <a href="<?= APP_URL ?>/modules/warehouse/index.php" class="nav-link">
                    <i class="fas fa-warehouse"></i>
                    Склад
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'manager', 'technologist'])): ?>
            <li>
                <a href="<?= APP_URL ?>/modules/equipment/index.php" class="nav-link">
                    <i class="fas fa-tools"></i>
                    Оборудование
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'manager', 'logistician'])): ?>
            <li>
                <a href="<?= APP_URL ?>/modules/shipment/index.php" class="nav-link">
                    <i class="fas fa-truck"></i>
                    Отгрузка
                </a>
            </li>
            <?php endif; ?>

            <?php if (hasRole(['admin', 'manager', 'technologist'])): ?>
            <li>
                <a href="<?= APP_URL ?>/modules/documents/index.php" class="nav-link active">
                    <i class="fas fa-file-contract"></i>
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
                <h1><i class="fas fa-file-contract"></i> Документация ГОСТ (Беларусь)</h1>
                <p>Формирование сопроводительной документации по стандартам РБ</p>
            </div>
            <?php if (hasRole(['admin', 'manager', 'technologist'])): ?>
            <a href="../documents/index.php" class="btn-primary-custom">
                <i class="fas fa-folder-open"></i> Перейти к Документам
            </a>
            <?php endif; ?>
        </div>

        <?php if ($order): ?>
        <!-- Order Details & Document Generation -->
        <div class="alert-info-custom">
            <i class="fas fa-info-circle"></i>
            <strong>Заказ:</strong> <?= e($order['order_number']) ?> от <?= date('d.m.Y', strtotime($order['order_date'])) ?>
        </div>
        
        <div class="order-details">
            <h3 style="margin-bottom: 1rem;">Информация о заказе</h3>
            <div class="detail-row">
                <span class="detail-label">Клиент</span>
                <span class="detail-value"><?= e($order['customer_name']) ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">УНП</span>
                <span class="detail-value"><?= e($order['inn']) ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Адрес доставки</span>
                <span class="detail-value"><?= e($order['address']) ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Контакты</span>
                <span class="detail-value"><?= e($order['customer_phone']) ?> / <?= e($order['email']) ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Менеджер</span>
                <span class="detail-value"><?= e($order['manager_first_name'] . ' ' . $order['manager_last_name']) ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-label">Сумма заказа</span>
                <span class="detail-value"><?= number_format($order['total_amount'], 2, ',', ' ') ?> BYN</span>
            </div>
        </div>
        
        <?php if (!empty($orderItems)): ?>
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-boxes"></i> Состав заказа
                </div>
            </div>
            <div class="card-body">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Код</th>
                            <th>Наименование</th>
                            <th>Категория</th>
                            <th>Кол-во</th>
                            <th>Цена (BYN)</th>
                            <th>Сумма (BYN)</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orderItems as $item): ?>
                        <tr>
                            <td><?= e($item['product_code']) ?></td>
                            <td><?= e($item['product_name']) ?></td>
                            <td><?= e($item['category']) ?></td>
                            <td><?= $item['quantity'] ?></td>
                            <td><?= number_format($item['unit_price'], 2, ',', ' ') ?></td>
                            <td><?= number_format($item['total_price'], 2, ',', ' ') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-file-alt"></i> Доступные шаблоны документов
                </div>
            </div>
            <div class="card-body">
                <div class="template-grid">
                    <?php foreach ($gostTemplates as $template): ?>
                    <div class="template-card">
                        <div class="template-icon">
                            <svg viewBox="0 0 24 24"><path d="M14 2H6c-1.1 0-1.99.9-1.99 2L4 20c0 1.1.89 2 1.99 2H18c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z"/></svg>
                        </div>
                        <div class="template-name"><?= e($template['name']) ?></div>
                        <div class="template-gost"><?= e($template['gost']) ?></div>
                        <a href="generate.php?order_id=<?= $order_id ?>&type=<?= $template['id'] ?>" class="btn-template">
                            <i class="fas fa-download"></i>
                            Сформировать
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <a href="index.php" class="btn-primary-custom" style="margin-top: 1rem;">
            <i class="fas fa-arrow-left"></i> Назад к списку
        </a>
        
        <?php else: ?>
        <!-- Select Order -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-list"></i> Выберите заказ для формирования документов
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>№ заказа</th>
                                <th>Клиент</th>
                                <th>УНП</th>
                                <th>Дата</th>
                                <th>Статус</th>
                                <th>Действие</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($availableOrders as $orderItem): ?>
                            <tr>
                                <td><strong><?= e($orderItem['order_number']) ?></strong></td>
                                <td><?= e($orderItem['customer_name']) ?></td>
                                <td><?= e($orderItem['inn']) ?></td>
                                <td><?= e($orderItem['last_update']) ?></td>
                                <td>
                                    <span class="badge badge-<?= e($orderItem['status']) ?>" style="padding: 0.35rem 0.75rem; border-radius: 8px; font-size: 0.75rem; background: rgba(48, 209, 88, 0.2); color: var(--success-color);">
                                        <?= e($orderItem['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="index.php?order_id=<?= $orderItem['id'] ?>" class="btn-action btn-view" style="padding: 0.4rem 0.75rem; border-radius: 8px; font-size: 0.8rem; text-decoration: none; display: inline-flex; align-items: center; gap: 0.25rem; background: rgba(90, 200, 250, 0.2); color: var(--info-color); border: 1px solid rgba(90, 200, 250, 0.3);">
                                        <i class="fas fa-folder-open"></i> Документы
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
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleMobileMenu() {
            const navMenu = document.querySelector('.nav-menu');
            navMenu.classList.toggle('active');
        }
    </script>
</body>
</html>
