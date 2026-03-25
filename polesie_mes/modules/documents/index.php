<?php
session_start();

// Проверка авторизации
if (!isset($_SESSION['user_id'])) {
    header('Location: ../../login.php');
    exit;
}

// Демо-данные документов (так как таблица еще не создана в БД)
$documents = [
    ['id' => 1, 'name' => 'ГОСТ 2.105-95 ЕСКД', 'type' => 'ГОСТ', 'status' => 'active', 'date' => '2023-10-15', 'size' => '2.4 MB'],
    ['id' => 2, 'name' => 'Инструкция по охране труда', 'type' => 'Инструкция', 'status' => 'active', 'date' => '2023-11-20', 'size' => '1.1 MB'],
    ['id' => 3, 'name' => 'План эвакуации 2024', 'type' => 'План', 'status' => 'archive', 'date' => '2024-01-10', 'size' => '5.8 MB'],
    ['id' => 4, 'name' => 'Договор поставки №45', 'type' => 'Договор', 'status' => 'draft', 'date' => '2024-02-05', 'size' => '0.8 MB'],
    ['id' => 5, 'name' => 'Сертификат соответствия', 'type' => 'Сертификат', 'status' => 'active', 'date' => '2023-12-12', 'size' => '1.5 MB'],
    ['id' => 6, 'name' => 'ГОСТ 19.101-78 ЕСПД', 'type' => 'ГОСТ', 'status' => 'active', 'date' => '2023-09-05', 'size' => '3.2 MB'],
    ['id' => 7, 'name' => 'Регламент техобслуживания', 'type' => 'Инструкция', 'status' => 'active', 'date' => '2024-03-01', 'size' => '1.8 MB'],
];

