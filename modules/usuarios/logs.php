<?php
require_once '../../includes/auth.php';
Auth::protect(['Admin', 'Socio', 'Diretor']);

require_once '../../config/database.php';

// Paginação
$page = $_GET['page'] ?? 1;
$limit = 50;
$offset = ($page - 1) * $limit;

// Filtros
$usuario_filtro = $_GET['usuario'] ?? '';
$acao_filtro = $_GET['acao'] ?? '';
$data_inicio = $_GET['data_inicio'] ?? '';
$data_fim = $_GET['data_fim'] ?? '';

// Construir query com filtros
$where_conditions = [];
$params = [];

if (!empty($usuario_filtro)) {
    $where_conditions[] = "u.nome LIKE ?";
    $params[] = "%$usuario_filtro%";
}

if (!empty($acao_filtro)) {
    $where_conditions[] = "l.acao LIKE ?";
    $params[] = "%$acao_filtro%";
}

if (!empty($data_inicio)) {
    $where_conditions[] = "DATE(l.data_hora) >= ?";
    $params[] = $data_inicio;
}

if (!empty($data_fim)) {
    $where_conditions[] = "DATE(l.data_hora) <= ?";
    $params[] = $data_fim;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Buscar logs
$sql = "SELECT l.*, u.nome as usuario_nome 
        FROM logs_sistema l 
        LEFT JOIN usuarios u ON l.usuario_id = u.id 
        $where_clause 
        ORDER BY l.data_hora DESC 
        LIMIT $limit OFFSET $offset";

$stmt = executeQuery($sql, $params);
$logs = $stmt->fetchAll();

// Contar total para paginação
$count_sql = "SELECT COUNT(*) as total 
              FROM logs_sistema l 
              LEFT JOIN usuarios u ON l.usuario_id = u.id 
              $where_clause";

$stmt = executeQuery($count_sql, $params);
$total_logs = $stmt->fetch()['total'];
$total_pages = ceil($total_logs / $limit);

$usuario_logado = Auth::user();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Logs do Sistema - SIGAM</title>
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
        
        .filters-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.15);
            padding: 25px;
            margin-bottom: 30px;
        }
        
        .filters-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-group label {
            margin-bottom: 5px;
            color: #333;
            font-weight: 600;
            font-size: 14px;
        }
        
        .filter-group input,
        .filter-group select {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        
        .btn-filter {
            background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-filter:hover {
            transform: translateY(-2px);
        }
        
        .btn-clear {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
            color: white;
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-left: 10px;
        }
        
        .btn-clear:hover {
            transform: translateY(-2px);
        }
        
        .table-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.15);
            overflow: hidden;
            margin-bottom: 30px;
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
            font-size: 14px;
        }
        
        tr:hover {
            background: rgba(26, 26, 26, 0.02);
        }
        
        .badge {
            padding: 4px 8px;
            border-radius: 15px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .badge-login { background: #28a745; color: white; }
        .badge-logout { background: #dc3545; color: white; }
        .badge-criar { background: #007bff; color: white; }
        .badge-editar { background: #ffc107; color: #000; }
        .badge-deletar { background: #dc3545; color: white; }
        .badge-default { background: #6c757d; color: white; }
        
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.15);
            padding: 20px;
        }
        
        .pagination a,
        .pagination span {
            padding: 10px 15px;
            text-decoration: none;
            border-radius: 5px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .pagination a {
            background: rgba(26, 26, 26, 0.05);
            color: #333;
        }
        
        .pagination a:hover {
            background: rgba(26, 26, 26, 0.1);
            transform: translateY(-1px);
        }
        
        .pagination .current {
            background: linear-gradient(135deg, #1a1a1a 0%, #333 100%);
            color: white;
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
        
        .stats-info {
            background: rgba(23, 162, 184, 0.1);
            border: 1px solid rgba(23, 162, 184, 0.3);
            color: #0c5460;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: 600;
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
            <a href="index.php" class="menu-item">Gerenciar Usuários</a>
            <a href="logs.php" class="menu-item active">Logs do Sistema</a>
        </div>
        
        <div class="content">
            <div class="page-header">
                <h2>Logs do Sistema</h2>
                <a href="index.php" class="btn-voltar">← Voltar</a>
            </div>
            
            <div class="stats-info">
                Exibindo <?= count($logs) ?> de <?= $total_logs ?> registros encontrados
            </div>
            
            <div class="filters-container">
                <form method="GET">
                    <div class="filters-grid">
                        <div class="filter-group">
                            <label for="usuario">Usuário:</label>
                            <input type="text" id="usuario" name="usuario" value="<?= htmlspecialchars($usuario_filtro) ?>" placeholder="Nome do usuário">
                        </div>
                        
                        <div class="filter-group">
                            <label for="acao">Ação:</label>
                            <input type="text" id="acao" name="acao" value="<?= htmlspecialchars($acao_filtro) ?>" placeholder="Tipo de ação">
                        </div>
                        
                        <div class="filter-group">
                            <label for="data_inicio">Data Início:</label>
                            <input type="date" id="data_inicio" name="data_inicio" value="<?= htmlspecialchars($data_inicio) ?>">
                        </div>
                        
                        <div class="filter-group">
                            <label for="data_fim">Data Fim:</label>
                            <input type="date" id="data_fim" name="data_fim" value="<?= htmlspecialchars($data_fim) ?>">
                        </div>
                    </div>
                    
                    <div>
                        <button type="submit" class="btn-filter">Filtrar</button>
                        <a href="logs.php" class="btn-clear">Limpar Filtros</a>
                    </div>
                </form>
            </div>
            
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Data/Hora</th>
                            <th>Usuário</th>
                            <th>Ação</th>
                            <th>Descrição</th>
                            <th>IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 40px; color: #666;">
                                Nenhum log encontrado com os filtros aplicados
                            </td>
                        </tr>
                        <?php else: ?>
                            <?php foreach ($logs as $log): ?>
                            <tr>
                                <td><?= date('d/m/Y H:i:s', strtotime($log['data_hora'])) ?></td>
                                <td><?= htmlspecialchars($log['usuario_nome'] ?? 'Sistema') ?></td>
                                <td>
                                    <?php
                                    $acao_lower = strtolower($log['acao']);
                                    $badge_class = 'badge-default';
                                    
                                    if (strpos($acao_lower, 'login') !== false) $badge_class = 'badge-login';
                                    elseif (strpos($acao_lower, 'logout') !== false) $badge_class = 'badge-logout';
                                    elseif (strpos($acao_lower, 'criar') !== false) $badge_class = 'badge-criar';
                                    elseif (strpos($acao_lower, 'editar') !== false) $badge_class = 'badge-editar';
                                    elseif (strpos($acao_lower, 'deletar') !== false || strpos($acao_lower, 'remover') !== false) $badge_class = 'badge-deletar';
                                    ?>
                                    <span class="badge <?= $badge_class ?>">
                                        <?= htmlspecialchars($log['acao']) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($log['descricao']) ?></td>
                                <td><?= htmlspecialchars($log['ip_address']) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=1<?= !empty($_GET) ? '&' . http_build_query(array_diff_key($_GET, ['page' => ''])) : '' ?>">« Primeira</a>
                    <a href="?page=<?= $page - 1 ?><?= !empty($_GET) ? '&' . http_build_query(array_diff_key($_GET, ['page' => ''])) : '' ?>">‹ Anterior</a>
                <?php endif; ?>
                
                <?php
                $start = max(1, $page - 2);
                $end = min($total_pages, $page + 2);
                
                for ($i = $start; $i <= $end; $i++):
                ?>
                    <?php if ($i == $page): ?>
                        <span class="current"><?= $i ?></span>
                    <?php else: ?>
                        <a href="?page=<?= $i ?><?= !empty($_GET) ? '&' . http_build_query(array_diff_key($_GET, ['page' => ''])) : '' ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $total_pages): ?>
                    <a href="?page=<?= $page + 1 ?><?= !empty($_GET) ? '&' . http_build_query(array_diff_key($_GET, ['page' => ''])) : '' ?>">Próxima ›</a>
                    <a href="?page=<?= $total_pages ?><?= !empty($_GET) ? '&' . http_build_query(array_diff_key($_GET, ['page' => ''])) : '' ?>">Última »</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>