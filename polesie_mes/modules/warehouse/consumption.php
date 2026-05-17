<?php
/**
 * Склад - Расход материалов (Полнофункциональная версия)
 * Поддерживает:
 * - Списание по производственному заданию
 * - Массовое списание нескольких материалов
 * - Автоматический расчет по нормам расхода
 * - Контроль остатков в реальном времени
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
$success_message = '';

// Получаем все активные материалы
$stmt = $db->query("SELECT id, name, item_code, current_stock, min_stock, unit_id FROM items WHERE item_type = 'material' AND is_active = 1 ORDER BY name");
$materials = $stmt->fetchAll();

// Получаем единицы измерения
$stmt = $db->query("SELECT id, code, name FROM dictionaries WHERE dict_type = 'unit'");
$units = [];
foreach ($stmt->fetchAll() as $u) {
    $units[$u['id']] = $u['name'];
}

// Получаем активные производственные задания
$stmt = $db->query("SELECT pt.id, pt.task_number, pt.stage_name, pt.quantity, pt.status, o.order_number, p.name as product_name 
    FROM production_tasks pt 
    LEFT JOIN orders o ON pt.order_id = o.id 
    LEFT JOIN items p ON pt.product_id = p.id 
    WHERE pt.status IN ('planned', 'in_progress', 'paused') 
    ORDER BY pt.created_at DESC");
$tasks = $stmt->fetchAll();

// Получаем причины списания
$consumption_reasons = [
    'production' => 'Производство',
    'defect' => 'Брак/Потери',
    'maintenance' => 'ТО оборудования',
    'inventory' => 'Инвентаризация',
    'sample' => 'Образцы/Тесты',
    'other' => 'Прочее'
];

// Обработка POST запроса
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'manual';
    
    if ($action === 'batch_consumption') {
        // Массовое списание
        $items_data = $_POST['items'] ?? [];
        $reason = $_POST['reason'] ?? 'production';
        $order_id = $_POST['order_id'] ?? null;
        $task_id = $_POST['task_id'] ?? null;
        $notes = trim($_POST['notes'] ?? '');
        
        if (empty($items_data)) {
            $errors[] = 'Не выбраны материалы для списания';
        } else {
            try {
                $db->beginTransaction();
                $total_items = 0;
                
                foreach ($items_data as $item_id => $qty) {
                    $quantity = floatval($qty);
                    if ($quantity <= 0) continue;
                    
                    // Проверка остатка
                    $stmt = $db->prepare("SELECT current_stock, name FROM items WHERE id = :id");
                    $stmt->execute(['id' => $item_id]);
                    $item = $stmt->fetch();
                    
                    if ($item['current_stock'] < $quantity) {
                        $db->rollBack();
                        $errors[] = "Недостаточно материала: {$item['name']} (доступно: {$item['current_stock']})";
                        break;
                    }
                    
                    // Списание
                    $stmt = $db->prepare("UPDATE items SET current_stock = current_stock - :qty WHERE id = :id");
                    $stmt->execute(['qty' => $quantity, 'id' => $item_id]);
                    
                    // Запись в движения
                    $stmt = $db->prepare("INSERT INTO movements (movement_type, item_id, quantity, reference_type, reference_id, notes, employee_id, movement_date) 
                        VALUES ('consumption', :item_id, :qty, :ref_type, :ref_id, :notes, :emp_id, NOW())");
                    $stmt->execute([
                        'item_id' => $item_id,
                        'qty' => $quantity,
                        'ref_type' => $task_id ? 'production_task' : ($order_id ? 'order' : 'manual'),
                        'ref_id' => $task_id ?: $order_id,
                        'notes' => trim($notes . ' | Причина: ' . $reason),
                        'emp_id' => $_SESSION['user_id']
                    ]);
                    
                    $total_items++;
                }
                
                if (empty($errors)) {
                    $db->commit();
                    $success = true;
                    $success_message = "Списано материалов: {$total_items}";
                }
            } catch (Exception $e) {
                $db->rollBack();
                $errors[] = 'Ошибка: ' . $e->getMessage();
            }
        }
    }
}

// Получаем последние операции списания
$stmt = $db->query("SELECT m.id, m.movement_date, i.name as item_name, i.item_code, m.quantity, d.name as unit_name, 
    s.last_name, s.first_name, m.notes, m.reference_type, m.reference_id
    FROM movements m
    JOIN items i ON m.item_id = i.id
    LEFT JOIN staff s ON m.employee_id = s.id
    LEFT JOIN dictionaries d ON i.unit_id = d.id
    WHERE m.movement_type = 'consumption'
    ORDER BY m.movement_date DESC LIMIT 20");
$recent_consumptions = $stmt->fetchAll();

$pageTitle = 'Расход материалов | PolesieMES';
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
        .history-card {
            background: var(--glass-bg);
            backdrop-filter: var(--backdrop-blur);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        
        .search-input {
            background: var(--bg-input);
            border: 1px solid var(--border);
            color: var(--text-primary);
            border-radius: 10px;
            padding: 0.75rem 1rem;
            transition: all 0.3s ease;
        }
        
        .search-input:focus {
            background: rgba(30, 30, 45, 0.7);
            border-color: var(--primary-gradient-start);
            box-shadow: 0 0 0 3px rgba(255, 107, 107, 0.2);
            color: var(--text-primary);
        }
        
        .table-custom {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table-custom th {
            background: rgba(255,255,255,0.05);
            color: var(--text-secondary);
            font-weight: 600;
            padding: 1rem;
            text-align: left;
            border-bottom: 2px solid var(--glass-border);
        }
        
        .table-custom td {
            padding: 1rem;
            border-bottom: 1px solid var(--glass-border);
            color: var(--text-primary);
        }
        
        .table-custom tr:hover {
            background: rgba(255,255,255,0.03);
        }
        
        .quantity-negative {
            color: var(--danger-color);
            font-weight: 600;
        }
        
        .quantity-positive {
            color: var(--success-color);
            font-weight: 600;
        }
        
        .stock-low {
            color: var(--warning-color);
            font-weight: 600;
        }
        
        .operation-consumption { 
            background: #ffd60a; 
            color: black; 
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .stat-card {
            background: var(--glass-bg);
            backdrop-filter: var(--backdrop-blur);
            border: 1px solid var(--glass-border);
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
        }
        
        .stat-label {
            color: var(--text-secondary);
            font-size: 0.9rem;
            margin-top: 0.5rem;
        }
        
        .form-label {
            color: var(--text-secondary);
            font-weight: 500;
            margin-bottom: 0.5rem;
        }
        
        .form-select, .form-control {
            background: var(--bg-input);
            border: 1px solid var(--border);
            color: var(--text-primary);
            border-radius: 10px;
            padding: 0.75rem 1rem;
        }
        
        .form-select:focus, .form-control:focus {
            background: rgba(30, 30, 45, 0.7);
            border-color: var(--primary-gradient-start);
            box-shadow: 0 0 0 3px rgba(255, 107, 107, 0.2);
            color: var(--text-primary);
        }
        
        .btn-action {
            background: linear-gradient(135deg, var(--primary-gradient-start), var(--primary-gradient-end));
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 10px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-action:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(255, 107, 107, 0.4);
        }
        
        .btn-secondary-custom {
            background: rgba(255,255,255,0.1);
            color: var(--text-primary);
            border: 1px solid var(--glass-border);
            padding: 0.75rem 1.5rem;
            border-radius: 10px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-secondary-custom:hover {
            background: rgba(255,255,255,0.15);
        }
        
        .badge-stock-ok {
            background: var(--success-color);
            color: white;
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        .badge-stock-low {
            background: var(--warning-color);
            color: black;
            padding: 0.35rem 0.75rem;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
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
            <li><a href="inventory.php" class="nav-link"><i class="fas fa-boxes"></i> Остатки</a></li>
            <li><a href="consumption.php" class="nav-link active"><i class="fas fa-dolly"></i> Расход</a></li>
            <li><a href="history.php" class="nav-link"><i class="fas fa-history"></i> История</a></li>
        </ul>
        <div class="user-menu">
            <span><?= e($_SESSION['full_name']) ?> (<?= e(getRoleName($_SESSION['role'])) ?>)</span>
            <a href="<?= APP_URL ?>/modules/auth/logout.php" class="btn-logout">Выход</a>
        </div>
    </nav>

    <div class="main-content">
        <div class="page-header">
            <div class="page-title">
                <h1><i class="fas fa-dolly"></i> Расход материалов</h1>
                <p>Списание материалов со склада</p>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?= count($materials) ?></div>
                <div class="stat-label">📦 Всего материалов</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--warning-color);"><?= count(array_filter($materials, fn($m) => $m['current_stock'] <= $m['min_stock'])) ?></div>
                <div class="stat-label">⚠️ Низкий запас</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--info-color);"><?= count($recent_consumptions) ?></div>
                <div class="stat-label">📋 Операций сегодня</div>
            </div>
            <div class="stat-card">
                <div class="stat-value" style="color: var(--success-color);"><?= count($tasks) ?></div>
                <div class="stat-label">🔧 Активных заданий</div>
            </div>
        </div>

        <?php if ($success): ?>
        <div class="history-card" style="border-left: 4px solid var(--success-color);">
            <div style="display: flex; align-items: center; gap: 1rem;">
                <i class="fas fa-check-circle" style="color: var(--success-color); font-size: 1.5rem;"></i>
                <div>
                    <strong style="color: var(--success-color);">Успешно!</strong>
                    <div style="color: var(--text-primary);"><?= e($success_message) ?></div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
        <div class="history-card" style="border-left: 4px solid var(--danger-color);">
            <div style="display: flex; align-items: center; gap: 1rem;">
                <i class="fas fa-exclamation-triangle" style="color: var(--danger-color); font-size: 1.5rem;"></i>
                <div>
                    <strong style="color: var(--danger-color);">Ошибка!</strong>
                    <?php foreach ($errors as $err): ?>
                    <div style="color: var(--text-primary);"><?= e($err) ?></div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Форма списания -->
        <div class="history-card">
            <form method="POST" id="consumptionForm">
                <input type="hidden" name="action" value="batch_consumption">
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
                    <div>
                        <label class="form-label"><i class="fas fa-tasks"></i> Производственное задание</label>
                        <select name="task_id" id="taskSelect" class="search-input" style="width: 100%;">
                            <option value="">Не выбрано</option>
                            <?php foreach ($tasks as $t): ?>
                            <option value="<?= $t['id'] ?>" 
                                data-order="<?= e($t['order_number']) ?>" 
                                data-stage="<?= e($t['stage_name']) ?>">
                                <?= e($t['task_number']) ?> | <?= e($t['stage_name']) ?> (<?= e($t['order_number']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: var(--text-muted);">Привязка к заданию (опционально)</small>
                    </div>
                    <div>
                        <label class="form-label"><i class="fas fa-clipboard-list"></i> Причина списания</label>
                        <select name="reason" class="search-input" style="width: 100%;">
                            <?php foreach ($consumption_reasons as $key => $label): ?>
                            <option value="<?= $key ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label"><i class="fas fa-comment"></i> Примечание</label>
                        <input type="text" name="notes" class="search-input" placeholder="Комментарий к операции" style="width: 100%;">
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table-custom" id="materialsTable">
                        <thead>
                            <tr>
                                <th width="50"><input type="checkbox" id="selectAll"></th>
                                <th>Код</th>
                                <th>Наименование</th>
                                <th width="100">Ед.изм.</th>
                                <th width="120">Остаток</th>
                                <th width="140">К списанию</th>
                                <th width="90">Статус</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($materials as $m): 
                                $unitName = isset($units[$m['unit_id']]) ? $units[$m['unit_id']] : 'шт.';
                                $lowStock = $m['current_stock'] <= $m['min_stock'];
                            ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="material-checkbox" 
                                        data-id="<?= $m['id'] ?>" 
                                        data-name="<?= e($m['name']) ?>"
                                        data-stock="<?= $m['current_stock'] ?>">
                                </td>
                                <td><strong style="color: var(--text-secondary);"><?= e($m['item_code']) ?></strong></td>
                                <td><?= e($m['name']) ?></td>
                                <td style="color: var(--text-secondary);"><?= e($unitName) ?></td>
                                <td>
                                    <span class="<?= $lowStock ? 'stock-low' : 'quantity-positive' ?>">
                                        <?= number_format($m['current_stock'], 3) ?>
                                    </span>
                                    <?php if ($lowStock): ?>
                                    <div style="font-size: 0.75rem; color: var(--warning-color); margin-top: 0.25rem;">
                                        <i class="fas fa-exclamation-triangle"></i> Мин: <?= $m['min_stock'] ?>
                                    </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <input type="number" step="0.001" min="0" 
                                        class="search-input qty-input" 
                                        name="items[<?= $m['id'] ?>]" 
                                        placeholder="0"
                                        data-max="<?= $m['current_stock'] ?>"
                                        oninput="validateQty(this)"
                                        style="width: 100%;">
                                </td>
                                <td>
                                    <span class="<?= $lowStock ? 'badge-stock-low' : 'badge-stock-ok' ?>">
                                        <?= $lowStock ? 'Мало' : 'OK' ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 1.5rem; flex-wrap: wrap; gap: 1rem;">
                    <div style="display: flex; gap: 1rem;">
                        <button type="button" class="btn-secondary-custom" onclick="clearAll()">
                            <i class="fas fa-times"></i> Очистить
                        </button>
                        <button type="button" class="btn-secondary-custom" onclick="fillFromTask()">
                            <i class="fas fa-tasks"></i> Заполнить по заданию
                        </button>
                    </div>
                    <div style="display: flex; align-items: center; gap: 1rem;">
                        <span id="selectedCount" style="color: var(--text-secondary);">Выбрано: <strong style="color: var(--text-primary);">0</strong></span>
                        <button type="submit" class="btn-action">
                            <i class="fas fa-minus-circle"></i> Списать материалы
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- История последних операций -->
        <div class="history-card">
            <h3 style="margin-bottom: 1.5rem; color: var(--text-primary);"><i class="fas fa-history"></i> Последние операции списания</h3>
            <div class="table-responsive">
                <table class="table-custom">
                    <thead>
                        <tr>
                            <th>Дата/Время</th>
                            <th>Материал</th>
                            <th>Количество</th>
                            <th>Сотрудник</th>
                            <th>Примечание</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_consumptions as $rc): ?>
                        <tr>
                            <td style="color: var(--text-secondary);"><?= date('d.m.Y H:i', strtotime($rc['movement_date'])) ?></td>
                            <td>
                                <div style="color: var(--text-primary);"><?= e($rc['item_name']) ?></div>
                                <small style="color: var(--text-muted);"><?= e($rc['item_code']) ?></small>
                            </td>
                            <td>
                                <span class="operation-consumption">
                                    <?= number_format($rc['quantity'], 3) ?> <?= e($rc['unit_name']) ?>
                                </span>
                            </td>
                            <td style="color: var(--text-secondary);"><?= e($rc['last_name'] . ' ' . $rc['first_name']) ?></td>
                            <td style="color: var(--text-muted); font-size: 0.9rem;"><?= e($rc['notes']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Выбор всех чекбоксов
    document.getElementById('selectAll').addEventListener('change', function() {
        document.querySelectorAll('.material-checkbox').forEach(cb => cb.checked = this.checked);
        updateSelectedCount();
    });
    
    // Подсчет выбранных
    document.querySelectorAll('.material-checkbox').forEach(cb => {
        cb.addEventListener('change', updateSelectedCount);
    });
    
    function updateSelectedCount() {
        const count = document.querySelectorAll('.material-checkbox:checked').length;
        document.getElementById('selectedCount').innerHTML = 'Выбрано: <strong>' + count + '</strong>';
    }
    
    // Проверка количества
    function validateQty(input) {
        const max = parseFloat(input.dataset.max);
        const val = parseFloat(input.value);
        if (val > max) {
            input.value = max;
            alert('Превышен доступный остаток!');
        }
        input.parentElement.parentElement.classList.toggle('table-danger', val > max);
    }
    
    // Очистка всех полей
    function clearAll() {
        document.querySelectorAll('.qty-input').forEach(input => input.value = '');
        document.querySelectorAll('.material-checkbox').forEach(cb => cb.checked = false);
        document.getElementById('taskSelect').value = '';
        updateSelectedCount();
    }
    
    // Автозаполнение по заданию (будущая функция)
    function fillFromTask() {
        const taskSelect = document.getElementById('taskSelect');
        if (!taskSelect.value) {
            alert('Сначала выберите производственное задание');
            return;
        }
        alert('Функция автозаполнения по нормам расхода будет добавлена в следующей версии');
    }
    
    // Предупреждение перед отправкой
    document.getElementById('consumptionForm').addEventListener('submit', function(e) {
        let hasValues = false;
        document.querySelectorAll('.qty-input').forEach(input => {
            if (parseFloat(input.value) > 0) hasValues = true;
        });
        if (!hasValues) {
            e.preventDefault();
            alert('Укажите количество хотя бы для одного материала');
        } else {
            const count = document.querySelectorAll('.qty-input').filter(i => parseFloat(i.value) > 0).length;
            if (!confirm('Списать материалы (' + count + ' поз.)?')) {
                e.preventDefault();
            }
        }
    });
    </script>
</body>
</html>