// Статистика
$total_docs = count($documents);
$active_docs = count(array_filter($documents, fn($d) => ($d['status'] ?? '') === 'active'));
$archive_docs = count(array_filter($documents, fn($d) => ($d['status'] ?? '') === 'archive'));
$draft_docs = count(array_filter($documents, fn($d) => ($d['status'] ?? '') === 'draft'));
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Документы - PolesieMES</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #2c3e50;
            --secondary-color: #34495e;
            --accent-color: #3498db;
            --text-color: #333;
            --bg-color: #f5f6fa;
            --card-bg: #ffffff;
            --success-color: #27ae60;
            --warning-color: #f39c12;
            --danger-color: #c0392b;
            --border-radius: 12px;
            --shadow: 0 4px 6px rgba(0,0,0,0.1);
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
            background-color: var(--bg-color);
            color: var(--text-color);
            overflow-x: hidden;
        }

        /* Навигация (как на главной) */
        .navbar {
            background: rgba(44, 62, 80, 0.95);
            backdrop-filter: blur(10px);
            padding: 1rem 2rem;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }

        .nav-logo {
            font-size: 1.5rem;
            font-weight: bold;
            color: white;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .nav-menu {
            display: flex;
            gap: 20px;
            list-style: none;
            margin: 0;
            padding: 0;
        }

        .nav-menu a {
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s;
            padding: 8px 16px;
            border-radius: 6px;
        }

        .nav-menu a:hover, .nav-menu a.active {
            color: white;
            background: rgba(255,255,255,0.1);
        }

        .nav-user {
            display: flex;
            align-items: center;
            gap: 10px;
            color: white;
        }

        .nav-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--accent-color);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
        }

        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
        }

        /* Контент */
        .container {
            max-width: 1400px;
            margin: 100px auto 40px;
            padding: 0 20px;
        }

        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .page-title h1 {
            margin: 0;
            font-size: 2rem;
            color: var(--primary-color);
        }

        .page-title p {
            margin: 5px 0 0;
            color: #7f8c8d;
        }

        .btn {
            padding: 10px 20px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .btn-primary {
            background: var(--accent-color);
            color: white;
        }

        .btn-primary:hover {
            background: #2980b9;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(52, 152, 219, 0.3);
        }

        /* Карточки статистики */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: var(--card-bg);
            padding: 25px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            display: flex;
            align-items: center;
            gap: 20px;
            transition: transform 0.3s;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }

        .stat-icon.blue { background: rgba(52, 152, 219, 0.1); color: var(--accent-color); }
        .stat-icon.green { background: rgba(39, 174, 96, 0.1); color: var(--success-color); }
        .stat-icon.orange { background: rgba(243, 156, 18, 0.1); color: var(--warning-color); }

        .stat-info h3 {
            margin: 0;
            font-size: 2rem;
            color: var(--primary-color);
        }

        .stat-info p {
            margin: 0;
            color: #7f8c8d;
            font-size: 0.9rem;
        }

        /* Таблица */
        .table-container {
            background: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        thead {
            background: #f8f9fa;
            border-bottom: 2px solid #eee;
        }

        th {
            padding: 15px 20px;
            text-align: left;
            font-weight: 600;
            color: var(--secondary-color);
            text-transform: uppercase;
            font-size: 0.85rem;
            letter-spacing: 0.5px;
        }

        td {
            padding: 15px 20px;
            border-bottom: 1px solid #eee;
            vertical-align: middle;
        }

        tr:last-child td {
            border-bottom: none;
        }

        tr:hover {
            background: #f8f9fa;
        }

        .doc-name {
            font-weight: 600;
            color: var(--primary-color);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .doc-icon {
            color: var(--accent-color);
            font-size: 1.2rem;
        }

        .badge {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .badge-active { background: rgba(39, 174, 96, 0.1); color: var(--success-color); }
        .badge-archive { background: rgba(149, 165, 166, 0.1); color: #7f8c8d; }
        .badge-draft { background: rgba(243, 156, 18, 0.1); color: var(--warning-color); }

        .action-btn {
            background: none;
            border: none;
            cursor: pointer;
            color: #7f8c8d;
            padding: 5px;
            border-radius: 4px;
            transition: all 0.2s;
        }

        .action-btn:hover {
            background: #f1f2f6;
            color: var(--accent-color);
        }

        @media (max-width: 768px) {
            .nav-menu {
                display: none;
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                background: var(--primary-color);
                flex-direction: column;
                padding: 20px;
                gap: 10px;
            }
            .nav-menu.active { display: flex; }
            .mobile-menu-btn { display: block; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .stats-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

    <!-- Навигация -->
    <nav class="navbar" id="navbar">
        <a href="../../index.php" class="nav-logo">
            <i class="fas fa-industry"></i> PolesieMES
        </a>
        
        <ul class="nav-menu" id="navMenu">
            <li><a href="../../index.php"><i class="fas fa-home"></i> Главная</a></li>
            <li><a href="../equipment/index.php"><i class="fas fa-cogs"></i> Оборудование</a></li>
            <li><a href="../warehouse/index.php"><i class="fas fa-boxes"></i> Склад</a></li>
            <li><a href="../employees/index.php"><i class="fas fa-users"></i> Сотрудники</a></li>
            <li><a href="index.php" class="active"><i class="fas fa-file-alt"></i> Документы</a></li>
        </ul>

        <div class="nav-user">
            <div class="nav-avatar">
                <?php echo strtoupper(substr($_SESSION['username'] ?? 'U', 0, 1)); ?>
            </div>
            <span><?php echo htmlspecialchars($_SESSION['username'] ?? 'Гость'); ?></span>
        </div>

        <button class="mobile-menu-btn" onclick="toggleMenu()">
            <i class="fas fa-bars"></i>
        </button>
    </nav>

    <div class="container">
        <div class="page-header">
            <div class="page-title">
                <h1>Управление документами</h1>
                <p>Реестр технической документации, ГОСТов и инструкций</p>
            </div>
            <button class="btn btn-primary" onclick="alert('Функция загрузки документа будет доступна в следующей версии')">
                <i class="fas fa-plus"></i> Добавить документ
            </button>
        </div>

        <!-- Статистика -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon blue">
                    <i class="fas fa-file-contract"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $total_docs; ?></h3>
                    <p>Всего документов</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green">
                    <i class="fas fa-check-circle"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $active_docs; ?></h3>
                    <p>Действующие</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange">
                    <i class="fas fa-archive"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $archive_docs; ?></h3>
                    <p>В архиве</p>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background: rgba(243, 156, 18, 0.1); color: var(--warning-color);">
                    <i class="fas fa-file-edit"></i>
                </div>
                <div class="stat-info">
                    <h3><?php echo $draft_docs; ?></h3>
                    <p>Черновики</p>
                </div>
            </div>
        </div>

        <!-- Таблица документов -->
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Наименование</th>
                        <th>Тип</th>
                        <th>Дата добавления</th>
                        <th>Размер</th>
                        <th>Статус</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($documents as $doc): ?>
                    <tr>
                        <td>
                            <div class="doc-name">
                                <i class="fas fa-file-pdf doc-icon"></i>
                                <?php echo htmlspecialchars($doc['name']); ?>
                            </div>
                        </td>
                        <td><?php echo htmlspecialchars($doc['type']); ?></td>
                        <td><?php echo date('d.m.Y', strtotime($doc['date'])); ?></td>
                        <td><?php echo $doc['size']; ?></td>
                        <td>
                            <?php
                            $statusClass = 'badge-active';
                            $statusText = 'Действует';
                            if ($doc['status'] === 'archive') {
                                $statusClass = 'badge-archive';
                                $statusText = 'Архив';
                            } elseif ($doc['status'] === 'draft') {
                                $statusClass = 'badge-draft';
                                $statusText = 'Черновик';
                            }
                            ?>
                            <span class="badge <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                        </td>
                        <td>
                            <button class="action-btn" title="Скачать"><i class="fas fa-download"></i></button>
                            <button class="action-btn" title="Просмотр"><i class="fas fa-eye"></i></button>
                            <button class="action-btn" title="Редактировать"><i class="fas fa-edit"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        function toggleMenu() {
            const menu = document.getElementById('navMenu');
            menu.classList.toggle('active');
        }
    </script>
</body>
</html>
