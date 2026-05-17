<?php
/**
 * Склад - Расход материалов (Полнофункциональная версия)
 * PolesieMES - Система управления производством ОАО "Полесьеэлектромаш"
 *
 * Функционал:
 * - Списание материалов по заказу на производство
 * - Прямое списание без привязки к заказу
 * - Пакетное списание нескольких материалов
 * - Автоматическая проверка остатков
 * - История последних списаний
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
$successMessage = '';

// Получение списка всех материалов с остатками
$stmt = $db->query("
    SELECT i.id, i.name, i.item_code, i.current_stock, i.min_stock, 
           u.name as unit_name, d.name as category_name
    FROM items i
    LEFT JOIN dictionaries u ON i.unit_id = u.id AND u.dict_type = 'unit'
    LEFT JOIN dictionaries d ON i.category_id = d.id AND d.dict_type = 'category'
    WHERE i.item_type = 'material' AND i.is_active = 1 
    ORDER BY i.name
");
$materials = $stmt->fetchAll();

// Получение активных заказов на производство
$stmt = $db->query("
    SELECT o.id, o.order_number, o.product_name, o.quantity as product_quantity, 
           o.status, o.created_at,
           CASE o.status
               WHEN 'new' THEN 'Новый'
               WHEN 'in_progress' THEN 'В производстве'
               WHEN 'completed' THEN 'Завершён'
               WHEN 'cancelled' THEN 'Отменён'
               ELSE o.status
           END as status_name
    FROM production_orders o
    WHERE o.status IN ('new', 'in_progress')
    ORDER BY o.created_at DESC
");
$productionOrders = $stmt->fetchAll();

// Последние операции списания
$stmt = $db->query("
    SELECT mvt.*, i.name as material_name, i.item_code, u.name as unit_name,
           s.first_name, s.last_name,
           po.order_number as production_order_number, po.product_name
    FROM movements mvt
    LEFT JOIN items i ON mvt.item_id = i.id
    LEFT JOIN dictionaries u ON i.unit_id = u.id AND u.dict_type = 'unit'
    LEFT JOIN staff s ON mvt.employee_id = s.id
    LEFT JOIN production_orders po ON mvt.reference_id = po.id AND mvt.reference_type = 'production_order'
    WHERE mvt.movement_type = 'consumption'
    ORDER BY mvt.movement_date DESC
    LIMIT 10
");
$recentConsumptions = $stmt->fetchAll();

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $consumption_items = $_POST['consumption_items'] ?? [];
    $production_order_id = $_POST['production_order_id'] ?? null;
    $notes = trim($_POST['notes'] ?? '');
    
    // Фильтрация пустых значений
    $consumption_items = array_filter($consumption_items, function($item) {
        return !empty($item['item_id']) && !empty($item['quantity']) && floatval($item['quantity']) > 0;
    });
    
    if (empty($consumption_items)) {
        $errors[] = 'Выберите хотя бы один материал и укажите количество';
    } else {
        // Проверка остатков для всех материалов
        foreach ($consumption_items as &$item) {
            $item_id = intval($item['item_id']);
            $quantity = floatval($item['quantity']);
            
            $stmt = $db->prepare("SELECT current_stock, name, item_code FROM items WHERE id = :id");
            $stmt->execute(['id' => $item_id]);
            $material = $stmt->fetch();
            
            if (!$material) {
                $errors[] = "Материал с ID {$item_id} не найден";
            } elseif ($material['current_stock'] < $quantity) {
                $errors[] = "Недостаточно материала '{$material['name']}' на складе. Доступно: {$material['current_stock']}, запрошено: {$quantity}";
            } else {
                $item['material_name'] = $material['name'];
                $item['material_code'] = $material['item_code'];
            }
        }
    }
    
    if (empty($errors)) {
        try {
            $db->beginTransaction();
            
            foreach ($consumption_items as $item) {
                $item_id = intval($item['item_id']);
                $quantity = floatval($item['quantity']);
                
                // Обновление остатка
                $stmt = $db->prepare("UPDATE items SET current_stock = current_stock - :qty WHERE id = :id");
                $stmt->execute(['qty' => $quantity, 'id' => $item_id]);
                
                // Запись в историю движений
                $stmt = $db->prepare("
                    INSERT INTO movements (movement_type, item_id, quantity, reference_type, reference_id, notes, employee_id, movement_date) 
                    VALUES ('consumption', :item_id, :qty, :ref_type, :ref_id, :notes, :emp_id, NOW())
                ");
                $stmt->execute([
                    'item_id' => $item_id,
                    'qty' => $quantity,
                    'ref_type' => $production_order_id ? 'production_order' : 'manual',
                    'ref_id' => $production_order_id,
                    'notes' => $notes,
                    'emp_id' => $_SESSION['user_id']
                ]);
            }
            
            $db->commit();
            $success = true;
            $successMessage = 'Материалы успешно списаны';
            
            // Обновление списка материалов после списания
            $stmt = $db->query("
                SELECT i.id, i.name, i.item_code, i.current_stock, i.min_stock, 
                       u.name as unit_name, d.name as category_name
                FROM items i
                LEFT JOIN dictionaries u ON i.unit_id = u.id AND u.dict_type = 'unit'
                LEFT JOIN dictionaries d ON i.category_id = d.id AND d.dict_type = 'category'
                WHERE i.item_type = 'material' AND i.is_active = 1 
                ORDER BY i.name
            ");
            $materials = $stmt->fetchAll();
            
            // Обновление истории
            $stmt = $db->query("
                SELECT mvt.*, i.name as material_name, i.item_code, u.name as unit_name,
                       s.first_name, s.last_name,
                       po.order_number as production_order_number, po.product_name
                FROM movements mvt
                LEFT JOIN items i ON mvt.item_id = i.id
                LEFT JOIN dictionaries u ON i.unit_id = u.id AND u.dict_type = 'unit'
                LEFT JOIN staff s ON mvt.employee_id = s.id
                LEFT JOIN production_orders po ON mvt.reference_id = po.id AND mvt.reference_type = 'production_order'
                WHERE mvt.movement_type = 'consumption'
                ORDER BY mvt.movement_date DESC
                LIMIT 10
            ");
            $recentConsumptions = $stmt->fetchAll();
            
        } catch (Exception $e) {
            $db->rollBack();
            $errors[] = 'Ошибка при списании: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'Расход материалов | Склад | ' . APP_NAME;
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
        .consumption-form-container {
            background: var(--glass-bg);
            backdrop-filter: var(--backdrop-blur);
            border-radius: 16px;
            padding: 2rem;
            border: 1px solid var(--glass-border);
            margin-bottom: 2rem;
        }
        
        .item-row {
            display: grid;
            grid-template-columns: 2fr 1fr 40px;
            gap: 1rem;
            align-items: center;
            margin-bottom: 1rem;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 12px;
            border: 1px solid transparent;
            transition: all 0.3s ease;
        }
        
        .item-row:hover {
            background: rgba(255, 255, 255, 0.05);
            border-color: var(--border);
        }
        
        .btn-add-item {
            background: linear-gradient(135deg, #30d158, #24a945);
            border: none;
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .btn-add-item:hover {
            background: linear-gradient(135deg, #24a945, #1a8c3a);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(48, 209, 88, 0.4);
            color: white;
        }
        
        .btn-remove-item {
            background: linear-gradient(135deg, #ff453a, #ff3b30);
            border: none;
            color: white;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .btn-remove-item:hover {
            background: linear-gradient(135deg, #ff3b30, #ff2d22);
            transform: scale(1.1);
            box-shadow: 0 4px 15px rgba(255, 69, 58, 0.4);
        }
        
        .stock-indicator {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }
        
        .stock-indicator.low {
            color: var(--warning-color);
        }
        
        .stock-indicator.critical {
            color: var(--danger-color);
        }
        
        .history-card {
            background: var(--glass-bg);
            backdrop-filter: var(--backdrop-blur);
            border-radius: 16px;
            padding: 1.5rem;
            border: 1px solid var(--glass-border);
        }
        
        .history-item {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.03);
            border-radius: 12px;
            margin-bottom: 0.75rem;
            transition: all 0.3s ease;
        }
        
        .history-item:hover {
            background: rgba(255, 255, 255, 0.05);
            transform: translateX(5px);
        }
        
        .history-icon {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, rgba(255, 214, 10, 0.2), rgba(255, 159, 10, 0.2));
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--warning-color);
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        
        .history-details {
            flex: 1;
        }
        
        .history-details h5 {
            font-size: 0.95rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .history-details p {
            font-size: 0.8rem;
            color: var(--text-muted);
            margin: 0;
        }
        
        .history-amount {
            font-weight: 700;
            color: var(--danger-color);
        }
        
        .history-time {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-align: right;
        }
        
        .form-select, .form-control {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid var(--glass-border);
            color: var(--text-primary);
            padding: 0.75rem 1rem;
            border-radius: 10px;
        }
        
        .form-select:focus, .form-control:focus {
            background: rgba(255, 255, 255, 0.08);
            border-color: var(--primary-glow);
            color: var(--text-primary);
            box-shadow: 0 0 0 3px rgba(90, 200, 250, 0.2);
        }
        
        .form-select option {
            background: #1c1c1e;
            color: var(--text-primary);
        }
        
        .btn-submit-consumption {
            background: linear-gradient(135deg, #ffd60a, #ff9f0a);
            border: none;
            color: #000;
            padding: 1rem 2rem;
            border-radius: 12px;
            font-weight: 700;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
            width: 100%;
            justify-content: center;
            margin-top: 1.5rem;
        }
        
        .btn-submit-consumption:hover {
            background: linear-gradient(135deg, #ff9f0a, #ff8f00);
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(255, 214, 10, 0.4);
            color: #000;
        }
        
        .order-select-section {
            margin-bottom: 2rem;
            padding: 1.5rem;
            background: rgba(90, 200, 250, 0.05);
            border-radius: 12px;
            border: 1px solid rgba(90, 200, 250, 0.2);
        }
    </style>
</head>
<body>
    <div class="particles-container"><div class="particle"></div><div class="particle"></div></div>
    <div class="glow-overlay"></div><div class="grid-overlay"></div>
    
    <nav class="navbar">
        <a href="<?= APP_URL ?>" class="nav-brand"><div class="brand-logo"><svg viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg></div><span class="brand-name">PolesieMES</span></a>
        <ul class="nav-menu">
            <li><a href="<?= APP_URL ?>/modules/warehouse/warehouse_dashboard.php" class="nav-link"><i class="fas fa-warehouse"></i> Склад</a></li>
            <li><a href="consumption.php" class="nav-link active"><i class="fas fa-dolly"></i> Расход</a></li>
        </ul>
        <div class="user-menu">
            <span><?= e($_SESSION['full_name']) ?> (<?= e(getRoleName($_SESSION['role'])) ?>)</span>
            <a href="<?= APP_URL ?>/modules/auth/logout.php" class="btn-logout">Выход</a>
        </div>
    </nav>

    <div class="main-content">
        <div class="page-header">
            <h1><i class="fas fa-dolly"></i> Расход материалов</h1>
            <a href="index.php" class="btn-primary-custom"><i class="fas fa-arrow-left"></i> Назад</a>
        </div>

        <?php if ($success): ?>
        <div class="alert alert-success d-flex align-items-center">
            <i class="fas fa-check-circle me-2"></i>
            <div><?= e($successMessage) ?></div>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <?php foreach ($errors as $err): ?>
            <div><i class="fas fa-exclamation-circle me-2"></i><?= e($err) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="consumption-form-container">
            <form method="POST" id="consumptionForm">
                <!-- Выбор заказа на производство (опционально) -->
                <?php if (!empty($productionOrders)): ?>
                <div class="order-select-section">
                    <label class="form-label"><i class="fas fa-clipboard-list me-2"></i>Привязать к заказу на производство (необязательно)</label>
                    <select name="production_order_id" class="form-select">
                        <option value="">-- Без привязки к заказу --</option>
                        <?php foreach ($productionOrders as $order): ?>
                        <option value="<?= $order['id'] ?>">
                            <?= e($order['order_number']) ?> - <?= e($order['product_name']) ?> 
                            (<?= e($order['status_name']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>

                <!-- Список материалов для списания -->
                <div class="mb-3">
                    <label class="form-label"><i class="fas fa-boxes me-2"></i>Материалы для списания</label>
                    <div id="itemsContainer">
                        <div class="item-row">
                            <div>
                                <select name="consumption_items[0][item_id]" class="form-select item-select" required>
                                    <option value="">Выберите материал</option>
                                    <?php foreach ($materials as $m): ?>
                                    <option value="<?= $m['id'] ?>" 
                                            data-stock="<?= $m['current_stock'] ?>" 
                                            data-unit="<?= e($m['unit_name'] ?? 'шт.') ?>"
                                            data-min="<?= $m['min_stock'] ?>">
                                        <?= e($m['name']) ?> (<?= e($m['item_code']) ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="stock-indicator"></div>
                            </div>
                            <div>
                                <input type="number" step="0.01" name="consumption_items[0][quantity]" class="form-control quantity-input" placeholder="Количество" required min="0.01">
                            </div>
                            <button type="button" class="btn-remove-item" style="visibility: hidden;">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                    
                    <button type="button" class="btn-add-item" onclick="addItemRow()">
                        <i class="fas fa-plus"></i> Добавить материал
                    </button>
                </div>

                <!-- Примечание -->
                <div class="mb-3">
                    <label class="form-label"><i class="fas fa-comment-alt me-2"></i>Примечание</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="Например: Заказ №..., Брак, Порча и т.д."></textarea>
                </div>

                <button type="submit" class="btn-submit-consumption">
                    <i class="fas fa-minus-circle"></i> Списать материалы
                </button>
            </form>
        </div>

        <!-- История последних списаний -->
        <?php if (!empty($recentConsumptions)): ?>
        <div class="history-card">
            <h3 class="mb-4"><i class="fas fa-history me-2"></i>Последние операции списания</h3>
            <div class="history-list">
                <?php foreach ($recentConsumptions as $consumption): ?>
                <div class="history-item">
                    <div class="history-icon">
                        <i class="fas fa-dolly"></i>
                    </div>
                    <div class="history-details">
                        <h5><?= e($consumption['material_name']) ?> <small class="text-muted">(<?= e($consumption['material_code']) ?>)</small></h5>
                        <p>
                            <?php if ($consumption['production_order_number']): ?>
                                <i class="fas fa-clipboard-list me-1"></i>Заказ <?= e($consumption['production_order_number']) ?>
                                <?php if ($consumption['employee_id']): ?>
                                    | <i class="fas fa-user me-1"></i><?= e($consumption['first_name'] . ' ' . $consumption['last_name']) ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <i class="fas fa-edit me-1"></i>Ручное списание
                                <?php if ($consumption['employee_id']): ?>
                                    | <i class="fas fa-user me-1"></i><?= e($consumption['first_name'] . ' ' . $consumption['last_name']) ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div class="history-amount">-<?= number_format($consumption['quantity'], 2) ?> <?= e($consumption['unit_name'] ?? 'шт.') ?></div>
                    <div class="history-time"><?= date('d.m.Y H:i', strtotime($consumption['movement_date'])) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let itemCount = 1;
        
        function addItemRow() {
            const container = document.getElementById('itemsContainer');
            const newRow = document.createElement('div');
            newRow.className = 'item-row';
            newRow.innerHTML = `
                <div>
                    <select name="consumption_items[${itemCount}][item_id]" class="form-select item-select" required>
                        <option value="">Выберите материал</option>
                        <?php foreach ($materials as $m): ?>
                        <option value="<?= $m['id'] ?>" 
                                data-stock="<?= $m['current_stock'] ?>" 
                                data-unit="<?= e($m['unit_name'] ?? 'шт.') ?>"
                                data-min="<?= $m['min_stock'] ?>">
                            <?= e($m['name']) ?> (<?= e($m['item_code']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="stock-indicator"></div>
                </div>
                <div>
                    <input type="number" step="0.01" name="consumption_items[${itemCount}][quantity]" class="form-control quantity-input" placeholder="Количество" required min="0.01">
                </div>
                <button type="button" class="btn-remove-item" onclick="removeItemRow(this)">
                    <i class="fas fa-trash"></i>
                </button>
            `;
            container.appendChild(newRow);
            itemCount++;
            
            // Добавляем обработчики событий для новых элементов
            attachSelectListeners(newRow.querySelector('.item-select'));
        }
        
        function removeItemRow(button) {
            const rows = document.querySelectorAll('.item-row');
            if (rows.length > 1) {
                button.closest('.item-row').remove();
            } else {
                alert('Должна быть хотя бы одна строка с материалом');
            }
        }
        
        function updateStockIndicator(selectElement) {
            const selectedOption = selectElement.options[selectElement.selectedIndex];
            const indicatorDiv = selectElement.parentElement.querySelector('.stock-indicator');
            
            if (selectedOption.value) {
                const stock = parseFloat(selectedOption.dataset.stock);
                const unit = selectedOption.dataset.unit;
                const minStock = parseFloat(selectedOption.dataset.min);
                
                let statusClass = '';
                let statusText = '';
                
                if (stock <= 0) {
                    statusClass = 'critical';
                    statusText = `⚠️ Нет на складе`;
                } else if (stock < minStock) {
                    statusClass = 'critical';
                    statusText = `⚠️ Мало: ${stock} ${unit}`;
                } else if (stock < minStock * 1.5) {
                    statusClass = 'low';
                    statusText = `✓ ${stock} ${unit}`;
                } else {
                    statusText = `✓ ${stock} ${unit}`;
                }
                
                indicatorDiv.className = `stock-indicator ${statusClass}`;
                indicatorDiv.textContent = statusText;
            } else {
                indicatorDiv.className = 'stock-indicator';
                indicatorDiv.textContent = '';
            }
        }
        
        function attachSelectListeners(selectElement) {
            selectElement.addEventListener('change', function() {
                updateStockIndicator(this);
            });
            // Вызываем сразу для текущих элементов
            updateStockIndicator(selectElement);
        }
        
        // Инициализация обработчиков для существующих элементов
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.item-select').forEach(attachSelectListeners);
        });
    </script>
</body>
</html>
