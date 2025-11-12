<?php
require_once __DIR__ . '/../../includes/auth.php';
Auth::protect();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/layout.php';

$usuario_logado = Auth::user();

// VERIFICAR ACESSO COMPLETO (apenas Diretores/Sócios podem editar)
$acesso_financeiro = $usuario_logado['acesso_financeiro'] ?? 'Nenhum';

if ($acesso_financeiro !== 'Completo') {
    die('
        <div style="text-align: center; margin-top: 50px;">
            <h2>Acesso Negado!</h2>
            <p>Apenas usuários com acesso completo podem editar receitas.</p>
            <a href="index.php">Voltar ao Dashboard Financeiro</a>
        </div>
    ');
}

// Verificar se foi passado um ID
$receita_id = $_GET['id'] ?? 0;

if (!$receita_id) {
    $_SESSION['erro'] = 'Receita não especificada';
    header('Location: index.php');
    exit;
}

// Buscar dados da receita
$sql = "SELECT pr.*, p.numero_processo, p.cliente_nome, n.nome as nucleo_nome
        FROM processo_receitas pr
        INNER JOIN processos p ON pr.processo_id = p.id
        INNER JOIN nucleos n ON p.nucleo_id = n.id
        WHERE pr.id = ?";
$stmt = executeQuery($sql, [$receita_id]);
$receita = $stmt->fetch();

if (!$receita) {
    $_SESSION['erro'] = 'Receita não encontrada';
    header('Location: index.php');
    exit;
}

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
    }
    
    .info-box {
        background: rgba(0, 123, 255, 0.1);
        border: 1px solid rgba(0, 123, 255, 0.3);
        color: #004085;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 25px;
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
        border-color: #007bff;
        box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
    }
    
    .form-group textarea {
        resize: vertical;
        min-height: 100px;
    }
    
    .btn-submit {
        background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
        color: white;
        padding: 15px 30px;
        border: none;
        border-radius: 8px;
        font-size: 16px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        box-shadow: 0 4px 15px rgba(0, 123, 255, 0.3);
        width: 100%;
    }
    
    .btn-submit:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0, 123, 255, 0.4);
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
    
    @media (max-width: 768px) {
        .page-header {
            flex-direction: column;
            align-items: stretch;
        }
        
        .form-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="page-header">
    <h2>✏️ Editar Receita</h2>
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
        <strong>Processo:</strong> <?= htmlspecialchars($receita['numero_processo']) ?> - 
        <?= htmlspecialchars($receita['cliente_nome']) ?> | 
        <strong>Núcleo:</strong> <?= htmlspecialchars($receita['nucleo_nome']) ?>
    </div>
    
    <div class="form-container">
        <form action="process_editar_receita.php" method="POST" id="editarReceitaForm">
            <input type="hidden" name="receita_id" value="<?= $receita_id ?>">
            <input type="hidden" name="processo_id" value="<?= $receita['processo_id'] ?>">
            
            <div class="form-section">
                <h3>💰 Dados da Receita</h3>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="tipo_receita">Tipo de Receita *</label>
                        <select id="tipo_receita" name="tipo_receita" required>
                            <option value="">Selecione...</option>
                            <option value="Honorário Fixo" <?= $receita['tipo_receita'] === 'Honorário Fixo' ? 'selected' : '' ?>>Honorário Fixo</option>
                            <option value="Percentual Êxito" <?= $receita['tipo_receita'] === 'Percentual Êxito' ? 'selected' : '' ?>>Percentual sobre Êxito</option>
                            <option value="Entrada" <?= $receita['tipo_receita'] === 'Entrada' ? 'selected' : '' ?>>Entrada/Sinal</option>
                            <option value="Parcela" <?= $receita['tipo_receita'] === 'Parcela' ? 'selected' : '' ?>>Parcela</option>
                            <option value="Outros" <?= $receita['tipo_receita'] === 'Outros' ? 'selected' : '' ?>>Outros</option>
                        </select>
                    </div>
                    
                    <div class="form-group" id="field-numero-parcela" style="display: <?= $receita['tipo_receita'] === 'Parcela' ? 'block' : 'none' ?>;">
                        <label for="numero_parcela">Número da Parcela</label>
                        <input type="number" id="numero_parcela" name="numero_parcela" min="1" 
                               value="<?= $receita['numero_parcela'] ?>" placeholder="Ex: 1, 2, 3...">
                    </div>
                    
                    <div class="form-group">
                        <label for="valor">Valor (R$) *</label>
                        <input type="text" id="valor" name="valor" required class="money-input" 
                               value="<?= number_format($receita['valor'], 2, ',', '') ?>" placeholder="0,00">
                    </div>
                    
                    <div class="form-group">
                        <label for="data_recebimento">Data do Recebimento *</label>
                        <input type="date" id="data_recebimento" name="data_recebimento" required 
                               value="<?= $receita['data_recebimento'] ?>">
                    </div>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label for="forma_recebimento">Forma de Recebimento *</label>
                        <select id="forma_recebimento" name="forma_recebimento" required>
                            <option value="">Selecione...</option>
                            <option value="PIX" <?= $receita['forma_recebimento'] === 'PIX' ? 'selected' : '' ?>>PIX</option>
                            <option value="Transferência" <?= $receita['forma_recebimento'] === 'Transferência' ? 'selected' : '' ?>>Transferência Bancária</option>
                            <option value="Dinheiro" <?= $receita['forma_recebimento'] === 'Dinheiro' ? 'selected' : '' ?>>Dinheiro</option>
                            <option value="Boleto" <?= $receita['forma_recebimento'] === 'Boleto' ? 'selected' : '' ?>>Boleto</option>
                            <option value="Cartão" <?= $receita['forma_recebimento'] === 'Cartão' ? 'selected' : '' ?>>Cartão de Crédito/Débito</option>
                            <option value="Cheque" <?= $receita['forma_recebimento'] === 'Cheque' ? 'selected' : '' ?>>Cheque</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="observacoes">Observações</label>
                    <textarea id="observacoes" name="observacoes" placeholder="Informações adicionais sobre este recebimento..."><?= htmlspecialchars($receita['observacoes']) ?></textarea>
                </div>
            </div>
            
            <button type="submit" class="btn-submit">💾 Salvar Alterações</button>
        </form>
    </div>
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
    }
});

// Validação do formulário
document.getElementById('editarReceitaForm').addEventListener('submit', function(e) {
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
echo renderLayout('Editar Receita', $conteudo, 'financeiro');
?>