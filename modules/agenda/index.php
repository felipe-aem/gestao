<?php
/**
 * Módulo Agenda Unificada - Página Principal
 * Exibe lista de tarefas, prazos, eventos e audiências
 */

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

// Parâmetros de filtro
$filtro = $_GET['filtro'] ?? $_GET['tipo'] ?? 'todos'; // todos, tarefas, prazos, eventos, audiencias
$status = $_GET['status'] ?? '';
$prioridade = $_GET['prioridade'] ?? '';
$responsavel = $_GET['responsavel'] ?? '';
$view = $_GET['view'] ?? 'list'; // list ou calendar

// Se view=calendar, redirecionar para calendario.php
if ($view === 'calendar') {
    $query = http_build_query([
        'tipo' => $filtro !== 'todos' ? $filtro : '',
        'status' => $status,
        'prioridade' => $prioridade
    ]);
    header('Location: calendario.php?' . $query);
    exit;
}

// Buscar itens
$items = [];
$pdo = getConnection();

try {
    // Base query parameters
    $where_conditions = [];
    $params = [];

    // Status filter
    if ($status) {
        $where_status = $status;
    }

    // Responsavel filter
    if ($responsavel) {
        $where_responsavel = "responsavel_id = ?";
        $params_responsavel = [$responsavel];
    }

    // Prioridade filter
    if ($prioridade) {
        $where_prioridade = "prioridade = ?";
        $params_prioridade = [$prioridade];
    }

    // BUSCAR TAREFAS
    if (in_array($filtro, ['todos', 'tarefas', 'tarefa'])) {
        $where_tarefa = ["t.deleted_at IS NULL"];
        $params_tarefa = [];

        if ($status) {
            $where_tarefa[] = "t.status = ?";
            $params_tarefa[] = $status;
        }
        if ($responsavel) {
            $where_tarefa[] = "t.responsavel_id = ?";
            $params_tarefa[] = $responsavel;
        }
        if ($prioridade) {
            $where_tarefa[] = "t.prioridade = ?";
            $params_tarefa[] = $prioridade;
        }

        $where_clause_tarefa = implode(' AND ', $where_tarefa);

        $sql = "SELECT
                'tarefa' as tipo_item,
                t.id,
                t.titulo,
                t.data_vencimento as data_item,
                t.status,
                t.prioridade,
                t.responsavel_id,
                u.nome as responsavel_nome,
                t.processo_id,
                pr.numero_processo,
                t.tipo_fluxo
                FROM tarefas t
                LEFT JOIN usuarios u ON t.responsavel_id = u.id
                LEFT JOIN processos pr ON t.processo_id = pr.id
                WHERE $where_clause_tarefa
                ORDER BY t.data_vencimento ASC, t.id DESC
                LIMIT 100";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params_tarefa);
        $items = array_merge($items, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // BUSCAR PRAZOS
    if (in_array($filtro, ['todos', 'prazos', 'prazo'])) {
        $where_prazo = ["p.deleted_at IS NULL"];
        $params_prazo = [];

        if ($status) {
            $where_prazo[] = "p.status = ?";
            $params_prazo[] = $status;
        }
        if ($responsavel) {
            $where_prazo[] = "p.responsavel_id = ?";
            $params_prazo[] = $responsavel;
        }
        if ($prioridade) {
            $where_prazo[] = "p.prioridade = ?";
            $params_prazo[] = $prioridade;
        }

        $where_clause_prazo = implode(' AND ', $where_prazo);

        $sql = "SELECT
                'prazo' as tipo_item,
                p.id,
                p.titulo,
                p.data_vencimento as data_item,
                p.status,
                p.prioridade,
                p.responsavel_id,
                u.nome as responsavel_nome,
                p.processo_id,
                pr.numero_processo,
                p.tipo_fluxo
                FROM prazos p
                LEFT JOIN usuarios u ON p.responsavel_id = u.id
                LEFT JOIN processos pr ON p.processo_id = pr.id
                WHERE $where_clause_prazo
                ORDER BY p.data_vencimento ASC, p.id DESC
                LIMIT 100";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params_prazo);
        $items = array_merge($items, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // BUSCAR EVENTOS (AGENDA)
    if (in_array($filtro, ['todos', 'eventos', 'evento'])) {
        $where_evento = ["a.deleted_at IS NULL"];
        $params_evento = [];

        if ($status) {
            $where_evento[] = "a.status = ?";
            $params_evento[] = $status;
        }
        if ($prioridade) {
            $where_evento[] = "a.prioridade = ?";
            $params_evento[] = $prioridade;
        }

        $where_clause_evento = implode(' AND ', $where_evento);

        $sql = "SELECT
                'evento' as tipo_item,
                a.id,
                a.titulo,
                a.data_inicio as data_item,
                a.status,
                a.prioridade,
                a.usuario_id as responsavel_id,
                u.nome as responsavel_nome,
                NULL as processo_id,
                NULL as numero_processo,
                NULL as tipo_fluxo
                FROM agenda a
                LEFT JOIN usuarios u ON a.usuario_id = u.id
                WHERE $where_clause_evento
                ORDER BY a.data_inicio DESC
                LIMIT 100";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params_evento);
        $items = array_merge($items, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // BUSCAR AUDIÊNCIAS
    if (in_array($filtro, ['todos', 'audiencias', 'audiencia'])) {
        $where_audiencia = ["aud.deleted_at IS NULL"];
        $params_audiencia = [];

        if ($status) {
            $where_audiencia[] = "aud.status = ?";
            $params_audiencia[] = $status;
        }
        if ($responsavel) {
            $where_audiencia[] = "aud.responsavel_id = ?";
            $params_audiencia[] = $responsavel;
        }

        $where_clause_audiencia = implode(' AND ', $where_audiencia);

        $sql = "SELECT
                'audiencia' as tipo_item,
                aud.id,
                aud.titulo,
                aud.data_audiencia as data_item,
                aud.status,
                'normal' as prioridade,
                aud.responsavel_id,
                u.nome as responsavel_nome,
                aud.processo_id,
                pr.numero_processo,
                NULL as tipo_fluxo
                FROM audiencias aud
                LEFT JOIN usuarios u ON aud.responsavel_id = u.id
                LEFT JOIN processos pr ON aud.processo_id = pr.id
                WHERE $where_clause_audiencia
                ORDER BY aud.data_audiencia DESC
                LIMIT 100";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params_audiencia);
        $items = array_merge($items, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    // Ordenar todos os items por data
    usort($items, function($a, $b) {
        return strtotime($b['data_item']) - strtotime($a['data_item']);
    });

} catch (Exception $e) {
    error_log("Erro ao buscar itens da agenda: " . $e->getMessage());
}

// Estatísticas
$stats = [
    'total' => count($items),
    'tarefas' => count(array_filter($items, fn($i) => $i['tipo_item'] === 'tarefa')),
    'prazos' => count(array_filter($items, fn($i) => $i['tipo_item'] === 'prazo')),
    'eventos' => count(array_filter($items, fn($i) => $i['tipo_item'] === 'evento')),
    'audiencias' => count(array_filter($items, fn($i) => $i['tipo_item'] === 'audiencia'))
];

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
        grid-template-columns: repeat(5, 1fr);
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
    .stat-mini:nth-child(2) { border-left-color: #6f42c1; }
    .stat-mini:nth-child(3) { border-left-color: #ffc107; }
    .stat-mini:nth-child(4) { border-left-color: #17a2b8; }
    .stat-mini:nth-child(5) { border-left-color: #28a745; }

    .stat-mini-number {
        font-size: 36px;
        font-weight: 800;
        line-height: 1;
        display: block;
        margin-bottom: 10px;
    }

    .stat-mini:nth-child(1) .stat-mini-number { color: #667eea; }
    .stat-mini:nth-child(2) .stat-mini-number { color: #6f42c1; }
    .stat-mini:nth-child(3) .stat-mini-number { color: #ffc107; }
    .stat-mini:nth-child(4) .stat-mini-number { color: #17a2b8; }
    .stat-mini:nth-child(5) .stat-mini-number { color: #28a745; }

    .stat-mini-label {
        font-size: 13px;
        color: #666;
        text-transform: uppercase;
        letter-spacing: 1px;
        font-weight: 700;
        display: block;
    }

    .items-list {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        overflow: hidden;
    }

    .item-row {
        padding: 20px 25px;
        border-bottom: 1px solid rgba(0,0,0,0.05);
        display: flex;
        align-items: center;
        gap: 20px;
        transition: all 0.3s;
        cursor: pointer;
    }

    .item-row:hover {
        background: rgba(102, 126, 234, 0.05);
    }

    .item-type {
        width: 80px;
        text-align: center;
        font-weight: 700;
        font-size: 12px;
        padding: 8px;
        border-radius: 8px;
    }

    .type-tarefa { background: rgba(111, 66, 193, 0.1); color: #6f42c1; }
    .type-prazo { background: rgba(255, 193, 7, 0.1); color: #856404; }
    .type-evento { background: rgba(23, 162, 184, 0.1); color: #0c5460; }
    .type-audiencia { background: rgba(40, 167, 69, 0.1); color: #155724; }

    .item-info {
        flex: 1;
    }

    .item-titulo {
        font-size: 16px;
        font-weight: 600;
        color: #1a1a1a;
        margin-bottom: 5px;
    }

    .item-meta {
        font-size: 13px;
        color: #666;
    }

    .item-data {
        width: 150px;
        text-align: right;
        font-size: 14px;
        color: #666;
    }

    .badge {
        display: inline-block;
        padding: 4px 12px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
    }

    .badge-pendente { background: #ffc107; color: #856404; }
    .badge-concluido { background: #28a745; color: white; }
    .badge-em_andamento { background: #17a2b8; color: white; }

    @media (max-width: 768px) {
        .stats-mini {
            grid-template-columns: repeat(2, 1fr);
        }
        .item-row {
            flex-direction: column;
            align-items: flex-start;
        }
        .item-data {
            text-align: left;
        }
    }
</style>

<div class="page-header">
    <div class="page-header-top">
        <h2>📅 Agenda Unificada</h2>
        <div class="header-actions">
            <div class="view-selector">
                <a href="index.php?view=list&filtro=<?= $filtro ?>"
                   class="view-btn active">📋 Lista</a>
                <a href="calendario.php?tipo=<?= $filtro !== 'todos' ? $filtro : '' ?>"
                   class="view-btn">📅 Calendário</a>
            </div>
            <a href="novo.php" class="btn-novo">+ Novo Evento</a>
        </div>
    </div>

    <!-- Estatísticas -->
    <div class="stats-mini">
        <div class="stat-mini" onclick="window.location.href='?filtro=todos'">
            <div class="stat-mini-number"><?= $stats['total'] ?></div>
            <div class="stat-mini-label">Total</div>
        </div>
        <div class="stat-mini" onclick="window.location.href='?filtro=tarefas'">
            <div class="stat-mini-number"><?= $stats['tarefas'] ?></div>
            <div class="stat-mini-label">Tarefas</div>
        </div>
        <div class="stat-mini" onclick="window.location.href='?filtro=prazos'">
            <div class="stat-mini-number"><?= $stats['prazos'] ?></div>
            <div class="stat-mini-label">Prazos</div>
        </div>
        <div class="stat-mini" onclick="window.location.href='?filtro=eventos'">
            <div class="stat-mini-number"><?= $stats['eventos'] ?></div>
            <div class="stat-mini-label">Eventos</div>
        </div>
        <div class="stat-mini" onclick="window.location.href='?filtro=audiencias'">
            <div class="stat-mini-number"><?= $stats['audiencias'] ?></div>
            <div class="stat-mini-label">Audiências</div>
        </div>
    </div>
</div>

<!-- Lista de Items -->
<div class="items-list">
    <?php if (empty($items)): ?>
        <div style="padding: 60px 20px; text-align: center; color: #999;">
            <svg width="80" height="80" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="opacity: 0.3; margin-bottom: 20px;">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                <line x1="16" y1="2" x2="16" y2="6"></line>
                <line x1="8" y1="2" x2="8" y2="6"></line>
                <line x1="3" y1="10" x2="21" y2="10"></line>
            </svg>
            <h3 style="color: #666; margin-bottom: 10px; font-size: 20px;">Nenhum item encontrado</h3>
            <p style="color: #999; font-size: 14px;">Não há itens para exibir com os filtros selecionados</p>
        </div>
    <?php else: ?>
        <?php foreach ($items as $item):
            $tipo_label = match($item['tipo_item']) {
                'tarefa' => 'Tarefa',
                'prazo' => 'Prazo',
                'evento' => 'Evento',
                'audiencia' => 'Audiência',
                default => 'Item'
            };

            $link = match($item['tipo_item']) {
                'tarefa' => '../tarefas/visualizar.php?id=' . $item['id'],
                'prazo' => '../prazos/visualizar.php?id=' . $item['id'],
                'evento' => 'visualizar.php?id=' . $item['id'],
                'audiencia' => '../audiencias/visualizar.php?id=' . $item['id'],
                default => '#'
            };
        ?>
        <div class="item-row" onclick="window.location.href='<?= $link ?>'">
            <div class="item-type type-<?= $item['tipo_item'] ?>">
                <?= $tipo_label ?>
            </div>
            <div class="item-info">
                <div class="item-titulo"><?= htmlspecialchars($item['titulo']) ?></div>
                <div class="item-meta">
                    <?php if ($item['responsavel_nome']): ?>
                        👤 <?= htmlspecialchars($item['responsavel_nome']) ?>
                    <?php endif; ?>
                    <?php if ($item['numero_processo']): ?>
                        &nbsp;•&nbsp; 📁 <?= htmlspecialchars($item['numero_processo']) ?>
                    <?php endif; ?>
                    <?php if ($item['status']): ?>
                        &nbsp;•&nbsp; <span class="badge badge-<?= $item['status'] ?>"><?= $item['status'] ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="item-data">
                <?php if ($item['data_item']): ?>
                    <?= date('d/m/Y H:i', strtotime($item['data_item'])) ?>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div style="margin-top: 20px; text-align: center; color: #999; font-size: 13px;">
    Exibindo <?= count($items) ?> itens
</div>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Agenda', $conteudo, 'agenda');
?>
