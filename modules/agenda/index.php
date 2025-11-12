<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../includes/auth.php';
Auth::protect();
require_once '../../config/database.php';
require_once __DIR__ . '/../../includes/search_helpers.php';

// Verificação de módulo em desenvolvimento
require_once __DIR__ . '/../../config/modules_config.php';
$moduloAtual = 'agenda';
$usuarioLogado = $_SESSION['usuario_id'] ?? null;

if (verificarModuloEmDesenvolvimento($moduloAtual, $usuarioLogado)) {
    include __DIR__ . '/../../config/paginas/em_desenvolvimento.html';
    exit;
}

require_once '../../includes/layout.php';
require_once '../../includes/EnvolvidosHelper.php';
$usuario_logado = Auth::user();

// Filtro para não mostrar itens excluídos
$deleted_filter_tarefas = " AND t.deleted_at IS NULL";
$deleted_filter_prazos = " AND p.deleted_at IS NULL";
$deleted_filter_audiencias = " AND a.deleted_at IS NULL";
$deleted_filter_agenda = " AND ag.deleted_at IS NULL";
$filtro_status = $_GET['status'] ?? '';
$filtro_vencimento = $_GET['vencimento'] ?? '';
$hoje_data = date('Y-m-d');

// Parâmetros de visualização
$view = $_GET['view'] ?? 'list';
$filtro_tipo = $_GET['tipo'] ?? '';
$filtro_periodo = $_GET['periodo'] ?? 'este_mes';
$filtro_busca = $_GET['busca'] ?? '';

// Sistema de filtros - usuários e núcleos
$usuarios_selecionados = [];
$nucleo_selecionado = $_GET['nucleo'] ?? '';

// ✅ CORREÇÃO: Priorizar núcleo sobre usuários individuais
if (!empty($nucleo_selecionado)) {
    // Se núcleo está selecionado, buscar TODOS os usuários dele
    try {
        $sql_nucleo = "SELECT id FROM usuarios WHERE nucleo_id = ? AND ativo = 1";
        $stmt_nucleo = executeQuery($sql_nucleo, [$nucleo_selecionado]);
        $usuarios_selecionados = $stmt_nucleo->fetchAll(PDO::FETCH_COLUMN);
        
        // Debug (pode remover depois)
        error_log("🏢 Núcleo {$nucleo_selecionado}: " . count($usuarios_selecionados) . " usuários encontrados");
        error_log("👥 IDs: " . implode(', ', $usuarios_selecionados));
        
        // Se não encontrou nenhum usuário, usar o usuário logado
        if (empty($usuarios_selecionados)) {
            error_log("⚠️ Nenhum usuário encontrado no núcleo {$nucleo_selecionado}");
            $usuarios_selecionados = [$usuario_logado['usuario_id']];
        }
    } catch (Exception $e) {
        error_log("❌ Erro ao buscar usuários do núcleo: " . $e->getMessage());
        $usuarios_selecionados = [$usuario_logado['usuario_id']];
    }
} elseif (!empty($_GET['usuarios'])) {
    // Se não tem núcleo, mas tem usuários específicos selecionados
    $usuarios_selecionados = array_filter(explode(',', $_GET['usuarios']), 'strlen');
} else {
    // Se não tem nem núcleo nem usuários, usar apenas o usuário logado
    $usuarios_selecionados = [$usuario_logado['usuario_id']];
}

$usuarios_selecionados = array_filter($usuarios_selecionados, function($id) {
    return !empty($id) && is_numeric($id);
});

if (empty($usuarios_selecionados)) {
    $usuarios_selecionados = [$usuario_logado['usuario_id']];
}

// Determinar período
switch ($filtro_periodo) {
    case 'hoje':
        $data_inicio = date('Y-m-d 00:00:00');
        $data_fim = date('Y-m-d 23:59:59');
        break;
    case 'amanha':
        $data_inicio = date('Y-m-d 00:00:00', strtotime('+1 day'));
        $data_fim = date('Y-m-d 23:59:59', strtotime('+1 day'));
        break;
    case 'esta_semana':
        $inicio_semana = new DateTime('monday this week');
        $fim_semana = new DateTime('sunday this week');
        $data_inicio = $inicio_semana->format('Y-m-d 00:00:00');
        $data_fim = $fim_semana->format('Y-m-d 23:59:59');
        break;
    case 'proxima_semana':
        $inicio_semana = new DateTime('monday next week');
        $fim_semana = new DateTime('sunday next week');
        $data_inicio = $inicio_semana->format('Y-m-d 00:00:00');
        $data_fim = $fim_semana->format('Y-m-d 23:59:59');
        break;
    case 'este_mes':
        $primeiro_dia = new DateTime('first day of this month');
        $ultimo_dia = new DateTime('last day of this month');
        $data_inicio = $primeiro_dia->format('Y-m-d 00:00:00');
        $data_fim = $ultimo_dia->format('Y-m-d 23:59:59');
        break;
    case 'proximo_mes':
        $primeiro_dia = new DateTime('first day of next month');
        $ultimo_dia = new DateTime('last day of next month');
        $data_inicio = $primeiro_dia->format('Y-m-d 00:00:00');
        $data_fim = $ultimo_dia->format('Y-m-d 23:59:59');
        break;
    case 'personalizado':
        if (!empty($_GET['data_inicio']) && !empty($_GET['data_fim'])) {
            $data_inicio = $_GET['data_inicio'] . ' 00:00:00';
            $data_fim = $_GET['data_fim'] . ' 23:59:59';
        } else {
            $data_inicio = date('Y-m-d 00:00:00');
            $data_fim = date('Y-m-d 23:59:59', strtotime('+30 days'));
        }
        break;
    case 'proximos_30':
    default:
        $data_inicio = date('Y-m-d 00:00:00');
        $data_fim = date('Y-m-d 23:59:59', strtotime('+30 days'));
        break;
}

// ===== FUNÇÃO PARA VERIFICAR SE ESTÁ ATRASADO =====
function isItemAtrasado($item) {
    if (!isset($item['data_compromisso'])) return false;
    
    $status_finalizados = ['concluida', 'concluido', 'realizada', 'cancelada', 'cancelado'];
    if (in_array(strtolower($item['status'] ?? ''), $status_finalizados)) {
        return false;
    }
    
    $data_item = new DateTime($item['data_compromisso']);
    $hoje = new DateTime();
    $hoje->setTime(0, 0, 0);
    
    return $data_item < $hoje;
}

$compromissos = [];

