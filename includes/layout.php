<?php
// includes/layout.php - Versão atualizada para sua estrutura

// Incluir configurações do banco de dados primeiro
if (!defined('SITE_URL')) {
    // Caminho para o arquivo de configuração na sua estrutura
    $config_path = dirname(__DIR__) . '/config/database.php';
    
    if (file_exists($config_path)) {
        require_once $config_path;
    }
    
    // Se SITE_URL ainda não estiver definida, definir agora
    if (!defined('SITE_URL')) {
        define('SITE_URL', 'https://gestao.alencarmartinazzo.adv.br');
    }
}

// Verificar autenticação
if (!isset($usuario_logado)) {
    require_once __DIR__ . '/auth.php';
    
    // Verificar se existe a classe Auth
    if (class_exists('Auth')) {
        Auth::protect();
        $usuario_logado = Auth::user();
    } else {
        // Se não existe, redirecionar para login
        header("Location: " . SITE_URL . "/modules/auth/login.php");
        exit;
    }
    
    // Garantir que a variável global esteja definida
    $GLOBALS['usuario_logado'] = $usuario_logado;
}

// Linha após o $GLOBALS['usuario_logado'] = $usuario_logado;

// Incluir funções de busca (necessário para a busca global no layout)
if (!function_exists('normalizarParaBusca')) {
    $search_helpers_path = __DIR__ . '/search_helpers.php';
    if (file_exists($search_helpers_path)) {
        require_once $search_helpers_path;
    }
}

