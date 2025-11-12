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

$usuario_logado = Auth::user();

// Buscar o processo
$sql = "SELECT p.*, n.nome as nucleo_nome FROM processos p 
        INNER JOIN nucleos n ON p.nucleo_id = n.id 
        WHERE p.id = ?";
$stmt = executeQuery($sql, [$processo_id]);
$processo = $stmt->fetch();

if (!$processo) {
    $_SESSION['erro'] = 'Processo não encontrado';
    header('Location: index.php');
    exit;
}

// Buscar partes do processo
$sql = "SELECT * FROM processo_partes WHERE processo_id = ? ORDER BY ordem ASC, id ASC";
$stmt = executeQuery($sql, [$processo_id]);
$partes_processo = $stmt->fetchAll();

// Buscar TODOS os núcleos ativos
$sql = "SELECT * FROM nucleos WHERE ativo = 1 ORDER BY nome";
$stmt = executeQuery($sql);
$nucleos = $stmt->fetchAll();

// Buscar TODOS os usuários ativos
$sql = "SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome";
$stmt = executeQuery($sql);
$usuarios = $stmt->fetchAll();

// Token da sessão para passar aos popups

// Buscar TODOS os núcleos ativos
$sql = "SELECT * FROM nucleos WHERE ativo = 1 ORDER BY nome";
$stmt = executeQuery($sql);
$nucleos = $stmt->fetchAll();

// Buscar TODOS os usuários ativos para o select de responsável
$sql = "SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome";
$stmt = executeQuery($sql);
$usuarios = $stmt->fetchAll();

// Definir tipos de processo por núcleo
$tipos_por_nucleo = [];
foreach ($nucleos as $nucleo) {
    switch ($nucleo['nome']) {
        case 'Família':
            $tipos_por_nucleo[$nucleo['id']] = [
                'Alimentos',
                'Cumprimento de Sentença – Prisão',
                'Cumprimento de Sentença – Penhora',
                'Execução de Alimentos - Prisão',
                'Execução de Alimentos - Penhora',
                'Inventário',
                'Medida Protetiva',
                'Pedidos diversos – completo',
                'Divórcio',
                'Dissolução',
                'Alimentos e Guarda',
                'Negatória de Paternidade'
            ];
            break;
        case 'Criminal':
            $tipos_por_nucleo[$nucleo['id']] = [
                'Habeas Corpus',
                'Ação Penal',
                'Recurso Criminal',
                'Execução Penal'
            ];
            break;
        case 'Trabalhista':
            $tipos_por_nucleo[$nucleo['id']] = [
                'Reclamação Trabalhista',
                'Recurso Ordinário',
                'Execução Trabalhista',
                'Mandado de Segurança'
            ];
            break;
        case 'Bancário':
            $tipos_por_nucleo[$nucleo['id']] = [
                'Ação Revisional',
                'Busca e Apreensão',
                'Execução',
                'Consignação em Pagamento'
            ];
            break;
        case 'Previdenciário':
            $tipos_por_nucleo[$nucleo['id']] = [
                'Aposentadoria',
                'Auxílio-doença',
                'Pensão por morte',
                'Revisão de benefício'
            ];
            break;
        case 'Cobrança':
            $tipos_por_nucleo[$nucleo['id']] = [
                'Ação de Cobrança',
                'Ação de Cobrança de Aluguel',
                'Ação Revisional de Aluguel',
                'Usucapião',
                'Ação de Reintegração de Posse',
                'Ação Demarcatória',
                'Ação de Adjudicação Compulsória',
                'Rescisão de Contrato de Locação',
                'Renovatória de Locação'
            ];
            break;
            
        case 'Criminal Econômico':
            $tipos_por_nucleo[$nucleo['id']] = [
                'Dissolução de Sociedade',
                'Recuperação Judicial',
                'Falência',
                'Ação de Cobrança Empresarial',
                'Arbitragem',
                'Contrato Social',
                'Medida Cautelar',
                'Ação Declaratória'
            ];
            break;
            
        case 'Sucessões':
            $tipos_por_nucleo[$nucleo['id']] = [
                'Mandado de Segurança Tributário',
                'Ação Anulatória de Débito Fiscal',
                'Embargos à Execução Fiscal',
                'Ação Declaratória de Inexistência de Relação Jurídico-Tributária',
                'Impugnação de Lançamento',
                'Compensação Tributária',
                'Recurso Administrativo'
            ];
            break;
            
        case 'Empresarial':
            $tipos_por_nucleo[$nucleo['id']] = [
                'Indenização por Danos Morais',
                'Indenização por Danos Materiais',
                'Revisão de Contrato',
                'Cancelamento de Contrato',
                'Devolução de Valores',
                'Defeito de Produto',
                'Vício de Serviço',
                'Cobrança Indevida'
            ];
        default:
            $tipos_por_nucleo[$nucleo['id']] = ['Outros'];
            break;
    }
}

