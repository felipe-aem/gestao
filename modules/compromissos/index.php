<?php
// modules/compromissos/index.php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';
require_once '../../includes/layout.php';

$usuario_logado = Auth::user();
$usuario_id = $usuario_logado['usuario_id'];
$nivel_acesso = $usuario_logado['nivel_acesso'];
$eh_admin = in_array($nivel_acesso, ['Admin', 'Administrador', 'Socio', 'Diretor']);

// Filtros
$filtro_tipo = $_GET['tipo'] ?? ''; // prazo, tarefa, audiencia
$filtro_status = $_GET['status'] ?? 'ativos';
$filtro_periodo = $_GET['periodo'] ?? 'todos'; // hoje, semana, mes
$filtro_responsavel = $_GET['responsavel'] ?? ($eh_admin ? '' : $usuario_id);
$filtro_busca = $_GET['busca'] ?? '';

// Paginação
$pagina_atual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
$por_pagina = 30;
$offset = ($pagina_atual - 1) * $por_pagina;

// Array para armazenar todos os compromissos
$compromissos = [];

// Buscar PRAZOS
if (empty($filtro_tipo) || $filtro_tipo === 'prazo') {
    $where_prazo = ["1=1"];
    $params_prazo = [];
    
    if ($filtro_status === 'ativos') {
        $where_prazo[] = "p.status IN ('pendente', 'em_andamento')";
    } elseif ($filtro_status === 'concluido') {
        $where_prazo[] = "p.status = 'cumprido'";
    }
    
    if ($filtro_periodo === 'hoje') {
        $where_prazo[] = "DATE(p.data_vencimento) = CURDATE()";
    } elseif ($filtro_periodo === 'semana') {
        $where_prazo[] = "p.data_vencimento BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)";
    } elseif ($filtro_periodo === 'mes') {
        $where_prazo[] = "p.data_vencimento BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)";
    }
    
    if ($filtro_responsavel) {
        $where_prazo[] = "p.responsavel_id = ?";
        $params_prazo[] = $filtro_responsavel;
    }
    
    if ($filtro_busca) {
        $where_prazo[] = "(p.titulo LIKE ? OR p.descricao LIKE ?)";
        $params_prazo[] = "%{$filtro_busca}%";
        $params_prazo[] = "%{$filtro_busca}%";
    }
    
    $where_prazo_clause = implode(' AND ', $where_prazo);
    
    $sql_prazos = "SELECT 
        'prazo' as tipo,
        p.id,
        p.titulo,
        p.descricao,
        p.data_vencimento as data_compromisso,
        p.status,
        p.prioridade,
        p.processo_id,
        pr.numero_processo,
        pr.cliente_nome,
        u.nome as responsavel_nome,
        CASE 
            WHEN p.data_vencimento < NOW() AND p.status IN ('pendente', 'em_andamento') THEN 'vencido'
            WHEN p.data_vencimento BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 48 HOUR) AND p.status IN ('pendente', 'em_andamento') THEN 'urgente'
            ELSE 'normal'
        END as alerta
        FROM prazos p
        INNER JOIN processos pr ON p.processo_id = pr.id
        LEFT JOIN usuarios u ON p.responsavel_id = u.id
        WHERE {$where_prazo_clause}";
    
    $stmt = executeQuery($sql_prazos, $params_prazo);
    $compromissos = array_merge($compromissos, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

// Buscar TAREFAS
if (empty($filtro_tipo) || $filtro_tipo === 'tarefa') {
    $where_tarefa = ["1=1"];
    $params_tarefa = [];
    
    if ($filtro_status === 'ativos') {
        $where_tarefa[] = "t.status IN ('pendente', 'em_andamento')";
    } elseif ($filtro_status === 'concluido') {
        $where_tarefa[] = "t.status = 'concluida'";
    }
    
    if ($filtro_periodo === 'hoje') {
        $where_tarefa[] = "DATE(t.data_vencimento) = CURDATE()";
    } elseif ($filtro_periodo === 'semana') {
        $where_tarefa[] = "t.data_vencimento BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)";
    } elseif ($filtro_periodo === 'mes') {
        $where_tarefa[] = "t.data_vencimento BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)";
    }
    
    if ($filtro_responsavel) {
        $where_tarefa[] = "t.responsavel_id = ?";
        $params_tarefa[] = $filtro_responsavel;
    }
    
    if ($filtro_busca) {
        $where_tarefa[] = "(t.titulo LIKE ? OR t.descricao LIKE ?)";
        $params_tarefa[] = "%{$filtro_busca}%";
        $params_tarefa[] = "%{$filtro_busca}%";
    }
    
    $where_tarefa_clause = implode(' AND ', $where_tarefa);
    
    $sql_tarefas = "SELECT 
        'tarefa' as tipo,
        t.id,
        t.titulo,
        t.descricao,
        t.data_vencimento as data_compromisso,
        t.status,
        t.prioridade,
        t.processo_id,
        pr.numero_processo,
        pr.cliente_nome,
        u.nome as responsavel_nome,
        CASE 
            WHEN t.data_vencimento < NOW() AND t.status IN ('pendente', 'em_andamento') THEN 'atrasada'
            WHEN t.data_vencimento BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 48 HOUR) AND t.status IN ('pendente', 'em_andamento') THEN 'urgente'
            ELSE 'normal'
        END as alerta
        FROM tarefas t
        LEFT JOIN processos pr ON t.processo_id = pr.id
        LEFT JOIN usuarios u ON t.responsavel_id = u.id
        WHERE {$where_tarefa_clause} AND t.data_vencimento IS NOT NULL";
    
    $stmt = executeQuery($sql_tarefas, $params_tarefa);
    $compromissos = array_merge($compromissos, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

// Buscar AUDIÊNCIAS
if (empty($filtro_tipo) || $filtro_tipo === 'audiencia') {
    $where_aud = ["1=1"];
    $params_aud = [];
    
    if ($filtro_status === 'ativos') {
        $where_aud[] = "a.status = 'agendada'";
    } elseif ($filtro_status === 'concluido') {
        $where_aud[] = "a.status = 'realizada'";
    }
    
    if ($filtro_periodo === 'hoje') {
        $where_aud[] = "DATE(a.data_inicio) = CURDATE()";
    } elseif ($filtro_periodo === 'semana') {
        $where_aud[] = "a.data_inicio BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)";
    } elseif ($filtro_periodo === 'mes') {
        $where_aud[] = "a.data_inicio BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 30 DAY)";
    }
    
    if ($filtro_responsavel) {
        $where_aud[] = "a.responsavel_id = ?";
        $params_aud[] = $filtro_responsavel;
    }
    
    if ($filtro_busca) {
        $where_aud[] = "(a.titulo LIKE ? OR a.descricao LIKE ?)";
        $params_aud[] = "%{$filtro_busca}%";
        $params_aud[] = "%{$filtro_busca}%";
    }
    
    $where_aud_clause = implode(' AND ', $where_aud);
    
    $sql_audiencias = "SELECT 
        'audiencia' as tipo,
        a.id,
        a.titulo,
        a.descricao,
        a.data_inicio as data_compromisso,
        a.status,
        a.prioridade,
        a.processo_id,
        pr.numero_processo,
        pr.cliente_nome,
        u.nome as responsavel_nome,
        CASE 
            WHEN a.data_inicio BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 24 HOUR) THEN 'urgente'
            ELSE 'normal'
        END as alerta
        FROM audiencias a
        INNER JOIN processos pr ON a.processo_id = pr.id
        LEFT JOIN usuarios u ON a.responsavel_id = u.id
        WHERE {$where_aud_clause}";
    
    $stmt = executeQuery($sql_audiencias, $params_aud);
    $compromissos = array_merge($compromissos, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

// Ordenar por data
usort($compromissos, function($a, $b) {
    return strtotime($a['data_compromisso']) - strtotime($b['data_compromisso']);
});

// Aplicar paginação
$total_registros = count($compromissos);
$total_paginas = ceil($total_registros / $por_pagina);
$compromissos = array_slice($compromissos, $offset, $por_pagina);

// Buscar usuários para filtro
$sql_users = "SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome";
$stmt_users = executeQuery($sql_users);
$usuarios = $stmt_users->fetchAll(PDO::FETCH_ASSOC);

// Estatísticas
$stats = [
    'total' => $total_registros,
    'hoje' => 0,
    'semana' => 0,
    'vencidos' => 0
];

foreach ($compromissos as $comp) {
    $data = new DateTime($comp['data_compromisso']);
    $hoje = new DateTime();
    
    if ($data->format('Y-m-d') === $hoje->format('Y-m-d')) {
        $stats['hoje']++;
    }
    
    if ($data <= (clone $hoje)->modify('+7 days')) {
        $stats['semana']++;
    }
    
    if ($data < $hoje && in_array($comp['alerta'], ['vencido', 'atrasada'])) {
        $stats['vencidos']++;
    }
}

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
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
    }
    
    .page-header h2 {
        color: #1a1a1a;
        font-size: 24px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .view-toggle {
        display: flex;
        gap: 10px;
    }

    .view-btn {
        padding: 10px 20px;
        background: rgba(255, 255, 255, 0.8);
        border: 2px solid #ddd;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        text-decoration: none;
        color: #333;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .view-btn.active {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-color: #667eea;
    }

    .view-btn:hover {
        transform: translateY(-2px);
    }

    .stats-mini {
        display: flex;
        gap: 30px;
        flex-wrap: wrap;
        align-items: center;
    }

    .stat-mini {
        text-align: center;
        min-width: 80px;
    }

    .stat-mini-number {
        font-size: 32px;
        font-weight: 700;
        color: #1a1a1a;
        line-height: 1;
        display: block;
        margin-bottom: 5px;
    }

    .stat-mini-label {
        font-size: 11px;
        color: #666;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        font-weight: 600;
        display: block;
    }

    .stat-mini:nth-child(2) .stat-mini-number {
        color: #667eea;
    }

    .stat-mini:nth-child(3) .stat-mini-number {
        color: #dc3545;
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
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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

    .compromissos-container {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        overflow: hidden;
    }

    .compromisso-card {
        padding: 20px 25px;
        border-bottom: 1px solid rgba(0,0,0,0.05);
        transition: all 0.3s;
        cursor: pointer;
        border-left: 4px solid transparent;
    }

    .compromisso-card:hover {
        background: rgba(0,0,0,0.02);
        transform: translateX(5px);
    }

    .compromisso-card:last-child {
        border-bottom: none;
    }

    .compromisso-card.prazo {
        border-left-color: #ffc107;
    }

    .compromisso-card.tarefa {
        border-left-color: #667eea;
    }

    .compromisso-card.audiencia {
        border-left-color: #28a745;
    }

    .compromisso-card.vencido,
    .compromisso-card.atrasada {
        border-left-color: #dc3545;
        background: rgba(220, 53, 69, 0.05);
    }

    .compromisso-card.urgente {
        background: rgba(255, 193, 7, 0.05);
    }

    .compromisso-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 12px;
        gap: 15px;
    }

    .compromisso-tipo-badge {
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        display: inline-block;
        margin-bottom: 5px;
    }

    .tipo-prazo {
        background: rgba(255, 193, 7, 0.1);
        color: #856404;
    }

    .tipo-tarefa {
        background: rgba(102, 126, 234, 0.1);
        color: #667eea;
    }

    .tipo-audiencia {
        background: rgba(40, 167, 69, 0.1);
        color: #155724;
    }

    .compromisso-title {
        font-weight: 700;
        color: #1a1a1a;
        font-size: 16px;
        margin-bottom: 5px;
    }

    .compromisso-processo {
        color: #667eea;
        font-size: 14px;
        font-weight: 600;
    }

    .compromisso-meta {
        display: flex;
        gap: 20px;
        flex-wrap: wrap;
        font-size: 13px;
        color: #666;
    }

    .compromisso-meta-item {
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

    .compromisso-alerta {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }

    .compromisso-alerta.vencido,
    .compromisso-alerta.atrasada {
        background: rgba(220, 53, 69, 0.1);
        color: #dc3545;
    }

    .compromisso-alerta.urgente {
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
        .filters-grid {
            grid-template-columns: 1fr;
        }

        .stats-mini {
            width: 100%;
            justify-content: space-around;
        }

        .compromisso-header {
            flex-direction: column;
        }

        .compromisso-meta {
            flex-direction: column;
            gap: 8px;
        }

        .page-header {
            flex-direction: column;
            align-items: flex-start;
        }
        
        .view-toggle {
            width: 100%;
        }

        .view-btn {
            flex: 1;
            justify-content: center;
        }
    }
</style>

<div class="page-header">
    <div>
        <h2>
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                <line x1="16" y1="2" x2="16" y2="6"></line>
                <line x1="8" y1="2" x2="8" y2="6"></line>
                <line x1="3" y1="10" x2="21" y2="10"></line>
            </svg>
            Compromissos
        </h2>
        <div class="stats-mini">
            <div class="stat-mini">
                <div class="stat-mini-number"><?= $stats['hoje'] ?></div>
                <div class="stat-mini-label">Hoje</div>
            </div>
            <div class="stat-mini">
                <div class="stat-mini-number"><?= $stats['semana'] ?></div>
                <div class="stat-mini-label">Esta Semana</div>
            </div>
            <div class="stat-mini">
                <div class="stat-mini-number"><?= $stats['vencidos'] ?></div>
                <div class="stat-mini-label">Vencidos</div>
            </div>
        </div>
    </div>
    <div class="view-toggle">
        <a href="index.php" class="view-btn active">
            📋 Lista
        </a>
        <a href="calendario.php" class="view-btn">
            📅 Calendário
        </a>
    </div>
</div>

<!-- Filtros -->
<div class="filters-section">
    <form method="GET" class="filters-grid">
        <div class="filter-group">
            <label>Tipo</label>
            <select name="tipo" onchange="this.form.submit()">
                <option value="">Todos</option>
                <option value="prazo" <?= $filtro_tipo === 'prazo' ? 'selected' : '' ?>>⏰ Prazos</option>
                <option value="tarefa" <?= $filtro_tipo === 'tarefa' ? 'selected' : '' ?>>✓ Tarefas</option>
                <option value="audiencia" <?= $filtro_tipo === 'audiencia' ? 'selected' : '' ?>>📅 Audiências</option>
            </select>
        </div>

        <div class="filter-group">
            <label>Status</label>
            <select name="status" onchange="this.form.submit()">
                <option value="ativos" <?= $filtro_status === 'ativos' ? 'selected' : '' ?>>Ativos</option>
                <option value="concluido" <?= $filtro_status === 'concluido' ? 'selected' : '' ?>>Concluídos</option>
            </select>
        </div>

        <div class="filter-group">
            <label>Período</label>
            <select name="periodo" onchange="this.form.submit()">
                <option value="todos" <?= $filtro_periodo === 'todos' ? 'selected' : '' ?>>Todos</option>
                <option value="hoje" <?= $filtro_periodo === 'hoje' ? 'selected' : '' ?>>Hoje</option>
                <option value="semana" <?= $filtro_periodo === 'semana' ? 'selected' : '' ?>>Esta Semana</option>
                <option value="mes" <?= $filtro_periodo === 'mes' ? 'selected' : '' ?>>Este Mês</option>
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
                   placeholder="Buscar...">
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

<!-- Lista de Compromissos -->
<div class="compromissos-container">
    <?php if (empty($compromissos)): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                <line x1="16" y1="2" x2="16" y2="6"></line>
                <line x1="8" y1="2" x2="8" y2="6"></line>
                <line x1="3" y1="10" x2="21" y2="10"></line>
            </svg>
            <h3 style="color: #666; margin-bottom: 10px;">Nenhum compromisso encontrado</h3>
            <p style="color: #999; font-size: 14px;">Não há compromissos com os filtros selecionados</p>
        </div>
    <?php else: ?>
        <?php foreach ($compromissos as $comp): 
            $data = new DateTime($comp['data_compromisso']);
            $hoje = new DateTime();
            $diff = $hoje->diff($data);
            
            if ($data < $hoje) {
                $dias_texto = "Há " . $diff->days . " dia(s)";
            } elseif ($data->format('Y-m-d') === $hoje->format('Y-m-d')) {
                $dias_texto = "Hoje às " . $data->format('H:i');
            } else {
                $dias_texto = "Em " . $diff->days . " dia(s)";
            }
            
            $data_formatada = $data->format('d/m/Y H:i');
            
            // Determinar link
            $link = match($comp['tipo']) {
                'prazo' => '../prazos/visualizar.php?id=' . $comp['id'],
                'tarefa' => '../tarefas/visualizar.php?id=' . $comp['id'],
                'audiencia' => '../audiencias/visualizar.php?id=' . $comp['id'],
                default => '#'
            };
        ?>
        <div class="compromisso-card <?= $comp['tipo'] ?> <?= $comp['alerta'] ?>" 
             onclick="window.location.href='<?= $link ?>'">
            <div class="compromisso-header">
                <div>
                    <span class="compromisso-tipo-badge tipo-<?= $comp['tipo'] ?>">
                        <?php
                        echo match($comp['tipo']) {
                            'prazo' => '⏰ PRAZO',
                            'tarefa' => '✓ TAREFA',
                            'audiencia' => '📅 AUDIÊNCIA',
                            default => $comp['tipo']
                        };
                        ?>
                    </span>
                    <div class="compromisso-title"><?= htmlspecialchars($comp['titulo']) ?></div>
                    <?php if ($comp['processo_id']): ?>
                        <div class="compromisso-processo">
                            ⚖️ <?= htmlspecialchars($comp['numero_processo']) ?> - <?= htmlspecialchars($comp['cliente_nome']) ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div>
                    <?php if (in_array($comp['alerta'], ['vencido', 'atrasada'])): ?>
                        <div class="compromisso-alerta <?= $comp['alerta'] ?>">🚨 VENCIDO</div>
                    <?php elseif ($comp['alerta'] === 'urgente'): ?>
                        <div class="compromisso-alerta urgente">⚠️ URGENTE</div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="compromisso-meta">
                <div class="compromisso-meta-item">
                    📅 <strong><?= $data_formatada ?></strong> (<?= $dias_texto ?>)
                </div>
                <div class="compromisso-meta-item">
                    👤 <?= htmlspecialchars($comp['responsavel_nome']) ?>
                </div>
                <div class="compromisso-meta-item">
                    <span class="badge badge-prioridade-<?= $comp['prioridade'] ?>">
                        <?= strtoupper($comp['prioridade']) ?>
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
echo renderLayout('Compromissos', $conteudo, 'compromissos');
?>