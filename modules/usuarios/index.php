<?php
require_once '../../includes/auth.php';
Auth::protect(['Admin', 'Socio', 'Diretor']); // Apenas esses níveis podem acessar

require_once '../../config/database.php';

// Buscar todos os usuários
$sql = "SELECT u.*, 
        (SELECT COUNT(*) FROM usuarios_nucleos WHERE usuario_id = u.id) as total_nucleos,
        criador.nome as criado_por_nome
        FROM usuarios u
        LEFT JOIN usuarios criador ON u.criado_por = criador.id
        ORDER BY u.data_criacao DESC";
$stmt = executeQuery($sql);
$usuarios = $stmt->fetchAll();

$usuario_logado = Auth::user();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Usuários - SIGAM</title>
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
        
        .btn-novo {
            padding: 12px 24px;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }
        
        .btn-novo:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
        }
        
        .table-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.15);
            overflow: hidden;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th {
            background: linear-gradient(135deg, #1a1a1a 0%, #333 100%);
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 13px;
            letter-spacing: 0.5px;
        }
        
        td {
            padding: 15px;
            border-bottom: 1px solid rgba(0,0,0,0.05);
            color: #444;
        }
        
        tr:hover {
            background: rgba(26, 26, 26, 0.02);
        }
        
        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .badge-admin { background: linear-gradient(135deg, #ffc107 0%, #ffb300 100%); color: #000; }
        .badge-socio { background: linear-gradient(135deg, #6f42c1 0%, #8b5cf6 100%); color: white; }
        .badge-diretor { background: linear-gradient(135deg, #007bff 0%, #0056b3 100%); color: white; }
        .badge-gestor { background: linear-gradient(135deg, #17a2b8 0%, #138496 100%); color: white; }
        .badge-advogado { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; }
        .badge-assistente { background: linear-gradient(135deg, #6c757d 0%, #495057 100%); color: white; }
        
        .badge-ativo { background: #28a745; color: white; }
        .badge-inativo { background: #dc3545; color: white; }
        
        .btn-action {
            padding: 6px 12px;
            margin: 0 2px;
            border-radius: 5px;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.3s;
            display: inline-block;
        }
        
        .btn-edit {
            background: #007bff;
            color: white;
        }
        
        .btn-edit:hover {
            background: #0056b3;
            transform: translateY(-1px);
        }
        
        .btn-delete {
            background: #dc3545;
            color: white;
        }
        
        .btn-delete:hover {
            background: #c82333;
            transform: translateY(-1px);
        }
        
        .btn-nucleos {
            background: #17a2b8;
            color: white;
        }
        
        .btn-nucleos:hover {
            background: #138496;
            transform: translateY(-1px);
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
                <h2>Gerenciar Usuários</h2>
                <a href="novo.php" class="btn-novo">+ Novo Usuário</a>
            </div>
            
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Nome</th>
                            <th>E-mail</th>
                            <th>Nível</th>
                            <th>Núcleos</th>
                            <th>Status</th>
                            <th>Último Acesso</th>
                            <th>Criado Por</th>
                            <th>Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($usuarios as $user): ?>
                        <tr>
                            <td>#<?= $user['id'] ?></td>
                            <td><?= htmlspecialchars($user['nome']) ?></td>
                            <td><?= htmlspecialchars($user['email']) ?></td>
                            <td>
                                <span class="badge badge-<?= strtolower($user['nivel_acesso']) ?>">
                                    <?= $user['nivel_acesso'] ?>
                                </span>
                            </td>
                            <td><?= $user['total_nucleos'] ?> núcleo(s)</td>
                            <td>
                                <span class="badge badge-<?= $user['ativo'] ? 'ativo' : 'inativo' ?>">
                                    <?= $user['ativo'] ? 'Ativo' : 'Inativo' ?>
                                </span>
                            </td>
                            <td><?= $user['ultimo_acesso'] ? date('d/m/Y H:i', strtotime($user['ultimo_acesso'])) : 'Nunca' ?></td>
                            <td><?= htmlspecialchars($user['criado_por_nome'] ?? 'Sistema') ?></td>
                            <td>
                                <a href="editar.php?id=<?= $user['id'] ?>" class="btn-action btn-edit">Editar</a>
                                <a href="nucleos.php?id=<?= $user['id'] ?>" class="btn-action btn-nucleos">Núcleos</a>
                                <?php if ($user['id'] != $usuario_logado['usuario_id']): ?>
                                <a href="toggle.php?id=<?= $user['id'] ?>" class="btn-action btn-delete">
                                    <?= $user['ativo'] ? 'Desativar' : 'Ativar' ?>
                                </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>