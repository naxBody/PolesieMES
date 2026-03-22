<?php
/**
 * Страница входа в систему PolesieMES
 * ОАО "Полесьеэлектромаш"
 * Современный дизайн в стиле 2026 - Индустриальный минимализм
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
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    
    <style>
        :root {
            /* Industrial 2026 Color Palette */
            --primary: #0a84ff;
            --primary-dark: #0066cc;
            --secondary: #5e5ce6;
            --accent: #30d158;
            --warning: #ffd60a;
            --danger: #ff453a;
            
            /* Neutral tones */
            --bg-dark: #0d1117;
            --bg-card: #161b22;
            --bg-input: #0d1117;
            --border: #30363d;
            --text-primary: #f0f6fc;
            --text-secondary: #8b949e;
            --text-muted: #6e7681;
            
            /* Gradients */
            --gradient-primary: linear-gradient(135deg, #0a84ff 0%, #5e5ce6 100%);
            --gradient-bg: linear-gradient(180deg, #0d1117 0%, #161b22 100%);
            --gradient-glow: radial-gradient(ellipse at center, rgba(10, 132, 255, 0.15) 0%, transparent 70%);
            
            /* Shadows */
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.3);
            --shadow-md: 0 4px 12px rgba(0, 0, 0, 0.4);
            --shadow-lg: 0 12px 24px rgba(0, 0, 0, 0.5);
            --glow-primary: 0 0 20px rgba(10, 132, 255, 0.3);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--gradient-bg);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-primary);
            overflow-x: hidden;
            position: relative;
        }
        
        /* Animated grid background */
        .grid-bg {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: 
                linear-gradient(rgba(48, 54, 61, 0.3) 1px, transparent 1px),
                linear-gradient(90deg, rgba(48, 54, 61, 0.3) 1px, transparent 1px);
            background-size: 60px 60px;
            animation: gridMove 20s linear infinite;
            z-index: 0;
        }
        
        @keyframes gridMove {
            0% { transform: translate(0, 0); }
            100% { transform: translate(60px, 60px); }
        }
        
        /* Glow effect */
        .glow-overlay {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 800px;
            height: 800px;
            background: var(--gradient-glow);
            border-radius: 50%;
            z-index: 0;
            animation: pulse 4s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 0.5; transform: translate(-50%, -50%) scale(1); }
            50% { opacity: 0.8; transform: translate(-50%, -50%) scale(1.1); }
        }
        
        /* Main container */
        .container {
            position: relative;
            z-index: 10;
            display: grid;
            grid-template-columns: 1fr 1fr;
            max-width: 1100px;
            width: 90%;
            background: var(--bg-card);
            border-radius: 24px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
        }
        
        /* Left panel - Brand */
        .brand-panel {
            background: linear-gradient(135deg, rgba(10, 132, 255, 0.1) 0%, rgba(94, 92, 230, 0.1) 100%);
            padding: 3rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            border-right: 1px solid var(--border);
            position: relative;
        }
        
        .brand-panel::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Cdefs%3E%3Cpattern id='grain' x='0' y='0' width='100' height='100' patternUnits='userSpaceOnUse'%3E%3Ccircle cx='10' cy='10' r='1' fill='%23ffffff' opacity='0.03'/%3E%3Ccircle cx='50' cy='50' r='1' fill='%23ffffff' opacity='0.03'/%3E%3Ccircle cx='90' cy='30' r='1' fill='%23ffffff' opacity='0.03'/%3E%3C/pattern%3E%3C/defs%3E%3Crect width='100' height='100' fill='url(%23grain)'/%3E%3C/svg%3E");
            pointer-events: none;
        }
        
        .brand-header {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .brand-logo {
            width: 56px;
            height: 56px;
            background: var(--gradient-primary);
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--glow-primary);
        }
        
        .brand-logo svg {
            width: 32px;
            height: 32px;
            fill: white;
        }
        
        .brand-name {
            font-size: 1.5rem;
            font-weight: 700;
            letter-spacing: -0.5px;
        }
        
        .brand-tagline {
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-top: 0.25rem;
        }
        
        .brand-content {
            flex: 1;
            display: flex;
            flex-direction: column;
            justify-content: center;
            padding: 2rem 0;
        }
        
        .brand-title {
            font-size: 2.5rem;
            font-weight: 700;
            line-height: 1.2;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, #f0f6fc 0%, #8b949e 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .brand-description {
            font-size: 0.95rem;
            color: var(--text-secondary);
            line-height: 1.7;
            margin-bottom: 2rem;
        }
        
        .features {
            display: grid;
            gap: 1rem;
        }
        
        .feature-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem;
            background: rgba(48, 54, 61, 0.3);
            border-radius: 12px;
            border: 1px solid rgba(48, 54, 61, 0.5);
            transition: all 0.3s ease;
        }
        
        .feature-item:hover {
            background: rgba(48, 54, 61, 0.5);
            border-color: var(--primary);
            transform: translateX(4px);
        }
        
        .feature-icon {
            width: 36px;
            height: 36px;
            background: var(--gradient-primary);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        
        .feature-icon svg {
            width: 18px;
            height: 18px;
            fill: white;
        }
        
        .feature-text {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-primary);
        }
        
        .brand-footer {
            font-size: 0.75rem;
            color: var(--text-muted);
            line-height: 1.6;
        }
        
        /* Right panel - Form */
        .form-panel {
            padding: 3rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        .form-header {
            margin-bottom: 2rem;
        }
        
        .form-title {
            font-size: 1.75rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .form-subtitle {
            font-size: 0.875rem;
            color: var(--text-secondary);
        }
        
        .form-group {
            margin-bottom: 1.25rem;
        }
        
        .form-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
        }
        
        .input-wrapper {
            position: relative;
        }
        
        .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            fill: var(--text-muted);
            transition: fill 0.2s ease;
        }
        
        .form-input {
            width: 100%;
            padding: 0.875rem 1rem 0.875rem 3rem;
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: 12px;
            font-size: 0.95rem;
            font-family: inherit;
            color: var(--text-primary);
            transition: all 0.2s ease;
        }
        
        .form-input::placeholder {
            color: var(--text-muted);
        }
        
        .form-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(10, 132, 255, 0.15);
        }
        
        .form-input:focus + .input-icon {
            fill: var(--primary);
        }
        
        .password-toggle {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            width: 20px;
            height: 20px;
            fill: var(--text-muted);
            transition: fill 0.2s ease;
        }
        
        .password-toggle:hover {
            fill: var(--text-primary);
        }
        
        .btn-submit {
            width: 100%;
            padding: 1rem;
            background: var(--gradient-primary);
            border: none;
            border-radius: 12px;
            font-size: 1rem;
            font-weight: 600;
            font-family: inherit;
            color: white;
            cursor: pointer;
            transition: all 0.3s ease;
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
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s ease;
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: var(--glow-primary);
        }
        
        .btn-submit:hover::before {
            left: 100%;
        }
        
        .btn-submit:active {
            transform: translateY(0);
        }
        
        /* Alert messages */
        .alert {
            padding: 0.875rem 1rem;
            border-radius: 12px;
            font-size: 0.875rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            animation: slideIn 0.3s ease;
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .alert-error {
            background: rgba(255, 69, 58, 0.1);
            border: 1px solid rgba(255, 69, 58, 0.3);
            color: #ff6b61;
        }
        
        .alert-info {
            background: rgba(10, 132, 255, 0.1);
            border: 1px solid rgba(10, 132, 255, 0.3);
            color: #5ac8fa;
        }
        
        .alert svg {
            width: 18px;
            height: 18px;
            flex-shrink: 0;
        }
        
        /* Quick access section */
        .quick-access {
            margin-top: 2rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--border);
        }
        
        .quick-access-title {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .quick-access-title svg {
            width: 14px;
            height: 14px;
            fill: currentColor;
        }
        
        .credentials-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 0.5rem;
        }
        
        .cred-btn {
            padding: 0.75rem;
            background: rgba(48, 54, 61, 0.3);
            border: 1px solid var(--border);
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.2s ease;
            text-align: left;
        }
        
        .cred-btn:hover {
            background: rgba(48, 54, 61, 0.5);
            border-color: var(--primary);
            transform: translateY(-1px);
        }
        
        .cred-user {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-primary);
            font-family: 'JetBrains Mono', monospace;
        }
        
        .cred-role {
            font-size: 0.7rem;
            color: var(--text-muted);
            margin-top: 0.25rem;
        }
        
        /* Loading overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(13, 17, 23, 0.95);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        
        .loader {
            width: 48px;
            height: 48px;
            border: 3px solid var(--border);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Responsive */
        @media (max-width: 900px) {
            .container {
                grid-template-columns: 1fr;
                max-width: 480px;
            }
            
            .brand-panel {
                display: none;
            }
            
            .form-panel {
                padding: 2rem;
            }
        }
    </style>
