<?php
/**
 * dashboard_revisoes.php - VERSÃO SIMPLIFICADA
 * Dashboard para visualização de revisões pendentes
 * SEM notificações em tempo real
 */

require_once '../../includes/auth.php';
Auth::protect();
require_once '../../config/database.php';

$usuario_logado = Auth::user();
$pdo = getConnection();
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard de Revisões</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <style>
        .dashboard-container {
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-left: 4px solid;
        }
        
        .stat-card.pendentes { border-color: #fbbf24; }
        .stat-card.aceitas { border-color: #34d399; }
        .stat-card.recusadas { border-color: #f87171; }
        .stat-card.atrasadas { border-color: #a78bfa; }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .stat-label {
            color: #6b7280;
            font-size: 0.9rem;
            text-transform: uppercase;
        }
        
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .tab {
            padding: 12px 24px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 1rem;
            color: #6b7280;
            transition: all 0.3s;
            position: relative;
        }
        
        .tab.active {
            color: #667eea;
        }
        
        .tab.active::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            right: 0;
            height: 2px;
            background: #667eea;
        }
        
        .revisao-list {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .revisao-item {
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
            transition: background 0.2s;
        }
        
        .revisao-item:hover {
            background: #f9fafb;
        }
        
        .revisao-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 10px;
        }
        
        .revisao-title {
            font-weight: 600;
            color: #1f2937;
            font-size: 1.1rem;
        }
        
        .revisao-badges {
            display: flex;
            gap: 8px;
        }
        
        .badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        
        .badge.urgente { background: #fee2e2; color: #dc2626; }
        .badge.alta { background: #fef3c7; color: #d97706; }
        .badge.normal { background: #dbeafe; color: #2563eb; }
        .badge.baixa { background: #e0e7ff; color: #4f46e5; }
        
        .badge.prazo-ok { background: #d1fae5; color: #059669; }
        .badge.prazo-atencao { background: #fed7aa; color: #ea580c; }
        .badge.prazo-atrasado { background: #fecaca; color: #dc2626; }
        
        .revisao-meta {
            display: flex;
            gap: 20px;
            color: #6b7280;
            font-size: 0.9rem;
            margin: 10px 0;
        }
        
        .revisao-meta-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .revisao-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        
        .btn-action {
            padding: 8px 16px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 0.9rem;
            font-weight: 500;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-revisar {
            background: #667eea;
            color: white;
        }
        
        .btn-revisar:hover {
            background: #5a67d8;
        }
        
        .btn-historico {
            background: #f3f4f6;
            color: #4b5563;
        }
        
        .btn-historico:hover {
            background: #e5e7eb;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #9ca3af;
        }
        
        .empty-state-icon {
            font-size: 4rem;
            margin-bottom: 20px;
        }
        
        .filter-bar {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .filter-select {
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: white;
            min-width: 150px;
        }
        
        .refresh-btn {
            padding: 8px 16px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            margin-left: auto;
        }
        
        .refresh-btn:hover {
            background: #5a67d8;
        }
    </style>
</head>
<body>
    <?php include '../../includes/layout.php'; ?>
    
    <div class="dashboard-container">
        <div class="page-header">
            <h1>📊 Dashboard de Revisões</h1>
            <p>Acompanhe todas as revisões pendentes e concluídas</p>
        </div>
        
        <?php
        // Estatísticas
        $sql_stats = "
            SELECT 
                COUNT(CASE WHEN status_revisao = 'pendente' THEN 1 END) as pendentes,
                COUNT(CASE WHEN status_revisao = 'aceita' AND DATE(data_resposta) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as aceitas_mes,
                COUNT(CASE WHEN status_revisao = 'recusada' AND DATE(data_resposta) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 END) as recusadas_mes,
                COUNT(CASE WHEN status_revisao = 'pendente' AND DATEDIFF(NOW(), data_envio) > 2 THEN 1 END) as atrasadas,
                AVG(CASE WHEN status_revisao IN ('aceita', 'recusada') THEN TIMESTAMPDIFF(HOUR, data_envio, data_resposta) END) as tempo_medio
            FROM fluxo_revisao
            WHERE revisor_id = ? OR solicitante_id = ?";
        
        $stmt = $pdo->prepare($sql_stats);
        $stmt->execute([$usuario_logado['usuario_id'], $usuario_logado['usuario_id']]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        ?>
        
        <!-- Cards de Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card pendentes">
                <div class="stat-label">📋 Pendentes</div>
                <div class="stat-number"><?= intval($stats['pendentes']) ?></div>
                <small>Aguardando revisão</small>
            </div>
            
            <div class="stat-card aceitas">
                <div class="stat-label">✅ Aceitas (30 dias)</div>
                <div class="stat-number"><?= intval($stats['aceitas_mes']) ?></div>
                <small>Revisões aprovadas</small>
            </div>
            
            <div class="stat-card recusadas">
                <div class="stat-label">❌ Recusadas (30 dias)</div>
                <div class="stat-number"><?= intval($stats['recusadas_mes']) ?></div>
                <small>Necessitam correção</small>
            </div>
            
            <div class="stat-card atrasadas">
                <div class="stat-label">⏰ Tempo Médio</div>
                <div class="stat-number"><?= round($stats['tempo_medio'] ?? 0) ?>h</div>
                <small>Tempo de resposta</small>
            </div>
        </div>
        
        <!-- Tabs -->
        <div class="tabs">
            <button class="tab active" onclick="mostrarTab('minhas-revisoes')">
                📝 Minhas Revisões (<?= intval($stats['pendentes']) ?>)
            </button>
            <button class="tab" onclick="mostrarTab('enviadas')">
                📤 Enviadas por Mim
            </button>
            <button class="tab" onclick="mostrarTab('historico')">
                📜 Histórico Completo
            </button>
        </div>
        
        <!-- Filtros -->
        <div class="filter-bar">
            <select class="filter-select" id="filtro-prioridade">
                <option value="">Todas as Prioridades</option>
                <option value="urgente">Urgente</option>
                <option value="alta">Alta</option>
                <option value="normal">Normal</option>
                <option value="baixa">Baixa</option>
            </select>
            
            <input type="text" class="filter-select" placeholder="Buscar..." id="filtro-busca" style="flex: 1; min-width: 200px;">
            
            <button class="refresh-btn" onclick="location.reload()">
                🔄 Atualizar
            </button>
        </div>
        
        <!-- Lista de Revisões Pendentes -->
        <div id="minhas-revisoes" class="tab-content">
            <div class="revisao-list">
                <?php
                // Buscar revisões pendentes onde o usuário é revisor
                $sql_revisoes = "SELECT * FROM vw_revisoes_pendentes 
                                WHERE revisor_id = ?
                                ORDER BY 
                                    CASE prioridade 
                                        WHEN 'urgente' THEN 1 
                                        WHEN 'alta' THEN 2 
                                        WHEN 'normal' THEN 3 
                                        ELSE 4 
                                    END,
                                    data_envio DESC";
                
                $stmt = $pdo->prepare($sql_revisoes);
                $stmt->execute([$usuario_logado['usuario_id']]);
                $revisoes = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($revisoes)) {
                    ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">🎉</div>
                        <h3>Nenhuma revisão pendente!</h3>
                        <p>Você está com todas as revisões em dia.</p>
                    </div>
                    <?php
                } else {
                    foreach ($revisoes as $rev) {
                        $sla_class = 'prazo-ok';
                        if ($rev['dias_aguardando'] > 2) $sla_class = 'prazo-atrasado';
                        elseif ($rev['dias_aguardando'] > 1) $sla_class = 'prazo-atencao';
                        ?>
                        <div class="revisao-item" data-prioridade="<?= $rev['prioridade'] ?>">
                            <div class="revisao-header">
                                <div>
                                    <div class="revisao-title">
                                        <?= htmlspecialchars($rev['titulo_item']) ?>
                                    </div>
                                    <?php if ($rev['numero_processo']): ?>
                                        <small>Processo: <?= htmlspecialchars($rev['numero_processo']) ?> - <?= htmlspecialchars($rev['cliente_nome']) ?></small>
                                    <?php endif; ?>
                                </div>
                                <div class="revisao-badges">
                                    <span class="badge <?= $rev['prioridade'] ?>">
                                        <?= ucfirst($rev['prioridade']) ?>
                                    </span>
                                    <span class="badge <?= $sla_class ?>">
                                        <?= $rev['dias_aguardando'] ?> dia<?= $rev['dias_aguardando'] != 1 ? 's' : '' ?>
                                    </span>
                                    <span class="badge" style="background: #f3f4f6;">
                                        Ciclo <?= $rev['ciclo_numero'] ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="revisao-meta">
                                <div class="revisao-meta-item">
                                    👤 Solicitante: <?= htmlspecialchars($rev['solicitante_nome']) ?>
                                </div>
                                <div class="revisao-meta-item">
                                    📅 Enviado: <?= date('d/m/Y H:i', strtotime($rev['data_envio'])) ?>
                                </div>
                                <div class="revisao-meta-item">
                                    ⏰ Vencimento: <?= date('d/m/Y', strtotime($rev['data_vencimento'])) ?>
                                </div>
                            </div>
                            
                            <?php if ($rev['comentario_solicitante']): ?>
                                <div style="margin: 10px 0; padding: 10px; background: #f9fafb; border-radius: 8px;">
                                    <strong>Comentário:</strong><br>
                                    <?= nl2br(htmlspecialchars($rev['comentario_solicitante'])) ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="revisao-actions">
                                <a href="responder_revisao.php?id=<?= $rev['revisao_id'] ?>" class="btn-action btn-revisar">
                                    ✅ Revisar
                                </a>
                                <a href="historico_fluxo.php?tipo=<?= $rev['tipo_item'] ?>&id=<?= $rev['item_original_id'] ?>" class="btn-action btn-historico">
                                    📜 Ver Histórico
                                </a>
                            </div>
                        </div>
                        <?php
                    }
                }
                ?>
            </div>
        </div>
        
        <!-- Tab: Enviadas por Mim -->
        <div id="enviadas" class="tab-content" style="display: none;">
            <div class="revisao-list">
                <?php
                // Buscar revisões enviadas pelo usuário
                $sql_enviadas = "
                    SELECT fr.*, 
                           CASE WHEN fr.tipo_item = 'tarefa' THEN t.titulo ELSE p.titulo END as titulo_item,
                           ur.nome as revisor_nome,
                           DATEDIFF(NOW(), fr.data_envio) as dias_aguardando
                    FROM fluxo_revisao fr
                    LEFT JOIN tarefas t ON fr.tipo_item = 'tarefa' AND fr.item_atual_id = t.id
                    LEFT JOIN prazos p ON fr.tipo_item = 'prazo' AND fr.item_atual_id = p.id
                    JOIN usuarios ur ON fr.revisor_id = ur.id
                    WHERE fr.solicitante_id = ?
                    ORDER BY fr.data_envio DESC
                    LIMIT 20";
                
                $stmt = $pdo->prepare($sql_enviadas);
                $stmt->execute([$usuario_logado['usuario_id']]);
                $enviadas = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (empty($enviadas)) {
                    ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📭</div>
                        <h3>Nenhuma revisão enviada</h3>
                        <p>Você ainda não enviou nenhuma tarefa/prazo para revisão.</p>
                    </div>
                    <?php
                } else {
                    foreach ($enviadas as $env) {
                        $status_badge = '';
                        if ($env['status_revisao'] == 'pendente') $status_badge = 'prazo-atencao';
                        elseif ($env['status_revisao'] == 'aceita') $status_badge = 'prazo-ok';
                        else $status_badge = 'prazo-atrasado';
                        ?>
                        <div class="revisao-item">
                            <div class="revisao-header">
                                <div>
                                    <div class="revisao-title">
                                        <?= htmlspecialchars($env['titulo_item']) ?>
                                    </div>
                                    <small>Revisor: <?= htmlspecialchars($env['revisor_nome']) ?></small>
                                </div>
                                <div class="revisao-badges">
                                    <span class="badge <?= $status_badge ?>">
                                        <?= ucfirst($env['status_revisao']) ?>
                                    </span>
                                </div>
                            </div>
                            
                            <div class="revisao-meta">
                                <div class="revisao-meta-item">
                                    📅 Enviado: <?= date('d/m/Y H:i', strtotime($env['data_envio'])) ?>
                                </div>
                                <?php if ($env['data_resposta']): ?>
                                    <div class="revisao-meta-item">
                                        ✅ Respondido: <?= date('d/m/Y H:i', strtotime($env['data_resposta'])) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php
                    }
                }
                ?>
            </div>
        </div>
        
        <!-- Tab: Histórico -->
        <div id="historico" class="tab-content" style="display: none;">
            <div class="revisao-list">
                <?php
                // Buscar histórico completo
                $sql_historico = "
                    SELECT fr.*, 
                           CASE WHEN fr.tipo_item = 'tarefa' THEN t.titulo ELSE p.titulo END as titulo_item,
                           us.nome as solicitante_nome,
                           ur.nome as revisor_nome
                    FROM fluxo_revisao fr
                    LEFT JOIN tarefas t ON fr.tipo_item = 'tarefa' AND fr.item_atual_id = t.id
                    LEFT JOIN prazos p ON fr.tipo_item = 'prazo' AND fr.item_atual_id = p.id
                    JOIN usuarios us ON fr.solicitante_id = us.id
                    JOIN usuarios ur ON fr.revisor_id = ur.id
                    WHERE fr.solicitante_id = ? OR fr.revisor_id = ?
                    ORDER BY fr.created_at DESC
                    LIMIT 50";
                
                $stmt = $pdo->prepare($sql_historico);
                $stmt->execute([$usuario_logado['usuario_id'], $usuario_logado['usuario_id']]);
                $historico = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($historico as $hist) {
                    $status_color = '';
                    if ($hist['status_revisao'] == 'aceita') $status_color = 'prazo-ok';
                    elseif ($hist['status_revisao'] == 'recusada') $status_color = 'prazo-atrasado';
                    else $status_color = 'prazo-atencao';
                    ?>
                    <div class="revisao-item">
                        <div class="revisao-header">
                            <div>
                                <div class="revisao-title">
                                    <?= htmlspecialchars($hist['titulo_item']) ?>
                                </div>
                                <small>
                                    Solicitante: <?= htmlspecialchars($hist['solicitante_nome']) ?> | 
                                    Revisor: <?= htmlspecialchars($hist['revisor_nome']) ?>
                                </small>
                            </div>
                            <div class="revisao-badges">
                                <span class="badge <?= $status_color ?>">
                                    <?= ucfirst($hist['status_revisao']) ?>
                                </span>
                            </div>
                        </div>
                        
                        <div class="revisao-meta">
                            <div class="revisao-meta-item">
                                📅 Enviado: <?= date('d/m/Y H:i', strtotime($hist['data_envio'])) ?>
                            </div>
                            <?php if ($hist['data_resposta']): ?>
                                <div class="revisao-meta-item">
                                    ✅ Respondido: <?= date('d/m/Y H:i', strtotime($hist['data_resposta'])) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php
                }
                ?>
            </div>
        </div>
    </div>
    
    <script>
    // Funções de Tab
    function mostrarTab(tabName) {
        // Esconder todas as tabs
        document.querySelectorAll('.tab-content').forEach(content => {
            content.style.display = 'none';
        });
        
        // Remover classe active de todas as tabs
        document.querySelectorAll('.tab').forEach(tab => {
            tab.classList.remove('active');
        });
        
        // Mostrar tab selecionada
        document.getElementById(tabName).style.display = 'block';
        
        // Adicionar classe active na tab clicada
        event.target.classList.add('active');
    }
    
    // Filtros
    document.getElementById('filtro-busca').addEventListener('input', function() {
        const busca = this.value.toLowerCase();
        document.querySelectorAll('.revisao-item').forEach(item => {
            const texto = item.textContent.toLowerCase();
            item.style.display = texto.includes(busca) ? '' : 'none';
        });
    });
    
    document.getElementById('filtro-prioridade').addEventListener('change', function() {
        const prioridade = this.value;
        document.querySelectorAll('.revisao-item').forEach(item => {
            if (!prioridade || item.dataset.prioridade === prioridade) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
            }
        });
    });
    </script>
</body>
</html>