<?php
/**
 * Модуль сотрудников - Добавление сотрудника
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

requireAuth();

$db = getDB();
$user = getCurrentUser();

$message = '';
$messageType = '';

// Получение должностей
$stmt = $db->query("SELECT id, name FROM dictionaries WHERE dict_type = 'position' ORDER BY name");
$positions = $stmt->fetchAll();

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $firstName = $_POST['first_name'] ?? '';
    $lastName = $_POST['last_name'] ?? '';
    $middleName = $_POST['middle_name'] ?? '';
    $positionId = $_POST['position_id'] ?: null;
    $department = $_POST['department'] ?? '';
    $email = $_POST['email'] ?? '';
    $phone = $_POST['phone'] ?? '';
    $employeeCode = $_POST['employee_code'] ?? '';
    $hireDate = $_POST['hire_date'] ?? date('Y-m-d');
    
    if ($firstName && $lastName) {
        $stmt = $db->prepare("
            INSERT INTO staff (first_name, last_name, middle_name, position_id, department, email, phone, employee_code, hire_date, status, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())
        ");
        $stmt->execute([$firstName, $lastName, $middleName, $positionId, $department, $email, $phone, $employeeCode, $hireDate]);
        
        header('Location: index.php?success=1');
        exit;
    } else {
        $message = 'Заполните обязательные поля';
        $messageType = 'error';
    }
}

$pageTitle = 'Добавить сотрудника | ' . APP_NAME;
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
                <h1><i class="fas fa-user-plus"></i> Добавить сотрудника</h1>
            </div>
            <a href="index.php" class="btn-secondary-custom"><i class="fas fa-arrow-left"></i> Назад</a>
        </div>

        <?php if ($message): ?>
        <div class="alert alert-<?= $messageType === 'success' ? 'success' : 'danger' ?>"><?= e($message) ?></div>
        <?php endif; ?>

        <div class="card">
            <div class="card-header"><div class="card-title">Основная информация</div></div>
            <div class="card-body">
                <form method="POST">
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Фамилия *</label>
                            <input type="text" name="last_name" class="form-control" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Имя *</label>
                            <input type="text" name="first_name" class="form-control" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Отчество</label>
                            <input type="text" name="middle_name" class="form-control">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Табельный номер</label>
                            <input type="text" name="employee_code" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Дата приема</label>
                            <input type="date" name="hire_date" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Должность</label>
                            <select name="position_id" class="form-select">
                                <option value="">Не выбрана</option>
                                <?php foreach ($positions as $pos): ?>
                                <option value="<?= $pos['id'] ?>"><?= e($pos['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Отдел</label>
                            <input type="text" name="department" class="form-control">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Телефон</label>
                            <input type="text" name="phone" class="form-control">
                        </div>
                    </div>
                    <button type="submit" class="btn-primary-custom">Сохранить</button>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