// Buscar clientes para autocomplete
$sql = "SELECT id, nome, cpf_cnpj FROM clientes WHERE ativo = 1 ORDER BY nome LIMIT 200";
$stmt = executeQuery($sql);
$clientes = $stmt->fetchAll();

$erro = $_SESSION['erro'] ?? '';
$sucesso = $_SESSION['sucesso'] ?? '';
unset($_SESSION['erro'], $_SESSION['sucesso']);

// Situações processuais
$situacoes_processuais = [
    'Em Andamento',
    'Transitado',
    'Em Cumprimento de Sentença',
    'Em Processo de Renúncia',
    'Baixado',
    'Renunciado',
    'Em Grau Recursal'
];

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
        max-width: 1200px;
        margin: 0 auto;
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
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }

    @media (min-width: 1200px) {
        .form-grid {
            grid-template-columns: 1fr 1fr 1fr;
        }
    }

    @media (min-width: 768px) and (max-width: 1199px) {
        .form-grid {
            grid-template-columns: 1fr 1fr;
        }
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
    
    .form-group {
		margin-bottom: 20px;
		position: relative;
	}

	.autocomplete-suggestions {
		position: absolute;
		top: 100%;
		left: 0;
		right: 0;
		background: white;
		border: 1px solid #ddd;
		border-top: none;
		border-radius: 0 0 8px 8px;
		max-height: 200px;
		overflow-y: auto;
		z-index: 9999;
		display: none;
		box-shadow: 0 4px 10px rgba(0,0,0,0.1);
		margin-top: -1px;
	}

	.autocomplete-suggestion {
		padding: 12px;
		cursor: pointer;
		border-bottom: 1px solid rgba(0,0,0,0.05);
	}

	.autocomplete-suggestion:hover,
	.autocomplete-suggestion.active {
		background: rgba(26, 26, 26, 0.05);
	}

	.autocomplete-suggestion:last-child {
		border-bottom: none;
	}
    
    .autocomplete-suggestion {
        padding: 12px;
        cursor: pointer;
        border-bottom: 1px solid rgba(0,0,0,0.05);
    }
    
    .autocomplete-suggestion:hover {
        background: rgba(26, 26, 26, 0.05);
    }
    
    .autocomplete-suggestion:last-child {
        border-bottom: none;
    }

    /* Estilos para Partes do Processo */
    .partes-container {
        border: 2px solid #e9ecef;
        border-radius: 12px;
        padding: 20px;
        background: rgba(0,0,0,0.02);
    }

    .parte-item {
        background: white;
        border: 1px solid #dee2e6;
        border-radius: 8px;
        padding: 15px;
        margin-bottom: 15px;
        position: relative;
    }

    .parte-item:last-child {
        margin-bottom: 0;
    }

    .parte-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
        padding-bottom: 10px;
        border-bottom: 1px solid #e9ecef;
    }

    .parte-header h4 {
        color: #1a1a1a;
        font-size: 16px;
        font-weight: 600;
    }

    .btn-remover-parte {
        background: #dc3545;
        color: white;
        border: none;
        padding: 6px 12px;
        border-radius: 5px;
        font-size: 12px;
        cursor: pointer;
        transition: all 0.3s;
    }

    .btn-remover-parte:hover {
        background: #c82333;
        transform: scale(1.05);
    }

    .btn-adicionar-parte {
        background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
        color: white;
        border: none;
        padding: 12px 24px;
        border-radius: 8px;
        font-size: 14px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        box-shadow: 0 4px 15px rgba(0, 123, 255, 0.3);
        width: 100%;
        margin-top: 15px;
    }

    .btn-adicionar-parte:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0, 123, 255, 0.4);
    }

    .checkbox-group {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px;
        background: rgba(40, 167, 69, 0.1);
        border: 1px solid rgba(40, 167, 69, 0.3);
        border-radius: 8px;
        margin-top: 10px;
    }

    .checkbox-group input[type="checkbox"] {
        width: auto !important;
        margin: 0;
    }

    .checkbox-group label {
        margin: 0 !important;
        font-weight: 600 !important;
        color: #155724;
    }
    
    .btn-add-cliente {
        background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        color: white;
        border: none;
        padding: 8px 16px;
        border-radius: 8px;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        white-space: nowrap;
        box-shadow: 0 2px 8px rgba(40, 167, 69, 0.3);
        flex-shrink: 0;
    }
    
    .btn-add-cliente:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(40, 167, 69, 0.4);
    }

    @media (max-width: 768px) {
        .cliente-options {
            flex-direction: column;
        }
    }
