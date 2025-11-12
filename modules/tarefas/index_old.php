<?php
// modules/tarefas/index.php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';
require_once '../../includes/layout.php';

$usuario_logado = Auth::user();
$usuario_id = $usuario_logado['usuario_id'];
$nivel_acesso = $usuario_logado['nivel_acesso'];
$eh_admin = in_array($nivel_acesso, ['Admin', 'Administrador', 'Socio', 'Diretor']);

// Filtros
$filtro_status = $_GET['status'] ?? 'ativas';
$filtro_prioridade = $_GET['prioridade'] ?? '';
$filtro_tipo = $_GET['tipo'] ?? ''; // vinculada ou avulsa
$filtro_responsavel = $_GET['responsavel'] ?? ($eh_admin ? '' : $usuario_id);
$filtro_busca = $_GET['busca'] ?? '';

// Paginação
$pagina_atual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$por_pagina = 20;
$offset = ($pagina_atual - 1) * $por_pagina;

// Construir WHERE
$where = ["t.id IS NOT NULL"];
$params = [];

// Filtro de status
switch ($filtro_status) {
    case 'ativas':
        $where[] = "t.status IN ('pendente', 'em_andamento')";
        break;
    case 'pendente':
        $where[] = "t.status = 'pendente'";
        break;
    case 'em_andamento':
        $where[] = "t.status = 'em_andamento'";
        break;
    case 'concluida':
        $where[] = "t.status = 'concluida'";
        break;
    case 'atrasada':
        $where[] = "t.status IN ('pendente', 'em_andamento') AND t.data_vencimento < NOW()";
        break;
    case 'vencendo':
        $where[] = "t.status IN ('pendente', 'em_andamento') AND t.data_vencimento BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 48 HOUR)";
        break;
}

// Filtro de prioridade
if ($filtro_prioridade) {
    $where[] = "t.prioridade = ?";
    $params[] = $filtro_prioridade;
}

// Filtro de tipo
if ($filtro_tipo === 'vinculada') {
    $where[] = "t.processo_id IS NOT NULL";
} elseif ($filtro_tipo === 'avulsa') {
    $where[] = "t.processo_id IS NULL";
}

// Filtro de responsável
if ($filtro_responsavel) {
    $where[] = "t.responsavel_id = ?";
    $params[] = $filtro_responsavel;
}

// Busca
if ($filtro_busca) {
    $where[] = "(t.titulo LIKE ? OR t.descricao LIKE ?)";
    $params[] = "%{$filtro_busca}%";
    $params[] = "%{$filtro_busca}%";
}

$where_clause = implode(' AND ', $where);

// Buscar tarefas
$sql = "SELECT t.*, 
        pr.numero_processo, pr.cliente_nome,
        u.nome as responsavel_nome,
        CASE 
            WHEN t.data_vencimento < NOW() AND t.status IN ('pendente', 'em_andamento') THEN 'atrasada'
            WHEN t.data_vencimento BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 48 HOUR) AND t.status IN ('pendente', 'em_andamento') THEN 'urgente'
            ELSE 'normal'
        END as alerta
        FROM tarefas t
        LEFT JOIN processos pr ON t.processo_id = pr.id
        LEFT JOIN usuarios u ON t.responsavel_id = u.id
        WHERE {$where_clause}
        ORDER BY 
            CASE WHEN t.data_vencimento < NOW() AND t.status IN ('pendente', 'em_andamento') THEN 0 ELSE 1 END,
            t.data_vencimento ASC
        LIMIT ? OFFSET ?";

$params[] = $por_pagina;
$params[] = $offset;

