<?php
/**
 * Заказы поставщикам - управление ожидаемыми поставками
 * PolesieMES - Система управления производством ОАО "Полесьеэлектромаш"
 */

require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/auth_functions.php';
require_once __DIR__ . '/../../../includes/helpers.php';

// Проверка авторизации
requireAuth();

// Проверка роли
if (!hasRole(['admin', 'manager', 'warehouse_keeper'])) {
    redirectWithMessage(APP_URL . '/modules/dashboard/index.php', 'Доступ запрещён.', 'error');
}

$db = getDB();
$user = getCurrentUser();

$successMessage = '';
$errorMessage = '';

// ==========================================
// ОБРАБОТКА ДЕЙСТВИЙ
// ==========================================

// Создание нового заказа поставщику
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'create_order':
                $supplier_id = (int)$_POST['supplier_id'];
                $order_date = $_POST['order_date'] ?? date('Y-m-d');
                $expected_delivery = $_POST['expected_delivery'] ?? null;
                $priority = $_POST['priority'] ?? 'normal';
                $notes = trim($_POST['notes'] ?? '');
                $items = $_POST['items'] ?? [];
                
                if (empty($supplier_id) || empty($items)) {
                    throw new Exception('Необходимо указать поставщика и хотя бы один товар');
                }
                
                // Генерация номера заказа
                $stmt = $db->query("SELECT MAX(CAST(SUBSTRING(order_number, 4) AS UNSIGNED)) as max_num FROM purchase_orders WHERE order_number LIKE 'PO-%'");
                $maxNum = $stmt->fetch()['max_num'] ?? 0;
                $orderNumber = 'PO-' . date('Y') . '-' . str_pad($maxNum + 1, 3, '0', STR_PAD_LEFT);
                
                // Формирование JSON с товарами
                $itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE);
                
                // Подсчёт общей суммы
                $totalAmount = 0;
                foreach ($items as $item) {
                    $totalAmount += ($item['quantity'] ?? 0) * ($item['price'] ?? 0);
                }
                
                $stmt = $db->prepare("
                    INSERT INTO purchase_orders 
                    (order_number, supplier_id, order_date, expected_delivery, priority, status, items_json, total_amount, notes, created_by)
                    VALUES (?, ?, ?, ?, ?, 'draft', ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $orderNumber, $supplier_id, $order_date, $expected_delivery, 
                    $priority, $itemsJson, $totalAmount, $notes, $user['id']
                ]);
                
                $successMessage = "Заказ поставщику <strong>$orderNumber</strong> успешно создан";
                break;
                
            case 'update_status':
                $order_id = (int)$_POST['order_id'];
                $new_status = $_POST['new_status'];
                
                $stmt = $db->prepare("UPDATE purchase_orders SET status = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$new_status, $order_id]);
                
                $successMessage = "Статус заказа обновлён";
                break;
                
            case 'receive_order':
                $order_id = (int)$_POST['order_id'];
                $received_by = $user['id'];
                $actual_delivery = date('Y-m-d H:i:s');
                
                // Получаем заказ
                $stmt = $db->prepare("SELECT * FROM purchase_orders WHERE id = ?");
                $stmt->execute([$order_id]);
                $order = $stmt->fetch();
                
                if (!$order) {
                    throw new Exception('Заказ не найден');
                }
                
                // Обновляем статус и дату получения
                $stmt = $db->prepare("
                    UPDATE purchase_orders 
                    SET status = 'received', actual_delivery = ?, received_by = ?, updated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$actual_delivery, $received_by, $order_id]);
                
                // Оприходуем товары на склад
                $items = json_decode($order['items_json'], true);
                if (is_array($items)) {
                    foreach ($items as $item) {
                        $item_id = $item['item_id'] ?? null;
                        $quantity = $item['quantity'] ?? 0;
                        $price = $item['price'] ?? 0;
                        
                        if ($item_id && $quantity > 0) {
                            // Обновляем остаток
                            $stmt = $db->prepare("UPDATE items SET current_stock = current_stock + ? WHERE id = ?");
                            $stmt->execute([$quantity, $item_id]);
                            
                            // Создаём запись о движении
                            $stmt = $db->prepare("
                                INSERT INTO movements 
                                (movement_type, item_id, quantity, reference_type, reference_id, warehouse_to, employee_id, cost, notes)
                                VALUES ('receipt', ?, ?, 'purchase_order', ?, 'Склад', ?, ?, 'Поступление по заказу {$order['order_number']}')
                            ");
                            $stmt->execute([$item_id, $quantity, $order_id, $user['id'], $quantity * $price]);
                        }
                    }
                }
                
                $successMessage = "Заказ <strong>{$order['order_number']}</strong> получен и оприходован на склад";
                break;
                
            case 'delete_order':
                $order_id = (int)$_POST['order_id'];
                
                $stmt = $db->prepare("DELETE FROM purchase_orders WHERE id = ? AND status = 'draft'");
                $stmt->execute([$order_id]);
                
                $successMessage = "Заказ удалён";
                break;
        }
    } catch (Exception $e) {
        $errorMessage = "Ошибка: " . $e->getMessage();
    }
}

