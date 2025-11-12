<?php
// modules/prazos/index.php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';
require_once '../../includes/layout.php';

$usuario_logado = Auth::user();
$usuario_id = $usuario_logado['usuario_id'];
$nivel_acesso = $usuario_logado['nivel_acesso'];
$eh_admin = in_array($nivel_acesso, ['Admin', 'Administrador', 'Socio', 'Diretor']);

// Filtros
$filtro_status = $_GET['status'] ?? 'ativos';
$filtro_prioridade = $_GET['prioridade'] ?? '';
$filtro_processo = $_GET['processo'] ?? '';
$filtro_responsavel = $_GET['responsavel'] ?? ($eh_admin ? '' : $usuario_id);
$filtro_busca = $_GET['busca'] ?? '';

// Paginação
$pagina_atual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$por_pagina = 20;
$offset = ($pagina_atual - 1) * $por_pagina;

// Construir WHERE
$where = ["p.id IS NOT NULL"];
$params = [];

// Filtro de status
switch ($filtro_status) {
    case 'ativos':
        $where[] = "p.status IN ('pendente', 'em_andamento')";
        break;
    case 'pendente':
        $where[] = "p.status = 'pendente'";
        break;
    case 'em_andamento':
        $where[] = "p.status = 'em_andamento'";
        break;
    case 'cumprido':
        $where[] = "p.status = 'cumprido'";
        break;
    case 'vencido':
        $where[] = "p.status IN ('pendente', 'em_andamento') AND p.data_vencimento < NOW()";
        break;
    case 'urgente':
        $where[] = "p.status IN ('pendente', 'em_andamento') AND p.data_vencimento BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 48 HOUR)";
        break;
}

// Filtro de prioridade
if ($filtro_prioridade) {
    $where[] = "p.prioridade = ?";
    $params[] = $filtro_prioridade;
}

// Filtro de processo
if ($filtro_processo) {
    $where[] = "(pr.numero_processo LIKE ? OR pr.cliente_nome LIKE ?)";
    $params[] = "%{$filtro_processo}%";
    $params[] = "%{$filtro_processo}%";
}

// Filtro de responsável
if ($filtro_responsavel) {
    $where[] = "p.responsavel_id = ?";
    $params[] = $filtro_responsavel;
}

// Busca
if ($filtro_busca) {
    $where[] = "(p.titulo LIKE ? OR p.descricao LIKE ?)";
    $params[] = "%{$filtro_busca}%";
    $params[] = "%{$filtro_busca}%";
}

$where_clause = implode(' AND ', $where);

// Buscar prazos
$sql = "SELECT p.*, 
        pr.numero_processo, pr.cliente_nome,
        u.nome as responsavel_nome,
        CASE 
            WHEN p.data_vencimento < NOW() AND p.status IN ('pendente', 'em_andamento') THEN 'vencido'
            WHEN p.data_vencimento BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 48 HOUR) AND p.status IN ('pendente', 'em_andamento') THEN 'urgente'
            WHEN p.data_vencimento BETWEEN DATE_ADD(NOW(), INTERVAL 48 HOUR) AND DATE_ADD(NOW(), INTERVAL 7 DAY) AND p.status IN ('pendente', 'em_andamento') THEN 'proximo'
            ELSE 'normal'
        END as alerta
        FROM prazos p
        INNER JOIN processos pr ON p.processo_id = pr.id
        LEFT JOIN usuarios u ON p.responsavel_id = u.id
        WHERE {$where_clause}
        ORDER BY 
            CASE WHEN p.data_vencimento < NOW() THEN 0 ELSE 1 END,
            p.data_vencimento ASC
        LIMIT ? OFFSET ?";
$params[] = $por_pagina;
$params[] = $offset;

$stmt = executeQuery($sql, $params);
$prazos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Contar total
$sql_count = "SELECT COUNT(*) FROM prazos p
              INNER JOIN processos pr ON p.processo_id = pr.id
              WHERE {$where_clause}";
$stmt_count = executeQuery($sql_count, array_slice($params, 0, -2));
$total_registros = $stmt_count->fetchColumn();
$total_paginas = ceil($total_registros / $por_pagina);

// Buscar usuários para filtro
$sql_users = "SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome";
$stmt_users = executeQuery($sql_users);
$usuarios = $stmt_users->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas rápidas
$sql_stats = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status IN ('pendente', 'em_andamento') THEN 1 ELSE 0 END) as ativos,
    SUM(CASE WHEN status IN ('pendente', 'em_andamento') AND data_vencimento < NOW() THEN 1 ELSE 0 END) as vencidos,
    SUM(CASE WHEN status IN ('pendente', 'em_andamento') AND data_vencimento BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 48 HOUR) THEN 1 ELSE 0 END) as urgentes
    FROM prazos p
    WHERE 1=1 " . ($eh_admin ? "" : "AND p.responsavel_id = {$usuario_id}");
