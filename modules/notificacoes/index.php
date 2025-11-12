<?php
// modules/notificacoes/index.php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';
require_once '../../includes/layout.php';

$usuario_logado = Auth::user();
$usuario_id = $usuario_logado['usuario_id'];

// Parâmetros de filtro
$filtro_tipo = $_GET['tipo'] ?? 'todas';
$filtro_status = $_GET['status'] ?? 'todas';
$pagina_atual = $_GET['pagina'] ?? 1;
$por_pagina = 20;
$offset = ($pagina_atual - 1) * $por_pagina;

// Construir WHERE clause
$where_conditions = ["usuario_id = ?"];
$params = [$usuario_id];

if ($filtro_tipo !== 'todas') {
    $where_conditions[] = "tipo = ?";
    $params[] = $filtro_tipo;
}

if ($filtro_status === 'nao_lidas') {
    $where_conditions[] = "lida = 0";
} elseif ($filtro_status === 'lidas') {
    $where_conditions[] = "lida = 1";
}

$where_clause = implode(' AND ', $where_conditions);

// Buscar notificações
$sql = "SELECT * FROM notificacoes_sistema 
        WHERE {$where_clause}
        AND (expira_em IS NULL OR expira_em > NOW())
        ORDER BY lida ASC, data_criacao DESC 
        LIMIT ? OFFSET ?";
$params[] = $por_pagina;
$params[] = $offset;

$stmt = executeQuery($sql, $params);
$notificacoes = $stmt->fetchAll();

// Contar total para paginação
$sql_count = "SELECT COUNT(*) FROM notificacoes_sistema 
              WHERE {$where_clause}
              AND (expira_em IS NULL OR expira_em > NOW())";
$stmt_count = executeQuery($sql_count, array_slice($params, 0, -2));
$total_registros = $stmt_count->fetchColumn();
$total_paginas = ceil($total_registros / $por_pagina);

