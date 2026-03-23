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
        
        .template-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        
        .template-card {
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 16px;
            padding: 1.5rem;
            backdrop-filter: var(--backdrop-blur);
            transition: all 0.3s ease;
        }
        
        .template-card:hover {
            border-color: var(--primary-gradient-start);
            transform: translateY(-2px);
        }
        
        .template-icon {
            width: 48px;
            height: 48px;
            background: var(--gradient-primary);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
        }
        
        .template-icon svg {
            width: 24px;
            height: 24px;
            fill: white;
        }
        
        .template-name {
            font-size: 1.125rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .template-gost {
            color: var(--text-secondary);
            font-size: 0.875rem;
            margin-bottom: 1rem;
        }
        
        .btn-template {
            width: 100%;
            padding: 0.75rem;
            background: var(--glass-bg);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text-primary);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            justify-content: center;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-template:hover {
            background: var(--gradient-primary);
            border-color: transparent;
        }
        
        .alert-info-custom {
            background: rgba(90, 200, 250, 0.1);
            border: 1px solid rgba(90, 200, 250, 0.3);
            color: var(--info-color);
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1rem;
        }
        
        .order-details {
            background: var(--glass-bg);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--border);
        }
        
        .detail-row:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            color: var(--text-secondary);
        }
        
        .detail-value {
            font-weight: 600;
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
            <li><a href="<?= APP_URL ?>/modules/dashboard/index.php" class="nav-link"><i class="fas fa-home"></i> Главная</a></li>
            <li><a href="<?= APP_URL ?>/modules/orders/index.php" class="nav-link"><i class="fas fa-shopping-cart"></i> Заказы</a></li>
            <li><a href="<?= APP_URL ?>/modules/production/index.php" class="nav-link"><i class="fas fa-industry"></i> Производство</a></li>
            <li><a href="<?= APP_URL ?>/modules/shipment/index.php" class="nav-link"><i class="fas fa-truck"></i> Отгрузка</a></li>
            <li><a href="<?= APP_URL ?>/modules/gost_docs/index.php" class="nav-link active"><i class="fas fa-file-contract"></i> ГОСТ Документы</a></li>
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
                <h1><i class="fas fa-file-contract"></i> Документация ГОСТ (Беларусь)</h1>
                <p>Формирование сопроводительной документации по стандартам РБ</p>
            </div>
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
</body>
</html>
