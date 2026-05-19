<?php
/**
 * Модуль оборудования - Просмотр оборудования
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireAuth();

$db = getDB();
$user = getCurrentUser();

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    header('Location: index.php');
    exit;
}

$stmt = $db->prepare("
    SELECT i.*, 
           d.name as category_name,
           d2.name as location_name
    FROM items i
    LEFT JOIN dictionaries d ON i.category_id = d.id AND d.dict_type = 'category'
    LEFT JOIN dictionaries d2 ON i.location_id = d2.id AND d2.dict_type = 'location'
    WHERE i.id = ? AND i.item_type = 'equipment'
");
$stmt->execute([$id]);
$equipment = $stmt->fetch();

if (!$equipment) {
    header('Location: index.php');
    exit;
}

// История обслуживания
$stmt = $db->prepare("
    SELECT j.*, s.first_name, s.last_name
    FROM journal j
    LEFT JOIN staff s ON j.technician_id = s.id
    WHERE j.item_id = ? AND j.journal_type = 'maintenance'
    ORDER BY j.created_at DESC
    LIMIT 20
");
$stmt->execute([$id]);
$maintenanceHistory = $stmt->fetchAll();

$pageTitle = 'Просмотр оборудования | ' . APP_NAME;
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
    <div class="particles-container">
        <div class="particle"></div><div class="particle"></div><div class="particle"></div>
    </div>
    <div class="glow-overlay"></div>
    <div class="grid-overlay"></div>
    
    <nav class="navbar">
        <a href="<?= APP_URL ?>" class="nav-brand">
            <div class="brand-logo"><svg viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg></div>
            <span class="brand-name">PolesieMES</span>
        </a>
        <ul class="nav-menu">
            <li><a href="<?= APP_URL ?>/modules/dashboard/index.php" class="nav-link"><i class="fas fa-chart-line"></i> Главная</a></li>
            <?php if (hasRole(['admin', 'manager'])): ?>
            <li><a href="<?= APP_URL ?>/modules/orders/index.php" class="nav-link"><i class="fas fa-shopping-cart"></i> Заказы</a></li>
            <?php endif; ?>
            <?php if (hasRole(['admin', 'manager', 'technologist'])): ?>
            <li><a href="<?= APP_URL ?>/modules/equipment/index.php" class="nav-link active"><i class="fas fa-tools"></i> Оборудование</a></li>
            <?php endif; ?>
        </ul>
        <div class="user-menu">
            <span class="user-name" style="color: var(--text-secondary);"><?= e($_SESSION['full_name'] ?? 'Пользователь') ?></span>
            <a href="<?= APP_URL ?>/modules/auth/logout.php" class="btn-logout">Выход</a>
        </div>
    </nav>

    <div class="main-content">
        <div class="page-header">
            <div class="page-title">
                <h1><i class="fas fa-eye"></i> <?= e($equipment['name']) ?></h1>
                <p>Информация об оборудовании</p>
            </div>
            <div>
                <a href="index.php" class="btn-secondary-custom"><i class="fas fa-arrow-left"></i> Назад</a>
                <?php if (hasRole(['admin', 'manager', 'engineer'])): ?>
                <a href="edit.php?id=<?= $id ?>" class="btn-primary-custom"><i class="fas fa-edit"></i> Редактировать</a>
                <a href="maintenance.php?id=<?= $id ?>" class="btn-primary-custom"><i class="fas fa-wrench"></i> Обслуживание</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-info-circle"></i> Основная информация</div>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Название:</strong> <?= e($equipment['name']) ?></p>
                        <p><strong>Категория:</strong> <?= e($equipment['category_name'] ?? '-') ?></p>
                        <p><strong>Расположение:</strong> <?= e($equipment['location_name'] ?? '-') ?></p>
                        <p><strong>Код:</strong> <?= e($equipment['item_code'] ?? '-') ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Статус:</strong> 
                            <span class="badge-status badge-<?= $equipment['status'] ?? 'operational' ?>">
                                <?= $equipment['status'] == 'operational' ? 'В работе' :
                                    ($equipment['status'] == 'maintenance' ? 'Обслуживание' :
                                    ($equipment['status'] == 'broken' ? 'Неисправно' : 'Отключено')) ?>
                            </span>
                        </p>
                        <p><strong>Дата ввода в эксплуатацию:</strong> <?= !empty($equipment['commissioning_date']) ? date('d.m.Y', strtotime($equipment['commissioning_date'])) : '-' ?></p>
                        <p><strong>Год выпуска:</strong> <?= !empty($equipment['manufacture_year']) ? e($equipment['manufacture_year']) : '-' ?></p>
                    </div>
                </div>
                <?php if ($equipment['description']): ?>
                <hr>
                <p><strong>Описание:</strong></p>
                <p><?= nl2br(e($equipment['description'])) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <div class="card-title"><i class="fas fa-history"></i> История обслуживания</div>
            </div>
            <div class="card-body">
                <?php if (empty($maintenanceHistory)): ?>
                <p style="color: var(--text-secondary);">История обслуживания отсутствует</p>
                <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Дата</th>
                            <th>Тип</th>
                            <th>Описание</th>
                            <th>Исполнитель</th>
                            <th>Статус</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($maintenanceHistory as $record): ?>
                        <tr>
                            <td><?= date('d.m.Y H:i', strtotime($record['created_at'])) ?></td>
                            <td><?= e($record['maintenance_type'] ?? '-') ?></td>
                            <td><?= e($record['description'] ?? '-') ?></td>
                            <td><?= e($record['first_name'] . ' ' . $record['last_name'] ?? '-') ?></td>
                            <td><span class="badge-status <?= $record['maintenance_status'] ?? 'completed' ?>"><?= e($record['maintenance_status'] ?? 'Завершено') ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