</style>

<?php include '../../modules/clientes/modal_cadastro_cliente_rapido.php'; ?>

<div class="page-header">
    <h2>Editar Processo</h2>
    <a href="index.php" class="btn-voltar">← Voltar</a>
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
    
    <div style="background: linear-gradient(135deg, rgba(59, 130, 246, 0.15) 0%, rgba(96, 165, 250, 0.25) 100%); border-left: 4px solid #60a5fa; border-radius: 12px; padding: 20px 24px; margin-bottom: 24px; box-shadow: 0 2px 8px rgba(59, 130, 246, 0.2); color: #1e40af;">
        <strong>✏️ Editando processo:</strong> <?= htmlspecialchars($processo['numero_processo']) ?>
        <br>
        <small>Núcleo: <?= htmlspecialchars($processo['nucleo_nome']) ?></small>
    </div>
    
    <div class="form-container">
        <form action="process_editar.php" method="POST" id="processoForm">
            <!-- Seção: Dados Básicos do Processo -->
                        <input type="hidden" name="processo_id" value="<?= $processo_id ?>">
            <input type="hidden" name="nucleo_id_original" value="<?= $processo['nucleo_id'] ?>">
            <input type="hidden" name="responsavel_id_original" value="<?= $processo['responsavel_id'] ?>">
            