function renderLayout($titulo_pagina, $conteudo_principal, $pagina_ativa = '') {
    // Garantir que temos o usuário logado
    global $usuario_logado;
    if (!isset($usuario_logado) && isset($GLOBALS['usuario_logado'])) {
        $usuario_logado = $GLOBALS['usuario_logado'];
    }
    
    // Se ainda não temos usuário, algo está errado
    if (!isset($usuario_logado) || empty($usuario_logado)) {
        header("Location: " . SITE_URL . "/modules/auth/login.php");
        exit;
    }
    
    ob_start();
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($titulo_pagina) ?> - SIGAM</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }

            body {
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                background: linear-gradient(135deg, rgba(0, 0, 0, 0.95) 0%, rgba(40, 40, 40, 0.98) 100%);
                background-attachment: fixed;
                min-height: 100vh;
                overflow-x: hidden;
            }

            /* HEADER FIXO */
            .header {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                height: 70px;
                background: linear-gradient(135deg, #1a1a1a 0%, #333 100%);
                backdrop-filter: blur(10px);
                color: white;
                padding: 0 25px;
                display: flex;
                justify-content: space-between;
                align-items: center;
                box-shadow: 0 4px 20px rgba(0,0,0,0.3);
                border-bottom: 1px solid rgba(255, 255, 255, 0.1);
                z-index: 800;
            }

            .header-left {
                display: flex;
                align-items: center;
                gap: 20px;
            }

            .logo {
                display: flex;
                align-items: center;
                gap: 12px;
                cursor: pointer;
                text-decoration: none;
                color: white;
                transition: all 0.3s ease;
                padding: 8px 12px;
                border-radius: 8px;
            }

            .logo:hover {
                background: rgba(255,255,255,0.1);
                transform: scale(1.02);
            }

            .logo-icon {
                width: 40px;
                height: 40px;
                border-radius: 8px;
                display: flex;
                align-items: center;
                justify-content: center;
                box-shadow: 0 4px 12px rgba(0,123,255,0.3);
            }

            .logo-icon img {
                max-width: 100%;
                max-height: 100%;
                object-fit: contain;
                display: block;
            }

            .logo-text {
                display: flex;
                flex-direction: column;
            }

            .logo-title {
                font-size: 18px;
                font-weight: 700;
                letter-spacing: 0.5px;
                line-height: 1;
            }

            .logo-subtitle {
                font-size: 11px;
                opacity: 0.8;
                font-weight: 400;
                letter-spacing: 0.3px;
            }

            .user-info {
                display: flex;
                align-items: center;
                gap: 15px;
                padding: 8px 12px;
                background: rgba(255, 255, 255, 0.05);
                border: 1px solid rgba(255, 255, 255, 0.1);
                border-radius: 8px;
                transition: all 0.3s ease;
                position: relative;
            }
            
            .user-info:hover {
                background: rgba(255, 255, 255, 0.1);
                border-color: rgba(255, 255, 255, 0.2);
            }

            .user-avatar {
                width: 38px;
                height: 38px;
                border-radius: 8px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                display: flex;
                align-items: center;
                justify-content: center;
                color: white;
                font-weight: 700;
                font-size: 14px;
                flex-shrink: 0;
                box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
            }
            
            .user-details {
                display: flex;
                flex-direction: column;
                gap: 3px;
                white-space: nowrap;
            }
            
            .user-name {
                font-size: 14px;
                font-weight: 600;
                color: white;
                line-height: 1;
            }
            
            .user-role {
                font-size: 11px;
                font-weight: 500;
                color: rgba(255, 255, 255, 0.6);
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            
            .user-menu-wrapper {
                position: relative;
                display: flex;
                align-items: center;
            }
            
            .user-menu-toggle {
                background: transparent;
                border: none;
                color: rgba(255, 255, 255, 0.6);
                cursor: pointer;
                padding: 4px;
                display: flex;
                align-items: center;
                justify-content: center;
                transition: all 0.3s ease;
                border-radius: 4px;
            }
            
            .user-menu-toggle:hover {
                color: white;
                background: rgba(255, 255, 255, 0.1);
            }
            
            .user-menu-toggle svg {
                transition: transform 0.3s ease;
            }
            
            .user-menu-toggle.active svg {
                transform: rotate(180deg);
            }
            
            /* USER DROPDOWN - Tema escuro para combinar com o header */
            .user-dropdown {
                position: absolute;
                top: calc(100% + 12px);
                right: 0;
                background: linear-gradient(135deg, #2a2a2a 0%, #1a1a1a 100%);
                border: 1px solid rgba(255, 255, 255, 0.1);
                border-radius: 12px;
                box-shadow: 0 8px 32px rgba(0, 0, 0, 0.5);
                min-width: 220px;
                overflow: hidden;
                z-index: 1001;
                animation: slideDown 0.3s ease;
                display: none;
            }
            
            .user-dropdown.show {
                display: block;
            }
            
            .dropdown-item {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 12px 16px;
                color: rgba(255, 255, 255, 0.9);
                text-decoration: none;
                transition: all 0.2s ease;
                font-size: 14px;
                font-weight: 500;
                cursor: pointer;
            }
            
            .dropdown-item:hover {
                background: rgba(255, 255, 255, 0.1);
                color: white;
            }
            
            .dropdown-item i {
                width: 18px;
                font-size: 14px;
                color: rgba(255, 255, 255, 0.7);
                text-align: center;
            }
            
            .dropdown-item:hover i {
                color: #667eea;
            }
            
            .dropdown-item.logout {
                color: #ff6b6b;
                border-top: 1px solid rgba(255, 255, 255, 0.1);
            }
            
            .dropdown-item.logout:hover {
                background: rgba(255, 107, 107, 0.1);
                color: #ff8787;
            }
            
            .dropdown-item.logout i {
                color: #ff6b6b;
            }
            
            .dropdown-item.logout:hover i {
                color: #ff8787;
            }

            /* SIDEBAR FIXO */
            .sidebar {
                position: fixed;
                top: 70px;
                left: 0;
                width: 280px;
                height: calc(100vh - 70px);
                background: rgba(255, 255, 255, 0.95);
                backdrop-filter: blur(15px);
                box-shadow: 4px 0 20px rgba(0,0,0,0.15);
                padding: 25px;
                border-right: 1px solid rgba(0, 0, 0, 0.1);
                overflow-y: auto;
                z-index: 999;
            }

            .sidebar h3 {
                color: #1a1a1a;
                margin-bottom: 20px;
                font-size: 16px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 1px;
                padding-bottom: 10px;
                border-bottom: 2px solid rgba(26, 26, 26, 0.1);
            }

            .menu-item {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 12px 16px;
                color: #444;
                text-decoration: none;
                border-radius: 8px;
                margin-bottom: 4px;
                transition: all 0.3s ease;
                font-weight: 500;
                font-size: 14px;
            }

            .menu-item:hover {
                background: rgba(26, 26, 26, 0.05);
                color: #1a1a1a;
                transform: translateX(4px);
            }

            .menu-item.active {
                background: linear-gradient(135deg, #1a1a1a 0%, #333 100%);
                color: white;
                font-weight: 700;
                box-shadow: 0 4px 12px rgba(26, 26, 26, 0.2);
            }

            .menu-item.active:hover {
                transform: translateX(0);
            }

            .menu-icon {
                font-size: 16px;
                width: 20px;
                text-align: center;
            }

            .menu-divider {
                margin: 20px 0;
                border: none;
                height: 1px;
                background: linear-gradient(90deg, transparent, rgba(26, 26, 26, 0.2), transparent);
            }

            /* CONTEÚDO PRINCIPAL */
            .main-content {
                margin-left: 280px;
                margin-top: 70px;
                min-height: calc(100vh - 70px);
                padding: 30px;
            }

            /* RESPONSIVIDADE */
            @media (max-width: 768px) {
                .user-name {
                    display: none;
                }
                
                .user-role {
                    display: none;
                }
                
                .user-info {
                    padding: 6px;
                    gap: 8px;
                }
                
                .user-dropdown {
                    right: -10px;
                    min-width: 200px;
                }
            }
            
            @media (max-width: 480px) {
                .user-dropdown {
                    position: fixed;
                    top: 70px;
                    right: 10px;
                    left: 10px;
                    width: auto;
                }
            }

            @media (max-width: 1024px) {
                .sidebar {
                    transform: translateX(-100%);
                    transition: transform 0.3s ease;
                }

                .sidebar.open {
                    transform: translateX(0);
                }

                .main-content {
                    margin-left: 0;
                }

                .mobile-menu-btn {
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    width: 40px;
                    height: 40px;
                    background: rgba(255,255,255,0.1);
                    border: 1px solid rgba(255,255,255,0.2);
                    border-radius: 6px;
                    color: white;
                    cursor: pointer;
                    transition: all 0.3s ease;
                }

                .mobile-menu-btn:hover {
                    background: rgba(255,255,255,0.2);
                }

                .logo-text {
                    display: none;
                }
            }

            @media (min-width: 1025px) {
                .mobile-menu-btn {
                    display: none;
                }
            }

            @media (max-width: 768px) {
                .header {
                    padding: 0 15px;
                }

                .user-details {
                    display: none; 
                }

                .main-content {
                    padding: 20px 15px;
                }

                .sidebar {
                    width: 100%;
                }
            }

            /* OVERLAY PARA MOBILE */
            .sidebar-overlay {
                display: none;
                position: fixed;
                top: 70px;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0,0,0,0.5);
                z-index: 998;
            }

            .sidebar-overlay.show {
                display: block;
            }

            /* SCROLLBAR CUSTOMIZADA */
            .sidebar::-webkit-scrollbar {
                width: 6px;
            }

            .sidebar::-webkit-scrollbar-track {
                background: rgba(0,0,0,0.05);
                border-radius: 3px;
            }

            .sidebar::-webkit-scrollbar-thumb {
                background: rgba(0,0,0,0.2);
                border-radius: 3px;
            }

            .sidebar::-webkit-scrollbar-thumb:hover {
                background: rgba(0,0,0,0.3);
            }
			
			/* === NOTIFICAÇÕES === */
			.notifications-widget {
				position: relative;
				margin-right: 20px;
			}

			.notifications-button {
				position: relative;
				background: transparent;
				border: none;
				cursor: pointer;
				padding: 8px;
				border-radius: 8px;
				transition: all 0.3s;
				display: flex;
				align-items: center;
				justify-content: center;
			}

			.notifications-button:hover {
				background: rgba(255, 255, 255, 0.1);
			}

			.notifications-button svg {
				color: white;
			}

			.notifications-badge {
				position: absolute;
				top: 2px;
				right: 2px;
				background: #dc3545;
				color: white;
				border-radius: 10px;
				padding: 2px 6px;
				font-size: 11px;
				font-weight: 700;
				min-width: 18px;
				text-align: center;
				animation: pulse 2s infinite;
			}

			@keyframes pulse {
				0%, 100% { transform: scale(1); }
				50% { transform: scale(1.1); }
			}

			.notifications-dropdown {
				position: absolute;
				top: 100%;
				right: 0;
				margin-top: 10px;
				width: 400px;
				max-height: 500px;
				background: white;
				border-radius: 12px;
				box-shadow: 0 8px 32px rgba(0,0,0,0.3);
				overflow: hidden;
				z-index: 1000;
				animation: slideDown 0.3s ease;
			}

			.notifications-header {
				padding: 15px 20px;
				background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
				color: white;
				display: flex;
				justify-content: space-between;
				align-items: center;
			}

			.notifications-header h3 {
				margin: 0;
				font-size: 16px;
				font-weight: 700;
			}

			.mark-all-read {
				background: rgba(255, 255, 255, 0.2);
				color: white;
				border: none;
				padding: 6px 12px;
				border-radius: 6px;
				font-size: 12px;
				font-weight: 600;
				cursor: pointer;
				transition: all 0.3s;
			}

			.mark-all-read:hover {
				background: rgba(255, 255, 255, 0.3);
			}

			.notifications-list {
				max-height: 400px;
				overflow-y: auto;
			}

			.notification-item {
				padding: 15px 20px;
				border-bottom: 1px solid #f0f0f0;
				cursor: pointer;
				transition: all 0.3s;
				display: flex;
				gap: 12px;
				align-items: flex-start;
			}

			.notification-item:hover {
				background: #f8f9fa;
			}

			.notification-item.unread {
				background: rgba(102, 126, 234, 0.05);
			}

			.notification-icon {
				width: 40px;
				height: 40px;
				min-width: 40px;
				border-radius: 50%;
				display: flex;
				align-items: center;
				justify-content: center;
				font-size: 18px;
			}

			.notification-icon.publicacao { background: rgba(220, 53, 69, 0.1); }
			.notification-icon.prazo { background: rgba(255, 193, 7, 0.1); }
			.notification-icon.tarefa { background: rgba(102, 126, 234, 0.1); }
			.notification-icon.audiencia { background: rgba(40, 167, 69, 0.1); }

			.notification-content {
				flex: 1;
			}

			.notification-title {
				font-weight: 600;
				color: #1a1a1a;
				margin-bottom: 4px;
				font-size: 14px;
			}

			.notification-message {
				color: #666;
				font-size: 13px;
				margin-bottom: 4px;
				line-height: 1.4;
			}

			.notification-time {
				color: #999;
				font-size: 11px;
			}

			.notification-unread-indicator {
				width: 8px;
				height: 8px;
				background: #667eea;
				border-radius: 50%;
				margin-top: 6px;
			}

			.loading-notifications {
				padding: 40px 20px;
				text-align: center;
				color: #666;
			}

			.spinner {
				width: 30px;
				height: 30px;
				border: 3px solid #f3f3f3;
				border-top: 3px solid #667eea;
				border-radius: 50%;
				animation: spin 1s linear infinite;
				margin: 0 auto 10px;
			}

			@keyframes spin {
				0% { transform: rotate(0deg); }
				100% { transform: rotate(360deg); }
			}

			.notifications-empty {
				padding: 40px 20px;
				text-align: center;
				color: #999;
			}

			.notifications-empty svg {
				width: 60px;
				height: 60px;
				margin-bottom: 10px;
				opacity: 0.3;
			}

			.notifications-footer {
				padding: 12px 20px;
				background: #f8f9fa;
				text-align: center;
				border-top: 1px solid #e9ecef;
			}

			.notifications-footer a {
				color: #667eea;
				font-weight: 600;
				font-size: 13px;
				text-decoration: none;
			}

			.notifications-footer a:hover {
				text-decoration: underline;
			}

			@media (max-width: 768px) {
				.notifications-dropdown {
					width: 90vw;
					right: -20px;
				}
			}
			
			/* === BUSCA GLOBAL === */
            .global-search-container {
                flex: 1;
                max-width: 600px;
                margin: 0 30px;
                position: relative;
            }
            
            .search-box {
                position: relative;
                display: flex;
                align-items: center;
                background: rgba(255, 255, 255, 0.1);
                border: 1px solid rgba(255, 255, 255, 0.2);
                border-radius: 10px;
                padding: 0 15px;
                transition: all 0.3s;
            }
            
            .search-box:focus-within {
                background: rgba(255, 255, 255, 0.15);
                border-color: rgba(255, 255, 255, 0.4);
                box-shadow: 0 0 0 3px rgba(255, 255, 255, 0.1);
            }
            
            .search-icon {
                color: rgba(255, 255, 255, 0.6);
                margin-right: 10px;
            }
            
            .search-input {
                flex: 1;
                background: transparent;
                border: none;
                color: white;
                font-size: 14px;
                padding: 12px 0;
                outline: none;
            }
            
            .search-input::placeholder {
                color: rgba(255, 255, 255, 0.5);
            }
            
            .search-shortcut {
                background: rgba(255, 255, 255, 0.1);
                border: 1px solid rgba(255, 255, 255, 0.2);
                border-radius: 4px;
                padding: 4px 8px;
                font-size: 11px;
                font-weight: 600;
                color: rgba(255, 255, 255, 0.6);
                margin-left: 10px;
            }
            
            .search-results {
                position: absolute;
                top: calc(100% + 10px);
                left: 0;
                right: 0;
                background: white;
                border-radius: 12px;
                box-shadow: 0 8px 32px rgba(0,0,0,0.3);
                max-height: 500px;
                overflow-y: auto;
                z-index: 1001;
                animation: slideDown 0.3s ease;
            }
            
            .search-results-header {
                padding: 15px 20px;
                border-bottom: 1px solid #e9ecef;
                background: #f8f9fa;
                border-radius: 12px 12px 0 0;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            
            .search-results-title {
                font-weight: 700;
                color: #1a1a1a;
                font-size: 14px;
            }
            
            .search-results-count {
                background: #667eea;
                color: white;
                padding: 4px 10px;
                border-radius: 12px;
                font-size: 12px;
                font-weight: 700;
            }
            
            .search-results-section {
                padding: 10px 0;
            }
            
            .search-results-section-title {
                padding: 10px 20px;
                font-size: 12px;
                font-weight: 700;
                color: #667eea;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                background: rgba(102, 126, 234, 0.05);
            }
            
            .search-result-item {
                padding: 12px 20px;
                cursor: pointer;
                transition: all 0.3s;
                display: flex;
                align-items: center;
                gap: 12px;
                border-bottom: 1px solid rgba(0,0,0,0.03);
            }
            
            .search-result-item:hover {
                background: rgba(102, 126, 234, 0.05);
            }
            
            .search-result-icon {
                width: 40px;
                height: 40px;
                min-width: 40px;
                border-radius: 8px;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 18px;
            }
            
            .search-result-icon.processo { background: rgba(102, 126, 234, 0.1); }
            .search-result-icon.cliente { background: rgba(40, 167, 69, 0.1); }
            .search-result-icon.publicacao { background: rgba(220, 53, 69, 0.1); }
            .search-result-icon.tarefa { background: rgba(255, 193, 7, 0.1); }
            .search-result-icon.audiencia { background: rgba(23, 162, 184, 0.1); }
            
            .search-result-content {
                flex: 1;
                min-width: 0;
            }
            
            .search-result-title {
                font-weight: 600;
                color: #1a1a1a;
                font-size: 14px;
                margin-bottom: 3px;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            
            .search-result-subtitle {
                font-size: 12px;
                color: #666;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
            
            .search-loading {
                padding: 40px 20px;
                text-align: center;
                color: #666;
            }
            
            .search-empty {
                padding: 40px 20px;
                text-align: center;
            }
            
            .search-empty svg {
                width: 60px;
                height: 60px;
                margin-bottom: 15px;
                opacity: 0.2;
            }
            
            .search-empty-title {
                color: #1a1a1a;
                font-weight: 600;
                margin-bottom: 5px;
            }
            
            .search-empty-text {
                color: #666;
                font-size: 13px;
            }
            
            .header-right {
                display: flex;
                align-items: center;
                gap: 20px;
            }
            
            @keyframes slideDown {
                from {
                    opacity: 0;
                    transform: translateY(-10px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
            
            @media (max-width: 1024px) {
                .global-search-container {
                    display: none;
                }
            }
            
            @media (max-width: 768px) {
                .header-right {
                    gap: 10px;
                }
            }
        </style>
    </head>
    <body>
        <!-- ============================================ -->
        <!-- POPUP DE IMPERSONAÇÃO - SOBRE O MENU LATERAL -->
        <!-- Substitua o bloco de impersonação no layout.php -->
        <!-- ============================================ -->
        
        <?php if (isset($_SESSION['admin_impersonating'])): ?>
        <style>
            .impersonation-popup {
                position: fixed;
                bottom: 20px;
                left: 20px;
                background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%);
                color: white;
                padding: 16px 20px;
                border-radius: 12px;
                box-shadow: 0 8px 32px rgba(238, 90, 111, 0.4), 0 0 0 1px rgba(255, 255, 255, 0.1);
                z-index: 10000;
                max-width: 240px;
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                animation: slideInLeft 0.4s cubic-bezier(0.4, 0, 0.2, 1);
                cursor: pointer;
                transition: all 0.3s ease;
            }
        
            .impersonation-popup:hover {
                transform: translateY(-4px) scale(1.02);
                box-shadow: 0 12px 40px rgba(238, 90, 111, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.2);
            }
        
            .impersonation-popup.minimized {
                padding: 12px 16px;
                max-width: 200px;
            }
        
            .impersonation-popup.minimized .popup-content {
                display: none;
            }
        
            .impersonation-popup.minimized .popup-header {
                margin-bottom: 0;
            }
        
            .popup-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 12px;
                gap: 12px;
            }
        
            .popup-title {
                display: flex;
                align-items: center;
                gap: 8px;
                font-size: 13px;
                font-weight: 700;
                letter-spacing: 0.3px;
            }
        
            .popup-icon {
                font-size: 18px;
                animation: pulse 2s infinite;
            }
        
            .popup-minimize {
                background: rgba(255, 255, 255, 0.15);
                border: none;
                color: white;
                width: 24px;
                height: 24px;
                border-radius: 6px;
                cursor: pointer;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 16px;
                transition: all 0.2s ease;
                flex-shrink: 0;
            }
        
            .popup-minimize:hover {
                background: rgba(255, 255, 255, 0.25);
                transform: scale(1.1);
            }
        
            .popup-content {
                animation: fadeIn 0.3s ease;
            }
        
            .impersonated-user {
                background: rgba(255, 255, 255, 0.15);
                padding: 10px 12px;
                border-radius: 8px;
                margin-bottom: 12px;
                border-left: 3px solid rgba(255, 255, 255, 0.5);
            }
        
            .impersonated-user strong {
                display: block;
                font-size: 14px;
                font-weight: 700;
                margin-bottom: 4px;
                color: #fff;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
        
            .impersonated-user small {
                font-size: 11px;
                opacity: 0.9;
                display: block;
                color: rgba(255, 255, 255, 0.85);
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
        
            .btn-return-admin {
                width: 100%;
                background: white;
                color: #ff6b6b;
                border: none;
                padding: 10px 14px;
                border-radius: 8px;
                font-weight: 700;
                font-size: 12px;
                cursor: pointer;
                transition: all 0.2s ease;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
            }
        
            .btn-return-admin:hover {
                background: #f8f9fa;
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
                color: #ff6b6b;
                text-decoration: none;
            }
        
            .pulse-badge {
                position: absolute;
                top: -6px;
                right: -6px;
                width: 12px;
                height: 12px;
                background: #ffc107;
                border-radius: 50%;
                border: 2px solid #ff6b6b;
                animation: pulseBadge 1.5s infinite;
            }
        
            @keyframes slideInLeft {
                from { transform: translateX(-100px); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }
        
            @keyframes fadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }
        
            @keyframes pulse {
                0%, 100% { transform: scale(1); }
                50% { transform: scale(1.1); }
            }
        
            @keyframes pulseBadge {
                0%, 100% { transform: scale(1); opacity: 1; }
                50% { transform: scale(1.3); opacity: 0.7; }
            }
        
            @media (max-width: 768px) {
                .impersonation-popup {
                    left: 10px;
                    right: 10px;
                    max-width: calc(100% - 20px);
                }
            }
            
            /* Ajuste quando tela for desktop */
            @media (min-width: 769px) {
                .impersonation-popup {
                    left: 20px;
                    max-width: 240px;
                }
            }
        </style>
        
        <div class="impersonation-popup" id="impersonationPopup">
            <span class="pulse-badge"></span>
            
            <div class="popup-header">
                <div class="popup-title">
                    <span class="popup-icon">🎭</span>
                    <span>MODO ADMIN</span>
                </div>
                <button class="popup-minimize" onclick="toggleImpersonationPopup()" title="Minimizar">
                    <span id="impersonationMinimizeIcon">−</span>
                </button>
            </div>
            
            <div class="popup-content">
                <div class="impersonated-user">
                    <strong><?= htmlspecialchars($_SESSION['nome']) ?></strong>
                    <small><?= htmlspecialchars($_SESSION['nivel_acesso']) ?></small>
                </div>
                
                <a href="<?= SITE_URL ?>/modules/admin/voltar_admin.php" class="btn-return-admin">
                    <span>←</span>
                    <span>Voltar Admin</span>
                </a>
            </div>
        </div>
        
        <script>
        function toggleImpersonationPopup() {
            const popup = document.getElementById('impersonationPopup');
            const icon = document.getElementById('impersonationMinimizeIcon');
            
            popup.classList.toggle('minimized');
            
            if (popup.classList.contains('minimized')) {
                icon.textContent = '+';
                localStorage.setItem('impersonationPopupMinimized', 'true');
            } else {
                icon.textContent = '−';
                localStorage.setItem('impersonationPopupMinimized', 'false');
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            const popup = document.getElementById('impersonationPopup');
            if (popup) {
                const isMinimized = localStorage.getItem('impersonationPopupMinimized') === 'true';
                if (isMinimized) {
                    popup.classList.add('minimized');
                    document.getElementById('impersonationMinimizeIcon').textContent = '+';
                }
                
                popup.addEventListener('click', function(e) {
                    if (this.classList.contains('minimized') && !e.target.closest('.popup-minimize')) {
                        toggleImpersonationPopup();
                    }
                });
            }
        });
        </script>
        <?php endif; ?>
        <!-- HEADER FIXO -->
        <div class="header">
            <div class="header-left">
                <button class="mobile-menu-btn" onclick="toggleSidebar()">
                    <span class="menu-icon">☰</span>
                </button>
                
                <a href="<?= SITE_URL ?>/modules/dashboard/" class="logo">
                    <div class="logo-icon">
                        <img src="<?= SITE_URL ?>/assets/img/AM_Prancheta 1.png" alt="Logo do Escritório">
                    </div>
                    <div class="logo-text">
                        <div class="logo-title">SIGAM</div>
                        <div class="logo-subtitle">Alencar & Martinazzo</div>
                    </div>
                </a>
            </div>
            
            <!-- BUSCA GLOBAL -->
            <div class="global-search-container">
                <div class="search-box">
                    <svg class="search-icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="11" cy="11" r="8"></circle>
                        <path d="m21 21-4.35-4.35"></path>
                    </svg>
                    <input type="text" 
                           id="globalSearch" 
                           class="search-input" 
                           placeholder="Buscar processos, clientes, publicações..."
                           autocomplete="off">
                    <kbd class="search-shortcut">Ctrl+K</kbd>
                </div>
                
                <!-- Resultados da busca -->
                <div class="search-results" id="searchResults" style="display: none;"></div>
            </div>
            
            <!-- DIREITA: Notificações + User -->
            <div class="header-right">
                <!-- Notificações -->
                <div class="notifications-widget">
                    <button class="notifications-button" onclick="toggleNotifications()" id="notificationsButton">
                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
                        </svg>
                        <span class="notifications-badge" id="notificationsBadge" style="display: none;">0</span>
                    </button>
        
                    <div class="notifications-dropdown" id="notificationsDropdown" style="display: none;">
                        <div class="notifications-header">
                            <h3>Notificações</h3>
                            <button onclick="marcarTodasComoLidas()" class="mark-all-read">
                                Marcar todas como lidas
                            </button>
                        </div>
        
                        <div class="notifications-list" id="notificationsList">
                            <div class="loading-notifications">
                                <div class="spinner"></div>
                                Carregando...
                            </div>
                        </div>
        
                        <div class="notifications-footer">
                            <a href="<?= SITE_URL ?>/modules/notificacoes/index.php">Ver todas</a>
                        </div>
                    </div>
                </div>
                
                <!-- User Info -->
                <div class="user-info">
                    <div class="user-avatar">
                        <?= strtoupper(substr(htmlspecialchars($usuario_logado['nome']), 0, 2)) ?>
                    </div>
                    <div class="user-details">
                        <div class="user-name"><?= htmlspecialchars($usuario_logado['nome']) ?></div>
                        <div class="user-role"><?= htmlspecialchars($usuario_logado['nivel_acesso']) ?></div>
                    </div>
                    <div class="user-menu-wrapper">
                        <button class="user-menu-toggle" onclick="toggleUserMenu()">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="6 9 12 15 18 9"></polyline>
                            </svg>
                        </button>
                        
                        <div class="user-dropdown" id="userDropdown" style="display: none;">
                            <a href="<?= SITE_URL ?>/modules/admin/alterar_minha_senha.php" class="dropdown-item">
                                <i class="fas fa-key"></i>
                                <span>Alterar Senha</span>
                            </a>
                            <a href="<?= SITE_URL ?>/modules/auth/logout.php" class="dropdown-item logout">
                                <i class="fas fa-sign-out-alt"></i>
                                <span>Sair</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- OVERLAY PARA MOBILE -->
        <div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
        
        <!-- SIDEBAR FIXO -->
        <div class="sidebar" id="sidebar">
            <h3>Menu Principal</h3>
            <a href="<?= SITE_URL ?>/modules/dashboard/" class="menu-item <?= $pagina_ativa === 'dashboard' ? 'active' : '' ?>">
                <span class="menu-icon">🏠</span>
                Dashboard
            </a>
            <!--<a href="<?= SITE_URL ?>/modules/atendimentos/" class="menu-item <?= $pagina_ativa === 'atendimentos' ? 'active' : '' ?>">
                <span class="menu-icon">👥</span>
                Atendimentos
            </a>-->
            <a href="<?= SITE_URL ?>/modules/clientes/" class="menu-item <?= $pagina_ativa === 'clientes' ? 'active' : '' ?>">
                <span class="menu-icon">👤</span>
                Clientes
            </a>
            <a href="<?= SITE_URL ?>/modules/prospeccao/" class="menu-item <?= $pagina_ativa === 'prospeccao' ? 'active' : '' ?>">
                <span class="menu-icon">📊</span>
                Prospecção
            </a>
            <a href="<?= SITE_URL ?>/modules/agenda/" class="menu-item <?= $pagina_ativa === 'agenda' ? 'active' : '' ?>">
                <span class="menu-icon">📅</span>
                Agenda
            </a>
            <a href="<?= SITE_URL ?>/modules/processos/" class="menu-item <?= $pagina_ativa === 'processos' ? 'active' : '' ?>">
                <span class="menu-icon">⚖️</span>
                Processos
            </a>
			<a href="<?= SITE_URL ?>/modules/publicacoes/" class="menu-item <?= $pagina_ativa === 'publicacoes' ? 'active' : '' ?>">
                <span class="menu-icon">📰</span>
                Publicações
            </a>
			<a href="<?= SITE_URL ?>/modules/configuracoes/etiquetas.php" class="menu-item <?= $pagina_ativa === 'etiquetas' ? 'active' : '' ?>">
				<span class="menu-icon">🏷️</span>
				Etiquetas
			</a>
			
            <?php 
			// Níveis que podem acessar área administrativa
			$niveis_admin = ['Admin', 'Administrador', 'Socio', 'Diretor'];
			$eh_admin = in_array($usuario_logado['nivel_acesso'], $niveis_admin);

			// Pegar acesso financeiro da sessão
			$acesso_financeiro = $usuario_logado['acesso_financeiro'] ?? 'Nenhum';

			// Mostrar menu de administração se for admin OU tiver acesso financeiro
			if ($eh_admin || $acesso_financeiro !== 'Nenhum'): 
			?>
			<hr class="menu-divider">
			<h3>Administração</h3>

			<?php if ($acesso_financeiro !== 'Nenhum'): ?>
			<a href="<?= SITE_URL ?>/modules/financeiro/" class="menu-item <?= $pagina_ativa === 'financeiro' ? 'active' : '' ?>">
				<span class="menu-icon">💰</span>
				Financeiro
			</a>
			<?php endif; ?>

			<?php if ($eh_admin): ?>
			<a href="<?= SITE_URL ?>/modules/admin/usuarios.php" class="menu-item <?= $pagina_ativa === 'usuarios' ? 'active' : '' ?>">
				<span class="menu-icon">👥</span>
				Gerenciar Usuários
			</a>
			<a href="<?= SITE_URL ?>/modules/admin/logs.php" class="menu-item <?= $pagina_ativa === 'logs' ? 'active' : '' ?>">
				<span class="menu-icon">📊</span>
				Logs do Sistema
			</a>
			<?php endif; ?>

			<?php endif; ?>
		</div>
        
        <!-- CONTEÚDO PRINCIPAL -->
        <div class="main-content">
            <?= $conteudo_principal ?>
        </div>
        
        <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

        <script>
            function toggleSidebar() {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('sidebarOverlay');
                
                sidebar.classList.toggle('open');
                overlay.classList.toggle('show');
            }
            
            function closeSidebar() {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('sidebarOverlay');
                
                sidebar.classList.remove('open');
                overlay.classList.remove('show');
            }
            
            // Fechar sidebar ao clicar em um link no mobile
            document.querySelectorAll('.menu-item').forEach(item => {
                item.addEventListener('click', () => {
                    if (window.innerWidth <= 1024) {
                        closeSidebar();
                    }
                });
            });
            
            // Fechar sidebar ao redimensionar para desktop
            window.addEventListener('resize', () => {
                if (window.innerWidth > 1024) {
                    closeSidebar();
                }
            });
			
			// === SISTEMA DE NOTIFICAÇÕES ===
			let notificationsOpen = false;
			let notificationsInterval = null;

			// Carregar notificações ao carregar a página
			document.addEventListener('DOMContentLoaded', function() {
				carregarNotificacoes();

				// Atualizar a cada 30 segundos
				notificationsInterval = setInterval(carregarNotificacoes, 30000);

				// Fechar dropdown ao clicar fora
				document.addEventListener('click', function(e) {
					const widget = document.querySelector('.notifications-widget');
					if (widget && !widget.contains(e.target)) {
						fecharNotifications();
					}
				});
			});

			function toggleNotifications() {
				const dropdown = document.getElementById('notificationsDropdown');

				if (notificationsOpen) {
					fecharNotifications();
				} else {
					dropdown.style.display = 'block';
					notificationsOpen = true;
					carregarNotificacoes();
				}
			}

			function fecharNotifications() {
				const dropdown = document.getElementById('notificationsDropdown');
				dropdown.style.display = 'none';
				notificationsOpen = false;
			}

			async function carregarNotificacoes() {
				try {
					const response = await fetch('<?= SITE_URL ?>/api/notificacoes.php?action=listar&limit=10');
					const data = await response.json();

					if (data.success) {
						atualizarBadge(data.nao_lidas);
						renderizarNotificacoes(data.notificacoes);
					}
				} catch (error) {
					console.error('Erro ao carregar notificações:', error);
				}
			}

			function atualizarBadge(count) {
				const badge = document.getElementById('notificationsBadge');

				if (count > 0) {
					badge.textContent = count > 99 ? '99+' : count;
					badge.style.display = 'block';
				} else {
					badge.style.display = 'none';
				}
			}

			function renderizarNotificacoes(notificacoes) {
				const container = document.getElementById('notificationsList');

				if (!notificacoes || notificacoes.length === 0) {
					container.innerHTML = `
						<div class="notifications-empty">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
								<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
								<path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
							</svg>
							<p>Nenhuma notificação</p>
						</div>
					`;
					return;
				}

				let html = '';
				notificacoes.forEach(notif => {
					const icone = getIconeNotificacao(notif.tipo);
					const tempo = formatarTempo(notif.data_criacao);
					const lida = notif.lida == 1;

					html += `
						<div class="notification-item ${!lida ? 'unread' : ''}" 
							 onclick="abrirNotificacao(${notif.id}, '${escapeJsString(notif.link || '')}')">
							<div class="notification-icon ${getTipoClass(notif.tipo)}">${icone}</div>
							<div class="notification-content">
								<div class="notification-title">${escapeHtml(notif.titulo)}</div>
								<div class="notification-message">${escapeHtml(notif.mensagem)}</div>
								<div class="notification-time">${tempo}</div>
							</div>
							${!lida ? '<div class="notification-unread-indicator"></div>' : ''}
						</div>
					`;
				});

				container.innerHTML = html;
			}

			function getTipoClass(tipo) {
				// Remove o sufixo para obter apenas a categoria
				if (tipo.includes('publicacao')) return 'publicacao';
				if (tipo.includes('prazo')) return 'prazo';
				if (tipo.includes('tarefa')) return 'tarefa';
				if (tipo.includes('audiencia')) return 'audiencia';
				return 'publicacao';
			}

			function getIconeNotificacao(tipo) {
				const icones = {
					'publicacao_nova': '📄',
					'publicacao_vinculada': '🔗',
					'prazo_vencendo': '⏰',
					'prazo_vencido': '🚨',
					'tarefa_atribuida': '✓',
					'tarefa_vencendo': '⏳',
					'audiencia_proxima': '📅',
					'processo_atualizado': '📁',
					'processo_criado': '✨'
				};
				return icones[tipo] || '🔔';
			}

			function formatarTempo(dataStr) {
				const data = new Date(dataStr);
				const agora = new Date();
				const diff = Math.floor((agora - data) / 1000); // segundos

				if (diff < 60) return 'Agora mesmo';
				if (diff < 3600) return Math.floor(diff / 60) + ' min atrás';
				if (diff < 86400) return Math.floor(diff / 3600) + 'h atrás';
				if (diff < 604800) return Math.floor(diff / 86400) + 'd atrás';

				return data.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit' });
			}

			async function abrirNotificacao(id, link) {
				try {
					// Marcar como lida
					await fetch('<?= SITE_URL ?>/api/notificacoes.php', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify({ action: 'marcar_lida', id: id })
					});

					// Atualizar lista
					await carregarNotificacoes();

					// Redirecionar se houver link
					if (link) {
						window.location.href = link;
					}
				} catch (error) {
					console.error('Erro ao abrir notificação:', error);
				}
			}

			async function marcarTodasComoLidas() {
				try {
					const response = await fetch('<?= SITE_URL ?>/api/notificacoes.php', {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify({ action: 'marcar_todas_lidas' })
					});

					const data = await response.json();

					if (data.success) {
						await carregarNotificacoes();
					}
				} catch (error) {
					console.error('Erro ao marcar todas como lidas:', error);
				}
			}

			function escapeHtml(text) {
				if (!text) return '';
				const div = document.createElement('div');
				div.textContent = text;
				return div.innerHTML;
			}

			function escapeJsString(str) {
				if (!str) return '';
				return str.replace(/'/g, "\\'").replace(/"/g, '\\"');
			}
			
			// === BUSCA GLOBAL ===
            let searchTimeout = null;
            let searchResultsOpen = false;
            
            const searchInput = document.getElementById('globalSearch');
            const searchResults = document.getElementById('searchResults');
            
            // Atalho Ctrl+K
            document.addEventListener('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                    e.preventDefault();
                    searchInput.focus();
                }
                
                // ESC para fechar busca
                if (e.key === 'Escape') {
                    fecharBusca();
                }
            });
            
            // Input de busca
            searchInput.addEventListener('input', function() {
                const query = this.value.trim();
                
                clearTimeout(searchTimeout);
                
                if (query.length < 2) {
                    fecharBusca();
                    return;
                }
                
                searchTimeout = setTimeout(() => {
                    realizarBusca(query);
                }, 300);
            });
            
            // Fechar ao clicar fora
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.global-search-container')) {
                    fecharBusca();
                }
            });
            
            async function realizarBusca(query) {
                searchResults.style.display = 'block';
                searchResultsOpen = true;
                
                searchResults.innerHTML = `
                    <div class="search-loading">
                        <div class="spinner"></div>
                        Buscando...
                    </div>
                `;
                
                try {
                    const response = await fetch('<?= SITE_URL ?>/api/busca_global.php?q=' + encodeURIComponent(query));
                    const data = await response.json();
                    
                    if (data.success && data.total > 0) {
                        renderizarResultadosBusca(data);
                    } else {
                        searchResults.innerHTML = `
                            <div class="search-empty">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="11" cy="11" r="8"></circle>
                                    <path d="m21 21-4.35-4.35"></path>
                                </svg>
                                <div class="search-empty-title">Nenhum resultado encontrado</div>
                                <div class="search-empty-text">Tente buscar por número de processo, nome de cliente ou título</div>
                            </div>
                        `;
                    }
                } catch (error) {
                    console.error('Erro na busca:', error);
                    searchResults.innerHTML = `
                        <div class="search-empty">
                            <div class="search-empty-title">Erro na busca</div>
                            <div class="search-empty-text">Tente novamente</div>
                        </div>
                    `;
                }
            }
            
            function renderizarResultadosBusca(data) {
                let html = `
                    <div class="search-results-header">
                        <span class="search-results-title">Resultados da Busca</span>
                        <span class="search-results-count">${data.total}</span>
                    </div>
                `;
                
                // Processos
                if (data.processos && data.processos.length > 0) {
                    html += `
                        <div class="search-results-section">
                            <div class="search-results-section-title">⚖️ Processos (${data.processos.length})</div>
                    `;
                    
                    data.processos.forEach(item => {
                        html += `
                            <div class="search-result-item" onclick="window.location.href='<?= SITE_URL ?>/modules/processos/visualizar.php?id=${item.id}'">
                                <div class="search-result-icon processo">⚖️</div>
                                <div class="search-result-content">
                                    <div class="search-result-title">${escapeHtml(item.numero_processo)}</div>
                                    <div class="search-result-subtitle">${escapeHtml(item.cliente_nome)}</div>
                                </div>
                            </div>
                        `;
                    });
                    
                    html += '</div>';
                }
                
                // Clientes
                if (data.clientes && data.clientes.length > 0) {
                    html += `
                        <div class="search-results-section">
                            <div class="search-results-section-title">👤 Clientes (${data.clientes.length})</div>
                    `;
                    
                    data.clientes.forEach(item => {
                        html += `
                            <div class="search-result-item" onclick="window.location.href='<?= SITE_URL ?>/modules/clientes/visualizar.php?id=${item.id}'">
                                <div class="search-result-icon cliente">👤</div>
                                <div class="search-result-content">
                                    <div class="search-result-title">${escapeHtml(item.nome)}</div>
                                    <div class="search-result-subtitle">${item.cpf_cnpj ? escapeHtml(item.cpf_cnpj) : 'Sem CPF/CNPJ'}</div>
                                </div>
                            </div>
                        `;
                    });
                    
                    html += '</div>';
                }
                
                // Publicações
                if (data.publicacoes && data.publicacoes.length > 0) {
                    html += `
                        <div class="search-results-section">
                            <div class="search-results-section-title">📄 Publicações (${data.publicacoes.length})</div>
                    `;
                    
                    data.publicacoes.forEach(item => {
                        html += `
                            <div class="search-result-item" onclick="window.location.href='<?= SITE_URL ?>/modules/publicacoes/visualizar.php?id=${item.id}'">
                                <div class="search-result-icon publicacao">📄</div>
                                <div class="search-result-content">
                                    <div class="search-result-title">${escapeHtml(item.numero_processo_cnj || item.titulo || 'Publicação')}</div>
                                    <div class="search-result-subtitle">${escapeHtml(item.tipo_documento || 'Documento')}</div>
                                </div>
                            </div>
                        `;
                    });
                    
                    html += '</div>';
                }
                
                // Tarefas
                if (data.tarefas && data.tarefas.length > 0) {
                    html += `
                        <div class="search-results-section">
                            <div class="search-results-section-title">✓ Tarefas (${data.tarefas.length})</div>
                    `;
                    
                    data.tarefas.forEach(item => {
                        html += `
                            <div class="search-result-item" onclick="window.location.href='<?= SITE_URL ?>/modules/agenda/visualizar.php?id=${item.id}&tipo=tarefa'">
                                <div class="search-result-icon tarefa">✓</div>
                                <div class="search-result-content">
                                    <div class="search-result-title">${escapeHtml(item.titulo)}</div>
                                    <div class="search-result-subtitle">${escapeHtml(item.status)}</div>
                                </div>
                            </div>
                        `;
                    });
                    
                    html += '</div>';
                }
                
                // Prospecções (Kanban) - MOVIDO PARA ANTES DO innerHTML
                if (data.prospeccoes && data.prospeccoes.length > 0) {
                    html += `
                        <div class="search-results-section">
                            <div class="search-results-section-title">🎯 Prospecções (${data.prospeccoes.length})</div>
                    `;
                    
                    data.prospeccoes.forEach(item => {
                        // Determinar o módulo correto baseado no tipo
                        let moduloUrl = '<?= SITE_URL ?>/modules/prospeccao/';
                        
                        // Mapear o tipo para o módulo correto
                        switch(item.tipo) {
                            case 'advocacia':
                                moduloUrl += 'visualizar_advocacia.php';
                                break;
                            case 'COMEX':
                                moduloUrl = 'visualizar_comex.php';
                                break;
                            case 'TAX':
                                moduloUrl = 'visualizar_tax.php';
                                break;
                            default:
                                // Se não tiver tipo definido, usa o padrão (advocacia)
                                moduloUrl += 'visualizar_advocacia.php';
                        }
                        
                        // Ícone por fase
                        const faseIcon = {
                            'Prospecção': '🔍',
                            'Negociação': '💬',
                            'Fechados': '✅',
                            'Perdidos': '❌'
                        }[item.fase] || '🎯';
                        
                        // Label do tipo
                        const tipoLabel = {
                            'advocacia': 'Advocacia',
                            'COMEX': 'COMEX',
                            'TAX': 'TAX',
                        }[item.tipo] || 'Advocacia';
                        
                        // Valor formatado
                        const valor = item.valor_fechado || item.valor_proposta;
                        const valorFormatado = valor ? 'R$ ' + parseFloat(valor).toLocaleString('pt-BR', {minimumFractionDigits: 2, maximumFractionDigits: 2}) : '';
                        
                        html += `
                            <div class="search-result-item" onclick="window.location.href='${moduloUrl}?id=${item.id}'">
                                <div class="search-result-icon prospeccao">${faseIcon}</div>
                                <div class="search-result-content">
                                    <div class="search-result-title">${escapeHtml(item.nome)}</div>
                                    <div class="search-result-subtitle">
                                        ${tipoLabel} • ${escapeHtml(item.fase)}${item.telefone ? ' • ' + escapeHtml(item.telefone) : ''}${valorFormatado ? ' • ' + valorFormatado : ''}
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    
                    html += '</div>';
                }

                searchResults.innerHTML = html;
            }
            
            function fecharBusca() {
                searchResults.style.display = 'none';
                searchResultsOpen = false;
            }
            function toggleUserMenu() {
                const dropdown = document.getElementById('userDropdown');
                const toggle = document.querySelector('.user-menu-toggle');
                
                if (dropdown.style.display === 'none' || dropdown.style.display === '') {
                    dropdown.style.display = 'block';
                    toggle.classList.add('active');
                } else {
                    dropdown.style.display = 'none';
                    toggle.classList.remove('active');
                }
            }
            
            // Fechar dropdown ao clicar fora
            document.addEventListener('click', function(event) {
                const userInfo = document.querySelector('.user-info');
                const dropdown = document.getElementById('userDropdown');
                const toggle = document.querySelector('.user-menu-toggle');
                
                if (userInfo && !userInfo.contains(event.target)) {
                    if (dropdown) dropdown.style.display = 'none';
                    if (toggle) toggle.classList.remove('active');
                }
            });
        </script>
    </body>
</html>
    <?php
    return ob_get_clean();
}
?>