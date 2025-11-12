<?php
// modules/publicacoes/index.php
require_once '../../includes/auth.php';
Auth::protect();

// Verificação de módulo em desenvolvimento
require_once __DIR__ . '/../../config/modules_config.php';
$moduloAtual = 'publicacoes';
$usuarioLogado = $_SESSION['usuario_id'] ?? null;

if (verificarModuloEmDesenvolvimento($moduloAtual, $usuarioLogado)) {
    include __DIR__ . '/../../config/paginas/em_desenvolvimento.html';
    exit;
}

require_once '../../config/database.php';
require_once '../../includes/layout.php';

$usuario_logado = Auth::user();
$nivel_acesso = $usuario_logado['nivel_acesso'];
$usuario_id = $usuario_logado['usuario_id'];

// Filtros
$status_filtro = $_GET['status'] ?? 'nao_tratado';
$tribunal_filtro = $_GET['tribunal'] ?? '';
$data_inicio = $_GET['data_inicio'] ?? ''; // SEM filtro automático
$data_fim = $_GET['data_fim'] ?? ''; // SEM filtro automático
$busca = $_GET['busca'] ?? '';
$vinculado = $_GET['vinculado'] ?? '';
$ordenacao = $_GET['ordenacao'] ?? 'mais_antigas'; // NOVO: ordenação padrão

// Construir query COM FILTRO DE PERMISSÃO
$where = ["p.deleted_at IS NULL"];
$params = [];

// === FILTRO DE PERMISSÃO: Controlar visibilidade das publicações ===
$sql_user_perm = "SELECT visualiza_publicacoes_nao_vinculadas FROM usuarios WHERE id = ? LIMIT 1";
$stmt_perm = executeQuery($sql_user_perm, [$usuario_id]);
$user_perm = $stmt_perm->fetch();
$pode_ver_nao_vinculadas = $user_perm['visualiza_publicacoes_nao_vinculadas'] ?? 0;

if (!$pode_ver_nao_vinculadas) {
    // Usuário NÃO pode ver publicações sem processo
    // Mostrar apenas publicações de processos onde ele é responsável
    $where[] = "(p.processo_id IS NOT NULL AND EXISTS (
        SELECT 1 FROM processos pr 
        WHERE pr.id = p.processo_id 
        AND pr.responsavel_id = ?
    ))";
    $params[] = $usuario_id;
} else {
    // Usuário PODE ver publicações não vinculadas
    // Mostrar: (1) publicações sem processo OU (2) processos dele OU (3) processos sem responsável definido
    $where[] = "(
        p.processo_id IS NULL 
        OR EXISTS (
            SELECT 1 FROM processos pr 
            WHERE pr.id = p.processo_id 
            AND (pr.responsavel_id = ? OR pr.responsavel_id IS NULL)
        )
    )";
    $params[] = $usuario_id;
}
// === FIM DO FILTRO DE PERMISSÃO ===

if ($status_filtro !== 'todos') {
    $where[] = "p.status_tratamento = ?";
    $params[] = $status_filtro;
}

if ($tribunal_filtro) {
    $where[] = "p.tribunal = ?";
    $params[] = $tribunal_filtro;
}

if ($data_inicio && $data_fim) {
    $where[] = "DATE(p.data_publicacao) BETWEEN ? AND ?";
    $params[] = $data_inicio;
    $params[] = $data_fim;
}

if ($busca) {
    require_once __DIR__ . '/../../includes/search_helpers.php';
    $busca_normalizada = normalizarParaBusca($busca);
    
    $where[] = "(
        p.numero_processo_cnj LIKE ? 
        OR p.numero_processo_tj LIKE ? 
        OR p.conteudo LIKE ? 
        OR p.polo_ativo LIKE ? 
        OR p.polo_passivo LIKE ?
        OR REPLACE(REPLACE(REPLACE(REPLACE(p.numero_processo_cnj, '.', ''), '-', ''), '/', ''), ' ', '') LIKE ?
        OR REPLACE(REPLACE(REPLACE(REPLACE(p.numero_processo_tj, '.', ''), '-', ''), '/', ''), ' ', '') LIKE ?
    )";
    
    $search_term = "%$busca%";
    $search_term_normalizado = "%$busca_normalizada%";
    
    $params = array_merge($params, [
        $search_term,           // numero_processo_cnj original
        $search_term,           // numero_processo_tj original
        $search_term,           // conteudo
        $search_term,           // polo_ativo
        $search_term,           // polo_passivo
        $search_term_normalizado, // numero_processo_cnj normalizado
        $search_term_normalizado  // numero_processo_tj normalizado
    ]);
}

if ($vinculado === 'sim') {
    $where[] = "p.processo_id IS NOT NULL";
} elseif ($vinculado === 'nao') {
    $where[] = "p.processo_id IS NULL";
}

$where_clause = implode(' AND ', $where);

// Definir ordenação
$order_by = match($ordenacao) {
    'mais_recentes' => 'p.data_publicacao DESC',
    'mais_antigas' => 'p.data_publicacao ASC',
    'status' => "FIELD(p.status_tratamento, 'nao_tratado', 'tratada', 'concluido', 'descartado'), p.data_publicacao ASC",
    default => 'p.data_publicacao ASC'
};

// Buscar publicações (OTIMIZADO)
$sql = "SELECT 
        p.id, p.titulo, p.id_ws, p.numero_processo_cnj, p.numero_processo_tj,
        p.tipo_documento, p.tribunal, p.comarca, p.vara, p.uf,
        p.data_publicacao, p.data_disponibilizacao, p.status_tratamento,
        p.processo_id, p.polo_ativo, p.polo_passivo, p.conteudo,
        p.tratada_por_usuario_id, p.data_tratamento,
        pr.numero_processo as processo_numero,
        pr.cliente_nome as processo_cliente,
        u.nome as tratado_por_nome
        FROM publicacoes p
        LEFT JOIN processos pr ON p.processo_id = pr.id
        LEFT JOIN usuarios u ON p.tratada_por_usuario_id = u.id
        WHERE $where_clause
        ORDER BY $order_by
        LIMIT 100";

$stmt = executeQuery($sql, $params);
$publicacoes = $stmt->fetchAll();

