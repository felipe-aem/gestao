<?php
// Este arquivo pode ser incluído no dashboard
require_once '../../config/database.php';

try {
    $usuario_id = Auth::user()['usuario_id'];
    
    // Buscar próximos 5 eventos onde o usuário participa
    $sql = "SELECT a.*, c.nome as cliente_nome, 
                   ap.status_participacao as meu_status,
                   org.nome as organizador_nome
            FROM agenda a
            LEFT JOIN clientes c ON a.cliente_id = c.id
            INNER JOIN agenda_participantes ap ON a.id = ap.agenda_id
            LEFT JOIN agenda_participantes ap_org ON a.id = ap_org.agenda_id AND ap_org.status_participacao = 'Organizador'
            LEFT JOIN usuarios org ON ap_org.usuario_id = org.id
            WHERE ap.usuario_id = ? 
            AND a.data_inicio >= NOW()
            AND a.status IN ('Agendado', 'Confirmado')
            AND ap.status_participacao IN ('Organizador', 'Confirmado', 'Convidado')
            ORDER BY a.data_inicio ASC
            LIMIT 5";
    
    $stmt = executeQuery($sql, [$usuario_id]);
    $proximos_eventos = $stmt->fetchAll();
    
} catch (Exception $e) {
    $proximos_eventos = [];
}
?>

<div class="widget-proximos-eventos">
    <h3>📅 Próximos Eventos (<?= count($proximos_eventos) ?>)</h3>
    
    <?php if (empty($proximos_eventos)): ?>
    <div class="empty-state">
        <div class="empty-icon">📅</div>
        <p>Nenhum evento próximo</p>
        <small>Você está livre nos próximos dias!</small>
    </div>
    <?php else: ?>
    <div class="eventos-list">
        <?php foreach ($proximos_eventos as $evento): ?>
        <?php
        // Definir ícone e cor baseado no status de participação
        $status_info = match($evento['meu_status']) {
            'Organizador' => ['icon' => '👑', 'class' => 'organizador', 'text' => 'Organizando'],
            'Confirmado' => ['icon' => '✅', 'class' => 'confirmado', 'text' => 'Confirmado'],
            'Convidado' => ['icon' => '⏳', 'class' => 'pendente', 'text' => 'Pendente'],
            default => ['icon' => '❓', 'class' => 'indefinido', 'text' => 'Indefinido']
        };
        
        // Calcular tempo restante
        $tempo_restante = strtotime($evento['data_inicio']) - time();
        $dias_restantes = floor($tempo_restante / (24 * 60 * 60));
        $horas_restantes = floor(($tempo_restante % (24 * 60 * 60)) / (60 * 60));
        ?>
        <div class="evento-item <?= $status_info['class'] ?>" 
             onclick="window.location.href='/modules/agenda/visualizar.php?id=<?= $evento['id'] ?>'"
             style="cursor: pointer;">
            <div class="evento-data">
                <div class="data-principal">
                    <?= date('d/m', strtotime($evento['data_inicio'])) ?>
                </div>
                <div class="hora-principal">
                    <?= date('H:i', strtotime($evento['data_inicio'])) ?>
                </div>
                <?php if ($dias_restantes == 0): ?>
                    <div class="tempo-restante hoje">Hoje</div>
                <?php elseif ($dias_restantes == 1): ?>
                    <div class="tempo-restante amanha">Amanhã</div>
                <?php elseif ($dias_restantes <= 7): ?>
                    <div class="tempo-restante proximos"><?= $dias_restantes ?>d</div>
                <?php endif; ?>
            </div>
            
            <div class="evento-info">
                <div class="evento-titulo">
                    <?= htmlspecialchars($evento['titulo']) ?>
                </div>
                
                <div class="evento-detalhes">
                    <?php if (!empty($evento['cliente_nome'])): ?>
                    <div class="evento-cliente">
                        👤 <?= htmlspecialchars($evento['cliente_nome']) ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($evento['local_evento'])): ?>
                    <div class="evento-local">
                        📍 <?= htmlspecialchars($evento['local_evento']) ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($evento['meu_status'] !== 'Organizador'): ?>
                    <div class="evento-organizador">
                        👑 <?= htmlspecialchars($evento['organizador_nome'] ?? 'N/A') ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="evento-status">
                <div class="status-badge <?= $status_info['class'] ?>">
                    <?= $status_info['icon'] ?>
                </div>
                <div class="status-text">
                    <?= $status_info['text'] ?>
                </div>
                <div class="evento-tipo">
                    <?= htmlspecialchars($evento['tipo']) ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <div class="widget-footer">
        <a href="/modules/agenda/" class="btn-ver-todos">Ver agenda completa</a>
        <a href="/modules/agenda/novo.php" class="btn-novo-evento">+ Novo</a>
    </div>
    <?php endif; ?>
</div>

