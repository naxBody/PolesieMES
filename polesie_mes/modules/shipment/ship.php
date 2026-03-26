<?php
/**
 * Отгрузка - Отгрузка заказа
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireAuth();
if (!hasRole(['admin', 'manager', 'logistician'])) {
    redirectWithMessage(APP_URL . '/modules/shipment/index.php', 'Доступ запрещён', 'error');
}

$db = getDB();
$errors = [];
$success = false;
$order = null;
$order_id = $_GET['id'] ?? $_POST['id'] ?? null;

if ($order_id) {
    $stmt = $db->prepare("SELECT o.*, c.name as customer_name, c.inn, c.address FROM orders o LEFT JOIN partners c ON o.customer_id = c.id WHERE o.id = :id");
    $stmt->execute(['id' => $order_id]);
    $order = $stmt->fetch();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $order_id = $_POST['id'] ?? null;
    $action = $_POST['action'] ?? '';
    
    if ($order_id && in_array($action, ['ship', 'complete'])) {
        try {
            $new_status = $action === 'ship' ? 'shipped' : 'completed';
            $stmt = $db->prepare("UPDATE orders SET status = :status, updated_at = NOW() WHERE id = :id");
            $stmt->execute(['status' => $new_status, 'id' => $order_id]);
            
            // Логирование
            logActivity($_SESSION['user_id'], $action, 'shipment', $order_id, 'Статус изменен на ' . $new_status);
            
            redirectWithMessage(APP_URL . '/modules/shipment/index.php', 'Заказ успешно ' . ($action === 'ship' ? 'отгружен' : 'завершен'), 'success');
        } catch (Exception $e) {
            $errors[] = 'Ошибка: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Отгрузка заказа | ' . APP_NAME;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/common-style.css">
</head>
<body>
    <div class="particles-container"><div class="particle"></div><div class="particle"></div></div>
    <div class="glow-overlay"></div><div class="grid-overlay"></div>
    
    <nav class="navbar">
        <a href="<?= APP_URL ?>" class="nav-brand"><div class="brand-logo"><svg viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg></div><span class="brand-name">PolesieMES</span></a>
        <ul class="nav-menu">
            <li><a href="<?= APP_URL ?>/modules/dashboard/index.php" class="nav-link"><i class="fas fa-chart-line"></i> Главная</a></li>
            <li><a href="<?= APP_URL ?>/modules/shipment/index.php" class="nav-link active"><i class="fas fa-truck"></i> Отгрузка</a></li>
        </ul>
        <div class="user-menu"><span><?= e($_SESSION['full_name']) ?></span><a href="<?= APP_URL ?>/modules/auth/logout.php" class="btn-logout">Выход</a></div>
    </nav>

    <div class="main-content">
        <div class="page-header">
            <h1><i class="fas fa-shipping-fast"></i> <?= $order && $_POST['action'] === 'complete' ? 'Завершение отгрузки' : 'Отгрузка заказа' ?></h1>
            <a href="index.php" class="btn-primary-custom"><i class="fas fa-arrow-left"></i> Назад</a>
        </div>

        <?php if (!empty($errors)): ?><div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div><?php endif; ?>

        <?php if ($order): ?>
        <div class="card">
            <div class="card-header"><h5>Заказ <?= e($order['order_number']) ?></h5></div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Клиент:</strong> <?= e($order['customer_name']) ?></p>
                        <p><strong>ИНН:</strong> <?= e($order['inn']) ?></p>
                        <p><strong>Адрес:</strong> <?= e($order['address']) ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Дата заказа:</strong> <?= date('d.m.Y', strtotime($order['order_date'])) ?></p>
                        <p><strong>Сумма:</strong> <?= number_format($order['total_amount'], 2, ',', ' ') ?> BYN</p>
                        <p><strong>Текущий статус:</strong> <span class="badge badge-<?= e($order['status']) ?>"><?= e($order['status']) ?></span></p>
                    </div>
                </div>
                
                <hr>
                
                <form method="POST">
                    <input type="hidden" name="id" value="<?= $order['id'] ?>">
                    <input type="hidden" name="action" value="<?= $_GET['action'] ?? 'ship' ?>">
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> Подтвердите действие:
                    </div>
                    
                    <?php if ($_GET['action'] ?? '' === 'ship'): ?>
                        <p>Вы подтверждаете отгрузку заказа <?= e($order['order_number']) ?>?</p>
                        <button type="submit" name="action" value="ship" class="btn btn-warning"><i class="fas fa-truck"></i> Подтвердить отгрузку</button>
                    <?php else: ?>
                        <p>Вы подтверждаете завершение отгрузки заказа <?= e($order['order_number']) ?>?</p>
                        <button type="submit" name="action" value="complete" class="btn btn-success"><i class="fas fa-check-circle"></i> Подтвердить завершение</button>
                    <?php endif; ?>
                    <a href="index.php" class="btn btn-secondary">Отмена</a>
                </form>
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-warning"><i class="fas fa-exclamation-triangle"></i> Заказ не найден</div>
        <?php endif; ?>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
