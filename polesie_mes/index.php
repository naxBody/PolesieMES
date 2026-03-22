<?php
/**
 * Главная страница (Landing Page) системы PolesieMES
 * Современный дизайн 2026 - Glassmorphism с оранжево-коралловым градиентом
 * ОАО "Полесьеэлектромаш"
 */

// Подключение конфигурации
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth_functions.php';
require_once __DIR__ . '/includes/helpers.php';

// Если пользователь уже авторизован - перенаправляем на панель управления
if (isLoggedIn()) {
    header('Location: ' . APP_URL . '/modules/dashboard/index.php');
    exit;
}

$pageTitle = 'PolesieMES - Система управления производством | ОАО Полесьеэлектромаш';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Современная MES-система для автоматизации производственных процессов ОАО Полесьеэлектромаш">
    <title><?= e($pageTitle) ?></title>
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    
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
        
        html {
            scroll-behavior: smooth;
        }
        
        body {
            font-family: 'Outfit', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--gradient-bg);
            min-height: 100vh;
            color: var(--text-primary);
            overflow-x: hidden;
            position: relative;
            line-height: 1.6;
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
            pointer-events: none;
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
        .particle:nth-child(6) { width: 50px; height: 50px; top: 50%; left: 50%; animation-delay: 10s; }
        
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
        
        /* Navbar */
        .navbar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            z-index: 1000;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(10, 10, 15, 0.8);
            backdrop-filter: var(--backdrop-blur);
            -webkit-backdrop-filter: var(--backdrop-blur);
            border-bottom: 1px solid var(--border);
            transition: all 0.3s ease;
        }
        
        .navbar.scrolled {
            padding: 0.75rem 2rem;
            background: rgba(10, 10, 15, 0.95);
            box-shadow: var(--shadow-md);
        }
        
        .nav-brand {
            display: flex;
            align-items: center;
            gap: 1rem;
            text-decoration: none;
            color: var(--text-primary);
        }
        
        .brand-logo {
            width: 48px;
            height: 48px;
            background: var(--gradient-primary);
            border-radius: 14px;
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
            width: 28px;
            height: 28px;
            fill: white;
        }
        
        .brand-name {
            font-size: 1.4rem;
            font-weight: 700;
            letter-spacing: -0.5px;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .nav-menu {
            display: flex;
            align-items: center;
            gap: 2rem;
            list-style: none;
        }
        
        .nav-link {
            color: var(--text-secondary);
            text-decoration: none;
            font-weight: 500;
            font-size: 0.95rem;
            transition: all 0.3s ease;
            position: relative;
            padding: 0.5rem 0;
        }
        
        .nav-link::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--gradient-primary);
            transition: width 0.3s ease;
        }
        
        .nav-link:hover {
            color: var(--text-primary);
        }
        
        .nav-link:hover::after {
            width: 100%;
        }
        
        .btn-nav {
            padding: 0.75rem 1.5rem;
            background: var(--gradient-primary);
            border: none;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 600;
            font-family: inherit;
            color: white;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
            box-shadow: 0 4px 20px rgba(255, 107, 107, 0.3);
        }
        
        .btn-nav:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(255, 107, 107, 0.5), 0 0 40px rgba(255, 142, 83, 0.3);
        }
        
        .mobile-menu-btn {
            display: none;
            background: none;
            border: none;
            cursor: pointer;
            padding: 0.5rem;
        }
        
        .mobile-menu-btn span {
            display: block;
            width: 24px;
            height: 2px;
            background: var(--text-primary);
            margin: 5px 0;
            transition: all 0.3s ease;
            border-radius: 2px;
        }
        
        /* Hero Section */
        .hero {
            position: relative;
            z-index: 10;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 8rem 2rem 4rem;
        }
        
        .hero-content {
            max-width: 1200px;
            width: 100%;
            text-align: center;
        }
        
        .hero-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1.25rem;
            background: var(--glass-bg);
            border: 1px solid var(--border-glow);
            border-radius: 50px;
            font-size: 0.875rem;
            color: var(--text-secondary);
            margin-bottom: 2rem;
            backdrop-filter: blur(10px);
            animation: fadeInDown 0.8s ease forwards;
            opacity: 0;
        }
        
        .hero-badge .dot {
            width: 8px;
            height: 8px;
            background: var(--gradient-primary);
            border-radius: 50%;
            animation: pulse 2s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50% { opacity: 0.5; transform: scale(1.2); }
        }
        
        .hero-title {
            font-size: clamp(2.5rem, 6vw, 5rem);
            font-weight: 800;
            line-height: 1.1;
            margin-bottom: 1.5rem;
            background: linear-gradient(135deg, #ffffff 0%, rgba(255, 255, 255, 0.7) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            animation: fadeInUp 0.8s ease 0.2s forwards;
            opacity: 0;
        }
        
        .hero-subtitle {
            font-size: clamp(1rem, 2vw, 1.25rem);
            color: var(--text-secondary);
            max-width: 700px;
            margin: 0 auto 3rem;
            line-height: 1.8;
            animation: fadeInUp 0.8s ease 0.4s forwards;
            opacity: 0;
        }
        
        .hero-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
            animation: fadeInUp 0.8s ease 0.6s forwards;
            opacity: 0;
        }
        
        .btn-hero-primary {
            padding: 1.125rem 2.5rem;
            background: var(--gradient-primary);
            border: none;
            border-radius: 16px;
            font-size: 1rem;
            font-weight: 700;
            font-family: inherit;
            color: white;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            box-shadow: 0 4px 20px rgba(255, 107, 107, 0.3);
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .btn-hero-primary:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 8px 30px rgba(255, 107, 107, 0.5), 0 0 40px rgba(255, 142, 83, 0.3);
        }
        
        .btn-hero-primary svg {
            width: 20px;
            height: 20px;
            fill: white;
            transition: transform 0.3s ease;
        }
        
        .btn-hero-primary:hover svg {
            transform: translateX(5px);
        }
        
        .btn-hero-secondary {
            padding: 1.125rem 2.5rem;
            background: var(--glass-bg);
            border: 1px solid var(--border);
            border-radius: 16px;
            font-size: 1rem;
            font-weight: 600;
            font-family: inherit;
            color: var(--text-primary);
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s ease;
            backdrop-filter: blur(10px);
            display: inline-flex;
            align-items: center;
            gap: 0.75rem;
        }
        
        .btn-hero-secondary:hover {
            background: rgba(255, 255, 255, 0.08);
            border-color: var(--border-glow);
            transform: translateY(-2px);
        }
        
        .btn-hero-secondary svg {
            width: 20px;
            height: 20px;
            fill: var(--text-secondary);
        }
        
        /* Stats Bar */
        .stats-bar {
            position: relative;
            z-index: 10;
            max-width: 1000px;
            margin: -4rem auto 4rem;
            padding: 2rem;
            background: var(--bg-card);
            backdrop-filter: var(--backdrop-blur);
            -webkit-backdrop-filter: var(--backdrop-blur);
            border-radius: 24px;
            border: 1px solid var(--border);
            box-shadow: var(--shadow-lg);
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 2rem;
        }
        
        .stat-item {
            text-align: center;
            padding: 1rem;
        }
        
        .stat-value {
            font-size: 2.5rem;
            font-weight: 800;
            background: var(--gradient-primary);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 0.25rem;
        }
        
        .stat-label {
            font-size: 0.875rem;
            color: var(--text-secondary);
            font-weight: 500;
        }
        
        /* Features Section */
        .features {
            position: relative;
            z-index: 10;
            padding: 6rem 2rem;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .section-header {
            text-align: center;
            margin-bottom: 4rem;
        }
        
        .section-tag {
            display: inline-block;
            padding: 0.5rem 1.25rem;
            background: var(--glass-bg);
            border: 1px solid var(--border-glow);
            border-radius: 50px;
            font-size: 0.875rem;
            color: var(--primary-gradient-start);
            font-weight: 600;
            margin-bottom: 1rem;
            backdrop-filter: blur(10px);
        }
        
        .section-title {
            font-size: clamp(2rem, 4vw, 3rem);
            font-weight: 800;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, #ffffff 0%, rgba(255, 255, 255, 0.7) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .section-subtitle {
            font-size: 1.125rem;
            color: var(--text-secondary);
            max-width: 600px;
            margin: 0 auto;
            line-height: 1.8;
        }
        
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 2rem;
        }
        
        .feature-card {
            background: var(--bg-card);
            backdrop-filter: var(--backdrop-blur);
            -webkit-backdrop-filter: var(--backdrop-blur);
            border-radius: 24px;
            border: 1px solid var(--border);
            padding: 2.5rem;
            transition: all 0.4s ease;
            position: relative;
            overflow: hidden;
        }
        
        .feature-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--gradient-primary);
            transform: scaleX(0);
            transition: transform 0.4s ease;
        }
        
        .feature-card:hover {
            transform: translateY(-10px);
            border-color: var(--border-glow);
            box-shadow: 0 20px 60px rgba(255, 107, 107, 0.15);
        }
        
        .feature-card:hover::before {
            transform: scaleX(1);
        }
        
        .feature-icon {
            width: 64px;
            height: 64px;
            background: var(--gradient-primary);
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.5rem;
            box-shadow: var(--glow-primary);
        }
        
        .feature-icon svg {
            width: 32px;
            height: 32px;
            fill: white;
        }
        
        .feature-title {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            color: var(--text-primary);
        }
        
        .feature-description {
            color: var(--text-secondary);
            line-height: 1.8;
            font-size: 0.95rem;
        }
        
        /* How It Works Section */
        .how-it-works {
            position: relative;
            z-index: 10;
            padding: 6rem 2rem;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .steps-container {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 3rem;
            margin-top: 3rem;
        }
        
        .step-card {
            text-align: center;
            padding: 2rem;
            position: relative;
        }
        
        .step-number {
            width: 80px;
            height: 80px;
            background: var(--gradient-primary);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: 800;
            color: white;
            margin: 0 auto 1.5rem;
            box-shadow: var(--glow-primary);
            position: relative;
            z-index: 1;
        }
        
        .step-card:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 60px;
            right: -50%;
            width: 100%;
            height: 2px;
            background: linear-gradient(90deg, var(--border-glow), transparent);
            display: none;
        }
        
        @media (min-width: 900px) {
            .step-card:not(:last-child)::after {
                display: block;
            }
        }
        
        .step-title {
            font-size: 1.25rem;
            font-weight: 700;
            margin-bottom: 0.75rem;
            color: var(--text-primary);
        }
        
        .step-description {
            color: var(--text-secondary);
            font-size: 0.95rem;
            line-height: 1.7;
        }
        
        /* CTA Section */
        .cta-section {
            position: relative;
            z-index: 10;
            padding: 6rem 2rem;
            max-width: 1000px;
            margin: 0 auto;
            text-align: center;
        }
        
        .cta-card {
            background: var(--bg-card);
            backdrop-filter: var(--backdrop-blur);
            -webkit-backdrop-filter: var(--backdrop-blur);
            border-radius: 32px;
            border: 1px solid var(--border);
            padding: 4rem 2rem;
            position: relative;
            overflow: hidden;
        }
        
        .cta-card::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 107, 107, 0.1) 0%, transparent 50%);
            animation: rotateBg 20s linear infinite;
        }
        
        @keyframes rotateBg {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .cta-content {
            position: relative;
            z-index: 1;
        }
        
        .cta-title {
            font-size: clamp(2rem, 4vw, 3rem);
            font-weight: 800;
            margin-bottom: 1rem;
            background: linear-gradient(135deg, #ffffff 0%, rgba(255, 255, 255, 0.7) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .cta-subtitle {
            font-size: 1.125rem;
            color: var(--text-secondary);
            margin-bottom: 2.5rem;
            max-width: 600px;
            margin-left: auto;
            margin-right: auto;
        }
        
        /* Footer */
        .footer {
            position: relative;
            z-index: 10;
            border-top: 1px solid var(--border);
            padding: 3rem 2rem;
            background: rgba(10, 10, 15, 0.8);
            backdrop-filter: var(--backdrop-blur);
        }
        
        .footer-content {
            max-width: 1400px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 3rem;
        }
        
        .footer-brand {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        
        .footer-description {
            color: var(--text-secondary);
            font-size: 0.95rem;
            line-height: 1.8;
        }
        
        .footer-links h4 {
            font-size: 1rem;
            font-weight: 700;
            margin-bottom: 1.25rem;
            color: var(--text-primary);
        }
        
        .footer-links ul {
            list-style: none;
        }
        
        .footer-links li {
            margin-bottom: 0.75rem;
        }
        
        .footer-links a {
            color: var(--text-secondary);
            text-decoration: none;
            font-size: 0.95rem;
            transition: all 0.3s ease;
        }
        
        .footer-links a:hover {
            color: var(--text-primary);
            padding-left: 5px;
        }
        
        .footer-bottom {
            max-width: 1400px;
            margin: 3rem auto 0;
            padding-top: 2rem;
            border-top: 1px solid var(--border);
            text-align: center;
            color: var(--text-muted);
            font-size: 0.875rem;
        }
        
        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Loading overlay */
        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--bg-dark);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            transition: opacity 0.5s ease, visibility 0.5s ease;
        }
        
        .loading-overlay.hidden {
            opacity: 0;
            visibility: hidden;
        }
        
        .loader {
            width: 60px;
            height: 60px;
            border: 3px solid var(--border);
            border-top-color: var(--primary-gradient-start);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Responsive */
        @media (max-width: 900px) {
            .stats-bar {
                grid-template-columns: repeat(2, 1fr);
                gap: 1.5rem;
            }
            
            .nav-menu {
                display: none;
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                background: rgba(10, 10, 15, 0.98);
                flex-direction: column;
                padding: 2rem;
                gap: 1.5rem;
                border-bottom: 1px solid var(--border);
            }
            
            .nav-menu.active {
                display: flex;
            }
            
            .mobile-menu-btn {
                display: block;
            }
        }
        
        @media (max-width: 600px) {
            .navbar {
                padding: 1rem;
            }
            
            .hero {
                padding: 6rem 1rem 3rem;
            }
            
            .stats-bar {
                grid-template-columns: 1fr;
                margin: -2rem 1rem 2rem;
            }
            
            .hero-buttons {
                flex-direction: column;
                align-items: center;
            }
            
            .btn-hero-primary,
            .btn-hero-secondary {
                width: 100%;
                max-width: 300px;
                justify-content: center;
            }
            
            .features,
            .how-it-works,
            .cta-section {
                padding: 4rem 1rem;
            }
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

    <!-- Navigation -->
    <nav class="navbar" id="navbar">
        <a href="/" class="nav-brand">
            <div class="brand-logo">
                <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
                </svg>
            </div>
            <span class="brand-name">PolesieMES</span>
        </a>
        
        <button class="mobile-menu-btn" id="mobileMenuBtn">
            <span></span>
            <span></span>
            <span></span>
        </button>
        
        <ul class="nav-menu" id="navMenu">
            <li><a href="#features" class="nav-link">Возможности</a></li>
            <li><a href="#how-it-works" class="nav-link">Как это работает</a></li>
            <li><a href="#about" class="nav-link">О системе</a></li>
            <li><a href="<?= APP_URL ?>/modules/auth/login.php" class="btn-nav">Войти в систему</a></li>
        </ul>
    </nav>

    <!-- Hero Section -->
    <section class="hero">
        <div class="hero-content">
            <div class="hero-badge">
                <span class="dot"></span>
                <span>Современная MES-система 2026</span>
            </div>
            
            <h1 class="hero-title">
                Будущее производства<br>начинается здесь
            </h1>
            
            <p class="hero-subtitle">
                Интеллектуальная система управления производственными процессами 
                для ОАО «Полесьеэлектромаш». Контролируйте каждый этап, оптимизируйте 
                ресурсы и повышайте эффективность в реальном времени.
            </p>
            
            <div class="hero-buttons">
                <a href="<?= APP_URL ?>/modules/auth/login.php" class="btn-hero-primary">
                    Начать работу
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M5 12h14M12 5l7 7-7 7"/>
                    </svg>
                </a>
                <a href="#features" class="btn-hero-secondary">
                    Узнать больше
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="12" cy="12" r="10"/>
                        <path d="M12 16v-4M12 8h.01"/>
                    </svg>
                </a>
            </div>
        </div>
    </section>

    <!-- Stats Bar -->
    <div class="stats-bar">
        <div class="stat-item">
            <div class="stat-value">100%</div>
            <div class="stat-label">Контроль процессов</div>
        </div>
        <div class="stat-item">
            <div class="stat-value">24/7</div>
            <div class="stat-label">Мониторинг</div>
        </div>
        <div class="stat-item">
            <div class="stat-value">30%</div>
            <div class="stat-label">Рост эффективности</div>
        </div>
        <div class="stat-item">
            <div class="stat-value">0</div>
            <div class="stat-label">Простоев</div>
        </div>
    </div>

    <!-- Features Section -->
    <section class="features" id="features">
        <div class="section-header">
            <span class="section-tag">Возможности</span>
            <h2 class="section-title">Всё для управления производством</h2>
            <p class="section-subtitle">
                Полный набор инструментов для автоматизации и оптимизации 
                всех аспектов производственного процесса
            </p>
        </div>
        
        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M22 12h-4l-3 9L9 3l-3 9H2"/>
                    </svg>
                </div>
                <h3 class="feature-title">Мониторинг в реальном времени</h3>
                <p class="feature-description">
                    Отслеживайте состояние оборудования, прогресс заказов и производительность 
                    сотрудников в режиме реального времени с обновлением данных каждую секунду.
                </p>
            </div>
            
            <div class="feature-card">
                <div class="feature-icon">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <rect x="3" y="3" width="18" height="18" rx="2"/>
                        <path d="M3 9h18M9 21V9"/>
                    </svg>
                </div>
                <h3 class="feature-title">Управление заказами</h3>
                <p class="feature-description">
                    Полный цикл управления заказами: от создания до отгрузки. 
                    Статусы, сроки, приоритеты и автоматические уведомления.
                </p>
            </div>
            
            <div class="feature-card">
                <div class="feature-icon">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="12" cy="12" r="3"/>
                        <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                    </svg>
                </div>
                <h3 class="feature-title">Контроль качества</h3>
                <p class="feature-description">
                    Встроенная система контроля качества на каждом этапе производства. 
                    Фиксация дефектов, статистика и предотвращение брака.
                </p>
            </div>
            
            <div class="feature-card">
                <div class="feature-icon">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
                        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
                    </svg>
                </div>
                <h3 class="feature-title">Управление командой</h3>
                <p class="feature-description">
                    Распределение задач между сотрудниками, учет рабочего времени, 
                    оценка производительности и планирование смен.
                </p>
            </div>
            
            <div class="feature-card">
                <div class="feature-icon">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
                        <path d="M3 6h18M16 10a4 4 0 0 1-8 0"/>
                    </svg>
                </div>
                <h3 class="feature-title">Склад и материалы</h3>
                <p class="feature-description">
                    Учет материалов, контроль запасов, автоматическое пополнение 
                    и отслеживание расхода в реальном времени.
                </p>
            </div>
            
            <div class="feature-card">
                <div class="feature-icon">
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/>
                        <polyline points="3.27 6.96 12 12.01 20.73 6.96"/>
                        <line x1="12" y1="22.08" x2="12" y2="12"/>
                    </svg>
                </div>
                <h3 class="feature-title">Аналитика и отчеты</h3>
                <p class="feature-description">
                    Детальная аналитика производства, настраиваемые дашборды, 
                    экспорт отчетов и прогнозирование на основе ИИ.
                </p>
            </div>
        </div>
    </section>

    <!-- How It Works Section -->
    <section class="how-it-works" id="how-it-works">
        <div class="section-header">
            <span class="section-tag">Процесс</span>
            <h2 class="section-title">Как это работает</h2>
            <p class="section-subtitle">
                Четыре простых шага для начала работы с системой
            </p>
        </div>
        
        <div class="steps-container">
            <div class="step-card">
                <div class="step-number">1</div>
                <h3 class="step-title">Авторизация</h3>
                <p class="step-description">
                    Войдите в систему под своей учетной записью. 
                    Каждый сотрудник получает доступ согласно своей роли.
                </p>
            </div>
            
            <div class="step-card">
                <div class="step-number">2</div>
                <h3 class="step-title">Обзор данных</h3>
                <p class="step-description">
                    Получите полную картину производства на главном дашборде. 
                    Все ключевые показатели в реальном времени.
                </p>
            </div>
            
            <div class="step-card">
                <div class="step-number">3</div>
                <h3 class="step-title">Управление</h3>
                <p class="step-description">
                    Создавайте заказы, распределяйте задачи, контролируйте 
                    качество и управляйте ресурсами через интуитивный интерфейс.
                </p>
            </div>
            
            <div class="step-card">
                <div class="step-number">4</div>
                <h3 class="step-title">Анализ</h3>
                <p class="step-description">
                    Анализируйте эффективность, получайте отчеты и 
                    оптимизируйте процессы на основе данных.
                </p>
            </div>
        </div>
    </section>

    <!-- CTA Section -->
    <section class="cta-section" id="about">
        <div class="cta-card">
            <div class="cta-content">
                <h2 class="cta-title">Готовы начать?</h2>
                <p class="cta-subtitle">
                    Присоединяйтесь к современной системе управления производством 
                    и выведите эффективность вашего предприятия на новый уровень
                </p>
                <a href="<?= APP_URL ?>/modules/auth/login.php" class="btn-hero-primary">
                    Войти в систему
                    <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path d="M5 12h14M12 5l7 7-7 7"/>
                    </svg>
                </a>
            </div>
        </div>
    </section>

    <!-- Footer -->
    <footer class="footer">
        <div class="footer-content">
            <div>
                <div class="footer-brand">
                    <div class="brand-logo" style="width: 40px; height: 40px;">
                        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" style="width: 24px; height: 24px;">
                            <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
                        </svg>
                    </div>
                    <span class="brand-name" style="font-size: 1.2rem;">PolesieMES</span>
                </div>
                <p class="footer-description">
                    Современная MES-система для автоматизации 
                    производственных процессов ОАО «Полесьеэлектромаш»
                </p>
            </div>
            
            <div class="footer-links">
                <h4>Система</h4>
                <ul>
                    <li><a href="#features">Возможности</a></li>
                    <li><a href="#how-it-works">Как это работает</a></li>
                    <li><a href="<?= APP_URL ?>/modules/auth/login.php">Войти</a></li>
                </ul>
            </div>
            
            <div class="footer-links">
                <h4>Модули</h4>
                <ul>
                    <li><a href="#">Заказы</a></li>
                    <li><a href="#">Производство</a></li>
                    <li><a href="#">Склад</a></li>
                    <li><a href="#">Отчеты</a></li>
                </ul>
            </div>
            
            <div class="footer-links">
                <h4>Контакты</h4>
                <ul>
                    <li><a href="#">Техническая поддержка</a></li>
                    <li><a href="#">Документация</a></li>
                    <li><a href="#">Обучение</a></li>
                </ul>
            </div>
        </div>
        
        <div class="footer-bottom">
            <p>© 2026 ОАО «Полесьеэлектромаш». Все права защищены. PolesieMES v1.0</p>
        </div>
    </footer>

    <script>
        // Loading overlay
        window.addEventListener('load', () => {
            setTimeout(() => {
                document.getElementById('loadingOverlay').classList.add('hidden');
            }, 500);
        });

        // Navbar scroll effect
        window.addEventListener('scroll', () => {
            const navbar = document.getElementById('navbar');
            if (window.scrollY > 50) {
                navbar.classList.add('scrolled');
            } else {
                navbar.classList.remove('scrolled');
            }
        });

        // Mobile menu toggle
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const navMenu = document.getElementById('navMenu');

        mobileMenuBtn.addEventListener('click', () => {
            navMenu.classList.toggle('active');
        });

        // Close mobile menu on link click
        document.querySelectorAll('.nav-link').forEach(link => {
            link.addEventListener('click', () => {
                navMenu.classList.remove('active');
            });
        });

        // Smooth scroll for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function(e) {
                const href = this.getAttribute('href');
                if (href !== '#') {
                    e.preventDefault();
                    const target = document.querySelector(href);
                    if (target) {
                        target.scrollIntoView({
                            behavior: 'smooth',
                            block: 'start'
                        });
                    }
                }
            });
        });

        // Intersection Observer for animations
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -50px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.style.opacity = '1';
                    entry.target.style.transform = 'translateY(0)';
                }
            });
        }, observerOptions);

        // Observe feature cards and step cards
        document.querySelectorAll('.feature-card, .step-card').forEach(card => {
            card.style.opacity = '0';
            card.style.transform = 'translateY(30px)';
            card.style.transition = 'all 0.6s ease';
            observer.observe(card);
        });
    </script>
</body>
</html>
