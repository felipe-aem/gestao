<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';
require_once '../../includes/layout.php';

$usuario_logado = Auth::user();

// Filtros
$data_inicio = $_GET['data_inicio'] ?? date('Y-m-01');
$data_fim = $_GET['data_fim'] ?? date('Y-m-t');
$organizador_filtro = $_GET['organizador'] ?? '';
$participante_filtro = $_GET['participante'] ?? '';
$tipo_filtro = $_GET['tipo'] ?? '';
$status_filtro = $_GET['status'] ?? '';

try {
    // Buscar usuários para filtro
    $sql = "SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome";
    $stmt = executeQuery($sql);
    $usuarios = $stmt->fetchAll();
    
	// Construir query base
	$where_conditions = ["DATE(a.data_inicio) >= ? AND DATE(a.data_inicio) <= ?"];
	$params = [$data_inicio, $data_fim];

	if (!empty($organizador_filtro)) {
		$where_conditions[] = "EXISTS (
			SELECT 1 FROM agenda_participantes ap 
			WHERE ap.agenda_id = a.id 
			AND ap.usuario_id = ? 
			AND ap.status_participacao = 'Organizador'
		)";
		$params[] = $organizador_filtro;
	}

	if (!empty($participante_filtro)) {
		$where_conditions[] = "EXISTS (
			SELECT 1 FROM agenda_participantes ap 
			WHERE ap.agenda_id = a.id 
			AND ap.usuario_id = ?
		)";
		$params[] = $participante_filtro;
	}
    
    if (!empty($tipo_filtro)) {
        $where_conditions[] = "a.tipo = ?";
        $params[] = $tipo_filtro;
    }
    
    if (!empty($status_filtro)) {
        $where_conditions[] = "a.status = ?";
        $params[] = $status_filtro;
    }
    
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
    
    // Estatísticas gerais
    $sql = "SELECT 
        COUNT(*) as total_eventos,
        SUM(CASE WHEN status = 'Agendado' THEN 1 ELSE 0 END) as agendados,
        SUM(CASE WHEN status = 'Confirmado' THEN 1 ELSE 0 END) as confirmados,
        SUM(CASE WHEN status = 'Concluído' THEN 1 ELSE 0 END) as concluidos,
        SUM(CASE WHEN status = 'Cancelado' THEN 1 ELSE 0 END) as cancelados,
        SUM(CASE WHEN prioridade = 'Urgente' THEN 1 ELSE 0 END) as urgentes,
        SUM(CASE WHEN prioridade = 'Alta' THEN 1 ELSE 0 END) as alta_prioridade
        FROM agenda a $where_clause";
    
    $stmt = executeQuery($sql, $params);
    $stats = $stmt->fetch();
    
    // Eventos por tipo
    $sql = "SELECT tipo, COUNT(*) as total 
            FROM agenda a $where_clause 
            GROUP BY tipo 
            ORDER BY total DESC";
    $stmt = executeQuery($sql, $params);
    $eventos_por_tipo = $stmt->fetchAll();
    
    // Eventos por organizador
	$sql = "SELECT u.nome, COUNT(*) as total,
			SUM(CASE WHEN a.status = 'Concluído' THEN 1 ELSE 0 END) as concluidos,
			SUM(CASE WHEN a.status = 'Cancelado' THEN 1 ELSE 0 END) as cancelados,
			SUM(CASE WHEN a.status IN ('Agendado', 'Confirmado') THEN 1 ELSE 0 END) as pendentes
			FROM agenda a 
			INNER JOIN agenda_participantes ap ON a.id = ap.agenda_id AND ap.status_participacao = 'Organizador'
			LEFT JOIN usuarios u ON ap.usuario_id = u.id
			$where_clause 
			GROUP BY ap.usuario_id, u.nome 
			ORDER BY total DESC";
    
	// Participação geral (todos os tipos de participação)
	$sql_participacao = "SELECT u.nome, ap.status_participacao, COUNT(*) as total
						 FROM agenda a 
						 INNER JOIN agenda_participantes ap ON a.id = ap.agenda_id
						 LEFT JOIN usuarios u ON ap.usuario_id = u.id
						 $where_clause 
						 GROUP BY ap.usuario_id, u.nome, ap.status_participacao 
						 ORDER BY u.nome, ap.status_participacao";
	$stmt = executeQuery($sql_participacao, $params);
	$participacao_geral = $stmt->fetchAll();
	
    // Eventos por dia (para gráfico)
    $sql = "SELECT DATE(data_inicio) as data, COUNT(*) as total
            FROM agenda a $where_clause
            GROUP BY DATE(data_inicio)
            ORDER BY data ASC";
    $stmt = executeQuery($sql, $params);
    $eventos_por_dia = $stmt->fetchAll();
    
    // Estatísticas de participação
	$sql_stats_participacao = "SELECT 
		ap.status_participacao,
		COUNT(*) as total
		FROM agenda a
		INNER JOIN agenda_participantes ap ON a.id = ap.agenda_id
		$where_clause
		GROUP BY ap.status_participacao";
	$stmt = executeQuery($sql_stats_participacao, $params);
	$stats_participacao = $stmt->fetchAll();

	$participacao_stats = [
		'Organizador' => 0,
		'Confirmado' => 0,
		'Convidado' => 0,
		'Recusado' => 0
	];

	foreach ($stats_participacao as $stat) {
		$participacao_stats[$stat['status_participacao']] = $stat['total'];
	}
	
	// Taxa de conclusão por mês
    $sql = "SELECT 
            DATE_FORMAT(data_inicio, '%Y-%m') as mes,
            COUNT(*) as total,
            SUM(CASE WHEN status = 'Concluído' THEN 1 ELSE 0 END) as concluidos,
            ROUND((SUM(CASE WHEN status = 'Concluído' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 1) as taxa_conclusao
            FROM agenda a $where_clause
            GROUP BY DATE_FORMAT(data_inicio, '%Y-%m')
            ORDER BY mes DESC
            LIMIT 6";
    $stmt = executeQuery($sql, $params);
    $taxa_conclusao = $stmt->fetchAll();
    
} catch (Exception $e) {
    $stats = ['total_eventos' => 0, 'agendados' => 0, 'confirmados' => 0, 'concluidos' => 0, 'cancelados' => 0, 'urgentes' => 0, 'alta_prioridade' => 0];
    $eventos_por_tipo = [];
    $eventos_por_usuario = [];
    $eventos_por_dia = [];
    $taxa_conclusao = [];
}

// Conteúdo da página
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
    }
    
    .page-header h2 {
        color: #1a1a1a;
        font-size: 24px;
        font-weight: 700;
    }
    
    .btn-voltar {
        padding: 10px 20px;
        background: #6c757d;
        color: white;
        text-decoration: none;
        border-radius: 8px;
        font-weight: 600;
        transition: all 0.3s;
    }
    
    .btn-voltar:hover {
        background: #5a6268;
        transform: translateY(-1px);
    }
    
    .filters-container {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        padding: 25px;
        margin-bottom: 30px;
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
        padding: 10px;
        border: 1px solid #ddd;
        border-radius: 5px;
        font-size: 14px;
    }
    
    .btn-filter {
        background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
        color: white;
        padding: 12px 24px;
        border: none;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
    }
    
    .btn-filter:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0, 123, 255, 0.4);
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
        padding: 20px;
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        text-align: center;
        transition: all 0.3s ease;
        border-left: 4px solid transparent;
    }
    
    .stat-card:hover {
        transform: translateY(-4px);
        box-shadow: 0 12px 40px rgba(0,0,0,0.2);
    }
    
    .stat-card h3 {
        color: #1a1a1a;
        font-size: 28px;
        margin-bottom: 8px;
        font-weight: 700;
    }
    
    .stat-card p {
        color: #555;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    
    .stat-card.primary { border-left-color: #007bff; }
    .stat-card.success { border-left-color: #28a745; }
    .stat-card.warning { border-left-color: #ffc107; }
    .stat-card.info { border-left-color: #17a2b8; }
    .stat-card.danger { border-left-color: #dc3545; }
    .stat-card.secondary { border-left-color: #6c757d; }
    
    .stat-card.primary h3 { color: #007bff; }
    .stat-card.success h3 { color: #28a745; }
    .stat-card.warning h3 { color: #ffc107; }
    .stat-card.info h3 { color: #17a2b8; }
    .stat-card.danger h3 { color: #dc3545; }
    .stat-card.secondary h3 { color: #6c757d; }
    
    .reports-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 30px;
        margin-bottom: 30px;
    }
    
    .report-section {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        padding: 25px;
    }
    
    .section-title {
        color: #1a1a1a;
        font-size: 18px;
        font-weight: 700;
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 2px solid #e9ecef;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .chart-container {
        height: 300px;
        display: flex;
        align-items: center;
        justify-content: center;
        background: rgba(0,0,0,0.02);
        border-radius: 8px;
        margin-bottom: 20px;
    }
    
    .data-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .data-table th {
        background: #f8f9fa;
        padding: 12px;
        text-align: left;
        font-weight: 600;
        border-bottom: 2px solid #e9ecef;
        font-size: 13px;
        text-transform: uppercase;
        color: #666;
    }
    
    .data-table td {
        padding: 12px;
        border-bottom: 1px solid #e9ecef;
        color: #444;
    }
    
    .data-table tr:hover {
        background: rgba(0,0,0,0.02);
    }
    
    .progress-bar {
        background: #e9ecef;
        border-radius: 10px;
        height: 8px;
        overflow: hidden;
        margin-top: 5px;
    }
    
    .progress-fill {
        height: 100%;
        background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        transition: width 0.3s ease;
    }
    
    .empty-state {
        text-align: center;
        padding: 40px 20px;
        color: #666;
    }
    
    .empty-state-icon {
        font-size: 48px;
        margin-bottom: 15px;
        opacity: 0.5;
    }
	
	.badge {
		padding: 4px 8px;
		border-radius: 12px;
		font-size: 11px;
		font-weight: 600;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		display: inline-block;
		margin: 2px;
	}

	.badge-primary { background: #007bff; color: white; }
	.badge-success { background: #28a745; color: white; }
	.badge-warning { background: #ffc107; color: #000; }
	.badge-danger { background: #dc3545; color: white; }

	.data-table td .badge {
		margin: 0;
	}
    
    @media (max-width: 768px) {
        .page-header {
            flex-direction: column;
            gap: 15px;
            text-align: center;
        }
        
        .filters-grid {
            grid-template-columns: 1fr;
        }
        
        .stats-grid {
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
        }
        
        .reports-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="page-header">
    <h2>📊 Relatórios da Agenda</h2>
    <a href="index.php" class="btn-voltar">← Voltar</a>
</div>

<!-- Filtros -->
<div class="filters-container">
    <form method="GET">
        <div class="filters-grid">
            <div class="filter-group">
                <label for="data_inicio">Data Início:</label>
                <input type="date" id="data_inicio" name="data_inicio" value="<?= htmlspecialchars($data_inicio) ?>">
            </div>
            
            <div class="filter-group">
                <label for="data_fim">Data Fim:</label>
                <input type="date" id="data_fim" name="data_fim" value="<?= htmlspecialchars($data_fim) ?>">
            </div>
            
            <div class="filter-group">
				<label for="organizador">Organizador:</label>
				<select id="organizador" name="organizador">
					<option value="">Todos</option>
					<?php foreach ($usuarios as $usuario): ?>
					<option value="<?= $usuario['id'] ?>" <?= $organizador_filtro == $usuario['id'] ? 'selected' : '' ?>>
						<?= htmlspecialchars($usuario['nome']) ?>
					</option>
					<?php endforeach; ?>
				</select>
			</div>

			<div class="filter-group">
				<label for="participante">Participante:</label>
				<select id="participante" name="participante">
					<option value="">Todos</option>
					<?php foreach ($usuarios as $usuario): ?>
					<option value="<?= $usuario['id'] ?>" <?= $participante_filtro == $usuario['id'] ? 'selected' : '' ?>>
						<?= htmlspecialchars($usuario['nome']) ?>
					</option>
					<?php endforeach; ?>
				</select>
			</div>
            
            <div class="filter-group">
                <label for="tipo">Tipo:</label>
                <select id="tipo" name="tipo">
                    <option value="">Todos</option>
                    <option value="Reunião" <?= $tipo_filtro === 'Reunião' ? 'selected' : '' ?>>Reunião</option>
                    <option value="Audiência" <?= $tipo_filtro === 'Audiência' ? 'selected' : '' ?>>Audiência</option>
                    <option value="Prazo" <?= $tipo_filtro === 'Prazo' ? 'selected' : '' ?>>Prazo</option>
                    <option value="Compromisso" <?= $tipo_filtro === 'Compromisso' ? 'selected' : '' ?>>Compromisso</option>
                    <option value="Tarefa" <?= $tipo_filtro === 'Tarefa' ? 'selected' : '' ?>>Tarefa</option>
                    <option value="Outro" <?= $tipo_filtro === 'Outro' ? 'selected' : '' ?>>Outro</option>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="status">Status:</label>
                <select id="status" name="status">
                    <option value="">Todos</option>
                    <option value="Agendado" <?= $status_filtro === 'Agendado' ? 'selected' : '' ?>>Agendado</option>
                    <option value="Confirmado" <?= $status_filtro === 'Confirmado' ? 'selected' : '' ?>>Confirmado</option>
                    <option value="Concluído" <?= $status_filtro === 'Concluído' ? 'selected' : '' ?>>Concluído</option>
                    <option value="Cancelado" <?= $status_filtro === 'Cancelado' ? 'selected' : '' ?>>Cancelado</option>
                    <option value="Reagendado" <?= $status_filtro === 'Reagendado' ? 'selected' : '' ?>>Reagendado</option>
                </select>
            </div>
        </div>
        
        <button type="submit" class="btn-filter">📊 Gerar Relatório</button>
    </form>
</div>

<!-- Estatísticas Gerais -->
<div class="stats-grid">
    <div class="stat-card primary">
        <h3><?= $stats['total_eventos'] ?></h3>
        <p>Total de Eventos</p>
    </div>
    <div class="stat-card info">
        <h3><?= $stats['agendados'] ?></h3>
        <p>Agendados</p>
    </div>
    <div class="stat-card warning">
        <h3><?= $stats['confirmados'] ?></h3>
        <p>Confirmados</p>
    </div>
    <div class="stat-card success">
        <h3><?= $stats['concluidos'] ?></h3>
        <p>Concluídos</p>
    </div>
    <div class="stat-card danger">
        <h3><?= $stats['cancelados'] ?></h3>
        <p>Cancelados</p>
    </div>
    <div class="stat-card secondary">
        <h3><?= $stats['urgentes'] + $stats['alta_prioridade'] ?></h3>
        <p>Alta Prioridade</p>
    </div>
</div>

<!-- Relatórios Detalhados -->
<div class="reports-grid">
    <!-- Eventos por Tipo -->
    <div class="report-section">
        <h3 class="section-title">📊 Eventos por Tipo</h3>
        
        <?php if (empty($eventos_por_tipo)): ?>
        <div class="empty-state">
            <div class="empty-state-icon">📊</div>
            <p>Nenhum dado encontrado</p>
        </div>
        <?php else: ?>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Tipo</th>
                    <th>Quantidade</th>
                    <th>Percentual</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($eventos_por_tipo as $tipo): ?>
                <?php $percentual = ($tipo['total'] / $stats['total_eventos']) * 100; ?>
                <tr>
                    <td><?= htmlspecialchars($tipo['tipo']) ?></td>
                    <td><?= $tipo['total'] ?></td>
                    <td>
                        <?= number_format($percentual, 1) ?>%
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: <?= $percentual ?>%"></div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- Eventos por Organizador -->
	<div class="report-section">
		<h3 class="section-title">👑 Eventos por Organizador</h3>

		<?php if (empty($eventos_por_usuario)): ?>
		<div class="empty-state">
			<div class="empty-state-icon">👑</div>
			<p>Nenhum dado encontrado</p>
		</div>
		<?php else: ?>
		<table class="data-table">
			<thead>
				<tr>
					<th>Organizador</th>
					<th>Total</th>
					<th>Concluídos</th>
					<th>Cancelados</th>
					<th>Pendentes</th>
					<th>Taxa Sucesso</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($eventos_por_usuario as $usuario): ?>
				<?php $taxa = $usuario['total'] > 0 ? ($usuario['concluidos'] / $usuario['total']) * 100 : 0; ?>
				<tr>
					<td><?= htmlspecialchars($usuario['nome']) ?></td>
					<td><?= $usuario['total'] ?></td>
					<td><span class="badge badge-success"><?= $usuario['concluidos'] ?></span></td>
					<td><span class="badge badge-danger"><?= $usuario['cancelados'] ?></span></td>
					<td><span class="badge badge-warning"><?= $usuario['pendentes'] ?></span></td>
					<td>
						<?= number_format($taxa, 1) ?>%
						<div class="progress-bar">
							<div class="progress-fill" style="width: <?= $taxa ?>%"></div>
						</div>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
	</div>
	
	<!-- Participação Geral -->
	<div class="report-section">
		<h3 class="section-title">👥 Participação por Status</h3>

		<?php if (empty($participacao_geral)): ?>
		<div class="empty-state">
			<div class="empty-state-icon">👥</div>
			<p>Nenhum dado encontrado</p>
		</div>
		<?php else: ?>
		<?php
		// Organizar dados por usuário
		$participacao_por_usuario = [];
		foreach ($participacao_geral as $item) {
			$nome = $item['nome'];
			if (!isset($participacao_por_usuario[$nome])) {
				$participacao_por_usuario[$nome] = [
					'Organizador' => 0,
					'Confirmado' => 0,
					'Convidado' => 0,
					'Recusado' => 0
				];
			}
			$participacao_por_usuario[$nome][$item['status_participacao']] = $item['total'];
		}
		?>

		<table class="data-table">
			<thead>
				<tr>
					<th>Usuário</th>
					<th>👑 Organizador</th>
					<th>✅ Confirmado</th>
					<th>⏳ Convidado</th>
					<th>❌ Recusado</th>
					<th>Total</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($participacao_por_usuario as $nome => $stats): ?>
				<?php $total = array_sum($stats); ?>
				<tr>
					<td><?= htmlspecialchars($nome) ?></td>
					<td><span class="badge badge-primary"><?= $stats['Organizador'] ?></span></td>
					<td><span class="badge badge-success"><?= $stats['Confirmado'] ?></span></td>
					<td><span class="badge badge-warning"><?= $stats['Convidado'] ?></span></td>
					<td><span class="badge badge-danger"><?= $stats['Recusado'] ?></span></td>
					<td><strong><?= $total ?></strong></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php endif; ?>
</div>
</div>

<!-- Taxa de Conclusão Mensal -->
<div class="report-section">
    <h3 class="section-title">📈 Taxa de Conclusão por Mês</h3>
    
    <?php if (empty($taxa_conclusao)): ?>
    <div class="empty-state">
        <div class="empty-state-icon">📈</div>
        <p>Nenhum dado encontrado</p>
    </div>
    <?php else: ?>
    <table class="data-table">
        <thead>
            <tr>
                <th>Mês</th>
                <th>Total Eventos</th>
                <th>Concluídos</th>
                <th>Taxa de Conclusão</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($taxa_conclusao as $mes): ?>
            <tr>
                <td><?= date('m/Y', strtotime($mes['mes'] . '-01')) ?></td>
                <td><?= $mes['total'] ?></td>
                <td><?= $mes['concluidos'] ?></td>
                <td>
                    <?= $mes['taxa_conclusao'] ?>%
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?= $mes['taxa_conclusao'] ?>%"></div>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<script>
    // Auto-ajustar data fim quando data início mudar
    document.getElementById('data_inicio').addEventListener('change', function() {
        const dataFim = document.getElementById('data_fim');
        if (this.value > dataFim.value) {
            dataFim.value = this.value;
        }
    });
    
    // Botões de período rápido
    function setPeriodo(dias) {
        const hoje = new Date();
        const inicio = new Date();
        inicio.setDate(hoje.getDate() - dias);
        
        document.getElementById('data_inicio').value = inicio.toISOString().split('T')[0];
        document.getElementById('data_fim').value = hoje.toISOString().split('T')[0];
    }
    
    // Adicionar botões de período rápido
    const filtersContainer = document.querySelector('.filters-grid');
    const periodoGroup = document.createElement('div');
    periodoGroup.className = 'filter-group';
    periodoGroup.innerHTML = `
        <label>Períodos Rápidos:</label>
        <div style="display: flex; gap: 5px; flex-wrap: wrap;">
            <button type="button" onclick="setPeriodo(7)" style="padding: 5px 10px; font-size: 12px; border: 1px solid #ddd; background: white; border-radius: 4px; cursor: pointer;">7 dias</button>
            <button type="button" onclick="setPeriodo(30)" style="padding: 5px 10px; font-size: 12px; border: 1px solid #ddd; background: white; border-radius: 4px; cursor: pointer;">30 dias</button>
            <button type="button" onclick="setPeriodo(90)" style="padding: 5px 10px; font-size: 12px; border: 1px solid #ddd; background: white; border-radius: 4px; cursor: pointer;">90 dias</button>
        </div>
    `;
    filtersContainer.appendChild(periodoGroup);
</script>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Relatórios da Agenda', $conteudo, 'agenda');
?>