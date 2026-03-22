<?php
/**
 * Страница входа в систему PolesieMES
 * ОАО "Полесьеэлектромаш"
 * Современный компактный дизайн
 */

// Подключение конфигурации
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth_functions.php';
require_once __DIR__ . '/../../includes/helpers.php';

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
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        :root {
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --secondary: #ec4899;
            --accent: #06b6d4;
            --success: #10b981;
            --dark: #0f172a;
            --light: #f8fafc;
            --gradient-main: linear-gradient(135deg, #6366f1 0%, #ec4899 50%, #06b6d4 100%);
            --gradient-card: linear-gradient(135deg, rgba(255,255,255,0.95) 0%, rgba(255,255,255,0.85) 100%);
            --glass-bg: rgba(255, 255, 255, 0.1);
            --glass-border: rgba(255, 255, 255, 0.2);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Outfit', sans-serif;
            background: var(--dark);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            position: relative;
        }
        
        /* Animated gradient background */
        .bg-animated {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(-45deg, #0f172a, #1e1b4b, #312e81, #1e3a5f);
            background-size: 400% 400%;
            animation: gradientShift 15s ease infinite;
            z-index: 0;
        }
        
        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        
        /* Floating orbs */
        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.5;
            animation: float 20s infinite;
        }
        
        .orb-1 {
            width: 400px;
            height: 400px;
            background: var(--primary);
            top: -100px;
            left: -100px;
            animation-delay: 0s;
        }
        
        .orb-2 {
            width: 300px;
            height: 300px;
            background: var(--secondary);
            bottom: -50px;
            right: -50px;
            animation-delay: -5s;
        }
        
        .orb-3 {
            width: 250px;
            height: 250px;
            background: var(--accent);
            top: 50%;
            left: 50%;
            animation-delay: -10s;
        }
        
        @keyframes float {
            0%, 100% { transform: translate(0, 0) scale(1); }
            25% { transform: translate(30px, -30px) scale(1.1); }
            50% { transform: translate(-20px, 20px) scale(0.9); }
            75% { transform: translate(20px, 30px) scale(1.05); }
        }
        
        /* Grid pattern overlay */
        .grid-pattern {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: 
                linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px);
            background-size: 50px 50px;
            z-index: 1;
        }
        
        .login-container {
            position: relative;
            z-index: 10;
            display: grid;
            grid-template-columns: 1fr 1fr;
            max-width: 1000px;
            width: 90%;
            background: var(--gradient-card);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            border: 1px solid var(--glass-border);
            box-shadow: 
                0 25px 50px -12px rgba(0, 0, 0, 0.5),
                0 0 0 1px rgba(255, 255, 255, 0.1);
            overflow: hidden;
        }
        
        .brand-section {
            background: var(--gradient-main);
            padding: 3rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            color: white;
            position: relative;
            overflow: hidden;
        }
        
        .brand-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.05'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
            opacity: 0.5;
        }
        
        .brand-logo {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.5rem;
            border: 1px solid rgba(255, 255, 255, 0.3);
            animation: logoPulse 3s ease-in-out infinite;
        }
        
        @keyframes logoPulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        .brand-logo i {
            font-size: 2.5rem;
            color: white;
        }
        
        .brand-title {
            font-size: 2rem;
            font-weight: 800;
            margin-bottom: 0.5rem;
            letter-spacing: -0.5px;
        }
        
        .brand-subtitle {
            font-size: 0.95rem;
            opacity: 0.9;
            margin-bottom: 2rem;
            line-height: 1.6;
        }
        
        .feature-list {
            list-style: none;
        }
        
        .feature-item {
            display: flex;
            align-items: center;
            padding: 0.75rem 0;
            font-size: 0.9rem;
            opacity: 0.95;
        }
        
        .feature-icon {
            width: 32px;
            height: 32px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 0.75rem;
            font-size: 0.85rem;
        }
        
        .form-section {
            padding: 2.5rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        .form-header {
            margin-bottom: 2rem;
        }
        
        .form-title {
            font-size: 1.75rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 0.5rem;
        }
        
        .form-subtitle {
            color: #64748b;
            font-size: 0.9rem;
        }
        
        .input-wrapper {
            position: relative;
            margin-bottom: 1.25rem;
        }
        
        .input-wrapper i.field-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 1.1rem;
            transition: color 0.3s ease;
            z-index: 2;
        }
        
        .input-wrapper input {
            width: 100%;
            padding: 1rem 1rem 1rem 3rem;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            font-size: 0.95rem;
            font-family: 'Outfit', sans-serif;
            transition: all 0.3s ease;
            background: #f8fafc;
        }
        
        .input-wrapper input:focus {
            outline: none;
            border-color: var(--primary);
            background: white;
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
        }
        
        .input-wrapper input:focus + i.field-icon {
            color: var(--primary);
        }
        
        .input-wrapper label {
            position: absolute;
            left: 3rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 0.9rem;
            pointer-events: none;
            transition: all 0.3s ease;
            background: transparent;
            padding: 0 0.25rem;
        }
        
        .input-wrapper input:focus ~ label,
        .input-wrapper input:not(:placeholder-shown) ~ label {
            top: 0;
            left: 0.75rem;
            font-size: 0.75rem;
            color: var(--primary);
            background: white;
            font-weight: 600;
        }
        
        .password-toggle {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #94a3b8;
            transition: color 0.3s ease;
            z-index: 2;
        }
        
        .password-toggle:hover {
            color: var(--primary);
        }
        
        .btn-submit {
            background: var(--gradient-main);
            border: none;
            padding: 1rem;
            font-weight: 600;
            font-size: 1rem;
            color: white;
            border-radius: 12px;
            width: 100%;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            position: relative;
            overflow: hidden;
        }
        
        .btn-submit::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s ease;
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(99, 102, 241, 0.4);
        }
        
        .btn-submit:hover::before {
            left: 100%;
        }
        
        .btn-submit:active {
            transform: translateY(0);
        }
        
        .demo-section {
            margin-top: 1.5rem;
            padding: 1rem;
            background: linear-gradient(135deg, #f1f5f9 0%, #e2e8f0 100%);
            border-radius: 12px;
            border: 1px solid #cbd5e1;
        }
        
        .demo-title {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 0.75rem;
            display: flex;
            align-items: center;
        }
        
        .demo-title i {
            margin-right: 0.5rem;
        }
        
        .credentials-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.5rem;
        }
        
        .cred-item {
            background: white;
            padding: 0.6rem;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .cred-item:hover {
            border-color: var(--primary);
            box-shadow: 0 4px 12px rgba(99, 102, 241, 0.15);
            transform: translateY(-1px);
        }
        
        .cred-user {
            font-weight: 600;
            color: var(--dark);
            font-size: 0.8rem;
        }
        
        .cred-pass {
            color: #64748b;
            font-size: 0.7rem;
            margin-top: 0.15rem;
        }
        
        .alert-custom {
            border-radius: 12px;
            border: none;
            padding: 0.875rem 1rem;
            margin-bottom: 1.25rem;
            animation: slideIn 0.4s ease;
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .alert-error {
            background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);
            color: #dc2626;
            border: 1px solid #fecaca;
        }
        
        .alert-info {
            background: linear-gradient(135deg, #f0f9ff 0%, #e0f2fe 100%);
            color: #0284c7;
            border: 1px solid #bae6fd;
        }
        
        .form-footer {
            text-align: center;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #e2e8f0;
        }
        
        .form-footer small {
            color: #64748b;
            font-size: 0.75rem;
            display: block;
            line-height: 1.6;
        }
        
        .security-badges {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 1rem;
        }
        
        .security-badges i {
            color: var(--success);
            font-size: 1rem;
        }
        
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(15, 23, 42, 0.9);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        
        .loader {
            width: 50px;
            height: 50px;
            border: 3px solid rgba(255,255,255,0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        @media (max-width: 768px) {
            .login-container {
                grid-template-columns: 1fr;
                max-width: 450px;
            }
            
            .brand-section {
                display: none;
            }
            
            .form-section {
                padding: 2rem;
            }
        }
    </style>
</head>
<body>
    <div class="bg-animated"></div>
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>
    <div class="grid-pattern"></div>
    
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loader"></div>
    </div>
    
    <div class="login-container">
        <div class="brand-section">
            <div class="brand-logo">
                <i class="fas fa-bolt"></i>
            </div>
            <h1 class="brand-title">PolesieMES</h1>
            <p class="brand-subtitle">
                Интеллектуальная система управления<br>производством нового поколения
            </p>
            
            <ul class="feature-list">
                <li class="feature-item">
                    <div class="feature-icon">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <span>Аналитика в реальном времени</span>
                </li>
                <li class="feature-item">
                    <div class="feature-icon">
                        <i class="fas fa-sync-alt"></i>
                    </div>
                    <span>Автоматизация процессов</span>
                </li>
                <li class="feature-item">
                    <div class="feature-icon">
                        <i class="fas fa-shield-check"></i>
                    </div>
                    <span>Контроль качества</span>
                </li>
                <li class="feature-item">
                    <div class="feature-icon">
                        <i class="fas fa-users-gear"></i>
                    </div>
                    <span>Управление командой</span>
                </li>
            </ul>
        </div>
        
        <div class="form-section">
            <div class="form-header">
                <h2 class="form-title">С возвращением!</h2>
                <p class="form-subtitle">Войдите для продолжения работы</p>
            </div>
            
            <?php if ($error): ?>
            <div class="alert-custom alert-error">
                <i class="fas fa-circle-exclamation me-2"></i>
                <?= e($error) ?>
            </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['error']) && $_GET['error'] === 'auth_required'): ?>
            <div class="alert-custom alert-info">
                <i class="fas fa-info-circle me-2"></i>
                Требуется авторизация
            </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="loginForm" onsubmit="showLoading()">
                <div class="input-wrapper">
                    <input type="text" 
                           id="username" 
                           name="username" 
                           placeholder=" "
                           value="<?= e($username) ?>"
                           required 
                           autofocus>
                    <label for="username">Логин</label>
                    <i class="fas fa-user field-icon"></i>
                </div>
                
                <div class="input-wrapper">
                    <input type="password" 
                           id="password" 
                           name="password" 
                           placeholder=" "
                           required>
                    <label for="password">Пароль</label>
                    <i class="fas fa-lock field-icon"></i>
                    <span class="password-toggle" onclick="togglePassword()">
                        <i class="fas fa-eye" id="toggleIcon"></i>
                    </span>
                </div>
                
                <button type="submit" class="btn-submit">
                    <i class="fas fa-arrow-right-to-bracket me-2"></i>Войти
                </button>
            </form>
            
            <div class="demo-section">
                <div class="demo-title">
                    <i class="fas fa-key"></i>
                    Быстрый доступ
                </div>
                <div class="credentials-grid">
                    <div class="cred-item" onclick="fillCredentials('admin', 'admin123')">
                        <div class="cred-user">admin</div>
                        <div class="cred-pass">Администратор</div>
                    </div>
                    <div class="cred-item" onclick="fillCredentials('prod_head', 'production2024')">
                        <div class="cred-user">prod_head</div>
                        <div class="cred-pass">Нач. производства</div>
                    </div>
                    <div class="cred-item" onclick="fillCredentials('sales1', 'sales123')">
                        <div class="cred-user">sales1</div>
                        <div class="cred-pass">Менеджер</div>
                    </div>
                    <div class="cred-item" onclick="fillCredentials('tech1', 'tech2024')">
                        <div class="cred-user">tech1</div>
                        <div class="cred-pass">Технолог</div>
                    </div>
                    <div class="cred-item" onclick="fillCredentials('operator1', 'oper123')">
                        <div class="cred-user">operator1</div>
                        <div class="cred-pass">Оператор</div>
                    </div>
                    <div class="cred-item" onclick="fillCredentials('otk1', 'quality123')">
                        <div class="cred-user">otk1</div>
                        <div class="cred-pass">Инспектор ОТК</div>
                    </div>
                </div>
            </div>
            
            <div class="form-footer">
                <small>
                    &copy; <?= date('Y') ?> ОАО "Полесьеэлектромаш"<br>
                    PolesieMES v<?= APP_VERSION ?>
                </small>
                <div class="security-badges">
                    <i class="fas fa-lock" title="Защищено"></i>
                    <i class="fas fa-shield-halved" title="Безопасно"></i>
                    <i class="fas fa-circle-check" title="Проверено"></i>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }
        
        function showLoading() {
            document.getElementById('loadingOverlay').style.display = 'flex';
        }
        
        function fillCredentials(user, pass) {
            document.getElementById('username').value = user;
            document.getElementById('password').value = pass;
        }
        
        // Auto-hide alerts
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert-custom');
            alerts.forEach(alert => {
                setTimeout(() => {
                    alert.style.opacity = '0';
                    alert.style.transform = 'translateY(-10px)';
                    setTimeout(() => alert.remove(), 300);
                }, 5000);
            });
        });
    </script>
