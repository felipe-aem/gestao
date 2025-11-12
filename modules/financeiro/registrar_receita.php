<?php
require_once __DIR__ . '/../../includes/auth.php';
Auth::protect();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/layout.php';

$usuario_logado = Auth::user();

// VERIFICAR ACESSO AO MÓDULO FINANCEIRO
$acesso_financeiro = $usuario_logado['acesso_financeiro'] ?? 'Nenhum';

if ($acesso_financeiro === 'Nenhum') {
    die('
        <div style="text-align: center; margin-top: 50px;">
            <h2>Acesso Negado!</h2>
            <p>Você não tem permissão para acessar o módulo financeiro.</p>
            <a href="../dashboard/">Voltar ao Dashboard</a>
        </div>
    ');
}

// Verificar se foi passado um processo_id
$processo_id = $_GET['processo_id'] ?? 0;

if (!$processo_id) {
    $_SESSION['erro'] = 'Processo não especificado';
    header('Location: index.php');
    exit;
}

// Buscar dados do processo
$nucleos_usuario = $usuario_logado['nucleos'] ?? [];

// Se for acesso completo, pode ver todos os processos
if ($acesso_financeiro === 'Completo') {
    $sql = "SELECT p.*, n.nome as nucleo_nome, pf.forma_pagamento, pf.valor_honorarios
            FROM processos p
            INNER JOIN nucleos n ON p.nucleo_id = n.id
            LEFT JOIN processo_financeiro pf ON p.id = pf.processo_id
            WHERE p.id = ?";
    $stmt = executeQuery($sql, [$processo_id]);
} else {
    // Gestores só veem processos do seu núcleo
    $placeholders = str_repeat('?,', count($nucleos_usuario) - 1) . '?';
    $sql = "SELECT p.*, n.nome as nucleo_nome, pf.forma_pagamento, pf.valor_honorarios
            FROM processos p
            INNER JOIN nucleos n ON p.nucleo_id = n.id
            LEFT JOIN processo_financeiro pf ON p.id = pf.processo_id
            WHERE p.id = ? AND p.nucleo_id IN ($placeholders)";
    $params = array_merge([$processo_id], $nucleos_usuario);
    $stmt = executeQuery($sql, $params);
}

$processo = $stmt->fetch();

if (!$processo) {
    $_SESSION['erro'] = 'Processo não encontrado ou você não tem acesso a ele';
    header('Location: index.php');
    exit;
}

// Buscar receitas já registradas
$sql_receitas = "SELECT * FROM processo_receitas WHERE processo_id = ? ORDER BY data_recebimento DESC";
$stmt = executeQuery($sql_receitas, [$processo_id]);
$receitas_existentes = $stmt->fetchAll();

$total_recebido = array_sum(array_column($receitas_existentes, 'valor'));
$saldo_pendente = $processo['valor_honorarios'] ? ($processo['valor_honorarios'] - $total_recebido) : 0;

$erro = $_SESSION['erro'] ?? '';
$sucesso = $_SESSION['sucesso'] ?? '';
unset($_SESSION['erro'], $_SESSION['sucesso']);

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
        flex: 1;
        padding: 30px;
        max-width: 1200px;
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
    
    .btn-voltar {
        padding: 12px 24px;
        background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
        color: white;
        text-decoration: none;
        border-radius: 8px;
        font-weight: 600;
        transition: all 0.3s;
    }
    
    .btn-voltar:hover {
        transform: translateY(-2px);
    }
    
    .form-container {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        padding: 30px;
        margin-bottom: 30px;
    }
    
	.info-box {
		background: linear-gradient(135deg, rgba(34, 197, 94, 0.15) 0%, rgba(74, 222, 128, 0.25) 100%);
		border-left: 4px solid #4ade80;
		border-radius: 12px;
		padding: 20px 24px;
		margin-bottom: 25px;
		box-shadow: 0 2px 8px rgba(34, 197, 94, 0.2);
		color: #bbf7d0;
		transition: all 0.3s ease;
	}

	.info-box:hover {
		box-shadow: 0 4px 16px rgba(34, 197, 94, 0.35);
		transform: translateY(-2px);
		background: linear-gradient(135deg, rgba(34, 197, 94, 0.2) 0%, rgba(74, 222, 128, 0.3) 100%);
	}

	.info-box h3 {
		margin-bottom: 10px;
		font-size: 18px;
		color: #86efac;
	}
    
    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
        margin-top: 15px;
    }
    
    .info-item {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }
    
    .info-label {
        font-size: 12px;
        font-weight: 600;
        opacity: 0.8;
    }
    
    .info-value {
        font-size: 18px;
        font-weight: 700;
    }
    
    .form-section {
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 1px solid rgba(0,0,0,0.1);
    }
    
    .form-section:last-child {
        border-bottom: none;
        margin-bottom: 0;
    }
    
    .form-section h3 {
        color: #1a1a1a;
        margin-bottom: 20px;
        font-size: 18px;
        font-weight: 700;
    }
    
    .form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
    }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-group.full-width {
        grid-column: 1 / -1;
    }
    
    .form-group label {
        display: block;
        margin-bottom: 8px;
        color: #333;
        font-weight: 600;
        font-size: 14px;
    }
    
    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 12px;
        border: 1px solid #ddd;
        border-radius: 8px;
        font-size: 14px;
        transition: border-color 0.3s;
    }
    
    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: #28a745;
        box-shadow: 0 0 0 3px rgba(40, 167, 69, 0.1);
    }
    
    .form-group textarea {
        resize: vertical;
        min-height: 100px;
    }
    
    .btn-submit {
        background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        color: white;
        padding: 15px 30px;
        border: none;
        border-radius: 8px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        width: 100%;
    }
    
    .btn-submit:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
    }
    
    .alert {
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-size: 14px;
    }
    
    .alert-danger {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }
    
    .alert-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }
    
    .receitas-anteriores {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        padding: 30px;
    }
    
    .receitas-anteriores h3 {
        color: #1a1a1a;
        margin-bottom: 20px;
        font-size: 18px;
        font-weight: 700;
    }
    
    .receita-item {
        background: white;
        border: 1px solid #e9ecef;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 10px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .receita-info {
        flex: 1;
    }
    
    .receita-valor {
        font-size: 20px;
        font-weight: 700;
        color: #28a745;
    }
    
    .badge {
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        text-transform: uppercase;
    }
    
    .badge-info {
        background: #17a2b8;
        color: white;
    }
    
    .empty-state {
        text-align: center;
        padding: 40px;
        color: #666;
    }
    
    @media (max-width: 768px) {
        .page-header {
            flex-direction: column;
            align-items: stretch;
        }
        
        .form-grid {
            grid-template-columns: 1fr;
        }
        
        .info-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="page-header">
    <h2>💳 Registrar Receita</h2>
    <a href="index.php" class="btn-voltar">← Voltar</a>
</div>

<div class="content">
    <?php if (!empty($erro)): ?>
    <div class="alert alert-danger"><?= $erro ?></div>
    <?php endif; ?>
    
    <?php if (!empty($sucesso)): ?>
    <div class="alert alert-success"><?= htmlspecialchars($sucesso) ?></div>
    <?php endif; ?>
    
    <div class="info-box">
        <h3>📋 Informações do Processo</h3>
        <p><strong>Processo:</strong> <?= htmlspecialchars($processo['numero_processo']) ?></p>
        <p><strong>Cliente:</strong> <?= htmlspecialchars($processo['cliente_nome']) ?></p>
        <p><strong>Núcleo:</strong> <?= htmlspecialchars($processo['nucleo_nome']) ?></p>
        
        <?php if ($processo['valor_honorarios']): ?>
        <div class="info-grid">
            <div class="info-item">
                <span class="info-label">Valor Contratado:</span>
                <span class="info-value" style="color: #007bff;">
                    R$ <?= number_format($processo['valor_honorarios'], 2, ',', '.') ?>
                </span>
            </div>
            
            <div class="info-item">
                <span class="info-label">Total Recebido:</span>
                <span class="info-value" style="color: #28a745;">
                    R$ <?= number_format($total_recebido, 2, ',', '.') ?>
                </span>
            </div>
            
            <div class="info-item">
                <span class="info-label">Saldo Pendente:</span>
                <span class="info-value" style="color: #ffc107;">
                    R$ <?= number_format($saldo_pendente, 2, ',', '.') ?>
                </span>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="form-container">
        <form action="process_receita.php" method="POST" id="receitaForm">
            <input type="hidden" name="processo_id" value="<?= $processo_id ?>">
            
            <div class="form-section">
                <h3>💰 Dados da Receita</h3>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="tipo_receita">Tipo de Receita *</label>
                        <select id="tipo_receita" name="tipo_receita" required>
                            <option value="">Selecione...</option>
                            <option value="Honorário Fixo">Honorário Fixo</option>
                            <option value="Percentual Êxito">Percentual sobre Êxito</option>
                            <option value="Entrada">Entrada/Sinal</option>
                            <option value="Parcela">Parcela</option>
                            <option value="Outros">Outros</option>
                        </select>
                    </div>
                    
                    <div class="form-group" id="field-numero-parcela" style="display: none;">
                        <label for="numero_parcela">Número da Parcela</label>
                        <input type="number" id="numero_parcela" name="numero_parcela" min="1" placeholder="Ex: 1, 2, 3...">
                    </div>
                    
                    <div class="form-group">
                        <label for="valor">Valor (R$) *</label>
                        <input type="text" id="valor" name="valor" required class="money-input" placeholder="0,00">
                    </div>
                    
                    <div class="form-group">
                        <label for="data_recebimento">Data do Recebimento *</label>
                        <input type="date" id="data_recebimento" name="data_recebimento" required value="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="forma_recebimento">Forma de Recebimento *</label>
                        <select id="forma_recebimento" name="forma_recebimento" required>
                            <option value="">Selecione...</option>
                            <option value="PIX">PIX</option>
                            <option value="Transferência">Transferência Bancária</option>
                            <option value="Dinheiro">Dinheiro</option>
                            <option value="Boleto">Boleto</option>
                            <option value="Cartão">Cartão de Crédito/Débito</option>
                            <option value="Cheque">Cheque</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="observacoes">Observações</label>
                    <textarea id="observacoes" name="observacoes" placeholder="Informações adicionais sobre este recebimento..."></textarea>
                </div>
            </div>
            
            <button type="submit" class="btn-submit">💾 Registrar Receita</button>
        </form>
    </div>
    
    <?php if (!empty($receitas_existentes)): ?>
    <div class="receitas-anteriores">
        <h3>📊 Receitas Anteriores deste Processo</h3>
        
        <?php foreach ($receitas_existentes as $receita): ?>
        <div class="receita-item">
            <div class="receita-info">
                <div>
                    <span class="badge badge-info"><?= htmlspecialchars($receita['tipo_receita']) ?></span>
                    <?php if ($receita['numero_parcela']): ?>
                    <small style="color: #666;">Parcela <?= $receita['numero_parcela'] ?></small>
                    <?php endif; ?>
                </div>
                <div style="margin-top: 8px; font-size: 13px; color: #666;">
                    <strong>Data:</strong> <?= date('d/m/Y', strtotime($receita['data_recebimento'])) ?> | 
                    <strong>Forma:</strong> <?= htmlspecialchars($receita['forma_recebimento']) ?>
                </div>
                <?php if ($receita['observacoes']): ?>
                <div style="margin-top: 5px; font-size: 12px; color: #999;">
                    <?= htmlspecialchars($receita['observacoes']) ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="receita-valor">
                R$ <?= number_format($receita['valor'], 2, ',', '.') ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script>
// Máscara de dinheiro
const moneyInput = document.getElementById('valor');
moneyInput.addEventListener('input', function(e) {
    let value = e.target.value.replace(/\D/g, '');
    value = (value / 100).toFixed(2);
    e.target.value = value.replace('.', ',');
});

// Mostrar campo de número da parcela quando tipo for "Parcela"
document.getElementById('tipo_receita').addEventListener('change', function() {
    const fieldNumeroParcela = document.getElementById('field-numero-parcela');
    const inputNumeroParcela = document.getElementById('numero_parcela');
    
    if (this.value === 'Parcela') {
        fieldNumeroParcela.style.display = 'block';
        inputNumeroParcela.required = true;
    } else {
        fieldNumeroParcela.style.display = 'none';
        inputNumeroParcela.required = false;
        inputNumeroParcela.value = '';
    }
});

// Validação do formulário
document.getElementById('receitaForm').addEventListener('submit', function(e) {
    const valor = document.getElementById('valor').value;
    
    if (!valor || parseFloat(valor.replace(',', '.')) <= 0) {
        e.preventDefault();
        alert('Por favor, informe um valor válido maior que zero.');
        return;
    }
});
</script>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Registrar Receita', $conteudo, 'financeiro');
?>