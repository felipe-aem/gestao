<?php
require_once '../../config/database.php';
session_start();

// Se já estiver logado, redireciona 
if (isset($_SESSION['usuario_id'])) {
    header('Location: ../dashboard/');
    exit;
}

$erro = $_SESSION['erro'] ?? '';
$sucesso = $_SESSION['sucesso'] ?? '';
unset($_SESSION['erro'], $_SESSION['sucesso']);
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - SIGAM</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, rgba(0, 0, 0, 0.8) 0%, rgba(40, 40, 40, 0.9) 100%), 
                        url(/assets/img/bgAtuacao.webp);
            background-size: cover;
            background-attachment: fixed;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        
        .login-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
            width: 100%;
            max-width: 400px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .login-header {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .login-header h1 {
            color: #1a1a1a;
            font-size: 28px;
            margin-bottom: 10px;
            font-weight: 700;
        }
        
        .login-header p {
            color: #444;
            font-size: 14px;
            font-weight: 500;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #2a2a2a;
            font-weight: 600;
            font-size: 14px;
        }
        
        .form-group input {
            width: 100%;
            padding: 14px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.9);
        }
        
        .form-group input:focus {
            outline: none;
            border-color: #333;
            box-shadow: 0 0 0 3px rgba(51, 51, 51, 0.1);
            background: white;
        }
        
        .btn-login {
            width: 100%;
            padding: 14px;
            background: linear-gradient(135deg, #1a1a1a 0%, #333 100%);
            color: white;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: all 0.3s ease;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.3);
            background: linear-gradient(135deg, #000 0%, #2a2a2a 100%);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .alert {
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            font-weight: 500;
        }
        
        .alert-danger {
            background: rgba(220, 53, 69, 0.1);
            color: #721c24;
            border: 2px solid rgba(220, 53, 69, 0.2);
        }
        
        .alert-success {
            background: rgba(40, 167, 69, 0.1);
            color: #155724;
            border: 2px solid rgba(40, 167, 69, 0.2);
        }
        
        .login-footer {
            text-align: center;
            margin-top: 25px;
            color: #555;
            font-size: 12px;
            font-weight: 500;
        }
        
        .login-footer p:first-child {
            margin-bottom: 4px;
            color: #333;
        }
        
        /* Efeito de partículas no fundo */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at 20% 80%, rgba(120, 119, 198, 0.1) 0%, transparent 50%),
                        radial-gradient(circle at 80% 20%, rgba(255, 255, 255, 0.05) 0%, transparent 50%),
                        radial-gradient(circle at 40% 40%, rgba(120, 119, 198, 0.05) 0%, transparent 50%);
            z-index: -1;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1>Alencar &amp; Martinazzo Advogados</h1>
            <p>Sistema Interno de Gestão</p>
        </div>
        
        <?php if ($erro): ?>
        <div class="alert alert-danger">
            <?= htmlspecialchars($erro) ?>
        </div>
        <?php endif; ?>
        
        <?php if ($sucesso): ?>
        <div class="alert alert-success">
            <?= htmlspecialchars($sucesso) ?>
        </div>
        <?php endif; ?>
        
        <form action="process_login.php" method="POST">
            <div class="form-group">
                <label for="email">E-mail:</label>
                <input type="email" id="email" name="email" required autofocus>
            </div>
            
            <div class="form-group">
                <label for="senha">Senha:</label>
                <input type="password" id="senha" name="senha" required>
            </div>
            
            <button type="submit" class="btn-login">Entrar</button>
        </form>
        
        <div class="login-footer">
            <p>© 2025 - Sistema Interno de Gestão - Alencar &amp; Martinazzo</p>
            <p>Acesso restrito a usuários autorizados - Todos os Direitos Reservados</p>
        </div>
    </div>
</body>
</html>