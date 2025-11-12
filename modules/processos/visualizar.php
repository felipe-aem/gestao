<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';
require_once '../../includes/layout.php';
require_once __DIR__ . '/ProcessoRelacionamento.php';

$processo_id = $_GET['id'] ?? 0;

if (!$processo_id) {
    $_SESSION['erro'] = 'Processo não encontrado';
    header('Location: index.php');
    exit;
}

$usuario_logado = Auth::user();

// Buscar dados do processo SEM restrição de núcleo
$sql = "SELECT p.*, 
        c.nome as cliente_nome_cadastrado,
        c.telefone as cliente_telefone,
        c.email as cliente_email,
        c.endereco as cliente_endereco,
        u.nome as responsavel_nome,
        cr.nome as criado_por_nome,
        n.nome as nucleo_nome
        FROM processos p
        LEFT JOIN clientes c ON p.cliente_id = c.id
        LEFT JOIN usuarios u ON p.responsavel_id = u.id
        LEFT JOIN usuarios cr ON p.criado_por = cr.id
        LEFT JOIN nucleos n ON p.nucleo_id = n.id
        WHERE p.id = ?";

$stmt = executeQuery($sql, [$processo_id]);
$processo = $stmt->fetch();

if (!$processo) {
    $_SESSION['erro'] = 'Processo não encontrado';
    header('Location: index.php');
    exit;
}

// Buscar partes do processo
$sql = "SELECT pp.*, c.telefone, c.email 
        FROM processo_partes pp
        LEFT JOIN clientes c ON pp.cliente_id = c.id
        WHERE pp.processo_id = ?
        ORDER BY pp.ordem ASC, pp.id ASC";
$stmt = executeQuery($sql, [$processo_id]);
$partes = $stmt->fetchAll();

// Separar nossos clientes das demais partes
$nossos_clientes = [];
$partes_contrarias = [];

foreach ($partes as $parte) {
    if ($parte['e_nosso_cliente'] == 1) {
        $nossos_clientes[] = $parte;
    } else {
        $partes_contrarias[] = $parte;
    }
}

// Buscar resultados do processo
$sql = "SELECT pr.*, u.nome as criado_por_nome 
        FROM processo_resultados pr
        LEFT JOIN usuarios u ON pr.criado_por = u.id
        WHERE pr.processo_id = ?
        ORDER BY pr.data_resultado DESC";
$stmt = executeQuery($sql, [$processo_id]);
$resultados = $stmt->fetchAll();

// Buscar movimentações do processo
$sql = "SELECT pm.*, 
        ua.nome as responsavel_anterior_nome,
        un.nome as responsavel_novo_nome,
        uc.nome as criado_por_nome
        FROM processo_movimentacoes pm
        LEFT JOIN usuarios ua ON pm.responsavel_anterior = ua.id
        LEFT JOIN usuarios un ON pm.responsavel_novo = un.id
        LEFT JOIN usuarios uc ON pm.criado_por = uc.id
        WHERE pm.processo_id = ?
        ORDER BY pm.data_movimentacao DESC, pm.data_criacao DESC";
$stmt = executeQuery($sql, [$processo_id]);
$movimentacoes = $stmt->fetchAll();

// Buscar histórico completo do processo
$sql_historico = "SELECT h.*, u.nome as usuario_nome
                  FROM processos_historico h
                  LEFT JOIN usuarios u ON h.usuario_id = u.id
                  WHERE h.processo_id = ?
                  ORDER BY h.data_acao DESC
                  LIMIT 100";
$stmt_hist = executeQuery($sql_historico, [$processo_id]);
$historico_completo = $stmt_hist->fetchAll();

// INICIAR OUTPUT BUFFERING AQUI
ob_start();
?>

