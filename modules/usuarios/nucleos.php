<?php
require_once '../../includes/auth.php';
Auth::protect(['Admin', 'Socio', 'Diretor']);

require_once '../../config/database.php';

$usuario_id = $_GET['id'] ?? 0;

if (!$usuario_id) {
    $_SESSION['erro'] = 'Usuário não encontrado';
    header('Location: index.php');
    exit;
}

// Buscar dados do usuário
$sql = "SELECT nome, email, nivel_acesso FROM usuarios WHERE id = ?";
$stmt = executeQuery($sql, [$usuario_id]);
$usuario = $stmt->fetch();

if (!$usuario) {
    $_SESSION['erro'] = 'Usuário não encontrado';
    header('Location: index.php');
    exit;
}

// Buscar núcleos do usuário com detalhes
$sql = "SELECT n.*, un.data_atribuicao 
        FROM nucleos n 
        INNER JOIN usuarios_nucleos un ON n.id = un.nucleo_id 
        WHERE un.usuario_id = ? 
        ORDER BY n.nome";
$stmt = executeQuery($sql, [$usuario_id]);
$nucleos_usuario = $stmt->fetchAll();

// Buscar núcleos disponíveis (que o usuário não tem)
$sql = "SELECT n.* FROM nucleos n 
        WHERE n.ativo = 1 
        AND n.id NOT IN (
            SELECT nucleo_id FROM usuarios_nucleos WHERE usuario_id = ?
        ) 
        ORDER BY n.nome";
$stmt = executeQuery($sql, [$usuario_id]);
$nucleos_disponiveis = $stmt->fetchAll();

$erro = $_SESSION['erro'] ?? '';
$sucesso = $_SESSION['sucesso'] ?? '';
unset($_SESSION['erro'], $_SESSION['sucesso']);