<div class="form-section">
                <h3>📋 Dados Básicos do Processo</h3>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="nucleo_id">Núcleo *</label>
                        <select name="nucleo_id" id="nucleo_id" required onchange="atualizarTiposProcesso()">
                            <option value="">Selecione o núcleo...</option>
                            <?php foreach ($nucleos as $nucleo): ?>
                                <option value="<?= $nucleo['id'] ?>" <?= $nucleo['id'] == $processo['nucleo_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($nucleo['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="numero_processo">Número do Processo *</label>
                        <input type="text" id="numero_processo" name="numero_processo" required
                               placeholder="0000000-00.0000.0.00.0000" value="<?= htmlspecialchars($processo['numero_processo']) ?>">
                    </div>
                    
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
                            <option value="Em Andamento" <?= $processo['situacao_processual'] == 'Em Andamento' ? 'selected' : '' ?>>Em Andamento</option>
                            <option value="Transitado" <?= $processo['situacao_processual'] == 'Transitado' ? 'selected' : '' ?>>Transitado</option>
                            <option value="Em Cumprimento de Sentença" <?= $processo['situacao_processual'] == 'Em Cumprimento de Sentença' ? 'selected' : '' ?>>Em Cumprimento de Sentença</option>
                            <option value="Em Processo de Renúncia" <?= $processo['situacao_processual'] == 'Em Processo de Renúncia' ? 'selected' : '' ?>>Em Processo de Renúncia</option>
                            <option value="Baixado" <?= $processo['situacao_processual'] == 'Baixado' ? 'selected' : '' ?>>Baixado</option>
                            <option value="Renunciado" <?= $processo['situacao_processual'] == 'Renunciado' ? 'selected' : '' ?>>Renunciado</option>
                            <option value="Em Grau Recursal" <?= $processo['situacao_processual'] == 'Em Grau Recursal' ? 'selected' : '' ?>>Em Grau Recursal</option>
                            <?php foreach ($situacoes_processuais as $situacao): ?>
                            <option value="<?= $situacao ?>" <?= $situacao === 'Em Andamento' ? 'selected' : '' ?>>
                                <?= htmlspecialchars($situacao) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="comarca">Comarca</label>
                        <input type="text" id="comarca" name="comarca" 
                               placeholder="Ex: Comarca de São Paulo" value="<?= htmlspecialchars($processo['comarca'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="vara">Vara</label>
                        <input type="text" id="vara" name="vara" 
                               placeholder="Ex: 1ª Vara Cível" value="<?= htmlspecialchars($processo['vara'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="valor_causa">Valor da Causa</label>
                        <input type="text" id="valor_causa" name="valor_causa" 
                               class="money-input" placeholder="R$ 0,00" value="<?= htmlspecialchars($processo['valor_causa'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="responsavel_id">Responsável pelo Processo *</label>
                        <select name="responsavel_id" id="responsavel_id" required>
                            <option value="">Selecione o responsável...</option>
                            <?php foreach ($usuarios as $usuario): ?>
                                <option value="<?= $usuario['id'] ?>">
                                    <?= htmlspecialchars($usuario['nome']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="fase_atual">Fase Atual do Processo</label>
                        <input type="text" id="fase_atual" name="fase_atual" 
                               placeholder="Ex: Aguardando citação, Em instrução..." value="<?= htmlspecialchars($processo['fase_atual'] ?? '') ?>">
                    </div>
                    
                    <div class="form-group full-width">
                        <label for="anotacoes">Observações</label>
                        <textarea id="anotacoes" name="anotacoes" 
                                  placeholder="Observações importantes sobre o processo..."><?= htmlspecialchars($processo['anotacoes'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>
            
            <!-- Seção: Partes do Processo -->
            <div class="form-section">
                <h3>👥 Partes do Processo</h3>
                
                <div class="partes-container" id="partesContainer">
                    <!-- As partes serão adicionadas aqui dinamicamente -->
                </div>
                
                <button type="button" class="btn-adicionar-parte" onclick="adicionarParte()">
                    + Adicionar Parte
                </button>
            </div>
            
            <button type="submit" class="btn-submit">Editar Processo</button>
        </form>
    </div>
</div>

<script>
    // Dados
    // Token para autenticação dos popups
    const tiposPorNucleo = <?= json_encode($tipos_por_nucleo) ?>;
    const clientes = <?= json_encode($clientes) ?>;
    let parteCounter = 0;
    
    function atualizarTiposProcesso() {
        const selectNucleo = document.getElementById('nucleo_id');
        const selectTipo = document.getElementById('tipo_processo');
        const nucleoSelecionado = selectNucleo.value;
        
        selectTipo.innerHTML = '<option value="">Selecione...</option>';
        
        if (nucleoSelecionado && tiposPorNucleo[nucleoSelecionado]) {
            tiposPorNucleo[nucleoSelecionado].forEach(tipo => {
                const option = document.createElement('option');
                option.value = tipo;
                option.textContent = tipo;
                if (tipo === tipoProcessoAtual) {
                    option.selected = true;
                }
                selectTipo.appendChild(option);
            });
        } else {
            selectTipo.innerHTML = '<option value="">Primeiro selecione o núcleo</option>';
        }
    }
    
    function adicionarParte() {
        parteCounter++;
        const container = document.getElementById('partesContainer');
        
        const parteDiv = document.createElement('div');
        parteDiv.className = 'parte-item';
        parteDiv.id = `parte-${parteCounter}`;
        
        parteDiv.innerHTML = `
            <div class="parte-header">
                <h4>Parte #${parteCounter}</h4>
                <button type="button" class="btn-remover-parte" onclick="removerParte(${parteCounter})">
                    🗑️ Remover
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
                
                <div class="form-group">
                    <label>Nome da Parte *</label>
                    <div style="display: flex; gap: 10px; align-items: center;">
                        <input type="text" 
                               class="parte-nome-input" 
                               name="partes[${parteCounter}][nome]" 
                               data-parte-id="${parteCounter}"
                               placeholder="Digite o nome..." 
                               required
                               autocomplete="off"
                               style="flex: 1;">
                        <button type="button" class="btn-add-cliente" onclick="abrirCadastroCliente(${parteCounter})" title="Cadastrar Novo Cliente">
                            <i class="fas fa-user-plus"></i> Novo
                        </button>
                    </div>
                    <input type="hidden" name="partes[${parteCounter}][cliente_id]" id="cliente-id-${parteCounter}">
                    <div class="autocomplete-suggestions" id="suggestions-${parteCounter}"></div>
                </div>
            </div>
            
            <div class="checkbox-group">
                <input type="checkbox" 
                       name="partes[${parteCounter}][e_nosso_cliente]" 
                       id="cliente-${parteCounter}" 
                       value="1">
                <label for="cliente-${parteCounter}">✓ Esta parte é quem nos contratou</label>
            </div>
        `;
        
        container.appendChild(parteDiv);
        setupAutocomplete(parteCounter);
    }
    
    function removerParte(id) {
        const parte = document.getElementById(`parte-${id}`);
        if (parte) {
            parte.remove();
        }
    }
    
    function setupAutocomplete(parteId) {
		const input = document.querySelector(`[data-parte-id="${parteId}"]`);
		const suggestions = document.getElementById(`suggestions-${parteId}`);
		const clienteIdInput = document.getElementById(`cliente-id-${parteId}`);
		let selectedIndex = -1;

		input.addEventListener('input', function() {
			const query = this.value.toLowerCase();
			selectedIndex = -1;

			if (query.length < 2) {
				suggestions.style.display = 'none';
				clienteIdInput.value = '';
				return;
			}

			const matches = clientes.filter(cliente => 
				cliente.nome.toLowerCase().includes(query) ||
				(cliente.cpf_cnpj && cliente.cpf_cnpj.includes(query))
			);

			if (matches.length > 0) {
				suggestions.innerHTML = matches.map(cliente => 
					`<div class="autocomplete-suggestion" data-id="${cliente.id}" data-nome="${cliente.nome}">
						<strong>${cliente.nome}</strong>
						${cliente.cpf_cnpj ? `<br><small>${cliente.cpf_cnpj}</small>` : ''}
					</div>`
				).join('');
				suggestions.style.display = 'block';
			} else {
				suggestions.style.display = 'none';
			}
		});

		input.addEventListener('keydown', function(e) {
			const items = suggestions.querySelectorAll('.autocomplete-suggestion');

			if (suggestions.style.display === 'none' || items.length === 0) return;

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
				suggestions.style.display = 'none';
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
			input.value = item.dataset.nome;
			clienteIdInput.value = item.dataset.id;
			suggestions.style.display = 'none';
			selectedIndex = -1;
		}

		suggestions.addEventListener('click', function(e) {
			const suggestion = e.target.closest('.autocomplete-suggestion');
			if (suggestion) {
				selectItem(suggestion);
			}
		});

		suggestions.addEventListener('mouseover', function(e) {
			const suggestion = e.target.closest('.autocomplete-suggestion');
			if (suggestion) {
				const items = suggestions.querySelectorAll('.autocomplete-suggestion');
				items.forEach((item, index) => {
					if (item === suggestion) {
						selectedIndex = index;
						item.classList.add('active');
					} else {
						item.classList.remove('active');
					}
				});
			}
		});

		document.addEventListener('click', function(e) {
			if (!e.target.closest(`[data-parte-id="${parteId}"]`) && 
				!e.target.closest(`#suggestions-${parteId}`)) {
				suggestions.style.display = 'none';
				selectedIndex = -1;
			}
		});
	}
    
    // Validação do formulário
    document.getElementById('processoForm').addEventListener('submit', function(e) {
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
            const confirmar = confirm('Nenhuma parte foi marcada como "quem nos contratou". Deseja continuar mesmo assim?');
            if (!confirmar) {
                e.preventDefault();
                return;
            }
        }
    });
    
    // Adicionar primeira parte automaticamente
    
    // Carregar partes existentes
    const partesExistentes = <?= json_encode($partes_processo) ?>;
    const tipoProcessoAtual = "<?= htmlspecialchars($processo['tipo_processo']) ?>";
    
    document.addEventListener('DOMContentLoaded', function() {
        atualizarTiposProcesso();
        
        if (partesExistentes.length > 0) {
            partesExistentes.forEach(parte => {
                adicionarParteExistente(parte);
            });
        } else {
            adicionarParte();
        }
    });
    
    function adicionarParteExistente(parteExistente) {
        parteCounter++;
        const container = document.getElementById('partesContainer');
        
        const parteDiv = document.createElement('div');
        parteDiv.className = 'parte-item';
        parteDiv.id = `parte-${parteCounter}`;
        
        parteDiv.innerHTML = `
            <input type="hidden" name="partes[${parteCounter}][id]" value="${parteExistente.id}">
            <div class="parte-header">
                <h4>Parte #${parteExistente.ordem || parteCounter}</h4>
                <button type="button" class="btn-remover-parte" onclick="removerParte(${parteCounter}, ${parteExistente.id})">🗑️ Remover</button>
            </div>
            <div class="form-grid">
                <div class="form-group">
                    <label>Tipo de Parte *</label>
                    <select name="partes[${parteCounter}][tipo_parte]" required>
                        <option value="">Selecione...</option>
                        <option value="Autor" ${parteExistente.tipo_parte === 'Autor' ? 'selected' : ''}>Autor</option>
                        <option value="Exequente" ${parteExistente.tipo_parte === 'Exequente' ? 'selected' : ''}>Exequente</option>
                        <option value="Réu" ${parteExistente.tipo_parte === 'Réu' ? 'selected' : ''}>Réu</option>
                        <option value="Executado" ${parteExistente.tipo_parte === 'Executado' ? 'selected' : ''}>Executado</option>
                        <option value="Outros" ${parteExistente.tipo_parte === 'Outros' ? 'selected' : ''}>Outros</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Nome da Parte *</label>
                    <input type="text" 
                           class="parte-nome-input" 
                           name="partes[${parteCounter}][nome]" 
                           data-parte-id="${parteCounter}"
                           value="${parteExistente.nome || ''}"
                           placeholder="Digite o nome..." 
                           required
                           autocomplete="off">
                    <input type="hidden" name="partes[${parteCounter}][cliente_id]" id="cliente-id-${parteCounter}" value="${parteExistente.cliente_id || ''}">
                    <div class="autocomplete-suggestions" id="suggestions-${parteCounter}"></div>
                </div>
            </div>
            <div class="checkbox-group">
                <input type="checkbox" 
                       name="partes[${parteCounter}][e_nosso_cliente]" 
                       id="cliente-${parteCounter}" 
                       value="1"
                       ${parteExistente.e_nosso_cliente == 1 ? 'checked' : ''}>
                <label for="cliente-${parteCounter}">✓ Esta parte é quem nos contratou</label>
            </div>
        `;
        
        container.appendChild(parteDiv);
        setupAutocomplete(parteCounter);
    }
    
    function removerParte(counterId, parteId) {
        const parte = document.getElementById(`parte-${counterId}`);
        if (parte) {
            if (parteId && parteId !== 'new') {
                // Marcar para remoção no backend
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'partes_remover[]';
                input.value = parteId;
                document.getElementById('processoForm').appendChild(input);
            }
            parte.remove();
        }
    }
    
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
    
    // Máscara de dinheiro - Aplicar nos campos
    document.querySelectorAll('.money-input').forEach(input => {
        // Formatar valor existente ao carregar
        if (input.value && input.value.trim() !== '') {
            const valorNumerico = parseFloat(input.value);
            if (!isNaN(valorNumerico) && valorNumerico > 0) {
                const centavos = Math.round(valorNumerico * 100).toString();
                input.value = formatarReal(centavos);
            }
        }
        
        // Aplicar máscara ao digitar
        input.addEventListener('input', function(e) {
            e.target.value = formatarReal(e.target.value);
        });
        
        // Ao perder o foco, garantir formato correto
        input.addEventListener('blur', function(e) {
            if (e.target.value === '' || e.target.value === '0,00') {
                e.target.value = '';
            }
        });
    });
    
    // Função para abrir cadastro de cliente
    function abrirCadastroCliente(parteId) {
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
        
        if (input && clienteIdInput) {
            input.value = clienteNome;
            clienteIdInput.value = clienteId;
            
            // Fechar sugestões se estiverem abertas
            const suggestions = document.getElementById(`suggestions-${parteId}`);
            if (suggestions) {
                suggestions.style.display = 'none';
            }
        }
    };
</script></script>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Editar Processo', $conteudo, 'processos');
?>