<style>
	* {
		margin: 0;
		padding: 0;
		box-sizing: border-box;
	}

	body {
		font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
		background: linear-gradient(135deg, rgba(0, 0, 0, 0.95) 0%, rgba(40, 40, 40, 0.98) 100%);
		background-attachment: fixed;
		min-height: 100vh;
	}

	.content {
		padding: 30px;
		max-width: 1400px;
		margin: 0 auto;
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
		font-size: 24px;
		font-weight: 700;
	}

	.header-actions {
		display: flex;
		gap: 15px;
		flex-wrap: wrap;
	}

	.btn-voltar, .btn-editar, .btn-resultado {
		padding: 12px 24px;
		color: white;
		text-decoration: none;
		border-radius: 8px;
		font-weight: 600;
		transition: all 0.3s;
		display: inline-block;
		text-align: center;
	}

	.btn-voltar {
		background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
	}

	.btn-editar {
		background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
	}

	.btn-resultado {
		background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
	}

	.btn-voltar:hover, .btn-editar:hover, .btn-resultado:hover {
		transform: translateY(-2px);
		box-shadow: 0 4px 12px rgba(0,0,0,0.2);
	}

	.info-grid {
		display: grid;
		grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
		gap: 30px;
		margin-bottom: 30px;
	}

	.info-card {
		background: rgba(255, 255, 255, 0.95);
		backdrop-filter: blur(10px);
		border-radius: 15px;
		box-shadow: 0 8px 32px rgba(0,0,0,0.15);
		padding: 25px;
	}

	.info-card h3 {
		color: #1a1a1a;
		margin-bottom: 20px;
		font-size: 18px;
		font-weight: 700;
		display: flex;
		align-items: center;
		gap: 10px;
	}

	.info-item {
		margin-bottom: 15px;
		padding-bottom: 10px;
		border-bottom: 1px solid rgba(0,0,0,0.05);
	}

	.info-item:last-child {
		border-bottom: none;
		margin-bottom: 0;
	}

	.info-label {
		font-weight: 600;
		color: #555;
		font-size: 14px;
		margin-bottom: 5px;
	}

	.info-value {
		color: #1a1a1a;
		font-size: 16px;
		font-weight: 500;
	}

	.badge {
		padding: 6px 12px;
		border-radius: 20px;
		font-size: 12px;
		font-weight: 600;
		text-transform: uppercase;
		letter-spacing: 0.5px;
		display: inline-block;
	}

	.badge-emandamento { background: #007bff; color: white; }
	.badge-transitado { background: #28a745; color: white; }
	.badge-emcumprimentodesentenca { background: #ffc107; color: #000; }
	.badge-emprocessoderenuncia { background: #dc3545; color: white; }
	.badge-positivo { background: #28a745; color: white; }
	.badge-negativo { background: #dc3545; color: white; }

	.full-width-card {
		background: rgba(255, 255, 255, 0.95);
		backdrop-filter: blur(10px);
		border-radius: 15px;
		box-shadow: 0 8px 32px rgba(0,0,0,0.15);
		padding: 25px;
		margin-bottom: 30px;
	}

	.full-width-card h3 {
		color: #1a1a1a;
		margin-bottom: 20px;
		font-size: 18px;
		font-weight: 700;
	}

	.timeline {
		position: relative;
		padding-left: 30px;
	}

	.timeline::before {
		content: '';
		position: absolute;
		left: 15px;
		top: 0;
		bottom: 0;
		width: 2px;
		background: linear-gradient(to bottom, #007bff, #28a745);
	}

	.timeline-item {
		position: relative;
		margin-bottom: 25px;
		background: rgba(255, 255, 255, 0.8);
		padding: 20px;
		border-radius: 10px;
		border-left: 4px solid #007bff;
	}

	.timeline-item::before {
		content: '';
		position: absolute;
		left: -37px;
		top: 20px;
		width: 12px;
		height: 12px;
		border-radius: 50%;
		background: #007bff;
		border: 3px solid white;
	}

	.timeline-date {
		font-weight: 600;
		color: #007bff;
		font-size: 14px;
		margin-bottom: 8px;
	}

	.timeline-content h4 {
		color: #1a1a1a;
		margin-bottom: 8px;
		font-size: 16px;
	}

	.timeline-content p {
		color: #666;
		line-height: 1.5;
		margin-bottom: 8px;
	}

	.timeline-meta {
		font-size: 12px;
		color: #999;
		border-top: 1px solid rgba(0,0,0,0.1);
		padding-top: 8px;
		margin-top: 10px;
	}

	.resultado-item {
		background: rgba(255, 255, 255, 0.8);
		padding: 20px;
		border-radius: 10px;
		margin-bottom: 15px;
		border-left: 4px solid #28a745;
	}

	.resultado-item.negativo {
		border-left-color: #dc3545;
	}

	.resultado-header {
		display: flex;
		justify-content: space-between;
		align-items: center;
		margin-bottom: 10px;
		flex-wrap: wrap;
		gap: 10px;
	}

	.resultado-data {
		font-weight: 600;
		color: #555;
		font-size: 14px;
	}

	.resultado-descricao {
		color: #444;
		line-height: 1.6;
		margin-bottom: 10px;
	}

	.resultado-entrega {
		background: rgba(23, 162, 184, 0.1);
		border: 1px solid rgba(23, 162, 184, 0.3);
		border-radius: 5px;
		padding: 8px 12px;
		font-size: 12px;
		color: #0c5460;
		margin-bottom: 8px;
	}

	.data-highlight {
		background: rgba(23, 162, 184, 0.1);
		border: 1px solid rgba(23, 162, 184, 0.3);
		border-radius: 8px;
		padding: 10px;
		color: #0c5460;
		font-weight: 600;
	}

	.empty-value {
		color: #999;
		font-style: italic;
	}

	.empty-state {
		text-align: center;
		padding: 40px;
		color: #666;
	}

	@media (max-width: 768px) {
		.info-grid {
			grid-template-columns: 1fr;
			gap: 20px;
		}

		.page-header {
			flex-direction: column;
			align-items: stretch;
		}

		.header-actions {
			flex-direction: column;
		}

		.timeline {
			padding-left: 20px;
		}

		.timeline-item::before {
			left: -27px;
		}
	}
	
	/* Tabs de navegação */
    .tabs-container {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px 15px 0 0;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        margin-bottom: 0;
        overflow: hidden;
    }
    
    .tabs-nav {
        display: flex;
        border-bottom: 2px solid #e9ecef;
        background: rgba(0,0,0,0.02);
    }
    
    .tab-button {
        flex: 1;
        padding: 18px 20px;
        background: transparent;
        border: none;
        color: #666;
        font-weight: 600;
        font-size: 15px;
        cursor: pointer;
        transition: all 0.3s;
        position: relative;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }
    
    .tab-button:hover {
        background: rgba(0,0,0,0.03);
        color: #333;
    }
    
    .tab-button.active {
        background: white;
        color: #007bff;
        border-bottom: 3px solid #007bff;
    }
    
    .tab-button .badge-count {
        background: #dc3545;
        color: white;
        padding: 2px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 700;
    }
    
    .tab-content {
        display: none;
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 0 0 15px 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        padding: 30px;
    }
    
    .tab-content.active {
        display: block;
    }
    
    /* Histórico completo */
    .historico-item {
        background: white;
        border: 1px solid #e9ecef;
        border-left: 4px solid #007bff;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 15px;
        transition: all 0.3s;
    }
    
    .historico-item:hover {
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        transform: translateX(5px);
    }
    
    .historico-item.tipo-publicacao {
        border-left-color: #ffc107;
    }
    
    .historico-item.tipo-tarefa {
        border-left-color: #28a745;
    }
    
    .historico-item.tipo-prazo {
        border-left-color: #dc3545;
    }
    
    .historico-item.tipo-audiencia {
        border-left-color: #17a2b8;
    }
    
    .historico-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        margin-bottom: 12px;
        flex-wrap: wrap;
        gap: 10px;
    }
    
    .historico-acao {
        font-weight: 700;
        color: #1a1a1a;
        font-size: 16px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .historico-data {
        color: #666;
        font-size: 13px;
        font-weight: 600;
    }
    
    .historico-descricao {
        color: #555;
        line-height: 1.6;
        margin-bottom: 10px;
    }
    
    .historico-meta {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding-top: 12px;
        margin-top: 12px;
        border-top: 1px solid #e9ecef;
        font-size: 12px;
        color: #999;
    }
    
    .historico-tipo-badge {
        padding: 4px 10px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
    }
    
    .tipo-publicacao-badge { background: rgba(255, 193, 7, 0.2); color: #856404; }
    .tipo-tarefa-badge { background: rgba(40, 167, 69, 0.2); color: #155724; }
    .tipo-prazo-badge { background: rgba(220, 53, 69, 0.2); color: #721c24; }
    .tipo-audiencia-badge { background: rgba(23, 162, 184, 0.2); color: #0c5460; }
    .tipo-outro-badge { background: rgba(108, 117, 125, 0.2); color: #383d41; }
    
    @media (max-width: 768px) {
        .tabs-nav {
            flex-direction: column;
        }
        
        .tab-button {
            border-bottom: 1px solid #e9ecef;
        }
    }
    
    /* =============================================================================
   CSS AJUSTE FINO - Últimos Detalhes
   
   ADICIONE ESTE CSS APÓS O CSS EXISTENTE (substituindo as regras antigas)
   ============================================================================= */

/* ==================== LISTA DE RESULTADOS - AJUSTE FINO ==================== */

    /* Lista principal - padding interno e espaçamento */
    #resultados-busca-pai .list-group {
        margin: 0;
        padding: 0;
    }
    
    /* Cada item da lista - ajustes finos */
    #resultados-busca-pai .list-group-item {
        padding: 16px 20px !important;
        border: none !important;
        border-bottom: 1px solid #f0f0f0 !important;
        cursor: pointer;
        transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        background: white;
        display: block !important;
    }
    
    /* Barra lateral esquerda - mais proeminente */
    #resultados-busca-pai .list-group-item::before {
        content: '';
        position: absolute;
        left: 0;
        top: 0;
        bottom: 0;
        width: 0;
        background: linear-gradient(180deg, #007bff 0%, #0056b3 100%);
        transition: width 0.25s ease;
        z-index: 1;
    }
    
    #resultados-busca-pai .list-group-item:hover::before {
        width: 4px;
    }
    
    /* Hover state - mais suave */
    #resultados-busca-pai .list-group-item:hover {
        background: linear-gradient(90deg, #f0f7ff 0%, #ffffff 100%) !important;
        padding-left: 24px !important;
        transform: translateX(0);
        box-shadow: inset 0 0 0 1px rgba(0,123,255,0.08);
    }
    
    #resultados-busca-pai .list-group-item:active {
        background: #e3f2fd !important;
    }
    
    /* ==================== CONTEÚDO DO ITEM - ORGANIZAÇÃO ==================== */
    
    /* Wrapper do conteúdo */
    #resultados-busca-pai .list-group-item > div {
        position: relative;
        z-index: 2;
    }
    
    /* Número do processo - destaque */
    #resultados-busca-pai .list-group-item h6 {
        font-size: 15px !important;
        font-weight: 700 !important;
        color: #2c3e50 !important;
        margin: 0 0 6px 0 !important;
        letter-spacing: 0.2px;
        line-height: 1.4;
        transition: color 0.2s ease;
    }
    
    #resultados-busca-pai .list-group-item:hover h6 {
        color: #007bff !important;
    }
    
    /* Nome do cliente */
    #resultados-busca-pai .list-group-item p {
        font-size: 14px !important;
        font-weight: 500 !important;
        color: #495057 !important;
        margin: 0 0 8px 0 !important;
        line-height: 1.5;
    }
    
    /* ==================== BADGES - MELHORIAS ==================== */
    
    /* Container dos badges - melhor alinhamento */
    #resultados-busca-pai .list-group-item > div > div {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        align-items: center;
        margin-top: 2px;
    }
    
    /* Badges individuais */
    .processo-info-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 4px 11px !important;
        font-size: 11.5px !important;
        font-weight: 600 !important;
        border-radius: 16px !important;
        margin: 0 !important;
        transition: all 0.2s ease;
        white-space: nowrap;
        line-height: 1.2;
    }
    
    /* Badge secundário (situação) */
    .badge-secondary {
        background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%) !important;
        color: white !important;
        box-shadow: 0 1px 3px rgba(108, 117, 125, 0.25);
    }
    
    #resultados-busca-pai .list-group-item:hover .badge-secondary {
        transform: translateY(-1px);
        box-shadow: 0 2px 5px rgba(108, 117, 125, 0.35);
    }
    
    /* Badge info (núcleo) */
    .badge-info {
        background: linear-gradient(135deg, #17a2b8 0%, #138496 100%) !important;
        color: white !important;
        box-shadow: 0 1px 3px rgba(23, 162, 184, 0.25);
    }
    
    #resultados-busca-pai .list-group-item:hover .badge-info {
        transform: translateY(-1px);
        box-shadow: 0 2px 5px rgba(23, 162, 184, 0.35);
    }
    
    /* ==================== TEXTO RESPONSÁVEL ==================== */
    
    /* Separador e texto do responsável */
    #resultados-busca-pai .list-group-item small {
        font-size: 12.5px !important;
        color: #6c757d !important;
        font-weight: 500;
        margin-left: 2px;
    }
    
    #resultados-busca-pai .list-group-item small::before {
        content: '•';
        margin: 0 6px;
        color: #dee2e6;
    }
    
    /* ==================== MELHORIAS NO LAYOUT ==================== */
    
    /* Garantir que o layout seja consistente */
    #resultados-busca-pai .d-flex {
        display: flex;
        flex-direction: column;
        gap: 0;
    }
    
    #resultados-busca-pai .w-100 {
        width: 100%;
    }
    
    #resultados-busca-pai .flex-grow-1 {
        flex-grow: 1;
    }
    
    /* ==================== ANIMAÇÃO DE ENTRADA ==================== */
    
    /* Animação mais suave */
    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-8px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    #resultados-busca-pai {
        animation: slideDown 0.25s ease-out;
    }
    
    /* ==================== SCROLLBAR - AJUSTE ==================== */
    
    #resultados-busca-pai::-webkit-scrollbar {
        width: 8px;
    }
    
    #resultados-busca-pai::-webkit-scrollbar-track {
        background: #f8f9fa;
        border-radius: 0 12px 12px 0;
    }
    
    #resultados-busca-pai::-webkit-scrollbar-thumb {
        background: linear-gradient(180deg, #007bff 0%, #0056b3 100%);
        border-radius: 8px;
        border: 2px solid #f8f9fa;
    }
    
    #resultados-busca-pai::-webkit-scrollbar-thumb:hover {
        background: linear-gradient(180deg, #0056b3 0%, #004085 100%);
    }
    
    /* ==================== RESPONSIVIDADE AJUSTADA ==================== */
    
    @media (max-width: 768px) {
        #resultados-busca-pai .list-group-item {
            padding: 14px 16px !important;
        }
        
        #resultados-busca-pai .list-group-item:hover {
            padding-left: 20px !important;
        }
        
        #resultados-busca-pai .list-group-item h6 {
            font-size: 14px !important;
        }
        
        #resultados-busca-pai .list-group-item p {
            font-size: 13px !important;
        }
        
        .processo-info-badge {
            padding: 3px 9px !important;
            font-size: 10.5px !important;
        }
    }
    
    /* ==================== ESTADO SEM RESULTADOS ==================== */
    
    #resultados-busca-pai .text-muted {
        cursor: default !important;
        padding: 28px 20px !important;
        text-align: center;
        background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%) !important;
        color: #6c757d !important;
        font-size: 13.5px;
        font-weight: 500;
        border: 2px dashed #dee2e6;
        border-radius: 8px;
        margin: 8px;
    }
    
    #resultados-busca-pai .text-muted i {
        display: inline-block;
        margin-right: 6px;
        color: #007bff;
        font-size: 15px;
    }
    
    #resultados-busca-pai .text-muted:hover {
        background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%) !important;
        padding: 28px 20px !important;
        transform: none !important;
    }
    
    /* ==================== FIM DOS AJUSTES ==================== */
