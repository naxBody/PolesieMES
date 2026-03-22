<?php
/**
 * Страница входа в систему PolesieMES
 * ОАО "Полесьеэлектромаш"
 * Современный дизайн 2026 - Glassmorphism с оранжево-коралловым градиентом
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
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    
    <style>
        :root {
            /* Оранжево-коралловая палитра */
            --primary-gradient-start: #FF6B6B;
            --primary-gradient-end: #FF8E53;
            --primary-glow: rgba(255, 107, 107, 0.4);
            --secondary-glow: rgba(255, 142, 83, 0.3);
            
            /* Темный фон */
            --bg-dark: #0a0a0f;
            --bg-card: rgba(20, 20, 30, 0.6);
            --bg-input: rgba(30, 30, 45, 0.5);
            --border: rgba(255, 255, 255, 0.1);
            --border-glow: rgba(255, 107, 107, 0.3);
            
            /* Текст */
            --text-primary: #ffffff;
            --text-secondary: rgba(255, 255, 255, 0.7);
            --text-muted: rgba(255, 255, 255, 0.4);
            
            /* Градиенты */
            --gradient-primary: linear-gradient(135deg, #FF6B6B 0%, #FF8E53 100%);
            --gradient-bg: linear-gradient(180deg, #0a0a0f 0%, #12121a 100%);
            --gradient-glow: radial-gradient(ellipse 600px 400px at 20% 20%, rgba(255, 107, 107, 0.15) 0%, transparent 50%),
                             radial-gradient(ellipse 500px 350px at 80% 80%, rgba(255, 142, 83, 0.12) 0%, transparent 50%);
            
            /* Glassmorphism */
            --glass-bg: rgba(255, 255, 255, 0.03);
            --glass-border: rgba(255, 255, 255, 0.08);
            --glass-shadow: 0 8px 32px rgba(0, 0, 0, 0.4);
            --backdrop-blur: blur(20px);
            
            /* Тени и свечение */
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.3);
            --shadow-md: 0 8px 24px rgba(0, 0, 0, 0.4);
            --shadow-lg: 0 16px 48px rgba(0, 0, 0, 0.5);
            --glow-primary: 0 0 30px var(--primary-glow);
            --glow-secondary: 0 0 20px var(--secondary-glow);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Outfit', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--gradient-bg);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-primary);
            overflow-x: hidden;
            position: relative;
        }
        
        /* Анимированный фон с частицами */
        .particles-container {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            overflow: hidden;
        }
        
        .particle {
            position: absolute;
            border-radius: 50%;
            background: var(--gradient-primary);
            opacity: 0.3;
            animation: float 15s infinite ease-in-out;
        }
        
        .particle:nth-child(1) { width: 80px; height: 80px; top: 10%; left: 15%; animation-delay: 0s; }
        .particle:nth-child(2) { width: 60px; height: 60px; top: 70%; left: 75%; animation-delay: 2s; }
        .particle:nth-child(3) { width: 100px; height: 100px; top: 40%; left: 85%; animation-delay: 4s; }
        .particle:nth-child(4) { width: 40px; height: 40px; top: 80%; left: 25%; animation-delay: 6s; }
        .particle:nth-child(5) { width: 70px; height: 70px; top: 20%; left: 65%; animation-delay: 8s; }
        
        @keyframes float {
            0%, 100% { transform: translate(0, 0) scale(1); opacity: 0.3; }
            25% { transform: translate(20px, -30px) scale(1.1); opacity: 0.5; }
            50% { transform: translate(-15px, 20px) scale(0.9); opacity: 0.4; }
            75% { transform: translate(25px, 15px) scale(1.05); opacity: 0.35; }
        }
        
        /* Glow effect на фон */
        .glow-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--gradient-glow);
            z-index: 1;
            pointer-events: none;
        }
        
        /* Сетка на фоне */
        .grid-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: 
                linear-gradient(rgba(255, 255, 255, 0.02) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.02) 1px, transparent 1px);
            background-size: 80px 80px;
            z-index: 2;
            pointer-events: none;
        }
        
        /* Main container */
        .container {
            position: relative;
            z-index: 10;
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            max-width: 1200px;
            width: 90%;
            min-height: 650px;
            background: var(--bg-card);
            backdrop-filter: var(--backdrop-blur);
            -webkit-backdrop-filter: var(--backdrop-blur);
            border-radius: 32px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-lg), 0 0 100px rgba(255, 107, 107, 0.1);
            overflow: hidden;
        }
        
        /* Left panel - Brand */
        .brand-panel {
            background: linear-gradient(135deg, rgba(255, 107, 107, 0.08) 0%, rgba(255, 142, 83, 0.08) 100%);
            padding: 3rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            border-right: 1px solid var(--border);
            position: relative;
            overflow: hidden;
        }
        
        .brand-panel::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle, rgba(255, 107, 107, 0.15) 0%, transparent 60%);
            animation: brandPulse 8s ease-in-out infinite;
        }
        
        @keyframes brandPulse {
            0%, 100% { transform: scale(1); opacity: 0.5; }
            50% { transform: scale(1.2); opacity: 0.8; }
        }
        
        .brand-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            position: relative;
            z-index: 1;
        }
        
        .brand-logo {
            width: 64px;
            height: 64px;
            background: var(--gradient-primary);
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: var(--glow-primary);
            transition: all 0.3s ease;
        }
        
        .brand-logo:hover {
            transform: scale(1.05);
            box-shadow: var(--glow-primary), var(--glow-secondary);
        }
        
        .brand-logo svg {
            width: 36px;
            height: 36px;
            fill: white;
        }
        
        .brand-name {
            font-size: 1.6rem;
            font-weight: 700;
            letter-spacing: -0.5px;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
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
            position: relative;
            z-index: 1;
        }
        
        .brand-title {
            font-size: 3rem;
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: 1.5rem;
            background: linear-gradient(135deg, #ffffff 0%, rgba(255, 255, 255, 0.7) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .brand-description {
            font-size: 1rem;
            color: var(--text-secondary);
            line-height: 1.8;
            margin-bottom: 2.5rem;
        }
        
        .features {
            display: grid;
            gap: 1rem;
        }
        
        .feature-item {
            display: flex;
            align-items: center;
            gap: 0.875rem;
            padding: 1rem;
            background: var(--glass-bg);
            border-radius: 16px;
            border: 1px solid var(--glass-border);
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }
        
        .feature-item:hover {
            background: rgba(255, 255, 255, 0.06);
            border-color: var(--border-glow);
            transform: translateX(8px);
            box-shadow: 0 4px 20px rgba(255, 107, 107, 0.15);
        }
        
        .feature-icon {
            width: 44px;
            height: 44px;
            background: var(--gradient-primary);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            box-shadow: var(--glow-primary);
        }
        
        .feature-icon svg {
            width: 20px;
            height: 20px;
            fill: white;
        }
        
        .feature-text {
            font-size: 0.9rem;
            font-weight: 500;
            color: var(--text-primary);
        }
        
        .brand-footer {
            font-size: 0.75rem;
            color: var(--text-muted);
            line-height: 1.6;
            position: relative;
            z-index: 1;
        }
        
        /* Right panel - Form */
        .form-panel {
            padding: 3.5rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
            background: rgba(15, 15, 22, 0.4);
            position: relative;
        }
        
        .form-panel::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at 30% 50%, rgba(255, 107, 107, 0.05) 0%, transparent 50%);
            pointer-events: none;
        }
        
        .form-header {
            margin-bottom: 2.5rem;
            position: relative;
            z-index: 1;
        }
        
        .form-title {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
        }
        
        .form-subtitle {
            font-size: 0.95rem;
            color: var(--text-secondary);
        }
        
        .form-group {
            margin-bottom: 1.5rem;
            position: relative;
            z-index: 1;
        }
        
        .form-label {
            display: block;
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.625rem;
        }
        
        .input-wrapper {
            position: relative;
        }
        
        .input-icon {
            position: absolute;
            left: 1.25rem;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            fill: var(--text-muted);
            transition: all 0.3s ease;
            z-index: 2;
        }
        
        .form-input {
            width: 100%;
            padding: 1rem 1.25rem 1rem 3.25rem;
            background: var(--bg-input);
            border: 1px solid var(--border);
            border-radius: 16px;
            font-size: 1rem;
            font-family: inherit;
            color: var(--text-primary);
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
        }
        
        .form-input::placeholder {
            color: var(--text-muted);
        }
        
        .form-input:focus {
            outline: none;
            border-color: var(--primary-gradient-start);
            box-shadow: 0 0 0 3px rgba(255, 107, 107, 0.15), 0 0 20px rgba(255, 107, 107, 0.1);
            background: rgba(30, 30, 45, 0.7);
        }
        
        .form-input:focus + .input-icon {
            fill: var(--primary-gradient-start);
        }
        
        .password-toggle {
            position: absolute;
            right: 1.25rem;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            width: 20px;
            height: 20px;
            fill: var(--text-muted);
            transition: all 0.2s ease;
            z-index: 2;
        }
        
        .password-toggle:hover {
            fill: var(--text-primary);
        }
        
        .btn-submit {
            width: 100%;
            padding: 1.125rem;
            background: var(--gradient-primary);
            border: none;
            border-radius: 16px;
            font-size: 1rem;
            font-weight: 700;
            font-family: inherit;
            color: white;
            cursor: pointer;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            overflow: hidden;
            margin-top: 0.5rem;
            box-shadow: 0 4px 20px rgba(255, 107, 107, 0.3);
        }
        
        .btn-submit::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.6s ease;
        }
        
        .btn-submit:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 8px 30px rgba(255, 107, 107, 0.5), 0 0 40px rgba(255, 142, 83, 0.3);
        }
        
        .btn-submit:hover::before {
            left: 100%;
        }
        
        .btn-submit:active {
            transform: translateY(-1px) scale(1);
        }
        
        /* Alert messages */
        .alert {
            padding: 1rem 1.25rem;
            border-radius: 16px;
            font-size: 0.9rem;
            margin-bottom: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            animation: slideIn 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            position: relative;
            z-index: 1;
        }
        
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-20px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        
        .alert-error {
            background: rgba(255, 69, 58, 0.15);
            border: 1px solid rgba(255, 69, 58, 0.4);
            color: #ff8a80;
            backdrop-filter: blur(10px);
        }
        
        .alert-info {
            background: rgba(255, 107, 107, 0.15);
            border: 1px solid rgba(255, 107, 107, 0.4);
            color: #ffc2b8;
            backdrop-filter: blur(10px);
        }
        
        .alert svg {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }
        
        /* Quick access section */
        .quick-access {
            margin-top: 2.5rem;
            padding-top: 2rem;
            border-top: 1px solid var(--border);
            position: relative;
            z-index: 1;
        }
        
        .quick-access-title {
            font-size: 0.75rem;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 1.25rem;
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
            gap: 0.75rem;
        }
        
        .cred-btn {
            padding: 0.875rem 1rem;
            background: var(--glass-bg);
            border: 1px solid var(--border);
            border-radius: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: left;
            backdrop-filter: blur(10px);
        }
        
        .cred-btn:hover {
            background: rgba(255, 255, 255, 0.08);
            border-color: var(--border-glow);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(255, 107, 107, 0.15);
        }
        
        .cred-user {
            font-size: 0.85rem;
            font-weight: 700;
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
            background: rgba(10, 10, 15, 0.95);
            backdrop-filter: blur(10px);
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 9999;
        }
        
        .loader {
            width: 56px;
            height: 56px;
            border: 3px solid var(--border);
            border-top-color: var(--primary-gradient-start);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            box-shadow: 0 0 20px rgba(255, 107, 107, 0.3);
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Responsive */
        @media (max-width: 1024px) {
            .container {
                grid-template-columns: 1fr;
                max-width: 520px;
                min-height: auto;
            }
            
            .brand-panel {
                display: none;
            }
            
            .form-panel {
                padding: 2.5rem;
            }
        }
        
        @media (max-width: 480px) {
            .container {
                width: 95%;
                border-radius: 24px;
            }
            
            .form-panel {
                padding: 2rem 1.5rem;
            }
            
            .form-title {
                font-size: 1.75rem;
            }
            
            .credentials-grid {
                grid-template-columns: 1fr;
            }
        }
        
        /* Floating animation for logo */
        @keyframes floatLogo {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-8px); }
        }
        
        .brand-logo {
            animation: floatLogo 4s ease-in-out infinite;
        }
    </style>
