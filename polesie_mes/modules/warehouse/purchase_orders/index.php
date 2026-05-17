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

// AJAX запрос для получения деталей заказа
if (isset($_GET['action']) && $_GET['action'] === 'get_order_details') {
    header('Content-Type: application/json');
    
    $order_id = (int)($_GET['order_id'] ?? 0);
    
    if ($order_id > 0) {
        $stmt = $db->prepare("
            SELECT po.*, p.name as supplier_name, 
                   s.first_name as created_by_first, s.last_name as created_by_last,
                   r.first_name as received_by_first, r.last_name as received_by_last
            FROM purchase_orders po
            LEFT JOIN partners p ON po.supplier_id = p.id
            LEFT JOIN staff s ON po.created_by = s.id
            LEFT JOIN staff r ON po.received_by = r.id
            WHERE po.id = ?
        ");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch();
        
        if ($order) {
            echo json_encode(['success' => true, 'order' => $order]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Заказ не найден']);
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Некорректный ID заказа']);
    }
    exit;
}

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

// Получаем данные о товарах в заказах для отображения
foreach ($orders as &$order) {
    $items = json_decode($order['items_json'], true);
    if (is_array($items)) {
        foreach ($items as &$item) {
            if (isset($item['item_id'])) {
                $stmt = $db->prepare("SELECT u.name as unit_name FROM items i LEFT JOIN dictionaries u ON i.unit_id = u.id WHERE i.id = ?");
                $stmt->execute([$item['item_id']]);
                $unitData = $stmt->fetch();
                if ($unitData) {
                    $item['unit_name'] = $unitData['unit_name'];
                }
            }
        }
        $order['items_json'] = json_encode($items, JSON_UNESCAPED_UNICODE);
    }
}
unset($order);

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
        
        /* Order Details Grid */
        .order-details-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1.5rem;
        }
        
        .detail-section {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 1.25rem;
            transition: all 0.3s ease;
        }
        
        .detail-section:hover {
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(255, 107, 107, 0.3);
        }
        
        .detail-section.full-width {
            grid-column: 1 / -1;
        }
        
        .detail-section h6 {
            color: var(--primary-gradient-start);
            font-weight: 600;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            font-size: 0.95rem;
            padding-bottom: 0.75rem;
            border-bottom: 1px solid var(--glass-border);
        }
        
        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            gap: 1rem;
        }
        
        .detail-row:not(:last-child) {
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
        }
        
        .detail-label {
            color: var(--text-secondary);
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .detail-value {
            color: var(--text-primary);
            font-size: 0.9rem;
            text-align: right;
        }
        
        .notes-content {
            background: rgba(255, 255, 255, 0.02);
            border-radius: 8px;
            padding: 1rem;
            color: var(--text-primary);
            font-size: 0.9rem;
            line-height: 1.6;
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
            color: var(--text-secondary);
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
            color: var(--text-muted);
        }
        
        /* Item Row Styles */
        .item-row-container {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 1.25rem;
            margin-bottom: 1rem;
            transition: all 0.3s ease;
        }
        
        .item-row-container:hover {
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(255, 107, 107, 0.3);
        }
        
        /* Items table in modal */
        #viewItemsContent table {
            width: 100%;
            margin-bottom: 1rem;
        }
        
        #viewItemsContent th {
            background: rgba(255, 107, 107, 0.2);
            color: var(--primary-gradient-start);
            font-weight: 600;
            padding: 0.75rem;
            border-bottom: 2px solid var(--glass-border);
        }
        
        #viewItemsContent td {
            padding: 0.75rem;
            border-bottom: 1px solid var(--glass-border);
            vertical-align: middle;
        }
        
        #viewItemsContent tr:hover {
            background: rgba(255, 255, 255, 0.03);
        }
        
        .item-row-grid {
            display: grid;
            grid-template-columns: 2fr 1fr 1.2fr 1.2fr 0.5fr;
            gap: 1rem;
            align-items: end;
        }
        
        .item-info-display {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            background: rgba(255, 255, 255, 0.02);
            border-radius: 8px;
            margin-bottom: 0.5rem;
        }
        
        .item-code-badge {
            background: rgba(255, 107, 107, 0.2);
            color: var(--primary-gradient-start);
            padding: 0.25rem 0.5rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .stock-info {
            font-size: 0.8rem;
            color: var(--text-muted);
        }
        
        /* Add Item Button */
        .add-item-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.5rem;
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.2), rgba(255, 142, 83, 0.1));
            border: 1px solid rgba(255, 107, 107, 0.3);
            border-radius: 10px;
            color: var(--primary-gradient-start);
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            width: 100%;
            justify-content: center;
            margin-top: 0.5rem;
        }
        
        .add-item-btn:hover {
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.3), rgba(255, 142, 83, 0.2));
            border-color: rgba(255, 107, 107, 0.5);
            transform: translateY(-2px);
        }
        
        /* Remove Button */
        .remove-item-btn {
            background: rgba(255, 69, 58, 0.2);
            border: 1px solid rgba(255, 69, 58, 0.3);
            color: var(--danger-color);
            width: 42px;
            height: 42px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .remove-item-btn:hover {
            background: rgba(255, 69, 58, 0.3);
            border-color: rgba(255, 69, 58, 0.5);
            transform: scale(1.05);
        }
        
        /* Total Summary */
        .total-summary-section {
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.1), rgba(255, 142, 83, 0.05));
            border: 1px solid rgba(255, 107, 107, 0.3);
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            margin-top: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .total-label {
            font-size: 1rem;
            color: var(--text-secondary);
            font-weight: 500;
        }
        
        .total-amount {
            font-size: 1.75rem;
            font-weight: 700;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        /* Section Divider */
        .section-divider {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin: 1.5rem 0;
            color: var(--text-secondary);
        }
        
        .section-divider::before,
        .section-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--glass-border);
        }
        
        .section-icon {
            width: 32px;
            height: 32px;
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.2), rgba(255, 142, 83, 0.1));
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary-gradient-start);
        }
        
        /* Required Field Indicator */
        .required-field::after {
            content: ' *';
            color: var(--danger-color);
        }
        
        /* Input Group */
        .input-group-custom {
            position: relative;
        }
        
        .input-group-custom .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            pointer-events: none;
        }
        
        .input-group-custom .form-control {
            padding-left: 2.75rem;
        }
        
        /* Items count badge */
        .items-count-badge {
            background: var(--gradient-primary);
            color: white;
            padding: 0.25rem 0.75rem;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            margin-left: 0.5rem;
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
                                <td><strong><?= number_format((float)($order['total_amount'] ?? 0), 2, '.', '') ?> BYN</strong></td>
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
                                        <button class="btn btn-info btn-sm" onclick="viewOrderDetails(<?= $order['id'] ?>)">
                                            <i class="fas fa-info-circle"></i> Детали
                                        </button>
                                        <button class="btn btn-secondary btn-sm" onclick="viewItems(<?= htmlspecialchars(json_encode($order['items_json'])) ?>, <?= $order['id'] ?>)">
                                            <i class="fas fa-boxes"></i> Состав
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
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <form method="POST" id="createOrderForm">
                    <input type="hidden" name="action" value="create_order">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-plus-circle"></i> Новый заказ поставщику</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <!-- Основная информация -->
                        <div class="section-divider">
                            <div class="section-icon"><i class="fas fa-info-circle"></i></div>
                            <span>Основная информация</span>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label required-field">Поставщик</label>
                                <div class="input-group-custom">
                                    <i class="fas fa-truck input-icon"></i>
                                    <select name="supplier_id" class="form-select" required>
                                        <option value="">Выберите поставщика</option>
                                        <?php foreach ($suppliers as $supplier): ?>
                                        <option value="<?= $supplier['id'] ?>"><?= e($supplier['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Дата заказа</label>
                                <div class="input-group-custom">
                                    <i class="fas fa-calendar input-icon"></i>
                                    <input type="date" name="order_date" class="form-control" value="<?= date('Y-m-d') ?>">
                                </div>
                            </div>
                            
                            <div class="col-md-3 mb-3">
                                <label class="form-label">Ожидаемая доставка</label>
                                <div class="input-group-custom">
                                    <i class="fas fa-clock input-icon"></i>
                                    <input type="date" name="expected_delivery" class="form-control">
                                </div>
                            </div>
                            
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Приоритет</label>
                                <select name="priority" class="form-select">
                                    <option value="low">🔽 Низкий</option>
                                    <option value="normal" selected>➡️ Обычный</option>
                                    <option value="high">⬆️ Высокий</option>
                                    <option value="urgent">🔥 Срочный</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Товары заказа -->
                        <div class="section-divider">
                            <div class="section-icon"><i class="fas fa-boxes"></i></div>
                            <span>Товары заказа <span id="itemsCountBadge" class="items-count-badge">0</span></span>
                        </div>
                        
                        <div id="itemsContainer">
                            <!-- Items will be added here dynamically -->
                        </div>
                        
                        <button type="button" class="add-item-btn" onclick="addItemRow()">
                            <i class="fas fa-plus-circle"></i> Добавить товар
                        </button>
                        
                        <!-- Итоговая сумма -->
                        <div class="total-summary-section">
                            <span class="total-label"><i class="fas fa-calculator"></i> Итого:</span>
                            <span class="total-amount" id="totalAmountDisplay">0.00 BYN</span>
                        </div>
                        
                        <!-- Примечание -->
                        <div class="mb-3 mt-4">
                            <label class="form-label"><i class="fas fa-comment-alt"></i> Примечание</label>
                            <textarea name="notes" class="form-control" rows="2" placeholder="Дополнительная информация по заказу..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times"></i> Отмена
                        </button>
                        <button type="submit" class="btn btn-primary" style="background: var(--gradient-primary); border: none;">
                            <i class="fas fa-save"></i> Создать заказ
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Модальное окно просмотра деталей заказа -->
    <div class="modal fade" id="orderDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file-invoice"></i> Детали заказа <span id="orderNumberDisplay"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="orderDetailsContent">
                    <!-- Content will be populated dynamically -->
                </div>
            </div>
        </div>
    </div>

    <!-- Модальное окно просмотра товаров -->
    <div class="modal fade" id="viewItemsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-boxes"></i> Состав поставки</h5>
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
        let itemIndex = 0;
        const materialsData = <?= json_encode($materials) ?>;
        
        function showCreateModal() {
            const modal = new bootstrap.Modal(document.getElementById('createOrderModal'));
            modal.show();
            // Add first item row when modal opens
            if (itemIndex === 0) {
                addItemRow();
            }
        }
        
        function addItemRow() {
            const container = document.getElementById('itemsContainer');
            const newRow = document.createElement('div');
            newRow.className = 'item-row-container';
            newRow.dataset.index = itemIndex;
            
            let materialOptions = '<option value="">Выберите материал</option>';
            materialsData.forEach(mat => {
                materialOptions += `<option value="${mat.id}" 
                    data-code="${mat.item_code}" 
                    data-unit="${mat.unit_name || 'шт'}"
                    data-stock="${mat.current_stock || 0}">${mat.name} (${mat.item_code})</option>`;
            });
            
            newRow.innerHTML = `
                <div class="item-row-grid">
                    <div>
                        <label class="form-label" style="font-size: 0.8rem;">Материал</label>
                        <select name="items[${itemIndex}][item_id]" class="form-select item-select" required onchange="updateItemInfo(this)">
                            ${materialOptions}
                        </select>
                        <div class="item-info-display" style="display: none; margin-top: 0.5rem;">
                            <span class="item-code-badge"></span>
                            <span class="stock-info">Остаток: <strong></strong></span>
                        </div>
                    </div>
                    <div>
                        <label class="form-label" style="font-size: 0.8rem;">Кол-во</label>
                        <input type="number" name="items[${itemIndex}][quantity]" class="form-control" placeholder="0" min="1" step="0.01" required oninput="calculateTotal()">
                    </div>
                    <div>
                        <label class="form-label" style="font-size: 0.8rem;">Цена (BYN)</label>
                        <input type="number" name="items[${itemIndex}][price]" class="form-control" placeholder="0.00" min="0" step="0.01" required oninput="calculateTotal()">
                    </div>
                    <div>
                        <label class="form-label" style="font-size: 0.8rem;">Сумма</label>
                        <div class="form-control" style="background: rgba(255, 107, 107, 0.1); border-color: rgba(255, 107, 107, 0.3); font-weight: 600;" readonly>
                            <span class="row-total">0.00</span> BYN
                        </div>
                    </div>
                    <div>
                        <button type="button" class="remove-item-btn" onclick="removeItemRow(this)" title="Удалить строку">
                            <i class="fas fa-trash-alt"></i>
                        </button>
                    </div>
                </div>
            `;
            container.appendChild(newRow);
            itemIndex++;
            updateItemsCount();
        }
        
        function removeItemRow(button) {
            const row = button.closest('.item-row-container');
            row.remove();
            updateItemsCount();
            calculateTotal();
        }
        
        function updateItemInfo(select) {
            const row = select.closest('.item-row-container');
            const infoDisplay = row.querySelector('.item-info-display');
            const codeBadge = row.querySelector('.item-code-badge');
            const stockInfo = row.querySelector('.stock-info strong');
            
            const selectedOption = select.options[select.selectedIndex];
            if (selectedOption.value) {
                infoDisplay.style.display = 'flex';
                codeBadge.textContent = selectedOption.dataset.code;
                stockInfo.textContent = selectedOption.dataset.stock + ' ' + (selectedOption.dataset.unit || 'шт');
            } else {
                infoDisplay.style.display = 'none';
            }
        }
        
        function calculateTotal() {
            let total = 0;
            document.querySelectorAll('.item-row-container').forEach(row => {
                const quantityInput = row.querySelector('input[name*="[quantity]"]');
                const priceInput = row.querySelector('input[name*="[price]"]');
                const totalSpan = row.querySelector('.row-total');
                
                const quantity = parseFloat(quantityInput.value) || 0;
                const price = parseFloat(priceInput.value) || 0;
                const rowTotal = quantity * price;
                
                if (totalSpan) {
                    totalSpan.textContent = rowTotal.toFixed(2);
                }
                total += rowTotal;
            });
            
            document.getElementById('totalAmountDisplay').textContent = total.toFixed(2) + ' BYN';
        }
        
        function updateItemsCount() {
            const count = document.querySelectorAll('.item-row-container').length;
            document.getElementById('itemsCountBadge').textContent = count;
        }
        
        function viewItems(itemsJson, orderId = null) {
            const items = JSON.parse(itemsJson);
            let html = '<div class="table-responsive"><table class="table table-hover"><thead><tr><th>#</th><th>Материал</th><th>Артикул</th><th>Кол-во</th><th>Ед.</th><th>Цена</th><th>Сумма</th></tr></thead><tbody>';
            
            let total = 0;
            items.forEach((item, index) => {
                const sum = (item.quantity || 0) * (item.price || 0);
                total += sum;
                html += `<tr>
                    <td>${index + 1}</td>
                    <td><strong>${item.item_name || 'Материал #' + item.item_id}</strong></td>
                    <td><span class="item-code-badge">${item.item_code || '-'}</span></td>
                    <td>${item.quantity}</td>
                    <td>${item.unit_name || '-'}</td>
                    <td>${parseFloat(item.price).toFixed(2)} BYN</td>
                    <td><strong>${sum.toFixed(2)} BYN</strong></td>
                </tr>`;
            });
            
            html += '</tbody></table></div>';
            html += `<div class="total-summary-section"><span class="total-label"><i class="fas fa-calculator"></i> Итого:</span><span class="total-amount">${total.toFixed(2)} BYN</span></div>`;
            
            document.getElementById('viewItemsContent').innerHTML = html;
            
            const modal = new bootstrap.Modal(document.getElementById('viewItemsModal'));
            modal.show();
        }
        
        function viewOrderDetails(orderId) {
            // Fetch order details via AJAX or use embedded data
            fetch(`?action=get_order_details&order_id=${orderId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const order = data.order;
                        const items = JSON.parse(order.items_json || '[]');
                        
                        let itemsHtml = '<div class="table-responsive"><table class="table table-sm"><thead><tr><th>Материал</th><th>Артикул</th><th>Кол-во</th><th>Цена</th><th>Сумма</th></tr></thead><tbody>';
                        let itemsTotal = 0;
                        items.forEach(item => {
                            const sum = (item.quantity || 0) * (item.price || 0);
                            itemsTotal += sum;
                            itemsHtml += `<tr>
                                <td>${item.item_name || 'Материал #' + item.item_id}</td>
                                <td><span class="item-code-badge">${item.item_code || '-'}</span></td>
                                <td>${item.quantity} ${item.unit_name || ''}</td>
                                <td>${parseFloat(item.price).toFixed(2)} BYN</td>
                                <td><strong>${sum.toFixed(2)} BYN</strong></td>
                            </tr>`;
                        });
                        itemsHtml += '</tbody></table></div>';
                        
                        const statusNames = {
                            'draft': 'Черновик',
                            'sent': 'Отправлен',
                            'confirmed': 'Подтверждён',
                            'partial': 'Частично получен',
                            'received': 'Получен',
                            'cancelled': 'Отменён'
                        };
                        
                        const priorityNames = {
                            'low': 'Низкий',
                            'normal': 'Обычный',
                            'high': 'Высокий',
                            'urgent': 'Срочный'
                        };
                        
                        const statusClass = {
                            'draft': 'status-draft',
                            'sent': 'status-sent',
                            'confirmed': 'status-confirmed',
                            'partial': 'status-partial',
                            'received': 'status-received',
                            'cancelled': 'status-cancelled'
                        };
                        
                        document.getElementById('orderNumberDisplay').textContent = '#' + order.order_number;
                        document.getElementById('orderDetailsContent').innerHTML = `
                            <div class="order-details-grid">
                                <div class="detail-section">
                                    <h6><i class="fas fa-info-circle"></i> Основная информация</h6>
                                    <div class="detail-row">
                                        <span class="detail-label">Номер заказа:</span>
                                        <span class="detail-value"><strong>${order.order_number}</strong></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Статус:</span>
                                        <span class="detail-value"><span class="status-badge ${statusClass[order.status] || ''}">${statusNames[order.status] || order.status}</span></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Приоритет:</span>
                                        <span class="detail-value"><span class="priority-badge priority-${order.priority}">${priorityNames[order.priority] || order.priority}</span></span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Поставщик:</span>
                                        <span class="detail-value">${order.supplier_name || 'Не указан'}</span>
                                    </div>
                                </div>
                                
                                <div class="detail-section">
                                    <h6><i class="fas fa-calendar-alt"></i> Даты</h6>
                                    <div class="detail-row">
                                        <span class="detail-label">Дата заказа:</span>
                                        <span class="detail-value">${order.order_date ? new Date(order.order_date).toLocaleDateString('ru-RU') : '-'}</span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Ожидаемая доставка:</span>
                                        <span class="detail-value">${order.expected_delivery ? new Date(order.expected_delivery).toLocaleDateString('ru-RU') : 'Не указана'}</span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Фактическая доставка:</span>
                                        <span class="detail-value">${order.actual_delivery ? new Date(order.actual_delivery).toLocaleString('ru-RU') : '-'}</span>
                                    </div>
                                </div>
                                
                                <div class="detail-section">
                                    <h6><i class="fas fa-users"></i> Сотрудники</h6>
                                    <div class="detail-row">
                                        <span class="detail-label">Создан:</span>
                                        <span class="detail-value">${order.created_by_first || ''} ${order.created_by_last || ''}</span>
                                    </div>
                                    <div class="detail-row">
                                        <span class="detail-label">Получил:</span>
                                        <span class="detail-value">${order.received_by_first || ''} ${order.received_by_last || ''}</span>
                                    </div>
                                </div>
                                
                                <div class="detail-section full-width">
                                    <h6><i class="fas fa-boxes"></i> Состав поставки (${items.length} поз.)</h6>
                                    ${itemsHtml}
                                    <div class="total-summary-section">
                                        <span class="total-label"><i class="fas fa-calculator"></i> Общая сумма:</span>
                                        <span class="total-amount">${itemsTotal.toFixed(2)} BYN</span>
                                    </div>
                                </div>
                                
                                ${order.notes ? `
                                <div class="detail-section full-width">
                                    <h6><i class="fas fa-comment-alt"></i> Примечание</h6>
                                    <div class="notes-content">${order.notes}</div>
                                </div>
                                ` : ''}
                            </div>
                        `;
                        
                        const modal = new bootstrap.Modal(document.getElementById('orderDetailsModal'));
                        modal.show();
                    } else {
                        alert('Ошибка загрузки данных заказа');
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Ошибка при загрузке данных заказа');
                });
        }
        
        function editOrder(orderId) {
            alert('Функция редактирования будет добавлена в следующей версии');
        }
        
        // Initialize form validation
        document.getElementById('createOrderForm').addEventListener('submit', function(e) {
            const supplierSelect = this.querySelector('select[name="supplier_id"]');
            const itemSelects = this.querySelectorAll('.item-select');
            let hasItems = false;
            
            itemSelects.forEach(select => {
                if (select.value) hasItems = true;
            });
            
            if (!supplierSelect.value) {
                e.preventDefault();
                supplierSelect.focus();
                alert('Выберите поставщика');
                return false;
            }
            
            if (!hasItems) {
                e.preventDefault();
                alert('Добавьте хотя бы один товар');
                return false;
            }
        });
    </script>
</body>
</html>
