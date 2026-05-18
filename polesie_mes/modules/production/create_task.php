<?php
/**
 * Производство - Создание производственного задания
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireAuth();
if (!hasRole(['admin', 'manager', 'technologist'])) {
    redirectWithMessage(APP_URL . '/modules/production/index.php', 'Доступ запрещён', 'error');
}

$db = getDB();
$errors = [];

// Заказы в работе
$stmt = $db->query("SELECT id, order_number FROM orders WHERE status IN ('confirmed', 'in_production') ORDER BY order_number");
$orders = $stmt->fetchAll();

// Продукция
$stmt = $db->query("SELECT id, name, item_code FROM items WHERE item_type = 'product' AND is_active = 1 ORDER BY name");
$products = $stmt->fetchAll();

// Сотрудники (операторы)
$stmt = $db->query("SELECT id, first_name, last_name FROM staff WHERE role IN ('operator', 'technologist') AND status = 'active' ORDER BY last_name");
$employees = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $order_id = $_POST['order_id'] ?? null;
    $product_id = $_POST['product_id'] ?? null;
    $stage_name = $_POST['stage_name'] ?? '';
    $quantity = intval($_POST['quantity'] ?? 1);
    $planned_start = $_POST['planned_start'] ?? date('Y-m-d H:i');
    $planned_end = $_POST['planned_end'] ?? date('Y-m-d H:i', strtotime('+1 day'));
    $assigned_to = $_POST['assigned_to'] ?? null;
    $notes = trim($_POST['notes'] ?? '');
    
    if (!$order_id) $errors[] = 'Выберите заказ';
    if (!$product_id) $errors[] = 'Выберите продукцию';
    if (!$stage_name) $errors[] = 'Укажите этап';
    if ($quantity <= 0) $errors[] = 'Некорректное количество';
    
    if (empty($errors)) {
        try {
            $task_number = generateUniqueNumber('TSK', 'production_tasks', 'task_number');
            
            $stmt = $db->prepare("INSERT INTO production_tasks (task_number, order_id, product_id, stage_name, quantity, planned_start, planned_end, assigned_to, status, notes) VALUES (:num, :oid, :pid, :stage, :qty, :pstart, :pend, :assign, 'planned', :notes)");
            $stmt->execute([
                'num' => $task_number, 'oid' => $order_id, 'pid' => $product_id,
                'stage' => $stage_name, 'qty' => $quantity, 'pstart' => $planned_start,
                'pend' => $planned_end, 'assign' => $assigned_to, 'notes' => $notes
            ]);
            
            redirectWithMessage(APP_URL . '/modules/production/index.php', 'Задание ' . $task_number . ' создано', 'success');
        } catch (Exception $e) {
            $errors[] = 'Ошибка: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Новое задание | Производство | ' . APP_NAME;
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
            <li><a href="<?= APP_URL ?>/modules/production/index.php" class="nav-link active"><i class="fas fa-cogs"></i> Производство</a></li>
        </ul>
        <div class="user-menu"><span><?= e($_SESSION['full_name']) ?></span><a href="<?= APP_URL ?>/modules/auth/logout.php" class="btn-logout">Выход</a></div>
    </nav>

    <div class="main-content">
        <div class="page-header">
            <h1><i class="fas fa-tasks"></i> Новое производственное задание</h1>
            <a href="index.php" class="btn-primary-custom"><i class="fas fa-arrow-left"></i> Назад</a>
        </div>

        <?php if (!empty($errors)): ?><div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div><?php endif; ?>

        <div class="card">
            <div class="card-body">
                <form method="POST">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Заказ *</label>
                            <select name="order_id" class="form-select" required>
                                <option value="">Выберите заказ</option>
                                <?php foreach ($orders as $o): ?>
                                <option value="<?= $o['id'] ?>"><?= e($o['order_number']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Продукция *</label>
                            <select name="product_id" class="form-select" required>
                                <option value="">Выберите продукцию</option>
                                <?php foreach ($products as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= e($p['name']) ?> (<?= e($p['item_code']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Этап производства *</label>
                            <input type="text" name="stage_name" class="form-control" placeholder="Например: Раскрой, Сборка, Покраска" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Количество *</label>
                            <input type="number" name="quantity" class="form-control" value="1" min="1" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Исполнитель</label>
                            <select name="assigned_to" class="form-select">
                                <option value="">Не назначен</option>
                                <?php foreach ($employees as $e): ?>
                                <option value="<?= $e['id'] ?>"><?= e($e['last_name'] . ' ' . $e['first_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">План начало *</label>
                            <input type="datetime-local" name="planned_start" class="form-control" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">План окончание *</label>
                            <input type="datetime-local" name="planned_end" class="form-control" required>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Примечание</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Создать задание</button>
                </form>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
