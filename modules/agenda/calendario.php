<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../includes/auth.php';
Auth::protect();
require_once '../../config/database.php';

// Verificação de módulo em desenvolvimento
require_once __DIR__ . '/../../config/modules_config.php';
$moduloAtual = 'agenda';
$usuarioLogado = $_SESSION['usuario_id'] ?? null;

if (verificarModuloEmDesenvolvimento($moduloAtual, $usuarioLogado)) {
    include __DIR__ . '/../../config/paginas/em_desenvolvimento.html';
    exit;
}

require_once '../../includes/layout.php';
$usuario_logado = Auth::user();

// Parâmetros de visualização
$view = $_GET['view'] ?? 'month'; // month, week, year
$mes = isset($_GET['mes']) ? (int)$_GET['mes'] : (int)date('m');
$ano = isset($_GET['ano']) ? (int)$_GET['ano'] : (int)date('Y');
$filtro_tipo = $_GET['tipo'] ?? '';
$filtro_prioridade = $_GET['prioridade'] ?? '';

// Validar mês e ano
if ($mes < 1 || $mes > 12) $mes = (int)date('m');
if ($ano < 2020 || $ano > 2050) $ano = (int)date('Y');

// Parâmetros adicionais para navegação
$dia_atual = isset($_GET['dia']) ? (int)$_GET['dia'] : (int)date('d');
$data_referencia = new DateTime("$ano-$mes-$dia_atual");

// Determinar período baseado na visualização
switch ($view) {
    case 'day':
        // Um dia específico
        $primeiro_dia = clone $data_referencia;
        $ultimo_dia = clone $data_referencia;
        $primeiro_dia->setTime(0, 0, 0);
        $ultimo_dia->setTime(23, 59, 59);
        break;
        
    case 'week':
        // Semana da data de referência (Segunda a Domingo)
        $inicio_semana = clone $data_referencia;
        $inicio_semana->modify('monday this week');
        $fim_semana = clone $inicio_semana;
        $fim_semana->modify('+6 days');
        $primeiro_dia = $inicio_semana;
        $ultimo_dia = $fim_semana;
        break;
        
    case 'year':
        // Ano inteiro
        $primeiro_dia = new DateTime("$ano-01-01");
        $ultimo_dia = new DateTime("$ano-12-31");
        break;
        
    case 'month':
    default:
        // Mês completo
        $primeiro_dia = new DateTime("$ano-$mes-01");
        $ultimo_dia = (clone $primeiro_dia)->modify('last day of this month');
        break;
}

// Buscar todos os compromissos do período
$compromissos_mes = [];