</body>
</html>
        :root {
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --secondary-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --accent-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            --dark-gradient: linear-gradient(135deg, #434343 0%, #000000 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --glass-bg: rgba(255, 255, 255, 0.95);
            --glass-border: rgba(255, 255, 255, 0.18);
            --shadow-light: 0 8px 32px 0 rgba(31, 38, 135, 0.37);
            --shadow-heavy: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Montserrat', sans-serif;
            background: var(--primary-gradient);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow-x: hidden;
            position: relative;
        }
        
        /* Animated Background */
        body::before {
            content: '';
            position: absolute;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
            background-size: 50px 50px;
            animation: moveBackground 20s linear infinite;
            z-index: 0;
        }
        
        @keyframes moveBackground {
            0% { transform: translate(0, 0); }
            100% { transform: translate(-50px, -50px); }
        }
        
        .floating-shapes {
            position: absolute;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: 1;
        }
        
        .shape {
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.1);
            animation: float 15s infinite;
        }
        
        .shape:nth-child(1) { width: 80px; height: 80px; top: 10%; left: 10%; animation-delay: 0s; }
        .shape:nth-child(2) { width: 60px; height: 60px; top: 60%; left: 80%; animation-delay: 2s; }
        .shape:nth-child(3) { width: 100px; height: 100px; top: 40%; left: 40%; animation-delay: 4s; }
        .shape:nth-child(4) { width: 40px; height: 40px; top: 80%; left: 20%; animation-delay: 6s; }
        .shape:nth-child(5) { width: 70px; height: 70px; top: 20%; left: 70%; animation-delay: 8s; }
        
        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); opacity: 0.3; }
            50% { transform: translateY(-20px) rotate(180deg); opacity: 0.6; }
        }
        
        .login-wrapper {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 1200px;
            padding: 2rem;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 2rem;
            align-items: center;
        }
        
        .info-section {
            color: white;
            padding: 2rem;
            animation: fadeInLeft 1s ease;
        }
        
        .info-section h1 {
            font-size: 3.5rem;
            font-weight: 800;
            margin-bottom: 1rem;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
            background: linear-gradient(45deg, #fff, #f0f0f0);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .info-section p {
            font-size: 1.2rem;
            opacity: 0.95;
            line-height: 1.8;
            margin-bottom: 2rem;
        }
        
        .features-list {
            list-style: none;
            margin-top: 2rem;
        }
        
        .features-list li {
            padding: 0.75rem 0;
            display: flex;
            align-items: center;
            font-size: 1.1rem;
            animation: fadeInUp 0.5s ease backwards;
        }
        
        .features-list li:nth-child(1) { animation-delay: 0.2s; }
        .features-list li:nth-child(2) { animation-delay: 0.4s; }
        .features-list li:nth-child(3) { animation-delay: 0.6s; }
        .features-list li:nth-child(4) { animation-delay: 0.8s; }
        
        .features-list i {
            width: 40px;
            height: 40px;
            background: var(--accent-gradient);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 1rem;
            font-size: 1.2rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        
        .login-card {
            background: var(--glass-bg);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 30px;
            border: 1px solid var(--glass-border);
            box-shadow: var(--shadow-light), var(--shadow-heavy);
            overflow: hidden;
            animation: fadeInRight 1s ease;
            position: relative;
        }
        
        .login-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: var(--accent-gradient);
        }
        
        .login-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2.5rem;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .login-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: pulse 3s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 0.5; }
            50% { transform: scale(1.1); opacity: 0.8; }
        }
        
        .logo-container {
            width: 100px;
            height: 100px;
            background: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            position: relative;
            z-index: 1;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            animation: logoFloat 3s ease-in-out infinite;
        }
        
        @keyframes logoFloat {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        
        .logo-container i {
            font-size: 3.5rem;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .login-header h1 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            position: relative;
            z-index: 1;
        }
        
        .login-header p {
            opacity: 0.9;
            margin: 0;
            font-size: 0.95rem;
            position: relative;
            z-index: 1;
        }
        
        .login-body {
            padding: 2.5rem;
        }
        
        .input-group-custom {
            position: relative;
            margin-bottom: 1.5rem;
        }
        
        .input-group-custom i {
            position: absolute;
            left: 1.25rem;
            top: 50%;
            transform: translateY(-50%);
            color: #667eea;
            font-size: 1.2rem;
            transition: all 0.3s ease;
            z-index: 2;
        }
        
        .input-group-custom input {
            width: 100%;
            padding: 1.25rem 1.25rem 1.25rem 3.5rem;
            border: 2px solid #e0e0e0;
            border-radius: 15px;
            font-size: 1rem;
            font-family: 'Montserrat', sans-serif;
            transition: all 0.3s ease;
            background: #fafafa;
        }
        
        .input-group-custom input:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.15);
            transform: translateY(-2px);
        }
        
        .input-group-custom input:focus + i {
            color: #764ba2;
        }
        
        .input-group-custom label {
            position: absolute;
            left: 3.5rem;
            top: 50%;
            transform: translateY(-50%);
            color: #999;
            font-size: 0.95rem;
            pointer-events: none;
            transition: all 0.3s ease;
            background: transparent;
            padding: 0 0.25rem;
        }
        
        .input-group-custom input:focus ~ label,
        .input-group-custom input:not(:placeholder-shown) ~ label {
            top: 0;
            left: 1rem;
            font-size: 0.8rem;
            color: #667eea;
            background: white;
            font-weight: 600;
        }
        
        .btn-login {
            background: var(--primary-gradient);
            border: none;
            padding: 1.25rem;
            font-weight: 700;
            font-size: 1.1rem;
            color: white;
            border-radius: 15px;
            width: 100%;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .btn-login::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s ease;
        }
        
        .btn-login:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.5);
        }
        
        .btn-login:hover::before {
            left: 100%;
        }
        
        .btn-login:active {
            transform: translateY(-1px);
        }
        
        .divider {
            text-align: center;
            margin: 1.5rem 0;
            position: relative;
        }
        
        .divider::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            width: 45%;
            height: 1px;
            background: linear-gradient(90deg, transparent, #ddd, transparent);
        }
        
        .divider::after {
            content: '';
            position: absolute;
            right: 0;
            top: 50%;
            width: 45%;
            height: 1px;
            background: linear-gradient(90deg, transparent, #ddd, transparent);
        }
        
        .divider span {
            background: white;
            padding: 0 1rem;
            color: #999;
            font-size: 0.85rem;
            position: relative;
            z-index: 1;
        }
        
        .demo-credentials {
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            border-radius: 15px;
            padding: 1.5rem;
            margin-top: 1.5rem;
            border: 1px solid #dee2e6;
        }
        
        .demo-credentials h6 {
            color: #667eea;
            font-weight: 700;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            font-size: 0.95rem;
        }
        
        .demo-credentials h6 i {
            margin-right: 0.5rem;
            font-size: 1.1rem;
        }
        
        .credentials-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.75rem;
        }
        
        .credential-item {
            background: white;
            padding: 0.75rem;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            transition: all 0.3s ease;
        }
        
        .credential-item:hover {
            border-color: #667eea;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.2);
            transform: translateY(-2px);
        }
        
        .credential-item strong {
            color: #764ba2;
            font-size: 0.9rem;
        }
        
        .credential-item span {
            display: block;
            color: #666;
            font-size: 0.8rem;
            margin-top: 0.25rem;
        }
        
        .alert {
            border-radius: 15px;
            border: none;
            padding: 1rem 1.25rem;
            margin-bottom: 1.5rem;
            animation: shake 0.5s ease;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-5px); }
            40%, 80% { transform: translateX(5px); }
        }
        
        .alert-danger {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a5a 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(238, 90, 90, 0.4);
        }
        
        .alert-info {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            color: white;
            box-shadow: 0 4px 15px rgba(79, 172, 254, 0.4);
        }
        
        .login-footer {
            text-align: center;
            padding: 1.5rem;
            background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
            color: #6c757d;
            font-size: 0.85rem;
            border-top: 1px solid #dee2e6;
        }
        
        .login-footer small {
            display: block;
            line-height: 1.6;
        }
        
        .security-badge {
            display: flex;
            justify-content: center;
            gap: 1rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid #eee;
        }
        
        .security-badge i {
            color: #28a745;
            font-size: 1.2rem;
        }
        
        /* Animations */
        @keyframes fadeInLeft {
            from { opacity: 0; transform: translateX(-50px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        @keyframes fadeInRight {
            from { opacity: 0; transform: translateX(50px); }
            to { opacity: 1; transform: translateX(0); }
        }
        
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Responsive */
        @media (max-width: 968px) {
            .login-wrapper {
                grid-template-columns: 1fr;
                max-width: 500px;
            }
            
            .info-section {
                display: none;
            }
            
            .info-section h1 {
                font-size: 2.5rem;
            }
        }
        
        /* Loading spinner */
        .spinner-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        
        .spinner {
            width: 60px;
            height: 60px;
            border: 5px solid rgba(255,255,255,0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Password toggle */
        .password-toggle {
            position: absolute;
            right: 1.25rem;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #999;
            transition: color 0.3s ease;
            z-index: 2;
        }
        
        .password-toggle:hover {
            color: #667eea;
        }
    </style>
</head>
<body>
    <!-- Floating shapes background -->
    <div class="floating-shapes">
        <div class="shape"></div>
        <div class="shape"></div>
        <div class="shape"></div>
        <div class="shape"></div>
        <div class="shape"></div>
    </div>
    
    <!-- Loading overlay -->
    <div class="spinner-overlay" id="loadingOverlay">
        <div class="spinner"></div>
    </div>
    
    <div class="login-wrapper">
        <!-- Info Section -->
        <div class="info-section">
            <h1 class="animate__animated animate__fadeInLeft">PolesieMES</h1>
            <p class="animate__animated animate__fadeInLeft animate__delay-1s">
                Современная система управления производством<br>
                <strong>ОАО "Полесьеэлектромаш"</strong>
            </p>
            
            <ul class="features-list">
                <li>
                    <i class="fas fa-chart-line"></i>
                    <span>Полный контроль над производством</span>
                </li>
                <li>
                    <i class="fas fa-tasks"></i>
                    <span>Управление заказами и задачами</span>
                </li>
                <li>
                    <i class="fas fa-users"></i>
                    <span>Координация работы сотрудников</span>
                </li>
                <li>
                    <i class="fas fa-shield-alt"></i>
                    <span>Контроль качества продукции</span>
                </li>
            </ul>
        </div>
        
        <!-- Login Card -->
        <div class="login-card">
            <div class="login-header">
                <div class="logo-container">
                    <i class="fas fa-industry"></i>
                </div>
                <h1>Добро пожаловать!</h1>
                <p>Войдите для продолжения работы</p>
            </div>
            
            <div class="login-body">
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?= e($error) ?>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <?php if (isset($_GET['error']) && $_GET['error'] === 'auth_required'): ?>
                <div class="alert alert-info alert-dismissible fade show" role="alert">
                    <i class="fas fa-info-circle me-2"></i>
                    Пожалуйста, войдите в систему для продолжения работы
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <form method="POST" action="" id="loginForm" onsubmit="showLoading()">
                    <div class="input-group-custom">
                        <input type="text" 
                               id="username" 
                               name="username" 
                               placeholder=" "
                               value="<?= e($username) ?>"
                               required 
                               autofocus>
                        <label for="username">Логин</label>
                        <i class="fas fa-user"></i>
                    </div>
                    
                    <div class="input-group-custom">
                        <input type="password" 
                               id="password" 
                               name="password" 
                               placeholder=" "
                               required>
                        <label for="password">Пароль</label>
                        <i class="fas fa-lock"></i>
                        <span class="password-toggle" onclick="togglePassword()">
                            <i class="fas fa-eye" id="toggleIcon"></i>
                        </span>
                    </div>
                    
                    <button type="submit" class="btn btn-login">
                        <i class="fas fa-sign-in-alt me-2"></i>Войти в систему
                    </button>
                </form>
                
                <div class="divider">
                    <span>Тестовые учетные записи</span>
                </div>
                
                <div class="demo-credentials">
                    <h6><i class="fas fa-key me-2"></i>Быстрый доступ:</h6>
                    <div class="credentials-grid">
                        <div class="credential-item">
                            <strong>admin / admin123</strong>
                            <span>Администратор</span>
                        </div>
                        <div class="credential-item">
                            <strong>prod_head / production2024</strong>
                            <span>Нач. производства</span>
                        </div>
                        <div class="credential-item">
                            <strong>sales1 / sales123</strong>
                            <span>Менеджер</span>
                        </div>
                        <div class="credential-item">
                            <strong>tech1 / tech2024</strong>
                            <span>Технолог</span>
                        </div>
                        <div class="credential-item">
                            <strong>operator1 / oper123</strong>
                            <span>Оператор</span>
                        </div>
                        <div class="credential-item">
                            <strong>otk1 / quality123</strong>
                            <span>Инспектор ОТК</span>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="login-footer">
                <small>
                    &copy; <?= date('Y') ?> ОАО "Полесьеэлектромаш"<br>
                    Система PolesieMES v<?= APP_VERSION ?>
                </small>
                <div class="security-badge">
                    <i class="fas fa-lock" title="Безопасное соединение"></i>
                    <i class="fas fa-shield-alt" title="Защита данных"></i>
                    <i class="fas fa-check-circle" title="Проверенная система"></i>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Toggle password visibility
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordInput.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }
        
        // Show loading spinner on form submit
        function showLoading() {
            document.getElementById('loadingOverlay').style.display = 'flex';
        }
        
        // Add ripple effect to button
        document.querySelector('.btn-login').addEventListener('click', function(e) {
            const rect = this.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            
            const ripple = document.createElement('span');
            ripple.style.position = 'absolute';
            ripple.style.background = 'rgba(255, 255, 255, 0.5)';
            ripple.style.borderRadius = '50%';
            ripple.style.pointerEvents = 'none';
            ripple.style.width = '100px';
            ripple.style.height = '100px';
            ripple.style.left = (x - 50) + 'px';
            ripple.style.top = (y - 50) + 'px';
            ripple.style.transform = 'scale(0)';
            ripple.style.animation = 'ripple 0.6s ease-out';
            
            this.style.position = 'relative';
            this.style.overflow = 'hidden';
            this.appendChild(ripple);
            
            setTimeout(() => ripple.remove(), 600);
        });
        
        // Auto-hide alerts after 5 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                setTimeout(() => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 5000);
            });
        });
        
        // Add smooth entrance animations
        document.addEventListener('DOMContentLoaded', function() {
            const elements = document.querySelectorAll('.credential-item');
            elements.forEach((el, index) => {
                el.style.opacity = '0';
                el.style.animation = `fadeInUp 0.5s ease ${index * 0.1}s forwards`;
            });
        });
    </script>
</body>
</html>