$stmt = executeQuery($sql, $params);
$tarefas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Contar total
$sql_count = "SELECT COUNT(*) FROM tarefas t
              LEFT JOIN processos pr ON t.processo_id = pr.id
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
    SUM(CASE WHEN status IN ('pendente', 'em_andamento') THEN 1 ELSE 0 END) as ativas,
    SUM(CASE WHEN status IN ('pendente', 'em_andamento') AND data_vencimento < NOW() THEN 1 ELSE 0 END) as atrasadas,
    SUM(CASE WHEN status = 'concluida' THEN 1 ELSE 0 END) as concluidas
    FROM tarefas t
    WHERE 1=1 " . ($eh_admin ? "" : "AND t.responsavel_id = {$usuario_id}");
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
        border-left-color: #28a745;
    }
    
    .stat-mini:nth-child(3):hover {
        background: linear-gradient(135deg, rgba(40, 167, 69, 0.1) 0%, rgba(40, 167, 69, 0.05) 100%);
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
        color: #28a745;
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

    .tarefas-container {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        overflow: hidden;
    }

    .tarefa-card {
        padding: 20px 25px;
        border-bottom: 1px solid rgba(0,0,0,0.05);
        transition: all 0.3s;
        cursor: pointer;
        border-left: 4px solid transparent;
    }

    .tarefa-card:hover {
        background: rgba(0,0,0,0.02);
        transform: translateX(5px);
    }

    .tarefa-card:last-child {
        border-bottom: none;
    }

    .tarefa-card.atrasada {
        border-left-color: #dc3545;
        background: rgba(220, 53, 69, 0.05);
    }

    .tarefa-card.urgente {
        border-left-color: #ffc107;
        background: rgba(255, 193, 7, 0.05);
    }

    .tarefa-card.concluida {
        opacity: 0.6;
        border-left-color: #28a745;
    }

    .tarefa-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 12px;
        gap: 15px;
    }

    .tarefa-title {
        font-weight: 700;
        color: #1a1a1a;
        font-size: 16px;
        margin-bottom: 5px;
    }

    .tarefa-card.concluida .tarefa-title {
        text-decoration: line-through;
    }

    .tarefa-processo {
        color: #667eea;
        font-size: 14px;
        font-weight: 600;
    }

    .tarefa-avulsa {
        color: #999;
        font-size: 14px;
        font-style: italic;
    }

    .tarefa-meta {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
        font-size: 13px;
        color: #666;
    }

    .tarefa-meta-item {
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

    .badge-status-concluida {
        background: rgba(40, 167, 69, 0.1);
        color: #28a745;
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

    .tarefa-alerta {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }

    .tarefa-alerta.atrasada {
        background: rgba(220, 53, 69, 0.1);
        color: #dc3545;
    }

    .tarefa-alerta.urgente {
        background: rgba(255, 193, 7, 0.1);
        color: #856404;
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
    
        .tarefa-header {
            flex-direction: column;
        }
    
        .tarefa-meta {
            flex-direction: column;
            gap: 8px;
        }

        .tarefa-header {
            flex-direction: column;
        }

        .tarefa-meta {
            flex-direction: column;
            gap: 8px;
        }

        .page-header {
            flex-direction: column;
            align-items: flex-start;
        }
        
    }
</style>

<div class="page-header">
    <div class="page-header-top">
        <h2>
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M9 11l3 3L22 4"></path>
                <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
            </svg>
            Tarefas
        </h2>
    </div>
    
    <div class="stats-mini">
        <div class="stat-mini">
            <div class="stat-mini-number"><?= number_format($stats['ativas']) ?></div>
            <div class="stat-mini-label">Ativas</div>
        </div>
        <div class="stat-mini">
            <div class="stat-mini-number"><?= number_format($stats['atrasadas']) ?></div>
            <div class="stat-mini-label">Atrasadas</div>
        </div>
        <div class="stat-mini">
            <div class="stat-mini-number"><?= number_format($stats['concluidas']) ?></div>
            <div class="stat-mini-label">Concluídas</div>
        </div>
    </div>
</div>

<!-- Filtros -->
<div class="filters-section">
    <form method="GET" class="filters-grid">
        <div class="filter-group">
            <label>Status</label>
            <select name="status" onchange="this.form.submit()">
                <option value="ativas" <?= $filtro_status === 'ativas' ? 'selected' : '' ?>>Ativas</option>
                <option value="atrasada" <?= $filtro_status === 'atrasada' ? 'selected' : '' ?>>🚨 Atrasadas</option>
                <option value="vencendo" <?= $filtro_status === 'vencendo' ? 'selected' : '' ?>>⚠️ Vencendo (48h)</option>
                <option value="pendente" <?= $filtro_status === 'pendente' ? 'selected' : '' ?>>Pendente</option>
                <option value="em_andamento" <?= $filtro_status === 'em_andamento' ? 'selected' : '' ?>>Em Andamento</option>
                <option value="concluida" <?= $filtro_status === 'concluida' ? 'selected' : '' ?>>Concluída</option>
            </select>
        </div>

        <div class="filter-group">
            <label>Tipo</label>
            <select name="tipo" onchange="this.form.submit()">
                <option value="">Todas</option>
                <option value="vinculada" <?= $filtro_tipo === 'vinculada' ? 'selected' : '' ?>>Vinculada a Processo</option>
                <option value="avulsa" <?= $filtro_tipo === 'avulsa' ? 'selected' : '' ?>>Avulsa</option>
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

<!-- Lista de Tarefas -->
<div class="tarefas-container">
    <?php if (empty($tarefas)): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M9 11l3 3L22 4"></path>
                <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
            </svg>
            <h3 style="color: #666; margin-bottom: 10px;">Nenhuma tarefa encontrada</h3>
            <p style="color: #999; font-size: 14px;">Não há tarefas com os filtros selecionados</p>
        </div>
    <?php else: ?>
        <?php foreach ($tarefas as $tarefa): 
            $alerta_class = $tarefa['status'] === 'concluida' ? 'concluida' : $tarefa['alerta'];
            
            if ($tarefa['data_vencimento']) {
                $data_venc = new DateTime($tarefa['data_vencimento']);
                $hoje = new DateTime();
                $diff = $hoje->diff($data_venc);
                
                if ($data_venc < $hoje) {
                    $dias_texto = "Atrasada há " . $diff->days . " dia(s)";
                } else {
                    $dias_texto = "Vence em " . $diff->days . " dia(s)";
                }
                
                $data_formatada = $data_venc->format('d/m/Y');
            } else {
                $dias_texto = "Sem prazo definido";
                $data_formatada = "-";
            }
        ?>
        <div class="tarefa-card <?= $alerta_class ?>" 
             onclick="window.location.href='visualizar.php?id=<?= $tarefa['id'] ?>'">
            <div class="tarefa-header">
                <div>
                    <div class="tarefa-title"><?= htmlspecialchars($tarefa['titulo']) ?></div>
                    <?php if ($tarefa['processo_id']): ?>
                        <div class="tarefa-processo">
                            ⚖️ <?= htmlspecialchars($tarefa['numero_processo']) ?> - <?= htmlspecialchars($tarefa['cliente_nome']) ?>
                        </div>
                    <?php else: ?>
                        <div class="tarefa-avulsa">📌 Tarefa avulsa</div>
                    <?php endif; ?>
                </div>
                <div>
                    <?php if ($tarefa['alerta'] === 'atrasada' && $tarefa['status'] !== 'concluida'): ?>
                        <div class="tarefa-alerta atrasada">🚨 ATRASADA</div>
                    <?php elseif ($tarefa['alerta'] === 'urgente' && $tarefa['status'] !== 'concluida'): ?>
                        <div class="tarefa-alerta urgente">⚠️ URGENTE</div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="tarefa-meta">
                <?php if ($tarefa['data_vencimento']): ?>
                <div class="tarefa-meta-item">
                    📅 <strong><?= $data_formatada ?></strong> (<?= $dias_texto ?>)
                </div>
                <?php endif; ?>
                <div class="tarefa-meta-item">
                    👤 <?= htmlspecialchars($tarefa['responsavel_nome']) ?>
                </div>
                <div class="tarefa-meta-item">
                    <span class="badge badge-prioridade-<?= $tarefa['prioridade'] ?>">
                        <?= strtoupper($tarefa['prioridade']) ?>
                    </span>
                </div>
                <div class="tarefa-meta-item">
                    <span class="badge badge-status<?= $tarefa['status'] === 'concluida' ? '-concluida' : '' ?>">
                        <?= $tarefa['status'] === 'concluida' ? '✓ ' : '' ?><?= strtoupper(str_replace('_', ' ', $tarefa['status'])) ?>
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
echo renderLayout('Tarefas', $conteudo, 'tarefas');
?>