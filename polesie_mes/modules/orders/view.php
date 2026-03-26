<?php
/**
 * Универсальная страница просмотра заказа
 * PolesieMES - Система управления производством ОАО "Полесьеэлектромаш"
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireAuth();

$db = getDB();
$user = getCurrentUser();

// Получение ID заказа
$orderId = $_GET['id'] ?? null;

if (!$orderId) {
    redirectWithMessage(APP_URL . '/modules/orders/index.php', 'Заказ не указан', 'error');
}

// Получение информации о заказе
$stmt = $db->prepare("
    SELECT o.*, c.name as customer_name, c.inn, c.address, c.phone as customer_phone, c.email as customer_email,
           s.first_name as manager_first_name, s.last_name as manager_last_name
    FROM orders o
    LEFT JOIN partners c ON o.customer_id = c.id
    LEFT JOIN staff s ON o.manager_id = s.id
    WHERE o.id = :id
");
$stmt->execute(['id' => $orderId]);
$order = $stmt->fetch();

if (!$order) {
    redirectWithMessage(APP_URL . '/modules/orders/index.php', 'Заказ не найден', 'error');
}

// Декодирование состава заказа
$items = json_decode($order['items_json'], true) ?: [];

// Получение связанных производственных заданий
$stmt = $db->prepare("
    SELECT pt.*, i.name as product_name
    FROM production_tasks pt
    LEFT JOIN items i ON pt.product_id = i.id
    WHERE pt.order_id = :order_id
    ORDER BY pt.stage_sequence
");
$stmt->execute(['order_id' => $orderId]);
$tasks = $stmt->fetchAll();

$pageTitle = 'Просмотр заказа #' . e($order['order_number']) . ' | ' . APP_NAME;
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/common-style.css">
</head>
<body>
    <div class="particles-container"><div class="particle"></div><div class="particle"></div></div>
    <div class="glow-overlay"></div><div class="grid-overlay"></div>
    
    <nav class="navbar">
        <a href="<?= APP_URL ?>" class="nav-brand">
            <div class="brand-logo"><svg viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg></div>
            <span class="brand-name">PolesieMES</span>
        </a>
        <ul class="nav-menu">
            <li><a href="<?= APP_URL ?>/modules/dashboard/index.php" class="nav-link"><i class="fas fa-chart-line"></i> Главная</a></li>
            <li><a href="<?= APP_URL ?>/modules/orders/index.php" class="nav-link active"><i class="fas fa-shopping-cart"></i> Заказы</a></li>
        </ul>
        <div class="user-menu">
            <span><?= e($_SESSION['full_name']) ?></span>
            <a href="<?= APP_URL ?>/modules/auth/logout.php" class="btn-logout">Выход</a>
        </div>
    </nav>

    <div class="main-content">
        <div class="page-header">
            <h1><i class="fas fa-eye"></i> Заказ <?= e($order['order_number']) ?></h1>
            <div>
                <a href="index.php" class="btn-primary-custom"><i class="fas fa-arrow-left"></i> Назад</a>
                <?php if (hasRole(['admin', 'manager'])): ?>
                <a href="edit.php?id=<?= $order['id'] ?>" class="btn-primary-custom" style="margin-left: 0.5rem;"><i class="fas fa-edit"></i> Редактировать</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="row">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Информация о заказе</h5>
                        <table class="table table-sm">
                            <tr><th width="30%">Статус:</th><td><span class="badge-status badge-<?= e($order['status']) ?>"><?= getOrderStatusName($order['status']) ?></span></td></tr>
                            <tr><th>Приоритет:</th><td><span class="badge-priority badge-<?= e($order['priority']) ?>"><?= getPriorityName($order['priority']) ?></span></td></tr>
                            <tr><th>Дата заказа:</th><td><?= formatDate($order['order_date']) ?></td></tr>
                            <tr><th>Срок поставки:</th><td><?= formatDate($order['delivery_date']) ?></td></tr>
                            <tr><th>Сумма:</th><td><strong><?= formatNumber($order['total_amount'], 2) ?> BYN</strong></td></tr>
                            <?php if ($order['notes']): ?><tr><th>Примечание:</th><td><?= nl2br(e($order['notes'])) ?></td></tr><?php endif; ?>
                        </table>
                    </div>
                </div>

                <?php if (!empty($items)): ?>
                <div class="card mt-3">
                    <div class="card-body">
                        <h5 class="card-title">Состав заказа</h5>
                        <table class="table table-sm">
                            <thead><tr><th>Продукция</th><th>Кол-во</th><th>Цена</th><th>Сумма</th></tr></thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                <tr>
                                    <td><?= e($item['name'] ?? 'Товар #' . $item['product_id']) ?></td>
                                    <td><?= $item['quantity'] ?></td>
                                    <td><?= formatNumber($item['unit_price'], 2) ?></td>
                                    <td><?= formatNumber($item['total_price'], 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <?php if (!empty($tasks)): ?>
                <div class="card mt-3">
                    <div class="card-body">
                        <h5 class="card-title">Производственные задания</h5>
                        <table class="table table-sm">
                            <thead><tr><th>Этап</th><th>Продукция</th><th>Статус</th><th>План</th></tr></thead>
                            <tbody>
                                <?php foreach ($tasks as $task): ?>
                                <tr>
                                    <td><?= e($task['stage_name']) ?></td>
                                    <td><?= e($task['product_name']) ?></td>
                                    <td><span class="badge-status badge-<?= e($task['status']) ?>"><?= e($task['status']) ?></span></td>
                                    <td><?= formatDate($task['planned_end'], 'd.m.Y') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <div class="col-md-4">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title">Клиент</h5>
                        <p><strong><?= e($order['customer_name']) ?></strong></p>
                        <?php if ($order['inn']): ?><p>ИНН: <?= e($order['inn']) ?></p><?php endif; ?>
                        <?php if ($order['customer_phone']): ?><p>Тел: <?= e($order['customer_phone']) ?></p><?php endif; ?>
                        <?php if ($order['customer_email']): ?><p>Email: <?= e($order['customer_email']) ?></p><?php endif; ?>
                        <?php if ($order['address']): ?><p>Адрес: <?= e($order['address']) ?></p><?php endif; ?>
                    </div>
                </div>

                <div class="card mt-3">
                    <div class="card-body">
                        <h5 class="card-title">Менеджер</h5>
                        <p><?= e($order['manager_first_name'] . ' ' . $order['manager_last_name']) ?></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= APP_URL ?>/assets/js/main.js"></script>
</body>
</html>
