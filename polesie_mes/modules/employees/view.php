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

// Получение полной информации о сотруднике
$stmt = $db->prepare("
    SELECT s.*, d.name as position_name,
           DATE_FORMAT(s.created_at, '%d.%m.%Y %H:%i') as created_at_fmt,
           DATE_FORMAT(s.updated_at, '%d.%m.%Y %H:%i') as updated_at_fmt,
           DATE_FORMAT(s.last_login, '%d.%m.%Y %H:%i') as last_login_fmt
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

// Получение количества задач сотрудника
$stmt = $db->prepare("SELECT COUNT(*) as task_count FROM production_tasks WHERE assigned_to = ?");
$stmt->execute([$id]);
$taskCount = $stmt->fetch()['task_count'] ?? 0;

// Получение количества записей в журнале перемещений
$stmt = $db->prepare("SELECT COUNT(*) as movement_count FROM movements WHERE employee_id = ?");
$stmt->execute([$id]);
$movementCount = $stmt->fetch()['movement_count'] ?? 0;

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
    <link href="<?= APP_URL ?>/assets/css/common-style.css" rel="stylesheet">
    <style>
        .employee-profile-header {
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.15) 0%, rgba(255, 142, 83, 0.1) 100%);
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            border: 1px solid var(--border);
            backdrop-filter: blur(10px);
        }
        
        .employee-avatar-large {
            width: 120px;
            height: 120px;
            background: var(--gradient-primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--glow-primary);
            font-size: 3rem;
            font-weight: 700;
            color: white;
            margin: 0 auto 1.5rem;
        }
        
        .info-item {
            display: flex;
            padding: 0.875rem 0;
            border-bottom: 1px solid var(--border);
        }
        
        .info-item:last-child {
            border-bottom: none;
        }
        
        .info-label {
            min-width: 180px;
            color: var(--text-secondary);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .info-label i {
            width: 20px;
            text-align: center;
            color: var(--primary-gradient-start);
        }
        
        .info-value {
            color: var(--text-primary);
            font-weight: 500;
            flex: 1;
        }
        
        .stat-box {
            background: var(--glass-bg);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.25rem;
            text-align: center;
            transition: all 0.3s ease;
        }
        
        .stat-box:hover {
            transform: translateY(-2px);
            border-color: var(--border-glow);
            box-shadow: 0 4px 20px rgba(255, 107, 107, 0.1);
        }
        
        .stat-box-icon {
            width: 50px;
            height: 50px;
            background: var(--gradient-primary);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.75rem;
            font-size: 1.25rem;
            color: white;
        }
        
        .stat-box-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 0.25rem;
        }
        
        .stat-box-label {
            font-size: 0.8rem;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .badge-role {
            padding: 0.4rem 0.85rem;
            border-radius: 8px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: capitalize;
        }
        
        .badge-role-admin { background: rgba(255, 69, 58, 0.2); color: #ff453a; }
        .badge-role-director { background: rgba(255, 214, 10, 0.2); color: #ffd60a; }
        .badge-role-manager { background: rgba(90, 200, 250, 0.2); color: #5ac8fa; }
        .badge-role-operator { background: rgba(48, 209, 88, 0.2); color: #30d158; }
        .badge-role-warehouse_keeper { background: rgba(255, 142, 83, 0.2); color: #FF8E53; }
        
        .status-badge-large {
            padding: 0.5rem 1.25rem;
            border-radius: 10px;
            font-size: 0.9rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .status-active { background: rgba(48, 209, 88, 0.2); color: #30d158; }
        .status-vacation { background: rgba(255, 214, 10, 0.2); color: #ffd60a; }
        .status-sick { background: rgba(255, 69, 58, 0.2); color: #ff453a; }
        .status-terminated { background: rgba(90, 200, 250, 0.2); color: #5ac8fa; }
        
        .timeline-item {
            position: relative;
            padding-left: 2rem;
            padding-bottom: 1.5rem;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 2px;
            background: var(--border);
        }
        
        .timeline-item::after {
            content: '';
            position: absolute;
            left: -4px;
            top: 5px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: var(--primary-gradient-start);
            box-shadow: 0 0 10px var(--primary-glow);
        }
        
        .timeline-item:last-child {
            padding-bottom: 0;
        }
        
        .timeline-item:last-child::before {
            display: none;
        }
        
        .timeline-date {
            font-size: 0.8rem;
            color: var(--text-secondary);
            margin-bottom: 0.25rem;
        }
        
        .timeline-content {
            color: var(--text-primary);
            font-weight: 500;
        }
    </style>
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
                <h1><i class="fas fa-user-circle"></i> Карточка сотрудника</h1>
                <p>Полная информация о сотруднике</p>
            </div>
            <div class="btn-group">
                <a href="index.php" class="btn-secondary-custom"><i class="fas fa-arrow-left"></i> Назад</a>
                <?php if (hasRole(['admin', 'manager'])): ?>
                <a href="edit.php?id=<?= $id ?>" class="btn-primary-custom"><i class="fas fa-edit"></i> Редактировать</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Профиль сотрудника -->
        <div class="employee-profile-header">
            <div class="row align-items-center">
                <div class="col-md-3 text-center">
                    <div class="employee-avatar-large">
                        <?= strtoupper(substr($employee['first_name'], 0, 1)) ?><?= strtoupper(substr($employee['last_name'], 0, 1)) ?>
                    </div>
                </div>
                <div class="col-md-9">
                    <h2 style="font-size: 1.75rem; font-weight: 700; margin-bottom: 0.5rem;">
                        <?= e($employee['last_name']) ?> <?= e($employee['first_name']) ?> <?= e($employee['middle_name'] ?? '') ?>
                    </h2>
                    <div style="margin-bottom: 1rem;">
                        <span class="badge-role badge-role-<?= $employee['role'] ?>">
                            <i class="fas fa-shield-alt"></i> <?= $employee['role'] == 'admin' ? 'Администратор' : 
                                ($employee['role'] == 'director' ? 'Директор' : 
                                ($employee['role'] == 'manager' ? 'Менеджер' : 
                                ($employee['role'] == 'operator' ? 'Оператор' : 'Кладовщик'))) ?>
                        </span>
                        <span class="status-badge-large status-<?= $employee['status'] ?>" style="margin-left: 0.75rem;">
                            <i class="fas fa-circle" style="font-size: 0.5rem;"></i>
                            <?= $employee['status'] == 'active' ? 'Активен' :
                                ($employee['status'] == 'vacation' ? 'В отпуске' :
                                ($employee['status'] == 'sick' ? 'На больничном' : 'Уволен')) ?>
                        </span>
                    </div>
                    <p style="color: var(--text-secondary); margin-bottom: 0;">
                        <i class="fas fa-briefcase"></i> <?= e($employee['position_name'] ?? 'Должность не указана') ?>
                        <?php if ($employee['department']): ?>
                            | <i class="fas fa-building"></i> <?= e($employee['department']) ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>
        </div>

        <!-- Статистика -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-box">
                    <div class="stat-box-icon"><i class="fas fa-tasks"></i></div>
                    <div class="stat-box-value"><?= $taskCount ?></div>
                    <div class="stat-box-label">Задач назначено</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-box">
                    <div class="stat-box-icon"><i class="fas fa-dolly"></i></div>
                    <div class="stat-box-value"><?= $movementCount ?></div>
                    <div class="stat-box-label">Перемещений материалов</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-box">
                    <div class="stat-box-icon"><i class="fas fa-calendar-check"></i></div>
                    <div class="stat-box-value"><?= $employee['hire_date'] ? date('Y', strtotime($employee['hire_date'])) : '-' ?></div>
                    <div class="stat-box-label">Год приема</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-box">
                    <div class="stat-box-icon"><i class="fas fa-id-card"></i></div>
                    <div class="stat-box-value"><?= e($employee['employee_code']) ?></div>
                    <div class="stat-box-label">Табельный номер</div>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Основная информация -->
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="fas fa-user"></i> Основная информация</div>
                    </div>
                    <div class="card-body">
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-user"></i> ФИО</div>
                            <div class="info-value"><?= e($employee['last_name']) ?> <?= e($employee['first_name']) ?> <?= e($employee['middle_name'] ?? '') ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-hashtag"></i> Табельный номер</div>
                            <div class="info-value"><?= e($employee['employee_code']) ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-briefcase"></i> Должность</div>
                            <div class="info-value"><?= e($employee['position_name'] ?? '-') ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-building"></i> Отдел</div>
                            <div class="info-value"><?= e($employee['department'] ?? '-') ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-shield-alt"></i> Роль</div>
                            <div class="info-value">
                                <span class="badge-role badge-role-<?= $employee['role'] ?>">
                                    <?= $employee['role'] == 'admin' ? 'Администратор' : 
                                        ($employee['role'] == 'director' ? 'Директор' : 
                                        ($employee['role'] == 'manager' ? 'Менеджер' : 
                                        ($employee['role'] == 'operator' ? 'Оператор' : 'Кладовщик'))) ?>
                                </span>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-calendar-plus"></i> Дата приема</div>
                            <div class="info-value"><?= $employee['hire_date'] ? date('d.m.Y', strtotime($employee['hire_date'])) : '-' ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-toggle-on"></i> Статус</div>
                            <div class="info-value">
                                <span class="status-badge-large status-<?= $employee['status'] ?>">
                                    <i class="fas fa-circle" style="font-size: 0.5rem;"></i>
                                    <?= $employee['status'] == 'active' ? 'Активен' :
                                        ($employee['status'] == 'vacation' ? 'В отпуске' :
                                        ($employee['status'] == 'sick' ? 'На больничном' : 'Уволен')) ?>
                                </span>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-check-circle"></i> Активен</div>
                            <div class="info-value">
                                <span class="badge-status <?= $employee['is_active'] ? 'badge-confirmed' : 'badge-cancelled' ?>">
                                    <?= $employee['is_active'] ? 'Да' : 'Нет' ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Контактная информация -->
            <div class="col-lg-6">
                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="fas fa-address-book"></i> Контактная информация</div>
                    </div>
                    <div class="card-body">
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-envelope"></i> Email</div>
                            <div class="info-value">
                                <?php if ($employee['email']): ?>
                                    <a href="mailto:<?= e($employee['email']) ?>" style="color: var(--info-color); text-decoration: none;">
                                        <?= e($employee['email']) ?>
                                    </a>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">Не указан</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-phone"></i> Телефон</div>
                            <div class="info-value">
                                <?php if ($employee['phone']): ?>
                                    <a href="tel:<?= e(str_replace([' ', '(', ')', '-', '+'], '', $employee['phone'])) ?>" style="color: var(--info-color); text-decoration: none;">
                                        <?= e($employee['phone']) ?>
                                    </a>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">Не указан</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-sign-in-alt"></i> Логин</div>
                            <div class="info-value"><?= $employee['username'] ? e($employee['username']) : '<span style="color: var(--text-muted);">Не задан</span>' ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label"><i class="fas fa-clock"></i> Последний вход</div>
                            <div class="info-value"><?= $employee['last_login_fmt'] ?? '<span style="color: var(--text-muted);">Никогда</span>' ?></div>
                        </div>
                    </div>
                </div>

                <!-- История -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="fas fa-history"></i> История записей</div>
                    </div>
                    <div class="card-body">
                        <div class="timeline-item">
                            <div class="timeline-date">Создано</div>
                            <div class="timeline-content"><?= $employee['created_at_fmt'] ?? '-' ?></div>
                        </div>
                        <div class="timeline-item">
                            <div class="timeline-date">Последнее обновление</div>
                            <div class="timeline-content"><?= $employee['updated_at_fmt'] ?? '-' ?></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
