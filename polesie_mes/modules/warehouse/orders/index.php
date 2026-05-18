<?php
/**
 * Отгрузка готовой продукции клиентам
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        switch ($_POST['action']) {
            case 'ship_order':
                $order_id = (int)$_POST['order_id'];
                $items_to_ship = $_POST['items'] ?? [];
                
                if (empty($items_to_ship)) {
                    throw new Exception('Необходимо выбрать товары для отгрузки');
                }
                
                // Получаем заказ
                $stmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
                $stmt->execute([$order_id]);
                $order = $stmt->fetch();
                
                if (!$order) {
                    throw new Exception('Заказ не найден');
                }
                
                // Обновляем статус заказа
                $newStatus = 'shipped';
                $stmt = $db->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$newStatus, $order_id]);
                
                // Создаём записи об отгрузке для каждого товара
                foreach ($items_to_ship as $itemData) {
                    $product_id = $itemData['product_id'] ?? null;
                    $quantity = (int)($itemData['quantity'] ?? 0);
                    
                    if ($product_id && $quantity > 0) {
                        // Проверяем наличие на складе
                        $stmt = $db->prepare("SELECT current_stock, name FROM items WHERE id = ? AND item_type = 'product'");
                        $stmt->execute([$product_id]);
                        $product = $stmt->fetch();
                        
                        if (!$product) {
                            throw new Exception("Продукт #$product_id не найден");
                        }
                        
                        if ($product['current_stock'] < $quantity) {
                            throw new Exception("Недостаточно товара '{$product['name']}' на складе. Доступно: {$product['current_stock']}");
                        }
                        
                        // Уменьшаем остаток
                        $stmt = $db->prepare("UPDATE items SET current_stock = current_stock - ? WHERE id = ?");
                        $stmt->execute([$quantity, $product_id]);
                        
                        // Создаём запись о движении (отгрузка)
                        $stmt = $db->prepare("
                            INSERT INTO movements 
                            (movement_type, item_id, quantity, reference_type, reference_id, warehouse_from, employee_id, partner_id, notes)
                            VALUES ('shipment', ?, ?, 'order', ?, 'Склад готовой продукции', ?, ?, 'Отгрузка по заказу {$order['order_number']}')
                        ");
                        $stmt->execute([$product_id, $quantity, $order_id, $user['id'], $order['customer_id']]);
                    }
                }
                
                $successMessage = "Заказ <strong>{$order['order_number']}</strong> успешно отгружен клиенту";
                break;
                
            case 'cancel_shipment':
                $order_id = (int)$_POST['order_id'];
                
                $stmt = $db->prepare("UPDATE orders SET status = 'ready' WHERE id = ? AND status = 'shipped'");
                $stmt->execute([$order_id]);
                
                $successMessage = "Отгрузка отменена";
                break;
        }
    } catch (Exception $e) {
        $errorMessage = "Ошибка: " . $e->getMessage();
    }
}

// ==========================================
// ПОЛУЧЕНИЕ ДАННЫХ
// ==========================================

// Заказы готовые к отгрузке
$stmt = $db->query("
    SELECT o.*, p.name as customer_name, p.inn, p.address, p.phone, p.email,
           s.first_name as manager_first, s.last_name as manager_last
    FROM orders o
    LEFT JOIN partners p ON o.customer_id = p.id
    LEFT JOIN staff s ON o.manager_id = s.id
    WHERE o.status IN ('ready', 'shipped')
    ORDER BY 
        CASE o.status WHEN 'ready' THEN 0 WHEN 'shipped' THEN 1 END,
        o.delivery_date ASC,
        o.created_at DESC
");
$readyOrders = $stmt->fetchAll();

// Все отгрузки за последнее время
$stmt = $db->query("
    SELECT mvt.*, i.name as item_name, i.item_code,
           p.name as customer_name,
           s.first_name, s.last_name
    FROM movements mvt
    LEFT JOIN items i ON mvt.item_id = i.id
    LEFT JOIN partners p ON mvt.partner_id = p.id
    LEFT JOIN staff s ON mvt.employee_id = s.id
    WHERE mvt.movement_type = 'shipment'
    ORDER BY mvt.movement_date DESC
    LIMIT 20
");
$recentShipments = $stmt->fetchAll();

$pageTitle = 'Отгрузка | PolesieMES';
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
        .status-ready { background: #30d158; color: white; }
        .status-shipped { background: #32ade6; color: white; }
        
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
        }
        
        .customer-info {
            background: rgba(255,255,255,0.05);
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
            color: var(--text-primary);
        }
        
        .items-to-ship {
            margin-top: 1rem;
        }
        
        .item-row {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 0.75rem;
            background: rgba(255,255,255,0.03);
            border-radius: 8px;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
        }
        
        .item-row label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            flex: 1;
            color: var(--text-primary);
        }
        
        .stock-available {
            font-size: 0.85rem;
            color: var(--text-secondary);
        }
        
        /* Modal Styles - Orange Theme */
        .modal-content {
            background: var(--bg-card);
            backdrop-filter: var(--backdrop-blur);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            box-shadow: var(--shadow-lg);
        }
        
        .modal-header {
            border-bottom: 1px solid var(--glass-border);
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.1), rgba(255, 142, 83, 0.05));
            border-radius: 16px 16px 0 0;
            padding: 1.5rem;
        }
        
        .modal-title {
            color: var(--text-primary);
            font-weight: 600;
            font-size: 1.25rem;
        }
        
        .modal-body {
            padding: 2rem;
            color: var(--text-primary);
        }
        
        .modal-footer {
            border-top: 1px solid var(--glass-border);
            padding: 1.5rem 2rem;
            background: rgba(255, 255, 255, 0.02);
            border-radius: 0 0 16px 16px;
        }
        
        .btn-close {
            filter: invert(1);
        }
        
        /* Form Styles */
        .form-label {
            color: var(--text-primary);
            font-weight: 500;
            margin-bottom: 0.5rem;
            font-size: 0.9rem;
        }
        
        .form-control, .form-select {
            background: var(--bg-input);
            border: 1px solid var(--border);
            color: var(--text-primary);
            border-radius: 10px;
            padding: 0.75rem 1rem;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            background: rgba(30, 30, 45, 0.7);
            border-color: var(--primary-gradient-start);
            box-shadow: 0 0 0 3px rgba(255, 107, 107, 0.2);
            color: var(--text-primary);
        }
        
        .form-control::placeholder {
            color: var(--text-secondary);
        }
        
        .table {
            color: var(--text-primary);
        }
        
        .table thead th {
            color: var(--text-primary);
            border-bottom-color: var(--glass-border);
        }
        
        .table tbody td {
            border-bottom-color: var(--glass-border);
        }
        
        .empty-state {
            text-align: center;
            padding: 3rem;
            color: var(--text-primary);
        }
        
        .empty-state i {
            font-size: 4rem;
            color: var(--text-secondary);
            margin-bottom: 1rem;
        }
        
        .empty-state p {
            font-size: 1.1rem;
            color: var(--text-primary);
        }
        
        .alert {
            border-radius: 12px;
            border: none;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
        }

        .alert-success {
            background: rgba(48, 209, 88, 0.15);
            color: #30d158;
            border: 1px solid rgba(48, 209, 88, 0.3);
        }

        .text-muted {
            color: var(--text-secondary) !important;
        }
        
        .card {
            background: var(--glass-bg);
            backdrop-filter: var(--backdrop-blur);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            margin-bottom: 1.5rem;
        }
        
        .card-header {
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.1), rgba(255, 142, 83, 0.05));
            border-bottom: 1px solid var(--glass-border);
            padding: 1.25rem 1.5rem;
            border-radius: 16px 16px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .card-title {
            color: var(--text-primary);
            font-weight: 600;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .card-body {
            padding: 1.5rem;
            color: var(--text-primary);
        }
        
        .badge {
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .bg-secondary {
            background: rgba(142, 142, 147, 0.3) !important;
            color: var(--text-primary);
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
            <li><a href="index.php" class="nav-link active"><i class="fas fa-truck"></i> Отгрузка</a></li>
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
                <h1><i class="fas fa-shipping-fast"></i> Отгрузка продукции</h1>
                <p>Отгрузка готовой продукции клиентам по заказам</p>
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

        <!-- Заказы к отгрузке -->
        <div class="card">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-box-open"></i> Готовы к отгрузке
                </div>
                <div class="card-stats">
                    <span class="badge bg-secondary">Всего: <?= count($readyOrders) ?></span>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($readyOrders)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>Нет заказов, готовых к отгрузке</p>
                </div>
                <?php else: ?>
                
                <?php foreach ($readyOrders as $order): ?>
                <div class="order-card">
                    <div class="d-flex justify-content-between align-items-start mb-3">
                        <div>
                            <h5 style="margin-bottom: 0.5rem;">
                                <strong><?= e($order['order_number']) ?></strong>
                                <?php if ($order['status'] === 'ready'): ?>
                                    <span class="status-badge status-ready">Готов к отгрузке</span>
                                <?php else: ?>
                                    <span class="status-badge status-shipped">Отгружен</span>
                                <?php endif; ?>
                            </h5>
                            <div style="color: var(--text-primary); font-size: 0.9rem; opacity: 0.8;">
                                <i class="far fa-calendar"></i> Дата заказа: <?= formatDate($order['order_date']) ?>
                                <?php if ($order['delivery_date']): ?>
                                | <i class="far fa-clock"></i> Доставка: <?= formatDate($order['delivery_date']) ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div>
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
                        </div>
                    </div>
                    
                    <div class="customer-info">
                        <div class="row">
                            <div class="col-md-4">
                                <strong><i class="fas fa-building"></i> Клиент:</strong><br>
                                <?= e($order['customer_name']) ?>
                            </div>
                            <div class="col-md-4">
                                <strong><i class="fas fa-file-invoice"></i> ИНН:</strong><br>
                                <?= e($order['inn'] ?? '-') ?>
                            </div>
                            <div class="col-md-4">
                                <strong><i class="fas fa-phone"></i> Телефон:</strong><br>
                                <?= e($order['phone'] ?? '-') ?>
                            </div>
                        </div>
                    </div>
                    
                    <?php
                    $items = json_decode($order['items_json'], true);
                    if (is_array($items)):
                    ?>
                    <form method="POST" class="items-to-ship">
                        <input type="hidden" name="action" value="ship_order">
                        <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                        
                        <h6 style="margin-bottom: 1rem;">Товары к отгрузке:</h6>
                        
                        <?php foreach ($items as $index => $item): 
                            $productId = $item['product_id'] ?? null;
                            
                            // Получаем информацию о продукте и текущий остаток
                            $stmt = $db->prepare("SELECT name, item_code, current_stock, unit_id FROM items WHERE id = ?");
                            $stmt->execute([$productId]);
                            $product = $stmt->fetch();
                            
                            // Получаем единицу измерения
                            $unitName = '';
                            if ($product && $product['unit_id']) {
                                $stmt = $db->prepare("SELECT name FROM dictionaries WHERE id = ?");
                                $stmt->execute([$product['unit_id']]);
                                $unit = $stmt->fetch();
                                $unitName = $unit['name'] ?? '';
                            }
                        ?>
                        <div class="item-row">
                            <label>
                                <?php if ($order['status'] === 'ready'): ?>
                                <input type="checkbox" name="items[<?= $index ?>][product_id]" value="<?= $productId ?>" checked>
                                <?php else: ?>
                                <input type="checkbox" disabled checked>
                                <?php endif; ?>
                                <input type="hidden" name="items[<?= $index ?>][quantity]" value="<?= $item['quantity'] ?>">
                                <span>
                                    <strong><?= e($product['name'] ?? 'Продукт #' . $productId) ?></strong>
                                    <code style="font-size: 0.8rem;"><?= e($product['item_code'] ?? '') ?></code>
                                </span>
                            </label>
                            <div style="text-align: right;">
                                <div><strong><?= $item['quantity'] ?> <?= e($unitName) ?></strong></div>
                                <div class="stock-available">
                                    На складе: <?= number_format($product['current_stock'] ?? 0, 2) ?> <?= e($unitName) ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        
                        <?php if ($order['status'] === 'ready'): ?>
                        <div class="mt-3">
                            <button type="submit" class="btn-success">
                                <i class="fas fa-check"></i> Подтвердить отгрузку
                            </button>
                        </div>
                        <?php else: ?>
                        <div class="mt-3">
                            <form method="POST" style="display:inline;">
                                <input type="hidden" name="action" value="cancel_shipment">
                                <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                <button type="submit" class="btn-warning" onclick="return confirm('Отменить отгрузку?')">
                                    <i class="fas fa-undo"></i> Отменить отгрузку
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </form>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                
                <?php endif; ?>
            </div>
        </div>

        <!-- История отгрузок -->
        <?php if (!empty($recentShipments)): ?>
        <div class="card mt-4">
            <div class="card-header">
                <div class="card-title">
                    <i class="fas fa-history"></i> Последние отгрузки
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Дата</th>
                                <th>Товар</th>
                                <th>Кол-во</th>
                                <th>Клиент</th>
                                <th>Ответственный</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentShipments as $shipment): ?>
                            <tr>
                                <td><?= formatDateTime($shipment['movement_date']) ?></td>
                                <td><?= e($shipment['item_name']) ?></td>
                                <td><strong><?= number_format($shipment['quantity'], 2) ?></strong></td>
                                <td><?= e($shipment['customer_name'] ?? '-') ?></td>
                                <td><?= e($shipment['first_name'] . ' ' . $shipment['last_name'] ?? '-') ?></td>
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