</head>
<body>
    <div class="grid-bg"></div>
    <div class="glow-overlay"></div>
    
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loader"></div>
    </div>
    
    <div class="container">
        <!-- Brand Panel -->
        <div class="brand-panel">
            <div class="brand-header">
                <div class="brand-logo">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/>
                    </svg>
                </div>
                <div>
                    <div class="brand-name">PolesieMES</div>
                    <div class="brand-tagline">Производственная система</div>
                </div>
            </div>
            
            <div class="brand-content">
                <h2 class="brand-title">Управление производством нового поколения</h2>
                <p class="brand-description">
                    Единая платформа для автоматизации всех процессов предприятия. 
                    Контроль, аналитика и оптимизация в реальном времени.
                </p>
                
                <div class="features">
                    <div class="feature-item">
                        <div class="feature-icon">
                            <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 14l-5-5 1.41-1.41L12 14.17l7.59-7.59L21 8l-9 9z"/></svg>
                        </div>
                        <span class="feature-text">Планирование производства</span>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon">
                            <svg viewBox="0 0 24 24"><path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-5 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/></svg>
                        </div>
                        <span class="feature-text">Контроль качества</span>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon">
                            <svg viewBox="0 0 24 24"><path d="M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3zm0 2c-2.33 0-7 1.17-7 3.5V19h14v-2.5c0-2.33-4.67-3.5-7-3.5zm8 0c-.29 0-.62.02-.97.05 1.16.84 1.97 1.97 1.97 3.45V19h6v-2.5c0-2.33-4.67-3.5-7-3.5z"/></svg>
                        </div>
                        <span class="feature-text">Управление командой</span>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon">
                            <svg viewBox="0 0 24 24"><path d="M19.35 10.04C18.67 6.59 15.64 4 12 4 9.11 4 6.6 5.64 5.35 8.04 2.34 8.36 0 10.91 0 14c0 3.31 2.69 6 6 6h13c2.76 0 5-2.24 5-5 0-2.64-2.05-4.78-4.65-4.96zM17 13l-5 5-5-5h3V9h4v4h3z"/></svg>
                        </div>
                        <span class="feature-text">Облачная синхронизация</span>
                    </div>
                </div>
            </div>
            
            <div class="brand-footer">
                © <?= date('Y') ?> ОАО «Полесьеэлектромаш»<br>
                PolesieMES v<?= APP_VERSION ?>
            </div>
        </div>
        
        <!-- Form Panel -->
        <div class="form-panel">
            <div class="form-header">
                <h1 class="form-title">С возвращением</h1>
                <p class="form-subtitle">Введите данные для входа в систему</p>
            </div>
            
            <?php if ($error): ?>
            <div class="alert alert-error">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/></svg>
                <?= e($error) ?>
            </div>
            <?php endif; ?>
            
            <?php if (isset($_GET['error']) && $_GET['error'] === 'auth_required'): ?>
            <div class="alert alert-info">
                <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-6h2v6zm0-8h-2V7h2v2z"/></svg>
                Требуется авторизация для доступа
            </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="loginForm" onsubmit="showLoading()">
                <div class="form-group">
                    <label class="form-label" for="username">Логин</label>
                    <div class="input-wrapper">
                        <input type="text" 
                               id="username" 
                               name="username" 
                               class="form-input"
                               placeholder="Введите логин"
                               value="<?= e($username) ?>"
                               required 
                               autofocus>
                        <svg class="input-icon" viewBox="0 0 24 24">
                            <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                        </svg>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="password">Пароль</label>
                    <div class="input-wrapper">
                        <input type="password" 
                               id="password" 
                               name="password" 
                               class="form-input"
                               placeholder="Введите пароль"
                               required>
                        <svg class="input-icon" viewBox="0 0 24 24">
                            <path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/>
                        </svg>
                        <svg class="password-toggle" id="togglePassword" viewBox="0 0 24 24" onclick="togglePassword()">
                            <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>
                        </svg>
                    </div>
                </div>
                
                <button type="submit" class="btn-submit">
                    Войти в систему
                </button>
            </form>
            
            <div class="quick-access">
                <div class="quick-access-title">
                    <svg viewBox="0 0 24 24"><path d="M18 8h-1V6c0-2.76-2.24-5-5-5S7 3.24 7 6v2H6c-1.1 0-2 .9-2 2v10c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V10c0-1.1-.9-2-2-2zm-6 9c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2zm3.1-9H8.9V6c0-1.71 1.39-3.1 3.1-3.1 1.71 0 3.1 1.39 3.1 3.1v2z"/></svg>
                    Быстрый доступ
                </div>
                <div class="credentials-grid">
                    <div class="cred-btn" onclick="fillCredentials('admin', 'admin123')">
                        <div class="cred-user">admin</div>
                        <div class="cred-role">Администратор</div>
                    </div>
                    <div class="cred-btn" onclick="fillCredentials('prod_head', 'production2024')">
                        <div class="cred-user">prod_head</div>
                        <div class="cred-role">Нач. производства</div>
                    </div>
                    <div class="cred-btn" onclick="fillCredentials('sales1', 'sales123')">
                        <div class="cred-user">sales1</div>
                        <div class="cred-role">Менеджер</div>
                    </div>
                    <div class="cred-btn" onclick="fillCredentials('tech1', 'tech2024')">
                        <div class="cred-user">tech1</div>
                        <div class="cred-role">Технолог</div>
                    </div>
                    <div class="cred-btn" onclick="fillCredentials('operator1', 'oper123')">
                        <div class="cred-user">operator1</div>
                        <div class="cred-role">Оператор</div>
                    </div>
                    <div class="cred-btn" onclick="fillCredentials('otk1', 'quality123')">
                        <div class="cred-user">otk1</div>
                        <div class="cred-role">Инспектор ОТК</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function togglePassword() {
            const passwordInput = document.getElementById('password');
            const toggleIcon = document.getElementById('togglePassword');
            
            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.innerHTML = '<path d="M12 7c2.76 0 5 2.24 5 5 0 .65-.13 1.26-.36 1.83l2.92 2.92c1.51-1.26 2.7-2.89 3.43-4.75-1.73-4.39-6-7.5-11-7.5-1.4 0-2.74.25-3.98.7l2.16 2.16C10.74 7.13 11.35 7 12 7zM2 4.27l2.28 2.28.46.46C3.08 8.3 1.78 10.02 1 12c1.73 4.39 6 7.5 11 7.5 1.55 0 3.03-.3 4.38-.84l.42.42L19.73 22 21 20.73 3.27 3 2 4.27zM7.53 9.8l1.55 1.55c-.05.21-.08.43-.08.65 0 1.66 1.34 3 3 3 .22 0 .44-.03.65-.08l1.55 1.55c-.67.33-1.41.53-2.2.53-2.76 0-5-2.24-5-5 0-.79.2-1.53.53-2.2zm4.31-.78l3.15 3.15.02-.16c0-1.66-1.34-3-3-3l-.17.01z"/>';
            } else {
                passwordInput.type = 'password';
                toggleIcon.innerHTML = '<path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>';
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
            const alerts = document.querySelectorAll('.alert');
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
