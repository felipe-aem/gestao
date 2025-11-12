<?php
/**
 * dashboard_revisoes.php
 * Dashboard para visualização de revisões pendentes
 */

require_once '../../includes/auth.php';
Auth::protect();
require_once '../../config/database.php';

$usuario_logado = Auth::user();
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
        
        /* Gráfico de tempo médio */
        .chart-container {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-top: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .chart-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin-bottom: 20px;
            color: #1f2937;
        }
        
        .sla-indicator {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .sla-ok { background: #d1fae5; color: #059669; }
        .sla-warning { background: #fed7aa; color: #ea580c; }
        .sla-danger { background: #fecaca; color: #dc2626; }
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
        $stats = [
            'pendentes' => 0,
            'aceitas_mes' => 0,
            'recusadas_mes' => 0,
            'atrasadas' => 0,
            'tempo_medio' => 0
        ];
        
        // Buscar estatísticas
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
                📝 Minhas Revisões
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
            <select class="filter-select" id="filtro-status">
                <option value="">Todos os Status</option>
                <option value="pendente">Pendentes</option>
                <option value="aceita">Aceitas</option>
                <option value="recusada">Recusadas</option>
            </select>
            
            <select class="filter-select" id="filtro-prioridade">
                <option value="">Todas as Prioridades</option>
                <option value="urgente">Urgente</option>
                <option value="alta">Alta</option>
                <option value="normal">Normal</option>
                <option value="baixa">Baixa</option>
            </select>
            
            <select class="filter-select" id="filtro-periodo">
                <option value="7">Últimos 7 dias</option>
                <option value="30" selected>Últimos 30 dias</option>
                <option value="90">Últimos 90 dias</option>
                <option value="todos">Todos</option>
            </select>
            
            <input type="text" class="filter-select" placeholder="Buscar..." id="filtro-busca" style="flex: 1; min-width: 200px;">
        </div>
        
        <!-- Lista de Revisões -->
        <div id="minhas-revisoes" class="tab-content">
            <div class="revisao-list">
                <?php
                // Buscar revisões pendentes onde o usuário é revisor
                $sql_revisoes = "SELECT * FROM vw_revisoes_pendentes 
                                WHERE revisor_id = ?
                                ORDER BY data_envio DESC";
                
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
                        <div class="revisao-item" data-id="<?= $rev['revisao_id'] ?>">
                            <div class="revisao-header">
                                <div>
                                    <div class="revisao-title">
                                        <?= htmlspecialchars($rev['titulo_item']) ?>
                                    </div>
                                    <?php if ($rev['numero_processo']): ?>
                                        <small>Processo: <?= htmlspecialchars($rev['numero_processo']) ?></small>
                                    <?php endif; ?>
                                </div>
                                <div class="revisao-badges">
                                    <span class="badge <?= $rev['prioridade'] ?>">
                                        <?= ucfirst($rev['prioridade']) ?>
                                    </span>
                                    <span class="badge <?= $sla_class ?>">
                                        <?= $rev['dias_aguardando'] ?> dia<?= $rev['dias_aguardando'] != 1 ? 's' : '' ?>
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
                                <button class="btn-action btn-revisar" onclick="abrirModalRevisao(<?= $rev['revisao_id'] ?>)">
                                    ✅ Revisar
                                </button>
                                <button class="btn-action btn-historico" onclick="verHistorico('<?= $rev['tipo_item'] ?>', <?= $rev['item_original_id'] ?>)">
                                    📜 Ver Histórico
                                </button>
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
                ?>
            </div>
        </div>
        
        <!-- Gráfico de Performance -->
        <div class="chart-container">
            <h3 class="chart-title">📈 Tempo de Resposta - Últimos 30 dias</h3>
            <canvas id="chartTempoResposta" height="80"></canvas>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
    
    // Abrir modal de revisão
    function abrirModalRevisao(revisaoId) {
        // Redirecionar para a página de revisão
        window.location.href = `responder_revisao.php?id=${revisaoId}`;
    }
    
    // Ver histórico completo
    function verHistorico(tipo, itemId) {
        window.location.href = `historico_fluxo.php?tipo=${tipo}&id=${itemId}`;
    }
    
    // Gráfico de tempo de resposta
    <?php
    // Buscar dados para o gráfico
    $sql_grafico = "
        SELECT 
            DATE(data_resposta) as data,
            AVG(TIMESTAMPDIFF(HOUR, data_envio, data_resposta)) as tempo_medio
        FROM fluxo_revisao
        WHERE status_revisao IN ('aceita', 'recusada')
        AND data_resposta >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY DATE(data_resposta)
        ORDER BY data";
    
    $stmt = $pdo->prepare($sql_grafico);
    $stmt->execute();
    $dados_grafico = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $labels = [];
    $valores = [];
    foreach ($dados_grafico as $dado) {
        $labels[] = date('d/m', strtotime($dado['data']));
        $valores[] = round($dado['tempo_medio'], 1);
    }
    ?>
    
    const ctx = document.getElementById('chartTempoResposta').getContext('2d');
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: <?= json_encode($labels) ?>,
            datasets: [{
                label: 'Tempo Médio (horas)',
                data: <?= json_encode($valores) ?>,
                borderColor: '#667eea',
                backgroundColor: 'rgba(102, 126, 234, 0.1)',
                tension: 0.3,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Horas'
                    }
                }
            }
        }
    });
    
    // Filtros
    document.getElementById('filtro-busca').addEventListener('input', function() {
        const busca = this.value.toLowerCase();
        document.querySelectorAll('.revisao-item').forEach(item => {
            const texto = item.textContent.toLowerCase();
            item.style.display = texto.includes(busca) ? '' : 'none';
        });
    });
    </script>
</body>
</html>