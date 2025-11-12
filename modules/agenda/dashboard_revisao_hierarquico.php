<?php
/**
 * dashboard_revisao_hierarquico.php
 * Dashboard para visualização de revisões pendentes usando MODELO HIERÁRQUICO
 *
 * Lista tarefas/prazos onde:
 * - tipo_fluxo = 'revisao'
 * - responsavel_id = usuário logado
 * - status = 'pendente'
 */

require_once '../../includes/auth.php';
Auth::protect();
require_once '../../config/database.php';
require_once '../../includes/RevisaoHelperHierarquico.php';

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
        .stat-card.correcoes { border-color: #f87171; }
        .stat-card.protocolos { border-color: #34d399; }
        .stat-card.concluidas { border-color: #a78bfa; }

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

        .badge.tipo-revisao { background: #fef3c7; color: #d97706; }
        .badge.tipo-correcao { background: #fee2e2; color: #dc2626; }
        .badge.tipo-protocolo { background: #d1fae5; color: #059669; }

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
            <h1>📊 Dashboard de Revisões (Modelo Hierárquico)</h1>
            <p>Acompanhe todas as revisões, correções e protocolos pendentes</p>
        </div>

        <?php
        // Estatísticas
        $sql_stats_revisoes = "
            SELECT COUNT(*) as total
            FROM tarefas
            WHERE responsavel_id = ?
            AND tipo_fluxo = 'revisao'
            AND status = 'pendente'
            AND deleted_at IS NULL
            UNION ALL
            SELECT COUNT(*) as total
            FROM prazos
            WHERE responsavel_id = ?
            AND tipo_fluxo = 'revisao'
            AND status = 'pendente'
            AND deleted_at IS NULL";

        $stmt = $pdo->prepare($sql_stats_revisoes);
        $stmt->execute([$usuario_logado['usuario_id'], $usuario_logado['usuario_id']]);
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $revisoes_pendentes = array_sum(array_column($resultados, 'total'));

        // Contar correções
        $sql_stats_correcoes = "
            SELECT COUNT(*) as total
            FROM tarefas
            WHERE responsavel_id = ?
            AND tipo_fluxo = 'correcao'
            AND status = 'pendente'
            AND deleted_at IS NULL
            UNION ALL
            SELECT COUNT(*) as total
            FROM prazos
            WHERE responsavel_id = ?
            AND tipo_fluxo = 'correcao'
            AND status = 'pendente'
            AND deleted_at IS NULL";

        $stmt = $pdo->prepare($sql_stats_correcoes);
        $stmt->execute([$usuario_logado['usuario_id'], $usuario_logado['usuario_id']]);
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $correcoes_pendentes = array_sum(array_column($resultados, 'total'));

        // Contar protocolos
        $sql_stats_protocolos = "
            SELECT COUNT(*) as total
            FROM tarefas
            WHERE responsavel_id = ?
            AND tipo_fluxo = 'protocolo'
            AND status = 'pendente'
            AND deleted_at IS NULL
            UNION ALL
            SELECT COUNT(*) as total
            FROM prazos
            WHERE responsavel_id = ?
            AND tipo_fluxo = 'protocolo'
            AND status = 'pendente'
            AND deleted_at IS NULL";

        $stmt = $pdo->prepare($sql_stats_protocolos);
        $stmt->execute([$usuario_logado['usuario_id'], $usuario_logado['usuario_id']]);
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $protocolos_pendentes = array_sum(array_column($resultados, 'total'));

        // Contar concluídas nos últimos 30 dias
        $sql_stats_concluidas = "
            SELECT COUNT(*) as total
            FROM tarefas
            WHERE responsavel_id = ?
            AND tipo_fluxo IN ('revisao', 'protocolo')
            AND status IN ('concluida', 'revisao_recusada')
            AND data_conclusao >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            AND deleted_at IS NULL
            UNION ALL
            SELECT COUNT(*) as total
            FROM prazos
            WHERE responsavel_id = ?
            AND tipo_fluxo IN ('revisao', 'protocolo')
            AND status IN ('concluido', 'revisao_recusada')
            AND data_conclusao >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            AND deleted_at IS NULL";

        $stmt = $pdo->prepare($sql_stats_concluidas);
        $stmt->execute([$usuario_logado['usuario_id'], $usuario_logado['usuario_id']]);
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $concluidas_mes = array_sum(array_column($resultados, 'total'));
        ?>

        <!-- Cards de Estatísticas -->
        <div class="stats-grid">
            <div class="stat-card pendentes">
                <div class="stat-label">📋 Revisões Pendentes</div>
                <div class="stat-number"><?= $revisoes_pendentes ?></div>
                <small>Aguardando sua revisão</small>
            </div>

            <div class="stat-card correcoes">
                <div class="stat-label">🔧 Correções Pendentes</div>
                <div class="stat-number"><?= $correcoes_pendentes ?></div>
                <small>Requer correção</small>
            </div>

            <div class="stat-card protocolos">
                <div class="stat-label">📤 Protocolos Pendentes</div>
                <div class="stat-number"><?= $protocolos_pendentes ?></div>
                <small>Pronto para protocolar</small>
            </div>

            <div class="stat-card concluidas">
                <div class="stat-label">✅ Concluídas (30 dias)</div>
                <div class="stat-number"><?= $concluidas_mes ?></div>
                <small>Finalizadas no mês</small>
            </div>
        </div>

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab active" onclick="mostrarTab('revisoes-pendentes')">
                📝 Revisões Pendentes (<?= $revisoes_pendentes ?>)
            </button>
            <button class="tab" onclick="mostrarTab('correcoes-pendentes')">
                🔧 Correções (<?= $correcoes_pendentes ?>)
            </button>
            <button class="tab" onclick="mostrarTab('protocolos-pendentes')">
                📤 Protocolos (<?= $protocolos_pendentes ?>)
            </button>
            <button class="tab" onclick="mostrarTab('historico')">
                📜 Histórico
            </button>
        </div>

        <!-- Filtros -->
        <div class="filter-bar">
            <select class="filter-select" id="filtro-tipo">
                <option value="">Todos os Tipos</option>
                <option value="tarefa">Tarefas</option>
                <option value="prazo">Prazos</option>
            </select>

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

        <!-- Tab: Revisões Pendentes -->
        <div id="revisoes-pendentes" class="tab-content">
            <div class="revisao-list">
                <?php
                // Buscar revisões pendentes (tarefas + prazos)
                $sql_revisoes = "
                    SELECT 'tarefa' as tipo_item, t.id, t.titulo, t.descricao, t.prioridade,
                           t.data_vencimento, t.data_criacao, t.processo_id, t.revisao_ciclo,
                           u_criador.nome as solicitante_nome,
                           proc.numero_processo,
                           cli.nome as cliente_nome,
                           DATEDIFF(NOW(), t.data_criacao) as dias_aguardando
                    FROM tarefas t
                    LEFT JOIN usuarios u_criador ON t.criado_por = u_criador.id
                    LEFT JOIN processos proc ON t.processo_id = proc.id
                    LEFT JOIN clientes cli ON proc.cliente_id = cli.id
                    WHERE t.responsavel_id = ?
                    AND t.tipo_fluxo = 'revisao'
                    AND t.status = 'pendente'
                    AND t.deleted_at IS NULL

                    UNION ALL

                    SELECT 'prazo' as tipo_item, p.id, p.titulo, p.descricao, p.prioridade,
                           p.data_vencimento, p.data_criacao, p.processo_id, p.revisao_ciclo,
                           u_criador.nome as solicitante_nome,
                           proc.numero_processo,
                           cli.nome as cliente_nome,
                           DATEDIFF(NOW(), p.data_criacao) as dias_aguardando
                    FROM prazos p
                    LEFT JOIN usuarios u_criador ON p.criado_por = u_criador.id
                    LEFT JOIN processos proc ON p.processo_id = proc.id
                    LEFT JOIN clientes cli ON proc.cliente_id = cli.id
                    WHERE p.responsavel_id = ?
                    AND p.tipo_fluxo = 'revisao'
                    AND p.status = 'pendente'
                    AND p.deleted_at IS NULL

                    ORDER BY prioridade DESC, data_criacao ASC";

                $stmt = $pdo->prepare($sql_revisoes);
                $stmt->execute([$usuario_logado['usuario_id'], $usuario_logado['usuario_id']]);
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

                        $tipo_label = $rev['tipo_item'] === 'tarefa' ? 'Tarefa' : 'Prazo';
                        ?>
                        <div class="revisao-item" data-tipo="<?= $rev['tipo_item'] ?>" data-prioridade="<?= $rev['prioridade'] ?>">
                            <div class="revisao-header">
                                <div>
                                    <div class="revisao-title">
                                        <?= htmlspecialchars($rev['titulo']) ?>
                                    </div>
                                    <?php if ($rev['numero_processo']): ?>
                                        <small>Processo: <?= htmlspecialchars($rev['numero_processo']) ?> - <?= htmlspecialchars($rev['cliente_nome']) ?></small>
                                    <?php endif; ?>
                                </div>
                                <div class="revisao-badges">
                                    <span class="badge tipo-revisao"><?= $tipo_label ?></span>
                                    <span class="badge <?= $rev['prioridade'] ?>">
                                        <?= ucfirst($rev['prioridade']) ?>
                                    </span>
                                    <span class="badge <?= $sla_class ?>">
                                        <?= $rev['dias_aguardando'] ?> dia<?= $rev['dias_aguardando'] != 1 ? 's' : '' ?>
                                    </span>
                                    <span class="badge" style="background: #f3f4f6;">
                                        Ciclo <?= $rev['revisao_ciclo'] ?>
                                    </span>
                                </div>
                            </div>

                            <div class="revisao-meta">
                                <div class="revisao-meta-item">
                                    👤 Solicitante: <?= htmlspecialchars($rev['solicitante_nome']) ?>
                                </div>
                                <div class="revisao-meta-item">
                                    📅 Criado: <?= date('d/m/Y H:i', strtotime($rev['data_criacao'])) ?>
                                </div>
                                <div class="revisao-meta-item">
                                    ⏰ Vencimento: <?= date('d/m/Y', strtotime($rev['data_vencimento'])) ?>
                                </div>
                            </div>

                            <?php if ($rev['descricao']): ?>
                                <div style="margin: 10px 0; padding: 10px; background: #f9fafb; border-radius: 8px;">
                                    <strong>Descrição:</strong><br>
                                    <?= nl2br(htmlspecialchars(substr($rev['descricao'], 0, 300))) ?>
                                    <?php if (strlen($rev['descricao']) > 300) echo '...'; ?>
                                </div>
                            <?php endif; ?>

                            <div class="revisao-actions">
                                <a href="../agenda/?acao=visualizar&tipo=<?= $rev['tipo_item'] ?>&id=<?= $rev['id'] ?>" class="btn-action btn-revisar">
                                    ✅ Revisar
                                </a>
                                <a href="historico_fluxo_hierarquico.php?tipo=<?= $rev['tipo_item'] ?>&id=<?= $rev['id'] ?>" class="btn-action btn-historico">
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

        <!-- Tab: Correções Pendentes -->
        <div id="correcoes-pendentes" class="tab-content" style="display: none;">
            <div class="revisao-list">
                <?php
                // Similar à query acima, mas com tipo_fluxo = 'correcao'
                $sql_correcoes = "
                    SELECT 'tarefa' as tipo_item, t.id, t.titulo, t.descricao, t.prioridade,
                           t.data_vencimento, t.data_criacao, t.processo_id, t.revisao_ciclo,
                           u_criador.nome as criador_nome,
                           proc.numero_processo,
                           DATEDIFF(NOW(), t.data_criacao) as dias_aguardando
                    FROM tarefas t
                    LEFT JOIN usuarios u_criador ON t.criado_por = u_criador.id
                    LEFT JOIN processos proc ON t.processo_id = proc.id
                    WHERE t.responsavel_id = ?
                    AND t.tipo_fluxo = 'correcao'
                    AND t.status = 'pendente'
                    AND t.deleted_at IS NULL

                    UNION ALL

                    SELECT 'prazo' as tipo_item, p.id, p.titulo, p.descricao, p.prioridade,
                           p.data_vencimento, p.data_criacao, p.processo_id, p.revisao_ciclo,
                           u_criador.nome as criador_nome,
                           proc.numero_processo,
                           DATEDIFF(NOW(), p.data_criacao) as dias_aguardando
                    FROM prazos p
                    LEFT JOIN usuarios u_criador ON p.criado_por = u_criador.id
                    LEFT JOIN processos proc ON p.processo_id = proc.id
                    WHERE p.responsavel_id = ?
                    AND p.tipo_fluxo = 'correcao'
                    AND p.status = 'pendente'
                    AND p.deleted_at IS NULL

                    ORDER BY data_vencimento ASC";

                $stmt = $pdo->prepare($sql_correcoes);
                $stmt->execute([$usuario_logado['usuario_id'], $usuario_logado['usuario_id']]);
                $correcoes = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($correcoes)) {
                    ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">✨</div>
                        <h3>Nenhuma correção pendente!</h3>
                        <p>Não há correções aguardando.</p>
                    </div>
                    <?php
                } else {
                    foreach ($correcoes as $cor) {
                        ?>
                        <div class="revisao-item" data-tipo="<?= $cor['tipo_item'] ?>">
                            <div class="revisao-header">
                                <div>
                                    <div class="revisao-title">
                                        <?= htmlspecialchars($cor['titulo']) ?>
                                    </div>
                                    <?php if ($cor['numero_processo']): ?>
                                        <small>Processo: <?= htmlspecialchars($cor['numero_processo']) ?></small>
                                    <?php endif; ?>
                                </div>
                                <div class="revisao-badges">
                                    <span class="badge tipo-correcao">Correção</span>
                                    <span class="badge <?= $cor['prioridade'] ?>">
                                        <?= ucfirst($cor['prioridade']) ?>
                                    </span>
                                </div>
                            </div>

                            <div class="revisao-actions">
                                <a href="../agenda/?acao=visualizar&tipo=<?= $cor['tipo_item'] ?>&id=<?= $cor['id'] ?>" class="btn-action btn-revisar">
                                    🔧 Fazer Correção
                                </a>
                            </div>
                        </div>
                        <?php
                    }
                }
                ?>
            </div>
        </div>

        <!-- Tab: Protocolos Pendentes -->
        <div id="protocolos-pendentes" class="tab-content" style="display: none;">
            <div class="revisao-list">
                <?php
                // Similar, mas com tipo_fluxo = 'protocolo'
                $sql_protocolos = "
                    SELECT 'tarefa' as tipo_item, t.id, t.titulo, t.descricao, t.prioridade,
                           t.data_vencimento, t.data_criacao, t.processo_id,
                           proc.numero_processo
                    FROM tarefas t
                    LEFT JOIN processos proc ON t.processo_id = proc.id
                    WHERE t.responsavel_id = ?
                    AND t.tipo_fluxo = 'protocolo'
                    AND t.status = 'pendente'
                    AND t.deleted_at IS NULL

                    UNION ALL

                    SELECT 'prazo' as tipo_item, p.id, p.titulo, p.descricao, p.prioridade,
                           p.data_vencimento, p.data_criacao, p.processo_id,
                           proc.numero_processo
                    FROM prazos p
                    LEFT JOIN processos proc ON p.processo_id = proc.id
                    WHERE p.responsavel_id = ?
                    AND p.tipo_fluxo = 'protocolo'
                    AND p.status = 'pendente'
                    AND p.deleted_at IS NULL

                    ORDER BY data_vencimento ASC";

                $stmt = $pdo->prepare($sql_protocolos);
                $stmt->execute([$usuario_logado['usuario_id'], $usuario_logado['usuario_id']]);
                $protocolos = $stmt->fetchAll(PDO::FETCH_ASSOC);

                if (empty($protocolos)) {
                    ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📭</div>
                        <h3>Nenhum protocolo pendente!</h3>
                        <p>Não há itens aguardando protocolo.</p>
                    </div>
                    <?php
                } else {
                    foreach ($protocolos as $prot) {
                        ?>
                        <div class="revisao-item" data-tipo="<?= $prot['tipo_item'] ?>">
                            <div class="revisao-header">
                                <div>
                                    <div class="revisao-title">
                                        <?= htmlspecialchars($prot['titulo']) ?>
                                    </div>
                                    <?php if ($prot['numero_processo']): ?>
                                        <small>Processo: <?= htmlspecialchars($prot['numero_processo']) ?></small>
                                    <?php endif; ?>
                                </div>
                                <div class="revisao-badges">
                                    <span class="badge tipo-protocolo">Protocolo</span>
                                    <span class="badge <?= $prot['prioridade'] ?>">
                                        <?= ucfirst($prot['prioridade']) ?>
                                    </span>
                                </div>
                            </div>

                            <div class="revisao-actions">
                                <a href="../agenda/?acao=visualizar&tipo=<?= $prot['tipo_item'] ?>&id=<?= $prot['id'] ?>" class="btn-action btn-revisar">
                                    📤 Protocolar
                                </a>
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
            <p style="padding: 20px; text-align: center; color: #6b7280;">
                Selecione um item específico para ver o histórico completo
            </p>
        </div>
    </div>

    <script>
    // Funções de Tab
    function mostrarTab(tabName) {
        document.querySelectorAll('.tab-content').forEach(content => {
            content.style.display = 'none';
        });

        document.querySelectorAll('.tab').forEach(tab => {
            tab.classList.remove('active');
        });

        document.getElementById(tabName).style.display = 'block';
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

    document.getElementById('filtro-tipo').addEventListener('change', function() {
        const tipo = this.value;
        document.querySelectorAll('.revisao-item').forEach(item => {
            if (!tipo || item.dataset.tipo === tipo) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
            }
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