<style>
	.widget-proximos-eventos {
		background: white;
		border-radius: 15px;
		padding: 20px;
		box-shadow: 0 4px 15px rgba(0,0,0,0.1);
		border: 1px solid rgba(0,0,0,0.05);
	}

	.widget-proximos-eventos h3 {
		margin-bottom: 15px;
		color: #1a1a1a;
		font-size: 16px;
		font-weight: 700;
		display: flex;
		align-items: center;
		gap: 8px;
	}

	.eventos-list {
		display: grid;
		gap: 12px;
	}

	.evento-item {
		display: flex;
		align-items: center;
		gap: 15px;
		padding: 12px;
		background: rgba(0,0,0,0.02);
		border-radius: 10px;
		transition: all 0.3s;
		border-left: 3px solid transparent;
		position: relative;
	}

	.evento-item:hover {
		background: rgba(0,0,0,0.05);
		transform: translateY(-1px);
		box-shadow: 0 2px 8px rgba(0,0,0,0.1);
	}

	.evento-item.organizador {
		border-left-color: #007bff;
		background: rgba(0, 123, 255, 0.05);
	}

	.evento-item.confirmado {
		border-left-color: #28a745;
		background: rgba(40, 167, 69, 0.05);
	}

	.evento-item.pendente {
		border-left-color: #ffc107;
		background: rgba(255, 193, 7, 0.05);
	}

	.evento-data {
		text-align: center;
		min-width: 60px;
		position: relative;
	}

	.data-principal {
		font-weight: 700;
		color: #1a1a1a;
		font-size: 14px;
		line-height: 1;
	}

	.hora-principal {
		font-size: 11px;
		color: #666;
		margin-top: 2px;
	}

	.tempo-restante {
		position: absolute;
		top: -8px;
		right: -8px;
		font-size: 9px;
		padding: 2px 6px;
		border-radius: 10px;
		font-weight: 600;
		text-transform: uppercase;
	}

	.tempo-restante.hoje {
		background: #dc3545;
		color: white;
	}

	.tempo-restante.amanha {
		background: #ffc107;
		color: #000;
	}

	.tempo-restante.proximos {
		background: #17a2b8;
		color: white;
	}

	.evento-info {
		flex: 1;
		min-width: 0;
	}

	.evento-titulo {
		font-weight: 600;
		color: #1a1a1a;
		font-size: 14px;
		margin-bottom: 4px;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	.evento-detalhes {
		display: flex;
		flex-direction: column;
		gap: 2px;
	}

	.evento-cliente,
	.evento-local,
	.evento-organizador {
		font-size: 11px;
		color: #666;
		white-space: nowrap;
		overflow: hidden;
		text-overflow: ellipsis;
	}

	.evento-status {
		display: flex;
		flex-direction: column;
		align-items: center;
		gap: 4px;
		min-width: 60px;
	}

	.status-badge {
		width: 28px;
		height: 28px;
		border-radius: 50%;
		display: flex;
		align-items: center;
		justify-content: center;
		font-size: 14px;
	}

	.status-badge.organizador {
		background: #007bff;
		color: white;
	}

	.status-badge.confirmado {
		background: #28a745;
		color: white;
	}

	.status-badge.pendente {
		background: #ffc107;
		color: #000;
	}

	.status-text {
		font-size: 10px;
		font-weight: 600;
		color: #666;
		text-transform: uppercase;
		text-align: center;
	}

	.evento-tipo {
		font-size: 9px;
		color: #999;
		text-transform: uppercase;
		text-align: center;
		margin-top: 2px;
	}

	.widget-footer {
		margin-top: 15px;
		display: flex;
		gap: 10px;
		justify-content: center;
	}

	.btn-ver-todos,
	.btn-novo-evento {
		color: #007bff;
		text-decoration: none;
		font-size: 12px;
		font-weight: 600;
		padding: 6px 12px;
		border-radius: 15px;
		transition: all 0.3s;
	}

	.btn-ver-todos:hover,
	.btn-novo-evento:hover {
		background: rgba(0, 123, 255, 0.1);
		text-decoration: none;
	}

	.btn-novo-evento {
		background: #007bff;
		color: white;
	}

	.btn-novo-evento:hover {
		background: #0056b3;
		color: white;
	}

	.empty-state {
		text-align: center;
		padding: 30px 20px;
		color: #666;
	}

	.empty-icon {
		font-size: 32px;
		margin-bottom: 10px;
		opacity: 0.5;
	}

	.empty-state p {
		margin: 0 0 5px 0;
		font-weight: 600;
	}

	.empty-state small {
		color: #999;
		font-style: italic;
	}

	/* Responsivo */
	@media (max-width: 480px) {
		.evento-item {
			flex-direction: column;
			gap: 10px;
			text-align: center;
		}

		.evento-data {
			min-width: auto;
		}

		.evento-info {
			text-align: center;
		}

		.widget-footer {
			flex-direction: column;
			gap: 8px;
		}
	}
</style>