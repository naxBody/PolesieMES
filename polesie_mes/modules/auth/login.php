<?php
/**
 * Страница входа в систему PolesieMES
 * ОАО "Полесьеэлектромаш"
 */

// Подключение конфигурации
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth_functions.php';
require_once __DIR__ . '/../includes/helpers.php';

// Если пользователь уже авторизован - перенаправляем на главную
if (isLoggedIn()) {
    header('Location: ' . APP_URL . '/modules/dashboard/index.php');
    exit;
}

$error = '';
$username = '';

// Обработка формы входа
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = cleanInput($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $error = 'Введите логин и пароль';
    } else {
        if (login($username, $password)) {
            // Успешный вход
            $redirect = $_GET['redirect'] ?? '/modules/dashboard/index.php';
            header('Location: ' . APP_URL . $redirect);
            exit;
        } else {
            $error = 'Неверный логин или пароль';
        }
    }
}

$pageTitle = 'Вход в систему | ' . APP_NAME;
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .login-container {
            max-width: 450px;
            width: 100%;
            padding: 2rem;
        }
        
        .login-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            overflow: hidden;
        }
        
        .login-header {
            background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
            color: white;
            padding: 2.5rem;
            text-align: center;
        }
        
        .login-header i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.9;
        }
        
        .login-header h1 {
            font-size: 1.75rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .login-header p {
            opacity: 0.9;
            margin: 0;
            font-size: 0.9rem;
        }
        
        .login-body {
            padding: 2.5rem;
        }
        
        .form-floating > label {
            color: #6c757d;
        }
        
        .form-control:focus {
            border-color: #0d6efd;
            box-shadow: 0 0 0 0.25rem rgba(13, 110, 253, 0.15);
        }
        
        .btn-login {
            background: linear-gradient(135deg, #0d6efd 0%, #0a58ca 100%);
            border: none;
            padding: 0.875rem;
            font-weight: 600;
            font-size: 1rem;
            transition: all 0.3s ease;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(13, 110, 253, 0.4);
        }
        
        .login-footer {
            text-align: center;
            padding: 1.5rem;
            background: #f8f9fa;
            color: #6c757d;
            font-size: 0.85rem;
        }
        
        .demo-credentials {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 1rem;
            margin-top: 1.5rem;
            font-size: 0.85rem;
        }
        
        .demo-credentials h6 {
            color: #0d6efd;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .demo-credentials ul {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .demo-credentials li {
            padding: 0.25rem 0;
            color: #495057;
        }
        
        .demo-credentials li strong {
            color: #212529;
        }
        
        .alert {
            border-radius: 10px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <i class="fas fa-industry"></i>
                <h1>PolesieMES</h1>
                <p>Система управления производством<br>ОАО "Полесьеэлектромаш"</p>
            </div>
            
            <div class="login-body">
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?= e($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <?php if (isset($_GET['error']) && $_GET['error'] === 'auth_required'): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="fas fa-info-circle me-2"></i>
                    Пожалуйста, войдите в систему для продолжения работы
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="form-floating mb-3">
                        <input type="text" 
                               class="form-control" 
                               id="username" 
                               name="username" 
                               placeholder="Логин"
                               value="<?= e($username) ?>"
                               required 
                               autofocus>
                        <label for="username"><i class="fas fa-user me-2"></i>Логин</label>
                    </div>
                    
                    <div class="form-floating mb-4">
                        <input type="password" 
                               class="form-control" 
                               id="password" 
                               name="password" 
                               placeholder="Пароль"
                               required>
                        <label for="password"><i class="fas fa-lock me-2"></i>Пароль</label>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary btn-login">
                            <i class="fas fa-sign-in-alt me-2"></i>Войти в систему
                        </button>
                    </div>
                </form>
                
                <div class="demo-credentials">
                    <h6><i class="fas fa-key me-2"></i>Тестовые учетные записи:</h6>
                    <ul>
                        <li><strong>admin</strong> / admin123 (Администратор)</li>
                        <li><strong>prod_head</strong> / production2024 (Начальник производства)</li>
                        <li><strong>sales1</strong> / sales123 (Менеджер по продажам)</li>
                        <li><strong>tech1</strong> / tech2024 (Технолог)</li>
                        <li><strong>operator1</strong> / oper123 (Оператор)</li>
                        <li><strong>otk1</strong> / quality123 (Инспектор ОТК)</li>
                        <li><strong>store1</strong> / store123 (Кладовщик)</li>
                    </ul>
                </div>
            </div>
            
            <div class="login-footer">
                <small>
                    &copy; <?= date('Y') ?> ОАО "Полесьеэлектромаш"<br>
                    Система PolesieMES v<?= APP_VERSION ?>
                </small>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
