<?php
/**
 * FORMULÁRIO SIMPLIFICADO DE CADASTRO DE PROCESSO
 * Versão popup para uso no tratamento de publicações
 */

// Carregar database e auth PRIMEIRO
require_once '../../config/database.php';
require_once '../../includes/auth.php';

// DEPOIS validar token se necessário
if (!isset($_SESSION['usuario_id']) && isset($_GET['token'])) {
    $token = $_GET['token'];
    $sql = "SELECT s.*, u.id as uid, u.nome, u.email, u.nivel_acesso 
            FROM sessoes s 
            INNER JOIN usuarios u ON s.usuario_id = u.id 
            WHERE s.token = ? AND s.ativo = 1";
    
    $stmt = executeQuery($sql, [$token]);
    $sessao = $stmt->fetch();
    
    if ($sessao) {
        $_SESSION['usuario_id'] = $sessao['uid'];
        $_SESSION['usuario_nome'] = $sessao['nome'];
        $_SESSION['usuario_email'] = $sessao['email'];
        $_SESSION['nivel_acesso'] = $sessao['nivel_acesso'];
        $_SESSION['token'] = $token;
        $_SESSION['popup_auth'] = true;
    }
}

Auth::protect();

$usuario_logado = Auth::user();
$session_token = $_SESSION['token'] ?? ''; // Token para popups
$modo_popup = isset($_GET['popup']) && $_GET['popup'] == '1';

// Buscar núcleos ativos
$sql = "SELECT * FROM nucleos WHERE ativo = 1 ORDER BY nome";
$stmt = executeQuery($sql);
$nucleos = $stmt->fetchAll();

// Buscar usuários ativos para responsável
$sql = "SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome";
$stmt = executeQuery($sql);
$usuarios = $stmt->fetchAll();

// Definir tipos de processo por núcleo
$tipos_por_nucleo = [];
foreach ($nucleos as $nucleo) {
    switch ($nucleo['nome']) {
        case 'Família':
            $tipos_por_nucleo[$nucleo['id']] = [
                'Alimentos', 'Cumprimento de Sentença – Prisão', 'Cumprimento de Sentença – Penhora',
                'Execução de Alimentos - Prisão', 'Execução de Alimentos - Penhora', 'Inventário',
                'Medida Protetiva', 'Pedidos diversos – completo', 'Divórcio', 'Dissolução',
                'Alimentos e Guarda', 'Negatória de Paternidade'
            ];
            break;
        case 'Criminal':
            $tipos_por_nucleo[$nucleo['id']] = ['Habeas Corpus', 'Ação Penal', 'Recurso Criminal', 'Execução Penal'];
            break;
        case 'Trabalhista':
            $tipos_por_nucleo[$nucleo['id']] = ['Reclamação Trabalhista', 'Recurso Ordinário', 'Execução Trabalhista', 'Mandado de Segurança'];
            break;
        case 'Bancário':
            $tipos_por_nucleo[$nucleo['id']] = ['Ação Revisional', 'Busca e Apreensão', 'Execução', 'Consignação em Pagamento'];
            break;
        case 'Previdenciário':
            $tipos_por_nucleo[$nucleo['id']] = ['Aposentadoria', 'Auxílio-doença', 'Pensão por morte', 'Revisão de benefício'];
            break;
        case 'Cobrança':
            $tipos_por_nucleo[$nucleo['id']] = [
                'Ação de Cobrança', 'Ação de Cobrança de Aluguel', 'Ação Revisional de Aluguel',
                'Usucapião', 'Ação de Reintegração de Posse', 'Ação Demarcatória',
                'Ação de Adjudicação Compulsória', 'Rescisão de Contrato de Locação', 'Renovatória de Locação'
            ];
            break;
        case 'Criminal Econômico':
            $tipos_por_nucleo[$nucleo['id']] = [
                'Dissolução de Sociedade', 'Recuperação Judicial', 'Falência', 
                'Ação de Cobrança Empresarial', 'Arbitragem', 'Contrato Social', 
                'Medida Cautelar', 'Ação Declaratória'
            ];
            break;
        case 'Sucessões':
            $tipos_por_nucleo[$nucleo['id']] = [
                'Mandado de Segurança Tributário', 'Ação Anulatória de Débito Fiscal',
                'Embargos à Execução Fiscal', 'Ação Declaratória de Inexistência de Relação Jurídico-Tributária',
                'Impugnação de Lançamento', 'Compensação Tributária', 'Recurso Administrativo'
            ];
            break;
        case 'Empresarial':
            $tipos_por_nucleo[$nucleo['id']] = [
                'Indenização por Danos Morais', 'Indenização por Danos Materiais',
                'Revisão de Contrato', 'Cancelamento de Contrato', 'Devolução de Valores',
                'Defeito de Produto', 'Vício de Serviço', 'Cobrança Indevida'
            ];
            break;
        default:
            $tipos_por_nucleo[$nucleo['id']] = ['Outros'];
            break;
    }
}