$usuario_logado = Auth::user();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Núcleos - SIGAM</title>
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
        }
        
        .header {
            background: linear-gradient(135deg, #1a1a1a 0%, #333 100%);
            backdrop-filter: blur(10px);
            color: white;
            padding: 18px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .header h1 {
            font-size: 26px;
            font-weight: 700;
            letter-spacing: 0.5px;
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .btn-logout {
            padding: 10px 18px;
            background: rgba(255,255,255,0.1);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            transition: all 0.3s ease;
            font-weight: 600;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .btn-logout:hover {
            background: rgba(255,255,255,0.2);
            transform: translateY(-1px);
        }
        
        .container {
            display: flex;
            min-height: calc(100vh - 76px);
        }
        
        .sidebar {
            width: 280px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(15px);
            box-shadow: 4px 0 20px rgba(0,0,0,0.15);
            padding: 25px;
            border-right: 1px solid rgba(0, 0, 0, 0.1);
        }
        
        .sidebar h3 {
            color: #1a1a1a;
            margin-bottom: 20px;
            font-size: 18px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        
        .menu-item {
            display: block;
            padding: 14px 16px;
            color: #444;
            text-decoration: none;
            border-radius: 8px;
            margin-bottom: 6px;
            transition: all 0.3s ease;
            font-weight: 500;
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
        }
        
        .content {
            flex: 1;
            padding: 30px;
        }
        
        .page-header {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            padding: 25px;
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.15);
            margin-bottom: 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .page-header h2 {
            color: #1a1a1a;
            font-size: 24px;
            font-weight: 700;
        }
        
        .btn-voltar {
            padding: 12px 24px;
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-voltar:hover {
            transform: translateY(-2px);
        }
        
        .user-info-box {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.15);
            padding: 25px;
            margin-bottom: 30px;
        }
        
        .user-info-box h3 {
            color: #1a1a1a;
            margin-bottom: 15px;
            font-size: 20px;
        }
        
        .user-info-box p {
            color: #444;
            margin-bottom: 8px;
            font-size: 16px;
        }
        
        .nucleos-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }
        
        .nucleos-section {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.15);
            padding: 25px;
        }
        
        .nucleos-section h3 {
            color: #1a1a1a;
            margin-bottom: 20px;
            font-size: 18px;
            font-weight: 700;
        }
        
        .nucleo-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px;
            border: 1px solid rgba(0,0,0,0.1);
            border-radius: 8px;
            margin-bottom: 10px;
            transition: all 0.3s;
        }
        
        .nucleo-item:hover {
            background: rgba(26, 26, 26, 0.02);
            transform: translateX(2px);
        }
        
        .nucleo-info h4 {
            color: #1a1a1a;
            font-size: 16px;
            margin-bottom: 5px;
        }
        
        .nucleo-info p {
            color: #666;
            font-size: 12px;
        }
        
        .btn-action {
            padding: 8px 15px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
        }
        
        .btn-add {
            background: #28a745;
            color: white;
        }
        
        .btn-add:hover {
            background: #218838;
            transform: translateY(-1px);
        }
        
        .btn-remove {
            background: #dc3545;
            color: white;
        }
        
        .btn-remove:hover {
            background: #c82333;
            transform: translateY(-1px);
        }
        
        .alert {
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .alert-danger {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .admin-badge {
            background: linear-gradient(135deg, #ffc107 0%, #ffb300 100%);
            color: #000;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            margin-left: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        hr {
            margin: 25px 0;
            border: none;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(26, 26, 26, 0.2), transparent);
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.5;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Sistema Interno de Gestão - Alencar & Martinazzo</h1>
        <div class="user-info">
            <span>Olá, <?= htmlspecialchars($usuario_logado['nome']) ?></span>
            <span class="admin-badge"><?= htmlspecialchars($usuario_logado['nivel_acesso']) ?></span>
            <a href="../auth/logout.php" class="btn-logout">Sair</a>
        </div>
    </div>
    
    <div class="container">
        <div class="sidebar">
            <h3>Menu Principal</h3>
            <a href="../dashboard/" class="menu-item">Dashboard</a>
            <a href="../atendimentos/" class="menu-item">Atendimentos</a>
            <a href="../agenda/" class="menu-item">Agenda</a>
            <a href="../processos/" class="menu-item">Processos</a>
            <a href="../clientes/" class="menu-item">Clientes</a>
            
            <hr>
            <h3>Administração</h3>
            <a href="index.php" class="menu-item active">Gerenciar Usuários</a>
            <a href="logs.php" class="menu-item">Logs do Sistema</a>
        </div>
        
        <div class="content">
            <div class="page-header">
                <h2>Gerenciar Núcleos do Usuário</h2>
                <a href="index.php" class="btn-voltar">← Voltar</a>
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
            
            <div class="user-info-box">
                <h3>Informações do Usuário</h3>
                <p><strong>Nome:</strong> <?= htmlspecialchars($usuario['nome']) ?></p>
                <p><strong>E-mail:</strong> <?= htmlspecialchars($usuario['email']) ?></p>
                <p><strong>Nível:</strong> <?= htmlspecialchars($usuario['nivel_acesso']) ?></p>
            </div>
            
            <div class="nucleos-grid">
                <div class="nucleos-section">
                    <h3>Núcleos Atribuídos (<?= count($nucleos_usuario) ?>)</h3>
                    
                    <?php if (empty($nucleos_usuario)): ?>
                    <div class="empty-state">
                        <p>Nenhum núcleo atribuído</p>
                    </div>
                    <?php else: ?>
                        <?php foreach ($nucleos_usuario as $nucleo): ?>
                        <div class="nucleo-item">
                            <div class="nucleo-info">
                                <h4><?= htmlspecialchars($nucleo['nome']) ?></h4>
                                <p>Atribuído em: <?= date('d/m/Y H:i', strtotime($nucleo['data_atribuicao'])) ?></p>
                            </div>
                            <form action="process_nucleos.php" method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="usuario_id" value="<?= $usuario_id ?>">
                                <input type="hidden" name="nucleo_id" value="<?= $nucleo['id'] ?>">
                                <button type="submit" class="btn-action btn-remove" onclick="return confirm('Remover este núcleo?')">
                                    Remover
                                </button>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <div class="nucleos-section">
                    <h3>Núcleos Disponíveis (<?= count($nucleos_disponiveis) ?>)</h3>
                    
                    <?php if (empty($nucleos_disponiveis)): ?>
                    <div class="empty-state">
                        <p>Todos os núcleos já foram atribuídos</p>
                    </div>
                    <?php else: ?>
                        <?php foreach ($nucleos_disponiveis as $nucleo): ?>
                        <div class="nucleo-item">
                            <div class="nucleo-info">
                                <h4><?= htmlspecialchars($nucleo['nome']) ?></h4>
                                <p><?= htmlspecialchars($nucleo['descricao']) ?></p>
                            </div>
                            <form action="process_nucleos.php" method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="add">
                                <input type="hidden" name="usuario_id" value="<?= $usuario_id ?>">
                                <input type="hidden" name="nucleo_id" value="<?= $nucleo['id'] ?>">
                                <button type="submit" class="btn-action btn-add">
                                    Adicionar
                                </button>
                            </form>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>