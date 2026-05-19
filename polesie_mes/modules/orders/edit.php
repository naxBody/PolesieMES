<?php
/**
 * Заказы - Редактирование заказа
 * PolesieMES - Система управления производством ОАО "Полесьеэлектромаш"
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireAuth();
if (!hasRole(['admin', 'manager'])) {
    redirectWithMessage(APP_URL . '/modules/orders/index.php', 'Доступ запрещён', 'error');
}

$db = getDB();
$errors = [];
$success = false;

// Получение ID заказа
$orderId = $_GET['id'] ?? null;

if (!$orderId) {
    redirectWithMessage(APP_URL . '/modules/orders/index.php', 'Заказ не указан', 'error');
}

// Получение информации о заказе
$stmt = $db->prepare("SELECT * FROM orders WHERE id = :id");
$stmt->execute(['id' => $orderId]);
$order = $stmt->fetch();

if (!$order) {
    redirectWithMessage(APP_URL . '/modules/orders/index.php', 'Заказ не найден', 'error');
}

// Декодирование состава заказа
$items = json_decode($order['items_json'], true) ?: [];

// Получение клиентов
$stmt = $db->query("SELECT id, name, inn FROM partners WHERE partner_type IN ('customer', 'both') ORDER BY name");
$customers = $stmt->fetchAll();

// Получение продукции
$stmt = $db->query("SELECT id, name, item_code, price FROM items WHERE item_type = 'product' AND is_active = 1 ORDER BY name");
$products = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $customer_id = $_POST['customer_id'] ?? null;
    $order_date = $_POST['order_date'] ?? date('Y-m-d');
    $delivery_date = $_POST['delivery_date'] ?? null;
    $priority = $_POST['priority'] ?? 'normal';
    $status = $_POST['status'] ?? $order['status'];
    $notes = trim($_POST['notes'] ?? '');
    $items_raw = $_POST['items_json'] ?? '[]';
    
    if (!$customer_id) $errors[] = 'Выберите клиента';
    if (!$delivery_date) $errors[] = 'Укажите срок поставки';
    
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            
            // Расчет суммы
            $items = json_decode($items_raw, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($items)) {
                $items = [];
            }
            
            // Валидация и нормализация данных items
            $normalizedItems = [];
            foreach ($items as $item) {
                if (!isset($item['product_id']) || !is_numeric($item['product_id'])) {
                    continue;
                }
                $normalizedItems[] = [
                    'product_id' => (int)$item['product_id'],
                    'name' => $item['name'] ?? '',
                    'quantity' => isset($item['quantity']) ? (float)$item['quantity'] : 0,
                    'unit_price' => isset($item['unit_price']) ? (float)$item['unit_price'] : 0,
                    'total_price' => isset($item['total_price']) ? (float)$item['total_price'] : 0
                ];
            }
            
            $total = array_sum(array_column($normalizedItems, 'total_price'));
            $items_json_encoded = json_encode($normalizedItems, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            
            $stmt = $db->prepare("UPDATE orders SET customer_id = :cust, order_date = :odate, delivery_date = :ddate, priority = :prio, status = :status, items_json = :items, total_amount = :total, notes = :notes WHERE id = :id");
            $stmt->execute([
                'cust' => $customer_id, 'odate' => $order_date,
                'ddate' => $delivery_date, 'prio' => $priority, 'status' => $status,
                'items' => $items_json_encoded, 'total' => $total, 'notes' => $notes, 'id' => $orderId
            ]);
            
            $db->commit();
            redirectWithMessage(APP_URL . '/modules/orders/view.php?id=' . $orderId, 'Заказ ' . e($order['order_number']) . ' успешно обновлён', 'success');
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Ошибка: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Редактирование заказа #' . e($order['order_number']) . ' | ' . APP_NAME;
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
    <style>
        .form-label {
            font-weight: 500;
            color: var(--text-secondary);
            margin-bottom: 0.5rem;
        }
        .form-control, .form-select {
            background: rgba(30, 30, 45, 0.8);
            border: 1px solid var(--glass-border);
            color: #ffffff !important;
            border-radius: 8px;
        }
        .form-control:focus, .form-select:focus {
            background: rgba(40, 40, 60, 0.9);
            border-color: var(--primary-gradient-start);
            color: #ffffff !important;
            box-shadow: 0 0 15px rgba(255, 107, 107, 0.2);
        }
        .form-control::placeholder {
            color: rgba(255, 255, 255, 0.5);
        }
        .form-control option, .form-select option {
            background: #1a1a2e;
            color: #ffffff;
        }
        #itemsTable .form-control, #itemsTable .form-select {
            font-size: 0.875rem;
            color: #ffffff !important;
        }
        .price-cell, .total-cell {
            font-weight: 600;
            color: var(--primary-gradient-start);
            padding: 0.5rem;
        }
    </style>
</head>
<body>
    <div class="particles-container"><div class="particle"></div><div class="particle"></div></div>
    <div class="glow-overlay"></div><div class="grid-overlay"></div>
    
    <nav class="navbar">
        <a href="<?= APP_URL ?>" class="nav-brand"><div class="brand-logo"><svg viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg></div><span class="brand-name">PolesieMES</span></a>
        <ul class="nav-menu">
            <li><a href="<?= APP_URL ?>/modules/dashboard/index.php" class="nav-link"><i class="fas fa-chart-line"></i> Главная</a></li>
            <li><a href="<?= APP_URL ?>/modules/orders/index.php" class="nav-link"><i class="fas fa-shopping-cart"></i> Заказы</a></li>
        </ul>
        <div class="user-menu"><span><?= e($_SESSION['full_name']) ?></span><a href="<?= APP_URL ?>/modules/auth/logout.php" class="btn-logout">Выход</a></div>
    </nav>

    <div class="main-content">
        <div class="page-header">
            <h1><i class="fas fa-edit"></i> Редактирование заказа <?= e($order['order_number']) ?></h1>
            <a href="view.php?id=<?= $orderId ?>" class="btn-primary-custom"><i class="fas fa-arrow-left"></i> Назад</a>
        </div>

        <?php if (!empty($errors)): ?><div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div><?php endif; ?>
        <?php if ($success): ?><div class="alert alert-success">Заказ успешно обновлён</div><?php endif; ?>

        <div class="card">
            <div class="card-body">
                <form method="POST" id="orderForm">
                    <input type="hidden" name="items_json" id="items_json" value="">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Клиент *</label>
                            <select name="customer_id" class="form-select" required>
                                <option value="">Выберите клиента</option>
                                <?php foreach ($customers as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= $c['id'] == $order['customer_id'] ? 'selected' : '' ?>><?= e($c['name']) ?> (ИНН: <?= e($c['inn']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Дата заказа *</label>
                            <input type="date" name="order_date" class="form-control" value="<?= e($order['order_date']) ?>" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Срок поставки *</label>
                            <input type="date" name="delivery_date" class="form-control" value="<?= e($order['delivery_date']) ?>" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Приоритет</label>
                            <select name="priority" class="form-select">
                                <option value="low" <?= $order['priority'] === 'low' ? 'selected' : '' ?>>Низкий</option>
                                <option value="normal" <?= $order['priority'] === 'normal' ? 'selected' : '' ?>>Обычный</option>
                                <option value="high" <?= $order['priority'] === 'high' ? 'selected' : '' ?>>Высокий</option>
                                <option value="urgent" <?= $order['priority'] === 'urgent' ? 'selected' : '' ?>>Срочный</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Статус</label>
                            <select name="status" class="form-select">
                                <option value="new" <?= $order['status'] === 'new' ? 'selected' : '' ?>>Новый</option>
                                <option value="confirmed" <?= $order['status'] === 'confirmed' ? 'selected' : '' ?>>Подтверждён</option>
                                <option value="in_production" <?= $order['status'] === 'in_production' ? 'selected' : '' ?>>В производстве</option>
                                <option value="ready" <?= $order['status'] === 'ready' ? 'selected' : '' ?>>Готов к отгрузке</option>
                                <option value="completed" <?= $order['status'] === 'completed' ? 'selected' : '' ?>>Выполнен</option>
                                <option value="cancelled" <?= $order['status'] === 'cancelled' ? 'selected' : '' ?>>Отменён</option>
                            </select>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Примечание</label>
                            <textarea name="notes" class="form-control" rows="2"><?= e($order['notes'] ?? '') ?></textarea>
                        </div>
                        
                        <div class="col-md-12 mb-3">
                            <hr>
                            <h5><i class="fas fa-boxes"></i> Состав заказа</h5>
                            <div class="table-responsive">
                                <table class="table table-sm" id="itemsTable">
                                    <thead><tr><th>Продукция</th><th style="width: 15%;">Кол-во</th><th style="width: 20%;">Цена</th><th style="width: 20%;">Сумма</th><th style="width: 5%;"></th></tr></thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addItem()"><i class="fas fa-plus"></i> Добавить позицию</button>
                        </div>
                    </div>
                    <div style="display: flex; gap: 1rem; margin-top: 1.5rem;">
                        <button type="submit" class="btn-primary-custom"><i class="fas fa-save"></i> Сохранить изменения</button>
                        <a href="view.php?id=<?= $orderId ?>" class="btn-secondary"><i class="fas fa-times"></i> Отмена</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    const products = <?= json_encode($products) ?>;
    const existingItems = <?= json_encode($items) ?>;
    let items = [...existingItems];
    
    function addItem(existingItem = null) {
        let opts = '<option value="">Выберите продукцию</option>';
        products.forEach(p => {
            const selected = existingItem && existingItem.product_id == p.id ? 'selected' : '';
            opts += `<option value="${p.id}" data-price="${p.price}" ${selected}>${p.name}</option>`;
        });
        
        const qty = existingItem ? existingItem.quantity : 1;
        const price = existingItem ? existingItem.unit_price : 0;
        const total = existingItem ? existingItem.total_price : 0;
        
        document.querySelector('#itemsTable tbody').innerHTML += `
            <tr>
                <td><select class="form-select form-select-sm item-select">${opts}</select></td>
                <td><input type="number" class="form-control form-control-sm qty-input" value="${qty}" min="1"></td>
                <td class="price-cell">${price.toFixed(2)}</td>
                <td class="total-cell">${total.toFixed(2)}</td>
                <td><button type="button" class="btn btn-sm btn-danger" onclick="this.closest('tr').remove(); updateItems();">×</button></td>
            </tr>`;
        
        document.querySelectorAll('.item-select').forEach(sel => sel.addEventListener('change', updateItems));
        document.querySelectorAll('.qty-input').forEach(inp => inp.addEventListener('input', updateItems));
    }
    
    function updateItems() {
        items = [];
        let total = 0;
        document.querySelectorAll('#itemsTable tbody tr').forEach(row => {
            const sel = row.querySelector('.item-select');
            const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
            const price = parseFloat(sel.options[sel.selectedIndex]?.dataset?.price) || 0;
            const sum = qty * price;
            row.querySelector('.price-cell').textContent = price.toFixed(2);
            row.querySelector('.total-cell').textContent = sum.toFixed(2);
            if (sel.value) {
                const productName = sel.options[sel.selectedIndex]?.text || '';
                items.push({ product_id: sel.value, name: productName, quantity: qty, unit_price: price, total_price: sum });
            }
            total += sum;
        });
        document.getElementById('items_json').value = JSON.stringify(items);
    }
    
    // Инициализация с существующими элементами
    document.addEventListener('DOMContentLoaded', function() {
        if (items.length > 0) {
            items.forEach(item => addItem(item));
            updateItems();
        } else {
            addItem();
        }
    });
    </script>
</body>
</html>
