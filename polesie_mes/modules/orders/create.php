<?php
/**
 * Заказы - Создание нового заказа
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
    $notes = trim($_POST['notes'] ?? '');
    $items_raw = $_POST['items_json'] ?? '[]';
    
    if (!$customer_id) $errors[] = 'Выберите клиента';
    if (!$delivery_date) $errors[] = 'Укажите срок поставки';
    
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            
            // Генерация номера заказа
            $order_number = generateUniqueNumber('ORD', 'orders', 'order_number');
            
            // Расчет суммы с безопасным парсингом JSON
            $itemsDecoded = json_decode($items_raw, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($itemsDecoded)) {
                $itemsDecoded = [];
            }
            $items = $itemsDecoded;
            
            // Валидация и нормализация данных items
            $normalizedItems = [];
            foreach ($items as $item) {
                if (!is_array($item) || !isset($item['product_id']) || !is_numeric($item['product_id'])) {
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
            $items_json_encoded = json_encode($normalizedItems, JSON_UNESCAPED_UNICODE);
            
            $stmt = $db->prepare("INSERT INTO orders (order_number, customer_id, order_date, delivery_date, priority, status, items_json, total_amount, notes, manager_id) VALUES (:num, :cust, :odate, :ddate, :prio, 'new', :items, :total, :notes, :mgr)");
            $stmt->execute([
                'num' => $order_number, 'cust' => $customer_id, 'odate' => $order_date,
                'ddate' => $delivery_date, 'prio' => $priority, 'items' => $items_json_encoded,
                'total' => $total, 'notes' => $notes, 'mgr' => $_SESSION['user_id']
            ]);
            
            $db->commit();
            redirectWithMessage(APP_URL . '/modules/orders/index.php', 'Заказ ' . $order_number . ' успешно создан', 'success');
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Ошибка: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Новый заказ | Заказы | ' . APP_NAME;
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
            <li><a href="<?= APP_URL ?>/modules/orders/index.php" class="nav-link active"><i class="fas fa-shopping-cart"></i> Заказы</a></li>
        </ul>
        <div class="user-menu"><span><?= e($_SESSION['full_name']) ?></span><a href="<?= APP_URL ?>/modules/auth/logout.php" class="btn-logout">Выход</a></div>
    </nav>

    <div class="main-content">
        <div class="page-header">
            <h1><i class="fas fa-plus-circle"></i> Новый заказ</h1>
            <a href="index.php" class="btn-primary-custom"><i class="fas fa-arrow-left"></i> Назад</a>
        </div>

        <?php if (!empty($errors)): ?><div class="alert alert-danger"><?php foreach ($errors as $err): ?><div><?= e($err) ?></div><?php endforeach; ?></div><?php endif; ?>

        <div class="card">
            <div class="card-body">
                <form method="POST" id="orderForm">
                    <input type="hidden" name="items_json" id="items_json" value="[]">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Клиент *</label>
                            <select name="customer_id" class="form-select" required>
                                <option value="">Выберите клиента</option>
                                <?php foreach ($customers as $c): ?>
                                <option value="<?= $c['id'] ?>"><?= e($c['name']) ?> (ИНН: <?= e($c['inn']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Дата заказа *</label>
                            <input type="date" name="order_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label">Срок поставки *</label>
                            <input type="date" name="delivery_date" class="form-control" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Приоритет</label>
                            <select name="priority" class="form-select">
                                <option value="low">Низкий</option>
                                <option value="normal" selected>Обычный</option>
                                <option value="high">Высокий</option>
                                <option value="urgent">Срочный</option>
                            </select>
                        </div>
                        <div class="col-md-12 mb-3">
                            <label class="form-label">Примечание</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                        
                        <div class="col-md-12 mb-3">
                            <hr>
                            <h5>Состав заказа</h5>
                            <div class="table-responsive">
                                <table class="table table-sm" id="itemsTable">
                                    <thead><tr><th>Продукция</th><th>Кол-во</th><th>Цена</th><th>Сумма</th><th></th></tr></thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="addItem()"><i class="fas fa-plus"></i> Добавить позицию</button>
                        </div>
                    </div>
                    <button type="submit" class="btn-primary"><i class="fas fa-save"></i> Создать заказ</button>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Безопасная передача данных из PHP в JavaScript
    const products = JSON.parse('<?= json_encode($products, JSON_UNESCAPED_UNICODE) ?>');
    let items = [];
    
    function addItem() {
        let opts = '<option value="">Выберите продукцию</option>';
        products.forEach(p => opts += `<option value="${p.id}" data-price="${p.price}">${p.name}</option>`);
        
        document.querySelector('#itemsTable tbody').innerHTML += `
            <tr>
                <td><select class="form-select form-select-sm item-select">${opts}</select></td>
                <td><input type="number" class="form-control form-control-sm qty-input" value="1" min="1"></td>
                <td class="price-cell">0.00</td>
                <td class="total-cell">0.00</td>
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
            if (sel.value) items.push({ product_id: sel.value, quantity: qty, unit_price: price, total_price: sum });
            total += sum;
        });
        document.getElementById('items_json').value = JSON.stringify(items);
    }
    </script>
</body>
</html>
