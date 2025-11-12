<?php
require_once '../../includes/auth.php';
Auth::protect(['Admin', 'Socio', 'Diretor']);

require_once '../../config/database.php';
require_once '../../includes/layout.php'; // MUDANÇA 1: Incluir o layout padronizado

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

// MUDANÇA 2: Remover a declaração manual do usuário logado (já vem do layout)

// MUDANÇA 3: Início do buffer de conteúdo
ob_start();
?>
<style>
    /* MUDANÇA 4: CSS específico apenas para esta página, sem estrutura base */
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
    
	.stats-info {
		background: linear-gradient(135deg, rgba(34, 211, 238, 0.15) 0%, rgba(103, 232, 249, 0.25) 100%);
		border-left: 4px solid #22d3ee;
		border-radius: 12px;
		padding: 18px 24px;
		margin-bottom: 20px;
		text-align: center;
		font-weight: 600;
		color: #a5f3fc;
		box-shadow: 0 2px 8px rgba(34, 211, 238, 0.2);
		transition: all 0.3s ease;
	}

	.stats-info:hover {
		box-shadow: 0 4px 16px rgba(34, 211, 238, 0.35);
		transform: translateY(-2px);
		background: linear-gradient(135deg, rgba(34, 211, 238, 0.2) 0%, rgba(103, 232, 249, 0.3) 100%);
	}
</style>

<div class="page-header">
    <h2>📊 Logs do Sistema</h2>
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

<?php
// MUDANÇA 5: Capturar o conteúdo e usar o layout padronizado
$conteudo = ob_get_clean();
echo renderLayout('Logs do Sistema', $conteudo, 'logs');
?>
