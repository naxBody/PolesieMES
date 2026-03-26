<?php
/**
 * Склад - Расход материалов
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireAuth();
if (!hasRole(['admin', 'manager', 'warehouse_manager', 'storekeeper'])) {
    redirectWithMessage(APP_URL . '/modules/warehouse/index.php', 'Доступ запрещён', 'error');
}

$db = getDB();
$errors = [];
$success = false;

$stmt = $db->query("SELECT id, name, item_code, current_stock FROM items WHERE item_type = 'material' AND is_active = 1 ORDER BY name");
$materials = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_id = $_POST['item_id'] ?? null;
    $quantity = floatval($_POST['quantity'] ?? 0);
    $order_id = $_POST['order_id'] ?? null;
    $notes = trim($_POST['notes'] ?? '');
    
    if (!$item_id) $errors[] = 'Выберите материал';
    if ($quantity <= 0) $errors[] = 'Укажите корректное количество';
    
    if (empty($errors)) {
        // Проверка остатка
        $stmt = $db->prepare("SELECT current_stock FROM items WHERE id = :id");
        $stmt->execute(['id' => $item_id]);
        $item = $stmt->fetch();
        
        if ($item['current_stock'] < $quantity) {
            $errors[] = 'Недостаточно материала на складе';
        } else {
            try {
                $db->beginTransaction();
                
                $stmt = $db->prepare("UPDATE items SET current_stock = current_stock - :qty WHERE id = :id");
                $stmt->execute(['qty' => $quantity, 'id' => $item_id]);
                
                $stmt = $db->prepare("INSERT INTO movements (movement_type, item_id, quantity, reference_type, reference_id, notes, employee_id) VALUES ('consumption', :item_id, :qty, 'order', :order_id, :notes, :emp_id)");
                $stmt->execute([
                    'item_id' => $item_id, 'qty' => $quantity, 'order_id' => $order_id,
                    'notes' => $notes, 'emp_id' => $_SESSION['user_id']
                ]);
                
                $db->commit();
                $success = true;
            } catch (Exception $e) {
                $db->rollBack();
                $errors[] = 'Ошибка: ' . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Расход | Склад | ' . APP_NAME;
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
            <li><a href="<?= APP_URL ?>/modules/warehouse/index.php" class="nav-link active"><i class="fas fa-warehouse"></i> Склад</a></li>
        </ul>
        <div class="user-menu"><span><?= e($_SESSION['full_name']) ?></span><a href="<?= APP_URL ?>/modules/auth/logout.php" class="btn-logout">Выход</a></div>
    </nav>

    <div class="main-content">
        <div class="page-header">
            <h1><i class="fas fa-dolly"></i> Расход материалов</h1>
            <a href="index.php" class="btn-primary-custom"><i class="fas fa-arrow-left"></i> Назад</a>
        </div>

        <?php if ($success): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> Материалы успешно списаны</div><?php endif; ?>
        <?php if (!empty($errors)): ?><div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div><?php endif; ?>

        <div class="card">
            <div class="card-body">
                <form method="POST">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Материал *</label>
                            <select name="item_id" class="form-select" required>
                                <option value="">Выберите материал</option>
                                <?php foreach ($materials as $m): ?>
                                <option value="<?= $m['id'] ?>"><?= e($m['name']) ?> (<?= e($m['item_code']) ?>) - Остаток: <?= number_format($m['current_stock'], 2) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Количество *</label>
                            <input type="number" step="0.01" name="quantity" class="form-control" required min="0.01">
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Примечание</label>
                            <textarea name="notes" class="form-control" rows="3" placeholder="Например: Заказ №..."></textarea>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-warning"><i class="fas fa-minus-circle"></i> Списать</button>
                </form>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