// Processar ações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    
    try {
        if ($acao === 'marcar_lida') {
            $notif_id = $_POST['notif_id'] ?? 0;
            $sql = "UPDATE notificacoes_sistema 
                    SET lida = 1, data_leitura = NOW() 
                    WHERE id = ? AND usuario_id = ?";
            executeQuery($sql, [$notif_id, $usuario_id]);
            $_SESSION['success_message'] = 'Notificação marcada como lida';
            
        } elseif ($acao === 'marcar_nao_lida') {
            $notif_id = $_POST['notif_id'] ?? 0;
            $sql = "UPDATE notificacoes_sistema 
                    SET lida = 0, data_leitura = NULL 
                    WHERE id = ? AND usuario_id = ?";
            executeQuery($sql, [$notif_id, $usuario_id]);
            $_SESSION['success_message'] = 'Notificação marcada como não lida';
            
        } elseif ($acao === 'excluir') {
            $notif_id = $_POST['notif_id'] ?? 0;
            $sql = "DELETE FROM notificacoes_sistema 
                    WHERE id = ? AND usuario_id = ?";
            executeQuery($sql, [$notif_id, $usuario_id]);
            $_SESSION['success_message'] = 'Notificação excluída';
            
        } elseif ($acao === 'marcar_todas_lidas') {
            $sql = "UPDATE notificacoes_sistema 
                    SET lida = 1, data_leitura = NOW() 
                    WHERE usuario_id = ? AND lida = 0";
            executeQuery($sql, [$usuario_id]);
            $_SESSION['success_message'] = 'Todas as notificações foram marcadas como lidas';
            
        } elseif ($acao === 'limpar_lidas') {
            $sql = "DELETE FROM notificacoes_sistema 
                    WHERE usuario_id = ? AND lida = 1";
            executeQuery($sql, [$usuario_id]);
            $_SESSION['success_message'] = 'Notificações lidas foram excluídas';
        }
        
        header('Location: index.php?' . http_build_query([
            'tipo' => $filtro_tipo,
            'status' => $filtro_status,
            'pagina' => $pagina_atual
        ]));
        exit;
        
    } catch (Exception $e) {
        $erro = $e->getMessage();
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
        flex: 1;
        min-width: 200px;
    }

    .filter-group label {
        display: block;
        margin-bottom: 8px;
        color: #333;
        font-weight: 600;
        font-size: 13px;
    }

    .filter-group select {
        width: 100%;
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

    .actions-group {
        display: flex;
        gap: 10px;
        margin-top: auto;
    }

    .btn {
        padding: 10px 20px;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        font-size: 13px;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
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

    .btn-danger {
        background: #dc3545;
        color: white;
    }

    .btn-danger:hover {
        background: #c82333;
        transform: translateY(-2px);
    }

    .btn-sm {
        padding: 6px 12px;
        font-size: 12px;
    }

    .notifications-container {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        overflow: hidden;
    }

    .notification-card {
        padding: 20px 25px;
        border-bottom: 1px solid rgba(0,0,0,0.05);
        display: flex;
        gap: 15px;
        align-items: flex-start;
        transition: all 0.3s;
        cursor: pointer;
    }

    .notification-card:hover {
        background: rgba(0,0,0,0.02);
    }

    .notification-card:last-child {
        border-bottom: none;
    }

    .notification-card.unread {
        background: rgba(102, 126, 234, 0.05);
    }

    .notification-icon-large {
        width: 50px;
        height: 50px;
        min-width: 50px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
    }

    .notification-icon-large.publicacao { background: rgba(220, 53, 69, 0.1); }
    .notification-icon-large.prazo { background: rgba(255, 193, 7, 0.1); }
    .notification-icon-large.tarefa { background: rgba(102, 126, 234, 0.1); }
    .notification-icon-large.audiencia { background: rgba(40, 167, 69, 0.1); }

    .notification-body {
        flex: 1;
    }

    .notification-header-line {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 8px;
        gap: 15px;
    }

    .notification-title-large {
        font-weight: 700;
        color: #1a1a1a;
        font-size: 16px;
        margin-bottom: 4px;
    }

    .notification-message-large {
        color: #666;
        font-size: 14px;
        line-height: 1.5;
        margin-bottom: 8px;
    }

    .notification-meta {
        display: flex;
        gap: 15px;
        align-items: center;
        flex-wrap: wrap;
    }

    .notification-time-large {
        color: #999;
        font-size: 12px;
    }

    .notification-priority {
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
    }

    .priority-alta { background: rgba(220, 53, 69, 0.1); color: #dc3545; }
    .priority-normal { background: rgba(102, 126, 234, 0.1); color: #667eea; }
    .priority-baixa { background: rgba(108, 117, 125, 0.1); color: #6c757d; }

    .notification-actions {
        display: flex;
        gap: 8px;
        margin-left: auto;
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

    .empty-state h3 {
        color: #666;
        margin-bottom: 10px;
    }

    .empty-state p {
        color: #999;
        font-size: 14px;
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

    .alert {
        padding: 15px 20px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-weight: 600;
    }

    .alert-success {
        background: rgba(40, 167, 69, 0.1);
        border: 1px solid rgba(40, 167, 69, 0.3);
        color: #155724;
    }

    .alert-error {
        background: rgba(220, 53, 69, 0.1);
        border: 1px solid rgba(220, 53, 69, 0.3);
        color: #721c24;
    }

    @media (max-width: 768px) {
        .filters-row {
            flex-direction: column;
        }

        .filter-group {
            width: 100%;
        }

        .actions-group {
            width: 100%;
            flex-direction: column;
        }

        .btn {
            width: 100%;
            justify-content: center;
        }

        .notification-card {
            flex-direction: column;
        }

        .notification-actions {
            width: 100%;
            margin-left: 0;
        }
    }
</style>

<div class="page-header">
    <h2>
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
        </svg>
        Notificações
    </h2>
</div>

<?php if (isset($_SESSION['success_message'])): ?>
<div class="alert alert-success">
    ✅ <?= htmlspecialchars($_SESSION['success_message']) ?>
</div>
<?php unset($_SESSION['success_message']); endif; ?>

<?php if (isset($erro)): ?>
<div class="alert alert-error">
    ❌ <?= htmlspecialchars($erro) ?>
</div>
<?php endif; ?>

<!-- Filtros -->
<div class="filters-section">
    <form method="GET" class="filters-row">
        <div class="filter-group">
            <label>Tipo</label>
            <select name="tipo" onchange="this.form.submit()">
                <option value="todas" <?= $filtro_tipo === 'todas' ? 'selected' : '' ?>>Todas</option>
                <option value="publicacao_nova" <?= $filtro_tipo === 'publicacao_nova' ? 'selected' : '' ?>>Publicações</option>
                <option value="prazo_vencendo" <?= $filtro_tipo === 'prazo_vencendo' ? 'selected' : '' ?>>Prazos</option>
                <option value="tarefa_atribuida" <?= $filtro_tipo === 'tarefa_atribuida' ? 'selected' : '' ?>>Tarefas</option>
                <option value="audiencia_proxima" <?= $filtro_tipo === 'audiencia_proxima' ? 'selected' : '' ?>>Audiências</option>
            </select>
        </div>

        <div class="filter-group">
            <label>Status</label>
            <select name="status" onchange="this.form.submit()">
                <option value="todas" <?= $filtro_status === 'todas' ? 'selected' : '' ?>>Todas</option>
                <option value="nao_lidas" <?= $filtro_status === 'nao_lidas' ? 'selected' : '' ?>>Não Lidas</option>
                <option value="lidas" <?= $filtro_status === 'lidas' ? 'selected' : '' ?>>Lidas</option>
            </select>
        </div>

        <div class="actions-group">
            <form method="POST" style="display: inline;">
                <input type="hidden" name="acao" value="marcar_todas_lidas">
                <button type="submit" class="btn btn-secondary btn-sm">
                    ✓ Marcar todas como lidas
                </button>
            </form>

            <form method="POST" style="display: inline;" onsubmit="return confirm('Tem certeza que deseja excluir todas as notificações lidas?')">
                <input type="hidden" name="acao" value="limpar_lidas">
                <button type="submit" class="btn btn-danger btn-sm">
                    🗑️ Limpar lidas
                </button>
            </form>
        </div>
    </form>
</div>

<!-- Lista de Notificações -->
<div class="notifications-container">
    <?php if (empty($notificacoes)): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path>
                <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
            </svg>
            <h3>Nenhuma notificação encontrada</h3>
            <p>Você não possui notificações com os filtros selecionados</p>
        </div>
    <?php else: ?>
        <?php foreach ($notificacoes as $notif): 
            $icone = match($notif['tipo']) {
                'publicacao_nova', 'publicacao_vinculada' => '📄',
                'prazo_vencendo', 'prazo_vencido' => '⏰',
                'tarefa_atribuida', 'tarefa_vencendo' => '✓',
                'audiencia_proxima' => '📅',
                'processo_atualizado', 'processo_criado' => '📁',
                default => '🔔'
            };
            
            $tipo_class = match(true) {
                str_contains($notif['tipo'], 'publicacao') => 'publicacao',
                str_contains($notif['tipo'], 'prazo') => 'prazo',
                str_contains($notif['tipo'], 'tarefa') => 'tarefa',
                str_contains($notif['tipo'], 'audiencia') => 'audiencia',
                default => 'publicacao'
            };
            
            $data = new DateTime($notif['data_criacao']);
            $agora = new DateTime();
            $diff = $agora->getTimestamp() - $data->getTimestamp();
            
            if ($diff < 3600) {
                $tempo = floor($diff / 60) . ' minutos atrás';
            } elseif ($diff < 86400) {
                $tempo = floor($diff / 3600) . ' horas atrás';
            } else {
                $tempo = $data->format('d/m/Y H:i');
            }
        ?>
        <div class="notification-card <?= $notif['lida'] ? '' : 'unread' ?>" 
             onclick="<?= $notif['link'] ? "window.location.href='" . htmlspecialchars($notif['link']) . "'" : '' ?>">
            <div class="notification-icon-large <?= $tipo_class ?>">
                <?= $icone ?>
            </div>
            
            <div class="notification-body">
                <div class="notification-header-line">
                    <div>
                        <div class="notification-title-large">
                            <?= htmlspecialchars($notif['titulo']) ?>
                        </div>
                        <div class="notification-message-large">
                            <?= htmlspecialchars($notif['mensagem']) ?>
                        </div>
                    </div>
                </div>
                
                <div class="notification-meta">
                    <span class="notification-time-large">📅 <?= $tempo ?></span>
                    <span class="notification-priority priority-<?= $notif['prioridade'] ?>">
                        <?= strtoupper($notif['prioridade']) ?>
                    </span>
                    <?php if (!$notif['lida']): ?>
                        <span class="notification-priority priority-alta">NÃO LIDA</span>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="notification-actions" onclick="event.stopPropagation()">
                <?php if (!$notif['lida']): ?>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="acao" value="marcar_lida">
                        <input type="hidden" name="notif_id" value="<?= $notif['id'] ?>">
                        <button type="submit" class="btn btn-secondary btn-sm" title="Marcar como lida">
                            ✓
                        </button>
                    </form>
                <?php else: ?>
                    <form method="POST" style="display: inline;">
                        <input type="hidden" name="acao" value="marcar_nao_lida">
                        <input type="hidden" name="notif_id" value="<?= $notif['id'] ?>">
                        <button type="submit" class="btn btn-secondary btn-sm" title="Marcar como não lida">
                            ↩️
                        </button>
                    </form>
                <?php endif; ?>
                
                <form method="POST" style="display: inline;" onsubmit="return confirm('Excluir esta notificação?')">
                    <input type="hidden" name="acao" value="excluir">
                    <input type="hidden" name="notif_id" value="<?= $notif['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm" title="Excluir">
                        🗑️
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
        
        <!-- Paginação -->
        <?php if ($total_paginas > 1): ?>
        <div class="pagination">
            <?php if ($pagina_atual > 1): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['pagina' => $pagina_atual - 1])) ?>">« Anterior</a>
            <?php endif; ?>
            
            <?php for ($i = 1; $i <= $total_paginas; $i++): ?>
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
echo renderLayout('Notificações', $conteudo, 'notificacoes');
?>