// Estatísticas COM FILTRO DE PERMISSÃO
if (!$pode_ver_nao_vinculadas) {
    // Usuário só vê processos dele
    $stats_sql = "SELECT 
        COUNT(*) as total,
        SUM(status_tratamento = 'nao_tratado') as nao_tratadas,
        SUM(status_tratamento = 'tratada') as tratadas,
        SUM(status_tratamento = 'concluido') as concluidas,
        SUM(status_tratamento = 'descartado') as descartadas,
        SUM(processo_id IS NOT NULL) as vinculadas,
        SUM(processo_id IS NULL) as nao_vinculadas
        FROM publicacoes p
        WHERE deleted_at IS NULL
        AND processo_id IS NOT NULL
        AND EXISTS (
            SELECT 1 FROM processos pr 
            WHERE pr.id = p.processo_id 
            AND pr.responsavel_id = ?
        )";
    $stmt_stats = executeQuery($stats_sql, [$usuario_id]);
} else {
    // Usuário vê publicações não vinculadas + processos dele + processos sem responsável
    $stats_sql = "SELECT 
        COUNT(*) as total,
        SUM(status_tratamento = 'nao_tratado') as nao_tratadas,
        SUM(status_tratamento = 'tratada') as tratadas,
        SUM(status_tratamento = 'concluido') as concluidas,
        SUM(status_tratamento = 'descartado') as descartadas,
        SUM(processo_id IS NOT NULL) as vinculadas,
        SUM(processo_id IS NULL) as nao_vinculadas
        FROM publicacoes p
        WHERE deleted_at IS NULL
        AND (
            processo_id IS NULL 
            OR EXISTS (
                SELECT 1 FROM processos pr 
                WHERE pr.id = p.processo_id 
                AND (pr.responsavel_id = ? OR pr.responsavel_id IS NULL)
            )
        )";
    $stmt_stats = executeQuery($stats_sql, [$usuario_id]);
}
$stats = $stmt_stats->fetch();

// Buscar tribunais para filtro
$sql_tribunais = "SELECT DISTINCT tribunal FROM publicacoes WHERE tribunal IS NOT NULL AND deleted_at IS NULL ORDER BY tribunal";
$stmt_trib = executeQuery($sql_tribunais);
$tribunais = $stmt_trib->fetchAll(PDO::FETCH_COLUMN);

ob_start();
?>
<script src="../../assets/js/toast.js"></script>

