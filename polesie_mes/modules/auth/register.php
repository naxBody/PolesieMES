<?php
/**
 * Страница регистрации в системе PolesieMES
 * ОАО "Полесьеэлектромаш"
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/helpers.php';

$error = '';
$success = '';

// Обработка формы регистрации
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = cleanInput($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    $firstName = cleanInput($_POST['first_name'] ?? '');
    $lastName = cleanInput($_POST['last_name'] ?? '');
    $middleName = cleanInput($_POST['middle_name'] ?? '');
    $email = cleanInput($_POST['email'] ?? '');
    $phone = cleanInput($_POST['phone'] ?? '');
    $position = cleanInput($_POST['position'] ?? '');
    
    // Валидация
    if (empty($username) || empty($password) || empty($firstName) || empty($lastName)) {
        $error = 'Заполните обязательные поля';
    } elseif ($password !== $confirmPassword) {
        $error = 'Пароли не совпадают';
    } elseif (strlen($password) < 6) {
        $error = 'Пароль должен быть не менее 6 символов';
    } else {
        $db = getDB();
        
        // Проверка существования пользователя
        $stmt = $db->prepare("SELECT id FROM staff WHERE username = :username");
        $stmt->execute(['username' => $username]);
        
        if ($stmt->fetch()) {
            $error = 'Пользователь с таким логином уже существует';
        } else {
            try {
                // Получаем ID должности или создаем новую
                $positionId = null;
                if (!empty($position)) {
                    $stmt = $db->prepare("SELECT id FROM dictionaries WHERE dict_type = 'position' AND name = :name");
                    $stmt->execute(['name' => $position]);
                    $pos = $stmt->fetch();
                    $positionId = $pos ? $pos['id'] : null;
                }
                
                // Генерация табельного номера
                $employeeCode = 'EMP' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
                
                // Регистрация по умолчанию как manager (требует подтверждения админа)
                $stmt = $db->prepare("
                    INSERT INTO staff (employee_code, username, password, first_name, last_name, middle_name, 
                                      position_id, email, phone, role, status, is_active)
                    VALUES (:employee_code, :username, :password, :first_name, :last_name, :middle_name,
                            :position_id, :email, :phone, 'manager', 'active', 0)
                ");
                
                $stmt->execute([
                    'employee_code' => $employeeCode,
                    'username' => $username,
                    'password' => $password, // В продакшене использовать password_hash()
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'middle_name' => $middleName,
                    'position_id' => $positionId,
                    'email' => $email,
                    'phone' => $phone
                ]);
                
                $success = 'Регистрация успешна! Ожидайте подтверждения администратора.';
                
            } catch (PDOException $e) {
                $error = 'Ошибка при регистрации: ' . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Регистрация | ' . APP_NAME;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-gradient-start: #FF6B6B;
            --primary-gradient-end: #FF8E53;
            --bg-dark: #0a0a0f;
            --bg-card: rgba(20, 20, 30, 0.6);
            --bg-input: rgba(30, 30, 45, 0.5);
            --border: rgba(255, 255, 255, 0.1);
            --text-primary: #ffffff;
            --text-secondary: rgba(255, 255, 255, 0.7);
            --text-muted: rgba(255, 255, 255, 0.4);
            --gradient-primary: linear-gradient(135deg, #FF6B6B 0%, #FF8E53 100%);
            --glass-bg: rgba(255, 255, 255, 0.03);
            --glass-border: rgba(255, 255, 255, 0.08);
            --shadow-lg: 0 16px 48px rgba(0, 0, 0, 0.5);
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Outfit', sans-serif;
            background: linear-gradient(180deg, #0a0a0f 0%, #12121a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-primary);
            padding: 2rem;
        }
        .container {
            max-width: 500px;
            width: 100%;
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-lg);
            padding: 2.5rem;
        }
        .header { text-align: center; margin-bottom: 2rem; }
        .logo {
            width: 64px; height: 64px;
            background: var(--gradient-primary);
            border-radius: 16px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
            font-size: 1.5rem;
            font-weight: 700;
        }
        h1 { font-size: 1.8rem; margin-bottom: 0.5rem; }
        .subtitle { color: var(--text-secondary); }
        .form-group { margin-bottom: 1.25rem; }
        label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        input {
            width: 100%;
            padding: 0.875rem 1rem;
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: 12px;
            color: var(--text-primary);
            font-size: 1rem;
        }
        input:focus {
            outline: none;
            border-color: var(--primary-gradient-start);
        }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        .btn {
            width: 100%;
            padding: 1rem;
            background: var(--gradient-primary);
            border: none;
            border-radius: 12px;
            color: white;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            margin-top: 1rem;
        }
        .btn:hover { opacity: 0.9; }
        .alert {
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
        }
        .alert-error { background: rgba(255, 69, 58, 0.2); border: 1px solid #ff453a; }
        .alert-success { background: rgba(48, 209, 88, 0.2); border: 1px solid #30d158; }
        .login-link {
            text-align: center;
            margin-top: 1.5rem;
            color: var(--text-secondary);
        }
        .login-link a { color: #FF6B6B; text-decoration: none; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="logo">П</div>
            <h1>Регистрация</h1>
            <p class="subtitle">Создание учетной записи PolesieMES</p>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><?= e($error) ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><?= e($success) ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="row">
                <div class="form-group">
                    <label>Фамилия *</label>
                    <input type="text" name="last_name" required>
                </div>
                <div class="form-group">
                    <label>Имя *</label>
                    <input type="text" name="first_name" required>
                </div>
            </div>
            
            <div class="form-group">
                <label>Отчество</label>
                <input type="text" name="middle_name">
            </div>
            
            <div class="form-group">
                <label>Логин *</label>
                <input type="text" name="username" required>
            </div>
            
            <div class="row">
                <div class="form-group">
                    <label>Пароль *</label>
                    <input type="password" name="password" required minlength="6">
                </div>
                <div class="form-group">
                    <label>Подтверждение *</label>
                    <input type="password" name="confirm_password" required>
                </div>
            </div>
            
            <div class="row">
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email">
                </div>
                <div class="form-group">
                    <label>Телефон</label>
                    <input type="tel" name="phone">
                </div>
            </div>
            
            <div class="form-group">
                <label>Должность</label>
                <input type="text" name="position" placeholder="Например: Менеджер">
            </div>
            
            <button type="submit" class="btn">Зарегистрироваться</button>
        </form>
        
        <div class="login-link">
            Уже есть аккаунт? <a href="<?= APP_URL ?>/modules/auth/login.php">Войти</a>
        </div>
    </div>
</body>
</html>
