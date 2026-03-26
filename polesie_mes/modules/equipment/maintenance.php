<?php
/**
 * Модуль оборудования - Обслуживание оборудования
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireAuth();

$db = getDB();
$user = getCurrentUser();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$message = '';
$messageType = '';

if (!$id) {
    header('Location: index.php');
    exit;
}

// Получение оборудования
$stmt = $db->prepare("SELECT * FROM items WHERE id = ? AND item_type = 'equipment'");
$stmt->execute([$id]);
$equipment = $stmt->fetch();

if (!$equipment) {
    header('Location: index.php');
    exit;
}

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $maintenanceType = $_POST['maintenance_type'] ?? '';
    $description = $_POST['description'] ?? '';
    $status = $_POST['status'] ?? 'completed';
    $technicianId = $user['staff_id'] ?? null;
    
    if ($maintenanceType && $description) {
        $stmt = $db->prepare("
            INSERT INTO journal (item_id, journal_type, maintenance_type, description, technician_id, maintenance_status, created_at)
            VALUES (?, 'maintenance', ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([$id, $maintenanceType, $description, $technicianId, $status]);
        
        // Обновление статуса оборудования если нужно
        if ($status === 'in_progress') {
            $stmt = $db->prepare("UPDATE items SET status = 'maintenance' WHERE id = ?");
            $stmt->execute([$id]);
        } elseif ($status === 'completed') {
            $stmt = $db->prepare("UPDATE items SET status = 'operational' WHERE id = ?");
            $stmt->execute([$id]);
        }
        
        $message = 'Запись об обслуживании добавлена';
        $messageType = 'success';
    } else {
        $message = 'Заполните обязательные поля';
        $messageType = 'error';
    }
}

$pageTitle = 'Обслуживание оборудования | ' . APP_NAME;
?>

<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/common-style.css">
</head>
<body>
    <div class="particles-container"><div class="particle"></div><div class="particle"></div></div>
    <div class="glow-overlay"></div>
    <div class="grid-overlay"></div>
    
    <nav class="navbar">
        <a href="<?= APP_URL ?>" class="nav-brand">
            <div class="brand-logo"><svg viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg></div>
            <span class="brand-name">PolesieMES</span>
        </a>
        <ul class="nav-menu">
            <li><a href="<?= APP_URL ?>/modules/dashboard/index.php" class="nav-link">Главная</a></li>
            <?php if (hasRole(['admin', 'manager', 'technologist'])): ?>
            <li><a href="index.php" class="nav-link active">Оборудование</a></li>
            <?php endif; ?>
        </ul>
        <div class="user-menu">
            <span style="color: var(--text-secondary);"><?= e($_SESSION['full_name']) ?></span>
            <a href="<?= APP_URL ?>/modules/auth/logout.php" class="btn-logout">Выход</a>
        </div>
    </nav>

    <div class="main-content">
        <div class="page-header">
            <div class="page-title">
                <h1><i class="fas fa-wrench"></i> Обслуживание: <?= e($equipment['name']) ?></h1>
            </div>
            <a href="view.php?id=<?= $id ?>" class="btn-secondary-custom"><i class="fas fa-arrow-left"></i> Назад</a>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?>"><?= e($message) ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header"><div class="card-title">Добавить запись об обслуживании</div></div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Тип обслуживания *</label>
                        <select name="maintenance_type" class="form-select" required>
                            <option value="">Выберите тип</option>
                            <option value="planned">Плановое ТО</option>
                            <option value="repair">Ремонт</option>
                            <option value="inspection">Проверка</option>
                            <option value="other">Другое</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Описание работ *</label>
                        <textarea name="description" class="form-control" rows="4" required></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Статус</label>
                        <select name="status" class="form-select">
                            <option value="completed">Завершено</option>
                            <option value="in_progress">В процессе</option>
                        </select>
                    </div>
                    <button type="submit" class="btn-primary-custom">Сохранить</button>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