</style>

<div class="content">
	<div class="page-header">
		<h2>Processo: <?= htmlspecialchars($processo['numero_processo']) ?></h2>
		<div class="header-actions">
			<a href="index.php" class="btn-voltar">← Voltar</a>
			<a href="editar.php?id=<?= $processo['id'] ?>" class="btn-editar">Editar</a>
			<a href="novo_resultado.php?id=<?= $processo['id'] ?>" class="btn-resultado">+ Resultado</a>
		</div>
	</div>

	<div class="info-grid">
		<!-- Dados do Processo -->
		<div class="info-card">
			<h3>📋 Dados do Processo</h3>

			<div class="info-item">
				<div class="info-label">Número do Processo:</div>
				<div class="info-value">
					<strong><?= htmlspecialchars($processo['numero_processo']) ?></strong>
				</div>
			</div>

			<div class="info-item">
				<div class="info-label">Tipo de Processo:</div>
				<div class="info-value">
					<?= htmlspecialchars($processo['tipo_processo']) ?>
				</div>
			</div>

			<div class="info-item">
				<div class="info-label">Situação Processual:</div>
				<div class="info-value">
					<span class="badge badge-<?= strtolower(str_replace([' ', 'ã', 'ç'], ['', 'a', 'c'], $processo['situacao_processual'])) ?>">
						<?= $processo['situacao_processual'] ?>
					</span>
				</div>
			</div>

			<div class="info-item">
				<div class="info-label">Comarca:</div>
				<div class="info-value">
					<?= $processo['comarca'] ? htmlspecialchars($processo['comarca']) : '<span class="empty-value">Não informada</span>' ?>
				</div>
			</div>

			<div class="info-item">
				<div class="info-label">Fase Atual:</div>
				<div class="info-value">
					<?= $processo['fase_atual'] ? htmlspecialchars($processo['fase_atual']) : '<span class="empty-value">Não informada</span>' ?>
				</div>
			</div>

			<div class="info-item">
				<div class="info-label">Responsável:</div>
				<div class="info-value">
					<?= htmlspecialchars($processo['responsavel_nome']) ?>
				</div>
			</div>
		</div>

		<!-- Dados das Partes -->
		<div class="info-card">
			<h3>👥 Partes do Processo</h3>

			<?php if (!empty($nossos_clientes)): ?>
			<div style="background: rgba(40, 167, 69, 0.1); border: 1px solid rgba(40, 167, 69, 0.3); border-radius: 8px; padding: 15px; margin-bottom: 15px;">
				<strong style="color: #155724; display: block; margin-bottom: 10px;">✓ Nosso(s) Cliente(s):</strong>
				<?php foreach ($nossos_clientes as $cliente): ?>
				<div style="margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid rgba(0,0,0,0.1);">
					<div style="font-weight: 600; color: #1a1a1a; margin-bottom: 5px;">
						<?= htmlspecialchars($cliente['nome']) ?>
						<span class="badge" style="background: #28a745; color: white; font-size: 10px; padding: 3px 8px; margin-left: 5px;">
							<?= htmlspecialchars($cliente['tipo_parte']) ?>
						</span>
					</div>

					<?php if ($cliente['cliente_id']): ?>
						<small style="color: #28a745; display: block;">📋 Cliente Cadastrado</small>
						<?php if ($cliente['telefone']): ?>
							<small style="color: #666; display: block;">📞 <?= htmlspecialchars($cliente['telefone']) ?></small>
						<?php endif; ?>
						<?php if ($cliente['email']): ?>
							<small style="color: #666; display: block;">✉️ <?= htmlspecialchars($cliente['email']) ?></small>
						<?php endif; ?>
					<?php else: ?>
						<small style="color: #ffc107; display: block;">⚠️ Informado Manualmente</small>
					<?php endif; ?>

					<?php if ($cliente['observacoes']): ?>
						<small style="color: #666; display: block; margin-top: 5px;">
							💬 <?= htmlspecialchars($cliente['observacoes']) ?>
						</small>
					<?php endif; ?>
				</div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<?php if (!empty($partes_contrarias)): ?>
			<div style="background: rgba(108, 117, 125, 0.1); border: 1px solid rgba(108, 117, 125, 0.3); border-radius: 8px; padding: 15px;">
				<strong style="color: #495057; display: block; margin-bottom: 10px;">⚖️ Outras Partes do Processo:</strong>
				<?php foreach ($partes_contrarias as $contraria): ?>
				<div style="margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid rgba(0,0,0,0.1);">
					<div style="font-weight: 600; color: #1a1a1a; margin-bottom: 5px;">
						<?= htmlspecialchars($contraria['nome']) ?>
						<span class="badge" style="background: #6c757d; color: white; font-size: 10px; padding: 3px 8px; margin-left: 5px;">
							<?= htmlspecialchars($contraria['tipo_parte']) ?>
						</span>
					</div>

					<?php if ($contraria['observacoes']): ?>
						<small style="color: #666; display: block; margin-top: 5px;">
							💬 <?= htmlspecialchars($contraria['observacoes']) ?>
						</small>
					<?php endif; ?>
				</div>
				<?php endforeach; ?>
			</div>
			<?php endif; ?>

			<?php if (empty($nossos_clientes) && empty($partes_contrarias)): ?>
			<div class="empty-value">Nenhuma parte cadastrada</div>
			<?php endif; ?>
		</div>
		
		<!-- Card de Relacionamentos MELHORADO -->
        <div class="info-card">
            <h3>
                <i class="fas fa-link"></i>
                Processos Relacionados
                <?php
                $relacionamentos = ProcessoRelacionamento::listarPorProcesso($processo['id']);
                $total_relacionamentos = count($relacionamentos);
                if ($total_relacionamentos > 0):
                ?>
                    <span style="display: inline-flex; align-items: center; justify-content: center; min-width: 28px; height: 28px; padding: 0 10px; background: linear-gradient(135deg, #007bff 0%, #0056b3 100%); color: white; border-radius: 20px; font-size: 13px; font-weight: 700; box-shadow: 0 2px 6px rgba(0, 123, 255, 0.3); margin-left: 10px;">
                        <?= $total_relacionamentos ?>
                    </span>
                <?php endif; ?>
            </h3>
            
            <?php if (empty($relacionamentos)): ?>
                <!-- Estado vazio -->
                <div style="text-align: center; padding: 40px 20px; background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%); border: 2px dashed #dee2e6; border-radius: 10px; color: #6c757d;">
                    <i class="fas fa-link" style="font-size: 48px; color: #dee2e6; margin-bottom: 16px; display: block;"></i>
                    <p style="font-size: 15px; font-weight: 500; margin-bottom: 20px; color: #495057;">
                        Nenhum processo relacionado encontrado
                    </p>
                    <button type="button" 
                            style="display: inline-flex; align-items: center; gap: 8px; padding: 12px 20px; background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);"
                            onclick="abrirModalRelacionamento(<?= $processo['id'] ?>)"
                            onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 16px rgba(40, 167, 69, 0.4)';"
                            onmouseout="this.style.transform=''; this.style.boxShadow='0 4px 12px rgba(40, 167, 69, 0.3)';">
                        <i class="fas fa-plus" style="font-size: 16px;"></i>
                        Adicionar Relacionamento
                    </button>
                </div>
            <?php else: ?>
                <?php
                // Organizar por tipo
                $processos_origem = [];
                $processos_derivados = [];
                
                foreach ($relacionamentos as $rel) {
                    if ($rel['direcao'] === 'destino_para_origem') {
                        $processos_origem[] = $rel;
                    } else {
                        $processos_derivados[] = $rel;
                    }
                }
                ?>
                
                <div style="display: flex; flex-direction: column; gap: 12px;">
                    
                    <?php if (!empty($processos_origem)): ?>
                        <!-- Seção: Processos de Origem -->
                        <div style="margin-bottom: 24px;">
                            <div style="font-size: 16px; font-weight: 700; color: #495057; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 2px solid #e9ecef; display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-arrow-left" style="color: #007bff;"></i>
                                Processo de Origem
                            </div>
                            
                            <?php foreach ($processos_origem as $rel): ?>
                                <div style="background: #f8f9fa; border: 2px solid #e9ecef; border-radius: 10px; padding: 16px 18px; transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); position: relative; overflow: hidden; margin-bottom: 12px;"
                                     onmouseover="this.style.background='white'; this.style.borderColor='#28a745'; this.style.transform='translateX(4px)'; this.style.boxShadow='0 4px 12px rgba(40, 167, 69, 0.12)'; this.querySelector('.barra-verde').style.width='5px';"
                                     onmouseout="this.style.background='#f8f9fa'; this.style.borderColor='#e9ecef'; this.style.transform=''; this.style.boxShadow=''; this.querySelector('.barra-verde').style.width='0';">
                                    
                                    <!-- Barra lateral verde -->
                                    <div class="barra-verde" style="content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 0; background: linear-gradient(180deg, #28a745 0%, #20c997 100%); transition: width 0.3s ease;"></div>
                                    
                                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; position: relative; z-index: 2;">
                                        <div style="flex: 1;">
                                            <!-- Número do Processo -->
                                            <a href="visualizar.php?id=<?= $rel['processo_destino_id'] ?>" 
                                               style="font-size: 15px; font-weight: 700; color: #2c3e50; margin-bottom: 6px; transition: color 0.2s ease; display: block; text-decoration: none;"
                                               onmouseover="this.style.color='#28a745';"
                                               onmouseout="this.style.color='#2c3e50';">
                                                <?= htmlspecialchars($rel['numero_processo_destino']) ?>
                                            </a>
                                            
                                            <!-- Badges -->
                                            <div style="display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-top: 8px;">
                                                <span style="display: inline-flex; align-items: center; padding: 5px 12px; font-size: 12px; font-weight: 600; border-radius: 16px; background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; box-shadow: 0 2px 4px rgba(40, 167, 69, 0.25); transition: all 0.2s ease;"
                                                      onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 3px 6px rgba(40, 167, 69, 0.35)';"
                                                      onmouseout="this.style.transform=''; this.style.boxShadow='0 2px 4px rgba(40, 167, 69, 0.25)';">
                                                    <?= htmlspecialchars($rel['tipo_relacionamento']) ?>
                                                </span>
                                            </div>
                                            
                                            <!-- Descrição (se houver) -->
                                            <?php if (!empty($rel['descricao'])): ?>
                                                <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #e9ecef; font-size: 13px; color: #6c757d; line-height: 1.6; font-style: italic;">
                                                    <?= htmlspecialchars($rel['descricao']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <!-- Ações -->
                                        <div style="display: flex; gap: 8px; flex-shrink: 0;">
                                            <a href="visualizar.php?id=<?= $rel['processo_destino_id'] ?>" 
                                               style="display: inline-flex; align-items: center; justify-content: center; padding: 8px 14px; background: linear-gradient(135deg, #007bff 0%, #0056b3 100%); color: white; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 6px rgba(0, 123, 255, 0.3); white-space: nowrap;"
                                               title="Visualizar Processo"
                                               onmouseover="this.style.background='linear-gradient(135deg, #0056b3 0%, #004085 100%)'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 10px rgba(0, 123, 255, 0.4)';"
                                               onmouseout="this.style.background='linear-gradient(135deg, #007bff 0%, #0056b3 100%)'; this.style.transform=''; this.style.boxShadow='0 2px 6px rgba(0, 123, 255, 0.3)';">
                                                <i class="fas fa-eye" style="margin-right: 6px; font-size: 14px;"></i> Ver
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($processos_derivados)): ?>
                        <!-- Seção: Processos Derivados -->
                        <div style="margin-bottom: 24px;">
                            <div style="font-size: 16px; font-weight: 700; color: #495057; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 2px solid #e9ecef; display: flex; align-items: center; gap: 8px;">
                                <i class="fas fa-arrow-right" style="color: #007bff;"></i>
                                Processos Derivados
                            </div>
                            
                            <?php foreach ($processos_derivados as $rel): ?>
                                <div style="background: #f8f9fa; border: 2px solid #e9ecef; border-radius: 10px; padding: 16px 18px; transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1); position: relative; overflow: hidden; margin-bottom: 12px;"
                                     onmouseover="this.style.background='white'; this.style.borderColor='#007bff'; this.style.transform='translateX(4px)'; this.style.boxShadow='0 4px 12px rgba(0, 123, 255, 0.12)'; this.querySelector('.barra-azul').style.width='5px';"
                                     onmouseout="this.style.background='#f8f9fa'; this.style.borderColor='#e9ecef'; this.style.transform=''; this.style.boxShadow=''; this.querySelector('.barra-azul').style.width='0';">
                                    
                                    <!-- Barra lateral azul -->
                                    <div class="barra-azul" style="content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 0; background: linear-gradient(180deg, #007bff 0%, #0056b3 100%); transition: width 0.3s ease;"></div>
                                    
                                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; position: relative; z-index: 2;">
                                        <div style="flex: 1;">
                                            <!-- Número do Processo -->
                                            <a href="visualizar.php?id=<?= $rel['processo_destino_id'] ?>" 
                                               style="font-size: 15px; font-weight: 700; color: #2c3e50; margin-bottom: 6px; transition: color 0.2s ease; display: block; text-decoration: none;"
                                               onmouseover="this.style.color='#007bff';"
                                               onmouseout="this.style.color='#2c3e50';">
                                                <?= htmlspecialchars($rel['numero_processo_destino']) ?>
                                            </a>
                                            
                                            <!-- Badges -->
                                            <div style="display: flex; flex-wrap: wrap; gap: 6px; align-items: center; margin-top: 8px;">
                                                <span style="display: inline-flex; align-items: center; padding: 5px 12px; font-size: 12px; font-weight: 600; border-radius: 16px; background: linear-gradient(135deg, #007bff 0%, #0056b3 100%); color: white; box-shadow: 0 2px 4px rgba(0, 123, 255, 0.25); transition: all 0.2s ease;"
                                                      onmouseover="this.style.transform='translateY(-1px)'; this.style.boxShadow='0 3px 6px rgba(0, 123, 255, 0.35)';"
                                                      onmouseout="this.style.transform=''; this.style.boxShadow='0 2px 4px rgba(0, 123, 255, 0.25)';">
                                                    <?= htmlspecialchars($rel['tipo_relacionamento']) ?>
                                                </span>
                                            </div>
                                            
                                            <!-- Descrição (se houver) -->
                                            <?php if (!empty($rel['descricao'])): ?>
                                                <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #e9ecef; font-size: 13px; color: #6c757d; line-height: 1.6; font-style: italic;">
                                                    <?= htmlspecialchars($rel['descricao']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <!-- Ações -->
                                        <div style="display: flex; gap: 8px; flex-shrink: 0;">
                                            <a href="visualizar.php?id=<?= $rel['processo_destino_id'] ?>" 
                                               style="display: inline-flex; align-items: center; justify-content: center; padding: 8px 14px; background: linear-gradient(135deg, #007bff 0%, #0056b3 100%); color: white; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; text-decoration: none; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 2px 6px rgba(0, 123, 255, 0.3); white-space: nowrap;"
                                               title="Visualizar Processo"
                                               onmouseover="this.style.background='linear-gradient(135deg, #0056b3 0%, #004085 100%)'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 4px 10px rgba(0, 123, 255, 0.4)';"
                                               onmouseout="this.style.background='linear-gradient(135deg, #007bff 0%, #0056b3 100%)'; this.style.transform=''; this.style.boxShadow='0 2px 6px rgba(0, 123, 255, 0.3)';">
                                                <i class="fas fa-eye" style="margin-right: 6px; font-size: 14px;"></i> Ver
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Botão Adicionar (quando já tem relacionamentos) -->
                <button type="button" 
                        style="display: inline-flex; align-items: center; gap: 8px; padding: 12px 20px; background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; transition: all 0.3s ease; box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3); margin-top: 16px;"
                        onclick="abrirModalRelacionamento(<?= $processo['id'] ?>)"
                        onmouseover="this.style.background='linear-gradient(135deg, #20c997 0%, #17a589 100%)'; this.style.transform='translateY(-2px)'; this.style.boxShadow='0 6px 16px rgba(40, 167, 69, 0.4)';"
                        onmouseout="this.style.background='linear-gradient(135deg, #28a745 0%, #20c997 100%)'; this.style.transform=''; this.style.boxShadow='0 4px 12px rgba(40, 167, 69, 0.3)';">
                    <i class="fas fa-plus" style="font-size: 16px;"></i>
                    Adicionar Novo Relacionamento
                </button>
            <?php endif; ?>
        </div>

		<!-- Anotações -->
		<?php if ($processo['anotacoes']): ?>
		<div class="info-card">
			<h3>📝 Anotações</h3>
			<div style="background: rgba(26, 26, 26, 0.02); border: 1px solid rgba(0,0,0,0.1); border-radius: 8px; padding: 15px; color: #444; line-height: 1.6; white-space: pre-line;">
				<?= htmlspecialchars($processo['anotacoes']) ?>
			</div>
		</div>
		<?php endif; ?>
	</div>
	
	<!-- Informações Financeiras -->
	<?php
	// Buscar informações financeiras
	$sql_fin = "SELECT * FROM processo_financeiro WHERE processo_id = ?";
	$stmt_fin = executeQuery($sql_fin, [$processo_id]);
	$financeiro = $stmt_fin->fetch();

	// Buscar receitas
	$sql_rec = "SELECT * FROM processo_receitas WHERE processo_id = ? ORDER BY data_recebimento DESC";
	$stmt_rec = executeQuery($sql_rec, [$processo_id]);
	$receitas = $stmt_rec->fetchAll();

	if ($financeiro):
	?>
	<div class="full-width-card" style="border-left: 4px solid #28a745;">
		<h3>💰 Informações Financeiras</h3>

		<div class="form-grid" style="grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));">
			<div class="info-item">
				<div class="info-label">Forma de Pagamento:</div>
				<div class="info-value">
					<span class="badge" style="background: #17a2b8; color: white;">
						<?= htmlspecialchars($financeiro['forma_pagamento']) ?>
					</span>
				</div>
			</div>

			<?php if ($financeiro['valor_honorarios']): ?>
			<div class="info-item">
				<div class="info-label">Valor dos Honorários:</div>
				<div class="info-value" style="font-size: 20px; color: #28a745; font-weight: 700;">
					R$ <?= number_format($financeiro['valor_honorarios'], 2, ',', '.') ?>
				</div>
			</div>
			<?php endif; ?>

			<?php if ($financeiro['porcentagem_exito']): ?>
			<div class="info-item">
				<div class="info-label">Porcentagem sobre Êxito:</div>
				<div class="info-value" style="font-size: 20px; color: #ffc107; font-weight: 700;">
					<?= number_format($financeiro['porcentagem_exito'], 2, ',', '.') ?>%
				</div>
			</div>
			<?php endif; ?>

			<?php if ($financeiro['valor_entrada']): ?>
			<div class="info-item">
				<div class="info-label">Valor de Entrada:</div>
				<div class="info-value">
					R$ <?= number_format($financeiro['valor_entrada'], 2, ',', '.') ?>
				</div>
			</div>
			<?php endif; ?>

			<?php if ($financeiro['numero_parcelas']): ?>
			<div class="info-item">
				<div class="info-label">Parcelamento:</div>
				<div class="info-value">
					<?= $financeiro['numero_parcelas'] ?>x de R$ <?= number_format($financeiro['valor_parcela'], 2, ',', '.') ?>
				</div>
			</div>
			<?php endif; ?>

			<?php if ($financeiro['data_vencimento_primeira_parcela']): ?>
			<div class="info-item">
				<div class="info-label">Vencimento 1ª Parcela:</div>
				<div class="info-value">
					<?= date('d/m/Y', strtotime($financeiro['data_vencimento_primeira_parcela'])) ?>
				</div>
			</div>
			<?php endif; ?>
		</div>

		<?php
		// Calcular totais
		$total_recebido = array_sum(array_column($receitas, 'valor'));
		$saldo_restante = $financeiro['valor_honorarios'] ? ($financeiro['valor_honorarios'] - $total_recebido) : 0;
		?>

		<div style="background: rgba(40, 167, 69, 0.1); border: 1px solid rgba(40, 167, 69, 0.3); border-radius: 8px; padding: 15px; margin-top: 20px;">
			<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
				<div>
					<div style="font-size: 12px; color: #155724; margin-bottom: 5px;">Total Recebido:</div>
					<div style="font-size: 24px; color: #28a745; font-weight: 700;">
						R$ <?= number_format($total_recebido, 2, ',', '.') ?>
					</div>
				</div>

				<?php if ($financeiro['valor_honorarios']): ?>
				<div>
					<div style="font-size: 12px; color: #856404; margin-bottom: 5px;">Saldo Restante:</div>
					<div style="font-size: 24px; color: #ffc107; font-weight: 700;">
						R$ <?= number_format($saldo_restante, 2, ',', '.') ?>
					</div>
				</div>
				<?php endif; ?>
			</div>
		</div>

		<?php if ($financeiro['observacoes_financeiras']): ?>
		<div style="margin-top: 15px; padding: 10px; background: rgba(0,0,0,0.02); border-radius: 5px;">
			<strong>Observações:</strong><br>
			<?= nl2br(htmlspecialchars($financeiro['observacoes_financeiras'])) ?>
		</div>
		<?php endif; ?>

		<?php if (!empty($receitas)): ?>
		<h4 style="margin-top: 25px; margin-bottom: 15px; color: #1a1a1a;">💳 Histórico de Receitas</h4>

		<?php foreach ($receitas as $receita): ?>
		<div style="background: white; border: 1px solid #e9ecef; border-radius: 8px; padding: 15px; margin-bottom: 10px;">
			<div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
				<div>
					<span class="badge" style="background: #28a745; color: white;">
						<?= htmlspecialchars($receita['tipo_receita']) ?>
					</span>
					<?php if ($receita['numero_parcela']): ?>
					<span style="color: #666; font-size: 12px;">Parcela <?= $receita['numero_parcela'] ?></span>
					<?php endif; ?>
				</div>

				<div style="font-size: 18px; color: #28a745; font-weight: 700;">
					R$ <?= number_format($receita['valor'], 2, ',', '.') ?>
				</div>
			</div>

			<div style="margin-top: 10px; font-size: 13px; color: #666;">
				<strong>Data:</strong> <?= date('d/m/Y', strtotime($receita['data_recebimento'])) ?> | 
				<strong>Forma:</strong> <?= htmlspecialchars($receita['forma_recebimento']) ?>
				<?php if ($receita['observacoes']): ?>
				<br><strong>Obs:</strong> <?= htmlspecialchars($receita['observacoes']) ?>
				<?php endif; ?>
			</div>
		</div>
		<?php endforeach; ?>
		<?php endif; ?>
	</div>
	<?php endif; ?>

	<!-- Resultados do Processo -->
	<div class="full-width-card">
		<h3>🎯 Resultados do Processo</h3>

		<?php if (empty($resultados)): ?>
		<div class="empty-state">
			<p>Nenhum resultado registrado para este processo.</p>
		</div>
		<?php else: ?>
		<?php foreach ($resultados as $resultado): ?>
		<div class="resultado-item <?= strtolower($resultado['tipo_resultado']) ?>">
			<div class="resultado-header">
				<span class="badge badge-<?= strtolower($resultado['tipo_resultado']) ?>">
					<?= $resultado['tipo_resultado'] ?>
				</span>
				<span class="resultado-data">
					<?= date('d/m/Y', strtotime($resultado['data_resultado'])) ?>
				</span>
			</div>

			<div class="resultado-descricao">
				<?= htmlspecialchars($resultado['descricao_resultado']) ?>
			</div>

			<?php if ($resultado['data_entrega_cliente']): ?>
			<div class="resultado-entrega">
				<strong>Entregue ao cliente em:</strong> 
				<?= date('d/m/Y', strtotime($resultado['data_entrega_cliente'])) ?>
			</div>
			<?php endif; ?>

			<?php if ($resultado['observacoes']): ?>
			<div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid rgba(0,0,0,0.1); font-size: 14px; color: #666;">
				<strong>Observações:</strong> <?= htmlspecialchars($resultado['observacoes']) ?>
			</div>
			<?php endif; ?>

			<div class="timeline-meta">
				Registrado por <?= htmlspecialchars($resultado['criado_por_nome']) ?> em 
				<?= date('d/m/Y H:i', strtotime($resultado['data_criacao'])) ?>
			</div>
		</div>
		<?php endforeach; ?>
		<?php endif; ?>
	</div>

	<!-- Sistema de Abas: Movimentações e Histórico -->
<div class="tabs-container">
    <div class="tabs-nav">
        <button class="tab-button active" onclick="openTab(event, 'movimentacoes')">
            📈 Edições
            <?php if (!empty($movimentacoes)): ?>
            <span class="badge-count"><?= count($movimentacoes) ?></span>
            <?php endif; ?>
        </button>
        <button class="tab-button" onclick="openTab(event, 'historico')">
            📜 Histórico
            <?php if (!empty($historico_completo)): ?>
            <span class="badge-count"><?= count($historico_completo) ?></span>
            <?php endif; ?>
        </button>
    </div>
</div>

<!-- Aba: Movimentações -->
<div id="movimentacoes" class="tab-content active">
    <?php if (empty($movimentacoes)): ?>
    <div class="empty-state">
        <p>Nenhuma movimentação registrada para este processo.</p>
    </div>
    <?php else: ?>
    <div class="timeline">
        <?php foreach ($movimentacoes as $movimentacao): ?>
        <div class="timeline-item">
            <div class="timeline-date">
                <?= date('d/m/Y', strtotime($movimentacao['data_movimentacao'])) ?>
            </div>

            <div class="timeline-content">
                <h4><?= htmlspecialchars($movimentacao['descricao']) ?></h4>

                <?php if ($movimentacao['fase_anterior'] && $movimentacao['fase_nova']): ?>
                <p>
                    <strong>Mudança de fase:</strong> 
                    <?= htmlspecialchars($movimentacao['fase_anterior']) ?> → 
                    <?= htmlspecialchars($movimentacao['fase_nova']) ?>
                </p>
                <?php elseif ($movimentacao['fase_nova']): ?>
                <p>
                    <strong>Nova fase:</strong> 
                    <?= htmlspecialchars($movimentacao['fase_nova']) ?>
                </p>
                <?php endif; ?>

                <?php if ($movimentacao['responsavel_anterior'] && $movimentacao['responsavel_novo']): ?>
                <p>
                    <strong>Mudança de responsável:</strong> 
                    <?= htmlspecialchars($movimentacao['responsavel_anterior_nome']) ?> → 
                    <?= htmlspecialchars($movimentacao['responsavel_novo_nome']) ?>
                </p>
                <?php elseif ($movimentacao['responsavel_novo']): ?>
                <p>
                    <strong>Responsável:</strong> 
                    <?= htmlspecialchars($movimentacao['responsavel_novo_nome']) ?>
                </p>
                <?php endif; ?>

                <?php if ($movimentacao['observacoes']): ?>
                <p>
                    <strong>Observações:</strong> 
                    <?= htmlspecialchars($movimentacao['observacoes']) ?>
                </p>
                <?php endif; ?>
            </div>

            <div class="timeline-meta">
                Registrado por <?= htmlspecialchars($movimentacao['criado_por_nome']) ?> em 
                <?= date('d/m/Y H:i', strtotime($movimentacao['data_criacao'])) ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Aba: Histórico Completo -->
<div id="historico" class="tab-content">
    <?php if (empty($historico_completo)): ?>
    <div class="empty-state">
        <p>📋 Nenhum registro no histórico deste processo.</p>
        <p style="font-size: 14px; color: #999; margin-top: 10px;">
            O histórico registra automaticamente todas as ações relacionadas ao processo, como:<br>
            • Publicações tratadas<br>
            • Tarefas criadas<br>
            • Prazos cadastrados<br>
            • Audiências agendadas<br>
            • Alterações e movimentações
        </p>
    </div>
    <?php else: ?>
    <?php 
    $tipo_icons = [
        'publicacao' => '📰',
        'tarefa' => '✅',
        'prazo' => '⏰',
        'audiencia' => '📅',
        'movimentacao' => '📈',
        'alteracao' => '✏️',
        'outro' => '📌'
    ];
    ?>
    <?php foreach ($historico_completo as $hist): ?>
    <div class="historico-item tipo-<?= $hist['tipo_referencia'] ?>">
        <div class="historico-header">
            <div class="historico-acao">
                <?= $tipo_icons[$hist['tipo_referencia']] ?? '📌' ?>
                <?= htmlspecialchars($hist['acao']) ?>
            </div>
            <div class="historico-data">
                <?= date('d/m/Y H:i', strtotime($hist['data_acao'])) ?>
            </div>
        </div>

        <div class="historico-descricao">
            <?= nl2br(htmlspecialchars($hist['descricao'])) ?>
        </div>

        <?php if ($hist['referencia_id']): ?>
        <div style="margin-top: 10px;">
            <?php
            $ref_links = [
                'publicacao' => '../publicacoes/visualizar.php?id=',
                'tarefa' => '../agenda/?view=tarefas#tarefa-',
                'prazo' => '../agenda/?view=prazos#prazo-',
                'audiencia' => '../agenda/?view=audiencias#audiencia-'
            ];
            
            if (isset($ref_links[$hist['tipo_referencia']])):
            ?>
            <a href="<?= $ref_links[$hist['tipo_referencia']] . $hist['referencia_id'] ?>" 
               style="color: #007bff; text-decoration: none; font-size: 13px; font-weight: 600;"
               target="_blank">
                🔗 Ver detalhes →
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="historico-meta">
            <span>
                👤 <?= htmlspecialchars($hist['usuario_nome']) ?>
            </span>
            <span class="historico-tipo-badge tipo-<?= $hist['tipo_referencia'] ?>-badge">
                <?= ucfirst($hist['tipo_referencia']) ?>
            </span>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
function openTab(evt, tabName) {
    // Esconder todos os conteúdos
    const tabContents = document.getElementsByClassName('tab-content');
    for (let i = 0; i < tabContents.length; i++) {
        tabContents[i].classList.remove('active');
    }
    
    // Remover classe active de todos os botões
    const tabButtons = document.getElementsByClassName('tab-button');
    for (let i = 0; i < tabButtons.length; i++) {
        tabButtons[i].classList.remove('active');
    }
    
    // Mostrar o conteúdo atual
    document.getElementById(tabName).classList.add('active');
    evt.currentTarget.classList.add('active');
}

function abrirModalRelacionamento(processoId) {
    console.log('🔵 Abrindo modal para processo:', processoId);
    
    const modal = document.getElementById('modalRelacionamentos');
    
    if (!modal) {
        console.error('❌ Modal não encontrado!');
        alert('Erro: Modal de relacionamentos não foi carregado.');
        return;
    }
    
    console.log('✅ Modal encontrado:', modal);
    
    // Definir o processo ID
    const processoIdInput = modal.querySelector('#processo_id_modal') || 
                           modal.querySelector('input[name="processo_id"]') ||
                           modal.querySelector('[data-processo-id]');
    
    if (processoIdInput) {
        if (processoIdInput.dataset) {
            processoIdInput.dataset.processoId = processoId;
        }
        if (processoIdInput.value !== undefined) {
            processoIdInput.value = processoId;
        }
        console.log('✅ Processo ID definido:', processoId);
    }
    
    if (typeof carregarRelacionamentosExistentes === 'function') {
        carregarRelacionamentosExistentes(processoId);
    }
    
    // Resto do código para abrir o modal...
    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        console.log('🟢 Usando Bootstrap 5');
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
        return;
    }
    
    // Tentar Bootstrap 4 com jQuery
    if (typeof $ !== 'undefined' && $.fn && $.fn.modal) {
        console.log('🟡 Usando Bootstrap 4 + jQuery');
        $(modal).modal('show');
        return;
    }
    
    // Fallback: Abrir modal manualmente (sem Bootstrap)
    console.log('🟠 Usando método manual (sem Bootstrap)');
    
    // Mostrar modal
    modal.style.display = 'block';
    modal.classList.add('show');
    modal.setAttribute('aria-modal', 'true');
    modal.setAttribute('role', 'dialog');
    modal.removeAttribute('aria-hidden');
    
    // Adicionar backdrop
    let backdrop = document.querySelector('.modal-backdrop');
    if (!backdrop) {
        backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop fade show';
        backdrop.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1040;';
        document.body.appendChild(backdrop);
        
        // Fechar ao clicar no backdrop
        backdrop.addEventListener('click', function() {
            fecharModalRelacionamento();
        });
    }
    
    // Adicionar classe ao body
    document.body.classList.add('modal-open');
    document.body.style.overflow = 'hidden';
    document.body.style.paddingRight = '0px';
    
    // Encontrar botão de fechar
    const closeButtons = modal.querySelectorAll('[data-dismiss="modal"], .close, .btn-close');
    closeButtons.forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            fecharModalRelacionamento();
        });
    });
    
    // Fechar com ESC
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            fecharModalRelacionamento();
        }
    });
    
    console.log('✅ Modal aberto manualmente');
}

function fecharModalRelacionamento() {
    const modal = document.getElementById('modalRelacionamentos');
    if (!modal) return;
    
    // Esconder modal
    modal.style.display = 'none';
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden', 'true');
    modal.removeAttribute('aria-modal');
    modal.removeAttribute('role');
    
    // Remover backdrop
    const backdrop = document.querySelector('.modal-backdrop');
    if (backdrop) {
        backdrop.remove();
    }
    
    // Remover classes do body
    document.body.classList.remove('modal-open');
    document.body.style.overflow = '';
    document.body.style.paddingRight = '';
    
    console.log('✅ Modal fechado');
}
</script>

<?php include __DIR__ . '/modal_relacionamentos.php'; ?>
<?php
$conteudo = ob_get_clean();

// Renderizar layout
echo renderLayout('Processos', $conteudo, 'processos');
?>