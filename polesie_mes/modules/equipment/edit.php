<?php
/**
 * Модуль оборудования - Редактирование оборудования
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

// Получение словарей
$stmt = $db->query("SELECT id, name FROM dictionaries WHERE dict_type = 'category' ORDER BY name");
$categories = $stmt->fetchAll();

$stmt = $db->query("SELECT id, name FROM dictionaries WHERE dict_type = 'location' ORDER BY name");
$locations = $stmt->fetchAll();

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = $_POST['name'] ?? '';
    $categoryId = $_POST['category_id'] ?: null;
    $locationId = $_POST['location_id'] ?: null;
    $inventoryNumber = $_POST['inventory_number'] ?? '';
    $status = $_POST['status'] ?? 'operational';
    $description = $_POST['description'] ?? '';
    
    if ($name) {
        $stmt = $db->prepare("
            UPDATE items SET 
                name = ?, category_id = ?, location_id = ?, inventory_number = ?, 
                status = ?, description = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$name, $categoryId, $locationId, $inventoryNumber, $status, $description, $id]);
        
        $message = 'Оборудование обновлено';
        $messageType = 'success';
        
        // Обновление данных
        $stmt = $db->prepare("SELECT * FROM items WHERE id = ?");
        $stmt->execute([$id]);
        $equipment = $stmt->fetch();
    } else {
        $message = 'Заполните обязательные поля';
        $messageType = 'error';
    }
}

$pageTitle = 'Редактирование оборудования | ' . APP_NAME;
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
                <h1><i class="fas fa-edit"></i> Редактировать: <?= e($equipment['name']) ?></h1>
            </div>
            <a href="view.php?id=<?= $id ?>" class="btn-secondary-custom"><i class="fas fa-arrow-left"></i> Назад</a>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?>"><?= e($message) ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header"><div class="card-title">Основная информация</div></div>
            <div class="card-body">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Название *</label>
                        <input type="text" name="name" class="form-control" value="<?= e($equipment['name']) ?>" required>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Категория</label>
                            <select name="category_id" class="form-select">
                                <option value="">Не выбрана</option>
                                <?php foreach ($categories as $cat): ?>
                                <option value="<?= $cat['id'] ?>" <?= $equipment['category_id'] == $cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Расположение</label>
                            <select name="location_id" class="form-select">
                                <option value="">Не выбрано</option>
                                <?php foreach ($locations as $loc): ?>
                                <option value="<?= $loc['id'] ?>" <?= $equipment['location_id'] == $loc['id'] ? 'selected' : '' ?>><?= e($loc['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Инвентарный номер</label>
                            <input type="text" name="inventory_number" class="form-control" value="<?= e($equipment['inventory_number']) ?>">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Статус</label>
                            <select name="status" class="form-select">
                                <option value="operational" <?= $equipment['status'] == 'operational' ? 'selected' : '' ?>>В работе</option>
                                <option value="maintenance" <?= $equipment['status'] == 'maintenance' ? 'selected' : '' ?>>Обслуживание</option>
                                <option value="broken" <?= $equipment['status'] == 'broken' ? 'selected' : '' ?>>Неисправно</option>
                                <option value="offline" <?= $equipment['status'] == 'offline' ? 'selected' : '' ?>>Отключено</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Описание</label>
                        <textarea name="description" class="form-control" rows="4"><?= e($equipment['description']) ?></textarea>
                    </div>
                    <button type="submit" class="btn-primary-custom">Сохранить изменения</button>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
