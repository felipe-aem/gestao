<?php
// modules/compromissos/calendario.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';
require_once '../../includes/layout.php';

$usuario_logado = Auth::user();
$usuario_id = $usuario_logado['usuario_id'];
$nivel_acesso = $usuario_logado['nivel_acesso'];
$eh_admin = in_array($nivel_acesso, ['Admin', 'Administrador', 'Socio', 'Diretor']);

// Mês e ano atual ou filtrado
$mes = isset($_GET['mes']) ? (int)$_GET['mes'] : (int)date('m');
$ano = isset($_GET['ano']) ? (int)$_GET['ano'] : (int)date('Y');

// Validar mês e ano
if ($mes < 1 || $mes > 12) $mes = (int)date('m');
if ($ano < 2020 || $ano > 2050) $ano = (int)date('Y');

// Filtros
$filtro_tipo = $_GET['tipo'] ?? '';
$filtro_responsavel = $_GET['responsavel'] ?? ($eh_admin ? '' : $usuario_id);

// Primeiro e último dia do mês
$primeiro_dia = new DateTime("$ano-$mes-01");
$ultimo_dia = (clone $primeiro_dia)->modify('last day of this month');

// Buscar todos os compromissos do mês
$compromissos_mes = [];

try {
    // Buscar PRAZOS
    if (empty($filtro_tipo) || $filtro_tipo === 'prazo') {
        $where = ["DATE(p.data_vencimento) BETWEEN ? AND ?"];
        $params = [$primeiro_dia->format('Y-m-d'), $ultimo_dia->format('Y-m-d')];
        
        if ($filtro_responsavel) {
            $where[] = "p.responsavel_id = ?";
            $params[] = $filtro_responsavel;
        }
        
        $where_clause = implode(' AND ', $where);
        
        $sql = "SELECT 
            'prazo' as tipo,
            p.id,
            p.titulo,
            p.data_vencimento as data_compromisso,
            p.status,
            p.prioridade,
            pr.numero_processo,
            pr.cliente_nome,
            u.nome as responsavel_nome
            FROM prazos p
            INNER JOIN processos pr ON p.processo_id = pr.id
            LEFT JOIN usuarios u ON p.responsavel_id = u.id
            WHERE {$where_clause}";
        
        $stmt = executeQuery($sql, $params);
        $compromissos_mes = array_merge($compromissos_mes, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
} catch (Exception $e) {
    error_log("Erro ao buscar prazos: " . $e->getMessage());
}

try {
    // Buscar TAREFAS
    if (empty($filtro_tipo) || $filtro_tipo === 'tarefa') {
        $where = ["DATE(t.data_vencimento) BETWEEN ? AND ?"];
        $params = [$primeiro_dia->format('Y-m-d'), $ultimo_dia->format('Y-m-d')];
        
        if ($filtro_responsavel) {
            $where[] = "t.responsavel_id = ?";
            $params[] = $filtro_responsavel;
        }
        
        $where_clause = implode(' AND ', $where);
        
        $sql = "SELECT 
            'tarefa' as tipo,
            t.id,
            t.titulo,
            t.data_vencimento as data_compromisso,
            t.status,
            t.prioridade,
            pr.numero_processo,
            pr.cliente_nome,
            u.nome as responsavel_nome
            FROM tarefas t
            LEFT JOIN processos pr ON t.processo_id = pr.id
            LEFT JOIN usuarios u ON t.responsavel_id = u.id
            WHERE {$where_clause} AND t.data_vencimento IS NOT NULL";
        
        $stmt = executeQuery($sql, $params);
        $compromissos_mes = array_merge($compromissos_mes, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
} catch (Exception $e) {
    error_log("Erro ao buscar tarefas: " . $e->getMessage());
}

try {
    // Buscar AUDIÊNCIAS (se a tabela existir)
    if (empty($filtro_tipo) || $filtro_tipo === 'audiencia') {
        $where = ["DATE(a.data_inicio) BETWEEN ? AND ?"];
        $params = [$primeiro_dia->format('Y-m-d'), $ultimo_dia->format('Y-m-d')];
        
        if ($filtro_responsavel) {
            $where[] = "a.responsavel_id = ?";
            $params[] = $filtro_responsavel;
        }
        
        $where_clause = implode(' AND ', $where);
        
        $sql = "SELECT 
            'audiencia' as tipo,
            a.id,
            a.titulo,
            a.data_inicio as data_compromisso,
            a.status,
            a.prioridade,
            pr.numero_processo,
            pr.cliente_nome,
            u.nome as responsavel_nome
            FROM audiencias a
            INNER JOIN processos pr ON a.processo_id = pr.id
            LEFT JOIN usuarios u ON a.responsavel_id = u.id
            WHERE {$where_clause}";
        
        $stmt = executeQuery($sql, $params);
        $compromissos_mes = array_merge($compromissos_mes, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
} catch (Exception $e) {
    error_log("Erro ao buscar audiências (tabela pode não existir): " . $e->getMessage());
}

try {
    // Buscar AGENDA (eventos da tabela agenda)
    if (empty($filtro_tipo) || $filtro_tipo === 'evento') {
        $where = ["DATE(ag.data_inicio) BETWEEN ? AND ?"];
        $params = [$primeiro_dia->format('Y-m-d'), $ultimo_dia->format('Y-m-d')];
        
        $where_clause = implode(' AND ', $where);
        
        $sql = "SELECT 
            'evento' as tipo,
            ag.id,
            ag.titulo,
            ag.data_inicio as data_compromisso,
            ag.status,
            'normal' as prioridade,
            NULL as numero_processo,
            c.nome as cliente_nome,
            u.nome as responsavel_nome
            FROM agenda ag
            LEFT JOIN clientes c ON ag.cliente_id = c.id
            LEFT JOIN usuarios u ON ag.responsavel_id = u.id
            WHERE {$where_clause}";
        
        $stmt = executeQuery($sql, $params);
        $compromissos_mes = array_merge($compromissos_mes, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
} catch (Exception $e) {
    error_log("Erro ao buscar eventos da agenda: " . $e->getMessage());
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

// Nome do mês em português
$meses = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];

// Buscar usuários para filtro
$sql_users = "SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome";
$stmt_users = executeQuery($sql_users);
$usuarios = $stmt_users->fetchAll(PDO::FETCH_ASSOC);

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
        align-items: center;
        flex-wrap: wrap;
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

    .filter-group select {
        padding: 10px 12px;
        border: 1px solid #ddd;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.3s;
        min-width: 150px;
    }

    .filter-group select:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
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
    }

    .nav-btn:hover {
        background: #667eea;
        color: white;
        transform: translateY(-2px);
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

    .event-prazo {
        background: rgba(255, 193, 7, 0.15);
        border-left-color: #ffc107;
        color: #856404;
    }

    .event-tarefa {
        background: rgba(102, 126, 234, 0.15);
        border-left-color: #667eea;
        color: #4a5cb8;
    }

    .event-audiencia {
        background: rgba(40, 167, 69, 0.15);
        border-left-color: #28a745;
        color: #155724;
    }

    .event-evento {
        background: rgba(23, 162, 184, 0.15);
        border-left-color: #17a2b8;
        color: #0c5460;
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

    .event-count:hover {
        background: rgba(108, 117, 125, 0.25);
    }

    .legend {
        display: flex;
        gap: 20px;
        margin-top: 20px;
        flex-wrap: wrap;
        justify-content: center;
    }

    .legend-item {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 13px;
        color: #666;
    }

    .legend-color {
        width: 16px;
        height: 16px;
        border-radius: 3px;
        border-left: 3px solid;
    }

    .legend-color.prazo {
        background: rgba(255, 193, 7, 0.15);
        border-left-color: #ffc107;
    }

    .legend-color.tarefa {
        background: rgba(102, 126, 234, 0.15);
        border-left-color: #667eea;
    }

    .legend-color.audiencia {
        background: rgba(40, 167, 69, 0.15);
        border-left-color: #28a745;
    }

    .legend-color.evento {
        background: rgba(23, 162, 184, 0.15);
        border-left-color: #17a2b8;
    }

    @media (max-width: 1024px) {
        .calendar-grid {
            gap: 5px;
        }

        .calendar-day {
            min-height: 100px;
            padding: 5px;
        }

        .event-item {
            font-size: 10px;
            padding: 3px 6px;
        }
    }

    @media (max-width: 768px) {
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

        .day-number {
            font-size: 18px;
            margin-bottom: 10px;
        }

        .event-item {
            font-size: 13px;
            padding: 8px 12px;
            white-space: normal;
        }

        .view-toggle {
            width: 100%;
        }

        .view-btn {
            flex: 1;
            justify-content: center;
        }

        .calendar-navigation {
            flex-direction: column;
            text-align: center;
        }

        .calendar-nav-buttons {
            width: 100%;
            justify-content: space-between;
        }
    }
</style>

<div class="page-header">
    <h2>
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
            <line x1="16" y1="2" x2="16" y2="6"></line>
            <line x1="8" y1="2" x2="8" y2="6"></line>
            <line x1="3" y1="10" x2="21" y2="10"></line>
        </svg>
        Calendário de Compromissos
    </h2>
    <div class="view-toggle">
        <a href="index.php?<?= http_build_query(['tipo' => $filtro_tipo, 'responsavel' => $filtro_responsavel]) ?>" class="view-btn">
            📋 Lista
        </a>
        <a href="calendario.php?<?= http_build_query(['mes' => $mes, 'ano' => $ano, 'tipo' => $filtro_tipo, 'responsavel' => $filtro_responsavel]) ?>" class="view-btn active">
            📅 Calendário
        </a>
    </div>
</div>

<!-- Filtros -->
<div class="filters-section">
    <form method="GET" class="filters-row">
        <input type="hidden" name="mes" value="<?= $mes ?>">
        <input type="hidden" name="ano" value="<?= $ano ?>">
        
        <div class="filter-group">
            <label>Tipo</label>
            <select name="tipo" onchange="this.form.submit()">
                <option value="">Todos</option>
                <option value="prazo" <?= $filtro_tipo === 'prazo' ? 'selected' : '' ?>>⏰ Prazos</option>
                <option value="tarefa" <?= $filtro_tipo === 'tarefa' ? 'selected' : '' ?>>✓ Tarefas</option>
                <option value="audiencia" <?= $filtro_tipo === 'audiencia' ? 'selected' : '' ?>>📅 Audiências</option>
                <option value="evento" <?= $filtro_tipo === 'evento' ? 'selected' : '' ?>>🎯 Eventos</option>
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
    </form>
</div>

<!-- Navegação do Calendário -->
<div class="calendar-navigation">
    <a href="?mes=<?= $mes_anterior->format('m') ?>&ano=<?= $mes_anterior->format('Y') ?>&<?= http_build_query(['tipo' => $filtro_tipo, 'responsavel' => $filtro_responsavel]) ?>" 
       class="nav-btn">
        ← <?= $meses[(int)$mes_anterior->format('m')] ?>
    </a>
    
    <div class="calendar-title">
        <?= $meses[$mes] ?> de <?= $ano ?>
    </div>
    
    <a href="?mes=<?= $mes_proximo->format('m') ?>&ano=<?= $mes_proximo->format('Y') ?>&<?= http_build_query(['tipo' => $filtro_tipo, 'responsavel' => $filtro_responsavel]) ?>" 
       class="nav-btn">
        <?= $meses[(int)$mes_proximo->format('m')] ?> →
    </a>
</div>

<!-- Calendário -->
<div class="calendar-container">
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
                    $link = match($evento['tipo']) {
                        'prazo' => '../prazos/visualizar.php?id=' . $evento['id'],
                        'tarefa' => '../tarefas/visualizar.php?id=' . $evento['id'],
                        'audiencia' => '../audiencias/visualizar.php?id=' . $evento['id'],
                        'evento' => '../agenda/visualizar.php?id=' . $evento['id'],
                        default => '#'
                    };
                    
                    $hora = date('H:i', strtotime($evento['data_compromisso']));
                    
                    echo '<div class="event-item event-' . $evento['tipo'] . '" onclick="window.location.href=\'' . $link . '\'" title="' . htmlspecialchars($evento['titulo']) . '">';
                    echo $hora . ' ' . htmlspecialchars(substr($evento['titulo'], 0, 15));
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
        <div class="legend-item">
            <div class="legend-color evento"></div>
            <span>🎯 Eventos</span>
        </div>
    </div>
</div>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Calendário de Compromissos', $conteudo, 'compromissos');
?>