</head>
<body>
    <!-- Частицы на фоне -->
    <div class="particles-container">
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
        <div class="particle"></div>
    </div>
    
    <!-- Glow overlay -->
    <div class="glow-overlay"></div>
    
    <!-- Grid overlay -->
    <div class="grid-overlay"></div>
    
    <!-- Loading overlay -->
    <div class="loading-overlay" id="loadingOverlay">
        <div class="loader"></div>
    </div>
    
    <div class="container">
        <!-- Левая панель - Бренд -->
        <div class="brand-panel">
            <div class="brand-header">
                <div class="brand-logo">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
                    </svg>
                </div>
                <div>
                    <div class="brand-name">PolesieMES</div>
                    <div class="brand-tagline">Система управления производством</div>
                </div>
            </div>
            
            <div class="brand-content">
                <h1 class="brand-title">Добро пожаловать в будущее производства</h1>
                <p class="brand-description">
                    Современная MES-система для автоматизации и оптимизации производственных процессов. 
                    Контролируйте каждый этап производства в реальном времени.
                </p>
                
                <div class="features">
                    <div class="feature-item">
                        <div class="feature-icon">
                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path d="M22 12h-4l-3 9L9 3l-3 9H2"/>
                            </svg>
                        </div>
                        <span class="feature-text">Мониторинг в реальном времени</span>
                    </div>
                    
                    <div class="feature-item">
                        <div class="feature-icon">
                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
                            </svg>
                        </div>
                        <span class="feature-text">Управление заказами</span>
                    </div>
                    
                    <div class="feature-item">
                        <div class="feature-icon">
                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <circle cx="12" cy="12" r="3"/>
                                <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                            </svg>
                        </div>
                        <span class="feature-text">Контроль качества</span>
                    </div>
                    
                    <div class="feature-item">
                        <div class="feature-icon">
                            <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                                <circle cx="9" cy="7" r="4"/>
                                <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                                <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                            </svg>
                        </div>
                        <span class="feature-text">Управление командой</span>
                    </div>
                </div>
            </div>
            
            <div class="brand-footer">
                © 2026 ОАО «Полесьеэлектромаш»<br>
                Все права защищены
            </div>
        </div>
        
        <!-- Правая панель - Форма входа -->
        <div class="form-panel">
            <div class="form-header">
                <h2 class="form-title">Вход в систему</h2>
                <p class="form-subtitle">Введите свои данные для доступа к панели управления</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    <?= e($error) ?>
                </div>
            <?php endif; ?>
            
            <div class="alert alert-info" style="display: <?= $error ? 'none' : 'flex' ?>;">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="12" y1="16" x2="12" y2="12"/>
                    <line x1="12" y1="8" x2="12.01" y2="8"/>
                </svg>
                Используйте тестовые учетные данные ниже для быстрого входа
            </div>
            
            <form method="POST" action="" id="loginForm">
                <div class="form-group">
                    <label class="form-label" for="username">Логин</label>
                    <div class="input-wrapper">
                        <input 
                            type="text" 
                            class="form-input" 
                            id="username" 
                            name="username" 
                            placeholder="Введите ваш логин"
                            value="<?= e($username) ?>"
                            required
                            autocomplete="username"
                        >
                        <svg class="input-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                            <circle cx="12" cy="7" r="4"/>
                        </svg>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label" for="password">Пароль</label>
                    <div class="input-wrapper">
                        <input 
                            type="password" 
                            class="form-input" 
                            id="password" 
                            name="password" 
                            placeholder="Введите ваш пароль"
                            required
                            autocomplete="current-password"
                        >
                        <svg class="input-icon" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                        <svg class="password-toggle" id="passwordToggle" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </div>
                </div>
                
                <button type="submit" class="btn-submit">
                    Войти в систему
                    <svg style="width: 18px; height: 18px; vertical-align: middle; margin-left: 8px; fill: currentColor;" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M5 12h14M12 5l7 7-7 7"/>
                    </svg>
                </button>
            </form>
            
            <div class="quick-access">
                <div class="quick-access-title">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="12" cy="12" r="3"/>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                    </svg>
                    Быстрый вход
                </div>
                
                <div class="credentials-grid">
                    <button class="cred-btn" onclick="fillCredentials('admin', 'admin123')">
                        <div class="cred-user">admin</div>
                        <div class="cred-role">Администратор</div>
                    </button>
                    
                    <button class="cred-btn" onclick="fillCredentials('manager', 'manager123')">
                        <div class="cred-user">manager</div>
                        <div class="cred-role">Менеджер</div>
                    </button>
                    
                    <button class="cred-btn" onclick="fillCredentials('master', 'master123')">
                        <div class="cred-user">master</div>
                        <div class="cred-role">Мастер цеха</div>
                    </button>
                    
                    <button class="cred-btn" onclick="fillCredentials('worker', 'worker123')">
                        <div class="cred-user">worker</div>
                        <div class="cred-role">Рабочий</div>
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Toggle password visibility
        const passwordToggle = document.getElementById('passwordToggle');
        const passwordInput = document.getElementById('password');
        
        passwordToggle.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            // Change icon
            if (type === 'text') {
                this.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
            } else {
                this.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
            }
        });
        
        // Fill credentials on quick access click
        function fillCredentials(username, password) {
            document.getElementById('username').value = username;
            document.getElementById('password').value = password;
            
            // Add visual feedback
            const btn = event.currentTarget;
            btn.style.borderColor = '#FF6B6B';
            btn.style.boxShadow = '0 4px 20px rgba(255, 107, 107, 0.3)';
            
            setTimeout(() => {
                btn.style.borderColor = '';
                btn.style.boxShadow = '';
            }, 500);
        }
        
        // Form submission with loading state
        const loginForm = document.getElementById('loginForm');
        const loadingOverlay = document.getElementById('loadingOverlay');
        
        loginForm.addEventListener('submit', function(e) {
            // Show loading overlay
            loadingOverlay.style.display = 'flex';
            
            // The form will submit normally
        });
        
        // Add input focus effects
        const inputs = document.querySelectorAll('.form-input');
        inputs.forEach(input => {
            input.addEventListener('focus', function() {
                this.parentElement.parentElement.style.transform = 'scale(1.02)';
            });
            
            input.addEventListener('blur', function() {
                this.parentElement.parentElement.style.transform = 'scale(1)';
            });
        });
        
        // Parallax effect on mouse move
        document.addEventListener('mousemove', (e) => {
            const particles = document.querySelectorAll('.particle');
            const x = e.clientX / window.innerWidth;
            const y = e.clientY / window.innerHeight;
            
            particles.forEach((particle, index) => {
                const speed = (index + 1) * 20;
                particle.style.transform = `translate(${x * speed}px, ${y * speed}px)`;
            });
        });
    </script>
</body>
</html>
            
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
