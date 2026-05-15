<?php
/**
 * Склад - Поступление материалов
 * PolesieMES - Система управления производством ОАО "Полесьеэлектромаш"
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireAuth();
if (!hasRole(['admin', 'manager', 'warehouse_keeper'])) {
    redirectWithMessage(APP_URL . '/modules/warehouse/warehouse_dashboard.php', 'Доступ запрещён', 'error');
}

$db = getDB();
$errors = [];
$success = false;
$message = '';

// Получение списка материалов
$stmt = $db->query("SELECT id, name, item_code, current_stock, min_stock FROM items WHERE item_type = 'material' AND is_active = 1 ORDER BY name");
$materials = $stmt->fetchAll();

// Поставщики
$stmt = $db->query("SELECT id, name FROM partners WHERE partner_type IN ('supplier', 'both') ORDER BY name");
$suppliers = $stmt->fetchAll();

// Проверка наличия order_id в URL (поступление по заказу поставщику)
$purchaseOrder = null;
$orderItems = [];
if (isset($_GET['order_id']) && !empty($_GET['order_id'])) {
    $orderId = intval($_GET['order_id']);
    
    // Получаем информацию о заказе поставщику
    $stmt = $db->prepare("
        SELECT po.*, p.name as supplier_name,
               CASE po.status
                   WHEN 'draft' THEN 'Черновик'
                   WHEN 'sent' THEN 'Отправлен'
                   WHEN 'confirmed' THEN 'Подтвержден'
                   WHEN 'partial' THEN 'Частично получен'
                   WHEN 'received' THEN 'Получен'
                   WHEN 'cancelled' THEN 'Отменен'
                   ELSE po.status
               END as status_name
        FROM purchase_orders po
        LEFT JOIN partners p ON po.supplier_id = p.id
        WHERE po.id = :id
    ");
    $stmt->execute(['id' => $orderId]);
    $purchaseOrder = $stmt->fetch();
    
    if ($purchaseOrder && !empty($purchaseOrder['items_json'])) {
        $orderItems = json_decode($purchaseOrder['items_json'], true);
        
        // Получаем детальную информацию о материалах в заказе
        if (!empty($orderItems)) {
            $itemIds = array_column($orderItems, 'item_id');
            $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
            $stmt = $db->prepare("
                SELECT i.id, i.name, i.item_code, i.current_stock, i.min_stock, u.name as unit_name
                FROM items i
                LEFT JOIN dictionaries u ON i.unit_id = u.id
                WHERE i.id IN ($placeholders)
            ");
            $stmt->execute($itemIds);
            $orderMaterials = $stmt->fetchAll();
            
            // Добавляем количество из заказа
            foreach ($orderMaterials as &$mat) {
                foreach ($orderItems as $item) {
                    if ($item['item_id'] == $mat['id']) {
                        $mat['order_quantity'] = $item['quantity'];
                        break;
                    }
                }
            }
        }
    }
}

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $item_id = $_POST['item_id'] ?? null;
    $quantity = floatval($_POST['quantity'] ?? 0);
    $supplier_id = $_POST['supplier_id'] ?? null;
    $notes = trim($_POST['notes'] ?? '');
    $purchase_order_id = $_POST['purchase_order_id'] ?? null;
    
    if (!$item_id) $errors[] = 'Выберите материал';
    if ($quantity <= 0) $errors[] = 'Укажите корректное количество';
    
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            
            // Обновление остатка
            $stmt = $db->prepare("UPDATE items SET current_stock = current_stock + :qty WHERE id = :id");
            $stmt->execute(['qty' => $quantity, 'id' => $item_id]);
            
            // Создание записи о движении
            $stmt = $db->prepare("INSERT INTO movements (movement_type, item_id, quantity, partner_id, notes, employee_id, reference_type, reference_id) VALUES ('receipt', :item_id, :qty, :supplier_id, :notes, :emp_id, 'purchase_order', :po_id)");
            $stmt->execute([
                'item_id' => $item_id,
                'qty' => $quantity,
                'supplier_id' => $supplier_id,
                'notes' => $notes,
                'emp_id' => $_SESSION['user_id'],
                'po_id' => $purchase_order_id
            ]);
            
            // Если это поступление по заказу - обновляем статус заказа
            if ($purchase_order_id) {
                // Проверяем, все ли материалы получены
                $stmt = $db->prepare("
                    SELECT po.items_json, po.status 
                    FROM purchase_orders po 
                    WHERE po.id = :id
                ");
                $stmt->execute(['id' => $purchase_order_id]);
                $po = $stmt->fetch();
                
                if ($po && !empty($po['items_json'])) {
                    $items = json_decode($po['items_json'], true);
                    
                    // Проверяем движения по этому заказу
                    $stmt = $db->prepare("
                        SELECT mvt.item_id, SUM(mvt.quantity) as received_qty
                        FROM movements mvt
                        WHERE mvt.reference_type = 'purchase_order' AND mvt.reference_id = :po_id
                        GROUP BY mvt.item_id
                    ");
                    $stmt->execute(['po_id' => $purchase_order_id]);
                    $received = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                    
                    // Проверяем, все ли позиции получены полностью
                    $allReceived = true;
                    foreach ($items as $item) {
                        $receivedQty = $received[$item['item_id']] ?? 0;
                        if ($receivedQty < $item['quantity']) {
                            $allReceived = false;
                            break;
                        }
                    }
                    
                    // Обновляем статус заказа
                    $newStatus = $allReceived ? 'received' : 'partial';
                    $stmt = $db->prepare("UPDATE purchase_orders SET status = :status, actual_delivery = NOW() WHERE id = :id");
                    $stmt->execute(['status' => $newStatus, 'id' => $purchase_order_id]);
                }
            }
            
            $db->commit();
            $success = true;
            $message = 'Материалы успешно оприходованы';
            
            // Если был order_id, перенаправляем обратно на страницу с этим заказом
            if ($purchase_order_id) {
                header('Location: receipt.php?order_id=' . $purchase_order_id . '&success=1');
                exit;
            }
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Ошибка: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Поступление | Склад | ' . APP_NAME;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/common-style.css">
</head>
<body>
    <div class="particles-container"><div class="particle"></div><div class="particle"></div><div class="particle"></div></div>
    <div class="glow-overlay"></div>
    <div class="grid-overlay"></div>
    
    <nav class="navbar">
        <a href="<?= APP_URL ?>" class="nav-brand"><div class="brand-logo"><svg viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg></div><span class="brand-name">PolesieMES</span></a>
        <ul class="nav-menu">
            <li><a href="<?= APP_URL ?>/modules/warehouse/warehouse_dashboard.php" class="nav-link"><i class="fas fa-warehouse"></i> Склад</a></li>
            <li><a href="receipt.php" class="nav-link active"><i class="fas fa-truck-loading"></i> Поступление</a></li>
        </ul>
        <div class="user-menu">
            <span><?= e($_SESSION['full_name']) ?> (<?= e(getRoleName($_SESSION['role'])) ?>)</span>
            <a href="<?= APP_URL ?>/modules/auth/logout.php" class="btn-logout">Выход</a>
        </div>
    </nav>

    <div class="main-content">
        <div class="page-header">
            <h1><i class="fas fa-truck-loading"></i> Поступление материалов</h1>
            <a href="warehouse_dashboard.php" class="btn-primary-custom"><i class="fas fa-arrow-left"></i> Назад</a>
        </div>

        <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success"><i class="fas fa-check-circle"></i> Материалы успешно оприходованы</div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div>
        <?php endif; ?>

        <!-- Информация о заказе поставщику (если есть) -->
        <?php if ($purchaseOrder): ?>
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="fas fa-shopping-cart"></i> Заказ поставщику <?= e($purchaseOrder['order_number']) ?></h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <strong>Поставщик:</strong><br>
                        <?= e($purchaseOrder['supplier_name']) ?>
                    </div>
                    <div class="col-md-3">
                        <strong>Статус:</strong><br>
                        <span class="badge bg-<?=
                            $purchaseOrder['status'] == 'confirmed' ? 'success' :
                            ($purchaseOrder['status'] == 'partial' ? 'warning' : 'info')
                        ?>"><?= e($purchaseOrder['status_name']) ?></span>
                    </div>
                    <div class="col-md-3">
                        <strong>Ожидается:</strong><br>
                        <?= date('d.m.Y', strtotime($purchaseOrder['expected_delivery'])) ?>
                    </div>
                    <div class="col-md-3">
                        <strong>Примечание:</strong><br>
                        <?= e($purchaseOrder['notes'] ?? '-') ?>
                    </div>
                </div>
                
                <?php if (!empty($orderMaterials)): ?>
                <hr>
                <h6>Материалы в заказе:</h6>
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th>Материал</th>
                            <th>Артикул</th>
                            <th>Заказано</th>
                            <th>Текущий остаток</th>
                            <th>Действие</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orderMaterials as $mat): ?>
                        <tr>
                            <td><?= e($mat['name']) ?></td>
                            <td><code><?= e($mat['item_code']) ?></code></td>
                            <td><strong><?= number_format($mat['order_quantity'], 2) ?> <?= e($mat['unit_name']) ?></strong></td>
                            <td><?= number_format($mat['current_stock'], 2) ?> <?= e($mat['unit_name']) ?></td>
                            <td>
                                <button type="button" class="btn btn-sm btn-primary" onclick="fillMaterial(<?= $mat['id'] ?>, <?= $mat['order_quantity'] ?>)">
                                    <i class="fas fa-plus"></i> Принять
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <form method="POST">
                    <?php if ($purchaseOrder): ?>
                    <input type="hidden" name="purchase_order_id" value="<?= $purchaseOrder['id'] ?>">
                    <?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Материал *</label>
                            <select name="item_id" id="item_id" class="form-select" required onchange="updateCurrentStock()">
                                <option value="">Выберите материал</option>
                                <?php foreach ($materials as $m): ?>
                                <option value="<?= $m['id'] ?>" data-stock="<?= $m['current_stock'] ?>"><?= e($m['name']) ?> (<?= e($m['item_code']) ?>) - Остаток: <?= number_format($m['current_stock'], 2) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Количество *</label>
                            <input type="number" step="0.01" name="quantity" id="quantity" class="form-control" required min="0.01">
                            <small class="text-muted">Текущий остаток: <span id="current_stock_display">-</span></small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Поставщик</label>
                            <select name="supplier_id" class="form-select">
                                <option value="">Не указано</option>
                                <?php foreach ($suppliers as $s): ?>
                                <option value="<?= $s['id'] ?>" <?= ($purchaseOrder && $s['id'] == $purchaseOrder['supplier_id']) ? 'selected' : '' ?>><?= e($s['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Примечание</label>
                            <textarea name="notes" class="form-control" rows="3"><?= e($purchaseOrder['notes'] ?? '') ?></textarea>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Оприходовать</button>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Функция для заполнения формы материалом из заказа
        function fillMaterial(itemId, quantity) {
            document.getElementById('item_id').value = itemId;
            document.getElementById('quantity').value = quantity;
            updateCurrentStock();
            window.scrollTo({ top: document.querySelector('.card').offsetTop, behavior: 'smooth' });
        }
        
        // Обновление отображения текущего остатка
        function updateCurrentStock() {
            const select = document.getElementById('item_id');
            const selectedOption = select.options[select.selectedIndex];
            const stock = selectedOption.getAttribute('data-stock');
            document.getElementById('current_stock_display').textContent = stock ? parseFloat(stock).toFixed(2) : '-';
        }
    </script>
</body>
</html>