// 1. BUSCAR EVENTOS DA AGENDA
try {
    if (empty($filtro_tipo) || $filtro_tipo === 'evento') {
        $where = ["DATE(a.data_inicio) BETWEEN ? AND ?"];
        $params = [$primeiro_dia->format('Y-m-d'), $ultimo_dia->format('Y-m-d')];
        
        if ($filtro_prioridade) {
            $where[] = "a.prioridade = ?";
            $params[] = $filtro_prioridade;
        }
        
        $where_clause = implode(' AND ', $where);
        
        $sql = "SELECT 
            'evento' as tipo_compromisso,
            a.id,
            a.titulo,
            a.data_inicio as data_compromisso,
            a.status,
            a.prioridade
            FROM agenda a
            WHERE {$where_clause}";
        
        $stmt = executeQuery($sql, $params);
        $compromissos_mes = array_merge($compromissos_mes, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
} catch (Exception $e) {
    error_log("Erro ao buscar eventos: " . $e->getMessage());
}

// 2. BUSCAR PRAZOS
try {
    if (empty($filtro_tipo) || $filtro_tipo === 'prazo') {
        $where = ["DATE(p.data_vencimento) BETWEEN ? AND ?"];
        $params = [$primeiro_dia->format('Y-m-d'), $ultimo_dia->format('Y-m-d')];
        
        if ($filtro_prioridade) {
            $where[] = "p.prioridade = ?";
            $params[] = $filtro_prioridade;
        }
        
        $where_clause = implode(' AND ', $where);
        
        $sql = "SELECT 
            'prazo' as tipo_compromisso,
            p.id,
            p.titulo,
            p.data_vencimento as data_compromisso,
            p.status,
            p.prioridade
            FROM prazos p
            WHERE {$where_clause}";
        
        $stmt = executeQuery($sql, $params);
        $compromissos_mes = array_merge($compromissos_mes, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
} catch (Exception $e) {
    error_log("Erro ao buscar prazos: " . $e->getMessage());
}

// 3. BUSCAR TAREFAS
try {
    if (empty($filtro_tipo) || $filtro_tipo === 'tarefa') {
        $where = ["DATE(t.data_vencimento) BETWEEN ? AND ?", "t.data_vencimento IS NOT NULL"];
        $params = [$primeiro_dia->format('Y-m-d'), $ultimo_dia->format('Y-m-d')];
        
        if ($filtro_prioridade) {
            $where[] = "t.prioridade = ?";
            $params[] = $filtro_prioridade;
        }
        
        $where_clause = implode(' AND ', $where);
        
        $sql = "SELECT 
            'tarefa' as tipo_compromisso,
            t.id,
            t.titulo,
            t.data_vencimento as data_compromisso,
            t.status,
            t.prioridade
            FROM tarefas t
            WHERE {$where_clause}";
        
        $stmt = executeQuery($sql, $params);
        $compromissos_mes = array_merge($compromissos_mes, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
} catch (Exception $e) {
    error_log("Erro ao buscar tarefas: " . $e->getMessage());
}

// 4. BUSCAR AUDIÊNCIAS
try {
    if (empty($filtro_tipo) || $filtro_tipo === 'audiencia') {
        $where = ["DATE(a.data_inicio) BETWEEN ? AND ?"];
        $params = [$primeiro_dia->format('Y-m-d'), $ultimo_dia->format('Y-m-d')];
        
        if ($filtro_prioridade) {
            $where[] = "a.prioridade = ?";
            $params[] = $filtro_prioridade;
        }
        
        $where_clause = implode(' AND ', $where);
        
        $sql = "SELECT 
            'audiencia' as tipo_compromisso,
            a.id,
            a.titulo,
            a.data_inicio as data_compromisso,
            a.status,
            a.prioridade
            FROM audiencias a
            WHERE {$where_clause}";
        
        $stmt = executeQuery($sql, $params);
        $compromissos_mes = array_merge($compromissos_mes, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
} catch (Exception $e) {
    error_log("Erro ao buscar audiências: " . $e->getMessage());
}

// Organizar compromissos por dia
$compromissos_por_dia = [];
foreach ($compromissos_mes as $comp) {
    $data = new DateTime($comp['data_compromisso']);
    $dia = (int)$data->format('d');
    
    if (!isset($compromissos_por_dia[$dia])) {
        $compromissos_por_dia[$dia] = [];
    }
    
    $compromissos_por_dia[$dia][] = $comp;
}

// Calcular navegação do calendário
$mes_anterior = (clone $primeiro_dia)->modify('-1 month');
$mes_proximo = (clone $primeiro_dia)->modify('+1 month');
$ano_anterior = $ano - 1;
$ano_proximo = $ano + 1;

// Nome do mês em português
$meses = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];

// Estatísticas
$stats = [
    'total' => count($compromissos_mes),
    'eventos' => 0,
    'prazos' => 0,
    'tarefas' => 0,
    'audiencias' => 0
];

foreach ($compromissos_mes as $comp) {
    $stats[$comp['tipo_compromisso'] . 's']++;
}

ob_start();
?>

<style>
    /* Manter os mesmos estilos do index.php */
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

    .header-actions {
        display: flex;
        gap: 15px;
        align-items: center;
        flex-wrap: wrap;
    }

    .view-selector {
        display: flex;
        background: rgba(255, 255, 255, 0.9);
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }
    
    .view-btn {
        padding: 8px 16px;
        background: transparent;
        border: none;
        color: #666;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        text-decoration: none;
        font-size: 14px;
    }
    
    .view-btn:hover {
        background: rgba(102, 126, 234, 0.1);
        color: #667eea;
    }
    
    .view-btn.active {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
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
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .btn-novo:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
    }

    .stats-mini {
        display: grid;
        grid-template-columns: repeat(6, 1fr);
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

    .stat-mini:nth-child(1) { border-left-color: #667eea; }
    .stat-mini:nth-child(2) { border-left-color: #17a2b8; }
    .stat-mini:nth-child(3) { border-left-color: #ffc107; }
    .stat-mini:nth-child(4) { border-left-color: #6f42c1; }
    .stat-mini:nth-child(5) { border-left-color: #28a745; }
    .stat-mini:nth-child(6) { border-left-color: #dc3545; }

    .stat-mini-number {
        font-size: 36px;
        font-weight: 800;
        line-height: 1;
        display: block;
        margin-bottom: 10px;
    }

    .stat-mini:nth-child(1) .stat-mini-number { color: #667eea; }
    .stat-mini:nth-child(2) .stat-mini-number { color: #17a2b8; }
    .stat-mini:nth-child(3) .stat-mini-number { color: #ffc107; }
    .stat-mini:nth-child(4) .stat-mini-number { color: #6f42c1; }
    .stat-mini:nth-child(5) .stat-mini-number { color: #28a745; }
    .stat-mini:nth-child(6) .stat-mini-number { color: #dc3545; }

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

    .filters-row {
        display: flex;
        gap: 15px;
        align-items: flex-end;
        flex-wrap: wrap;
    }

    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 5px;
        min-width: 180px;
    }

    .filter-group label {
        font-size: 13px;
        font-weight: 600;
        color: #333;
    }

    .filter-group select {
        padding: 10px 12px;
        border: 1px solid #ddd;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.3s;
    }

    .filter-group select:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
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

    .btn-secondary {
        background: #6c757d;
        color: white;
    }

    /* Seletor de visualização do calendário */
    .calendar-view-selector {
        display: flex;
        gap: 10px;
        align-items: center;
    }

    .calendar-view-btn {
        padding: 10px 18px;
        background: rgba(102, 126, 234, 0.1);
        border: 1px solid #667eea;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        text-decoration: none;
        color: #667eea;
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
    }

    .calendar-view-btn:hover {
        background: rgba(102, 126, 234, 0.2);
        transform: translateY(-2px);
    }

    .calendar-view-btn.active {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border-color: #667eea;
    }

    .calendar-navigation {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        padding: 20px 25px;
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        margin-bottom: 25px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
    }

    .calendar-title {
        font-size: 24px;
        font-weight: 700;
        color: #1a1a1a;
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .calendar-nav-buttons {
        display: flex;
        gap: 10px;
    }

    .nav-btn {
        padding: 10px 15px;
        background: rgba(102, 126, 234, 0.1);
        border: 1px solid #667eea;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        text-decoration: none;
        color: #667eea;
        display: flex;
        align-items: center;
        gap: 5px;
        font-size: 13px;
    }

    .nav-btn:hover {
        background: #667eea;
        color: white;
        transform: translateY(-2px);
    }

    .btn-hoje {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        border: none;
    }

    .btn-hoje:hover {
        background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
    }

    .calendar-container {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        overflow: hidden;
        padding: 25px;
    }

    .calendar-grid {
        display: grid;
        grid-template-columns: repeat(7, 1fr);
        gap: 10px;
    }

    .calendar-header {
        text-align: center;
        font-weight: 700;
        color: #667eea;
        padding: 15px 10px;
        font-size: 14px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .calendar-day {
        min-height: 120px;
        border: 1px solid rgba(0,0,0,0.1);
        border-radius: 8px;
        padding: 8px;
        background: rgba(255, 255, 255, 0.5);
        transition: all 0.3s;
        position: relative;
    }

    .calendar-day:hover {
        background: rgba(102, 126, 234, 0.05);
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    }

    .calendar-day.empty {
        background: rgba(0,0,0,0.02);
        cursor: default;
    }

    .calendar-day.empty:hover {
        transform: none;
        box-shadow: none;
    }

    .calendar-day.today {
        background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
        border: 2px solid #667eea;
    }

    .day-number {
        font-weight: 700;
        color: #1a1a1a;
        font-size: 16px;
        margin-bottom: 8px;
    }

    .calendar-day.today .day-number {
        background: #667eea;
        color: white;
        width: 28px;
        height: 28px;
        border-radius: 50%;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
    }

    .day-events {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .event-item {
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s;
        border-left: 3px solid;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .event-item:hover {
        transform: translateX(2px);
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    }

    .event-evento {
        background: rgba(23, 162, 184, 0.15);
        border-left-color: #17a2b8;
        color: #0c5460;
    }

    .event-prazo {
        background: rgba(255, 193, 7, 0.15);
        border-left-color: #ffc107;
        color: #856404;
    }

    .event-tarefa {
        background: rgba(111, 66, 193, 0.15);
        border-left-color: #6f42c1;
        color: #4a3a5c;
    }

    .event-audiencia {
        background: rgba(40, 167, 69, 0.15);
        border-left-color: #28a745;
        color: #155724;
    }

    .event-count {
        padding: 4px 8px;
        background: rgba(108, 117, 125, 0.15);
        border-radius: 4px;
        font-size: 11px;
        font-weight: 600;
        color: #6c757d;
        text-align: center;
        cursor: pointer;
    }

    .legend {
        display: flex;
        gap: 20px;
        margin-top: 20px;
        flex-wrap: wrap;
        justify-content: center;
        padding-top: 20px;
        border-top: 2px solid rgba(0,0,0,0.05);
    }

    .legend-item {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        color: #666;
        font-weight: 600;
    }

    .legend-color {
        width: 16px;
        height: 16px;
        border-radius: 3px;
        border-left: 3px solid;
    }

    .legend-color.evento {
        background: rgba(23, 162, 184, 0.15);
        border-left-color: #17a2b8;
    }

    .legend-color.prazo {
        background: rgba(255, 193, 7, 0.15);
        border-left-color: #ffc107;
    }

    .legend-color.tarefa {
        background: rgba(111, 66, 193, 0.15);
        border-left-color: #6f42c1;
    }

    .legend-color.audiencia {
        background: rgba(40, 167, 69, 0.15);
        border-left-color: #28a745;
    }

    @media (max-width: 1200px) {
        .stats-mini {
            grid-template-columns: repeat(3, 1fr);
        }
    }

    @media (max-width: 768px) {
        .page-header-top {
            flex-direction: column;
            align-items: flex-start;
        }

        .stats-mini {
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
        }

        .stat-mini {
            padding: 15px 10px;
        }

        .stat-mini-number {
            font-size: 28px;
        }

        .calendar-grid {
            grid-template-columns: 1fr;
        }

        .calendar-header {
            display: none;
        }

        .calendar-day {
            min-height: auto;
            padding: 15px;
        }

        .calendar-day.empty {
            display: none;
        }
    }
</style>

<!-- HEADER IDÊNTICO AO INDEX.PHP -->
<div class="page-header">
    <div class="page-header-top">
        <h2>📅 Agenda Unificada</h2>
        <div class="header-actions">
            <div class="view-selector">
                <a href="index.php?view=list<?= !empty($filtro_tipo) ? '&tipo=' . $filtro_tipo : '' ?>" 
                   class="view-btn">📋 Lista</a>
                <a href="calendario.php<?= !empty($filtro_tipo) ? '?tipo=' . $filtro_tipo : '' ?>" 
                   class="view-btn active">📅 Calendário</a>
            </div>
            <a href="novo.php" class="btn-novo">+ Novo Evento</a>
        </div>
    </div>

    <!-- Estatísticas - IGUAIS AO INDEX -->
    <div class="stats-mini">
        <div class="stat-mini">
            <div class="stat-mini-number"><?= number_format($stats['total']) ?></div>
            <div class="stat-mini-label">Total</div>
        </div>
        <div class="stat-mini">
            <div class="stat-mini-number"><?= number_format($stats['eventos']) ?></div>
            <div class="stat-mini-label">Eventos</div>
        </div>
        <div class="stat-mini">
            <div class="stat-mini-number"><?= number_format($stats['prazos']) ?></div>
            <div class="stat-mini-label">Prazos</div>
        </div>
        <div class="stat-mini">
            <div class="stat-mini-number"><?= number_format($stats['tarefas']) ?></div>
            <div class="stat-mini-label">Tarefas</div>
        </div>
        <div class="stat-mini">
            <div class="stat-mini-number"><?= number_format($stats['audiencias']) ?></div>
            <div class="stat-mini-label">Audiências</div>
        </div>
        <div class="stat-mini">
            <div class="stat-mini-number">0</div>
            <div class="stat-mini-label">Urgentes</div>
        </div>
    </div>
</div>

<!-- Filtros + Seletor de Visualização do Calendário -->
<div class="filters-section">
    <form method="GET" class="filters-row">
        <input type="hidden" name="mes" value="<?= $mes ?>">
        <input type="hidden" name="ano" value="<?= $ano ?>">
        
        <div class="filter-group">
            <label>Tipo</label>
            <select name="tipo" onchange="this.form.submit()">
                <option value="">Todos os Tipos</option>
                <option value="evento" <?= $filtro_tipo === 'evento' ? 'selected' : '' ?>>🎯 Eventos</option>
                <option value="prazo" <?= $filtro_tipo === 'prazo' ? 'selected' : '' ?>>⏰ Prazos</option>
                <option value="tarefa" <?= $filtro_tipo === 'tarefa' ? 'selected' : '' ?>>✓ Tarefas</option>
                <option value="audiencia" <?= $filtro_tipo === 'audiencia' ? 'selected' : '' ?>>📅 Audiências</option>
            </select>
        </div>

        <div class="filter-group">
            <label>Prioridade</label>
            <select name="prioridade" onchange="this.form.submit()">
                <option value="">Todas</option>
                <option value="urgente" <?= $filtro_prioridade === 'urgente' ? 'selected' : '' ?>>🚨 Urgente</option>
                <option value="alta" <?= $filtro_prioridade === 'alta' ? 'selected' : '' ?>>🔴 Alta</option>
                <option value="normal" <?= $filtro_prioridade === 'normal' ? 'selected' : '' ?>>🟡 Normal</option>
                <option value="baixa" <?= $filtro_prioridade === 'baixa' ? 'selected' : '' ?>>🟢 Baixa</option>
            </select>
        </div>

        <!-- NOVO: Seletor de Visualização do Calendário -->
        <div class="filter-group">
            <label>Visualização</label>
            <div class="calendar-view-selector">
                <a href="?view=day&mes=<?= $mes ?>&ano=<?= $ano ?><?= !empty($filtro_tipo) ? '&tipo=' . $filtro_tipo : '' ?><?= !empty($filtro_prioridade) ? '&prioridade=' . $filtro_prioridade : '' ?>" 
                   class="calendar-view-btn <?= $view === 'day' ? 'active' : '' ?>">
                    📍 Dia
                </a>
                <a href="?view=week&mes=<?= $mes ?>&ano=<?= $ano ?><?= !empty($filtro_tipo) ? '&tipo=' . $filtro_tipo : '' ?><?= !empty($filtro_prioridade) ? '&prioridade=' . $filtro_prioridade : '' ?>" 
                   class="calendar-view-btn <?= $view === 'week' ? 'active' : '' ?>">
                    📅 Semana
                </a>
                <a href="?view=month&mes=<?= $mes ?>&ano=<?= $ano ?><?= !empty($filtro_tipo) ? '&tipo=' . $filtro_tipo : '' ?><?= !empty($filtro_prioridade) ? '&prioridade=' . $filtro_prioridade : '' ?>" 
                   class="calendar-view-btn <?= $view === 'month' ? 'active' : '' ?>">
                    📆 Mês
                </a>
                <a href="?view=year&ano=<?= $ano ?><?= !empty($filtro_tipo) ? '&tipo=' . $filtro_tipo : '' ?><?= !empty($filtro_prioridade) ? '&prioridade=' . $filtro_prioridade : '' ?>" 
                   class="calendar-view-btn <?= $view === 'year' ? 'active' : '' ?>">
                    📊 Ano
                </a>
            </div>
        </div>

        <a href="calendario.php?mes=<?= $mes ?>&ano=<?= $ano ?>" class="btn btn-secondary">
            🔄 Limpar
        </a>
    </form>
</div>

<!-- Navegação do Calendário -->
<div class="calendar-navigation">
    <div class="calendar-nav-buttons">
        <?php if ($view === 'year'): ?>
            <a href="?view=year&ano=<?= $ano - 1 ?><?= !empty($filtro_tipo) ? '&tipo=' . $filtro_tipo : '' ?><?= !empty($filtro_prioridade) ? '&prioridade=' . $filtro_prioridade : '' ?>" 
               class="nav-btn">
                ← <?= $ano - 1 ?>
            </a>
        <?php else: ?>
            <a href="?view=<?= $view ?>&mes=<?= $mes_anterior->format('m') ?>&ano=<?= $mes_anterior->format('Y') ?><?= !empty($filtro_tipo) ? '&tipo=' . $filtro_tipo : '' ?><?= !empty($filtro_prioridade) ? '&prioridade=' . $filtro_prioridade : '' ?>" 
               class="nav-btn">
                ← <?= $meses[(int)$mes_anterior->format('m')] ?>
            </a>
        <?php endif; ?>
    </div>
    
    <div class="calendar-title">
        <?php if ($view === 'year'): ?>
            📆 <?= $ano ?>
        <?php else: ?>
            📅 <?= $meses[$mes] ?> de <?= $ano ?>
        <?php endif; ?>
        
        <a href="?view=<?= $view ?>&mes=<?= date('m') ?>&ano=<?= date('Y') ?><?= !empty($filtro_tipo) ? '&tipo=' . $filtro_tipo : '' ?><?= !empty($filtro_prioridade) ? '&prioridade=' . $filtro_prioridade : '' ?>" 
           class="nav-btn btn-hoje">
            📍 Hoje
        </a>
    </div>
    
    <div class="calendar-nav-buttons">
        <?php if ($view === 'year'): ?>
            <a href="?view=year&ano=<?= $ano + 1 ?><?= !empty($filtro_tipo) ? '&tipo=' . $filtro_tipo : '' ?><?= !empty($filtro_prioridade) ? '&prioridade=' . $filtro_prioridade : '' ?>" 
               class="nav-btn">
                <?= $ano + 1 ?> →
            </a>
        <?php else: ?>
            <a href="?view=<?= $view ?>&mes=<?= $mes_proximo->format('m') ?>&ano=<?= $mes_proximo->format('Y') ?><?= !empty($filtro_tipo) ? '&tipo=' . $filtro_tipo : '' ?><?= !empty($filtro_prioridade) ? '&prioridade=' . $filtro_prioridade : '' ?>" 
               class="nav-btn">
                <?= $meses[(int)$mes_proximo->format('m')] ?> →
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Calendário -->
<div class="calendar-container">
    <?php if ($view === 'day'): ?>
        <!-- ============================================ -->
        <!-- VISUALIZAÇÃO POR DIA -->
        <!-- ============================================ -->
        <div style="max-width: 800px; margin: 0 auto;">
            <!-- Cabeçalho do Dia -->
            <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px; border-radius: 12px 12px 0 0; color: white; text-align: center;">
                <div style="font-size: 14px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; opacity: 0.9; margin-bottom: 10px;">
                    <?php
                    $dias_semana = ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
                    echo $dias_semana[(int)$data_referencia->format('w')];
                    ?>
                </div>
                <div style="font-size: 48px; font-weight: 800; margin-bottom: 5px;">
                    <?= $data_referencia->format('d') ?>
                </div>
                <div style="font-size: 18px; font-weight: 600; opacity: 0.95;">
                    <?= $meses[(int)$data_referencia->format('m')] ?> de <?= $data_referencia->format('Y') ?>
                </div>
            </div>

            <!-- Lista de Compromissos do Dia -->
            <div style="background: white; padding: 30px; border-radius: 0 0 12px 12px;">
                <?php
                $compromissos_dia = array_filter($compromissos_mes, function($comp) use ($data_referencia) {
                    $data_comp = new DateTime($comp['data_compromisso']);
                    return $data_comp->format('Y-m-d') === $data_referencia->format('Y-m-d');
                });

                // Ordenar por hora
                usort($compromissos_dia, function($a, $b) {
                    return strtotime($a['data_compromisso']) - strtotime($b['data_compromisso']);
                });

                if (empty($compromissos_dia)): ?>
                    <div style="text-align: center; padding: 60px 20px; color: #999;">
                        <svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.3; margin-bottom: 20px;">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                            <line x1="16" y1="2" x2="16" y2="6"></line>
                            <line x1="8" y1="2" x2="8" y2="6"></line>
                            <line x1="3" y1="10" x2="21" y2="10"></line>
                        </svg>
                        <h3 style="color: #666; margin-bottom: 10px; font-size: 20px;">Nenhum compromisso</h3>
                        <p style="color: #999; font-size: 14px;">Este dia está livre de compromissos</p>
                    </div>
                <?php else: ?>
                    <!-- Timeline de Compromissos -->
                    <div style="position: relative; padding-left: 100px;">
                        <!-- Linha do tempo -->
                        <div style="position: absolute; left: 90px; top: 0; bottom: 0; width: 2px; background: linear-gradient(to bottom, #667eea, #764ba2);"></div>

                        <?php foreach ($compromissos_dia as $comp): 
                            $hora = date('H:i', strtotime($comp['data_compromisso']));
                            
                            $tipo_icon = match($comp['tipo_compromisso']) {
                                'evento' => '🎯',
                                'prazo' => '⏰',
                                'tarefa' => '✓',
                                'audiencia' => '📅',
                                default => '📌'
                            };

                            $tipo_color = match($comp['tipo_compromisso']) {
                                'evento' => '#17a2b8',
                                'prazo' => '#ffc107',
                                'tarefa' => '#6f42c1',
                                'audiencia' => '#28a745',
                                default => '#667eea'
                            };

                            $link = match($comp['tipo_compromisso']) {
                                'evento' => 'visualizar.php?id=' . $comp['id'],
                                'prazo' => '../prazos/visualizar.php?id=' . $comp['id'],
                                'tarefa' => '../tarefas/visualizar.php?id=' . $comp['id'],
                                'audiencia' => '../audiencias/visualizar.php?id=' . $comp['id'],
                                default => '#'
                            };
                        ?>
                        <div style="position: relative; margin-bottom: 30px; cursor: pointer;" onclick="window.location.href='<?= $link ?>'">
                            <!-- Hora -->
                            <div style="position: absolute; left: -100px; top: 5px; font-weight: 700; color: #667eea; font-size: 14px; width: 80px; text-align: right;">
                                <?= $hora ?>
                            </div>

                            <!-- Ponto na timeline -->
                            <div style="position: absolute; left: -15px; top: 8px; width: 12px; height: 12px; background: <?= $tipo_color ?>; border-radius: 50%; box-shadow: 0 0 0 4px white, 0 0 0 6px <?= $tipo_color ?>40;"></div>

                            <!-- Card do compromisso -->
                            <div style="background: white; border: 2px solid <?= $tipo_color ?>40; border-left: 4px solid <?= $tipo_color ?>; border-radius: 12px; padding: 20px; transition: all 0.3s; box-shadow: 0 2px 8px rgba(0,0,0,0.05);" 
                                 onmouseover="this.style.transform='translateX(5px)'; this.style.boxShadow='0 4px 16px rgba(0,0,0,0.1)';" 
                                 onmouseout="this.style.transform='translateX(0)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.05)';">
                                
                                <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 10px;">
                                    <div>
                                        <div style="font-size: 11px; font-weight: 700; color: <?= $tipo_color ?>; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 8px;">
                                            <?= $tipo_icon ?> <?= strtoupper($comp['tipo_compromisso']) ?>
                                        </div>
                                        <div style="font-size: 18px; font-weight: 700; color: #1a1a1a; margin-bottom: 5px;">
                                            <?= htmlspecialchars($comp['titulo']) ?>
                                        </div>
                                    </div>
                                    <?php if (in_array($comp['prioridade'], ['urgente', 'Urgente', 'alta', 'Alta'])): ?>
                                    <div style="background: rgba(220, 53, 69, 0.1); color: #dc3545; padding: 6px 12px; border-radius: 20px; font-size: 11px; font-weight: 700;">
                                        🚨 <?= strtoupper($comp['prioridade']) ?>
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <?php if (!empty($comp['status'])): ?>
                                <div style="display: inline-block; background: rgba(102, 126, 234, 0.1); color: #667eea; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 700; text-transform: uppercase; margin-top: 10px;">
                                    <?= str_replace('_', ' ', $comp['status']) ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Navegação do Dia -->
            <div style="display: flex; justify-content: space-between; margin-top: 20px; gap: 10px;">
                <?php 
                $dia_anterior = clone $data_referencia;
                $dia_anterior->modify('-1 day');
                $dia_proximo = clone $data_referencia;
                $dia_proximo->modify('+1 day');
                ?>
                <a href="?view=day&dia=<?= $dia_anterior->format('d') ?>&mes=<?= $dia_anterior->format('m') ?>&ano=<?= $dia_anterior->format('Y') ?><?= !empty($filtro_tipo) ? '&tipo=' . $filtro_tipo : '' ?>" 
                   class="nav-btn" style="flex: 1;">
                    ← <?= $dia_anterior->format('d/m') ?>
                </a>
                <a href="?view=day&dia=<?= date('d') ?>&mes=<?= date('m') ?>&ano=<?= date('Y') ?><?= !empty($filtro_tipo) ? '&tipo=' . $filtro_tipo : '' ?>" 
                   class="nav-btn btn-hoje">
                    📍 Hoje
                </a>
                <a href="?view=day&dia=<?= $dia_proximo->format('d') ?>&mes=<?= $dia_proximo->format('m') ?>&ano=<?= $dia_proximo->format('Y') ?><?= !empty($filtro_tipo) ? '&tipo=' . $filtro_tipo : '' ?>" 
                   class="nav-btn" style="flex: 1;">
                    <?= $dia_proximo->format('d/m') ?> →
                </a>
            </div>
        </div>

    <?php elseif ($view === 'week'): ?>
        <!-- ============================================ -->
        <!-- VISUALIZAÇÃO POR SEMANA -->
        <!-- ============================================ -->
        <div style="overflow-x: auto;">
            <div style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 15px; min-width: 900px;">
                <?php
                $inicio_semana = clone $primeiro_dia;
                $dias_semana_curto = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
                $hoje = new DateTime();

                for ($i = 0; $i < 7; $i++):
                    $data_dia = clone $inicio_semana;
                    $data_dia->modify("+$i days");
                    $eh_hoje = ($data_dia->format('Y-m-d') === $hoje->format('Y-m-d'));

                    // Compromissos do dia
                    $compromissos_dia = array_filter($compromissos_mes, function($comp) use ($data_dia) {
                        $data_comp = new DateTime($comp['data_compromisso']);
                        return $data_comp->format('Y-m-d') === $data_dia->format('Y-m-d');
                    });

                    // Ordenar por hora
                    usort($compromissos_dia, function($a, $b) {
                        return strtotime($a['data_compromisso']) - strtotime($b['data_compromisso']);
                    });
                ?>
                <div style="background: white; border-radius: 12px; overflow: hidden; border: 2px solid <?= $eh_hoje ? '#667eea' : 'rgba(0,0,0,0.1)' ?>; box-shadow: 0 2px 8px rgba(0,0,0,0.05);">
                    <!-- Cabeçalho do dia -->
                    <div style="background: <?= $eh_hoje ? 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)' : '#f8f9fa' ?>; padding: 15px; text-align: center; border-bottom: 2px solid rgba(0,0,0,0.05);">
                        <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: <?= $eh_hoje ? 'rgba(255,255,255,0.9)' : '#999' ?>; margin-bottom: 5px;">
                            <?= $dias_semana_curto[(int)$data_dia->format('w')] ?>
                        </div>
                        <div style="font-size: 28px; font-weight: 800; color: <?= $eh_hoje ? 'white' : '#1a1a1a' ?>;">
                            <?= $data_dia->format('d') ?>
                        </div>
                        <div style="font-size: 11px; font-weight: 600; color: <?= $eh_hoje ? 'rgba(255,255,255,0.9)' : '#999' ?>;">
                            <?= $meses[(int)$data_dia->format('m')] ?>
                        </div>
                    </div>

                    <!-- Compromissos do dia -->
                    <div style="padding: 15px; min-height: 300px; max-height: 500px; overflow-y: auto;">
                        <?php if (empty($compromissos_dia)): ?>
                            <div style="text-align: center; padding: 40px 10px; color: #ccc;">
                                <div style="font-size: 32px; margin-bottom: 10px;">📭</div>
                                <div style="font-size: 12px; color: #999;">Sem compromissos</div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($compromissos_dia as $comp): 
                                $hora = date('H:i', strtotime($comp['data_compromisso']));
                                
                                $tipo_icon = match($comp['tipo_compromisso']) {
                                    'evento' => '🎯',
                                    'prazo' => '⏰',
                                    'tarefa' => '✓',
                                    'audiencia' => '📅',
                                    default => '📌'
                                };

                                $tipo_bg = match($comp['tipo_compromisso']) {
                                    'evento' => 'rgba(23, 162, 184, 0.1)',
                                    'prazo' => 'rgba(255, 193, 7, 0.1)',
                                    'tarefa' => 'rgba(111, 66, 193, 0.1)',
                                    'audiencia' => 'rgba(40, 167, 69, 0.1)',
                                    default => 'rgba(102, 126, 234, 0.1)'
                                };

                                $tipo_border = match($comp['tipo_compromisso']) {
                                    'evento' => '#17a2b8',
                                    'prazo' => '#ffc107',
                                    'tarefa' => '#6f42c1',
                                    'audiencia' => '#28a745',
                                    default => '#667eea'
                                };

                                $link = match($comp['tipo_compromisso']) {
                                    'evento' => 'visualizar.php?id=' . $comp['id'],
                                    'prazo' => '../prazos/visualizar.php?id=' . $comp['id'],
                                    'tarefa' => '../tarefas/visualizar.php?id=' . $comp['id'],
                                    'audiencia' => '../audiencias/visualizar.php?id=' . $comp['id'],
                                    default => '#'
                                };
                            ?>
                            <div onclick="window.location.href='<?= $link ?>'" 
                                 style="background: <?= $tipo_bg ?>; border-left: 3px solid <?= $tipo_border ?>; border-radius: 6px; padding: 10px; margin-bottom: 8px; cursor: pointer; transition: all 0.2s;"
                                 onmouseover="this.style.transform='translateX(3px)'; this.style.boxShadow='0 2px 8px rgba(0,0,0,0.1)';"
                                 onmouseout="this.style.transform='translateX(0)'; this.style.boxShadow='none';">
                                <div style="font-size: 11px; font-weight: 700; color: <?= $tipo_border ?>; margin-bottom: 5px;">
                                    <?= $tipo_icon ?> <?= $hora ?>
                                </div>
                                <div style="font-size: 13px; font-weight: 600; color: #1a1a1a; line-height: 1.3;">
                                    <?= htmlspecialchars(substr($comp['titulo'], 0, 30)) ?>
                                    <?= strlen($comp['titulo']) > 30 ? '...' : '' ?>
                                </div>
                                <?php if (in_array($comp['prioridade'], ['urgente', 'Urgente'])): ?>
                                <div style="font-size: 10px; color: #dc3545; margin-top: 5px; font-weight: 700;">
                                    🚨 URGENTE
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endfor; ?>
            </div>

            <!-- Navegação da Semana -->
            <div style="display: flex; justify-content: space-between; margin-top: 20px; gap: 10px;">
                <?php 
                $semana_anterior = clone $inicio_semana;
                $semana_anterior->modify('-7 days');
                $semana_proxima = clone $inicio_semana;
                $semana_proxima->modify('+7 days');
                ?>
                <a href="?view=week&dia=<?= $semana_anterior->format('d') ?>&mes=<?= $semana_anterior->format('m') ?>&ano=<?= $semana_anterior->format('Y') ?><?= !empty($filtro_tipo) ? '&tipo=' . $filtro_tipo : '' ?>" 
                   class="nav-btn" style="flex: 1;">
                    ← Semana Anterior
                </a>
                <a href="?view=week&dia=<?= date('d') ?>&mes=<?= date('m') ?>&ano=<?= date('Y') ?><?= !empty($filtro_tipo) ? '&tipo=' . $filtro_tipo : '' ?>" 
                   class="nav-btn btn-hoje">
                    📍 Esta Semana
                </a>
                <a href="?view=week&dia=<?= $semana_proxima->format('d') ?>&mes=<?= $semana_proxima->format('m') ?>&ano=<?= $semana_proxima->format('Y') ?><?= !empty($filtro_tipo) ? '&tipo=' . $filtro_tipo : '' ?>" 
                   class="nav-btn" style="flex: 1;">
                    Próxima Semana →
                </a>
            </div>
        </div>

    <?php elseif ($view === 'year'): ?>
        <!-- ============================================ -->
        <!-- VISUALIZAÇÃO ANUAL - 12 MESES EM GRID -->
        <!-- ============================================ -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 25px;">
            <?php for ($m = 1; $m <= 12; $m++): 
                $primeiro_dia_mes = new DateTime("$ano-$m-01");
                $ultimo_dia_mes = (clone $primeiro_dia_mes)->modify('last day of this month');
                $total_dias_mes = (int)$ultimo_dia_mes->format('d');
                $dia_semana_inicio = (int)$primeiro_dia_mes->format('w');
                
                // Contar compromissos do mês
                $compromissos_do_mes = array_filter($compromissos_mes, function($comp) use ($m, $ano) {
                    $data_comp = new DateTime($comp['data_compromisso']);
                    return (int)$data_comp->format('m') === $m && (int)$data_comp->format('Y') === $ano;
                });
                $total_comp_mes = count($compromissos_do_mes);
            ?>
            <div style="background: rgba(255, 255, 255, 0.8); border-radius: 12px; padding: 15px; border: 1px solid rgba(0,0,0,0.1); transition: all 0.3s; cursor: pointer;" 
                 onclick="window.location.href='?view=month&mes=<?= $m ?>&ano=<?= $ano ?><?= !empty($filtro_tipo) ? '&tipo=' . $filtro_tipo : '' ?>'">
                
                <!-- Cabeçalho do Mês -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; padding-bottom: 10px; border-bottom: 2px solid rgba(102, 126, 234, 0.2);">
                    <h3 style="margin: 0; color: #667eea; font-size: 16px; font-weight: 700;">
                        <?= $meses[$m] ?>
                    </h3>
                    <?php if ($total_comp_mes > 0): ?>
                        <span style="background: rgba(102, 126, 234, 0.1); color: #667eea; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 700;">
                            <?= $total_comp_mes ?> compromisso<?= $total_comp_mes > 1 ? 's' : '' ?>
                        </span>
                    <?php endif; ?>
                </div>
                
                <!-- Mini Calendário -->
                <div style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 3px;">
                    <!-- Cabeçalhos dos dias -->
                    <?php foreach (['D', 'S', 'T', 'Q', 'Q', 'S', 'S'] as $dia_semana): ?>
                        <div style="text-align: center; font-size: 10px; font-weight: 700; color: #999; padding: 3px;">
                            <?= $dia_semana ?>
                        </div>
                    <?php endforeach; ?>
                    
                    <!-- Células vazias antes do primeiro dia -->
                    <?php for ($i = 0; $i < $dia_semana_inicio; $i++): ?>
                        <div style="padding: 5px;"></div>
                    <?php endfor; ?>
                    
                    <!-- Dias do mês -->
                    <?php 
                    $hoje = new DateTime();
                    for ($d = 1; $d <= $total_dias_mes; $d++): 
                        $data_dia = new DateTime("$ano-$m-$d");
                        $eh_hoje = ($data_dia->format('Y-m-d') === $hoje->format('Y-m-d'));
                        
                        // Verificar se há compromissos neste dia
                        $tem_compromisso = false;
                        foreach ($compromissos_mes as $comp) {
                            $data_comp = new DateTime($comp['data_compromisso']);
                            if ($data_comp->format('Y-m-d') === $data_dia->format('Y-m-d')) {
                                $tem_compromisso = true;
                                break;
                            }
                        }
                        
                        $cor_fundo = $eh_hoje ? '#667eea' : ($tem_compromisso ? 'rgba(102, 126, 234, 0.2)' : 'transparent');
                        $cor_texto = $eh_hoje ? 'white' : ($tem_compromisso ? '#667eea' : '#333');
                    ?>
                        <div style="text-align: center; padding: 5px; font-size: 11px; font-weight: <?= $tem_compromisso ? '700' : '400' ?>; background: <?= $cor_fundo ?>; color: <?= $cor_texto ?>; border-radius: 4px;">
                            <?= $d ?>
                        </div>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endfor; ?>
        </div>

    <?php else: ?>
        <!-- ============================================ -->
        <!-- VISUALIZAÇÃO MENSAL - CALENDÁRIO COMPLETO -->
        <!-- ============================================ -->
        <div class="calendar-grid">
            <!-- Cabeçalhos dos dias -->
            <div class="calendar-header">DOM</div>
            <div class="calendar-header">SEG</div>
            <div class="calendar-header">TER</div>
            <div class="calendar-header">QUA</div>
            <div class="calendar-header">QUI</div>
            <div class="calendar-header">SEX</div>
            <div class="calendar-header">SÁB</div>
            
            <?php
            // Dia da semana do primeiro dia (0=domingo, 6=sábado)
            $dia_semana_inicio = (int)$primeiro_dia->format('w');
            
            // Adicionar células vazias antes do primeiro dia
            for ($i = 0; $i < $dia_semana_inicio; $i++) {
                echo '<div class="calendar-day empty"></div>';
            }
            
            // Dias do mês
            $total_dias = (int)$ultimo_dia->format('d');
            $hoje = new DateTime();
            
            for ($dia = 1; $dia <= $total_dias; $dia++) {
                $data_atual = new DateTime("$ano-$mes-$dia");
                $eh_hoje = ($data_atual->format('Y-m-d') === $hoje->format('Y-m-d'));
                
                $eventos_dia = $compromissos_por_dia[$dia] ?? [];
                $total_eventos = count($eventos_dia);
                
                echo '<div class="calendar-day' . ($eh_hoje ? ' today' : '') . '">';
                echo '<div class="day-number">' . $dia . '</div>';
                
                if ($total_eventos > 0) {
                    echo '<div class="day-events">';
                    
                    // Mostrar até 3 eventos, depois mostrar contador
                    $max_exibir = 3;
                    $eventos_exibidos = array_slice($eventos_dia, 0, $max_exibir);
                    
                    foreach ($eventos_exibidos as $evento) {
                        $link = match($evento['tipo_compromisso']) {
                            'evento' => 'visualizar.php?id=' . $evento['id'],
                            'prazo' => '../prazos/visualizar.php?id=' . $evento['id'],
                            'tarefa' => '../tarefas/visualizar.php?id=' . $evento['id'],
                            'audiencia' => '../audiencias/visualizar.php?id=' . $evento['id'],
                            default => '#'
                        };
                        
                        $hora = date('H:i', strtotime($evento['data_compromisso']));
                        
                        // Emoji de prioridade
                        $prioridade_emoji = match($evento['prioridade']) {
                            'urgente', 'Urgente' => '🚨 ',
                            'alta', 'Alta' => '🔴 ',
                            'baixa', 'Baixa' => '🟢 ',
                            default => ''
                        };
                        
                        echo '<div class="event-item event-' . $evento['tipo_compromisso'] . '" onclick="window.location.href=\'' . $link . '\'" title="' . htmlspecialchars($evento['titulo']) . '">';
                        echo $prioridade_emoji . $hora . ' ' . htmlspecialchars(substr($evento['titulo'], 0, 15));
                        if (strlen($evento['titulo']) > 15) echo '...';
                        echo '</div>';
                    }
                    
                    // Se houver mais eventos, mostrar contador
                    if ($total_eventos > $max_exibir) {
                        $restantes = $total_eventos - $max_exibir;
                        echo '<div class="event-count">+' . $restantes . ' compromisso' . ($restantes > 1 ? 's' : '') . '</div>';
                    }
                    
                    echo '</div>';
                }
                
                echo '</div>';
            }
            ?>
        </div>
        
        <!-- Legenda -->
        <div class="legend">
            <div class="legend-item">
                <div class="legend-color evento"></div>
                <span>🎯 Eventos</span>
            </div>
            <div class="legend-item">
                <div class="legend-color prazo"></div>
                <span>⏰ Prazos</span>
            </div>
            <div class="legend-item">
                <div class="legend-color tarefa"></div>
                <span>✓ Tarefas</span>
            </div>
            <div class="legend-item">
                <div class="legend-color audiencia"></div>
                <span>📅 Audiências</span>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Rodapé com informações -->
<div style="margin-top: 30px; padding: 20px; background: rgba(255, 255, 255, 0.95); border-radius: 15px; box-shadow: 0 8px 32px rgba(0,0,0,0.15); text-align: center;">
    <div style="display: flex; justify-content: center; gap: 40px; flex-wrap: wrap; margin-bottom: 15px;">
        <div style="text-align: center;">
            <div style="font-size: 28px; font-weight: 700; color: #667eea;"><?= $stats['total'] ?></div>
            <div style="font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;">
                Total de Compromissos
            </div>
        </div>
        
        <?php if ($view === 'month'): ?>
        <div style="text-align: center;">
            <div style="font-size: 28px; font-weight: 700; color: #28a745;">
                <?= count(array_filter($compromissos_mes, function($c) { 
                    return in_array($c['status'], ['concluido', 'cumprido', 'concluida']); 
                })) ?>
            </div>
            <div style="font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;">
                Concluídos
            </div>
        </div>
        
        <div style="text-align: center;">
            <div style="font-size: 28px; font-weight: 700; color: #ffc107;">
                <?= count(array_filter($compromissos_mes, function($c) { 
                    return in_array($c['status'], ['pendente', 'agendado', 'em_andamento']); 
                })) ?>
            </div>
            <div style="font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;">
                Pendentes
            </div>
        </div>
        
        <div style="text-align: center;">
            <div style="font-size: 28px; font-weight: 700; color: #dc3545;">
                <?= count(array_filter($compromissos_mes, function($c) { 
                    return in_array($c['prioridade'], ['urgente', 'Urgente', 'alta', 'Alta']); 
                })) ?>
            </div>
            <div style="font-size: 12px; color: #666; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600;">
                Alta Prioridade
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <div style="padding-top: 15px; border-top: 1px solid rgba(0,0,0,0.1); color: #999; font-size: 13px;">
        <strong>Dica:</strong> Clique em qualquer compromisso para visualizar os detalhes completos
    </div>
</div>

<script>
// Atalhos de teclado
document.addEventListener('keydown', function(e) {
    // Seta esquerda - mês/ano anterior
    if (e.key === 'ArrowLeft') {
        <?php if ($view === 'year'): ?>
            window.location.href = '?view=year&ano=<?= $ano_anterior ?><?= !empty($filtro_tipo) ? '&tipo=' . $filtro_tipo : '' ?>';
        <?php else: ?>
            window.location.href = '?view=<?= $view ?>&mes=<?= $mes_anterior->format('m') ?>&ano=<?= $mes_anterior->format('Y') ?><?= !empty($filtro_tipo) ? '&tipo=' . $filtro_tipo : '' ?>';
        <?php endif; ?>
    }
    
    // Seta direita - próximo mês/ano
    if (e.key === 'ArrowRight') {
        <?php if ($view === 'year'): ?>
            window.location.href = '?view=year&ano=<?= $ano_proximo ?><?= !empty($filtro_tipo) ? '&tipo=' . $filtro_tipo : '' ?>';
        <?php else: ?>
            window.location.href = '?view=<?= $view ?>&mes=<?= $mes_proximo->format('m') ?>&ano=<?= $mes_proximo->format('Y') ?><?= !empty($filtro_tipo) ? '&tipo=' . $filtro_tipo : '' ?>';
        <?php endif; ?>
    }
    
    // Tecla H - Voltar para hoje
    if (e.key === 'h' || e.key === 'H') {
        window.location.href = '?view=<?= $view ?>&mes=<?= date('m') ?>&ano=<?= date('Y') ?><?= !empty($filtro_tipo) ? '&tipo=' . $filtro_tipo : '' ?>';
    }
    
    // Tecla L - Ir para lista
    if (e.key === 'l' || e.key === 'L') {
        window.location.href = 'index.php?view=list<?= !empty($filtro_tipo) ? '&tipo=' . $filtro_tipo : '' ?>';
    }
    
    // Tecla M - Visualização mensal
    if (e.key === 'm' || e.key === 'M') {
        window.location.href = '?view=month&mes=<?= $mes ?>&ano=<?= $ano ?><?= !empty($filtro_tipo) ? '&tipo=' . $filtro_tipo : '' ?>';
    }
    
    // Tecla Y - Visualização anual
    if (e.key === 'y' || e.key === 'Y') {
        window.location.href = '?view=year&ano=<?= $ano ?><?= !empty($filtro_tipo) ? '&tipo=' . $filtro_tipo : '' ?>';
    }
});

// Tooltip com atalhos
window.addEventListener('load', function() {
    const tooltip = document.createElement('div');
    tooltip.style.cssText = `
        position: fixed;
        bottom: 20px;
        right: 20px;
        background: rgba(0,0,0,0.8);
        color: white;
        padding: 12px 18px;
        border-radius: 8px;
        font-size: 12px;
        z-index: 1000;
        display: none;
        animation: fadeIn 0.3s ease;
    `;
    tooltip.innerHTML = `
        <strong>Atalhos:</strong><br>
        ← → Navegar | H: Hoje | L: Lista | M: Mês | Y: Ano
    `;
    document.body.appendChild(tooltip);
    
    // Mostrar tooltip ao pressionar ?
    document.addEventListener('keydown', function(e) {
        if (e.key === '?' || e.key === '/') {
            tooltip.style.display = tooltip.style.display === 'none' ? 'block' : 'none';
        }
    });
});
</script>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Agenda', $conteudo, 'agenda');
?>