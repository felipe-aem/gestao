<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';
require_once '../../includes/layout.php';

$processo_id = $_GET['id'] ?? 0;

if (!$processo_id) {
    $_SESSION['erro'] = 'Processo não encontrado';
    header('Location: index.php');
    exit;
}

// Verificar acesso ao processo
$usuario_logado = Auth::user();
$nucleos_usuario = $usuario_logado['nucleos'] ?? [];

$sql = "SELECT p.numero_processo, p.cliente_nome, p.nucleo_id, n.nome as nucleo_nome
        FROM processos p 
        INNER JOIN nucleos n ON p.nucleo_id = n.id 
        WHERE p.id = ?";
$stmt = executeQuery($sql, [$processo_id]);
$processo = $stmt->fetch();

if (!$processo || !in_array($processo['nucleo_id'], $nucleos_usuario)) {
    die('
        <div style="text-align: center; margin-top: 50px;">
            <h2>Acesso Negado!</h2>
            <p>Você não tem acesso a este processo.</p>
            <a href="index.php">Voltar para Processos</a>
        </div>
    ');
}

// CORRIGIDO: Capturar e limpar APENAS mensagens desta página
$erro = '';
$sucesso = '';

// Só mostra mensagens se foram definidas especificamente para novo_resultado
if (isset($_SESSION['erro_resultado'])) {
    $erro = $_SESSION['erro_resultado'];
    unset($_SESSION['erro_resultado']);
}

if (isset($_SESSION['sucesso_resultado'])) {
    $sucesso = $_SESSION['sucesso_resultado'];
    unset($_SESSION['sucesso_resultado']);
}

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
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 20px;
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
        border-color: #1a1a1a;
        box-shadow: 0 0 0 3px rgba(26, 26, 26, 0.1);
    }
    
    .form-group textarea {
        resize: vertical;
        min-height: 120px;
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
    
    .info-box {
        background: rgba(40, 167, 69, 0.1);
        border: 1px solid rgba(40, 167, 69, 0.3);
        color: #155724;
        padding: 15px;
        border-radius: 8px;
        margin-bottom: 20px;
    }

    @media (max-width: 768px) {
        .form-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="page-header">
    <h2>Registrar Resultado</h2>
    <a href="visualizar.php?id=<?= $processo_id ?>" class="btn-voltar">← Voltar</a>
</div>

<div class="content">
    <?php if (!empty($erro)): ?>
    <div class="alert alert-danger">
        <?= $erro ?>
    </div>
    <?php endif; ?>
    
    <?php if (!empty($sucesso)): ?>
    <div class="alert alert-success">
        <?= htmlspecialchars($sucesso) ?>
    </div>
    <?php endif; ?>
    
    <div class="info-box">
        <strong>Processo:</strong> <?= htmlspecialchars($processo['numero_processo']) ?> - 
        <?= htmlspecialchars($processo['cliente_nome']) ?>
    </div>
    
    <div class="form-container">
        <form action="process_resultado.php" method="POST" id="resultadoForm">
            <input type="hidden" name="processo_id" value="<?= $processo_id ?>">
            
            <!-- Seção: Dados do Resultado -->
            <div class="form-section">
                <h3>🎯 Dados do Resultado</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="tipo_resultado">Tipo de Resultado *</label>
                        <select id="tipo_resultado" name="tipo_resultado" required>
                            <option value="">Selecione...</option>
                            <option value="Positivo">Positivo</option>
                            <option value="Negativo">Negativo</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="data_resultado">Data do Resultado *</label>
                        <input type="date" id="data_resultado" name="data_resultado" 
                               value="<?= date('Y-m-d') ?>" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="descricao_resultado">Descrição do Resultado *</label>
                    <textarea id="descricao_resultado" name="descricao_resultado" required
                              placeholder="Descreva detalhadamente o resultado obtido no processo..."></textarea>
                </div>
            </div>
            
            <!-- Seção: Entrega ao Cliente -->
            <div class="form-section">
                <h3>📞 Entrega ao Cliente</h3>
                
                <div class="form-group">
                    <label for="data_entrega_cliente">Data de Entrega ao Cliente</label>
                    <input type="date" id="data_entrega_cliente" name="data_entrega_cliente">
                    <small style="color: #666; font-size: 12px;">
                        Deixe em branco se ainda não foi entregue ao cliente
                    </small>
                </div>
            </div>
            
            <!-- Seção: Observações -->
            <div class="form-section">
                <h3>📝 Observações Adicionais</h3>
                
                <div class="form-group">
                    <label for="observacoes">Observações</label>
                    <textarea id="observacoes" name="observacoes"
                              placeholder="Informações adicionais sobre o resultado, próximos passos, etc."></textarea>
                </div>
            </div>
            
            <button type="submit" class="btn-submit">Registrar Resultado</button>
        </form>
    </div>
</div>

<script>
    // Validação do formulário
    document.getElementById('resultadoForm').addEventListener('submit', function(e) {
        const dataResultado = document.getElementById('data_resultado').value;
        const dataEntrega = document.getElementById('data_entrega_cliente').value;
        
        // Verificar se data de entrega não é anterior à data do resultado
        if (dataResultado && dataEntrega) {
            const resultado = new Date(dataResultado);
            const entrega = new Date(dataEntrega);
            
            if (entrega < resultado) {
                e.preventDefault();
                alert('A data de entrega ao cliente não pode ser anterior à data do resultado.');
                return;
            }
        }
    });
</script>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Novo Resultado', $conteudo, 'processos');
?>