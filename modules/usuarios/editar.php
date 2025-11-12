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
$sql = "SELECT * FROM usuarios WHERE id = ?";
$stmt = executeQuery($sql, [$usuario_id]);
$usuario = $stmt->fetch();

if (!$usuario) {
    $_SESSION['erro'] = 'Usuário não encontrado';
    header('Location: index.php');
    exit;
}

// Buscar núcleos do usuário
$sql = "SELECT nucleo_id FROM usuarios_nucleos WHERE usuario_id = ?";
$stmt = executeQuery($sql, [$usuario_id]);
$nucleos_usuario = $stmt->fetchAll(PDO::FETCH_COLUMN);

// Buscar todos os núcleos disponíveis
$sql = "SELECT * FROM nucleos WHERE ativo = 1 ORDER BY nome";
$stmt = executeQuery($sql);
$nucleos = $stmt->fetchAll();

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
    <title>Editar Usuário - SIGAM</title>
    <style>
        /* Mesmo CSS do novo.php */
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
        
        .form-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.15);
            padding: 30px;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 600;
            font-size: 14px;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #1a1a1a;
            box-shadow: 0 0 0 3px rgba(26, 26, 26, 0.1);
        }
        
        .nucleos-container {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 15px;
            max-height: 200px;
            overflow-y: auto;
        }
        
        .nucleos-container h4 {
            margin-bottom: 15px;
            color: #333;
            font-size: 16px;
        }
        
        .checkbox-group {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .checkbox-group input[type="checkbox"] {
            width: auto;
            margin-right: 10px;
        }
        
        .checkbox-group label {
            margin-bottom: 0;
            font-weight: 500;
        }
        
        .btn-submit {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 15px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
            margin-right: 15px;
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
        }
        
        .btn-reset-senha {
            background: linear-gradient(135deg, #ffc107 0%, #ffb300 100%);
            color: #000;
            padding: 15px 30px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-reset-senha:hover {
            transform: translateY(-2px);
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
        
        .info-box {
            background: rgba(23, 162, 184, 0.1);
            border: 1px solid rgba(23, 162, 184, 0.3);
            color: #0c5460;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
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
                <h2>Editar Usuário: <?= htmlspecialchars($usuario['nome']) ?></h2>
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
            
            <div class="info-box">
                <strong>Informações:</strong> Deixe a senha em branco para manter a atual. 
                Para resetar a senha, use o botão "Resetar Senha" que gerará uma nova senha temporária.
            </div>
            
            <div class="form-container">
                <form action="process_editar.php" method="POST">
                    <input type="hidden" name="usuario_id" value="<?= $usuario['id'] ?>">
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="nome">Nome Completo *</label>
                            <input type="text" id="nome" name="nome" value="<?= htmlspecialchars($usuario['nome']) ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="email">E-mail *</label>
                            <input type="email" id="email" name="email" value="<?= htmlspecialchars($usuario['email']) ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="cpf">CPF</label>
                            <input type="text" id="cpf" name="cpf" value="<?= $usuario['cpf'] ? preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $usuario['cpf']) : '' ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="telefone">Telefone</label>
                            <input type="text" id="telefone" name="telefone" value="<?= $usuario['telefone'] ? preg_replace('/(\d{2})(\d{5})(\d{4})/', '($1) $2-$3', $usuario['telefone']) : '' ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="nivel_acesso">Nível de Acesso *</label>
                            <select id="nivel_acesso" name="nivel_acesso" required>
                                <option value="">Selecione...</option>
                                <option value="Assistente" <?= $usuario['nivel_acesso'] === 'Assistente' ? 'selected' : '' ?>>Assistente</option>
                                <option value="Advogado" <?= $usuario['nivel_acesso'] === 'Advogado' ? 'selected' : '' ?>>Advogado</option>
                                <option value="Gestor" <?= $usuario['nivel_acesso'] === 'Gestor' ? 'selected' : '' ?>>Gestor</option>
                                <option value="Diretor" <?= $usuario['nivel_acesso'] === 'Diretor' ? 'selected' : '' ?>>Diretor</option>
                                <option value="Socio" <?= $usuario['nivel_acesso'] === 'Socio' ? 'selected' : '' ?>>Sócio</option>
                                <?php if ($usuario_logado['nivel_acesso'] === 'Admin'): ?>
                                <option value="Admin" <?= $usuario['nivel_acesso'] === 'Admin' ? 'selected' : '' ?>>Admin</option>
                                <?php endif; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="ativo">Status</label>
                            <select id="ativo" name="ativo">
                                <option value="1" <?= $usuario['ativo'] ? 'selected' : '' ?>>Ativo</option>
                                <option value="0" <?= !$usuario['ativo'] ? 'selected' : '' ?>>Inativo</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="acesso_financeiro">Acesso Financeiro</label>
                            <select id="acesso_financeiro" name="acesso_financeiro">
                                <option value="Nenhum" <?= $usuario['acesso_financeiro'] === 'Nenhum' ? 'selected' : '' ?>>Nenhum</option>
                                <option value="Leitura" <?= $usuario['acesso_financeiro'] === 'Leitura' ? 'selected' : '' ?>>Leitura</option>
                                <option value="Completo" <?= $usuario['acesso_financeiro'] === 'Completo' ? 'selected' : '' ?>>Completo</option>
                            </select>
                        </div>
                        
                        <div class="form-group full-width">
                            <div class="checkbox-group">
                                <input type="checkbox" 
                                       id="visualiza_publicacoes_nao_vinculadas" 
                                       name="visualiza_publicacoes_nao_vinculadas" 
                                       value="1"
                                       <?= $usuario['visualiza_publicacoes_nao_vinculadas'] ? 'checked' : '' ?>>
                                <label for="visualiza_publicacoes_nao_vinculadas">
                                    <strong>Visualizar publicações sem processo vinculado</strong>
                                    <span style="display: block; font-size: 12px; color: #666; font-weight: normal; margin-top: 4px;">
                                        Se marcado, o usuário verá todas as publicações que não estão vinculadas a nenhum processo, além das publicações dos processos em que ele é responsável.
                                    </span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="form-group full-width">
                            <label>Núcleos de Acesso</label>
                            <div class="nucleos-container">
                                <h4>Selecione os núcleos que o usuário terá acesso:</h4>
                                <?php foreach ($nucleos as $nucleo): ?>
                                <div class="checkbox-group">
                                    <input type="checkbox" 
                                           id="nucleo_<?= $nucleo['id'] ?>" 
                                           name="nucleos[]" 
                                           value="<?= $nucleo['id'] ?>"
                                           <?= in_array($nucleo['id'], $nucleos_usuario) ? 'checked' : '' ?>>
                                    <label for="nucleo_<?= $nucleo['id'] ?>"><?= htmlspecialchars($nucleo['nome']) ?></label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn-submit">Salvar Alterações</button>
                    <button type="button" class="btn-reset-senha" onclick="resetarSenha(<?= $usuario['id'] ?>)">Resetar Senha</button>
                </form>
            </div>
        </div>
    </div>
    
    <script>
        // Máscara para CPF
        document.getElementById('cpf').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            value = value.replace(/(\d{3})(\d)/, '$1.$2');
            value = value.replace(/(\d{3})(\d)/, '$1.$2');
            value = value.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
            e.target.value = value;
        });
        
        // Máscara para telefone
        document.getElementById('telefone').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            value = value.replace(/(\d{2})(\d)/, '($1) $2');
            value = value.replace(/(\d{5})(\d)/, '$1-$2');
            e.target.value = value;
        });
        
        function resetarSenha(userId) {
            if (confirm('Tem certeza que deseja resetar a senha deste usuário? Uma nova senha temporária será gerada.')) {
                window.location.href = 'reset_senha.php?id=' + userId;
            }
        }
    </script>
</body>
</html>