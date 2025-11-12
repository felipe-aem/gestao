<?php
// modules/publicacoes/visualizar.php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';
require_once '../../includes/layout.php';

$usuario_logado = Auth::user();
$usuario_id = $usuario_logado['usuario_id'];
$publicacao_id = $_GET['id'] ?? null;

if (!$publicacao_id) {
    header('Location: index.php');
    exit;
}

// Buscar publicação
$sql = "SELECT p.*, 
        pr.id as processo_id,
        pr.numero_processo as processo_numero,
        pr.cliente_nome as processo_cliente,
        pr.responsavel_id as processo_responsavel_id,
        u_resp.nome as processo_responsavel_nome,
        u_tratado.nome as tratado_por_nome
        FROM publicacoes p
        LEFT JOIN processos pr ON p.processo_id = pr.id
        LEFT JOIN usuarios u_resp ON pr.responsavel_id = u_resp.id
        LEFT JOIN usuarios u_tratado ON p.tratada_por_usuario_id = u_tratado.id
        WHERE p.id = ? AND p.deleted_at IS NULL";

$stmt = executeQuery($sql, [$publicacao_id]);
$pub = $stmt->fetch();

if (!$pub) {
    header('Location: index.php');
    exit;
}

// Buscar tratamentos
$sql_tratamentos = "SELECT pt.*, u.nome as usuario_nome
                    FROM publicacoes_tratamentos pt
                    INNER JOIN usuarios u ON pt.usuario_id = u.id
                    WHERE pt.publicacao_id = ?
                    ORDER BY pt.data_tratamento DESC";
$stmt_trat = executeQuery($sql_tratamentos, [$publicacao_id]);
$tratamentos = $stmt_trat->fetchAll();

// Decodificar JSON completo se existir
$json_data = null;
if ($pub['json_data_completo']) {
    $json_data = json_decode($pub['json_data_completo'], true);
}

ob_start();
?>
<script src="../../assets/js/toast.js">
	// Fechar modal ao clicar fora
	window.addEventListener('click', function(event) {
		const modal = document.getElementById('modalTratamento');
		if (event.target === modal) {
			fecharModalTratamento();
		}
	});
