<?php
/**
 * Склад - Поступление материалов (Улучшенная версия)
 * PolesieMES - Система управления производством ОАО "Полесьеэлектромаш"
 * 
 * Функционал:
 * - Поступление по заказу поставщику
 * - Прямое поступление без заказа
 * - Пакетное добавление нескольких товаров
 * - Загрузка документов (счёт, накладная, УПД)
 * - Полная информация из документов
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
$receipt_items = []; // Массив для пакетного поступления

// Получение списка материалов
$stmt = $db->query("SELECT id, name, item_code, current_stock, min_stock FROM items WHERE item_type = 'material' AND is_active = 1 ORDER BY name");
$materials = $stmt->fetchAll();

// Поставщики
$stmt = $db->query("SELECT id, name, inn, kpp, address FROM partners WHERE partner_type IN ('supplier', 'both') ORDER BY name");
$suppliers = $stmt->fetchAll();

// Единицы измерения
$stmt = $db->query("SELECT id, name FROM dictionaries WHERE dict_type = 'unit' ORDER BY name");
$units = $stmt->fetchAll();

// Проверка наличия order_id в URL (поступление по заказу поставщику)
$purchaseOrder = null;
$orderItems = [];
if (isset($_GET['order_id']) && !empty($_GET['order_id'])) {
    $orderId = intval($_GET['order_id']);
    
    // Получаем информацию о заказе поставщику
    $stmt = $db->prepare("
        SELECT po.*, p.name as supplier_name, p.inn, p.kpp, p.address,
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
                        $mat['price'] = $item['price'] ?? 0;
                        break;
                    }
                }
            }
        }
    }
}

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $receipt_date = $_POST['receipt_date'] ?? date('Y-m-d H:i:s');
    $document_number = trim($_POST['document_number'] ?? '');
    $document_type = $_POST['document_type'] ?? 'invoice';
    $supplier_id = $_POST['supplier_id'] ?? null;
    $notes = trim($_POST['notes'] ?? '');
    $storage_location = trim($_POST['storage_location'] ?? 'Основной склад');
    $responsible_person_id = $_POST['responsible_person_id'] ?? $_SESSION['user_id'];
    
    // Получаем товары из POST (поддержка множественного добавления)
    $items = $_POST['items'] ?? [];
    
    if (empty($items)) {
        $errors[] = 'Добавьте хотя бы один товар';
    }
    
    // Валидация товаров
    foreach ($items as $idx => $item) {
        if (empty($item['item_id'])) {
            unset($items[$idx]);
            continue;
        }
        $qty = floatval($item['quantity'] ?? 0);
        $price = floatval($item['price'] ?? 0);
        if ($qty <= 0) {
            $errors[] = "Товар #" . ($idx + 1) . ": укажите корректное количество";
        }
    }
    
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            
            $totalAmount = 0;
            
            foreach ($items as $item) {
                $item_id = $item['item_id'];
                $quantity = floatval($item['quantity']);
                $price = floatval($item['price'] ?? 0);
                $series_number = trim($item['series_number'] ?? '');
                $expiry_date = !empty($item['expiry_date']) ? $item['expiry_date'] : null;
                
                // Обновление остатка
                $stmt = $db->prepare("UPDATE items SET current_stock = current_stock + :qty WHERE id = :id");
                $stmt->execute(['qty' => $quantity, 'id' => $item_id]);
                
                // Создание записи о движении
                $stmt = $db->prepare("
                    INSERT INTO movements (
                        movement_type, item_id, quantity, price, total_amount, 
                        partner_id, notes, employee_id, reference_type, reference_id,
                        storage_location, series_number, expiry_date, movement_date
                    ) VALUES (
                        'receipt', :item_id, :qty, :price, :total, 
                        :supplier_id, :notes, :emp_id, 'purchase_order', :po_id,
                        :storage, :series, :expiry, :receipt_date
                    )
                ");
                $stmt->execute([
                    'item_id' => $item_id,
                    'qty' => $quantity,
                    'price' => $price,
                    'total' => $quantity * $price,
                    'supplier_id' => $supplier_id,
                    'notes' => $notes,
                    'emp_id' => $responsible_person_id,
                    'po_id' => $_POST['purchase_order_id'] ?? null,
                    'storage' => $storage_location,
                    'series' => $series_number,
                    'expiry' => $expiry_date,
                    'receipt_date' => $receipt_date
                ]);
                
                $totalAmount += $quantity * $price;
            }
            
            // Если это поступление по заказу - обновляем статус заказа
            if (!empty($_POST['purchase_order_id'])) {
                $poId = $_POST['purchase_order_id'];
                
                $stmt = $db->prepare("SELECT items_json, status FROM purchase_orders WHERE id = :id");
                $stmt->execute(['id' => $poId]);
                $po = $stmt->fetch();
                
                if ($po && !empty($po['items_json'])) {
                    $poItems = json_decode($po['items_json'], true);
                    
                    // Проверяем движения по этому заказу
                    $stmt = $db->prepare("
                        SELECT mvt.item_id, SUM(mvt.quantity) as received_qty
                        FROM movements mvt
                        WHERE mvt.reference_type = 'purchase_order' AND mvt.reference_id = :po_id
                        GROUP BY mvt.item_id
                    ");
                    $stmt->execute(['po_id' => $poId]);
                    $received = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                    
                    // Проверяем, все ли позиции получены полностью
                    $allReceived = true;
                    foreach ($poItems as $poItem) {
                        $receivedQty = $received[$poItem['item_id']] ?? 0;
                        if ($receivedQty < $poItem['quantity']) {
                            $allReceived = false;
                            break;
                        }
                    }
                    
                    // Обновляем статус заказа
                    $newStatus = $allReceived ? 'received' : 'partial';
                    $stmt = $db->prepare("UPDATE purchase_orders SET status = :status, actual_delivery = NOW() WHERE id = :id");
                    $stmt->execute(['status' => $newStatus, 'id' => $poId]);
                }
            }
            
            $db->commit();
            $success = true;
            $message = sprintf('Материалы успешно оприходованы на сумму %.2f руб.', $totalAmount);
            
            // Если был order_id, перенаправляем обратно на страницу с этим заказом
            if (!empty($_POST['purchase_order_id'])) {
                header('Location: receipt.php?order_id=' . $_POST['purchase_order_id'] . '&success=1');
                exit;
            }
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Ошибка: ' . $e->getMessage();
        }
    }
}

// Получение ответственных лиц
$stmt = $db->query("SELECT id, first_name, last_name FROM staff WHERE is_active = 1 ORDER BY last_name");
$staff = $stmt->fetchAll();

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
    <style>
        .receipt-form-card {
            background: var(--glass-bg);
            backdrop-filter: var(--backdrop-blur);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .document-info-section {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding: 1rem;
            background: rgba(255,255,255,0.05);
            border-radius: 12px;
        }
        
        .items-table-container {
            max-height: 400px;
            overflow-y: auto;
            margin-bottom: 1rem;
        }
        
        .item-row {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr 0.5fr;
            gap: 0.75rem;
            align-items: end;
            margin-bottom: 1rem;
            padding: 1rem;
            background: rgba(255,255,255,0.03);
            border-radius: 8px;
            border: 1px solid var(--glass-border);
        }
        
        .add-item-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: linear-gradient(135deg, rgba(48, 209, 88, 0.2), rgba(36, 169, 69, 0.1));
            border: 1px solid rgba(48, 209, 88, 0.3);
            border-radius: 8px;
            color: var(--success-color);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .add-item-btn:hover {
            background: linear-gradient(135deg, rgba(48, 209, 88, 0.3), rgba(36, 169, 69, 0.2));
            border-color: rgba(48, 209, 88, 0.5);
        }
        
        .remove-item-btn {
            background: rgba(255, 69, 58, 0.2);
            border: 1px solid rgba(255, 69, 58, 0.3);
            color: var(--danger-color);
            width: 40px;
            height: 40px;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .remove-item-btn:hover {
            background: rgba(255, 69, 58, 0.3);
            border-color: rgba(255, 69, 58, 0.5);
        }
        
        .total-summary {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem 1.5rem;
            background: linear-gradient(135deg, rgba(48, 209, 88, 0.1), rgba(36, 169, 69, 0.05));
            border: 1px solid rgba(48, 209, 88, 0.3);
            border-radius: 12px;
            margin-top: 1rem;
        }
        
        .total-amount {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--success-color);
        }
    </style>
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
                                <button type="button" class="btn btn-sm btn-primary" onclick="addToItems(<?= $mat['id'] ?>, '<?= e($mat['name']) ?>', <?= $mat['order_quantity'] ?>, <?= $mat['price'] ?? 0 ?>)">
                                    <i class="fas fa-plus"></i> Добавить
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

        <!-- Основная форма поступления -->
        <div class="receipt-form-card">
            <form method="POST" id="receiptForm">
                <?php if ($purchaseOrder): ?>
                <input type="hidden" name="purchase_order_id" value="<?= $purchaseOrder['id'] ?>">
                <?php endif; ?>
                
                <!-- Информация о документе -->
                <div class="document-info-section">
                    <div>
                        <label class="form-label"><i class="fas fa-calendar"></i> Дата принятия</label>
                        <input type="datetime-local" name="receipt_date" class="form-control" value="<?= date('Y-m-d\TH:i') ?>">
                    </div>
                    <div>
                        <label class="form-label"><i class="fas fa-file-alt"></i> Номер документа</label>
                        <input type="text" name="document_number" class="form-control" placeholder="№ накладной/счёта">
                    </div>
                    <div>
                        <label class="form-label"><i class="fas fa-file-invoice"></i> Тип документа</label>
                        <select name="document_type" class="form-select">
                            <option value="invoice">Счёт-фактура</option>
                            <option value="nakladnaya">Товарная накладная</option>
                            <option value="upd">УПД</option>
                            <option value="other">Другое</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label"><i class="fas fa-user-tie"></i> Ответственный</label>
                        <select name="responsible_person_id" class="form-select">
                            <?php foreach ($staff as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= $s['id'] == $_SESSION['user_id'] ? 'selected' : '' ?>>
                                <?= e($s['first_name'] . ' ' . $s['last_name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <!-- Поставщик и склад -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <label class="form-label"><i class="fas fa-truck"></i> Поставщик</label>
                        <select name="supplier_id" class="form-select">
                            <option value="">Не указано</option>
                            <?php foreach ($suppliers as $s): ?>
                            <option value="<?= $s['id'] ?>" <?= ($purchaseOrder && $s['id'] == $purchaseOrder['supplier_id']) ? 'selected' : '' ?>>
                                <?= e($s['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label"><i class="fas fa-warehouse"></i> Место хранения</label>
                        <input type="text" name="storage_location" class="form-control" value="Основной склад" placeholder="Например: Зона А, Стеллаж 3">
                    </div>
                </div>
                
                <!-- Таблица товаров -->
                <h5 class="mb-3"><i class="fas fa-boxes"></i> Товары для оприходования</h5>
                <div class="items-table-container" id="itemsContainer">
                    <!-- Сюда добавляются строки с товарами -->
                </div>
                
                <button type="button" class="add-item-btn" onclick="addItemRow()">
                    <i class="fas fa-plus-circle"></i> Добавить товар
                </button>
                
                <!-- Итоговая сумма -->
                <div class="total-summary">
                    <span><i class="fas fa-calculator"></i> Общая сумма:</span>
                    <span class="total-amount" id="totalAmount">0.00 руб.</span>
                </div>
                
                <!-- Примечание -->
                <div class="mb-3 mt-4">
                    <label class="form-label"><i class="fas fa-comment"></i> Примечание</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="<?= e($purchaseOrder['notes'] ?? 'Дополнительная информация...') ?>"></textarea>
                </div>
                
                <button type="submit" class="btn btn-primary btn-lg" style="width: 100%;">
                    <i class="fas fa-save"></i> Оприходовать все товары
                </button>
            </form>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let itemsCount = 0;
        const materials = <?= json_encode($materials) ?>;
        
        // Функция для добавления товара из заказа
        function addToItems(itemId, itemName, quantity, price) {
            addItemRow(itemId, quantity, price);
        }
        
        // Добавление строки с товаром
        function addItemRow(preselectedId = '', preQuantity = '', prePrice = '') {
            itemsCount++;
            const container = document.getElementById('itemsContainer');
            
            const row = document.createElement('div');
            row.className = 'item-row';
            row.id = 'item-row-' + itemsCount;
            
            let options = '<option value="">Выберите материал</option>';
            materials.forEach(m => {
                const selected = m.id == preselectedId ? 'selected' : '';
                options += `<option value="${m.id}" ${selected} data-stock="${m.current_stock}">${m.name} (${m.item_code}) - Остаток: ${m.current_stock}</option>`;
            });
            
            row.innerHTML = `
                <div>
                    <label class="form-label small">Материал *</label>
                    <select name="items[${itemsCount}][item_id]" class="form-select item-select" required onchange="updateStockDisplay(this)">
                        ${options}
                    </select>
                </div>
                <div>
                    <label class="form-label small">Количество *</label>
                    <input type="number" step="0.01" name="items[${itemsCount}][quantity]" class="form-control item-quantity" required min="0.01" value="${preQuantity}">
                </div>
                <div>
                    <label class="form-label small">Цена за ед. (руб.)</label>
                    <input type="number" step="0.01" name="items[${itemsCount}][price]" class="form-control item-price" min="0" value="${prePrice}" onchange="calculateTotal()">
                </div>
                <div>
                    <label class="form-label small">Серия/№</label>
                    <input type="text" name="items[${itemsCount}][series_number]" class="form-control" placeholder="Серия или номер партии">
                </div>
                <div>
                    <label class="form-label small">&nbsp;</label>
                    <button type="button" class="remove-item-btn" onclick="removeItemRow(${itemsCount})">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            `;
            
            container.appendChild(row);
            if (preQuantity && prePrice) {
                calculateTotal();
            }
        }
        
        // Удаление строки
        function removeItemRow(rowId) {
            const row = document.getElementById('item-row-' + rowId);
            if (row) {
                row.remove();
                calculateTotal();
            }
        }
        
        // Обновление отображения остатка
        function updateStockDisplay(select) {
            const selectedOption = select.options[select.selectedIndex];
            const stock = selectedOption.getAttribute('data-stock');
            // Можно добавить отображение текущего остатка рядом
        }
        
        // Подсчёт общей суммы
        function calculateTotal() {
            let total = 0;
            document.querySelectorAll('.item-row').forEach(row => {
                const qty = parseFloat(row.querySelector('.item-quantity').value) || 0;
                const price = parseFloat(row.querySelector('.item-price').value) || 0;
                total += qty * price;
            });
            document.getElementById('totalAmount').textContent = total.toFixed(2) + ' руб.';
        }
        
        // Добавляем первую строку при загрузке
        document.addEventListener('DOMContentLoaded', function() {
            addItemRow();
        });
    </script>
</body>
</html>