<style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        min-height: 100vh;
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
        flex-wrap: wrap;
        gap: 15px;
    }
    
    .page-header h2 {
        color: #1a1a1a;
        font-size: 28px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .header-actions {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }

    .btn {
        padding: 12px 24px;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-size: 14px;
    }

    .btn-primary {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(102, 126, 234, 0.6);
    }

    .btn-success {
        background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        color: white;
        box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
    }

    .btn-success:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .stat-card {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        padding: 25px;
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        text-align: center;
        transition: all 0.3s;
        border-left: 4px solid transparent;
        cursor: pointer;
    }
    
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 40px rgba(0,0,0,0.2);
    }
    
    .stat-card h3 {
        font-size: 36px;
        margin-bottom: 8px;
        font-weight: 700;
    }
    
    .stat-card p {
        color: #555;
        font-size: 13px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .stat-card.total { border-left-color: #667eea; }
    .stat-card.total h3 { color: #667eea; }
    
    .stat-card.nao-tratada { border-left-color: #dc3545; }
    .stat-card.nao-tratada h3 { color: #dc3545; }
    
    .stat-card.tratada { border-left-color: #17a2b8; }
    .stat-card.tratada h3 { color: #17a2b8; }
    
    .stat-card.concluida { border-left-color: #28a745; }
    .stat-card.concluida h3 { color: #28a745; }
    
    .stat-card.descartada { border-left-color: #6c757d; }
    .stat-card.descartada h3 { color: #6c757d; }

    .stat-card.vinculada { border-left-color: #17a2b8; }
    .stat-card.vinculada h3 { color: #17a2b8; }

    .stat-card.nao-vinculada { border-left-color: #ff6b6b; }
    .stat-card.nao-vinculada h3 { color: #ff6b6b; }

    .filters-container {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        padding: 25px;
        margin-bottom: 30px;
    }

    .filters-container h3 {
        color: #1a1a1a;
        margin-bottom: 20px;
        font-size: 18px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 10px;
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
        padding: 10px 12px;
        border: 1px solid #ddd;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.3s;
        background: white;
    }
    
    .filter-group input:focus,
    .filter-group select:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }

    .filter-actions {
        display: flex;
        gap: 10px;
        justify-content: flex-end;
    }

    .btn-filter {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 12px 24px;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
    }
    
    .btn-filter:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
    }

    .btn-clear {
        background: #6c757d;
        color: white;
        padding: 12px 24px;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
    }

    .btn-clear:hover {
        background: #5a6268;
        transform: translateY(-2px);
    }

    .publicacoes-list {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        overflow: hidden;
    }

    .publicacao-item {
        padding: 20px;
        border-bottom: 1px solid rgba(0,0,0,0.05);
        transition: all 0.3s;
        cursor: pointer;
    }

    .publicacao-item:hover {
        background: rgba(102, 126, 234, 0.05);
    }

    .publicacao-item:last-child {
        border-bottom: none;
    }

    .publicacao-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 12px;
        gap: 15px;
        flex-wrap: wrap;
    }

    .publicacao-titulo {
        flex: 1;
        min-width: 250px;
    }

    .publicacao-titulo h4 {
        color: #1a1a1a;
        font-size: 16px;
        margin-bottom: 5px;
        font-weight: 600;
    }

    .publicacao-titulo .processo-numero {
        color: #667eea;
        font-weight: 600;
        font-size: 14px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        transition: all 0.3s;
    }

    .publicacao-titulo .processo-numero:hover {
        color: #764ba2;
        transform: translateX(3px);
    }

    .publicacao-titulo .processo-numero-copiar {
        cursor: pointer;
        padding: 4px 8px;
        border-radius: 6px;
        user-select: none;
    }

    .publicacao-titulo .processo-numero-copiar:hover {
        background: rgba(102, 126, 234, 0.1);
        transform: scale(1.05);
    }

    .publicacao-titulo .processo-numero-copiar:active {
        transform: scale(0.98);
    }

    .publicacao-badges {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        align-items: center;
    }

    .badge {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        white-space: nowrap;
    }

    .badge-nao-tratado { background: #dc3545; color: white; }
    .badge-tratada { background: #17a2b8; color: white; }
    .badge-concluido { background: #28a745; color: white; }
    .badge-descartado { background: #6c757d; color: white; }
    .badge-vinculado { background: #17a2b8; color: white; }
    .badge-nao-vinculado { background: #ff6b6b; color: white; }

    .publicacao-info {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 12px;
        margin-bottom: 12px;
        font-size: 13px;
        color: #666;
    }

    .info-item {
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .info-item strong {
        color: #333;
        font-weight: 600;
    }

    .publicacao-preview {
        background: rgba(0,0,0,0.02);
        padding: 12px;
        border-radius: 8px;
        margin-bottom: 12px;
        font-size: 13px;
        color: #555;
        line-height: 1.6;
        max-height: 80px;
        overflow: hidden;
        position: relative;
    }

    .publicacao-preview::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 30px;
        background: linear-gradient(to bottom, transparent, rgba(0,0,0,0.02));
    }

    .publicacao-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .btn-action {
        padding: 8px 16px;
        border: none;
        border-radius: 6px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        gap: 6px;
        text-decoration: none;
    }

    .btn-view {
        background: #667eea;
        color: white;
    }

    .btn-view:hover {
        background: #764ba2;
        transform: translateY(-1px);
    }

    .btn-treat {
        background: #ffc107;
        color: #000;
    }

    .btn-treat:hover {
        background: #e0a800;
        transform: translateY(-1px);
    }

    .btn-complete {
        background: #28a745;
        color: white;
    }

    .btn-complete:hover {
        background: #218838;
        transform: translateY(-1px);
    }

    .btn-discard {
        background: #6c757d;
        color: white;
    }

    .btn-discard:hover {
        background: #5a6268;
        transform: translateY(-1px);
    }

    .alert {
        padding: 20px;
        border-radius: 12px;
        margin-bottom: 20px;
        text-align: center;
        font-weight: 600;
        font-size: 16px;
    }
    
    .alert-info {
        background: rgba(23, 162, 184, 0.1);
        border: 1px solid rgba(23, 162, 184, 0.3);
        color: #0c5460;
    }

    .loading {
        text-align: center;
        padding: 60px 20px;
        color: #666;
        font-size: 18px;
    }

    .empty-state {
        text-align: center;
        padding: 80px 20px;
    }

    .empty-state svg {
        width: 120px;
        height: 120px;
        margin-bottom: 20px;
        opacity: 0.3;
    }

    .empty-state h3 {
        color: #1a1a1a;
        margin-bottom: 10px;
        font-size: 24px;
    }

    .empty-state p {
        color: #666;
        font-size: 16px;
    }

    /* Modal Tratamento Rápido */
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        overflow: auto;
        background-color: rgba(0,0,0,0.6);
        padding-top: 50px;
    }
    
    .modal-content {
        background-color: #fefefe;
        margin: 3% auto;
        padding: 30px;
        width: 90%;
        max-width: 500px;
        border-radius: 15px;
        box-shadow: 0 8px 25px rgba(0,0,0,0.3);
        animation: slideDown 0.3s ease;
    }
    
    @keyframes slideDown {
        from {
            transform: translateY(-50px);
            opacity: 0;
        }
        to {
            transform: translateY(0);
            opacity: 1;
        }
    }
    
    .close-modal {
        color: #888;
        float: right;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
        line-height: 1;
        transition: color 0.3s;
    }
    
    .close-modal:hover {
        color: #000;
    }

    .modal-content h3 {
        margin-top: 0;
        margin-bottom: 20px;
        color: #1a1a1a;
    }

    .modal-options {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .modal-option {
        padding: 15px;
        border: 2px solid #e0e0e0;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.3s;
        display: flex;
        align-items: center;
        gap: 12px;
        background: white;
    }

    .modal-option:hover {
        border-color: #667eea;
        background: rgba(102, 126, 234, 0.05);
        transform: translateX(5px);
    }

    .modal-option svg {
        width: 24px;
        height: 24px;
        flex-shrink: 0;
    }

    .modal-option-text h4 {
        margin: 0 0 4px 0;
        color: #1a1a1a;
        font-size: 16px;
    }

    .modal-option-text p {
        margin: 0;
        color: #666;
        font-size: 13px;
    }

    @media (max-width: 768px) {
        .page-header {
            flex-direction: column;
            text-align: center;
        }

        .stats-grid {
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        }

        .filters-grid {
            grid-template-columns: 1fr;
        }

        .publicacao-info {
            grid-template-columns: 1fr;
        }

        .publicacao-actions {
            justify-content: center;
        }
    }
</style>

<div class="page-header">
    <h2>
        <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
            <polyline points="14 2 14 8 20 8"></polyline>
            <line x1="16" y1="13" x2="8" y2="13"></line>
            <line x1="16" y1="17" x2="8" y2="17"></line>
            <polyline points="10 9 9 9 8 9"></polyline>
        </svg>
        Publicações
    </h2>
    <div class="header-actions">
        <button class="btn btn-success" onclick="sincronizarPublicacoes()">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="23 4 23 10 17 10"></polyline>
                <path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"></path>
            </svg>
            Sincronizar Agora
        </button>
    </div>
</div>

<div class="stats-grid">
    <div class="stat-card total" onclick="filtrarPorStatus('todos')">
        <h3><?= number_format($stats['total']) ?></h3>
        <p>Total de Publicações</p>
    </div>
    <div class="stat-card nao-tratada" onclick="filtrarPorStatus('nao_tratado')">
        <h3><?= number_format($stats['nao_tratadas']) ?></h3>
        <p>Não Tratadas</p>
    </div>
        <div class="stat-card tratada" onclick="filtrarPorStatus('tratada')">
        <h3><?= number_format($stats['tratadas'] ?? 0) ?></h3>
        <p>Tratadas</p>
    </div>
    <div class="stat-card concluida" onclick="filtrarPorStatus('concluido')">
        <h3><?= number_format($stats['concluidas']) ?></h3>
        <p>Concluídas</p>
    </div>
    <div class="stat-card descartada" onclick="filtrarPorStatus('descartado')">
        <h3><?= number_format($stats['descartadas']) ?></h3>
        <p>Descartadas</p>
    </div>
    <div class="stat-card vinculada" onclick="filtrarPorVinculo('sim')">
        <h3><?= number_format($stats['vinculadas']) ?></h3>
        <p>Vinculadas</p>
    </div>
    <div class="stat-card nao-vinculada" onclick="filtrarPorVinculo('nao')">
        <h3><?= number_format($stats['nao_vinculadas']) ?></h3>
        <p>Não Vinculadas</p>
    </div>
</div>

<div class="filters-container">
    <h3>
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polygon points="22 3 2 3 10 12.46 10 19 14 21 14 12.46 22 3"></polygon>
        </svg>
        Filtros
    </h3>
    <form method="GET" id="filterForm">
        <div class="filters-grid">
            <div class="filter-group">
                <label>Buscar:</label>
                <input type="text" name="busca" value="<?= htmlspecialchars($busca) ?>" 
                       placeholder="Número, partes ou conteúdo">
            </div>

            <div class="filter-group">
                <label>Status:</label>
                <select name="status">
                    <option value="todos" <?= $status_filtro === 'todos' ? 'selected' : '' ?>>Todos</option>
                    <option value="nao_tratado" <?= $status_filtro === 'nao_tratado' ? 'selected' : '' ?>>Não Tratadas</option>
                    <option value="tratada" <?= $status_filtro === 'tratada' ? 'selected' : '' ?>>Tratadas</option>
                    <option value="concluido" <?= $status_filtro === 'concluido' ? 'selected' : '' ?>>Concluídas</option>
                    <option value="descartado" <?= $status_filtro === 'descartado' ? 'selected' : '' ?>>Descartadas</option>
                </select>
            </div>

            <div class="filter-group">
                <label>Tribunal:</label>
                <select name="tribunal">
                    <option value="">Todos</option>
                    <?php foreach ($tribunais as $trib): ?>
                        <option value="<?= htmlspecialchars($trib) ?>" <?= $tribunal_filtro === $trib ? 'selected' : '' ?>>
                            <?= htmlspecialchars($trib) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group">
                <label>Vinculação:</label>
                <select name="vinculado">
                    <option value="">Todas</option>
                    <option value="sim" <?= $vinculado === 'sim' ? 'selected' : '' ?>>Vinculadas</option>
                    <option value="nao" <?= $vinculado === 'nao' ? 'selected' : '' ?>>Não Vinculadas</option>
                </select>
            </div>

            <div class="filter-group">
                <label>Data Início:</label>
                <input type="date" name="data_inicio" value="<?= $data_inicio ?>">
            </div>

            <div class="filter-group">
                <label>Data Fim:</label>
                <input type="date" name="data_fim" value="<?= $data_fim ?>">
            </div>

            <div class="filter-group">
                <label>Ordenar por:</label>
                <select name="ordenacao">
                    <option value="mais_antigas" <?= $ordenacao === 'mais_antigas' ? 'selected' : '' ?>>⬆️ Mais Antigas Primeiro</option>
                    <option value="mais_recentes" <?= $ordenacao === 'mais_recentes' ? 'selected' : '' ?>>⬇️ Mais Recentes Primeiro</option>
                    <option value="status" <?= $ordenacao === 'status' ? 'selected' : '' ?>>📊 Por Status (Não tratadas primeiro)</option>
                </select>
            </div>
        </div>

        <div class="filter-actions">
            <button type="button" class="btn-clear" onclick="limparFiltros()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="18" y1="6" x2="6" y2="18"></line>
                    <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
                Limpar
            </button>
            <button type="submit" class="btn-filter">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="11" cy="11" r="8"></circle>
                    <path d="m21 21-4.35-4.35"></path>
                </svg>
                Filtrar
            </button>
        </div>
    </form>
</div>

<div class="publicacoes-list">
    <?php if (empty($publicacoes)): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
            </svg>
            <h3>Nenhuma publicação encontrada</h3>
            <p>Ajuste os filtros ou sincronize para buscar novas publicações</p>
        </div>
	<?php else: ?>
        <?php foreach ($publicacoes as $pub): ?>
            <div class="publicacao-item" data-href="visualizar.php?id=<?= $pub['id'] ?>" style="cursor: pointer;">
                <div class="publicacao-header">
                    <div class="publicacao-titulo">
                        <?php 
                        // Se for WebServiceTabela, mostrar número do processo como título
                        $tipo_doc = $pub['tipo_documento'] ?? 'Intimação';
                        if (strtolower($tipo_doc) === 'webservicetabela') {
                            $numero_para_titulo = $pub['numero_processo_cnj'] ?: $pub['numero_processo_tj'] ?: 'WebServiceTabela';
                            ?>
                            <h4><?= htmlspecialchars($numero_para_titulo) ?></h4>
                        <?php } else { ?>
                            <h4><?= htmlspecialchars($tipo_doc) ?></h4>
                        <?php } ?>
                        <?php if ($pub['numero_processo_cnj']): ?>
								<span class="processo-numero processo-numero-copiar" 
									  data-numero="<?= htmlspecialchars($pub['numero_processo_cnj']) ?>"
									  onclick="copiarNumeroProcesso(event, '<?= htmlspecialchars($pub['numero_processo_cnj']) ?>');"
									  title="Clique para copiar o número do processo">
									<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
										<rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
										<path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
									</svg>
									<?= htmlspecialchars($pub['numero_processo_cnj']) ?>
								</span>
                        <?php elseif ($pub['numero_processo_tj']): ?>
                            <span class="processo-numero processo-numero-copiar" 
                                  data-numero="<?= htmlspecialchars($pub['numero_processo_tj']) ?>"
                                  onclick="copiarNumeroProcesso(event, '<?= htmlspecialchars($pub['numero_processo_tj']) ?>');"
                                  title="Clique para copiar o número do processo">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                </svg>
                                <?= htmlspecialchars($pub['numero_processo_tj']) ?>
                            </span>
                        <?php else: ?>
                            <!-- Mostrar informações alternativas quando não houver número de processo -->
                            <span class="processo-numero" style="cursor: default; color: #999;">
                                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                                    <polyline points="14 2 14 8 20 8"></polyline>
                                </svg>
                                Publicação #<?= $pub['id'] ?>
                                <?php if ($pub['id_ws']): ?>
                                    (WS: <?= htmlspecialchars($pub['id_ws']) ?>)
                                <?php endif; ?>
                            </span>
                            <?php if ($pub['tribunal'] || $pub['comarca']): ?>
                                <div style="font-size: 12px; color: #999; margin-top: 3px;">
                                    <?php if ($pub['tribunal']): ?>
                                        📍 <?= htmlspecialchars(substr($pub['tribunal'], 0, 40)) ?><?= strlen($pub['tribunal']) > 40 ? '...' : '' ?>
                                    <?php endif; ?>
                                    <?php if ($pub['comarca']): ?>
                                        - <?= htmlspecialchars($pub['comarca']) ?>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                    <div class="publicacao-badges">
                        <?php
                        $status_map = [
                            'nao_tratado' => ['Não Tratada', 'badge-nao-tratado'],
                            'tratada' => ['Tratada', 'badge-tratada'],
                            'concluido' => ['Concluída', 'badge-concluido'],
                            'descartado' => ['Descartada', 'badge-descartado']
                        ];
                        $status_info = $status_map[$pub['status_tratamento']] ?? ['Desconhecido', 'badge-nao-tratado'];
                        ?>
                        <span class="badge <?= $status_info[1] ?>">
                            <?= $status_info[0] ?>
                        </span>

                        <?php if ($pub['processo_id']): ?>
                            <span class="badge badge-vinculado">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                                    <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
                                </svg>
                                Vinculada
                            </span>
                        <?php else: ?>
                            <span class="badge badge-nao-vinculado">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                    <circle cx="12" cy="12" r="10"></circle>
                                    <line x1="15" y1="9" x2="9" y2="15"></line>
                                    <line x1="9" y1="9" x2="15" y2="15"></line>
                                </svg>
                                Não Vinculada
                            </span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="publicacao-info">
                    <?php if ($pub['tribunal']): ?>
                        <div class="info-item">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                                <line x1="16" y1="2" x2="16" y2="6"></line>
                                <line x1="8" y1="2" x2="8" y2="6"></line>
                                <line x1="3" y1="10" x2="21" y2="10"></line>
                            </svg>
                            <strong>Tribunal:</strong> <?= htmlspecialchars($pub['tribunal']) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($pub['comarca']): ?>
                        <div class="info-item">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
                                <circle cx="12" cy="10" r="3"></circle>
                            </svg>
                            <strong>Comarca:</strong> <?= htmlspecialchars($pub['comarca']) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($pub['vara']): ?>
                        <div class="info-item">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
                                <polyline points="9 22 9 12 15 12 15 22"></polyline>
                            </svg>
                            <strong>Vara:</strong> <?= htmlspecialchars($pub['vara']) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($pub['data_publicacao']): ?>
                        <div class="info-item">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"></circle>
                                <polyline points="12 6 12 12 16 14"></polyline>
                            </svg>
                            <strong>Publicação:</strong> <?= date('d/m/Y H:i', strtotime($pub['data_publicacao'])) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($pub['processo_id'] && $pub['processo_cliente']): ?>
                        <div class="info-item">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                            <strong>Cliente:</strong> <?= htmlspecialchars($pub['processo_cliente']) ?>
                        </div>
                    <?php endif; ?>

                    <?php 
					// Buscar tratamentos apenas para publicações tratadas
					$total_trat = 0;
					if ($pub['status_tratamento'] !== 'nao_tratado') {
						$sql_count = "SELECT COUNT(*) FROM publicacoes_tratamentos WHERE publicacao_id = ?";
						$stmt_count = executeQuery($sql_count, [$pub['id']]);
						$total_trat = $stmt_count->fetchColumn();
					}
					if ($total_trat > 0): 
					?>
                        <div class="info-item" style="color: #28a745;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="20 6 9 17 4 12"></polyline>
                            </svg>
                            <strong><?= $total_trat ?> tratamento(s)</strong> realizados
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ($pub['polo_ativo'] || $pub['polo_passivo']): ?>
                    <div class="publicacao-info">
                        <?php if ($pub['polo_ativo']): ?>
                            <div class="info-item">
                                <strong>Polo Ativo:</strong> <?= htmlspecialchars(substr($pub['polo_ativo'], 0, 80)) ?>
                                <?= strlen($pub['polo_ativo']) > 80 ? '...' : '' ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($pub['polo_passivo']): ?>
                            <div class="info-item">
                                <strong>Polo Passivo:</strong> <?= htmlspecialchars(substr($pub['polo_passivo'], 0, 80)) ?>
                                <?= strlen($pub['polo_passivo']) > 80 ? '...' : '' ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <?php if ($pub['conteudo']): ?>
                    <div class="publicacao-preview">
                        <?= htmlspecialchars(substr(strip_tags($pub['conteudo']), 0, 250)) ?>
                        <?= strlen($pub['conteudo']) > 250 ? '...' : '' ?>
                    </div>
                <?php endif; ?>

                <div class="publicacao-actions" onclick="event.stopPropagation();">
                    <button onclick="abrirPopupVisualizacao(<?= $pub['id'] ?>, event)" class="btn-action btn-view">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
                            <circle cx="12" cy="12" r="3"></circle>
                        </svg>
                        Visualizar
                    </a>

                    <?php if ($pub['status_tratamento'] === 'nao_tratado'): ?>
                        <button class="btn-action btn-treat" onclick="abrirModalTratamento(<?= $pub['id'] ?>)">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <line x1="12" y1="5" x2="12" y2="19"></line>
                                <line x1="5" y1="12" x2="19" y2="12"></line>
                            </svg>
                            Tratar
                        </button>

                        <button class="btn-action btn-complete" onclick="concluirPublicacao(<?= $pub['id'] ?>)">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="20 6 9 17 4 12"></polyline>
                            </svg>
                            Concluir
                        </button>

                        <button class="btn-action btn-discard" onclick="descartarPublicacao(<?= $pub['id'] ?>)">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <polyline points="3 6 5 6 21 6"></polyline>
                                <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                            </svg>
                            Descartar
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Modal Tratamento Rápido -->
<div id="modalTratamento" class="modal">
    <div class="modal-content" style="max-width: 700px; padding: 0; border-radius: 15px; overflow: hidden;">
        <div style="display: flex; justify-content: space-between; align-items: center; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 15px 20px;">
            <h3 style="margin: 0; color: white; font-size: 18px;">Tratamento de Publicação</h3>
            <span class="close-modal" onclick="fecharModalTratamento()" style="color: white; font-size: 28px; cursor: pointer;">&times;</span>
        </div>
        <iframe id="iframe-tratamento" src="" style="width: 100%; height: 75vh; border: none;" frameborder="0"></iframe>
    </div>
</div>

<script>
    const SITE_URL = '<?= SITE_URL ?>';
    let publicacaoSelecionadaId = null;

    function verPublicacao(id) {
        // Abrir em nova aba
        const width = 1000, height = 800;
        const left = (screen.width - width) / 2;
        const top = (screen.height - height) / 2;
        window.open('visualizar.php?id=' + id, 'visualizar_' + id,
            `width=${width},height=${height},left=${left},top=${top}`);
    }

    // Nova função para abrir popup de visualização rápida
    async function abrirPopupVisualizacao(id, event) {
        if (event) {
            event.stopPropagation();
            event.preventDefault();
        }
        
        mostrarNotificacao('🔄 Carregando...', 'info');
        
        try {
            // Buscar dados da publicação via API
            const response = await fetch('api.php?action=get&id=' + id);
            
            if (!response.ok) {
                throw new Error('HTTP error! status: ' + response.status);
            }
            
            const data = await response.json();
            
            console.log('Dados recebidos:', data); // DEBUG
            
            if (!data.success) {
                throw new Error(data.message || 'Erro ao carregar publicação');
            }
            
            const pub = data.publicacao;
            
            // Criar modal de visualização
            const modal = document.createElement('div');
            modal.className = 'modal-visualizacao';
            modal.style.cssText = `
                display: block;
                position: fixed;
                z-index: 2000;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                overflow: auto;
                background-color: rgba(0,0,0,0.7);
                padding: 20px;
            `;
            
            const statusColors = {
                'nao_tratado': { bg: 'rgba(220, 53, 69, 0.1)', border: '#dc3545', emoji: '🔴', texto: 'Não Tratada' },
                'em_tratamento': { bg: 'rgba(255, 193, 7, 0.1)', border: '#ffc107', emoji: '🟡', texto: 'Em Tratamento' },
                'concluido': { bg: 'rgba(40, 167, 69, 0.1)', border: '#28a745', emoji: '🟢', texto: 'Concluída' },
                'descartado': { bg: 'rgba(108, 117, 125, 0.1)', border: '#6c757d', emoji: '⚫', texto: 'Descartada' }
            };
            
            const statusInfo = statusColors[pub.status_tratamento] || statusColors['nao_tratado'];
            
            modal.innerHTML = `
                <div style="
                    background: white;
                    margin: 2% auto;
                    padding: 0;
                    max-width: 900px;
                    border-radius: 15px;
                    box-shadow: 0 8px 32px rgba(0,0,0,0.3);
                    animation: slideDown 0.3s ease;
                    max-height: 90vh;
                    overflow-y: auto;
                ">
                    <div style="
                        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                        padding: 20px 30px;
                        border-radius: 15px 15px 0 0;
                        display: flex;
                        justify-content: space-between;
                        align-items: center;
                    ">
                        <h3 style="color: white; margin: 0; font-size: 20px;">
                            📄 ${escapeHtml(pub.tipo_documento || 'Publicação')}
                        </h3>
                        <span onclick="this.closest('.modal-visualizacao').remove()" style="
                            color: white;
                            font-size: 28px;
                            font-weight: bold;
                            cursor: pointer;
                            line-height: 1;
                        ">&times;</span>
                    </div>
                    
                    <div style="padding: 30px;">
                        <!-- Status Banner -->
                        <div style="
                            background: ${statusInfo.bg};
                            border-left: 4px solid ${statusInfo.border};
                            padding: 15px;
                            border-radius: 8px;
                            margin-bottom: 25px;
                        ">
                            <strong style="font-size: 16px; color: #1a1a1a;">
                                Status: ${statusInfo.emoji} ${statusInfo.texto}
                            </strong>
                        </div>
                        
                        <!-- Informações -->
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin-bottom: 25px;">
                            ${pub.numero_processo_cnj ? `
                            <div>
                                <div style="color: #666; font-size: 12px; font-weight: 600; text-transform: uppercase; margin-bottom: 5px;">Processo CNJ</div>
                                <div style="color: #1a1a1a; font-weight: 600;">${escapeHtml(pub.numero_processo_cnj)}</div>
                            </div>
                            ` : ''}
                            
                            ${pub.tribunal ? `
                            <div>
                                <div style="color: #666; font-size: 12px; font-weight: 600; text-transform: uppercase; margin-bottom: 5px;">Tribunal</div>
                                <div style="color: #1a1a1a; font-weight: 600;">${escapeHtml(pub.tribunal)}</div>
                            </div>
                            ` : ''}
                            
                            ${pub.comarca ? `
                            <div>
                                <div style="color: #666; font-size: 12px; font-weight: 600; text-transform: uppercase; margin-bottom: 5px;">Comarca</div>
                                <div style="color: #1a1a1a; font-weight: 600;">${escapeHtml(pub.comarca)}</div>
                            </div>
                            ` : ''}
                            
                            ${pub.vara ? `
                            <div>
                                <div style="color: #666; font-size: 12px; font-weight: 600; text-transform: uppercase; margin-bottom: 5px;">Vara</div>
                                <div style="color: #1a1a1a; font-weight: 600;">${escapeHtml(pub.vara)}</div>
                            </div>
                            ` : ''}
                            
                            ${pub.data_publicacao ? `
                            <div>
                                <div style="color: #666; font-size: 12px; font-weight: 600; text-transform: uppercase; margin-bottom: 5px;">Data Publicação</div>
                                <div style="color: #1a1a1a; font-weight: 600;">${formatarData(pub.data_publicacao)}</div>
                            </div>
                            ` : ''}
                        </div>
                        
                        <!-- Partes -->
                        ${pub.polo_ativo || pub.polo_passivo ? `
                        <div style="margin-bottom: 25px;">
                            <h4 style="color: #1a1a1a; margin-bottom: 15px; font-size: 16px;">👥 Partes do Processo</h4>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 15px;">
                                ${pub.polo_ativo ? `
                                <div style="background: rgba(0,0,0,0.02); padding: 12px; border-radius: 8px;">
                                    <strong style="color: #667eea; font-size: 13px;">POLO ATIVO:</strong>
                                    <div style="margin-top: 5px; color: #333;">${escapeHtml(pub.polo_ativo)}</div>
                                </div>
                                ` : ''}
                                ${pub.polo_passivo ? `
                                <div style="background: rgba(0,0,0,0.02); padding: 12px; border-radius: 8px;">
                                    <strong style="color: #dc3545; font-size: 13px;">POLO PASSIVO:</strong>
                                    <div style="margin-top: 5px; color: #333;">${escapeHtml(pub.polo_passivo)}</div>
                                </div>
                                ` : ''}
                            </div>
                        </div>
                        ` : ''}
                        
                        <!-- Conteúdo -->
                        ${pub.conteudo ? `
                        <div style="margin-bottom: 25px;">
                            <h4 style="color: #1a1a1a; margin-bottom: 15px; font-size: 16px;">📝 Conteúdo da Publicação</h4>
                            <div style="
                                background: rgba(0,0,0,0.02);
                                padding: 20px;
                                border-radius: 8px;
                                max-height: 300px;
                                overflow-y: auto;
                                white-space: pre-wrap;
                                font-size: 14px;
                                line-height: 1.6;
                                color: #333;
                            ">${escapeHtml(pub.conteudo)}</div>
                        </div>
                        ` : ''}
                        
                        <!-- Processo Vinculado -->
                        ${pub.processo_id ? `
                        <div style="
                            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
                            padding: 15px;
                            border-radius: 8px;
                            border: 2px solid rgba(102, 126, 234, 0.3);
                            margin-bottom: 25px;
                        ">
                            <strong style="color: #667eea;">🔗 Processo Vinculado:</strong>
                            <div style="margin-top: 8px; color: #333;">
                                ${escapeHtml(pub.processo_numero || 'N/A')} - ${escapeHtml(pub.processo_cliente || 'N/A')}
                            </div>
                        </div>
                        ` : `
                        <div style="
                            background: rgba(255, 193, 7, 0.1);
                            padding: 15px;
                            border-radius: 8px;
                            border: 2px solid rgba(255, 193, 7, 0.3);
                            margin-bottom: 25px;
                            color: #856404;
                        ">
                            ⚠️ <strong>Esta publicação não está vinculada a nenhum processo</strong>
                        </div>
                        `}
                        
                        <!-- Ações -->
                        <div style="display: flex; gap: 10px; flex-wrap: wrap; justify-content: flex-end; padding-top: 20px; border-top: 2px solid rgba(0,0,0,0.05);">
                            <button onclick="verPublicacao(${pub.id})" style="
                                padding: 10px 20px;
                                background: #667eea;
                                color: white;
                                border: none;
                                border-radius: 8px;
                                font-weight: 600;
                                cursor: pointer;
                            ">🔗 Abrir Completo</button>
                            
                            ${pub.status_tratamento === 'nao_tratado' ? `
                            <button onclick="this.closest('.modal-visualizacao').remove(); abrirModalTratamento(${pub.id});" style="
                                padding: 10px 20px;
                                background: #ffc107;
                                color: #000;
                                border: none;
                                border-radius: 8px;
                                font-weight: 600;
                                cursor: pointer;
                            ">➕ Tratamento</button>
                            
                            <button onclick="this.closest('.modal-visualizacao').remove(); concluirPublicacao(${pub.id});" style="
                                padding: 10px 20px;
                                background: #28a745;
                                color: white;
                                border: none;
                                border-radius: 8px;
                                font-weight: 600;
                                cursor: pointer;
                            ">✅ Concluir</button>
                            ` : ''}
                        </div>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // Fechar ao clicar fora
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.remove();
                }
            });
            
        } catch (error) {
            console.error('Erro completo ao abrir popup:', error);
            mostrarNotificacao('❌ Erro: ' + error.message, 'error');
        }
    }

    // Funções auxiliares
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatarData(dataStr) {
        if (!dataStr) return '';
        const data = new Date(dataStr);
        return data.toLocaleDateString('pt-BR', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function filtrarPorStatus(status) {
        const form = document.getElementById('filterForm');
        const statusSelect = form.querySelector('[name="status"]');
        statusSelect.value = status;
        form.submit();
    }

    function filtrarPorVinculo(vinculo) {
        const form = document.getElementById('filterForm');
        const vinculoSelect = form.querySelector('[name="vinculado"]');
        vinculoSelect.value = vinculo;
        form.submit();
    }

    function limparFiltros() {
        window.location.href = 'index.php';
    }

    function copiarNumeroProcesso(event, numeroProcesso) {
        // Prevenir propagação para não abrir o modal da publicação
        event.stopPropagation();
        
        // Criar um elemento temporário para copiar
        const tempInput = document.createElement('input');
        tempInput.value = numeroProcesso;
        document.body.appendChild(tempInput);
        tempInput.select();
        tempInput.setSelectionRange(0, 99999); // Para mobile
        
        try {
            document.execCommand('copy');
            document.body.removeChild(tempInput);
            
            // Feedback visual
            mostrarNotificacao('📋 Número do processo copiado: ' + numeroProcesso, 'success');
            
            // Adicionar classe de feedback no elemento clicado
            const elemento = event.currentTarget;
            elemento.style.background = '#28a745';
            elemento.style.color = 'white';
            setTimeout(() => {
                elemento.style.background = '';
                elemento.style.color = '';
            }, 300);
            
        } catch (err) {
            document.body.removeChild(tempInput);
            mostrarNotificacao('❌ Erro ao copiar número do processo', 'error');
        }
    }

    function abrirModalTratamento(publicacaoId) {
        publicacaoSelecionadaId = publicacaoId;
        
        document.getElementById('iframe-tratamento').src = 'tratar.php?id=' + publicacaoId; // ← ADICIONAR
        
        document.getElementById('modalTratamento').style.display = 'block';
        document.body.style.overflow = 'hidden';
    }

    function fecharModalTratamento() {
        document.getElementById('modalTratamento').style.display = 'none';
        document.body.style.overflow = 'auto';
        publicacaoSelecionadaId = null;
        
        document.getElementById('iframe-tratamento').src = ''; // ← ADICIONAR
    }
    
    // ========================================
    // NOVA FUNÇÃO: Abrir tratar.php em popup
    // ========================================
    function abrirPopupTratamento(publicacaoId) {
        const width = 650;
        const height = 750;
        const left = (screen.width - width) / 2;
        const top = (screen.height - height) / 2;
        
        const popup = window.open(
            'tratar.php?id=' + publicacaoId,
            'tratamento_' + publicacaoId,
            'width=' + width + ',height=' + height + ',left=' + left + ',top=' + top + ',resizable=yes,scrollbars=yes'
        );
        
        if (popup) {
            popup.focus();
        } else {
            alert('⚠️ Popup bloqueado! Permita popups para este site.');
        }
    }

    async function concluirPublicacao(id) {
        if (!confirm('Tem certeza que deseja marcar esta publicação como concluída?\n\nIsso indica que não é necessário tomar nenhuma ação.')) {
            return;
        }

        try {
            const response = await fetch('api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'concluir',
                    publicacao_id: id
                })
            });

            const result = await response.json();

            if (result.success) {
                mostrarNotificacao('✅ Publicação marcada como concluída!', 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                mostrarNotificacao('❌ Erro: ' + (result.message || 'Erro desconhecido'), 'error');
            }
        } catch (error) {
            console.error('Erro:', error);
            mostrarNotificacao('❌ Erro ao concluir publicação', 'error');
        }
    }

    async function descartarPublicacao(id) {
        if (!confirm('Tem certeza que deseja descartar esta publicação?\n\nIsso é usado para publicações duplicadas ou irrelevantes.')) {
            return;
        }

        try {
            const response = await fetch('api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'descartar',
                    publicacao_id: id
                })
            });

            const result = await response.json();

            if (result.success) {
                mostrarNotificacao('✅ Publicação descartada!', 'success');
                setTimeout(() => location.reload(), 1500);
            } else {
                mostrarNotificacao('❌ Erro: ' + (result.message || 'Erro desconhecido'), 'error');
            }
        } catch (error) {
            console.error('Erro:', error);
            mostrarNotificacao('❌ Erro ao descartar publicação', 'error');
        }
    }

    async function sincronizarPublicacoes() {
        if (!confirm('Deseja sincronizar novas publicações agora?\n\nIsso pode levar alguns minutos dependendo da quantidade de publicações.')) {
            return;
        }

        mostrarNotificacao('🔄 Sincronizando publicações...', 'info');

        try {
            const response = await fetch('api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    action: 'sincronizar'
                })
            });

            const result = await response.json();

            if (result.success) {
                mostrarNotificacao('✅ ' + (result.novas || 0) + ' novas publicações sincronizadas!', 'success');
                setTimeout(() => location.reload(), 2000);
            } else {
                mostrarNotificacao('⚠️ ' + (result.message || 'Nenhuma publicação nova encontrada'), 'warning');
            }
        } catch (error) {
            console.error('Erro:', error);
            mostrarNotificacao('❌ Erro ao sincronizar publicações', 'error');
        }
    }

    function mostrarNotificacao(mensagem, tipo) {
        tipo = tipo || 'info';
        const notifAnterior = document.querySelector('.notification-toast');
        if (notifAnterior) {
            notifAnterior.remove();
        }

        const cores = {
            success: '#28a745',
            error: '#dc3545',
            warning: '#ffc107',
            info: '#17a2b8'
        };

        const notif = document.createElement('div');
        notif.className = 'notification-toast';
        notif.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: ${cores[tipo] || cores.info};
            color: white;
            padding: 15px 25px;
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
            z-index: 10000;
            font-weight: 600;
            animation: slideInRight 0.3s ease;
        `;
        notif.textContent = mensagem;
        document.body.appendChild(notif);

        setTimeout(function() {
            notif.style.animation = 'slideOutRight 0.3s ease';
            setTimeout(function() { notif.remove(); }, 300);
        }, 4000);
    }

    const styleSheet = document.createElement('style');
    styleSheet.textContent = `
        @keyframes slideInRight {
            from { transform: translateX(400px); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes slideOutRight {
            from { transform: translateX(0); opacity: 1; }
            to { transform: translateX(400px); opacity: 0; }
        }
        @keyframes slideDown {
            from { transform: translateY(-50px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
    `;
    document.head.appendChild(styleSheet);

    window.onclick = function(event) {
        const modal = document.getElementById('modalTratamento');
        if (event.target === modal) {
            fecharModalTratamento();
        }
    }
    
    // ============================================
    // RECEBER MENSAGEM DO POPUP
    // ============================================
    window.addEventListener('message', function(event) {
        // Verificar origem (segurança)
        if (event.origin !== window.location.origin) {
            return;
        }
        
         // Verificar se é mensagem de tratamento concluído
        if (event.data.type === 'publicacao_tratada' && event.data.success) {
            // Fechar modal se ainda estiver aberto
            fecharModalTratamento();
            
            // Mostrar mensagem de sucesso (você pode implementar um toast aqui)
            showSuccessToast(event.data.message);
            
            // Recarregar a página após 1 segundo
            setTimeout(function() {
                window.location.reload();
            }, 1000);
        }
    });
    
    // Click em linhas abre em nova aba
    document.querySelectorAll('.publicacao-item[data-href]').forEach(item => {
        item.addEventListener('click', function(e) {
            // Não abrir se clicar em botões dentro da linha
            if (e.target.tagName === 'BUTTON' || e.target.closest('button')) {
                return;
            }
            
            const url = this.getAttribute('data-href');
            window.open(url, '_blank', 'noopener,noreferrer');
        });
    });
</script>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Publicações', $conteudo, 'publicacoes');
?>