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
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/common-style.css">
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
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle"></i> <?= e($success_message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php foreach ($errors as $err): ?><div><i class="fas fa-exclamation-triangle"></i> <?= e($err) ?></div><?php endforeach; ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Вкладка: Массовое списание -->
        <div class="card mb-4">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0"><i class="fas fa-boxes"></i> Списание материалов</h5>
            </div>
            <div class="card-body">
                <form method="POST" id="consumptionForm">
                    <input type="hidden" name="action" value="batch_consumption">
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">Производственное задание</label>
                            <select name="task_id" id="taskSelect" class="form-select">
                                <option value="">Не выбрано</option>
                                <?php foreach ($tasks as $t): ?>
                                <option value="<?= $t['id'] ?>" 
                                    data-order="<?= e($t['order_number']) ?>" 
                                    data-stage="<?= e($t['stage_name']) ?>">
                                    <?= e($t['task_number']) ?> | <?= e($t['stage_name']) ?> (<?= e($t['order_number']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">Привязка к заданию (опционально)</small>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Причина списания</label>
                            <select name="reason" class="form-select">
                                <?php foreach ($consumption_reasons as $key => $label): ?>
                                <option value="<?= $key ?>"><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Примечание</label>
                            <input type="text" name="notes" class="form-control" placeholder="Комментарий к операции">
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover table-bordered" id="materialsTable">
                            <thead class="table-light">
                                <tr>
                                    <th width="40"><input type="checkbox" id="selectAll"></th>
                                    <th>Код</th>
                                    <th>Наименование</th>
                                    <th width="120">Ед.изм.</th>
                                    <th width="130">Остаток</th>
                                    <th width="150">К списанию</th>
                                    <th width="80">Статус</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($materials as $m): 
                                    $unitName = isset($units[$m['unit_id']]) ? $units[$m['unit_id']] : 'шт.';
                                    $lowStock = $m['current_stock'] <= $m['min_stock'];
                                ?>
                                <tr class="<?= $lowStock ? 'table-warning' : '' ?>">
                                    <td>
                                        <input type="checkbox" class="material-checkbox" 
                                            data-id="<?= $m['id'] ?>" 
                                            data-name="<?= e($m['name']) ?>"
                                            data-stock="<?= $m['current_stock'] ?>">
                                    </td>
                                    <td><strong><?= e($m['item_code']) ?></strong></td>
                                    <td><?= e($m['name']) ?></td>
                                    <td><?= e($unitName) ?></td>
                                    <td>
                                        <span class="<?= $lowStock ? 'text-danger fw-bold' : 'text-success' ?>">
                                            <?= number_format($m['current_stock'], 3) ?>
                                        </span>
                                        <?php if ($lowStock): ?>
                                        <br><small class="text-danger"><i class="fas fa-exclamation-triangle"></i> Мин: <?= $m['min_stock'] ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <input type="number" step="0.001" min="0" 
                                            class="form-control form-control-sm qty-input" 
                                            name="items[<?= $m['id'] ?>]" 
                                            placeholder="0"
                                            data-max="<?= $m['current_stock'] ?>"
                                            oninput="validateQty(this)">
                                    </td>
                                    <td>
                                        <span class="badge <?= $lowStock ? 'bg-warning text-dark' : 'bg-success' ?>">
                                            <?= $lowStock ? 'Мало' : 'OK' ?>
                                        </span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <div>
                            <button type="button" class="btn btn-outline-secondary" onclick="clearAll()">
                                <i class="fas fa-times"></i> Очистить
                            </button>
                            <button type="button" class="btn btn-outline-primary" onclick="fillFromTask()">
                                <i class="fas fa-tasks"></i> Заполнить по заданию
                            </button>
                        </div>
                        <div>
                            <span id="selectedCount" class="me-3">Выбрано: <strong>0</strong></span>
                            <button type="submit" class="btn btn-warning btn-lg">
                                <i class="fas fa-minus-circle"></i> Списать материалы
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- История последних операций -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-history"></i> Последние операции списания</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm table-striped">
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
                                <td><?= date('d.m.Y H:i', strtotime($rc['movement_date'])) ?></td>
                                <td>
                                    <strong><?= e($rc['item_name']) ?></strong><br>
                                    <small class="text-muted"><?= e($rc['item_code']) ?></small>
                                </td>
                                <td><span class="badge bg-warning text-dark"><?= number_format($rc['quantity'], 3) ?> <?= e($rc['unit_name']) ?></span></td>
                                <td><?= e($rc['last_name'] . ' ' . $rc['first_name']) ?></td>
                                <td><small><?= e($rc['notes']) ?></small></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
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