// BUSCAR EVENTOS
try {
    if (empty($filtro_tipo) || $filtro_tipo === 'evento') {
        $where_conditions = [
            "(
                (a.data_inicio BETWEEN ? AND ?) 
                OR 
                (a.data_inicio < ? AND a.status NOT IN ('Realizado', 'Cancelado'))
            )"
        ];
        $params = [$data_inicio, $data_fim, $data_inicio];
        
        if (!empty($filtro_busca)) {
            $where_conditions[] = "(a.titulo LIKE ? OR a.descricao LIKE ?)";
            $params[] = "%{$filtro_busca}%";
            $params[] = "%{$filtro_busca}%";
        }
        
        // ✅ NOVO: Filtro de STATUS
        if (!empty($filtro_status)) {
            $status_list = is_array($filtro_status) ? $filtro_status : explode(',', $filtro_status);
            $placeholders = str_repeat('?,', count($status_list) - 1) . '?';
            $where_conditions[] = "a.status IN ($placeholders)";
            $params = array_merge($params, $status_list);
        }
        
        // ✅ NOVO: Filtro de VENCIMENTO
        if ($filtro_vencimento === 'atrasados') {
            $where_conditions[] = "a.data_inicio < ? AND a.status NOT IN ('Realizado', 'Cancelado')";
            $params[] = $hoje_data;
        } elseif ($filtro_vencimento === 'vence_hoje') {
            $where_conditions[] = "DATE(a.data_inicio) = ?";
            $params[] = $hoje_data;
        } elseif ($filtro_vencimento === 'proximos_3_dias') {
            $data_fim_3 = date('Y-m-d', strtotime('+3 days'));
            $where_conditions[] = "a.data_inicio BETWEEN ? AND ?";
            $params[] = $hoje_data;
            $params[] = $data_fim_3 . ' 23:59:59';
        } elseif ($filtro_vencimento === 'proximos_7_dias') {
            $data_fim_7 = date('Y-m-d', strtotime('+7 days'));
            $where_conditions[] = "a.data_inicio BETWEEN ? AND ?";
            $params[] = $hoje_data;
            $params[] = $data_fim_7 . ' 23:59:59';
        }
        
        if (!empty($usuarios_selecionados)) {
            $placeholders = str_repeat('?,', count($usuarios_selecionados) - 1) . '?';
            $where_conditions[] = "(EXISTS (SELECT 1 FROM agenda_participantes ap WHERE ap.agenda_id = a.id AND ap.usuario_id IN ($placeholders) AND ap.status_participacao IN ('Organizador', 'Confirmado', 'Pendente')) OR a.criado_por IN ($placeholders))";
            $params = array_merge($params, $usuarios_selecionados, $usuarios_selecionados);
        }
        
        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        
        $sql = "SELECT 
            'evento' as tipo_compromisso, 
            a.id, a.titulo, a.descricao, 
            a.data_inicio as data_compromisso, 
            a.data_fim, 
            a.status, a.prioridade, 
            a.tipo as subtipo, 
            a.local_evento, 
            a.observacoes,
            c.nome as cliente_nome, 
            p.numero_processo, 
            org.nome as responsavel_nome 
        FROM agenda a
        LEFT JOIN clientes c ON a.cliente_id = c.id 
        LEFT JOIN processos p ON a.processo_id = p.id 
        LEFT JOIN agenda_participantes ap_org ON a.id = ap_org.agenda_id AND ap_org.status_participacao = 'Organizador' 
        LEFT JOIN usuarios org ON ap_org.usuario_id = org.id 
        $where_clause 
        AND a.deleted_at IS NULL
        GROUP BY a.id 
        ORDER BY a.data_inicio ASC";

        $stmt = executeQuery($sql, $params);
        $compromissos = array_merge($compromissos, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
} catch (Exception $e) {
    error_log("Erro ao buscar eventos: " . $e->getMessage());
}

// BUSCAR PRAZOS
try {
    if (empty($filtro_tipo) || $filtro_tipo === 'prazo') {
        $where_prazo = [
            "(
                (DATE(p.data_vencimento) BETWEEN ? AND ?) 
                OR 
                (p.data_vencimento < ? AND p.status NOT IN ('concluido', 'cancelado'))
            )"
        ];
        $params_prazo = [$data_inicio, $data_fim, $data_inicio]; 

        if (!empty($filtro_busca)) {
            $where_prazo[] = "(p.titulo LIKE ? OR p.descricao LIKE ?)";
            $params_prazo[] = "%{$filtro_busca}%";
            $params_prazo[] = "%{$filtro_busca}%";
        }
        
        // ✅ NOVO: Filtro de STATUS
        if (!empty($filtro_status)) {
            $status_list = is_array($filtro_status) ? $filtro_status : explode(',', $filtro_status);
            $placeholders = str_repeat('?,', count($status_list) - 1) . '?';
            $where_prazo[] = "p.status IN ($placeholders)";
            $params_prazo = array_merge($params_prazo, $status_list);
        }
        
        // ✅ NOVO: Filtro de VENCIMENTO
        if ($filtro_vencimento === 'atrasados') {
            $where_prazo[] = "p.data_vencimento < ? AND p.status NOT IN ('concluido', 'cancelado')";
            $params_prazo[] = $hoje_data;
        } elseif ($filtro_vencimento === 'vence_hoje') {
            $where_prazo[] = "DATE(p.data_vencimento) = ?";
            $params_prazo[] = $hoje_data;
        } elseif ($filtro_vencimento === 'proximos_3_dias') {
            $data_fim_3 = date('Y-m-d', strtotime('+3 days'));
            $where_prazo[] = "p.data_vencimento BETWEEN ? AND ?";
            $params_prazo[] = $hoje_data;
            $params_prazo[] = $data_fim_3 . ' 23:59:59';
        } elseif ($filtro_vencimento === 'proximos_7_dias') {
            $data_fim_7 = date('Y-m-d', strtotime('+7 days'));
            $where_prazo[] = "p.data_vencimento BETWEEN ? AND ?";
            $params_prazo[] = $hoje_data;
            $params_prazo[] = $data_fim_7 . ' 23:59:59';
        }
        
        if (!empty($usuarios_selecionados)) {
            $placeholders = str_repeat('?,', count($usuarios_selecionados) - 1) . '?';
            $where_prazo[] = "(p.responsavel_id IN ($placeholders) OR EXISTS (SELECT 1 FROM prazo_envolvidos pe WHERE pe.prazo_id = p.id AND pe.usuario_id IN ($placeholders)))";
            $params_prazo = array_merge($params_prazo, $usuarios_selecionados, $usuarios_selecionados);
        }
        
        $where_prazo_clause = implode(' AND ', $where_prazo);
        
        $sql_prazos = "SELECT 
            'prazo' as tipo_compromisso, 
            p.id, p.titulo, p.descricao, 
            p.data_vencimento as data_compromisso, 
            p.data_vencimento as data_fim, 
            p.status, p.prioridade,
            p.tipo_prazo,
            'Prazo Processual' as subtipo, 
            NULL as local_evento, 
            pr.cliente_nome, pr.numero_processo, 
            u.nome as responsavel_nome,
            tr.usuario_revisor_id,
            u_revisor.nome as revisor_nome,
            tr.status as status_revisao
        FROM prazos p
        INNER JOIN processos pr ON p.processo_id = pr.id 
        LEFT JOIN usuarios u ON p.responsavel_id = u.id 
        LEFT JOIN tarefa_revisoes tr ON p.id = tr.tarefa_origem_id 
            AND tr.tipo_origem = 'prazo' 
            AND tr.status = 'pendente'
        LEFT JOIN usuarios u_revisor ON tr.usuario_revisor_id = u_revisor.id
        WHERE p.deleted_at IS NULL AND {$where_prazo_clause} 
        ORDER BY p.data_vencimento ASC";
        
        $stmt = executeQuery($sql_prazos, $params_prazo);
        $compromissos = array_merge($compromissos, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
} catch (Exception $e) {
    error_log("Erro ao buscar prazos: " . $e->getMessage());
}

// BUSCAR TAREFAS
try {
    if (empty($filtro_tipo) || $filtro_tipo === 'tarefa') {
        $where_tarefa = [
            "(
                (DATE(t.data_vencimento) BETWEEN ? AND ?) 
                OR 
                (t.data_vencimento < ? AND t.status NOT IN ('concluida', 'cancelada'))
            )", 
            "t.data_vencimento IS NOT NULL"
        ];
        $params_tarefa = [$data_inicio, $data_fim, $data_inicio]; // Adiciona data_inicio mais uma vez

        if (!empty($filtro_busca)) {
            $where_tarefa[] = "(t.titulo LIKE ? OR t.descricao LIKE ?)";
            $params_tarefa[] = "%{$filtro_busca}%";
            $params_tarefa[] = "%{$filtro_busca}%";
        }
        
        // ✅ NOVO: Filtro de STATUS
        if (!empty($filtro_status)) {
            $status_list = is_array($filtro_status) ? $filtro_status : explode(',', $filtro_status);
            $placeholders = str_repeat('?,', count($status_list) - 1) . '?';
            $where_tarefa[] = "t.status IN ($placeholders)";
            $params_tarefa = array_merge($params_tarefa, $status_list);
        }
        
        // ✅ NOVO: Filtro de VENCIMENTO
        if ($filtro_vencimento === 'atrasados') {
            $where_tarefa[] = "t.data_vencimento < ? AND t.status NOT IN ('concluida', 'cancelada')";
            $params_tarefa[] = $hoje_data;
        } elseif ($filtro_vencimento === 'vence_hoje') {
            $where_tarefa[] = "DATE(t.data_vencimento) = ?";
            $params_tarefa[] = $hoje_data;
        } elseif ($filtro_vencimento === 'proximos_3_dias') {
            $data_fim_3 = date('Y-m-d', strtotime('+3 days'));
            $where_tarefa[] = "t.data_vencimento BETWEEN ? AND ?";
            $params_tarefa[] = $hoje_data;
            $params_tarefa[] = $data_fim_3 . ' 23:59:59';
        } elseif ($filtro_vencimento === 'proximos_7_dias') {
            $data_fim_7 = date('Y-m-d', strtotime('+7 days'));
            $where_tarefa[] = "t.data_vencimento BETWEEN ? AND ?";
            $params_tarefa[] = $hoje_data;
            $params_tarefa[] = $data_fim_7 . ' 23:59:59';
        }
        
        if (!empty($usuarios_selecionados)) {
            $placeholders = str_repeat('?,', count($usuarios_selecionados) - 1) . '?';
            $where_tarefa[] = "(t.responsavel_id IN ($placeholders) OR EXISTS (SELECT 1 FROM tarefa_envolvidos te WHERE te.tarefa_id = t.id AND te.usuario_id IN ($placeholders)))";
            $params_tarefa = array_merge($params_tarefa, $usuarios_selecionados, $usuarios_selecionados);
        }
        
        $where_tarefa_clause = implode(' AND ', $where_tarefa);
        
        $sql_tarefas = "SELECT 
            'tarefa' as tipo_compromisso, 
            t.id, t.titulo, t.descricao, 
            t.data_vencimento as data_compromisso, 
            t.data_vencimento as data_fim, 
            t.status, t.prioridade, 
            t.tipo_tarefa,
            'Tarefa' as subtipo, 
            NULL as local_evento, 
            pr.cliente_nome, pr.numero_processo, 
            u.nome as responsavel_nome,
            tr.usuario_revisor_id,
            u_revisor.nome as revisor_nome,
            tr.status as status_revisao
        FROM tarefas t
        LEFT JOIN processos pr ON t.processo_id = pr.id 
        LEFT JOIN usuarios u ON t.responsavel_id = u.id 
        LEFT JOIN tarefa_revisoes tr ON t.id = tr.tarefa_origem_id 
            AND tr.tipo_origem = 'tarefa' 
            AND tr.status = 'pendente'
        LEFT JOIN usuarios u_revisor ON tr.usuario_revisor_id = u_revisor.id
        WHERE t.deleted_at IS NULL AND {$where_tarefa_clause} 
        ORDER BY t.data_vencimento ASC";
        
        $stmt = executeQuery($sql_tarefas, $params_tarefa);
        $compromissos = array_merge($compromissos, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
} catch (Exception $e) {
    error_log("Erro ao buscar tarefas: " . $e->getMessage());
}

// BUSCAR AUDIÊNCIAS
try {
    if (empty($filtro_tipo) || $filtro_tipo === 'audiencia') {
        $where_aud = [
            "(
                (DATE(a.data_inicio) BETWEEN ? AND ?) 
                OR 
                (a.data_inicio < ? AND a.status NOT IN ('realizada', 'cancelada'))
            )"
        ];
        $params_aud = [$data_inicio, $data_fim, $data_inicio];
        
        if (!empty($filtro_busca)) {
            $where_aud[] = "(a.titulo LIKE ? OR a.descricao LIKE ?)";
            $params_aud[] = "%{$filtro_busca}%";
            $params_aud[] = "%{$filtro_busca}%";
        }
        
        // ✅ NOVO: Filtro de STATUS
        if (!empty($filtro_status)) {
            $status_list = is_array($filtro_status) ? $filtro_status : explode(',', $filtro_status);
            $placeholders = str_repeat('?,', count($status_list) - 1) . '?';
            $where_aud[] = "a.status IN ($placeholders)";
            $params_aud = array_merge($params_aud, $status_list);
        }
        
        // ✅ NOVO: Filtro de VENCIMENTO
        if ($filtro_vencimento === 'atrasados') {
            $where_aud[] = "a.data_inicio < ? AND a.status NOT IN ('realizada', 'cancelada')";
            $params_aud[] = $hoje_data;
        } elseif ($filtro_vencimento === 'vence_hoje') {
            $where_aud[] = "DATE(a.data_inicio) = ?";
            $params_aud[] = $hoje_data;
        } elseif ($filtro_vencimento === 'proximos_3_dias') {
            $data_fim_3 = date('Y-m-d', strtotime('+3 days'));
            $where_aud[] = "a.data_inicio BETWEEN ? AND ?";
            $params_aud[] = $hoje_data;
            $params_aud[] = $data_fim_3 . ' 23:59:59';
        } elseif ($filtro_vencimento === 'proximos_7_dias') {
            $data_fim_7 = date('Y-m-d', strtotime('+7 days'));
            $where_aud[] = "a.data_inicio BETWEEN ? AND ?";
            $params_aud[] = $hoje_data;
            $params_aud[] = $data_fim_7 . ' 23:59:59';
        }
        
        if (!empty($usuarios_selecionados)) {
            $placeholders = str_repeat('?,', count($usuarios_selecionados) - 1) . '?';
            $where_aud[] = "(a.responsavel_id IN ($placeholders) OR EXISTS (SELECT 1 FROM audiencia_envolvidos ae WHERE ae.audiencia_id = a.id AND ae.usuario_id IN ($placeholders)))";
            $params_aud = array_merge($params_aud, $usuarios_selecionados, $usuarios_selecionados);
        }
        
        $where_aud_clause = implode(' AND ', $where_aud);
        
        $sql_audiencias = "SELECT 
            'audiencia' as tipo_compromisso, 
            a.id, a.titulo, a.descricao, 
            a.data_inicio as data_compromisso, 
            a.data_fim, 
            a.status, a.prioridade, 
            a.tipo as subtipo, 
            a.local_evento, 
            pr.cliente_nome, pr.numero_processo, 
            u.nome as responsavel_nome 
        FROM audiencias a
        INNER JOIN processos pr ON a.processo_id = pr.id 
        LEFT JOIN usuarios u ON a.responsavel_id = u.id 
        WHERE a.deleted_at IS NULL AND {$where_aud_clause} 
        ORDER BY a.data_inicio ASC";  
        
        $stmt = executeQuery($sql_audiencias, $params_aud);
        $compromissos = array_merge($compromissos, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
} catch (Exception $e) {
    error_log("Erro ao buscar audiências: " . $e->getMessage());
}

usort($compromissos, function($a, $b) {
    return strtotime($a['data_compromisso']) - strtotime($b['data_compromisso']);
});

$stats = [
    'total' => count($compromissos),
    'eventos' => 0,
    'prazos' => 0,
    'tarefas' => 0,
    'audiencias' => 0,
    'hoje' => 0,
    'urgentes' => 0
];

$hoje = date('Y-m-d');
foreach ($compromissos as $comp) {
    $stats[$comp['tipo_compromisso'] . 's']++;
    if (date('Y-m-d', strtotime($comp['data_compromisso'])) === $hoje) {
        $stats['hoje']++;
    }
    if ($comp['prioridade'] === 'urgente' || $comp['prioridade'] === 'Urgente') {
        $stats['urgentes']++;
    }
}

$usuarios = [];
if (Auth::user()) {
    $sql = "SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome";
    $stmt = executeQuery($sql);
    $usuarios = $stmt->fetchAll();
}

$nucleos = [];
try {
    $sql_nucleos = "SELECT id, nome FROM nucleos WHERE ativo = 1 ORDER BY nome";
    $stmt_nucleos = executeQuery($sql_nucleos);
    $nucleos = $stmt_nucleos->fetchAll();
} catch (Exception $e) {
    error_log("Erro ao buscar núcleos: " . $e->getMessage());
}

// ============================================================================
// FUNÇÃO DE RENDERIZAÇÃO - COPIE TODO O CONTEÚDO HTML DO SEU ARQUIVO ORIGINAL AQUI
// ============================================================================
function renderConteudoAgenda($stats, $compromissos, $usuarios, $nucleos, $usuarios_selecionados, $usuario_logado, $filtro_tipo, $filtro_periodo, $filtro_busca, $nucleo_selecionado, $hoje, $view) {
ob_start();
?>

<style>
    /* Badges para tipos de tarefa */
    .badge-tipo-tarefa {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 600;
        margin-right: 5px;
        text-transform: uppercase;
    }
    
    .badge-revisao {
        background: #ffc107;
        color: #000;
    }
    
    .badge-protocolo {
        background: #28a745;
        color: white;
    }
    
    .badge-correcao {
        background: #dc3545;
        color: white;
    }
    
    /* Destaque na listagem */
    .tarefa-revisao {
        border-left: 4px solid #ffc107 !important;
    }
    
    .tarefa-protocolo {
        border-left: 4px solid #28a745 !important;
    }
    
    .tarefa-correcao {
        border-left: 4px solid #dc3545 !important;
    }
    
    /* Estilos existentes mantidos */
    .page-header {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        padding: 25px;
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        margin-bottom: 30px;
        position: relative;
        z-index: 100; /* ✅ ALTO mas menor que dropdown */
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
        /* ✅ REMOVIDO: position: relative; z-index: 10; */
        /* Deixa herdar o fundo do pai naturalmente */
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
        position: relative;
        z-index: 9999; /* ✅ MESMO NÍVEL DO DROPDOWN */
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
        /* IMPORTANTE: Remover position relative e z-index alto */
        margin-top: 0;
    }
    
    .stat-mini {
        text-align: center;
        padding: 20px 15px;
        background: linear-gradient(135deg, rgba(0,0,0,0.02) 0%, rgba(0,0,0,0.04) 100%);
        border-radius: 12px;
        transition: all 0.3s;
        border-left: 4px solid transparent;
        cursor: pointer;
        /* IMPORTANTE: z-index baixo para não sobrepor nada */
        position: relative;
        z-index: 1;
    }
    
    .stat-mini:hover {
        background: linear-gradient(135deg, rgba(0,0,0,0.04) 0%, rgba(0,0,0,0.06) 100%);
        transform: translateY(-3px);
        box-shadow: 0 6px 20px rgba(0,0,0,0.1);
        z-index: 2;
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
        font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
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
    
    .filters-container {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        padding: 25px;
        margin-bottom: 30px;
        position: relative;
        z-index: 1; /* ✅ BAIXO - fica atrás do dropdown */
    }
    .filters-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-bottom: 15px;
    }
    
    .filter-group {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }
    
    .filter-group.full-width {
        grid-column: 1 / -1;
    }
    
    .filter-group label {
        font-size: 13px;
        font-weight: 600;
        color: #333;
        display: flex;
        align-items: center;
        gap: 5px;
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

    /* Estilos para seletor de usuários - LISTBOX */

    .usuarios-selector select option {
        padding: 8px 12px;
        cursor: pointer;
    }

    .usuarios-selector select option:hover {
        background: rgba(102, 126, 234, 0.1);
    }

    .filter-actions {
        display: flex;
        gap: 10px;
        justify-content: flex-end;
        margin-top: 15px;
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
        white-space: nowrap;
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
        transform: translateY(-2px);
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
    
    .compromisso-card.evento { border-left-color: #17a2b8; }
    .compromisso-card.prazo { border-left-color: #ffc107; }
    .compromisso-card.tarefa { border-left-color: #6f42c1; }
    .compromisso-card.audiencia { border-left-color: #28a745; }
    
    /* ===== DESTAQUE PARA ITENS ATRASADOS ===== */
    .compromisso-card.item-atrasado {
        background: rgba(220, 53, 69, 0.08) !important;
        border-left: 4px solid #dc3545 !important;
    }
    
    .compromisso-card.item-atrasado:hover {
        background: rgba(220, 53, 69, 0.12) !important;
    }
    
    .badge-atrasado {
        background: #dc3545;
        color: white;
        padding: 5px 12px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        animation: pulseAtrasado 2s infinite;
        box-shadow: 0 2px 8px rgba(220, 53, 69, 0.3);
        margin-left: 8px;
    }
    
    @keyframes pulseAtrasado {
        0%, 100% { opacity: 1; transform: scale(1); }
        50% { opacity: 0.8; transform: scale(1.05); }
    }
    
    /* Info de filtros ativos */
    .filter-info .filter-tag {
        background: rgba(102, 126, 234, 0.2);
        padding: 3px 10px;
        border-radius: 12px;
        font-size: 12px;
        display: inline-flex;
        align-items: center;
        gap: 5px;
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
    
    .tipo-evento { background: rgba(23, 162, 184, 0.1); color: #0c5460; }
    .tipo-prazo { background: rgba(255, 193, 7, 0.1); color: #856404; }
    .tipo-tarefa { background: rgba(111, 66, 193, 0.1); color: #6f42c1; }
    .tipo-audiencia { background: rgba(40, 167, 69, 0.1); color: #155724; }
    
    .compromisso-title {
        font-weight: 700;
        color: #1a1a1a;
        font-size: 16px;
        margin-bottom: 5px;
    }
    
    .compromisso-time {
        font-size: 14px;
        color: #666;
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

    .filter-info {
        background: rgba(102, 126, 234, 0.1);
        border-left: 4px solid #667eea;
        padding: 12px 15px;
        border-radius: 8px;
        margin-bottom: 15px;
        font-size: 14px;
        color: #667eea;
        font-weight: 600;
    }
    
    .envolvidos-inline {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        flex-wrap: wrap;
    }
    
    .envolvido-mini {
        background: rgba(102, 126, 234, 0.15);
        color: #667eea;
        padding: 3px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        border: 1px solid rgba(102, 126, 234, 0.25);
        white-space: nowrap;
    }
    
    .envolvidos-contador {
        background: rgba(102, 126, 234, 0.1);
        color: #667eea;
        padding: 2px 6px;
        border-radius: 10px;
        font-size: 10px;
        font-weight: 700;
        cursor: help;
    }
    
    .meta-info {
        display: flex;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
    }
    
    .meta-item {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        font-size: 13px;
        color: #666;
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
        
        .header-actions {
            width: 100%;
            justify-content: center;
        }
        
        .compromisso-header {
            flex-direction: column;
        }

        .filters-grid {
            grid-template-columns: 1fr;
        }
    }
    
    /* Estilos adicionais para os badges de tipo */
    .text-purple { color: #6f42c1; }
    .text-orange { color: #fd7e14; }
    
    .badge-tarefa { background-color: #ffc107; color: #000; }
    .badge-prazo { background-color: #dc3545; color: #fff; }
    .badge-audiencia { background-color: #6f42c1; color: #fff; }

    #formularioContainer {
        animation: slideIn 0.3s ease;
    }
    
    @keyframes slideIn {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    /* Dropdown - z-index mais alto */
    .dropdown {
        position: relative;
        display: inline-block;
        z-index: 9999; /* ✅ AUMENTADO - maior que tudo */
    }
    
    .dropdown-menu {
        position: absolute;
        background: white;
        border: 1px solid rgba(0,0,0,.15);
        border-radius: 8px;
        box-shadow: 0 6px 20px rgba(0,0,0,.15);
        min-width: 250px;
        padding: 8px 0;
        margin-top: 4px;
        z-index: 10000; /* ✅ AINDA MAIOR */
        right: 0;
        left: auto;
    }
        
    .dropdown-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 20px;
        color: #333;
        text-decoration: none;
        transition: all 0.2s;
        cursor: pointer;
        white-space: nowrap;
    }
    
    .dropdown-item:hover {
        background: rgba(102, 126, 234, 0.1);
        color: #667eea;
        text-decoration: none;
    }
    
    .dropdown-item i {
        font-size: 16px;
        width: 20px;
        text-align: center;
    }
    
    .dropdown-divider {
        height: 1px;
        background: rgba(0,0,0,.1);
        margin: 8px 0;
    }
    
    /* Botão Dropdown */
    .btn.dropdown-toggle::after {
        display: inline-block;
        margin-left: 8px;
        vertical-align: middle;
        content: "";
        border-top: 4px solid;
        border-right: 4px solid transparent;
        border-bottom: 0;
        border-left: 4px solid transparent;
        z-index: 999999;
    }
    
    .btn.dropdown-toggle.active::after {
        transform: rotate(180deg);
    }
    
    /* View Selector */
    .view-selector {
        display: flex;
        background: rgba(255, 255, 255, 0.9);
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        z-index: 50;
        position: relative;
    }
    
    .view-btn {
        padding: 10px 18px;
        background: transparent;
        border: none;
        color: #666;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        text-decoration: none;
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 6px;
        white-space: nowrap;
    }
    
    .view-btn:hover {
        background: rgba(102, 126, 234, 0.1);
        color: #667eea;
        text-decoration: none;
    }
    
    .view-btn.active {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
    }
    
    .view-btn i {
        font-size: 14px;
    }
</style>
<div class="page-header">
    <div class="page-header-top">
        <h2>📅 Agenda</h2>
        
        <div class="header-actions">
            <!-- Link para Histórico de Revisões -->
            <a href="historico_revisoes.php" class="btn btn-secondary" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border: none;">
                <i class="fas fa-history"></i> Histórico de Revisões
            </a>
            
            <!-- Seletor de Visualização -->
            <div class="view-selector">
                <button type="button" onclick="trocarVisualizacao('list'); return false;" data-view="list" class="view-btn <?= $view === 'list' ? 'active' : '' ?>">
                    <i class="fas fa-list"></i> Lista
                </button>
                <button type="button" onclick="trocarVisualizacao('calendar'); return false;" data-view="calendar" class="view-btn <?= $view === 'calendar' ? 'active' : '' ?>">
                    <i class="fas fa-calendar"></i> Calendário
                </button>
            </div>
            
            <!-- Dropdown de Criação - CORRIGIDO -->
            <div class="dropdown" style="position: relative;">
                <button class="btn btn-primary dropdown-toggle" 
                        type="button" 
                        id="btnNovo" 
                        onclick="toggleDropdownNovo(event)">
                    <i class="fas fa-plus"></i> Novo
                </button>
                <div class="dropdown-menu dropdown-menu-right" 
                     id="dropdownNovo" 
                     style="display: none; position: absolute; right: 0; top: 100%; z-index: 1000;">
                    <a class="dropdown-item" href="#" onclick="abrirFormulario('tarefa'); return false;">
                        <i class="fas fa-tasks text-warning"></i> Tarefa
                    </a>
                    <a class="dropdown-item" href="#" onclick="abrirFormulario('prazo'); return false;">
                        <i class="fas fa-clock text-danger"></i> Prazo
                    </a>
                    <a class="dropdown-item" href="#" onclick="abrirFormulario('audiencia'); return false;">
                        <i class="fas fa-gavel" style="color: #6f42c1;"></i> Audiência
                    </a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item" href="novo.php">
                        <i class="fas fa-calendar text-primary"></i> Reunião/Compromisso
                    </a>
                </div>
            </div>
        </div>
    
        <!-- Estatísticas -->
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
                <div class="stat-mini-number"><?= number_format($stats['urgentes']) ?></div>
                <div class="stat-mini-label">Urgentes</div>
            </div>
        </div>
    </div>
</div>

<!-- Filtros EXPANDIDOS -->
<div class="filters-container">
    <form method="GET" id="formFiltros">
        <input type="hidden" name="view" value="<?= $view ?>">
        
        <?php 
        // Verificar quais filtros estão ativos
        $filtros_ativos = [];
        
        if (!empty($nucleo_selecionado)) {
            $nucleo_nome = '';
            foreach ($nucleos as $n) {
                if ($n['id'] == $nucleo_selecionado) {
                    $nucleo_nome = $n['nome'];
                    break;
                }
            }
            $filtros_ativos[] = "Núcleo: <span class='filter-tag'>🏢 {$nucleo_nome}</span>";
        }
        
        if (count($usuarios_selecionados) > 1) {
            $filtros_ativos[] = "Usuários: <span class='filter-tag'>👥 " . count($usuarios_selecionados) . " selecionados</span>";
        } elseif (!in_array($usuario_logado['usuario_id'], $usuarios_selecionados) && count($usuarios_selecionados) === 1) {
            // Buscar nome do usuário
            foreach ($usuarios as $u) {
                if ($u['id'] == $usuarios_selecionados[0]) {
                    $filtros_ativos[] = "Usuário: <span class='filter-tag'>👤 {$u['nome']}</span>";
                    break;
                }
            }
        }
        
        if (!empty($filtro_tipo)) {
            $tipo_nome = match($filtro_tipo) {
                'evento' => '🎯 Eventos',
                'prazo' => '⏰ Prazos',
                'tarefa' => '✓ Tarefas',
                'audiencia' => '📅 Audiências',
                default => $filtro_tipo
            };
            $filtros_ativos[] = "Tipo: <span class='filter-tag'>{$tipo_nome}</span>";
        }
        
        if (!empty($filtro_status)) {
            $status_nome = match($filtro_status) {
                'pendente' => '⏳ Pendente',
                'em_andamento' => '▶️ Em Andamento',
                'concluida,concluido,realizada' => '✅ Concluída',
                'agendada,agendado' => '📅 Agendada',
                'cancelada,cancelado' => '❌ Cancelada',
                'revisao_aceita' => '✅ Revisão Aceita',
                'revisao_recusada' => '❌ Revisão Recusada',
                'aguardando_revisao' => '🔍 Aguardando Revisão',
                'em_revisao' => '📝 Em Revisão',
                'protocolada,protocolado' => '📨 Protocolada',
                default => ucfirst(str_replace('_', ' ', $filtro_status))
            };
            $filtros_ativos[] = "Status: <span class='filter-tag'>{$status_nome}</span>";
        }
        
        if (!empty($filtro_vencimento)) {
            $venc_nome = match($filtro_vencimento) {
                'atrasados' => '🔴 Atrasados',
                'vence_hoje' => '📍 Vence Hoje',
                'proximos_3_dias' => '⚠️ Próximos 3 dias',
                'proximos_7_dias' => '📆 Próximos 7 dias',
                default => $filtro_vencimento
            };
            $filtros_ativos[] = "Vencimento: <span class='filter-tag'>{$venc_nome}</span>";
        }
        
        if (!empty($filtro_busca)) {
            $filtros_ativos[] = "Busca: <span class='filter-tag'>🔍 \"" . htmlspecialchars($filtro_busca) . "\"</span>";
        }
        
        if (!empty($filtros_ativos)): 
        ?>
        <div class="filter-info">
            <i class="fas fa-filter"></i>
            <strong>Filtros ativos:</strong>
            <?= implode(' • ', $filtros_ativos) ?>
            
            <a href="?view=<?= $view ?>" 
               style="margin-left: auto; color: #dc3545; text-decoration: none; font-weight: 600; display: inline-flex; align-items: center; gap: 5px;">
                <i class="fas fa-times-circle"></i> Limpar todos
            </a>
        </div>
        <?php endif; ?>
        
        <div class="filters-grid">
            <div class="filter-group">
                <label>📌 Tipo</label>
                <select name="tipo">
                    <option value="">Todos os Tipos</option>
                    <option value="evento" <?= $filtro_tipo === 'evento' ? 'selected' : '' ?>>🎯 Eventos</option>
                    <option value="prazo" <?= $filtro_tipo === 'prazo' ? 'selected' : '' ?>>⏰ Prazos</option>
                    <option value="tarefa" <?= $filtro_tipo === 'tarefa' ? 'selected' : '' ?>>✓ Tarefas</option>
                    <option value="audiencia" <?= $filtro_tipo === 'audiencia' ? 'selected' : '' ?>>📅 Audiências</option>
                </select>
            </div>

            <!-- 
                SUBSTITUIR A SEÇÃO DE FILTROS DE PERÍODO
                Localizar o select de Período e os campos de data
            -->
            
            <div class="filter-group">
                <label>📅 Período</label>
                <select name="periodo" id="selectPeriodo">
                    <option value="proximos_30" <?= $filtro_periodo === 'proximos_30' ? 'selected' : '' ?>>Próximos 30 dias</option>
                    <option value="hoje" <?= $filtro_periodo === 'hoje' ? 'selected' : '' ?>>Hoje</option>
                    <option value="amanha" <?= $filtro_periodo === 'amanha' ? 'selected' : '' ?>>Amanhã</option>
                    <option value="esta_semana" <?= $filtro_periodo === 'esta_semana' ? 'selected' : '' ?>>Esta Semana</option>
                    <option value="proxima_semana" <?= $filtro_periodo === 'proxima_semana' ? 'selected' : '' ?>>Próxima Semana</option>
                    <option value="este_mes" <?= $filtro_periodo === 'este_mes' ? 'selected' : '' ?>>Este Mês</option>
                    <option value="proximo_mes" <?= $filtro_periodo === 'proximo_mes' ? 'selected' : '' ?>>Próximo Mês</option>
                    <option value="personalizado" <?= $filtro_periodo === 'personalizado' ? 'selected' : '' ?>>📆 Período Personalizado</option>
                </select>
            </div>
            
            <!-- CAMPOS DE DATA PERSONALIZADA (aparecem quando "personalizado" está selecionado) -->
            <div id="camposDataPersonalizada" style="display: <?= $filtro_periodo === 'personalizado' ? 'contents' : 'none' ?>;">
                <div class="filter-group">
                    <label>📅 Data Início</label>
                    <input type="date" 
                           name="data_inicio" 
                           id="dataInicio"
                           value="<?= isset($_GET['data_inicio']) ? htmlspecialchars($_GET['data_inicio']) : date('Y-m-d') ?>"
                           style="padding: 10px 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px;">
                </div>
            
                <div class="filter-group">
                    <label>📅 Data Fim</label>
                    <input type="date" 
                           name="data_fim" 
                           id="dataFim"
                           value="<?= isset($_GET['data_fim']) ? htmlspecialchars($_GET['data_fim']) : date('Y-m-d', strtotime('+30 days')) ?>"
                           style="padding: 10px 12px; border: 1px solid #ddd; border-radius: 8px; font-size: 14px;">
                </div>
            </div>
            
            <style>
            /* Garantir que os campos de data apareçam no grid corretamente */
            #camposDataPersonalizada {
                display: contents; /* Faz os filhos participarem do grid pai */
            }
            
            #camposDataPersonalizada.hidden {
                display: none;
            }
            
            /* Estilo adicional para os campos de data */
            input[type="date"] {
                cursor: pointer;
                transition: all 0.3s;
            }
            
            input[type="date"]:focus {
                outline: none;
                border-color: #667eea !important;
                box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1) !important;
            }
            
            input[type="date"]:hover {
                border-color: #667eea;
            }
            </style>
            
            <script>
            // ===== CONTROLE DO PERÍODO PERSONALIZADO =====
            
            function toggleCamposDataPersonalizada() {
                const selectPeriodo = document.getElementById('selectPeriodo');
                const camposPersonalizados = document.getElementById('camposDataPersonalizada');
                
                if (!selectPeriodo || !camposPersonalizados) {
                    console.error('Elementos não encontrados');
                    return;
                }
                
                console.log('Período selecionado:', selectPeriodo.value);
                
                if (selectPeriodo.value === 'personalizado') {
                    camposPersonalizados.style.display = 'contents';
                    console.log('✅ Campos de data personalizados MOSTRADOS');
                } else {
                    camposPersonalizados.style.display = 'none';
                    console.log('❌ Campos de data personalizados OCULTOS');
                }
            }
            
            // Executar ao carregar
            document.addEventListener('DOMContentLoaded', function() {
                console.log('🎯 Iniciando controle de período personalizado...');
                
                const selectPeriodo = document.getElementById('selectPeriodo');
                if (selectPeriodo) {
                    console.log('✅ Select de período encontrado');
                    
                    // Verificar estado inicial
                    toggleCamposDataPersonalizada();
                    
                    // Adicionar listener
                    selectPeriodo.addEventListener('change', function() {
                        console.log('📅 Período alterado para:', this.value);
                        toggleCamposDataPersonalizada();
                    });
                } else {
                    console.error('❌ Select de período NÃO encontrado!');
                }
            });
            </script>

            <div class="filter-group">
                <label>🔍 Buscar</label>
                <input type="text" name="busca" value="<?= htmlspecialchars($filtro_busca) ?>" 
                       placeholder="Título, descrição...">
            </div>
            
            <!-- 
                FILTROS MULTI-SELECT - SUBSTITUIR OS SELECTS ATUAIS
                Localizar os campos de Status e Vencimento e substituir por este código
            -->
            
            <style>
            /* Estilos para filtros multi-select */
            .filter-multi-select {
                position: relative;
            }
            
            .filter-multi-toggle {
                padding: 10px 12px;
                border: 1px solid #ddd;
                border-radius: 8px;
                background: white;
                cursor: pointer;
                display: flex;
                justify-content: space-between;
                align-items: center;
                transition: all 0.3s;
                min-height: 42px;
            }
            
            .filter-multi-toggle:hover {
                border-color: #667eea;
                box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            }
            
            .filter-multi-toggle.active {
                border-color: #667eea;
                box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            }
            
            .filter-multi-label {
                flex: 1;
                font-size: 14px;
                color: #666;
            }
            
            .filter-multi-count {
                background: #667eea;
                color: white;
                padding: 2px 8px;
                border-radius: 10px;
                font-size: 11px;
                font-weight: 700;
                margin-left: 8px;
            }
            
            .filter-multi-arrow {
                margin-left: 8px;
                transition: transform 0.3s;
                color: #999;
            }
            
            .filter-multi-toggle.active .filter-multi-arrow {
                transform: rotate(180deg);
            }
            
            .filter-multi-dropdown {
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                background: white;
                border: 1px solid #ddd;
                border-radius: 8px;
                margin-top: 4px;
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                z-index: 1000;
                display: none;
                max-height: 300px;
                overflow-y: auto;
            }
            
            .filter-multi-dropdown.show {
                display: block;
            }
            
            .filter-multi-option {
                padding: 10px 15px;
                cursor: pointer;
                transition: all 0.2s;
                display: flex;
                align-items: center;
                gap: 10px;
                border-bottom: 1px solid rgba(0,0,0,0.05);
            }
            
            .filter-multi-option:last-child {
                border-bottom: none;
            }
            
            .filter-multi-option:hover {
                background: rgba(102, 126, 234, 0.1);
            }
            
            .filter-multi-option input[type="checkbox"] {
                width: 18px;
                height: 18px;
                cursor: pointer;
                accent-color: #667eea;
            }
            
            .filter-multi-option label {
                flex: 1;
                cursor: pointer;
                font-size: 14px;
                margin: 0;
            }
            
            .filter-multi-actions {
                padding: 10px 15px;
                border-top: 1px solid rgba(0,0,0,0.1);
                display: flex;
                gap: 8px;
                background: #f8f9fa;
                border-radius: 0 0 8px 8px;
            }
            
            .filter-multi-btn {
                padding: 6px 12px;
                border: none;
                border-radius: 6px;
                font-size: 12px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.2s;
            }
            
            .filter-multi-btn-primary {
                background: #667eea;
                color: white;
            }
            
            .filter-multi-btn-primary:hover {
                background: #5568d3;
            }
            
            .filter-multi-btn-secondary {
                background: #e9ecef;
                color: #495057;
            }
            
            .filter-multi-btn-secondary:hover {
                background: #dee2e6;
            }
            
            /* Scrollbar customizada */
            .filter-multi-dropdown::-webkit-scrollbar {
                width: 8px;
            }
            
            .filter-multi-dropdown::-webkit-scrollbar-track {
                background: #f1f1f1;
                border-radius: 0 8px 8px 0;
            }
            
            .filter-multi-dropdown::-webkit-scrollbar-thumb {
                background: #667eea;
                border-radius: 4px;
            }
            
            .filter-multi-dropdown::-webkit-scrollbar-thumb:hover {
                background: #5568d3;
            }
            </style>
            
            <!-- FILTRO DE STATUS MULTI-SELECT -->
            <div class="filter-group">
                <label>🔵 Status</label>
                <div class="filter-multi-select">
                    <div class="filter-multi-toggle" onclick="toggleFilterDropdown('status-dropdown', this)">
                        <span class="filter-multi-label" id="status-label">Selecionar status...</span>
                        <span class="filter-multi-count" id="status-count" style="display: none;">0</span>
                        <i class="fas fa-chevron-down filter-multi-arrow"></i>
                    </div>
                    
                    <div class="filter-multi-dropdown" id="status-dropdown">
                        <div class="filter-multi-option">
                            <input type="checkbox" name="status[]" value="pendente" id="status_pendente" 
                                   onchange="updateFilterLabel('status')">
                            <label for="status_pendente">⏳ Pendente</label>
                        </div>
                        
                        <div class="filter-multi-option">
                            <input type="checkbox" name="status[]" value="em_andamento" id="status_em_andamento" 
                                   onchange="updateFilterLabel('status')">
                            <label for="status_em_andamento">▶️ Em Andamento</label>
                        </div>
                        
                        <div class="filter-multi-option">
                            <input type="checkbox" name="status[]" value="concluida" id="status_concluida" 
                                   onchange="updateFilterLabel('status')">
                            <label for="status_concluida">✅ Concluída</label>
                        </div>
                        
                        <div class="filter-multi-option">
                            <input type="checkbox" name="status[]" value="agendada" id="status_agendada" 
                                   onchange="updateFilterLabel('status')">
                            <label for="status_agendada">📅 Agendada</label>
                        </div>
                        
                        <div class="filter-multi-option">
                            <input type="checkbox" name="status[]" value="cancelada" id="status_cancelada" 
                                   onchange="updateFilterLabel('status')">
                            <label for="status_cancelada">❌ Cancelada</label>
                        </div>
                        
                        <div class="filter-multi-option">
                            <input type="checkbox" name="status[]" value="aguardando_revisao" id="status_aguardando_revisao" 
                                   onchange="updateFilterLabel('status')">
                            <label for="status_aguardando_revisao">🔍 Aguardando Revisão</label>
                        </div>
                        
                        <div class="filter-multi-option">
                            <input type="checkbox" name="status[]" value="em_revisao" id="status_em_revisao" 
                                   onchange="updateFilterLabel('status')">
                            <label for="status_em_revisao">📝 Em Revisão</label>
                        </div>
                        
                        <div class="filter-multi-option">
                            <input type="checkbox" name="status[]" value="revisao_aceita" id="status_revisao_aceita" 
                                   onchange="updateFilterLabel('status')">
                            <label for="status_revisao_aceita">✅ Revisão Aceita</label>
                        </div>
                        
                        <div class="filter-multi-option">
                            <input type="checkbox" name="status[]" value="revisao_recusada" id="status_revisao_recusada" 
                                   onchange="updateFilterLabel('status')">
                            <label for="status_revisao_recusada">❌ Revisão Recusada</label>
                        </div>
                        
                        <div class="filter-multi-option">
                            <input type="checkbox" name="status[]" value="protocolada" id="status_protocolada" 
                                   onchange="updateFilterLabel('status')">
                            <label for="status_protocolada">📨 Protocolada</label>
                        </div>
                        
                        <div class="filter-multi-actions">
                            <button type="button" class="filter-multi-btn filter-multi-btn-secondary" 
                                    onclick="clearFilter('status')">
                                Limpar
                            </button>
                            <button type="button" class="filter-multi-btn filter-multi-btn-primary" 
                                    onclick="closeFilterDropdown('status-dropdown')">
                                OK
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- FILTRO DE VENCIMENTO MULTI-SELECT -->
            <div class="filter-group">
                <label>⏰ Vencimento</label>
                <div class="filter-multi-select">
                    <div class="filter-multi-toggle" onclick="toggleFilterDropdown('vencimento-dropdown', this)">
                        <span class="filter-multi-label" id="vencimento-label">Selecionar vencimento...</span>
                        <span class="filter-multi-count" id="vencimento-count" style="display: none;">0</span>
                        <i class="fas fa-chevron-down filter-multi-arrow"></i>
                    </div>
                    
                    <div class="filter-multi-dropdown" id="vencimento-dropdown">
                        <div class="filter-multi-option">
                            <input type="checkbox" name="vencimento[]" value="atrasados" id="venc_atrasados" 
                                   onchange="updateFilterLabel('vencimento')">
                            <label for="venc_atrasados">🔴 Atrasados</label>
                        </div>
                        
                        <div class="filter-multi-option">
                            <input type="checkbox" name="vencimento[]" value="vence_hoje" id="venc_hoje" 
                                   onchange="updateFilterLabel('vencimento')">
                            <label for="venc_hoje">📍 Vence Hoje</label>
                        </div>
                        
                        <div class="filter-multi-option">
                            <input type="checkbox" name="vencimento[]" value="proximos_3_dias" id="venc_3dias" 
                                   onchange="updateFilterLabel('vencimento')">
                            <label for="venc_3dias">⚠️ Próximos 3 dias</label>
                        </div>
                        
                        <div class="filter-multi-option">
                            <input type="checkbox" name="vencimento[]" value="proximos_7_dias" id="venc_7dias" 
                                   onchange="updateFilterLabel('vencimento')">
                            <label for="venc_7dias">📆 Próximos 7 dias</label>
                        </div>
                        
                        <div class="filter-multi-actions">
                            <button type="button" class="filter-multi-btn filter-multi-btn-secondary" 
                                    onclick="clearFilter('vencimento')">
                                Limpar
                            </button>
                            <button type="button" class="filter-multi-btn filter-multi-btn-primary" 
                                    onclick="closeFilterDropdown('vencimento-dropdown')">
                                OK
                            </button>
                        </div>
                    </div>
                </div>
            </div>
            
            <script>
            // Fechar dropdown ao clicar fora
            document.addEventListener('click', function(event) {
                if (!event.target.closest('.filter-multi-select')) {
                    document.querySelectorAll('.filter-multi-dropdown').forEach(dropdown => {
                        dropdown.classList.remove('show');
                        dropdown.previousElementSibling.classList.remove('active');
                    });
                }
            });
            
            // Toggle dropdown
            function toggleFilterDropdown(dropdownId, toggle) {
                event.stopPropagation();
                
                const dropdown = document.getElementById(dropdownId);
                const isOpen = dropdown.classList.contains('show');
                
                // Fechar todos os outros dropdowns
                document.querySelectorAll('.filter-multi-dropdown').forEach(d => {
                    d.classList.remove('show');
                    d.previousElementSibling.classList.remove('active');
                });
                
                // Toggle atual
                if (!isOpen) {
                    dropdown.classList.add('show');
                    toggle.classList.add('active');
                }
            }
            
            // Fechar dropdown
            function closeFilterDropdown(dropdownId) {
                const dropdown = document.getElementById(dropdownId);
                dropdown.classList.remove('show');
                dropdown.previousElementSibling.classList.remove('active');
            }
            
            // Atualizar label do filtro
            function updateFilterLabel(filterType) {
                const checkboxes = document.querySelectorAll(`input[name="${filterType}[]"]:checked`);
                const label = document.getElementById(`${filterType}-label`);
                const count = document.getElementById(`${filterType}-count`);
                
                if (checkboxes.length === 0) {
                    label.textContent = `Selecionar ${filterType}...`;
                    count.style.display = 'none';
                } else if (checkboxes.length === 1) {
                    label.textContent = checkboxes[0].nextElementSibling.textContent;
                    count.style.display = 'none';
                } else {
                    label.textContent = `${checkboxes.length} selecionado${checkboxes.length > 1 ? 's' : ''}`;
                    count.textContent = checkboxes.length;
                    count.style.display = 'inline-block';
                }
            }
            
            // Limpar filtro
            function clearFilter(filterType) {
                document.querySelectorAll(`input[name="${filterType}[]"]`).forEach(checkbox => {
                    checkbox.checked = false;
                });
                updateFilterLabel(filterType);
            }
            
            // Inicializar ao carregar a página
            document.addEventListener('DOMContentLoaded', function() {
                // Marcar checkboxes baseado nos valores da URL
                const urlParams = new URLSearchParams(window.location.search);
                
                // Status
                const statusParam = urlParams.get('status');
                if (statusParam) {
                    const statusValues = statusParam.split(',');
                    statusValues.forEach(value => {
                        const checkbox = document.getElementById(`status_${value}`);
                        if (checkbox) checkbox.checked = true;
                    });
                    updateFilterLabel('status');
                }
                
                // Vencimento
                const vencParam = urlParams.get('vencimento');
                if (vencParam) {
                    const vencValues = vencParam.split(',');
                    vencValues.forEach(value => {
                        const checkbox = document.getElementById(`venc_${value.replace('proximos_', '').replace('_dias', 'dias').replace('vence_hoje', 'hoje')}`);
                        if (checkbox) checkbox.checked = true;
                    });
                    updateFilterLabel('vencimento');
                }
            });
            
            // Antes de submeter o formulário, converter checkboxes em string separada por vírgula
            document.getElementById('formFiltros').addEventListener('submit', function(e) {
                // Status
                const statusChecked = Array.from(document.querySelectorAll('input[name="status[]"]:checked'))
                    .map(cb => cb.value);
                
                if (statusChecked.length > 0) {
                    // Remover todos os checkboxes individuais
                    document.querySelectorAll('input[name="status[]"]').forEach(cb => cb.disabled = true);
                    
                    // Criar um input hidden com todos os valores
                    const statusInput = document.createElement('input');
                    statusInput.type = 'hidden';
                    statusInput.name = 'status';
                    statusInput.value = statusChecked.join(',');
                    this.appendChild(statusInput);
                }
                
                // Vencimento
                const vencChecked = Array.from(document.querySelectorAll('input[name="vencimento[]"]:checked'))
                    .map(cb => cb.value);
                
                if (vencChecked.length > 0) {
                    // Remover todos os checkboxes individuais
                    document.querySelectorAll('input[name="vencimento[]"]').forEach(cb => cb.disabled = true);
                    
                    // Criar um input hidden com todos os valores
                    const vencInput = document.createElement('input');
                    vencInput.type = 'hidden';
                    vencInput.name = 'vencimento';
                    vencInput.value = vencChecked.join(',');
                    this.appendChild(vencInput);
                }
            });
            </script>

            <?php if (!empty($nucleos)): ?>
            <div class="filter-group">
                <label>🏢 Núcleo</label>
                <select name="nucleo" id="selectNucleo">
                    <option value="">Selecione um núcleo</option>
                    <?php foreach ($nucleos as $nucleo): ?>
                    <option value="<?= $nucleo['id'] ?>" <?= $nucleo_selecionado == $nucleo['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($nucleo['nome']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="filter-group">
                <label>👥 Usuários</label>
                <div class="filter-multi-select">
                    <div class="filter-multi-toggle" onclick="toggleFilterDropdown('usuarios-dropdown', this)">
                        <span class="filter-multi-label" id="usuarios-label">Selecionar usuários...</span>
                        <span class="filter-multi-count" id="usuarios-count" style="display: none;">0</span>
                        <i class="fas fa-chevron-down filter-multi-arrow"></i>
                    </div>
                    
                    <div class="filter-multi-dropdown" id="usuarios-dropdown">
                        <?php foreach ($usuarios as $usuario): ?>
                        <div class="filter-multi-option">
                            <input type="checkbox" 
                                   name="usuarios_select[]" 
                                   value="<?= $usuario['id'] ?>" 
                                   id="usuario_<?= $usuario['id'] ?>" 
                                   <?= in_array($usuario['id'], $usuarios_selecionados) ? 'checked' : '' ?>
                                   onchange="updateUsuariosLabel()">
                            <label for="usuario_<?= $usuario['id'] ?>">
                                <?php if ($usuario['id'] == $usuario_logado['usuario_id']): ?>
                                    <i class="fas fa-user-circle"></i>
                                <?php else: ?>
                                    <i class="fas fa-user"></i>
                                <?php endif; ?>
                                <?= htmlspecialchars($usuario['nome']) ?>
                                <?= $usuario['id'] == $usuario_logado['usuario_id'] ? ' (Você)' : '' ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                        
                        <div class="filter-multi-actions">
                            <button type="button" class="filter-multi-btn filter-multi-btn-secondary" 
                                    onclick="clearUsuariosFilter()">
                                Limpar
                            </button>
                            <button type="button" class="filter-multi-btn filter-multi-btn-primary" 
                                    onclick="closeFilterDropdown('usuarios-dropdown')">
                                OK
                            </button>
                        </div>
                    </div>
                </div>
                <input type="hidden" name="usuarios" id="usuariosHidden" value="<?= implode(',', $usuarios_selecionados) ?>">
            </div>
        </div>

        <div class="filter-actions">
            <button type="submit" class="btn btn-primary">🔍 Filtrar</button>
            <a href="?" class="btn btn-secondary">🔄 Limpar</a>
            <button type="button" onclick="limparFiltrosSalvos()" class="btn btn-secondary" style="background: #6c757d;" title="Limpar filtros salvos">
                <i class="fas fa-eraser"></i> Limpar Salvos
            </button>
        </div>
    </form>
</div>

<script>
// ============================================================================
// SISTEMA DE SALVAMENTO DE FILTROS NO localStorage - VERSÃO CORRIGIDA
// ============================================================================

// ===== CONSTANTE GLOBAL =====
const STORAGE_KEY = 'agenda_filtros_salvos';
// ✅ Limpar filtros antigos automaticamente (migração v2.0)
(function() {
    const versao = localStorage.getItem('agenda_filtros_versao');
    if (versao !== '2.0') {
        localStorage.removeItem(STORAGE_KEY);
        localStorage.setItem('agenda_filtros_versao', '2.0');
        console.log('🔄 Filtros antigos limpos automaticamente (v2.0)');
    }
})();


// ===== FUNÇÃO GLOBAL: SALVAR FILTROS (DEFINIDA PRIMEIRO!) =====
// ===== FUNÇÃO GLOBAL: SALVAR FILTROS (CORRIGIDA!) =====
window.salvarFiltros = function() {
    const form = document.getElementById('formFiltros');
    if (!form) {
        console.warn('⚠️ Formulário não encontrado para salvar');
        return;
    }
    
    console.log('💾 Salvando filtros...');
    
    // Buscar valores REAIS dos checkboxes de status
    const statusCheckboxes = form.querySelectorAll('input[name="status[]"]:checked');
    const statusValues = Array.from(statusCheckboxes).map(cb => cb.value);
    console.log('📋 Status selecionados:', statusValues);
    
    // Buscar valores REAIS dos checkboxes de vencimento
    const vencimentoCheckboxes = form.querySelectorAll('input[name="vencimento[]"]:checked');
    const vencimentoValues = Array.from(vencimentoCheckboxes).map(cb => cb.value);
    console.log('📅 Vencimento selecionados:', vencimentoValues);
    
    const filtros = {
        tipo: form.querySelector('[name="tipo"]')?.value || '',
        periodo: form.querySelector('[name="periodo"]')?.value || '',
        status: statusValues.join(','),  // ✅ CORRIGIDO - agora pega valores reais
        vencimento: vencimentoValues.join(','),  // ✅ CORRIGIDO - agora pega valores reais
        data_inicio: form.querySelector('[name="data_inicio"]')?.value || '',
        data_fim: form.querySelector('[name="data_fim"]')?.value || '',
        busca: form.querySelector('[name="busca"]')?.value || '',
        nucleo: form.querySelector('[name="nucleo"]')?.value || '',
        usuarios: form.querySelector('[name="usuarios"]')?.value || '',
        view: form.querySelector('[name="view"]')?.value || 'list',
        timestamp: new Date().toISOString()
    };
    
    console.log('📦 Filtros para salvar:', filtros);
    
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(filtros));
        console.log('✅ Filtros salvos com sucesso!');
        
        // Verificar se realmente salvou
        const verificacao = localStorage.getItem(STORAGE_KEY);
        console.log('🔍 Verificação - Filtros no localStorage:', JSON.parse(verificacao));
    } catch (e) {
        console.error('❌ Erro ao salvar filtros:', e);
    }
};

// ===== FUNÇÃO GLOBAL: LIMPAR FILTROS =====
window.limparFiltrosSalvos = function() {
    try {
        localStorage.removeItem(STORAGE_KEY);
        console.log('🗑️ Filtros salvos removidos');
        alert('✅ Filtros salvos foram limpos com sucesso!');
    } catch (e) {
        console.error('❌ Erro ao limpar filtros:', e);
    }
};

// ===== IIFE: CARREGAR FILTROS E CONFIGURAR EVENTOS =====
(function() {
    'use strict';
    
    console.log('💾 Iniciando sistema de salvamento de filtros...');
    
    // ===== CARREGAR FILTROS =====
    function carregarFiltros() {
        console.log('🔄 Iniciando carregamento de filtros...');
        
        const urlParams = new URLSearchParams(window.location.search);
        const temParametros = urlParams.toString().length > 0;
        
        // ✅ NOVA LÓGICA: Só não aplica se a URL já tem os parâmetros corretos
        if (temParametros) {
            console.log('⏭️ URL tem parâmetros, não carrega filtros salvos:', urlParams.toString());
            return;
        }
        
        try {
            const filtrosSalvos = localStorage.getItem(STORAGE_KEY);
            if (!filtrosSalvos) {
                console.log('ℹ️ Nenhum filtro salvo encontrado');
                return;
            }
            
            const filtros = JSON.parse(filtrosSalvos);
            console.log('📂 Filtros carregados do localStorage:', filtros);
            
            const form = document.getElementById('formFiltros');
            if (!form) return;
            
            if (filtros.tipo) {
                const campo = form.querySelector('[name="tipo"]');
                if (campo) campo.value = filtros.tipo;
            }
            
            if (filtros.periodo) {
                const campo = form.querySelector('[name="periodo"]');
                if (campo) {
                    campo.value = filtros.periodo;
                    campo.dispatchEvent(new Event('change'));
                }
            }
            
            if (filtros.data_inicio) {
                const campo = form.querySelector('[name="data_inicio"]');
                if (campo) campo.value = filtros.data_inicio;
            }
            
            if (filtros.data_fim) {
                const campo = form.querySelector('[name="data_fim"]');
                if (campo) campo.value = filtros.data_fim;
            }
            
            if (filtros.busca) {
                const campo = form.querySelector('[name="busca"]');
                if (campo) campo.value = filtros.busca;
            }
            
            if (filtros.nucleo) {
                const campo = form.querySelector('[name="nucleo"]');
                if (campo) campo.value = filtros.nucleo;
            }
            
            if (filtros.usuarios) {
                const campo = form.querySelector('[name="usuarios"]');
                if (campo) campo.value = filtros.usuarios;
            }
            
            if (filtros.status) {
                const statusValues = filtros.status.split(',').filter(v => v);
                statusValues.forEach(value => {
                    const checkbox = document.querySelector(`input[name="status[]"][value="${value}"]`);
                    if (checkbox) checkbox.checked = true;
                });
                if (typeof updateFilterLabel === 'function') {
                    updateFilterLabel('status');
                }
            }
            
            if (filtros.vencimento) {
                const vencValues = filtros.vencimento.split(',').filter(v => v);
                vencValues.forEach(value => {
                    const checkbox = document.querySelector(`input[name="vencimento[]"][value="${value}"]`);
                    if (checkbox) checkbox.checked = true;
                });
                if (typeof updateFilterLabel === 'function') {
                    updateFilterLabel('vencimento');
                }
            }

            mostrarMensagemFiltrosCarregados();
            
            // ✅ NOVO: Aplicar filtros automaticamente
            console.log('🔄 Aplicando filtros automaticamente...');
            console.log('📋 Filtros que serão aplicados:', filtros);
            
            setTimeout(() => {
                const form = document.getElementById('formFiltros');
                if (form) {
                    console.log('📤 Submetendo formulário com filtros salvos...');
                    // ✅ IMPORTANTE: Usar requestSubmit() ao invés de submit()
                    // para disparar o evento submit e salvar os filtros
                    if (form.requestSubmit) {
                        form.requestSubmit();
                    } else {
                        // Fallback para navegadores antigos
                        form.submit();
                    }
                }
            }, 300); // Pequeno delay para garantir que tudo foi carregado
            
        } catch (e) {
            console.error('❌ Erro ao carregar filtros:', e);
        }
    }
    
    // ===== MOSTRAR MENSAGEM =====
    function mostrarMensagemFiltrosCarregados() {
        const container = document.querySelector('.filters-container');
        if (!container) return;
        
        const mensagem = document.createElement('div');
        mensagem.style.cssText = `
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 8px 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: slideDown 0.3s ease;
        `;
        mensagem.innerHTML = `
            <i class="fas fa-info-circle"></i>
            <span>Filtros salvos aplicados automaticamente</span>
            <button onclick="this.parentElement.remove()" style="margin-left: auto; background: rgba(255,255,255,0.2); border: none; color: white; padding: 4px 8px; border-radius: 4px; cursor: pointer;">
                <i class="fas fa-times"></i>
            </button>
        `;
        
        container.insertBefore(mensagem, container.firstChild);
        
        setTimeout(() => {
            if (mensagem.parentElement) {
                mensagem.style.animation = 'slideUp 0.3s ease';
                setTimeout(() => mensagem.remove(), 300);
            }
        }, 5000);
    }
    
    // ===== EVENTOS =====
    const form = document.getElementById('formFiltros');
    if (form) {
        form.addEventListener('submit', function() {
            window.salvarFiltros();
        });
    }
    
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', carregarFiltros);
    } else {
        carregarFiltros();
    }
    
    console.log('✅ Sistema de salvamento de filtros inicializado');
    
})();

// ========================================
// DROPDOWN DE USUÁRIOS
// ========================================

function updateUsuariosLabel() {
    const checkboxes = document.querySelectorAll('input[name="usuarios_select[]"]:checked');
    const label = document.getElementById('usuarios-label');
    const count = document.getElementById('usuarios-count');
    const hidden = document.getElementById('usuariosHidden');
    
    const ids = Array.from(checkboxes).map(cb => cb.value);
    if (hidden) {
        hidden.value = ids.join(',');
    }
    
    if (checkboxes.length === 0) {
        label.textContent = 'Selecionar usuários...';
        count.style.display = 'none';
    } else if (checkboxes.length === 1) {
        label.textContent = checkboxes[0].nextElementSibling.textContent.trim();
        count.style.display = 'none';
    } else {
        label.textContent = `${checkboxes.length} selecionado${checkboxes.length > 1 ? 's' : ''}`;
        count.textContent = checkboxes.length;
        count.style.display = 'inline-block';
    }
    
    console.log('👥 Usuários atualizados:', ids);
}

function clearUsuariosFilter() {
    document.querySelectorAll('input[name="usuarios_select[]"]').forEach(checkbox => {
        checkbox.checked = false;
    });
    updateUsuariosLabel();
}

// Inicializar labels ao carregar
document.addEventListener('DOMContentLoaded', function() {
    console.log('🎯 Inicializando labels dos filtros...');
    
    if (typeof updateFilterLabel === 'function') {
        updateFilterLabel('status');
        updateFilterLabel('vencimento');
    }
    
    if (typeof updateUsuariosLabel === 'function') {
        updateUsuariosLabel();
        console.log('✅ Label de usuários inicializado');
    }
    
    const checkboxes = document.querySelectorAll('input[name="usuarios_select[]"]:checked');
    console.log('👥 Usuários pré-selecionados:', checkboxes.length);
    checkboxes.forEach(cb => {
        console.log('  -', cb.nextElementSibling.textContent.trim());
    });
});
</script>

<style>
@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes slideUp {
    from {
        opacity: 1;
        transform: translateY(0);
    }
    to {
        opacity: 0;
        transform: translateY(-20px);
    }
}
</style>

<!-- Container para Formulário Inline -->
<div id="formularioContainer" style="display:none; margin-bottom: 20px;">
    <!-- Conteúdo carregado via AJAX -->
</div>

<?php if ($view === 'calendar'): ?>
<!-- ============================================================================ -->
<!-- VISUALIZAÇÃO EM CALENDÁRIO                                                  -->
<!-- ============================================================================ -->
<div class="calendar-container" style="background: white; border-radius: 15px; padding: 25px; box-shadow: 0 8px 32px rgba(0,0,0,0.15);">
    <div class="calendar-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px;">
        <button onclick="mudarMes(-1)" class="btn btn-secondary" style="padding: 10px 15px;">
            <i class="fas fa-chevron-left"></i> Anterior
        </button>
        <h3 id="mesAnoAtual" style="font-size: 24px; font-weight: 700; color: #1a1a1a; margin: 0;">
            <!-- Será preenchido via JavaScript -->
        </h3>
        <button onclick="mudarMes(1)" class="btn btn-secondary" style="padding: 10px 15px;">
            Próximo <i class="fas fa-chevron-right"></i>
        </button>
    </div>
    
    <div class="calendar-grid" id="calendarGrid" style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 0; background: white; border: 1px solid #ddd; border-radius: 8px; overflow: hidden;">
        <!-- Cabeçalhos dos dias da semana -->
        <div class="calendar-day-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px; text-align: center; font-weight: 700; font-size: 14px;">DOM</div>
        <div class="calendar-day-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px; text-align: center; font-weight: 700; font-size: 14px;">SEG</div>
        <div class="calendar-day-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px; text-align: center; font-weight: 700; font-size: 14px;">TER</div>
        <div class="calendar-day-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px; text-align: center; font-weight: 700; font-size: 14px;">QUA</div>
        <div class="calendar-day-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px; text-align: center; font-weight: 700; font-size: 14px;">QUI</div>
        <div class="calendar-day-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px; text-align: center; font-weight: 700; font-size: 14px;">SEX</div>
        <div class="calendar-day-header" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px; text-align: center; font-weight: 700; font-size: 14px;">SÁB</div>
        
        <!-- Os dias serão preenchidos via JavaScript -->
    </div>
    
    <!-- Modal para ver compromissos do dia -->
    <div id="modalDia" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 9999; padding: 20px; overflow-y: auto;">
        <div style="max-width: 800px; margin: 50px auto; background: white; border-radius: 15px; padding: 30px; box-shadow: 0 20px 60px rgba(0,0,0,0.3);">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h3 id="modalTitulo" style="margin: 0; font-size: 24px; font-weight: 700;"><!-- Será preenchido --></h3>
                <button onclick="fecharModal()" style="background: none; border: none; font-size: 24px; cursor: pointer; color: #999; padding: 5px 10px;">×</button>
            </div>
            <div id="modalConteudo">
                <!-- Será preenchido com os compromissos do dia -->
            </div>
        </div>
    </div>
</div>

<?php else: ?>
    <!-- ========================================== -->
    <!-- VISUALIZAÇÃO DE LISTA (PADRÃO) -->
    <!-- ========================================== -->
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
                <p style="color: #999; font-size: 14px;">Não há compromissos para o período e filtros selecionados</p>
            </div>
        <?php else: ?>
            <?php 
            $compromissos_agrupados = [];
            foreach ($compromissos as $comp) {
                $data_comp = date('Y-m-d', strtotime($comp['data_compromisso']));
                if (!isset($compromissos_agrupados[$data_comp])) {
                    $compromissos_agrupados[$data_comp] = [];
                }
                $compromissos_agrupados[$data_comp][] = $comp;
            }
            
            // Ordenar compromissos de cada dia: abertas primeiro, concluídas depois
            foreach ($compromissos_agrupados as $data => &$comps_array) {
                usort($comps_array, function($a, $b) {
                    $status_concluido_a = in_array(strtolower($a['status']), ['concluida', 'concluído', 'cumprido', 'realizado']);
                    $status_concluido_b = in_array(strtolower($b['status']), ['concluida', 'concluído', 'cumprido', 'realizado']);
                    
                    // Se um está concluído e outro não, o não concluído vem primeiro
                    if ($status_concluido_a != $status_concluido_b) {
                        return $status_concluido_a ? 1 : -1;
                    }
                    
                    // Se ambos têm o mesmo status, ordenar por hora
                    return strtotime($a['data_compromisso']) - strtotime($b['data_compromisso']);
                });
            }
            unset($comps_array);
            
            foreach ($compromissos_agrupados as $data => $comps_do_dia):
                $data_obj = new DateTime($data);
                $hoje_obj = new DateTime();
                
                if ($data_obj->format('Y-m-d') === $hoje_obj->format('Y-m-d')) {
                    $titulo_dia = '📍 Hoje - ' . $data_obj->format('d/m/Y');
                } elseif ($data_obj->format('Y-m-d') === $hoje_obj->modify('+1 day')->format('Y-m-d')) {
                    $titulo_dia = '⏭️ Amanhã - ' . $data_obj->format('d/m/Y');
                } else {
                    $dias_semana = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
                    $dia_semana = $dias_semana[(int)$data_obj->format('w')];
                    $titulo_dia = $dia_semana . ' - ' . $data_obj->format('d/m/Y');
                }
            ?>
            
            <!-- Separador de Data -->
            <div style="background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%); padding: 12px 25px; font-weight: 700; color: #667eea; border-left: 4px solid #667eea; font-size: 15px;">
                <?= $titulo_dia ?> (<?= count($comps_do_dia) ?> compromisso<?= count($comps_do_dia) > 1 ? 's' : '' ?>)
            </div>
            
            <?php foreach ($comps_do_dia as $comp): 
                $data = new DateTime($comp['data_compromisso']);
                $hoje_dt = new DateTime();
                $diff = $hoje_dt->diff($data);
                
                if ($data < $hoje_dt) {
                    $dias_texto = "Há " . $diff->days . " dia(s)";
                } elseif ($data->format('Y-m-d') === $hoje_dt->format('Y-m-d')) {
                    $dias_texto = "Hoje às " . $data->format('H:i');
                } else {
                    $dias_texto = "Em " . $diff->days . " dia(s) às " . $data->format('H:i');
                }
                
                // ===== INÍCIO - DETERMINAÇÃO DE LINK COM SUPORTE A PROSPECTOS =====
                $link = '#';
                
                if ($comp['tipo_compromisso'] === 'evento') {
                    // Verificar se é um atendimento de prospecto
                    if (!empty($comp['observacoes']) && preg_match('/Prospecto ID: (\d+)/', $comp['observacoes'], $matches)) {
                        $prospecto_id = $matches[1];
                        // Link para visualizar o prospecto
                        $link = '../prospeccao/visualizar_advocacia.php?id=' . $prospecto_id;
                    } else {
                        // Link normal para evento
                        $link = 'visualizar.php?id=' . $comp['id'] . '&tipo=evento';
                    }
                } elseif ($comp['tipo_compromisso'] === 'prazo') {
                    $link = 'visualizar.php?id=' . $comp['id'] . '&tipo=prazo';
                } elseif ($comp['tipo_compromisso'] === 'tarefa') {
                    $link = 'visualizar.php?id=' . $comp['id'] . '&tipo=tarefa';
                } elseif ($comp['tipo_compromisso'] === 'audiencia') {
                    $link = 'visualizar.php?id=' . $comp['id'] . '&tipo=audiencia';
                }
                // ===== FIM - DETERMINAÇÃO DE LINK =====
                
                $tipo_icon = match($comp['tipo_compromisso']) {
                    'evento' => '🎯',
                    'prazo' => '⏰',
                    'tarefa' => '✓',
                    'audiencia' => '📅',
                    default => '📌'
                };
                
                // ===== INÍCIO - BADGE DE PROSPECTO =====
                $badge_prospecto = '';
                if (!empty($comp['observacoes']) && strpos($comp['observacoes'], 'Prospecto ID:') !== false) {
                    $badge_prospecto = '<span style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 3px 8px; border-radius: 12px; font-size: 10px; font-weight: 700; margin-left: 5px; display: inline-block;">📋 PROSPECTO</span>';
                }
                // ===== FIM - BADGE DE PROSPECTO =====
                
                // Verificar se está concluída
                $status_concluido = in_array(strtolower($comp['status']), ['concluida', 'concluído', 'cumprido', 'realizado']);
                $estilo_concluido = $status_concluido ? 'text-decoration: line-through; opacity: 0.7;' : '';
                
                // Determinar tipo de tarefa/prazo (revisão, protocolo, correção)
                $tipo_item = $comp['tipo_tarefa'] ?? $comp['tipo_prazo'] ?? '';
                $badge_tipo = '';
                $classe_tipo = '';
                
                if (!empty($tipo_item)) {
                    switch($tipo_item) {
                        case 'revisao':
                            $badge_tipo = '<span class="badge-tipo-tarefa badge-revisao">📋 REVISÃO</span>';
                            $classe_tipo = 'tarefa-revisao';
                            break;
                        case 'protocolo':
                            $badge_tipo = '<span class="badge-tipo-tarefa badge-protocolo">✅ PROTOCOLO</span>';
                            $classe_tipo = 'tarefa-protocolo';
                            break;
                        case 'correcao':
                            $badge_tipo = '<span class="badge-tipo-tarefa badge-correcao">🔧 CORREÇÃO</span>';
                            $classe_tipo = 'tarefa-correcao';
                            break;
                    }
                }
            ?>
            
            <?php
            // Verificar se está atrasado
            $esta_atrasado = isItemAtrasado($comp);
            $classe_atrasado = $esta_atrasado ? 'item-atrasado' : '';
            ?>
            
            <div class="compromisso-card <?= $comp['tipo_compromisso'] ?> <?= $classe_tipo ?> <?= $classe_atrasado ?>"
                 onclick="window.location.href='<?= $link ?>'"
                 style="<?= $estilo_concluido ?>">
                <div class="compromisso-header">
                    <div style="flex: 1;">
                        <span class="compromisso-tipo-badge tipo-<?= $comp['tipo_compromisso'] ?>">
                            <?= $tipo_icon ?> <?= strtoupper($comp['tipo_compromisso']) ?>
                        </span>
                        <!-- ✅ BADGE DE ATRASADO -->
                        <?php if ($esta_atrasado): ?>
                            <span class="badge-atrasado">
                                <i class="fas fa-exclamation-triangle"></i> ATRASADO
                            </span>
                        <?php endif; ?>
                        <div class="compromisso-title" style="<?= $estilo_concluido ?>">
                            <?= $badge_tipo ?>
                            <?= htmlspecialchars($comp['titulo']) ?>
                            <?= $badge_prospecto ?>  <!-- ← BADGE DE PROSPECTO AQUI -->
                        </div>
                        <div class="compromisso-time" style="<?= $estilo_concluido ?>">
                            🕐 <?= $data->format('H:i') ?> - <?= $dias_texto ?>
                        </div>
                        <!-- ✅ MOSTRAR QUANTOS DIAS ESTÁ ATRASADO -->
                        <?php if ($esta_atrasado): ?>
                            <?php
                            $data_venc = new DateTime($comp['data_compromisso']);
                            $hoje_dt = new DateTime();
                            $diff_atraso = $hoje_dt->diff($data_venc);
                            ?>
                            <span style="color: #dc3545; font-weight: 700; margin-left: 10px;">
                                (<?= $diff_atraso->days ?> dia<?= $diff_atraso->days > 1 ? 's' : '' ?> de atraso)
                            </span>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($comp['prioridade'] && in_array($comp['prioridade'], ['urgente', 'Urgente', 'alta', 'Alta'])): ?>
                    <div>
                        <span style="background: rgba(220, 53, 69, 0.1); color: #dc3545; padding: 6px 12px; border-radius: 20px; font-size: 12px; font-weight: 700;">
                            🚨 <?= strtoupper($comp['prioridade']) ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if ($comp['descricao']): ?>
                <div style="color: #666; font-size: 14px; margin-bottom: 12px; line-height: 1.5; <?= $estilo_concluido ?>">
                    <?= htmlspecialchars(substr($comp['descricao'], 0, 150)) ?>
                    <?= strlen($comp['descricao']) > 150 ? '...' : '' ?>
                </div>
                <?php endif; ?>
                
                <div class="compromisso-meta">
                    <?php if ($comp['responsavel_nome']): ?>
                    <div class="compromisso-meta-item">
                        👤 <strong><?= htmlspecialchars($comp['responsavel_nome']) ?></strong>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($comp['revisor_nome']) && in_array($comp['status'], ['aguardando_revisao', 'revisao_aceita', 'revisao_recusada'])): ?>
                    <div class="compromisso-meta-item" style="background: #fff3cd; padding: 8px 12px; border-radius: 6px; border-left: 3px solid #ffc107;">
                        📋 <strong style="color: #856404;">Em revisão com: <?= htmlspecialchars($comp['revisor_nome']) ?></strong>
                    </div>
                    <?php endif; ?>
                    
                    <?php
                    // Buscar envolvidos para este compromisso
                    $dados_env = EnvolvidosHelper::buscar($comp['tipo_compromisso'], $comp['id'], 2);
                    $envolvidos = $dados_env['envolvidos'];
                    $total_envolvidos = $dados_env['total'];
                    
                    if (!empty($envolvidos)): ?>
                        <div class="compromisso-meta-item">
                            <span style="color: #667eea;">👥</span>
                            <?= EnvolvidosHelper::renderTags($envolvidos, $total_envolvidos, 2) ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($comp['cliente_nome']): ?>
                    <div class="compromisso-meta-item">
                        🏢 <?= htmlspecialchars($comp['cliente_nome']) ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($comp['numero_processo']): ?>
                    <div class="compromisso-meta-item">
                        ⚖️ <?= htmlspecialchars($comp['numero_processo']) ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($comp['local_evento']): ?>
                    <div class="compromisso-meta-item">
                        📍 <?= htmlspecialchars($comp['local_evento']) ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($comp['status']): ?>
                    <div class="compromisso-meta-item">
                        <?php
                        $status_colors = [
                            'concluida' => ['bg' => '#28a745', 'text' => 'white', 'icon' => '✅'],
                            'concluído' => ['bg' => '#28a745', 'text' => 'white', 'icon' => '✅'],
                            'cumprido' => ['bg' => '#28a745', 'text' => 'white', 'icon' => '✅'],
                            'realizado' => ['bg' => '#28a745', 'text' => 'white', 'icon' => '✅'],
                            'pendente' => ['bg' => '#ffc107', 'text' => '#000', 'icon' => '⏳'],
                            'agendada' => ['bg' => '#17a2b8', 'text' => 'white', 'icon' => '📅'],
                            'agendado' => ['bg' => '#17a2b8', 'text' => 'white', 'icon' => '📅'],
                            'em_andamento' => ['bg' => '#007bff', 'text' => 'white', 'icon' => '🔄'],
                            'em andamento' => ['bg' => '#007bff', 'text' => 'white', 'icon' => '🔄'],
                            'cancelada' => ['bg' => '#6c757d', 'text' => 'white', 'icon' => '❌'],
                            'cancelado' => ['bg' => '#6c757d', 'text' => 'white', 'icon' => '❌'],
                            'atrasada' => ['bg' => '#dc3545', 'text' => 'white', 'icon' => '🚨'],
                            'atrasado' => ['bg' => '#dc3545', 'text' => 'white', 'icon' => '🚨'],
                        ];
                        
                        $status_lower = strtolower($comp['status']);
                        $color_config = $status_colors[$status_lower] ?? ['bg' => '#667eea', 'text' => 'white', 'icon' => '📌'];
                        ?>
                        <span style="
                            background: <?= $color_config['bg'] ?>; 
                            color: <?= $color_config['text'] ?>; 
                            padding: 5px 12px; 
                            border-radius: 12px; 
                            font-size: 12px; 
                            font-weight: 700;
                            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
                            display: inline-flex;
                            align-items: center;
                            gap: 5px;
                        ">
                            <?= $color_config['icon'] ?> <?= strtoupper(str_replace('_', ' ', $comp['status'])) ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>
<script>
// ============================================================================
// JAVASCRIPT PARA AGENDA - VERSÃO CORRIGIDA
// ============================================================================

console.log('🔧 Iniciando JavaScript da Agenda...');

// Aguardar carregamento do jQuery e DOM
(function() {
    function inicializar() {
        console.log('✅ Inicializando funcionalidades da agenda');

        // ========================================
        // 1. ATUALIZAR CAMPO HIDDEN DOS USUÁRIOS
        // ========================================
        const formFiltros = document.getElementById('formFiltros');
        if (formFiltros) {
            console.log('✅ Formulário de filtros encontrado');
            formFiltros.addEventListener('submit', function(e) {
                // ✅ Sincronizar usuários (agora via checkboxes)
                const checkboxes = document.querySelectorAll('input[name="usuarios_select[]"]:checked');
                const hidden = document.getElementById('usuariosHidden');
                
                if (hidden) {
                    const ids = Array.from(checkboxes).map(cb => cb.value);
                    hidden.value = ids.join(',');
                    console.log('👥 Usuários ao submeter:', ids);
                }
                
                // Salvar filtros - AGORA FUNCIONA!
                window.salvarFiltros();
            });
        }
        
        // ========================================
        // 2. AUTO-SUBMIT NO PERÍODO
        // ========================================
        const selectPeriodo = document.getElementById('selectPeriodo');
        if (selectPeriodo) {
            console.log('✅ Select de período encontrado');
            selectPeriodo.addEventListener('change', function() {
                if (this.value !== 'personalizado' && formFiltros) {
                    formFiltros.submit();
                }
            });
        }
        
        // ========================================
        // 3. OBTER REFERÊNCIA DO NÚCLEO (UMA ÚNICA VEZ)
        // ========================================
        const selectNucleo = document.getElementById('selectNucleo');
        
        // ========================================
        // 4. SUBMETER AO SELECIONAR NÚCLEO
        // ========================================
        if (selectNucleo) {
            selectNucleo.addEventListener('change', function() {
                if (this.value) {
                    console.log('🏢 Núcleo selecionado:', this.value);
                    
                    // Submeter formulário
                    const form = document.getElementById('formFiltros');
                    if (form) {
                        window.salvarFiltros();
                        form.submit();
                    }
                }
            });
        }
        
        console.log('✅ Event listeners configurados');
    }
    
    // Verificar se jQuery está disponível
    if (typeof jQuery !== 'undefined') {
        console.log('✅ jQuery detectado - versão:', jQuery.fn.jquery);
        jQuery(document).ready(inicializar);
    } else {
        console.log('⚠️ jQuery não encontrado - usando DOMContentLoaded');
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', inicializar);
        } else {
            inicializar();
        }
    }
})();

// ========================================
// 5. FUNÇÃO GLOBAL PARA ABRIR FORMULÁRIO
// ========================================
window.abrirFormulario = function(tipo) {
    console.log('📝 [AGENDA] Abrindo formulário:', tipo);
    
    const container = document.getElementById('formularioContainer');
    if (!container) {
        console.error('❌ Container não encontrado!');
        return;
    }
    
    container.style.display = 'block';
    container.innerHTML = '<div style="padding:40px;text-align:center"><i class="fas fa-spinner fa-spin fa-3x" style="color:#667eea"></i><p style="margin-top:20px;color:#666">Carregando...</p></div>';
    
    const timestamp = Date.now();
    const url = `formularios/form_${tipo}.php?v=${timestamp}`;
    
    fetch(url, {
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => {
        console.log('📊 Status:', response.status);
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        return response.text();
    })
    .then(html => {
        console.log('✅ HTML carregado:', html.length, 'chars');
        
        if (html.includes('Login - SIGAM')) {
            throw new Error('Sessão expirou');
        }
        
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = html;
        container.innerHTML = '';
        container.appendChild(tempDiv);
        
        // EXECUTAR SCRIPTS
        const scripts = tempDiv.querySelectorAll('script');
        console.log('📜 Scripts encontrados:', scripts.length);
        
        scripts.forEach((oldScript, index) => {
            console.log(`🚀 Executando script ${index + 1}`);
            const newScript = document.createElement('script');
            Array.from(oldScript.attributes).forEach(attr => {
                newScript.setAttribute(attr.name, attr.value);
            });
            newScript.textContent = oldScript.textContent;
            oldScript.parentNode.replaceChild(newScript, oldScript);
            console.log(`✅ Script ${index + 1} executado`);
        });
        
        setTimeout(() => {
            console.log('🎉 Formulário pronto!');
            container.scrollIntoView({ behavior: 'smooth' });
        }, 100);
    })
    .catch(error => {
        console.error('❌ Erro:', error);
        container.innerHTML = `
            <div style="margin:30px;padding:30px;background:#f8d7da;border-radius:15px;border:1px solid #f5c6cb;color:#721c24">
                <h3><i class="fas fa-exclamation-triangle"></i> Erro ao Carregar</h3>
                <p><strong>Erro:</strong> ${error.message}</p>
                <button onclick="window.location.reload()" style="background:#dc3545;color:white;border:none;padding:12px 24px;border-radius:8px;cursor:pointer;font-weight:bold;margin-top:15px">
                    🔄 Recarregar Página
                </button>
            </div>
        `;
    });
};

window.fecharFormulario = function() {
    const container = document.getElementById('formularioContainer');
    if (container) {
        container.style.display = 'none';
        container.innerHTML = '';
    }
};

console.log('✅ JavaScript da Agenda carregado - funções globais definidas');

// ========================================
// 8. TOGGLE DO DROPDOWN "NOVO"
// ========================================
window.toggleDropdownNovo = function(event) {
    event.stopPropagation();
    const dropdown = document.getElementById('dropdownNovo');
    const button = document.getElementById('btnNovo');
    
    if (!dropdown || !button) {
        console.error('❌ Dropdown ou botão não encontrados');
        return;
    }
    
    if (dropdown.style.display === 'none' || dropdown.style.display === '') {
        dropdown.style.display = 'block';
        button.classList.add('active');
        console.log('✅ Dropdown aberto');
    } else {
        dropdown.style.display = 'none';
        button.classList.remove('active');
        console.log('❌ Dropdown fechado');
    }
};

// ========================================
// 9. FECHAR DROPDOWN AO CLICAR FORA
// ========================================
document.addEventListener('click', function(event) {
    const dropdown = document.getElementById('dropdownNovo');
    const button = document.getElementById('btnNovo');
    
    if (dropdown && button) {
        const isClickInside = button.contains(event.target) || dropdown.contains(event.target);
        
        if (!isClickInside && dropdown.style.display === 'block') {
            dropdown.style.display = 'none';
            button.classList.remove('active');
            console.log('❌ Dropdown fechado (clique fora)');
        }
    }
});

// ========================================
// 10. FECHAR DROPDOWN AO PRESSIONAR ESC
// ========================================
document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        const dropdown = document.getElementById('dropdownNovo');
        const button = document.getElementById('btnNovo');
        
        if (dropdown && dropdown.style.display === 'block') {
            dropdown.style.display = 'none';
            if (button) button.classList.remove('active');
            console.log('❌ Dropdown fechado (ESC)');
        }
    }
});

console.log('✅ Sistema de dropdown manual inicializado');

// ========================================
// FUNÇÃO PARA TROCAR VISUALIZAÇÃO
// ========================================
window.trocarVisualizacao = function(view) {
    const url = new URL(window.location.href);
    url.searchParams.set('view', view);
    window.location.href = url.toString();
};
</script>

<!-- Script de Sincronização de Filtros -->
<script src="js/filtros-sync.js?v=<?= time() ?>"></script>

<?php
return ob_get_clean();
}

// ============================================================================
// CHAMAR FUNÇÃO E RENDERIZAR
// ============================================================================
$conteudo = renderConteudoAgenda($stats, $compromissos, $usuarios, $nucleos, $usuarios_selecionados, $usuario_logado, $filtro_tipo, $filtro_periodo, $filtro_busca, $nucleo_selecionado, $hoje, $view);
echo renderLayout('Agenda', $conteudo, 'agenda');

// ✅ MODAL DEVE SER INCLUÍDO AQUI, FORA DA FUNÇÃO
include 'includes/modal_revisao.php';
?>