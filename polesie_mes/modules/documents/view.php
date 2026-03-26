<?php
/**
 * Модуль документов - Просмотр документа
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

// Получаем документ из демо-данных (так как таблица еще не создана)
$documents = [
    ['id' => 1, 'name' => 'ГОСТ 2.105-95 ЕСКД', 'type' => 'ГОСТ', 'status' => 'active', 'date' => '2023-10-15', 'size' => '2.4 MB', 'content' => 'Общие требования к текстовым документам'],
    ['id' => 2, 'name' => 'Инструкция по охране труда', 'type' => 'Инструкция', 'status' => 'active', 'date' => '2023-11-20', 'size' => '1.1 MB', 'content' => 'Правила техники безопасности на производстве'],
];

$document = null;
foreach ($documents as $doc) {
    if ($doc['id'] == $id) {
        $document = $doc;
        break;
    }
}

if (!$document) {
    header('Location: index.php');
    exit;
}

$pageTitle = 'Просмотр документа | ' . APP_NAME;
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
            <li><a href="index.php" class="nav-link active">Документы</a></li>
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
                <h1><i class="fas fa-file-alt"></i> <?= e($document['name']) ?></h1>
                <p>Информация о документе</p>
            </div>
            <div>
                <a href="index.php" class="btn-secondary-custom"><i class="fas fa-arrow-left"></i> Назад</a>
                <a href="#" class="btn-primary-custom" onclick="alert('Скачивание файла...')"><i class="fas fa-download"></i> Скачать</a>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><div class="card-title">Основная информация</div></div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Название:</strong> <?= e($document['name']) ?></p>
                        <p><strong>Тип:</strong> <?= e($document['type']) ?></p>
                        <p><strong>Размер:</strong> <?= e($document['size']) ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Дата добавления:</strong> <?= date('d.m.Y', strtotime($document['date'])) ?></p>
                        <p><strong>Статус:</strong> 
                            <span class="badge-status <?= $document['status'] ?>">
                                <?= $document['status'] == 'active' ? 'Действует' : ($document['status'] == 'archive' ? 'Архив' : 'Черновик') ?>
                            </span>
                        </p>
                    </div>
                </div>
                <hr>
                <p><strong>Описание:</strong></p>
                <p><?= e($document['content']) ?></p>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
