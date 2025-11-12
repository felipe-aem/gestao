<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';
require_once '../../includes/layout.php';

// Buscar núcleos disponíveis
$sql = "SELECT * FROM nucleos WHERE ativo = 1 ORDER BY nome";
$stmt = executeQuery($sql);
$nucleos = $stmt->fetchAll();

// Buscar usuários ativos
$sql = "SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome";
$stmt = executeQuery($sql);
$usuarios = $stmt->fetchAll();

// Buscar clientes para autocomplete
$sql = "SELECT id, nome, cpf_cnpj FROM clientes WHERE ativo = 1 ORDER BY nome LIMIT 100";
$stmt = executeQuery($sql);
$clientes = $stmt->fetchAll();

$erro = $_SESSION['erro'] ?? '';
$sucesso = $_SESSION['sucesso'] ?? '';
unset($_SESSION['erro'], $_SESSION['sucesso']);

$usuario_logado = Auth::user();
ob_start();
?>
<style>
    body {
        font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        background: linear-gradient(135deg, rgba(0, 0, 0, 0.95) 0%, rgba(40, 40, 40, 0.98) 100%);
        background-attachment: fixed;
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
        animation: slideDown 0.4s ease-out;
    }
    
    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
    }
    
    @keyframes slideUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
    
    @keyframes pulse {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.05); }
    }
    
    .page-header h2 {
        color: #1a1a1a;
        font-size: 24px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .page-header h2::before {
        content: '➕';
        font-size: 28px;
        animation: pulse 2s infinite;
    }
    
    .btn-voltar {
        padding: 12px 24px;
        background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
        color: white;
        text-decoration: none;
        border-radius: 8px;
        font-weight: 600;
        transition: all 0.3s;
        box-shadow: 0 4px 15px rgba(108, 117, 125, 0.3);
    }
    
    .btn-voltar:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(108, 117, 125, 0.4);
    }
    
    .form-container {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        padding: 30px;
        animation: slideUp 0.5s ease-out;
    }
    
    .form-section {
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 1px solid rgba(0,0,0,0.1);
        animation: fadeIn 0.6s ease-out;
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
        display: flex;
        align-items: center;
        gap: 10px;
        position: relative;
        padding-left: 15px;
    }
    
    .form-section h3::before {
        content: '';
        position: absolute;
        left: 0;
        top: 50%;
        transform: translateY(-50%);
        width: 4px;
        height: 24px;
        background: linear-gradient(135deg, #1a1a1a 0%, #666 100%);
        border-radius: 2px;
    }
    
    .form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 20px;
    }
    
    .form-group {
        margin-bottom: 20px;
        position: relative;
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
        transition: color 0.3s;
    }
    
    .form-group input,
    .form-group select,
    .form-group textarea {
        width: 100%;
        padding: 12px;
        border: 2px solid #ddd;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.3s;
        background: white;
    }
    
    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: #1a1a1a;
        box-shadow: 0 0 0 4px rgba(26, 26, 26, 0.1);
        transform: translateY(-1px);
    }
    
    .form-group textarea {
        resize: vertical;
        min-height: 100px;
        font-family: inherit;
    }
    
    /* Cliente Options - Cards melhorados */
    .cliente-options {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 20px;
        margin-bottom: 20px;
    }
    
    .cliente-option {
        padding: 20px;
        border: 3px solid #ddd;
        border-radius: 12px;
        cursor: pointer;
        text-align: center;
        transition: all 0.3s;
        background: white;
        position: relative;
        overflow: hidden;
    }
    
    .cliente-option::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 4px;
        background: linear-gradient(90deg, #1a1a1a, #666);
        transform: scaleX(0);
        transition: transform 0.3s;
    }
    
    .cliente-option:hover {
        border-color: #1a1a1a;
        transform: translateY(-4px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.15);
    }
    
    .cliente-option:hover::before {
        transform: scaleX(1);
    }
    
    .cliente-option.active {
        border-color: #1a1a1a;
        background: linear-gradient(135deg, rgba(26, 26, 26, 0.05) 0%, rgba(26, 26, 26, 0.02) 100%);
        box-shadow: 0 8px 20px rgba(0,0,0,0.15);
    }
    
    .cliente-option.active::before {
        transform: scaleX(1);
    }
    
    .cliente-option input[type="radio"] {
        display: none;
    }
    
    .cliente-option h4 {
        color: #1a1a1a;
        margin-bottom: 8px;
        font-size: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }
    
    .cliente-option h4::before {
        font-size: 24px;
    }
    
    .cliente-option:first-child h4::before {
        content: '👤';
    }
    
    .cliente-option:last-child h4::before {
        content: '✨';
    }
    
    .cliente-option p {
        color: #666;
        font-size: 13px;
    }
    
    /* Autocomplete melhorado */
    .autocomplete {
        position: relative;
    }
    
    .autocomplete-suggestions {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: white;
        border: 2px solid #1a1a1a;
        border-top: none;
        border-radius: 0 0 8px 8px;
        max-height: 250px;
        overflow-y: auto;
        z-index: 1000;
        display: none;
        box-shadow: 0 8px 20px rgba(0,0,0,0.15);
        animation: slideDown 0.3s ease-out;
    }
    
    .autocomplete-suggestion {
        padding: 14px;
        cursor: pointer;
        border-bottom: 1px solid rgba(0,0,0,0.05);
        transition: all 0.2s;
    }
    
    .autocomplete-suggestion:hover {
        background: linear-gradient(135deg, rgba(26, 26, 26, 0.08) 0%, rgba(26, 26, 26, 0.04) 100%);
        padding-left: 18px;
    }
    
    .autocomplete-suggestion:last-child {
        border-bottom: none;
    }
    
    .autocomplete-suggestion strong {
        color: #1a1a1a;
        display: block;
        margin-bottom: 4px;
    }
    
    .autocomplete-suggestion small {
        color: #666;
        font-size: 12px;
    }
    
    /* Checkbox customizado melhorado */
    .checkbox-wrapper {
        display: flex;
        align-items: center;
        padding: 16px;
        border: 2px solid #ddd;
        border-radius: 8px;
        cursor: pointer;
        transition: all 0.3s;
        background: white;
        position: relative;
        overflow: hidden;
    }
    
    .checkbox-wrapper::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        width: 0;
        height: 100%;
        background: linear-gradient(135deg, rgba(26, 26, 26, 0.05) 0%, rgba(26, 26, 26, 0.02) 100%);
        transition: width 0.3s;
    }
    
    .checkbox-wrapper:hover {
        border-color: #1a1a1a;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        transform: translateY(-2px);
    }
    
    .checkbox-wrapper:hover::before {
        width: 100%;
    }
    
    .checkbox-wrapper input[type="checkbox"] {
        appearance: none;
        width: 24px;
        height: 24px;
        border: 2px solid #bbb;
        border-radius: 6px;
        margin-right: 12px;
        cursor: pointer;
        position: relative;
        transition: all 0.3s;
        flex-shrink: 0;
    }
    
    .checkbox-wrapper input[type="checkbox"]:checked {
        background: linear-gradient(135deg, #1a1a1a 0%, #333 100%);
        border-color: #1a1a1a;
        animation: checkBounce 0.3s ease-out;
    }
    
    @keyframes checkBounce {
        0% { transform: scale(1); }
        50% { transform: scale(1.2); }
        100% { transform: scale(1); }
    }
    
    .checkbox-wrapper input[type="checkbox"]:checked::after {
        content: '';
        position: absolute;
        left: 7px;
        top: 3px;
        width: 6px;
        height: 12px;
        border: solid white;
        border-width: 0 3px 3px 0;
        transform: rotate(45deg);
    }
    
    .checkbox-wrapper label {
        margin-bottom: 0 !important;
        font-weight: 600;
        color: #333;
        cursor: pointer;
        flex: 1;
        position: relative;
        z-index: 1;
    }
    
    /* Seção de Nova Reunião melhorada */
    .nova-reuniao-section {
        display: none;
        background: linear-gradient(135deg, rgba(255, 193, 7, 0.15) 0%, rgba(255, 193, 7, 0.05) 100%);
        border: 2px solid rgba(255, 193, 7, 0.3);
        border-radius: 12px;
        padding: 25px;
        margin-top: 20px;
        position: relative;
        overflow: hidden;
        animation: expandSection 0.4s ease-out;
    }
    
    @keyframes expandSection {
        from {
            opacity: 0;
            max-height: 0;
            padding-top: 0;
            padding-bottom: 0;
        }
        to {
            opacity: 1;
            max-height: 500px;
            padding-top: 25px;
            padding-bottom: 25px;
        }
    }
    
    .nova-reuniao-section::before {
        content: '⏰';
        position: absolute;
        top: 20px;
        right: 20px;
        font-size: 48px;
        opacity: 0.1;
    }
    
    .nova-reuniao-section.show {
        display: block;
    }
    
    .nova-reuniao-section h3 {
        color: #856404;
        margin-bottom: 20px;
        font-size: 18px;
        font-weight: 700;
    }
    
    /* Nucleos container melhorado */
    .nucleos-container {
        border: 2px solid #ddd;
        border-radius: 12px;
        padding: 20px;
        max-height: 300px;
        overflow-y: auto;
        background: white;
        transition: border-color 0.3s;
    }
    
    .nucleos-container:hover {
        border-color: #1a1a1a;
    }
    
    .nucleos-container h4 {
        margin-bottom: 15px;
        color: #333;
        font-size: 16px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .nucleos-container h4::before {
        content: '🏢';
    }
    
    .checkbox-group {
        display: flex;
        align-items: flex-start;
        margin-bottom: 12px;
        padding: 10px;
        border-radius: 6px;
        transition: background 0.2s;
    }
    
    .checkbox-group:hover {
        background: rgba(26, 26, 26, 0.03);
    }
    
    .checkbox-group input[type="checkbox"] {
        width: 20px;
        height: 20px;
        margin-right: 10px;
        margin-top: 2px;
        cursor: pointer;
        flex-shrink: 0;
    }
    
    .checkbox-group label {
        margin-bottom: 0;
        font-weight: 500;
        cursor: pointer;
        flex: 1;
    }
    
    .checkbox-group label small {
        display: block;
        color: #666;
        font-size: 12px;
        margin-top: 4px;
    }
    
    .btn-submit {
        background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        color: white;
        padding: 16px 32px;
        border: none;
        border-radius: 10px;
        font-size: 16px;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.3s;
        box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        display: inline-flex;
        align-items: center;
        gap: 10px;
        position: relative;
    }
    
    .btn-submit::before {
        content: '💾';
        font-size: 20px;
    }
    
    .btn-submit:hover {
        transform: translateY(-3px);
        box-shadow: 0 6px 25px rgba(40, 167, 69, 0.5);
    }
    
    .btn-submit:active {
        transform: translateY(-1px);
    }
    
    .alert {
        padding: 16px 20px;
        border-radius: 10px;
        margin-bottom: 20px;
        font-size: 14px;
        font-weight: 600;
        animation: slideDown 0.4s ease-out;
        display: flex;
        align-items: center;
        gap: 12px;
    }
    
    .alert-danger {
        background: linear-gradient(135deg, #f8d7da 0%, #f5c6cb 100%);
        color: #721c24;
        border: 2px solid #f5c6cb;
    }
    
    .alert-danger::before {
        content: '⚠️';
        font-size: 20px;
    }
    
    .alert-success {
        background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%);
        color: #155724;
        border: 2px solid #c3e6cb;
    }
    
    .alert-success::before {
        content: '✅';
        font-size: 20px;
    }
    
    /* Loading state */
    .btn-submit.loading {
        pointer-events: none;
        opacity: 0.7;
    }
    
    .btn-submit.loading::after {
        content: '';
        position: absolute;
        width: 16px;
        height: 16px;
        top: 50%;
        right: 20px;
        margin-top: -8px;
        border: 3px solid rgba(255,255,255,0.3);
        border-radius: 50%;
        border-top-color: white;
        animation: spin 0.8s linear infinite;
    }
    
    @keyframes spin {
        to { transform: rotate(360deg); }
    }
    
    /* Scrollbar customizada */
    .nucleos-container::-webkit-scrollbar,
    .autocomplete-suggestions::-webkit-scrollbar {
        width: 8px;
    }
    
    .nucleos-container::-webkit-scrollbar-track,
    .autocomplete-suggestions::-webkit-scrollbar-track {
        background: rgba(0,0,0,0.05);
        border-radius: 10px;
    }
    
    .nucleos-container::-webkit-scrollbar-thumb,
    .autocomplete-suggestions::-webkit-scrollbar-thumb {
        background: rgba(26, 26, 26, 0.3);
        border-radius: 10px;
    }
    
    .nucleos-container::-webkit-scrollbar-thumb:hover,
    .autocomplete-suggestions::-webkit-scrollbar-thumb:hover {
        background: rgba(26, 26, 26, 0.5);
    }
    
    /* Transições suaves para campos de formulário */
    .cliente-form {
        transition: all 0.4s ease-out;
    }
    
    @media (max-width: 768px) {
        .form-grid,
        .cliente-options {
            grid-template-columns: 1fr;
        }
        
        .page-header {
            flex-direction: column;
            gap: 15px;
            text-align: center;
        }
    }
</style>

<div class="page-header">
    <h2>Novo Atendimento</h2>
    <a href="index.php" class="btn-voltar">← Voltar</a>
</div>

<?php if ($erro): ?>
<div class="alert alert-danger">
    <?= htmlspecialchars($erro) ?>
</div>
<?php endif; ?>

<?php if ($sucesso): ?>
<div class="alert alert-success">
    <?= htmlspecialchars($sucesso) ?>
</div>
<?php endif; ?>

<div class="form-container">
    <form action="process_novo.php" method="POST" id="atendimentoForm">
        <!-- Seção: Dados do Atendimento -->
        <div class="form-section">
            <h3>📅 Dados do Atendimento</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label for="data_atendimento">Data e Hora do Atendimento *</label>
                    <input type="datetime-local" id="data_atendimento" name="data_atendimento" 
                           value="<?= date('Y-m-d\TH:i') ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="atendido_por">Atendido Por *</label>
                    <select id="atendido_por" name="atendido_por" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($usuarios as $user): ?>
                        <option value="<?= $user['id'] ?>" 
                                <?= $user['id'] == $usuario_logado['usuario_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($user['nome']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        
        <!-- Seção: Dados do Cliente -->
        <div class="form-section">
            <h3>👤 Dados do Cliente</h3>
            
            <div class="cliente-options">
                <label class="cliente-option active">
                    <input type="radio" name="tipo_cliente" value="existente" checked>
                    <h4>Cliente Existente</h4>
                    <p>Selecionar cliente já cadastrado</p>
                </label>
                <label class="cliente-option">
                    <input type="radio" name="tipo_cliente" value="novo">
                    <h4>Novo Cliente</h4>
                    <p>Informar dados manualmente</p>
                </label>
            </div>
            
            <!-- Cliente Existente -->
            <div id="cliente-existente" class="cliente-form">
                <div class="form-group autocomplete">
                    <label for="cliente_id">Cliente *</label>
                    <input type="text" id="cliente_search" placeholder="🔍 Digite o nome do cliente..." autocomplete="off">
                    <input type="hidden" id="cliente_id" name="cliente_id">
                    <div class="autocomplete-suggestions" id="cliente-suggestions"></div>
                </div>
            </div>
            
            <!-- Novo Cliente -->
            <div id="cliente-novo" class="cliente-form" style="display: none;">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="cliente_nome">Nome Completo *</label>
                        <input type="text" id="cliente_nome" name="cliente_nome" placeholder="Nome do cliente">
                    </div>
                    
                    <div class="form-group">
                        <label for="cliente_cpf_cnpj">CPF/CNPJ</label>
                        <input type="text" id="cliente_cpf_cnpj" name="cliente_cpf_cnpj" placeholder="000.000.000-00">
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Seção: Status do Atendimento -->
        <div class="form-section">
            <h3>📋 Status do Atendimento</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label for="status_contrato">Status do Contrato *</label>
                    <select id="status_contrato" name="status_contrato" required>
                        <option value="">Selecione...</option>
                        <option value="Fechado">✅ Fechado</option>
                        <option value="Não Fechou">❌ Não Fechou</option>
                        <option value="Em Análise">⏳ Em Análise</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <div class="checkbox-wrapper">
                        <input type="checkbox" id="precisa_nova_reuniao" name="precisa_nova_reuniao" value="1">
                        <label for="precisa_nova_reuniao">Precisa de nova reunião?</label>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Seção: Dados da Nova Reunião (CONDICIONAL) -->
        <div id="nova-reuniao-section" class="nova-reuniao-section">
            <h3>📅 Dados da Nova Reunião</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label for="data_nova_reuniao">Data e Hora da Nova Reunião *</label>
                    <input type="datetime-local" id="data_nova_reuniao" name="data_nova_reuniao">
                </div>
                
                <div class="form-group">
                    <label for="responsavel_nova_reuniao">Responsável pela Nova Reunião *</label>
                    <select id="responsavel_nova_reuniao" name="responsavel_nova_reuniao">
                        <option value="">Selecione...</option>
                        <?php foreach ($usuarios as $user): ?>
                        <option value="<?= $user['id'] ?>">
                            <?= htmlspecialchars($user['nome']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        
        <!-- Seção: Núcleos de Atendimento -->
        <div class="form-section">
            <h3>🏢 Núcleos de Atendimento</h3>
            <div class="form-group full-width">
                <div class="nucleos-container">
                    <h4>Selecione os núcleos que atenderão o cliente:</h4>
                    <?php foreach ($nucleos as $nucleo): ?>
                    <div class="checkbox-group">
                        <input type="checkbox" id="nucleo_<?= $nucleo['id'] ?>" 
                               name="nucleos_atendimento[]" value="<?= $nucleo['id'] ?>">
                        <label for="nucleo_<?= $nucleo['id'] ?>">
                            <?= htmlspecialchars($nucleo['nome']) ?>
                            <small><?= htmlspecialchars($nucleo['descricao']) ?></small>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- Seção: Observações -->
        <div class="form-section">
            <h3>📝 Observações</h3>
            <div class="form-group">
                <label for="observacoes">Observações do Atendimento</label>
                <textarea id="observacoes" name="observacoes" 
                          placeholder="Descreva detalhes importantes do atendimento, demandas do cliente, etc."></textarea>
            </div>
        </div>
        
        <button type="submit" class="btn-submit">Registrar Atendimento</button>
    </form>
</div>

<script>
    // Dados dos clientes para autocomplete
    const clientes = <?= json_encode($clientes) ?>;
    
    // Alternar entre cliente existente e novo COM ANIMAÇÃO
    document.querySelectorAll('input[name="tipo_cliente"]').forEach(radio => {
        radio.addEventListener('change', function() {
            const clienteExistente = document.getElementById('cliente-existente');
            const clienteNovo = document.getElementById('cliente-novo');
            const clienteOptions = document.querySelectorAll('.cliente-option');
            
            // Atualizar visual das opções
            clienteOptions.forEach(option => option.classList.remove('active'));
            this.closest('.cliente-option').classList.add('active');
            
            if (this.value === 'existente') {
                clienteNovo.style.opacity = '0';
                setTimeout(() => {
                    clienteNovo.style.display = 'none';
                    clienteExistente.style.display = 'block';
                    setTimeout(() => {
                        clienteExistente.style.opacity = '1';
                    }, 10);
                }, 300);
                
                // Limpar campos do novo cliente
                document.getElementById('cliente_nome').value = '';
                document.getElementById('cliente_cpf_cnpj').value = '';
            } else {
                clienteExistente.style.opacity = '0';
                setTimeout(() => {
                    clienteExistente.style.display = 'none';
                    clienteNovo.style.display = 'block';
                    setTimeout(() => {
                        clienteNovo.style.opacity = '1';
                    }, 10);
                }, 300);
                
                // Limpar seleção de cliente existente
                document.getElementById('cliente_search').value = '';
                document.getElementById('cliente_id').value = '';
            }
        });
    });
    
    // Autocomplete para clientes COM ANIMAÇÃO
    const clienteSearch = document.getElementById('cliente_search');
    const clienteSuggestions = document.getElementById('cliente-suggestions');
    const clienteIdInput = document.getElementById('cliente_id');
    
    clienteSearch.addEventListener('input', function() {
        const query = this.value.toLowerCase();
        
        if (query.length < 2) {
            clienteSuggestions.style.display = 'none';
            return;
        }
        
        const matches = clientes.filter(cliente => 
            cliente.nome.toLowerCase().includes(query) ||
            (cliente.cpf_cnpj && cliente.cpf_cnpj.includes(query))
        );
        
        if (matches.length > 0) {
            clienteSuggestions.innerHTML = matches.map(cliente => 
                `<div class="autocomplete-suggestion" data-id="${cliente.id}" data-nome="${cliente.nome}">
                    <strong>${cliente.nome}</strong>
                    ${cliente.cpf_cnpj ? `<br><small>📄 ${cliente.cpf_cnpj}</small>` : ''}
                </div>`
            ).join('');
            clienteSuggestions.style.display = 'block';
        } else {
            clienteSuggestions.innerHTML = '<div class="autocomplete-suggestion" style="pointer-events: none; color: #999;">Nenhum cliente encontrado</div>';
            clienteSuggestions.style.display = 'block';
        }
    });
    
    // Selecionar cliente do autocomplete COM FEEDBACK
    clienteSuggestions.addEventListener('click', function(e) {
        const suggestion = e.target.closest('.autocomplete-suggestion');
        if (suggestion && suggestion.dataset.id) {
            const clienteId = suggestion.dataset.id;
            const clienteNome = suggestion.dataset.nome;
            
            clienteSearch.value = clienteNome;
            clienteIdInput.value = clienteId;
            clienteSuggestions.style.display = 'none';
            
            // Feedback visual
            clienteSearch.style.borderColor = '#28a745';
            setTimeout(() => {
                clienteSearch.style.borderColor = '#ddd';
            }, 1000);
        }
    });
    
    // Fechar autocomplete ao clicar fora
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.autocomplete')) {
            clienteSuggestions.style.display = 'none';
        }
    });
    
    // CONTROLE DA SEÇÃO DE NOVA REUNIÃO COM ANIMAÇÃO
    document.getElementById('precisa_nova_reuniao').addEventListener('change', function() {
        const novaReuniaoSection = document.getElementById('nova-reuniao-section');
        const dataReuniaoInput = document.getElementById('data_nova_reuniao');
        const responsavelReuniaoSelect = document.getElementById('responsavel_nova_reuniao');
        
        if (this.checked) {
            // Mostrar seção com animação
            novaReuniaoSection.classList.add('show');
            dataReuniaoInput.required = true;
            responsavelReuniaoSelect.required = true;
        } else {
            // Ocultar seção
            novaReuniaoSection.classList.remove('show');
            dataReuniaoInput.required = false;
            responsavelReuniaoSelect.required = false;
            dataReuniaoInput.value = '';
            responsavelReuniaoSelect.value = '';
        }
    });
    
    // Máscara para CPF/CNPJ COM FEEDBACK
    document.getElementById('cliente_cpf_cnpj').addEventListener('input', function(e) {
        let value = e.target.value.replace(/\D/g, '');
        
        if (value.length <= 11) {
            // CPF
            value = value.replace(/(\d{3})(\d)/, '$1.$2');
            value = value.replace(/(\d{3})(\d)/, '$1.$2');
            value = value.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
        } else {
            // CNPJ
            value = value.replace(/^(\d{2})(\d)/, '$1.$2');
            value = value.replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3');
            value = value.replace(/\.(\d{3})(\d)/, '.$1/$2');
            value = value.replace(/(\d{4})(\d)/, '$1-$2');
        }
        
        e.target.value = value;
    });
    
    // Validação do formulário COM FEEDBACK VISUAL
    document.getElementById('atendimentoForm').addEventListener('submit', function(e) {
        const tipoCliente = document.querySelector('input[name="tipo_cliente"]:checked').value;
        
        if (tipoCliente === 'existente') {
            const clienteId = document.getElementById('cliente_id').value;
            if (!clienteId) {
                e.preventDefault();
                alert('⚠️ Por favor, selecione um cliente existente.');
                document.getElementById('cliente_search').focus();
                document.getElementById('cliente_search').style.borderColor = '#dc3545';
                return;
            }
        } else {
            const clienteNome = document.getElementById('cliente_nome').value;
            if (!clienteNome.trim()) {
                e.preventDefault();
                alert('⚠️ Por favor, informe o nome do cliente.');
                document.getElementById('cliente_nome').focus();
                document.getElementById('cliente_nome').style.borderColor = '#dc3545';
                return;
            }
        }
        
        // Verificar se pelo menos um núcleo foi selecionado
        const nucleosSelecionados = document.querySelectorAll('input[name="nucleos_atendimento[]"]:checked');
        if (nucleosSelecionados.length === 0) {
            e.preventDefault();
            alert('⚠️ Por favor, selecione pelo menos um núcleo de atendimento.');
            document.querySelector('.nucleos-container').style.borderColor = '#dc3545';
            document.querySelector('.nucleos-container').scrollIntoView({ behavior: 'smooth' });
            return;
        }
        
        // Adicionar loading ao botão
        const btnSubmit = this.querySelector('.btn-submit');
        btnSubmit.classList.add('loading');
        btnSubmit.innerHTML = '💾 Registrando...';
    });
    
    // Feedback visual ao focar nos campos
    document.querySelectorAll('input, select, textarea').forEach(element => {
        element.addEventListener('focus', function() {
            this.parentElement.style.transform = 'scale(1.01)';
            this.parentElement.style.transition = 'transform 0.2s';
        });
        
        element.addEventListener('blur', function() {
            this.parentElement.style.transform = 'scale(1)';
            // Reset border color on blur if it was set to error
            if (this.style.borderColor === 'rgb(220, 53, 69)') {
                this.style.borderColor = '#ddd';
            }
        });
    });
    
    // Contador de caracteres para observações
    const observacoesTextarea = document.getElementById('observacoes');
    const contadorDiv = document.createElement('div');
    contadorDiv.style.cssText = 'text-align: right; color: #666; font-size: 12px; margin-top: 5px;';
    observacoesTextarea.parentElement.appendChild(contadorDiv);
    
    observacoesTextarea.addEventListener('input', function() {
        const count = this.value.length;
        contadorDiv.textContent = `${count} caracteres`;
        if (count > 500) {
            contadorDiv.style.color = '#dc3545';
        } else if (count > 400) {
            contadorDiv.style.color = '#ffc107';
        } else {
            contadorDiv.style.color = '#666';
        }
    });
    
    // Animação inicial dos elementos
    document.addEventListener('DOMContentLoaded', function() {
        const sections = document.querySelectorAll('.form-section');
        sections.forEach((section, index) => {
            section.style.opacity = '0';
            section.style.transform = 'translateY(20px)';
            setTimeout(() => {
                section.style.transition = 'all 0.5s ease-out';
                section.style.opacity = '1';
                section.style.transform = 'translateY(0)';
            }, 100 * index);
        });
    });
    
    // Adicionar tooltips aos campos obrigatórios
    document.querySelectorAll('input[required], select[required]').forEach(element => {
        const label = element.parentElement.querySelector('label');
        if (label && !label.querySelector('.required-indicator')) {
            const indicator = document.createElement('span');
            indicator.className = 'required-indicator';
            indicator.textContent = ' *';
            indicator.style.color = '#dc3545';
            indicator.title = 'Campo obrigatório';
            label.appendChild(indicator);
        }
    });
</script>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Novo Atendimento', $conteudo, 'atendimentos');
?>