// Situações processuais
$situacoes_processuais = [
    'Em Andamento', 'Transitado', 'Em Cumprimento de Sentença',
    'Em Processo de Renúncia', 'Baixado', 'Renunciado', 'Em Grau Recursal'
];

// Buscar clientes para autocomplete
$sql = "SELECT id, nome, cpf_cnpj FROM clientes WHERE ativo = 1 ORDER BY nome LIMIT 200";
$stmt = executeQuery($sql);
$clientes = $stmt->fetchAll();

$erro = $_SESSION['erro'] ?? '';
$sucesso = $_SESSION['sucesso'] ?? '';
unset($_SESSION['erro'], $_SESSION['sucesso']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $modo_popup ? 'Cadastrar Processo' : 'Novo Processo' ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
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
            padding: 20px;
        }
        
        .container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header h2 {
            font-size: 24px;
            font-weight: 600;
        }
        
        .btn-fechar {
            background: rgba(255,255,255,0.2);
            border: none;
            color: white;
            width: 35px;
            height: 35px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 20px;
            transition: all 0.3s;
        }
        
        .btn-fechar:hover {
            background: rgba(255,255,255,0.3);
            transform: rotate(90deg);
        }
        
        .form-content {
            padding: 30px;
        }
        
        .alert {
            padding: 15px 20px;
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
        
        .form-section {
            margin-bottom: 30px;
        }
        
        .form-section h3 {
            color: #333;
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #667eea;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .form-grid.three-cols {
            grid-template-columns: 1fr 1fr 1fr;
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
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
            font-family: inherit;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        /* Campo com botão ao lado */
        .input-with-button {
            position: relative;
            display: flex;
            gap: 10px;
        }
        
        .input-with-button input {
            flex: 1;
        }
        
        .btn-add-cliente {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border: none;
            padding: 12px 20px;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            white-space: nowrap;
            box-shadow: 0 4px 15px rgba(40, 167, 69, 0.3);
        }
        
        .btn-add-cliente:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(40, 167, 69, 0.4);
        }
        
        /* Autocomplete */
        .autocomplete-container {
            position: relative;
        }
        
        .autocomplete-suggestions {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 2px solid #667eea;
            border-top: none;
            border-radius: 0 0 8px 8px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-shadow: 0 10px 25px rgba(0,0,0,0.15);
        }
        
        .autocomplete-suggestions.active {
            display: block;
        }
        
        .autocomplete-suggestion {
            padding: 12px 15px;
            cursor: pointer;
            transition: all 0.2s;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .autocomplete-suggestion:last-child {
            border-bottom: none;
        }
        
        .autocomplete-suggestion:hover,
        .autocomplete-suggestion.active {
            background: #f8f9ff;
        }
        
        .autocomplete-suggestion strong {
            color: #333;
            display: block;
            margin-bottom: 3px;
        }
        
        .autocomplete-suggestion small {
            color: #666;
            font-size: 12px;
        }
        
        /* Cliente Selecionado */
        .cliente-selecionado {
            background: #e7f3ff;
            border: 2px solid #667eea;
            border-radius: 8px;
            padding: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .cliente-selecionado-info {
            flex: 1;
        }
        
        .cliente-selecionado-nome {
            font-weight: 600;
            color: #333;
            margin-bottom: 5px;
        }
        
        .cliente-selecionado-doc {
            font-size: 13px;
            color: #666;
        }
        
        .btn-remover-cliente {
            background: #dc3545;
            color: white;
            border: none;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            cursor: pointer;
            font-size: 16px;
            transition: all 0.3s;
        }
        
        .btn-remover-cliente:hover {
            background: #c82333;
            transform: scale(1.1);
        }
        
        /* Checkbox estilizado */
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
            background: rgba(40, 167, 69, 0.1);
            padding: 12px;
            border-radius: 8px;
            border: 1px solid rgba(40, 167, 69, 0.3);
        }
        
        .checkbox-group input[type="checkbox"] {
            width: auto !important;
            margin: 0;
        }
        
        .checkbox-group label {
            margin: 0 !important;
            color: #155724;
            font-weight: 600 !important;
        }
        
        /* Parte Item */
        .parte-item {
            background: #f8f9fa;
            border: 2px solid #e9ecef;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
        }
        
        .parte-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #dee2e6;
        }
        
        .parte-header h4 {
            color: #333;
            font-size: 16px;
            font-weight: 600;
        }
        
        .btn-remover-parte {
            background: #dc3545;
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 6px;
            font-size: 13px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-remover-parte:hover {
            background: #c82333;
        }
        
        .btn-adicionar-parte {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
            width: 100%;
        }
        
        .btn-adicionar-parte:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.4);
        }
        
        /* Botões de ação */
        .form-actions {
            display: flex;
            gap: 15px;
            padding-top: 20px;
            border-top: 2px solid #e9ecef;
        }
        
        .btn-submit {
            flex: 1;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border: none;
            padding: 15px 30px;
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
        
        .btn-submit:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        .btn-cancelar {
            background: #6c757d;
            color: white;
            border: none;
            padding: 15px 30px;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn-cancelar:hover {
            background: #5a6268;
        }
        
        @media (max-width: 768px) {
            .form-grid,
            .form-grid.three-cols {
                grid-template-columns: 1fr;
            }
            
            .input-with-button {
                flex-direction: column;
            }
            
            body {
                padding: 10px;
            }
        }
    </style>
</head>
<?php include __DIR__ . '/../clientes/modal_cadastro_cliente_rapido.php'; ?>
<body>
    <div class="container">
        <div class="header">
            <h2><i class="fas fa-folder-plus"></i> <?= $modo_popup ? 'Cadastrar Processo' : 'Novo Processo' ?></h2>
            <?php if ($modo_popup): ?>
            <button type="button" class="btn-fechar" onclick="window.close()">
                <i class="fas fa-times"></i>
            </button>
            <?php endif; ?>
        </div>
        
        <div class="form-content">
            <?php if (!empty($erro)): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle"></i> <?= $erro ?>
            </div>
            <?php endif; ?>
            
            <?php if (!empty($sucesso)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($sucesso) ?>
            </div>
            <?php endif; ?>
            
            <form action="process_novo_simplificado.php<?= $modo_popup ? '?popup=1' : '' ?>" method="POST" id="processoForm">
                <!-- Dados Básicos -->
                <div class="form-section">
                    <h3><i class="fas fa-info-circle"></i> Dados do Processo</h3>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="nucleo_id">Núcleo *</label>
                            <select name="nucleo_id" id="nucleo_id" required onchange="atualizarTiposProcesso()">
                                <option value="">Selecione...</option>
                                <?php foreach ($nucleos as $nucleo): ?>
                                    <option value="<?= $nucleo['id'] ?>">
                                        <?= htmlspecialchars($nucleo['nome']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="numero_processo">Número do Processo *</label>
                            <input type="text" id="numero_processo" name="numero_processo" required
                                   placeholder="0000000-00.0000.0.00.0000">
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="tipo_processo">Tipo de Processo *</label>
                            <select id="tipo_processo" name="tipo_processo" required>
                                <option value="">Primeiro selecione o núcleo</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="situacao_processual">Situação Processual *</label>
                            <select id="situacao_processual" name="situacao_processual" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($situacoes_processuais as $situacao): ?>
                                <option value="<?= $situacao ?>" <?= $situacao === 'Em Andamento' ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($situacao) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="comarca">Comarca</label>
                            <input type="text" id="comarca" name="comarca" 
                                   placeholder="Ex: Comarca de São Paulo">
                        </div>
                        
                        <div class="form-group">
                            <label for="valor_causa">Valor da Causa</label>
                            <input type="text" id="valor_causa" name="valor_causa" 
                                   class="money-input" placeholder="0,00">
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="responsavel_id">Responsável *</label>
                            <select name="responsavel_id" id="responsavel_id" required>
                                <option value="">Selecione...</option>
                                <?php foreach ($usuarios as $usuario): ?>
                                    <option value="<?= $usuario['id'] ?>" 
                                            <?= $usuario['id'] == $usuario_logado['usuario_id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($usuario['nome']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="fase_atual">Fase Atual</label>
                            <input type="text" id="fase_atual" name="fase_atual" 
                                   placeholder="Ex: Instrução, Julgamento...">
                        </div>
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="anotacoes">Observações</label>
                        <textarea id="anotacoes" name="anotacoes" 
                                  placeholder="Observações gerais sobre o processo..."></textarea>
                    </div>
                </div>
                
                <!-- Partes do Processo -->
                <div class="form-section">
                    <h3><i class="fas fa-users"></i> Partes do Processo</h3>
                    
                    <div id="partesContainer">
                        <!-- Partes serão adicionadas aqui via JavaScript -->
                    </div>
                    
                    <button type="button" class="btn-adicionar-parte" onclick="adicionarParte()">
                        <i class="fas fa-plus-circle"></i> Adicionar Parte
                    </button>
                </div>
                
                <!-- Ações -->
                <div class="form-actions">
                    <?php if ($modo_popup): ?>
                    <button type="button" class="btn-cancelar" onclick="window.close()">
                        <i class="fas fa-times"></i> Cancelar
                    </button>
                    <?php else: ?>
                    <button type="button" class="btn-cancelar" onclick="window.location.href='index.php'">
                        <i class="fas fa-arrow-left"></i> Voltar
                    </button>
                    <?php endif; ?>
                    <button type="submit" class="btn-submit" id="btnSubmit">
                        <i class="fas fa-save"></i> Salvar Processo
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Dados
        const tiposPorNucleo = <?= json_encode($tipos_por_nucleo) ?>;
        const clientes = <?= json_encode($clientes) ?>;
        const modoPopup = <?= $modo_popup ? 'true' : 'false' ?>;
        const sessionToken = '<?= $session_token ?>'; // Token para autenticação
        
        let parteCounter = 0;
        
        // Atualizar tipos de processo
        function atualizarTiposProcesso() {
            const nucleoId = document.getElementById('nucleo_id').value;
            const selectTipo = document.getElementById('tipo_processo');
            
            selectTipo.innerHTML = '<option value="">Selecione...</option>';
            
            if (nucleoId && tiposPorNucleo[nucleoId]) {
                tiposPorNucleo[nucleoId].forEach(tipo => {
                    const option = document.createElement('option');
                    option.value = tipo;
                    option.textContent = tipo;
                    selectTipo.appendChild(option);
                });
            }
        }
        
        // Adicionar parte
        function adicionarParte() {
            parteCounter++;
            const container = document.getElementById('partesContainer');
            
            const parteDiv = document.createElement('div');
            parteDiv.className = 'parte-item';
            parteDiv.id = `parte-${parteCounter}`;
            
            parteDiv.innerHTML = `
                <div class="parte-header">
                    <h4><i class="fas fa-user"></i> Parte #${parteCounter}</h4>
                    <button type="button" class="btn-remover-parte" onclick="removerParte(${parteCounter})">
                        <i class="fas fa-trash"></i> Remover
                    </button>
                </div>
                
                <div class="form-grid">
                    <div class="form-group">
                        <label>Tipo de Parte *</label>
                        <select name="partes[${parteCounter}][tipo_parte]" required>
                            <option value="">Selecione...</option>
                            <option value="Autor">Autor</option>
                            <option value="Exequente">Exequente</option>
                            <option value="Réu">Réu</option>
                            <option value="Executado">Executado</option>
                            <option value="Outros">Outros</option>
                        </select>
                    </div>
                    
                    <div class="form-group autocomplete-container">
                        <label>Nome da Parte *</label>
                        <div class="input-with-button">
                            <input type="text" 
                                   class="parte-nome-input" 
                                   name="partes[${parteCounter}][nome]" 
                                   data-parte-id="${parteCounter}"
                                   placeholder="Digite o nome ou busque..." 
                                   required
                                   autocomplete="off">
                            <button type="button" class="btn-add-cliente" onclick="abrirCadastroCliente(${parteCounter})">
                                <i class="fas fa-user-plus"></i> Novo
                            </button>
                        </div>
                        <input type="hidden" name="partes[${parteCounter}][cliente_id]" id="cliente-id-${parteCounter}">
                        <div class="autocomplete-suggestions" id="suggestions-${parteCounter}"></div>
                    </div>
                </div>
                
                <div id="cliente-selecionado-${parteCounter}"></div>
                
                <div class="checkbox-group">
                    <input type="checkbox" 
                           name="partes[${parteCounter}][e_nosso_cliente]" 
                           id="cliente-${parteCounter}" 
                           value="1">
                    <label for="cliente-${parteCounter}">
                        <i class="fas fa-check-circle"></i> Esta parte é nosso cliente (quem nos contratou)
                    </label>
                </div>
            `;
            
            container.appendChild(parteDiv);
            setupAutocomplete(parteCounter);
        }
        
        // Remover parte
        function removerParte(id) {
            const parte = document.getElementById(`parte-${id}`);
            if (parte) {
                parte.remove();
            }
        }
        
        // Setup autocomplete
        function setupAutocomplete(parteId) {
            const input = document.querySelector(`[data-parte-id="${parteId}"]`);
            const suggestions = document.getElementById(`suggestions-${parteId}`);
            const clienteIdInput = document.getElementById(`cliente-id-${parteId}`);
            let selectedIndex = -1;

            input.addEventListener('input', function() {
                const query = this.value.toLowerCase();
                selectedIndex = -1;

                if (query.length < 2) {
                    suggestions.classList.remove('active');
                    clienteIdInput.value = '';
                    return;
                }

                const matches = clientes.filter(cliente => 
                    cliente.nome.toLowerCase().includes(query) ||
                    (cliente.cpf_cnpj && cliente.cpf_cnpj.includes(query))
                );

                if (matches.length > 0) {
                    suggestions.innerHTML = matches.map(cliente => 
                        `<div class="autocomplete-suggestion" data-id="${cliente.id}" data-nome="${cliente.nome}" data-doc="${cliente.cpf_cnpj || ''}">
                            <strong>${cliente.nome}</strong>
                            ${cliente.cpf_cnpj ? `<small>${cliente.cpf_cnpj}</small>` : ''}
                        </div>`
                    ).join('');
                    suggestions.classList.add('active');
                } else {
                    suggestions.classList.remove('active');
                }
            });

            input.addEventListener('keydown', function(e) {
                const items = suggestions.querySelectorAll('.autocomplete-suggestion');

                if (!suggestions.classList.contains('active') || items.length === 0) return;

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    selectedIndex = (selectedIndex + 1) % items.length;
                    updateSelection(items);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    selectedIndex = selectedIndex <= 0 ? items.length - 1 : selectedIndex - 1;
                    updateSelection(items);
                } else if (e.key === 'Enter' || e.key === 'Tab') {
                    if (selectedIndex >= 0 && selectedIndex < items.length) {
                        e.preventDefault();
                        selectItem(items[selectedIndex]);
                    }
                } else if (e.key === 'Escape') {
                    suggestions.classList.remove('active');
                    selectedIndex = -1;
                }
            });

            function updateSelection(items) {
                items.forEach((item, index) => {
                    if (index === selectedIndex) {
                        item.classList.add('active');
                        item.scrollIntoView({ block: 'nearest' });
                    } else {
                        item.classList.remove('active');
                    }
                });
            }

            function selectItem(item) {
                const clienteId = item.dataset.id;
                const clienteNome = item.dataset.nome;
                const clienteDoc = item.dataset.doc;
                
                input.value = clienteNome;
                clienteIdInput.value = clienteId;
                suggestions.classList.remove('active');
                selectedIndex = -1;
                
                // Mostrar cliente selecionado
                mostrarClienteSelecionado(parteId, clienteNome, clienteDoc);
            }

            suggestions.addEventListener('click', function(e) {
                const suggestion = e.target.closest('.autocomplete-suggestion');
                if (suggestion) {
                    selectItem(suggestion);
                }
            });

            document.addEventListener('click', function(e) {
                if (!e.target.closest(`[data-parte-id="${parteId}"]`) && 
                    !e.target.closest(`#suggestions-${parteId}`)) {
                    suggestions.classList.remove('active');
                    selectedIndex = -1;
                }
            });
        }
        
        // Mostrar cliente selecionado
        function mostrarClienteSelecionado(parteId, nome, doc) {
            const container = document.getElementById(`cliente-selecionado-${parteId}`);
            
            if (!container) {
                console.error('❌ Container não encontrado para parte:', parteId);
                return;
            }
            
            container.innerHTML = `
                <div class="cliente-selecionado">
                    <div class="cliente-selecionado-info">
                        <div class="cliente-selecionado-nome">
                            <i class="fas fa-check-circle"></i> ${nome}
                        </div>
                        ${doc ? `<div class="cliente-selecionado-doc">${doc}</div>` : ''}
                    </div>
                    <button type="button" class="btn-remover-cliente" onclick="removerClienteSelecionado(${parteId})">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
            
            console.log('✅ Cliente exibido no container');
        }
        
        // Remover cliente selecionado
        function removerClienteSelecionado(parteId) {
            const input = document.querySelector(`[data-parte-id="${parteId}"]`);
            const clienteIdInput = document.getElementById(`cliente-id-${parteId}`);
            const container = document.getElementById(`cliente-selecionado-${parteId}`);
            
            if (input) input.value = '';
            if (clienteIdInput) clienteIdInput.value = '';
            if (container) container.innerHTML = '';
        }
        
        // Abrir cadastro de cliente
        function abrirCadastroCliente(parteId) {
            console.log('=== ABRINDO MODAL DE CLIENTE (SIMPLIFICADO) ===');
            console.log('Parte ID:', parteId);
            
            // Guardar referência da parte para quando o cliente for criado
            window.parteIdAtual = parteId;
            console.log('✅ ParteIdAtual salvo:', window.parteIdAtual);
            
            // Usar modal ao invés de popup
            window.abrirModalCadastroCliente(parteId);
        }
        
        // Função chamada pelo popup de cliente quando um novo cliente é criado
        window.selecionarClienteCriado = function(clienteId, clienteNome, clienteDoc) {
            const parteId = window.parteIdAtual;
            
            // Adicionar cliente à lista
            clientes.push({
                id: clienteId,
                nome: clienteNome,
                cpf_cnpj: clienteDoc
            });
            
            // Selecionar no campo
            const input = document.querySelector(`[data-parte-id="${parteId}"]`);
            const clienteIdInput = document.getElementById(`cliente-id-${parteId}`);
            
            input.value = clienteNome;
            clienteIdInput.value = clienteId;
            
            // Mostrar cliente selecionado
            mostrarClienteSelecionado(parteId, clienteNome, clienteDoc);
        };
        
        // Função para formatar em Real Brasileiro
        function formatarReal(valor) {
            // Remove tudo que não é número
            valor = valor.replace(/\D/g, '');
            
            // Se vazio, retorna vazio
            if (valor === '' || valor === '0') {
                return '';
            }
            
            // Converte para número e formata
            valor = (parseInt(valor) / 100).toFixed(2);
            
            // Separa parte inteira da decimal
            let partes = valor.split('.');
            let inteiro = partes[0];
            let decimal = partes[1];
            
            // Adiciona separador de milhares
            inteiro = inteiro.replace(/\B(?=(\d{3})+(?!\d))/g, '.');
            
            // Retorna formatado
            return inteiro + ',' + decimal;
        }
        
        // Máscara de dinheiro
        document.addEventListener('input', function(e) {
            if (e.target.classList.contains('money-input')) {
                e.target.value = formatarReal(e.target.value);
            }
        });
        
        // Validação do formulário
        document.getElementById('processoForm').addEventListener('submit', async function(e) {
            const nucleoId = document.getElementById('nucleo_id').value;
            if (!nucleoId) {
                e.preventDefault();
                alert('Por favor, selecione um núcleo.');
                return;
            }
            
            const partes = document.querySelectorAll('.parte-item');
            if (partes.length === 0) {
                e.preventDefault();
                alert('Por favor, adicione pelo menos uma parte ao processo.');
                return;
            }
            
            const temNossoCliente = document.querySelector('input[name*="[e_nosso_cliente]"]:checked');
            if (!temNossoCliente) {
                const confirmar = confirm('Nenhuma parte foi marcada como "nosso cliente". Deseja continuar mesmo assim?');
                if (!confirmar) {
                    e.preventDefault();
                    return;
                }
            }
            
            // Se for modo popup, fazer submit via AJAX
            if (modoPopup) {
                e.preventDefault();
                
                const btnSubmit = document.getElementById('btnSubmit');
                btnSubmit.disabled = true;
                btnSubmit.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
                
                try {
                    const formData = new FormData(this);
                    const response = await fetch('process_novo_simplificado.php?popup=1', {
                        method: 'POST',
                        body: formData
                    });
                    
                    const result = await response.json();
                    
                    if (result.success) {
                        // Notificar janela pai (modal de tratamento)
                        if (window.opener && !window.opener.closed) {
                            window.opener.selecionarProcessoCriado(
                                result.processo_id,
                                result.numero_processo,
                                result.cliente_nome
                            );
                        }
                        
                        // Fechar popup
                        alert('✅ ' + result.message);
                        window.close();
                    } else {
                        alert('❌ ' + result.message);
                        btnSubmit.disabled = false;
                        btnSubmit.innerHTML = '<i class="fas fa-save"></i> Salvar Processo';
                    }
                } catch (error) {
                    console.error(error);
                    alert('❌ Erro ao salvar processo. Tente novamente.');
                    btnSubmit.disabled = false;
                    btnSubmit.innerHTML = '<i class="fas fa-save"></i> Salvar Processo';
                }
            } else {
                // Modo normal, deixa submeter normalmente
                const btnSubmit = document.getElementById('btnSubmit');
                btnSubmit.disabled = true;
                btnSubmit.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
            }
        });
        
        // Adicionar primeira parte automaticamente
        document.addEventListener('DOMContentLoaded', function() {
            adicionarParte();
        });
    </script>
</body>
</html>