</script>
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
        font-size: 24px;
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

    .btn-back {
        background: #6c757d;
        color: white;
    }

    .btn-back:hover {
        background: #5a6268;
        transform: translateY(-2px);
    }

    .btn-treat {
        background: linear-gradient(135deg, #ffc107 0%, #ff9800 100%);
        color: #000;
        box-shadow: 0 4px 15px rgba(255, 193, 7, 0.3);
    }

    .btn-treat:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(255, 193, 7, 0.4);
    }

    .btn-complete {
        background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        color: white;
        box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
    }

    .btn-complete:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
    }

    .btn-discard {
        background: #dc3545;
        color: white;
    }

    .btn-discard:hover {
        background: #c82333;
        transform: translateY(-2px);
    }

    .btn-open {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
    }

    .btn-open:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
    }

    .status-banner {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        padding: 20px;
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        margin-bottom: 30px;
        display: flex;
        align-items: center;
        gap: 15px;
        border-left: 6px solid;
    }

    .status-banner.nao-tratado { border-left-color: #dc3545; }
    .status-banner.em-tratamento { border-left-color: #ffc107; }
    .status-banner.concluido { border-left-color: #28a745; }
    .status-banner.descartado { border-left-color: #6c757d; }

    .status-icon {
        font-size: 32px;
    }

    .status-info h3 {
        color: #1a1a1a;
        margin-bottom: 5px;
        font-size: 18px;
    }

    .status-info p {
        color: #666;
        font-size: 14px;
        margin: 0;
    }

    .content-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 30px;
    }

    .content-section {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        padding: 25px;
    }

    .section-title {
        color: #1a1a1a;
        font-size: 20px;
        font-weight: 700;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
        padding-bottom: 15px;
        border-bottom: 2px solid rgba(0,0,0,0.05);
    }

    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }

    .info-item {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .info-label {
        color: #666;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .info-value {
        color: #1a1a1a;
        font-size: 15px;
        font-weight: 500;
    }

    .info-value a {
        color: #667eea;
        text-decoration: none;
        transition: all 0.3s;
        display: inline-flex;
        align-items: center;
        gap: 5px;
    }

    .info-value a:hover {
        color: #764ba2;
        transform: translateX(3px);
    }

    .badge {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        display: inline-block;
    }

    .badge-vinculado { background: #17a2b8; color: white; }
    .badge-nao-vinculado { background: #ff6b6b; color: white; }

    .conteudo-box {
        background: rgba(0,0,0,0.02);
        padding: 20px;
        border-radius: 12px;
        border: 1px solid rgba(0,0,0,0.05);
        margin-top: 15px;
    }

    .conteudo-text {
        color: #333;
        line-height: 1.8;
        font-size: 14px;
        white-space: pre-wrap;
        word-wrap: break-word;
    }

    .processo-vinculado {
        background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
        padding: 20px;
        border-radius: 12px;
        border: 2px solid rgba(102, 126, 234, 0.3);
        margin-top: 15px;
    }

    .processo-vinculado h4 {
        color: #667eea;
        margin-bottom: 15px;
        font-size: 16px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .tratamentos-list {
        display: flex;
        flex-direction: column;
        gap: 15px;
    }

    .tratamento-item {
        background: rgba(0,0,0,0.02);
        padding: 15px;
        border-radius: 8px;
        border-left: 4px solid #667eea;
    }

    .tratamento-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 8px;
        flex-wrap: wrap;
        gap: 10px;
    }

    .tratamento-tipo {
        font-weight: 600;
        color: #1a1a1a;
        font-size: 14px;
    }

    .tratamento-data {
        color: #666;
        font-size: 12px;
    }

    .tratamento-usuario {
        color: #667eea;
        font-size: 13px;
        font-weight: 500;
    }

    .tratamento-obs {
        color: #555;
        font-size: 13px;
        line-height: 1.6;
        margin-top: 5px;
    }

    .empty-state {
        text-align: center;
        padding: 40px 20px;
        color: #999;
    }

    .empty-state svg {
        width: 60px;
        height: 60px;
        margin-bottom: 15px;
        opacity: 0.3;
    }

    .alert {
        padding: 15px 20px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-weight: 600;
    }

    .alert-warning {
        background: rgba(255, 193, 7, 0.1);
        border: 1px solid rgba(255, 193, 7, 0.3);
        color: #856404;
    }

    @media (max-width: 768px) {
        .page-header {
            flex-direction: column;
            text-align: center;
        }

        .info-grid {
            grid-template-columns: 1fr;
        }

        .header-actions {
            width: 100%;
            justify-content: center;
        }

        .btn {
            flex: 1;
            justify-content: center;
        }
    }
	
	/* Modal Tratamento */
	.modal {
		display: none;
		position: fixed;
		z-index: 2000;
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
</style>

<div class="page-header">
    <h2>
        <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
            <polyline points="14 2 14 8 20 8"></polyline>
        </svg>
        Detalhes da Publicação
    </h2>
    <div class="header-actions">
        <button onclick="fecharOuVoltar()" class="btn btn-back">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="19" y1="12" x2="5" y2="12"></line>
                <polyline points="12 19 5 12 12 5"></polyline>
            </svg>
            Voltar
        </button>

        <?php if ($pub['status_tratamento'] === 'nao_tratado'): ?>
            <button class="btn btn-treat" onclick="abrirModalTratamento(<?= $pub['id'] ?>)">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <line x1="12" y1="5" x2="12" y2="19"></line>
                    <line x1="5" y1="12" x2="19" y2="12"></line>
                </svg>
                Tratamento
            </button>

            <button class="btn btn-complete" onclick="abrirTratamento(<?= $pub['id'] ?>)">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
                Concluir
            </button>

            <button class="btn btn-discard" onclick="descartarPublicacao(<?= $pub['id'] ?>)">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="3 6 5 6 21 6"></polyline>
                    <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
                </svg>
                Descartar
            </button>
        <?php endif; ?>

        <?php if ($pub['numero_processo_cnj'] && $pub['tribunal']): ?>
            <button class="btn btn-open" onclick="abrirNoTribunal()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                    <polyline points="15 3 21 3 21 9"></polyline>
                    <line x1="10" y1="14" x2="21" y2="3"></line>
                </svg>
                Abrir no Tribunal
            </button>
        <?php endif; ?>
    </div>
</div>

<?php
$status_map = [
    'nao_tratado' => ['🔴', 'Não Tratada', 'Esta publicação ainda não recebeu nenhum tratamento'],
    'em_tratamento' => ['🟡', 'Em Tratamento', 'Publicação está sendo analisada'],
    'concluido' => ['🟢', 'Concluída', 'Publicação foi concluída com sucesso'],
    'descartado' => ['⚫', 'Descartada', 'Publicação foi descartada (duplicada ou irrelevante)']
];
$status_info = $status_map[$pub['status_tratamento']] ?? ['❓', 'Desconhecido', ''];
?>

<div class="status-banner <?= $pub['status_tratamento'] ?>">
    <div class="status-icon"><?= $status_info[0] ?></div>
    <div class="status-info">
        <h3><?= $status_info[1] ?></h3>
        <p><?= $status_info[2] ?></p>
        <?php if ($pub['tratado_por_nome']): ?>
            <p style="margin-top: 5px;"><strong>Tratado por:</strong> <?= htmlspecialchars($pub['tratado_por_nome']) ?> 
            em <?= date('d/m/Y H:i', strtotime($pub['data_tratamento'])) ?></p>
        <?php endif; ?>
    </div>
</div>

<!-- Alertas Melhorados -->
<?php if (!$pub['processo_id']): ?>
<div style="
    background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%);
    border: 2px solid #ffc107;
    border-left: 5px solid #ffc107;
    padding: 20px 25px;
    border-radius: 12px;
    margin-bottom: 25px;
    box-shadow: 0 4px 15px rgba(255, 193, 7, 0.2);
    display: flex;
    align-items: flex-start;
    gap: 15px;
">
    <div style="
        background: #ffc107;
        color: white;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        flex-shrink: 0;
        font-weight: bold;
    ">⚠️</div>
    <div style="flex: 1;">
        <h4 style="margin: 0 0 8px 0; color: #856404; font-size: 16px; font-weight: 700;">
            Atenção: Esta publicação não está vinculada a nenhum processo cadastrado no sistema.
        </h4>
        <p style="margin: 0; color: #856404; font-size: 14px; line-height: 1.5;">
            <strong>Processo:</strong> 
            <?php if ($pub['numero_processo_cnj']): ?>
                <?= htmlspecialchars($pub['numero_processo_cnj']) ?>
            <?php elseif ($pub['numero_processo_tj']): ?>
                <?= htmlspecialchars($pub['numero_processo_tj']) ?>
            <?php else: ?>
                Não informado
            <?php endif; ?>
        </p>
    </div>
</div>
<?php endif; ?>

<?php if ($pub['processo_id'] && !$pub['processo_responsavel_id']): ?>
<div style="
    background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
    border: 2px solid #dc3545;
    border-left: 5px solid #dc3545;
    padding: 20px 25px;
    border-radius: 12px;
    margin-bottom: 25px;
    box-shadow: 0 4px 15px rgba(220, 53, 69, 0.2);
    display: flex;
    align-items: flex-start;
    gap: 15px;
">
    <div style="
        background: #dc3545;
        color: white;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        flex-shrink: 0;
        font-weight: bold;
    ">🚨</div>
    <div style="flex: 1;">
        <h4 style="margin: 0 0 8px 0; color: #721c24; font-size: 16px; font-weight: 700;">
            Importante: Este processo não possui um responsável definido!
        </h4>
        <p style="margin: 0; color: #721c24; font-size: 14px; line-height: 1.5;">
            Atribua um responsável ao processo para que as publicações sejam direcionadas automaticamente.
        </p>
    </div>
</div>
<?php endif; ?>

<?php if ($pub['status_tratamento'] === 'concluido'): ?>
<div style="
    background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
    border: 2px solid #28a745;
    border-left: 5px solid #28a745;
    padding: 20px 25px;
    border-radius: 12px;
    margin-bottom: 25px;
    box-shadow: 0 4px 15px rgba(40, 167, 69, 0.2);
    display: flex;
    align-items: flex-start;
    gap: 15px;
">
    <div style="
        background: #28a745;
        color: white;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        flex-shrink: 0;
        font-weight: bold;
    ">✅</div>
    <div style="flex: 1;">
        <h4 style="margin: 0 0 8px 0; color: #155724; font-size: 16px; font-weight: 700;">
            Esta publicação foi marcada como concluída
        </h4>
        <p style="margin: 0; color: #155724; font-size: 14px; line-height: 1.5;">
            <?php if ($pub['tratado_por_nome']): ?>
                Tratada por <strong><?= htmlspecialchars($pub['tratado_por_nome']) ?></strong> 
                em <?= date('d/m/Y \à\s H:i', strtotime($pub['data_tratamento'])) ?>
            <?php endif; ?>
        </p>
    </div>
</div>
<?php endif; ?>

<?php if ($pub['status_tratamento'] === 'descartado'): ?>
<div style="
    background: linear-gradient(135deg, #e2e3e5 0%, #d6d8db 100%);
    border: 2px solid #6c757d;
    border-left: 5px solid #6c757d;
    padding: 20px 25px;
    border-radius: 12px;
    margin-bottom: 25px;
    box-shadow: 0 4px 15px rgba(108, 117, 125, 0.2);
    display: flex;
    align-items: flex-start;
    gap: 15px;
">
    <div style="
        background: #6c757d;
        color: white;
        width: 40px;
        height: 40px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 20px;
        flex-shrink: 0;
        font-weight: bold;
    ">🗑️</div>
    <div style="flex: 1;">
        <h4 style="margin: 0 0 8px 0; color: #383d41; font-size: 16px; font-weight: 700;">
            Esta publicação foi descartada
        </h4>
        <p style="margin: 0; color: #383d41; font-size: 14px; line-height: 1.5;">
            Publicação marcada como irrelevante ou duplicada
            <?php if ($pub['tratado_por_nome']): ?>
                por <strong><?= htmlspecialchars($pub['tratado_por_nome']) ?></strong>
            <?php endif; ?>
        </p>
    </div>
</div>
<?php endif; ?>

<div class="content-grid">
    <!-- Informações Principais -->
    <div class="content-section">
        <h3 class="section-title">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="16" x2="12" y2="12"></line>
                <line x1="12" y1="8" x2="12.01" y2="8"></line>
            </svg>
            Informações da Publicação
        </h3>

        <div class="info-grid">
            <?php if ($pub['numero_processo_cnj'] || $pub['numero_processo_tj']): ?>
            <div class="info-item">
                <span class="info-label">Número do Processo</span>
                <span class="info-value">
                    <?php
                    // Função para formatar número CNJ
                    function formatarNumeroCNJ($numero) {
                        // Remove tudo que não é número
                        $numero = preg_replace('/[^0-9]/', '', $numero);
                        
                        // Formato: NNNNNNN-DD.AAAA.J.TT.OOOO
                        if (strlen($numero) >= 20) {
                            return substr($numero, 0, 7) . '-' . 
                                   substr($numero, 7, 2) . '.' . 
                                   substr($numero, 9, 4) . '.' . 
                                   substr($numero, 13, 1) . '.' . 
                                   substr($numero, 14, 2) . '.' . 
                                   substr($numero, 16);
                        }
                        return $numero;
                    }
                    
                    // Pegar o número disponível (CNJ tem preferência)
                    $numero_original = $pub['numero_processo_cnj'] ?: $pub['numero_processo_tj'];
                    $numero_formatado = formatarNumeroCNJ($numero_original);
                    ?>
                    
                    <!-- NÚMERO CLICÁVEL INLINE -->
                    <span class="processo-numero-copiar" 
                          onclick="copiarNumeroProcessoInline(event, '<?= htmlspecialchars($numero_formatado) ?>')"
                          style="color: #667eea; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 5px; padding: 4px 8px; border-radius: 6px; transition: all 0.3s; user-select: none;"
                          onmouseover="this.style.background='rgba(102, 126, 234, 0.1)'; this.style.transform='scale(1.05)';"
                          onmouseout="this.style.background=''; this.style.transform='';"
                          title="Clique para copiar o número do processo">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                            <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                        </svg>
                        <?= htmlspecialchars($numero_formatado) ?>
                    </span>
                </span>
            </div>
            <?php endif; ?>

            <?php if ($pub['tribunal']): ?>
            <div class="info-item">
                <span class="info-label">Tribunal</span>
                <span class="info-value"><?= htmlspecialchars($pub['tribunal']) ?></span>
            </div>
            <?php endif; ?>

            <?php if ($pub['comarca']): ?>
            <div class="info-item">
                <span class="info-label">Comarca</span>
                <span class="info-value"><?= htmlspecialchars($pub['comarca']) ?></span>
            </div>
            <?php endif; ?>

            <?php if ($pub['vara']): ?>
            <div class="info-item">
                <span class="info-label">Vara</span>
                <span class="info-value"><?= htmlspecialchars($pub['vara']) ?></span>
            </div>
            <?php endif; ?>

            <?php if ($pub['uf']): ?>
            <div class="info-item">
                <span class="info-label">UF</span>
                <span class="info-value"><?= htmlspecialchars($pub['uf']) ?></span>
            </div>
            <?php endif; ?>

            <?php if ($pub['data_publicacao']): ?>
            <div class="info-item">
                <span class="info-label">Data de Publicação</span>
                <span class="info-value"><?= date('d/m/Y H:i', strtotime($pub['data_publicacao'])) ?></span>
            </div>
            <?php endif; ?>

            <?php if ($pub['data_disponibilizacao']): ?>
            <div class="info-item">
                <span class="info-label">Data de Disponibilização</span>
                <span class="info-value"><?= date('d/m/Y H:i', strtotime($pub['data_disponibilizacao'])) ?></span>
            </div>
            <?php endif; ?>

            <?php if ($pub['assunto']): ?>
            <div class="info-item">
                <span class="info-label">Assunto</span>
                <span class="info-value"><?= htmlspecialchars($pub['assunto']) ?></span>
            </div>
            <?php endif; ?>

            <div class="info-item">
                <span class="info-label">Status de Vinculação</span>
                <span class="info-value">
                    <?php if ($pub['processo_id']): ?>
                        <span class="badge badge-vinculado">✓ Vinculada ao Processo</span>
                    <?php else: ?>
                        <span class="badge badge-nao-vinculado">✗ Não Vinculada</span>
                    <?php endif; ?>
                </span>
            </div>
        </div>

        <?php if ($pub['inicio_prazo_api'] || $pub['final_prazo_api']): ?>
        <div style="background: rgba(255, 193, 7, 0.1); padding: 15px; border-radius: 8px; margin-top: 15px; border-left: 4px solid #ffc107;">
            <strong style="color: #856404;">⏰ Informações de Prazo (da API):</strong>
            <div style="margin-top: 10px; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">
                <?php if ($pub['inicio_prazo_api']): ?>
                    <div>
                        <span style="color: #666; font-size: 12px;">Início:</span>
                        <strong style="display: block; color: #333;"><?= date('d/m/Y H:i', strtotime($pub['inicio_prazo_api'])) ?></strong>
                    </div>
                <?php endif; ?>
                <?php if ($pub['final_prazo_api']): ?>
                    <div>
                        <span style="color: #666; font-size: 12px;">Término:</span>
                        <strong style="display: block; color: #333;"><?= date('d/m/Y H:i', strtotime($pub['final_prazo_api'])) ?></strong>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Partes do Processo -->
    <?php if ($pub['polo_ativo'] || $pub['polo_passivo']): ?>
    <div class="content-section">
        <h3 class="section-title">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                <circle cx="9" cy="7" r="4"></circle>
                <path d="M23 21v-2a4 4 0 0 0-3-3.87"></path>
                <path d="M16 3.13a4 4 0 0 1 0 7.75"></path>
            </svg>
            Partes do Processo
        </h3>

        <div class="info-grid">
            <?php if ($pub['polo_ativo']): ?>
            <div class="info-item">
                <span class="info-label">Polo Ativo</span>
                <span class="info-value"><?= nl2br(htmlspecialchars($pub['polo_ativo'])) ?></span>
            </div>
            <?php endif; ?>

            <?php if ($pub['polo_passivo']): ?>
            <div class="info-item">
                <span class="info-label">Polo Passivo</span>
                <span class="info-value"><?= nl2br(htmlspecialchars($pub['polo_passivo'])) ?></span>
            </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Processo Vinculado -->
    <?php if ($pub['processo_id']): ?>
    <div class="content-section">
        <h3 class="section-title">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
                <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
            </svg>
            Processo Vinculado
        </h3>

        <div class="processo-vinculado">
            <h4>
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                    <polyline points="14 2 14 8 20 8"></polyline>
                </svg>
                <?= htmlspecialchars($pub['processo_numero']) ?>
            </h4>

            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Cliente</span>
                    <span class="info-value"><?= htmlspecialchars($pub['processo_cliente']) ?></span>
                </div>

                <div class="info-item">
                    <span class="info-label">Responsável</span>
                    <span class="info-value"><?= htmlspecialchars($pub['processo_responsavel_nome'] ?? 'Não informado') ?></span>
                </div>

                <div class="info-item">
                    <span class="info-label">Ações</span>
                    <span class="info-value">
                        <a href="../processos/visualizar.php?id=<?= $pub['processo_id'] ?>" target="_blank">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path>
                                <polyline points="15 3 21 3 21 9"></polyline>
                                <line x1="10" y1="14" x2="21" y2="3"></line>
                            </svg>
                            Ver Processo Completo
                        </a>
                    </span>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Conteúdo da Publicação -->
    <div class="content-section">
        <h3 class="section-title">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
                <line x1="16" y1="13" x2="8" y2="13"></line>
                <line x1="16" y1="17" x2="8" y2="17"></line>
                <polyline points="10 9 9 9 8 9"></polyline>
            </svg>
            Conteúdo Completo da Publicação
        </h3>

        <?php if ($pub['conteudo']): ?>
            <div class="conteudo-box">
                <div class="conteudo-text"><?= nl2br(htmlspecialchars($pub['conteudo'])) ?></div>
            </div>
        <?php else: ?>
            <p style="color: #999; text-align: center; padding: 40px;">Conteúdo não disponível</p>
        <?php endif; ?>
    </div>

<!-- Histórico de Tratamentos -->
    <?php if (!empty($tratamentos)): ?>
    <div class="content-section">
        <h3 class="section-title">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 20h9"></path>
                <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
            </svg>
            Histórico de Tratamentos (<?= count($tratamentos) ?>)
        </h3>

        <div class="tratamentos-list">
            <?php foreach ($tratamentos as $trat): ?>
                <div class="tratamento-item">
                    <div class="tratamento-header">
                        <div>
                            <span class="tratamento-tipo">
                                <?php
                                $tipo_icons = [
                                    'tarefa' => '✓',
                                    'prazo' => '⏰',
                                    'audiencia' => '📅',
                                    'concluido' => '✅',
                                    'descartado' => '🗑️'
                                ];
                                echo $tipo_icons[$trat['tipo_tratamento']] ?? '📝';
                                ?>
                                <?= ucfirst($trat['tipo_tratamento']) ?>
                            </span>
                            <span class="tratamento-usuario">
                                por <?= htmlspecialchars($trat['usuario_nome']) ?>
                            </span>
                        </div>
                        <span class="tratamento-data">
                            <?= date('d/m/Y H:i', strtotime($trat['data_tratamento'])) ?>
                        </span>
                    </div>
                    
                    <?php if ($trat['observacao']): ?>
                        <div class="tratamento-obs">
                            <?= nl2br(htmlspecialchars($trat['observacao'])) ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($trat['referencia_tipo'] && $trat['referencia_id']): ?>
                        <div style="margin-top: 10px;">
                            <a href="../agenda/ver.php?tipo=<?= $trat['referencia_tipo'] ?>&id=<?= $trat['referencia_id'] ?>" 
                               style="color: #667eea; font-size: 13px; text-decoration: none; font-weight: 500;">
                                → Ver <?= ucfirst($trat['referencia_tipo']) ?> #<?= $trat['referencia_id'] ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php else: ?>
    <div class="content-section">
        <h3 class="section-title">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M12 20h9"></path>
                <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"></path>
            </svg>
            Histórico de Tratamentos
        </h3>

        <div class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="15" y1="9" x2="9" y2="15"></line>
                <line x1="9" y1="9" x2="15" y2="15"></line>
            </svg>
            <p>Nenhum tratamento registrado para esta publicação</p>
        </div>
    </div>
    <?php endif; ?>
</div>


    </div>
</div>


<!-- Modal Tratamento (com iframe interno como no index.php) -->
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
                setTimeout(() => window.location.href = 'index.php', 1500);
            } else {
                mostrarNotificacao('❌ Erro: ' + (result.message || 'Erro desconhecido'), 'error');
            }
        } catch (error) {
            console.error('Erro:', error);
            mostrarNotificacao('❌ Erro ao descartar publicação', 'error');
        }
    }

    function abrirNoTribunal() {
        const tribunal = '<?= $pub['tribunal'] ?? '' ?>';
        const processoCNJ = '<?= $pub['numero_processo_cnj'] ?? '' ?>';
        
        if (!tribunal || !processoCNJ) {
            mostrarNotificacao('⚠️ Informações insuficientes para abrir no tribunal', 'warning');
            return;
        }

        // URLs dos principais tribunais (você pode expandir esta lista)
        const tribunalURLs = {
            'TJSP': 'https://esaj.tjsp.jus.br/cpopg/open.do',
            'TJRJ': 'https://www3.tjrj.jus.br/consultaprocessual/',
            'TJMG': 'https://www4.tjmg.jus.br/juridico/sf/index.jsp',
            'TJSC': 'https://eproc1g.tjsc.jus.br/eproc/controlador.php?acao=painel_adv_listar&acao_origem=principal&hash=06a63ae913d54ee3523f10ebb9c81e7b',
            'TJRS': 'https://www.tjrs.jus.br/site/processos/consulta/',
            'TJPR': 'https://portal.tjpr.jus.br/jurisprudencia/publico/consulta.do',
            'TJPE': 'https://srv01.tjpe.jus.br/consultaprocessualunificada/',
            'TJBA': 'https://esaj.tjba.jus.br/cpopg/open.do',
            'TJCE': 'https://esaj.tjce.jus.br/cpopg/open.do',
            'TJGO': 'https://projudi.tjgo.jus.br/BuscaProcesso',
            'TJMT': 'https://pjd.tjmt.jus.br/consulta',
            'TJMS': 'https://esaj.tjms.jus.br/cpopg/open.do',
            'TJPB': 'https://www.tjpb.jus.br/consultaprocessual/',
            'TJPI': 'https://www.tjpi.jus.br/e-tjpi/',
            'TJES': 'https://sistemas.tjes.jus.br/procweb/',
            'TJRN': 'https://www.tjrn.jus.br/consultaprocesso/',
            'TJAL': 'https://www2.tjal.jus.br/cpopg/open.do',
            'TJAM': 'https://consultasaj.tjam.jus.br/cpopg/open.do',
            'TJAP': 'https://tucujuris.tjap.jus.br/tucujuris/',
            'TJPA': 'https://consultasaj.tjpa.jus.br/cpopg/open.do',
            'TJRO': 'https://pje.tjro.jus.br/pg/ConsultaPublica/listView.seam',
            'TJRR': 'https://esaj.tjrr.jus.br/cpopg/open.do',
            'TJSE': 'https://www.tjse.jus.br/portal/consultas/consulta-processual',
            'TJTO': 'https://jurisconsult.tjto.jus.br/',
            'TJAC': 'https://tucujuris.tjac.jus.br/tucujuris/',
            'TJDF': 'https://www.tjdft.jus.br/consultas/consulta-de-processos-de-primeiro-grau',
            'TRF1': 'https://pje1g.trf1.jus.br/consultapublica/ConsultaPublica/listView.seam',
            'TRF2': 'https://eproc.trf2.jus.br/eproc/externo_controlador.php',
            'TRF3': 'https://www.trf3.jus.br/consultas/',
            'TRF4': 'https://www.trf4.jus.br/trf4/processos/acompanhamento/',
            'TRF5': 'https://pje.trf5.jus.br/pje/ConsultaPublica/listView.seam',
            'TRF6': 'https://pje.trf6.jus.br/pje/ConsultaPublica/listView.seam',
            'TST': 'https://consultaprocessual.tst.jus.br/consultaProcessual/',
            'STJ': 'https://www.stj.jus.br/sites/portalp/Processos/Consulta-Processual',
            'STF': 'https://portal.stf.jus.br/processos/listarProcessos.asp'
        };

        const url = tribunalURLs[tribunal];
        
        if (url) {
            window.open(url, '_blank');
            mostrarNotificacao('🌐 Abrindo tribunal em nova aba...', 'info');
        } else {
            mostrarNotificacao('⚠️ URL do tribunal não configurada para: ' + tribunal, 'warning');
        }
    }

    function mostrarNotificacao(mensagem, tipo = 'info') {
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

        setTimeout(() => {
            notif.style.animation = 'slideOutRight 0.3s ease';
            setTimeout(() => notif.remove(), 300);
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
    `;
    document.head.appendChild(styleSheet);
	
	const publicacaoId = <?= $pub['id'] ?>;

	function abrirModalTratamento(publicacaoId) {
		// Abre modal com iframe interno (igual ao index.php)
		document.getElementById('iframe-tratamento').src = 'tratar.php?id=' + publicacaoId;
		document.getElementById('modalTratamento').style.display = 'block';
		document.body.style.overflow = 'hidden';
	}

	function fecharModalTratamento() {
		document.getElementById('modalTratamento').style.display = 'none';
		document.body.style.overflow = 'auto';
		document.getElementById('iframe-tratamento').src = '';
	}










	
	// Copiar número do processo CNJ
    // Função única para copiar número do processo inline
    function copiarNumeroProcessoInline(event, numeroProcesso) {
        // Prevenir propagação se necessário
        if (event) {
            event.stopPropagation();
        }
        
        // Criar um elemento temporário para copiar
        const tempInput = document.createElement('input');
        tempInput.value = numeroProcesso;
        document.body.appendChild(tempInput);
        tempInput.select();
        tempInput.setSelectionRange(0, 99999); // Para mobile
        
        try {
            document.execCommand('copy');
            document.body.removeChild(tempInput);
            
            // Feedback visual no elemento
            if (event && event.currentTarget) {
                const elemento = event.currentTarget;
                const bgOriginal = elemento.style.background;
                const colorOriginal = elemento.style.color;
                
                elemento.style.background = '#28a745';
                elemento.style.color = 'white';
                setTimeout(() => {
                    elemento.style.background = bgOriginal;
                    elemento.style.color = colorOriginal;
                }, 300);
            }
            
            mostrarNotificacao('📋 Número do processo copiado: ' + numeroProcesso, 'success');
        } catch (err) {
            document.body.removeChild(tempInput);
            alert('❌ Erro ao copiar: ' + err);
        }
    }
    
    // ============================================
    // FECHAR POPUP/ABA OU VOLTAR
    // ============================================
    function fecharOuVoltar() {
        // Tenta fechar a janela (funciona se foi aberta via window.open)
        if (window.opener && !window.opener.closed) {
            // Popup aberto por outra janela
            window.close();
        } else {
            // Tentar fechar aba (pode não funcionar em alguns navegadores)
            window.close();
            
            // Se não fechou, volta para página anterior
            setTimeout(function() {
                if (!window.closed) {
                    window.history.back();
                }
            }, 100);
        }
    }
    
    // ============================================
    // ABRIR TRATAMENTO EM POPUP
    // ============================================
    function abrirTratamento(publicacaoId) {
        const width = 950;
        const height = 750;
        const left = (screen.width - width) / 2;
        const top = (screen.height - height) / 2;
        
        const popup = window.open(
            'tratar.php?id=' + publicacaoId,
            'tratamento_' + publicacaoId,
            `width=${width},height=${height},left=${left},top=${top},resizable=yes,scrollbars=yes`
        );
        
        if (popup) {
            popup.focus();
        } else {
            alert('⚠️ Popup bloqueado! Por favor, permita popups para este site.');
        }
    }
    
    // ============================================
    // RECEBER MENSAGEM DO POPUP DE TRATAMENTO
    // ============================================
    window.addEventListener('message', function(event) {
        if (event.origin !== window.location.origin) {
            return;
        }
        
        if (event.data.type === 'publicacao_tratada' && event.data.success) {
        // Mostrar toast
        showSuccessToast(event.data.message);
        
        // Se estiver em popup, fechar após 1 segundo
        if (window.opener) {
            setTimeout(() => window.close(), 1000);
        } else {
            // Recarregar após 1.5 segundos
            setTimeout(() => window.location.reload(), 1500);
        }
    }
    });
    
    // ============================================
    // ATALHO DE TECLADO: ESC = FECHAR/VOLTAR
    // ============================================
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            fecharOuVoltar();
        }
    });
</script>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Detalhes da Publicação', $conteudo, 'publicacoes');
?>