// ==========================================
// ПОЛУЧЕНИЕ ДАННЫХ
// ==========================================

// Все заказы поставщикам
$stmt = $db->query("
    SELECT po.*, p.name as supplier_name, 
           s.first_name as created_by_first, s.last_name as created_by_last,
           r.first_name as received_by_first, r.last_name as received_by_last
    FROM purchase_orders po
    LEFT JOIN partners p ON po.supplier_id = p.id
    LEFT JOIN staff s ON po.created_by = s.id
    LEFT JOIN staff r ON po.received_by = r.id
    ORDER BY po.created_at DESC
");
$orders = $stmt->fetchAll();

// Поставщики
$stmt = $db->query("SELECT id, name FROM partners WHERE partner_type IN ('supplier', 'both') ORDER BY name");
$suppliers = $stmt->fetchAll();

// Материалы для выбора
$stmt = $db->query("
    SELECT i.id, i.item_code, i.name, i.current_stock, u.name as unit_name
    FROM items i
    LEFT JOIN dictionaries u ON i.unit_id = u.id
    WHERE i.item_type = 'material' AND i.is_active = TRUE
    ORDER BY i.name
");
$materials = $stmt->fetchAll();

$pageTitle = 'Заказы поставщикам | PolesieMES';
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
        .status-badge {
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .status-draft { background: #8e8e93; color: white; }
        .status-sent { background: #5ac8fa; color: white; }
        .status-confirmed { background: #30d158; color: white; }
        .status-partial { background: #ffd60a; color: black; }
        .status-received { background: #32ade6; color: white; }
        .status-cancelled { background: #ff453a; color: white; }
        
        .priority-badge {
            padding: 0.25rem 0.5rem;
            border-radius: 8px;
            font-size: 0.7rem;
            font-weight: 600;
        }
        .priority-low { background: #8e8e93; color: white; }
        .priority-normal { background: #32ade6; color: white; }
        .priority-high { background: #ff9f0a; color: black; }
        .priority-urgent { background: #ff453a; color: white; }
        
        .order-card {
            background: var(--glass-bg);
            backdrop-filter: var(--backdrop-blur);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
        }
        
        .order-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.2);
        }
        
        .items-table {
            width: 100%;
            margin-top: 1rem;
            font-size: 0.9rem;
        }
        
        .items-table th {
            font-weight: 600;
            padding: 0.5rem;
            border-bottom: 1px solid var(--glass-border);
        }
        
        .items-table td {
            padding: 0.5rem;
            border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        
        .add-item-row {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            align-items: center;
        }
    </style>
</head>
<body>
    <!-- Анимированный фон -->
    <div class="particles-container">
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
    </div>
    <div class="glow-overlay"></div>
    <div class="grid-overlay"></div>
    
    <!-- Навигация -->
    <nav class="navbar" id="navbar">
        <a href="<?= APP_URL ?>" class="nav-brand">
            <div class="brand-logo">
                <svg viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
            </div>
            <span class="brand-name">PolesieMES</span>
        </a>

        <ul class="nav-menu">
            <li><a href="<?= APP_URL ?>/modules/warehouse/warehouse_dashboard.php" class="nav-link"><i class="fas fa-warehouse"></i> Склад</a></li>
            <li><a href="index.php" class="nav-link active"><i class="fas fa-truck"></i> Поставки</a></li>
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
                <i class="fas fa-sign-out-alt"></i> Выход
            </a>
        </div>

        <button class="mobile-menu-btn" onclick="toggleMobileMenu()">
            <span></span><span></span><span></span>
        </button>
    </nav>

    <!-- Основной контент -->
    <div class="main-content">
        <div class="page-header">
            <div class="page-title">
                <h1><i class="fas fa-truck-loading"></i> Заказы поставщикам</h1>
                <p>Управление ожидаемыми поставками материалов</p>
            </div>
            <div class="page-actions">
                <button class="btn-primary" onclick="showCreateModal()">
                    <i class="fas fa-plus"></i> Создать заказ
                </button>
            </div>
        </div>

        <?php if ($successMessage): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?= $successMessage ?>
        </div>
        <?php endif; ?>
        
        <?php if ($errorMessage): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i> <?= $errorMessage ?>
        </div>
        <?php endif; ?>

        <!-- Список заказов -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-list"></i> Все заказы
                </div>
                <div class="card-stats">
                    <span class="badge bg-secondary">Всего: <?= count($orders) ?></span>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($orders)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>Заказы поставщикам отсутствуют</p>
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>№ заказа</th>
                                <th>Поставщик</th>
                                <th>Дата заказа</th>
                                <th>Ожидаемая доставка</th>
                                <th>Статус</th>
                                <th>Приоритет</th>
                                <th>Сумма</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                            <tr>
                                <td><strong><?= e($order['order_number']) ?></strong></td>
                                <td><?= e($order['supplier_name']) ?></td>
                                <td><?= formatDate($order['order_date']) ?></td>
                                <td><?= $order['expected_delivery'] ? formatDate($order['expected_delivery']) : '-' ?></td>
                                <td>
                                    <span class="status-badge status-<?= $order['status'] ?>">
                                        <?php
                                        $statusNames = [
                                            'draft' => 'Черновик',
                                            'sent' => 'Отправлен',
                                            'confirmed' => 'Подтверждён',
                                            'partial' => 'Частично',
                                            'received' => 'Получен',
                                            'cancelled' => 'Отменён'
                                        ];
                                        echo $statusNames[$order['status']] ?? $order['status'];
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="priority-badge priority-<?= $order['priority'] ?>">
                                        <?php
                                        $priorityNames = [
                                            'low' => 'Низкий',
                                            'normal' => 'Обычный',
                                            'high' => 'Высокий',
                                            'urgent' => 'Срочный'
                                        ];
                                        echo $priorityNames[$order['priority']] ?? $order['priority'];
                                        ?>
                                    </span>
                                </td>
                                <td><strong><?= number_format($order['total_amount'], 2) ?> BYN</strong></td>
                                <td>
                                    <div class="btn-group btn-group-sm">
                                        <?php if ($order['status'] === 'draft'): ?>
                                        <button class="btn btn-info btn-sm" onclick="editOrder(<?= $order['id'] ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                            <input type="hidden" name="new_status" value="sent">
                                            <button type="submit" class="btn btn-primary btn-sm">
                                                <i class="fas fa-paper-plane"></i>
                                            </button>
                                        </form>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Удалить заказ?')">
                                            <input type="hidden" name="action" value="delete_order">
                                            <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                        <?php elseif ($order['status'] === 'sent' || $order['status'] === 'confirmed'): ?>
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="receive_order">
                                            <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                            <button type="submit" class="btn btn-success btn-sm">
                                                <i class="fas fa-check"></i> Получить
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                        <button class="btn btn-secondary btn-sm" onclick="viewItems(<?= htmlspecialchars(json_encode($order['items_json'])) ?>)">
                                            <i class="fas fa-eye"></i> Товары
                                        </button>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Модальное окно создания заказа -->
    <div class="modal fade" id="createOrderModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="create_order">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-plus"></i> Новый заказ поставщику</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Поставщик *</label>
                                <select name="supplier_id" class="form-select" required>
                                    <option value="">Выберите поставщика</option>
                                    <?php foreach ($suppliers as $supplier): ?>
                                    <option value="<?= $supplier['id'] ?>"><?= e($supplier['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Дата заказа</label>
                                <input type="date" name="order_date" class="form-control" value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Ожидаемая доставка</label>
                                <input type="date" name="expected_delivery" class="form-control">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Приоритет</label>
                                <select name="priority" class="form-select">
                                    <option value="low">Низкий</option>
                                    <option value="normal" selected>Обычный</option>
                                    <option value="high">Высокий</option>
                                    <option value="urgent">Срочный</option>
                                </select>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <h6>Товары заказа</h6>
                        <div id="itemsContainer">
                            <div class="add-item-row">
                                <select name="items[0][item_id]" class="form-select item-select" required>
                                    <option value="">Выберите материал</option>
                                    <?php foreach ($materials as $material): ?>
                                    <option value="<?= $material['id'] ?>" 
                                            data-unit="<?= e($material['unit_name']) ?>"
                                            data-code="<?= e($material['item_code']) ?>">
                                        <?= e($material['name']) ?> (<?= e($material['item_code']) ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="number" name="items[0][quantity]" class="form-control" placeholder="Кол-во" min="1" required style="width: 100px;">
                                <input type="number" name="items[0][price]" class="form-control" placeholder="Цена" min="0" step="0.01" required style="width: 120px;">
                                <button type="button" class="btn btn-success btn-sm" onclick="addItemRow()">
                                    <i class="fas fa-plus"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div class="mb-3 mt-3">
                            <label class="form-label">Примечание</label>
                            <textarea name="notes" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Создать заказ
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Модальное окно просмотра товаров -->
    <div class="modal fade" id="viewItemsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Товары заказа</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="viewItemsContent"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let itemIndex = 1;
        
        function showCreateModal() {
            const modal = new bootstrap.Modal(document.getElementById('createOrderModal'));
            modal.show();
        }
        
        function addItemRow() {
            const container = document.getElementById('itemsContainer');
            const newRow = document.createElement('div');
            newRow.className = 'add-item-row';
            newRow.innerHTML = `
                <select name="items[${itemIndex}][item_id]" class="form-select item-select" required>
                    <option value="">Выберите материал</option>
                    <?php foreach ($materials as $material): ?>
                    <option value="<?= $material['id'] ?>"><?= e($material['name']) ?> (<?= e($material['item_code']) ?>)</option>
                    <?php endforeach; ?>
                </select>
                <input type="number" name="items[${itemIndex}][quantity]" class="form-control" placeholder="Кол-во" min="1" required style="width: 100px;">
                <input type="number" name="items[${itemIndex}][price]" class="form-control" placeholder="Цена" min="0" step="0.01" required style="width: 120px;">
                <button type="button" class="btn btn-danger btn-sm" onclick="this.parentElement.remove()">
                    <i class="fas fa-trash"></i>
                </button>
            `;
            container.appendChild(newRow);
            itemIndex++;
        }
        
        function viewItems(itemsJson) {
            const items = JSON.parse(itemsJson);
            let html = '<table class="items-table"><thead><tr><th>Материал</th><th>Кол-во</th><th>Цена</th><th>Сумма</th></tr></thead><tbody>';
            
            items.forEach(item => {
                const sum = (item.quantity || 0) * (item.price || 0);
                html += `<tr>
                    <td>${item.item_name || 'Материал #' + item.item_id}</td>
                    <td>${item.quantity}</td>
                    <td>${item.price} BYN</td>
                    <td><strong>${sum.toFixed(2)} BYN</strong></td>
                </tr>`;
            });
            
            html += '</tbody></table>';
            document.getElementById('viewItemsContent').innerHTML = html;
            
            const modal = new bootstrap.Modal(document.getElementById('viewItemsModal'));
            modal.show();
        }
        
        function editOrder(orderId) {
            alert('Функция редактирования будет добавлена в следующей версии');
        }
    </script>
</body>
</html>