$stmt_stats = executeQuery($sql_stats);
$stats = $stmt_stats->fetch(PDO::FETCH_ASSOC);

ob_start();
?>

<style>
    .page-header {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        padding: 25px;
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        margin-bottom: 30px;
    }
    
    .page-header-top {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
        margin-bottom: 25px;
        padding-bottom: 20px;
        border-bottom: 2px solid rgba(0,0,0,0.05);
    }
    
    .page-header h2 {
        color: #1a1a1a;
        font-size: 28px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 12px;
        margin: 0;
    }
    
    .stats-mini {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 20px;
        width: 100%;
    }
    
    .stat-mini {
        text-align: center;
        padding: 20px 15px;
        background: linear-gradient(135deg, rgba(0,0,0,0.02) 0%, rgba(0,0,0,0.04) 100%);
        border-radius: 12px;
        transition: all 0.3s;
        border-left: 4px solid transparent;
        cursor: pointer;
    }
    
    .stat-mini:hover {
        background: linear-gradient(135deg, rgba(0,0,0,0.04) 0%, rgba(0,0,0,0.06) 100%);
        transform: translateY(-3px);
        box-shadow: 0 6px 20px rgba(0,0,0,0.1);
    }
    
    .stat-mini:nth-child(1) {
        border-left-color: #667eea;
    }
    
    .stat-mini:nth-child(1):hover {
        background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(102, 126, 234, 0.05) 100%);
    }
    
    .stat-mini:nth-child(2) {
        border-left-color: #dc3545;
    }
    
    .stat-mini:nth-child(2):hover {
        background: linear-gradient(135deg, rgba(220, 53, 69, 0.1) 0%, rgba(220, 53, 69, 0.05) 100%);
    }
    
    .stat-mini:nth-child(3) {
        border-left-color: #ffc107;
    }
    
    .stat-mini:nth-child(3):hover {
        background: linear-gradient(135deg, rgba(255, 193, 7, 0.1) 0%, rgba(255, 193, 7, 0.05) 100%);
    }
    
    .stat-mini-number {
        font-size: 36px;
        font-weight: 800;
        line-height: 1;
        display: block;
        margin-bottom: 10px;
        font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
    }
    
    .stat-mini:nth-child(1) .stat-mini-number {
        color: #667eea;
    }
    
    .stat-mini:nth-child(2) .stat-mini-number {
        color: #dc3545;
    }
    
    .stat-mini:nth-child(3) .stat-mini-number {
        color: #ffc107;
    }
    
    .stat-mini-label {
        font-size: 13px;
        color: #666;
        text-transform: uppercase;
        letter-spacing: 1px;
        font-weight: 700;
        display: block;
    }

    .filters-section {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        padding: 20px 25px;
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        margin-bottom: 25px;
    }

    .filters-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .filter-group label {
        font-size: 13px;
        font-weight: 600;
        color: #333;
    }

    .filter-group select,
    .filter-group input {
        padding: 10px 12px;
        border: 1px solid #ddd;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.3s;
    }

    .filter-group select:focus,
    .filter-group input:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }

    .filter-actions {
        display: flex;
        gap: 10px;
        align-items: flex-end;
    }

    .btn {
        padding: 10px 20px;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        font-size: 14px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .btn-primary {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
    }

    .btn-secondary {
        background: #6c757d;
        color: white;
    }

    .btn-secondary:hover {
        background: #5a6268;
    }

    .prazos-container {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        overflow: hidden;
    }

    .prazo-card {
        padding: 20px 25px;
        border-bottom: 1px solid rgba(0,0,0,0.05);
        transition: all 0.3s;
        cursor: pointer;
        border-left: 4px solid transparent;
    }

    .prazo-card:hover {
        background: rgba(0,0,0,0.02);
        transform: translateX(5px);
    }

    .prazo-card:last-child {
        border-bottom: none;
    }

    .prazo-card.vencido {
        border-left-color: #dc3545;
        background: rgba(220, 53, 69, 0.05);
    }

    .prazo-card.urgente {
        border-left-color: #ffc107;
        background: rgba(255, 193, 7, 0.05);
    }

    .prazo-card.proximo {
        border-left-color: #17a2b8;
        background: rgba(23, 162, 184, 0.05);
    }

    .prazo-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 12px;
        gap: 15px;
    }

    .prazo-title {
        font-weight: 700;
        color: #1a1a1a;
        font-size: 16px;
        margin-bottom: 5px;
    }

    .prazo-processo {
        color: #667eea;
        font-size: 14px;
        font-weight: 600;
    }

    .prazo-meta {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
        font-size: 13px;
        color: #666;
    }

    .prazo-meta-item {
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .badge {
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
    }

    .badge-status {
        background: rgba(102, 126, 234, 0.1);
        color: #667eea;
    }

    .badge-prioridade-urgente {
        background: rgba(220, 53, 69, 0.1);
        color: #dc3545;
    }

    .badge-prioridade-alta {
        background: rgba(255, 193, 7, 0.1);
        color: #ffc107;
    }

    .badge-prioridade-normal {
        background: rgba(102, 126, 234, 0.1);
        color: #667eea;
    }

    .badge-prioridade-baixa {
        background: rgba(108, 117, 125, 0.1);
        color: #6c757d;
    }

    .prazo-alerta {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }

    .prazo-alerta.vencido {
        background: rgba(220, 53, 69, 0.1);
        color: #dc3545;
    }

    .prazo-alerta.urgente {
        background: rgba(255, 193, 7, 0.1);
        color: #856404;
    }

    .prazo-alerta.proximo {
        background: rgba(23, 162, 184, 0.1);
        color: #0c5460;
    }

    .empty-state {
        padding: 60px 20px;
        text-align: center;
    }

    .empty-state svg {
        width: 80px;
        height: 80px;
        opacity: 0.2;
        margin-bottom: 20px;
    }

    .pagination {
        padding: 20px 25px;
        display: flex;
        justify-content: center;
        gap: 8px;
        border-top: 1px solid rgba(0,0,0,0.05);
    }

    .pagination a,
    .pagination span {
        padding: 8px 12px;
        border-radius: 6px;
        text-decoration: none;
        color: #667eea;
        font-weight: 600;
        font-size: 13px;
        transition: all 0.3s;
    }

    .pagination a:hover {
        background: rgba(102, 126, 234, 0.1);
    }

    .pagination .active {
        background: #667eea;
        color: white;
    }

   @media (max-width: 768px) {
        .page-header-top {
            flex-direction: column;
            align-items: flex-start;
            padding-bottom: 15px;
        }
        
        .page-header h2 {
            font-size: 24px;
        }
        
        .stats-mini {
            grid-template-columns: 1fr;
            gap: 12px;
        }
        
        .stat-mini {
            display: flex;
            justify-content: space-between;
            align-items: center;
            text-align: left;
            padding: 15px 20px;
        }
        
        .stat-mini-number {
            font-size: 32px;
            margin-bottom: 0;
            margin-right: 15px;
        }
        
        .stat-mini-label {
            font-size: 12px;
            text-align: right;
        }
    
        .filters-grid {
            grid-template-columns: 1fr;
        }
    
        .prazo-header {
            flex-direction: column;
        }
    
        .prazo-meta {
            flex-direction: column;
            gap: 8px;
        }
    }
</style>

<div class="page-header">
    <div class="page-header-top">
        <h2>
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"></circle>
                <polyline points="12 6 12 12 16 14"></polyline>
            </svg>
            Prazos Processuais
        </h2>
    </div>
    
    <div class="stats-mini">
        <div class="stat-mini">
            <div class="stat-mini-number"><?= number_format($stats['ativos']) ?></div>
            <div class="stat-mini-label">Ativos</div>
        </div>
        <div class="stat-mini">
            <div class="stat-mini-number"><?= number_format($stats['vencidos']) ?></div>
            <div class="stat-mini-label">Vencidos</div>
        </div>
        <div class="stat-mini">
            <div class="stat-mini-number"><?= number_format($stats['urgentes']) ?></div>
            <div class="stat-mini-label">Urgentes (48h)</div>
        </div>
    </div>
</div>

<!-- Filtros -->
<div class="filters-section">
    <form method="GET" class="filters-grid">
        <div class="filter-group">
            <label>Status</label>
            <select name="status" onchange="this.form.submit()">
                <option value="ativos" <?= $filtro_status === 'ativos' ? 'selected' : '' ?>>Ativos</option>
                <option value="urgente" <?= $filtro_status === 'urgente' ? 'selected' : '' ?>>🚨 Urgentes (48h)</option>
                <option value="vencido" <?= $filtro_status === 'vencido' ? 'selected' : '' ?>>⏰ Vencidos</option>
                <option value="pendente" <?= $filtro_status === 'pendente' ? 'selected' : '' ?>>Pendente</option>
                <option value="em_andamento" <?= $filtro_status === 'em_andamento' ? 'selected' : '' ?>>Em Andamento</option>
                <option value="cumprido" <?= $filtro_status === 'cumprido' ? 'selected' : '' ?>>Cumprido</option>
            </select>
        </div>

        <div class="filter-group">
            <label>Prioridade</label>
            <select name="prioridade" onchange="this.form.submit()">
                <option value="">Todas</option>
                <option value="urgente" <?= $filtro_prioridade === 'urgente' ? 'selected' : '' ?>>Urgente</option>
                <option value="alta" <?= $filtro_prioridade === 'alta' ? 'selected' : '' ?>>Alta</option>
                <option value="normal" <?= $filtro_prioridade === 'normal' ? 'selected' : '' ?>>Normal</option>
                <option value="baixa" <?= $filtro_prioridade === 'baixa' ? 'selected' : '' ?>>Baixa</option>
            </select>
        </div>

        <?php if ($eh_admin): ?>
        <div class="filter-group">
            <label>Responsável</label>
            <select name="responsavel" onchange="this.form.submit()">
                <option value="">Todos</option>
                <?php foreach ($usuarios as $user): ?>
                    <option value="<?= $user['id'] ?>" <?= $filtro_responsavel == $user['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($user['nome']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>

        <div class="filter-group">
            <label>Processo/Cliente</label>
            <input type="text" name="processo" value="<?= htmlspecialchars($filtro_processo) ?>" 
                   placeholder="Buscar processo...">
        </div>

        <div class="filter-group">
            <label>Buscar</label>
            <input type="text" name="busca" value="<?= htmlspecialchars($filtro_busca) ?>" 
                   placeholder="Título ou descrição...">
        </div>

        <div class="filter-actions">
            <button type="submit" class="btn btn-primary">
                🔍 Filtrar
            </button>
            <a href="index.php" class="btn btn-secondary">
                🔄 Limpar
            </a>
        </div>
    </form>
</div>

<!-- Lista de Prazos -->
<div class="prazos-container">
    <?php if (empty($prazos)): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"></circle>
                <polyline points="12 6 12 12 16 14"></polyline>
            </svg>
            <h3 style="color: #666; margin-bottom: 10px;">Nenhum prazo encontrado</h3>
            <p style="color: #999; font-size: 14px;">Não há prazos com os filtros selecionados</p>
        </div>
    <?php else: ?>
        <?php foreach ($prazos as $prazo): 
            $data_venc = new DateTime($prazo['data_vencimento']);
            $hoje = new DateTime();
            $diff = $hoje->diff($data_venc);
            
            if ($data_venc < $hoje) {
                $dias_texto = "Vencido há " . $diff->days . " dia(s)";
            } else {
                $dias_texto = "Vence em " . $diff->days . " dia(s)";
            }
            
            $data_formatada = $data_venc->format('d/m/Y');
        ?>
        <div class="prazo-card <?= $prazo['alerta'] ?>" 
             onclick="window.location.href='visualizar.php?id=<?= $prazo['id'] ?>'">
            <div class="prazo-header">
                <div>
                    <div class="prazo-title"><?= htmlspecialchars($prazo['titulo']) ?></div>
                    <div class="prazo-processo">
                        ⚖️ <?= htmlspecialchars($prazo['numero_processo']) ?> - <?= htmlspecialchars($prazo['cliente_nome']) ?>
                    </div>
                </div>
                <div>
                    <?php if ($prazo['alerta'] === 'vencido'): ?>
                        <div class="prazo-alerta vencido">🚨 VENCIDO</div>
                    <?php elseif ($prazo['alerta'] === 'urgente'): ?>
                        <div class="prazo-alerta urgente">⚠️ URGENTE</div>
                    <?php elseif ($prazo['alerta'] === 'proximo'): ?>
                        <div class="prazo-alerta proximo">📅 PRÓXIMO</div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="prazo-meta">
                <div class="prazo-meta-item">
                    📅 <strong><?= $data_formatada ?></strong> (<?= $dias_texto ?>)
                </div>
                <div class="prazo-meta-item">
                    👤 <?= htmlspecialchars($prazo['responsavel_nome']) ?>
                </div>
                <div class="prazo-meta-item">
                    <span class="badge badge-prioridade-<?= $prazo['prioridade'] ?>">
                        <?= strtoupper($prazo['prioridade']) ?>
                    </span>
                </div>
                <div class="prazo-meta-item">
                    <span class="badge badge-status">
                        <?= strtoupper(str_replace('_', ' ', $prazo['status'])) ?>
                    </span>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        
        <!-- Paginação -->
        <?php if ($total_paginas > 1): ?>
        <div class="pagination">
            <?php if ($pagina_atual > 1): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina_atual - 1])) ?>">« Anterior</a>
            <?php endif; ?>
            
            <?php for ($i = 1; $i <= min($total_paginas, 10); $i++): ?>
                <?php if ($i == $pagina_atual): ?>
                    <span class="active"><?= $i ?></span>
                <?php else: ?>
                    <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $i])) ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            
            <?php if ($pagina_atual < $total_paginas): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina_atual + 1])) ?>">Próxima »</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Prazos Processuais', $conteudo, 'prazos');
?>