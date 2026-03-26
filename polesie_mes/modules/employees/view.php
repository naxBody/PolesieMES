<?php
/**
 * Модуль сотрудников - Просмотр сотрудника
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
    SELECT s.*, d.name as position_name
    FROM staff s
    LEFT JOIN dictionaries d ON s.position_id = d.id AND d.dict_type = 'position'
    WHERE s.id = ?
");
$stmt->execute([$id]);
$employee = $stmt->fetch();

if (!$employee) {
    header('Location: index.php');
    exit;
}

$pageTitle = 'Просмотр сотрудника | ' . APP_NAME;
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
            <?php if (hasRole('admin')): ?>
            <li><a href="index.php" class="nav-link active">Сотрудники</a></li>
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
                <h1><i class="fas fa-user"></i> <?= e($employee['last_name']) ?> <?= e($employee['first_name']) ?> <?= e($employee['middle_name'] ?? '') ?></h1>
                <p>Информация о сотруднике</p>
            </div>
            <div>
                <a href="index.php" class="btn-secondary-custom"><i class="fas fa-arrow-left"></i> Назад</a>
                <?php if (hasRole(['admin', 'manager'])): ?>
                <a href="edit.php?id=<?= $id ?>" class="btn-primary-custom"><i class="fas fa-edit"></i> Редактировать</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><div class="card-title">Основная информация</div></div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>ФИО:</strong> <?= e($employee['last_name']) ?> <?= e($employee['first_name']) ?> <?= e($employee['middle_name'] ?? '') ?></p>
                        <p><strong>Табельный номер:</strong> <?= e($employee['employee_code'] ?? '-') ?></p>
                        <p><strong>Должность:</strong> <?= e($employee['position_name'] ?? '-') ?></p>
                        <p><strong>Отдел:</strong> <?= e($employee['department'] ?? '-') ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Email:</strong> <?= $employee['email'] ? e($employee['email']) : '-' ?></p>
                        <p><strong>Телефон:</strong> <?= $employee['phone'] ? e($employee['phone']) : '-' ?></p>
                        <p><strong>Дата приема:</strong> <?= $employee['hire_date'] ? date('d.m.Y', strtotime($employee['hire_date'])) : '-' ?></p>
                        <p><strong>Статус:</strong> 
                            <span class="badge-status <?= $employee['status'] ?>">
                                <?= $employee['status'] == 'active' ? 'Активен' :
                                    ($employee['status'] == 'vacation' ? 'Отпуск' :
                                    ($employee['status'] == 'sick' ? 'Больничный' : 'Уволен')) ?>
                            </span>
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
