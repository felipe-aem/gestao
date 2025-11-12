<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';
require_once '../../includes/layout.php';

// Verificação de módulo em desenvolvimento
require_once __DIR__ . '/../../config/modules_config.php';
$moduloAtual = 'clientes';
$usuarioLogado = $_SESSION['usuario_id'] ?? null;

if (verificarModuloEmDesenvolvimento($moduloAtual, $usuarioLogado)) {
    include __DIR__ . '/../../config/paginas/em_desenvolvimento.html';
    exit;
}

$usuario_logado = Auth::user();
$cliente_id = $_GET['id'] ?? 0;

if (!$cliente_id) {
    header('Location: index.php');
    exit;
}

// Buscar dados do cliente
try {
    $sql = "SELECT * FROM clientes WHERE id = ?";
    $stmt = executeQuery($sql, [$cliente_id]);
    $cliente = $stmt->fetch();
    
    if (!$cliente) {
        header('Location: index.php?erro=Cliente não encontrado');
        exit;
    }
} catch (Exception $e) {
    die('Erro: ' . $e->getMessage());
}

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validações básicas
        $nome = trim($_POST['nome']);
        $id_pasta = trim($_POST['id_pasta'] ?? '');
        $tipo_pessoa = $_POST['tipo_pessoa'];
        $cpf_cnpj = preg_replace('/[^0-9]/', '', $_POST['cpf_cnpj'] ?? '');
        
        if (empty($nome)) {
            throw new Exception('Nome é obrigatório');
        }
        
        // Validar ID da Pasta se fornecido
        if (!empty($id_pasta) && $id_pasta !== $cliente['id_pasta']) {
            $sql_pasta = "SELECT id, nome FROM clientes WHERE id_pasta = ? AND id != ?";
            $stmt_pasta = executeQuery($sql_pasta, [$id_pasta, $cliente_id]);
            if ($cliente_existente = $stmt_pasta->fetch()) {
                throw new Exception("ID da Pasta '{$id_pasta}' já está sendo usado pelo cliente: {$cliente_existente['nome']}");
            }
            
            if (!preg_match('/^[A-Za-z0-9\-_]+$/', $id_pasta)) {
                throw new Exception('ID da Pasta deve conter apenas letras, números, hífens e underscores');
            }
        }
        
        if (!empty($cpf_cnpj) && $cpf_cnpj !== $cliente['cpf_cnpj']) {
            // Verificar se CPF/CNPJ já existe em outro cliente
            $sql = "SELECT id FROM clientes WHERE cpf_cnpj = ? AND id != ?";
            $stmt = executeQuery($sql, [$cpf_cnpj, $cliente_id]);
            if ($stmt->fetch()) {
                throw new Exception('CPF/CNPJ já cadastrado para outro cliente');
            }
        }
        
        // Preparar dados para atualização
        $dados_anteriores = $cliente;
        
        // Atualizar cliente - INCLUINDO ID_PASTA
        $sql = "UPDATE clientes SET 
            nome = ?, id_pasta = ?, tipo_pessoa = ?, cpf_cnpj = ?, rg_ie = ?, data_nascimento = ?, estado_civil = ?, 
            profissao = ?, nacionalidade = ?, cep = ?, endereco = ?, numero = ?, complemento = ?, 
            bairro = ?, cidade = ?, estado = ?, telefone = ?, celular = ?, email = ?, 
            banco = ?, agencia = ?, conta = ?, tipo_conta = ?, pix = ?, 
            observacoes = ?, como_conheceu = ?, indicado_por = ?
            WHERE id = ?";
        
        $params = [
            $nome,
            $id_pasta ?: null, // NOVO CAMPO
            $tipo_pessoa,
            $cpf_cnpj ?: null,
            $_POST['rg_ie'] ?: null,
            $_POST['data_nascimento'] ?: null,
            $_POST['estado_civil'] ?: null,
            $_POST['profissao'] ?: null,
            $_POST['nacionalidade'] ?: 'Brasileira',
            $_POST['cep'] ?: null,
            $_POST['endereco'] ?: null,
            $_POST['numero'] ?: null,
            $_POST['complemento'] ?: null,
            $_POST['bairro'] ?: null,
            $_POST['cidade'] ?: null,
            $_POST['estado'] ?: null,
            $_POST['telefone'] ?: null,
            $_POST['celular'] ?: null,
            $_POST['email'] ?: null,
            $_POST['banco'] ?: null,
            $_POST['agencia'] ?: null,
            $_POST['conta'] ?: null,
            $_POST['tipo_conta'] ?: null,
            $_POST['pix'] ?: null,
            $_POST['observacoes'] ?: null,
            $_POST['como_conheceu'] ?: null,
            $_POST['indicado_por'] ?: null,
            $cliente_id
        ];
        
        executeQuery($sql, $params);
        
        // Registrar no histórico
        $alteracoes = [];
        $campos_monitorados = [
            'nome' => 'Nome/Razão Social',
            'id_pasta' => 'ID da Pasta', // NOVO CAMPO
            'tipo_pessoa' => 'Tipo de Pessoa',
            'cpf_cnpj' => 'CPF/CNPJ',
            'email' => 'E-mail',
            'celular' => 'Celular',
            'telefone' => 'Telefone',
            'cidade' => 'Cidade',
            'estado' => 'Estado'
        ];
        
        foreach ($campos_monitorados as $campo => $label) {
            $valor_anterior = $dados_anteriores[$campo] ?? '';
            $valor_novo = $_POST[$campo] ?? '';
            
            if ($valor_anterior !== $valor_novo) {
                $alteracoes[] = "$label alterado";
            }
        }
        
        if (!empty($alteracoes)) {
            $descricao = 'Campos alterados: ' . implode(', ', $alteracoes);
        } else {
            $descricao = 'Dados do cliente atualizados';
        }
        
        $sql_hist = "INSERT INTO clientes_historico (cliente_id, acao, descricao_alteracao, usuario_id) 
                     VALUES (?, 'Editado', ?, ?)";
        executeQuery($sql_hist, [$cliente_id, $descricao, $usuario_logado['usuario_id']]);
        
        header('Location: visualizar.php?id=' . $cliente_id . '&success=editado');
        exit;
        
    } catch (Exception $e) {
        $erro = $e->getMessage();
        // Recarregar dados do cliente em caso de erro
        $sql = "SELECT * FROM clientes WHERE id = ?";
        $stmt = executeQuery($sql, [$cliente_id]);
        $cliente = $stmt->fetch();
    }
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
    
    .form-container {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        padding: 30px;
        margin-bottom: 30px;
    }
    
    .form-section {
        margin-bottom: 35px;
    }
    
    .form-section h3 {
        color: #1a1a1a;
        font-size: 18px;
        font-weight: 700;
        margin-bottom: 25px;
        padding-bottom: 12px;
        border-bottom: 2px solid #e9ecef;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .form-grid {
        display: grid;
        gap: 20px;
        align-items: start;
    }
    
    .form-grid.dados-basicos {
        grid-template-columns: 2fr 1fr 1fr;
        grid-template-areas: 
            "nome id-pasta cpf"
            "rg data-nasc estado-civil"
            "profissao nacionalidade .";
    }
    
    .form-grid.endereco {
        grid-template-columns: 1fr 2fr 1fr;
        grid-template-areas:
            "cep endereco numero"
            "complemento bairro cidade"
            ". . estado";
    }
    
    .form-grid.contato {
        grid-template-columns: 1fr 1fr 1fr;
    }
    
    .form-grid.bancarios {
        grid-template-columns: 2fr 1fr 1fr;
        grid-template-areas:
            "banco agencia conta"
            "tipo-conta pix .";
    }
    
    .form-grid.adicionais {
        grid-template-columns: 1fr 1fr;
        grid-template-areas:
            "como-conheceu indicado"
            "observacoes observacoes";
    }
    
    .form-group {
        display: flex;
        flex-direction: column;
    }
    
    .campo-nome { grid-area: nome; }
    .campo-id-pasta { grid-area: id-pasta; }
    .campo-cpf { grid-area: cpf; }
    .campo-rg { grid-area: rg; }
    .campo-data-nasc { grid-area: data-nasc; }
    .campo-estado-civil { grid-area: estado-civil; }
    .campo-profissao { grid-area: profissao; }
    .campo-nacionalidade { grid-area: nacionalidade; }
    
    .campo-cep { grid-area: cep; }
    .campo-endereco { grid-area: endereco; }
    .campo-numero { grid-area: numero; }
    .campo-complemento { grid-area: complemento; }
    .campo-bairro { grid-area: bairro; }
    .campo-cidade { grid-area: cidade; }
    .campo-estado { grid-area: estado; }
    
    .campo-banco { grid-area: banco; }
    .campo-agencia { grid-area: agencia; }
    .campo-conta { grid-area: conta; }
    .campo-tipo-conta { grid-area: tipo-conta; }
    .campo-pix { grid-area: pix; }
    
    .campo-como-conheceu { grid-area: como-conheceu; }
    .campo-indicado { grid-area: indicado; }
    .campo-observacoes { grid-area: observacoes; }
    
    .form-group label {
        margin-bottom: 8px;
        color: #333;
        font-weight: 600;
        font-size: 14px;
    }
    
    .form-group label.required::after {
        content: ' *';
        color: #dc3545;
    }
    
    .form-group input,
    .form-group select,
    .form-group textarea {
        padding: 12px 15px;
        border: 1px solid #ddd;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.3s;
        background: #fff;
    }
    
    .form-group input:focus,
    .form-group select:focus,
    .form-group textarea:focus {
        outline: none;
        border-color: #007bff;
        box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
        transform: translateY(-1px);
    }
    
    .form-group textarea {
        resize: vertical;
        min-height: 100px;
        font-family: inherit;
    }
    
    .field-help {
        font-size: 12px;
        color: #6c757d;
        margin-top: 4px;
        font-style: italic;
    }
    
    .tipo-pessoa-toggle {
        display: flex;
        background: #f8f9fa;
        border-radius: 10px;
        padding: 6px;
        margin-bottom: 25px;
        box-shadow: inset 0 2px 4px rgba(0,0,0,0.1);
    }
    
    .tipo-pessoa-toggle label {
        flex: 1;
        padding: 15px;
        text-align: center;
        cursor: pointer;
        border-radius: 8px;
        transition: all 0.3s;
        font-weight: 600;
        margin-bottom: 0;
        user-select: none;
    }
    
    .tipo-pessoa-toggle input[type="radio"] {
        display: none;
    }
    
    .tipo-pessoa-toggle input[type="radio"]:checked + label {
        background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
        color: white;
        box-shadow: 0 4px 15px rgba(0, 123, 255, 0.3);
        transform: translateY(-1px);
    }
    
    .alert {
        padding: 15px 20px;
        border-radius: 10px;
        margin-bottom: 25px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .alert-danger {
        background: rgba(220, 53, 69, 0.1);
        border: 1px solid rgba(220, 53, 69, 0.3);
        color: #721c24;
    }
    
    .form-actions {
        display: flex;
        gap: 15px;
        justify-content: flex-end;
        margin-top: 40px;
        padding-top: 25px;
        border-top: 2px solid #e9ecef;
    }
    
    .btn {
        padding: 14px 28px;
        border: none;
        border-radius: 10px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s;
        text-decoration: none;
        display: inline-block;
        text-align: center;
        font-size: 14px;
    }
    
    .btn-primary {
        background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
        color: white;
        box-shadow: 0 4px 15px rgba(0, 123, 255, 0.3);
    }
    
    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0, 123, 255, 0.4);
    }
    
    .btn-secondary {
        background: #6c757d;
        color: white;
        box-shadow: 0 4px 15px rgba(108, 117, 125, 0.3);
    }
    
    .btn-secondary:hover {
        background: #5a6268;
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(108, 117, 125, 0.4);
    }
    
    .pessoa-fisica-fields,
    .pessoa-juridica-fields {
        display: none;
    }
    
    .pessoa-fisica-fields.active,
    .pessoa-juridica-fields.active {
        display: flex;
        flex-direction: column;
    }
    
    @media (max-width: 1200px) {
        .form-grid.dados-basicos {
            grid-template-columns: 1fr 1fr;
            grid-template-areas: 
                "nome nome"
                "id-pasta cpf"
                "rg data-nasc"
                "estado-civil profissao"
                "nacionalidade .";
        }
        
        .form-grid.endereco {
            grid-template-columns: 1fr 1fr;
            grid-template-areas:
                "cep endereco"
                "numero complemento"
                "bairro cidade"
                "estado .";
        }
        
        .form-grid.bancarios {
            grid-template-columns: 1fr 1fr;
            grid-template-areas:
                "banco agencia"
                "conta tipo-conta"
                "pix .";
        }
    }
    
    @media (max-width: 768px) {
        .page-header {
            flex-direction: column;
            gap: 15px;
            text-align: center;
            padding: 20px;
        }
        
        .form-container {
            padding: 20px;
        }
        
        .form-grid,
        .form-grid.dados-basicos,
        .form-grid.endereco,
        .form-grid.contato,
        .form-grid.bancarios,
        .form-grid.adicionais {
            grid-template-columns: 1fr;
            grid-template-areas: none;
        }
        
        .form-grid > * {
            grid-area: auto;
        }
        
        .form-actions {
            flex-direction: column;
        }
        
        .tipo-pessoa-toggle {
            flex-direction: column;
            gap: 8px;
        }
    }
</style>

<div class="page-header">
    <h2>✏️ Editar Cliente</h2>
    <a href="visualizar.php?id=<?= $cliente['id'] ?>" class="btn-voltar">← Voltar</a>
</div>

<?php if (isset($erro)): ?>
<div class="alert alert-danger">
    ❌ <?= htmlspecialchars($erro) ?>
</div>
<?php endif; ?>

<form method="POST" class="form-container">
    <!-- Tipo de Pessoa -->
    <div class="form-section">
        <h3>🔍 Tipo de Pessoa</h3>
        <div class="tipo-pessoa-toggle">
            <input type="radio" id="fisica" name="tipo_pessoa" value="Física" <?= ($cliente['tipo_pessoa'] ?? 'Física') === 'Física' ? 'checked' : '' ?>>
            <label for="fisica">👤 Pessoa Física</label>
            
            <input type="radio" id="juridica" name="tipo_pessoa" value="Jurídica" <?= ($cliente['tipo_pessoa'] ?? '') === 'Jurídica' ? 'checked' : '' ?>>
            <label for="juridica">🏢 Pessoa Jurídica</label>
        </div>
    </div>

    <!-- Dados Básicos -->
    <div class="form-section">
        <h3>📋 Dados Básicos</h3>
        <div class="form-grid dados-basicos">
            <div class="form-group campo-nome">
                <label for="nome" class="required">Nome/Razão Social</label>
                <input type="text" id="nome" name="nome" required value="<?= htmlspecialchars($cliente['nome']) ?>">
            </div>
            
            <!-- NOVO CAMPO - ID DA PASTA -->
            <div class="form-group campo-id-pasta">
                <label for="id_pasta">📁 ID da Pasta</label>
                <input type="text" 
                       id="id_pasta" 
                       name="id_pasta" 
                       value="<?= htmlspecialchars($cliente['id_pasta'] ?? '') ?>"
                       placeholder="Ex: PASTA-001, CLI-2024-001"
                       maxlength="50">
                <small class="field-help">Identificador único da pasta física/digital do cliente</small>
            </div>
            
            <div class="form-group campo-cpf">
                <label for="cpf_cnpj">CPF/CNPJ</label>
                <input type="text" id="cpf_cnpj" name="cpf_cnpj" value="<?= htmlspecialchars($cliente['cpf_cnpj'] ?? '') ?>">
            </div>
            
            <div class="form-group campo-rg pessoa-fisica-fields <?= ($cliente['tipo_pessoa'] ?? 'Física') === 'Física' ? 'active' : '' ?>">
                <label for="rg_pf">RG</label>
                <input type="text" id="rg_pf" name="rg_ie" value="<?= htmlspecialchars($cliente['rg_ie'] ?? '') ?>">
            </div>
            
            <div class="form-group campo-rg pessoa-juridica-fields <?= ($cliente['tipo_pessoa'] ?? '') === 'Jurídica' ? 'active' : '' ?>">
                <label for="ie_pj">Inscrição Estadual</label>
                <input type="text" id="ie_pj" name="rg_ie" value="<?= htmlspecialchars($cliente['rg_ie'] ?? '') ?>">
            </div>
            
            <div class="form-group campo-data-nasc pessoa-fisica-fields <?= ($cliente['tipo_pessoa'] ?? 'Física') === 'Física' ? 'active' : '' ?>">
                <label for="data_nascimento">Data de Nascimento</label>
                <input type="date" id="data_nascimento" name="data_nascimento" value="<?= htmlspecialchars($cliente['data_nascimento'] ?? '') ?>">
            </div>
            
            <div class="form-group campo-estado-civil pessoa-fisica-fields <?= ($cliente['tipo_pessoa'] ?? 'Física') === 'Física' ? 'active' : '' ?>">
                <label for="estado_civil">Estado Civil</label>
                <select id="estado_civil" name="estado_civil">
                    <option value="">Selecione</option>
                    <option value="Solteiro(a)" <?= ($cliente['estado_civil'] ?? '') === 'Solteiro(a)' ? 'selected' : '' ?>>Solteiro(a)</option>
                    <option value="Casado(a)" <?= ($cliente['estado_civil'] ?? '') === 'Casado(a)' ? 'selected' : '' ?>>Casado(a)</option>
                    <option value="Divorciado(a)" <?= ($cliente['estado_civil'] ?? '') === 'Divorciado(a)' ? 'selected' : '' ?>>Divorciado(a)</option>
                    <option value="Viúvo(a)" <?= ($cliente['estado_civil'] ?? '') === 'Viúvo(a)' ? 'selected' : '' ?>>Viúvo(a)</option>
                    <option value="União Estável" <?= ($cliente['estado_civil'] ?? '') === 'União Estável' ? 'selected' : '' ?>>União Estável</option>
                    <option value="Separado(a)" <?= ($cliente['estado_civil'] ?? '') === 'Separado(a)' ? 'selected' : '' ?>>Separado(a)</option>
                </select>
            </div>
            
            <div class="form-group campo-profissao">
                <label for="profissao">Profissão/Atividade</label>
                <input type="text" id="profissao" name="profissao" value="<?= htmlspecialchars($cliente['profissao'] ?? '') ?>">
            </div>
            
            <div class="form-group campo-nacionalidade">
                <label for="nacionalidade">Nacionalidade</label>
                <input type="text" id="nacionalidade" name="nacionalidade" value="<?= htmlspecialchars($cliente['nacionalidade'] ?? 'Brasileira') ?>">
            </div>
        </div>
    </div>

    <!-- Endereço -->
    <div class="form-section">
        <h3>📍 Endereço</h3>
        <div class="form-grid endereco">
            <div class="form-group campo-cep">
                <label for="cep">CEP</label>
                <input type="text" id="cep" name="cep" value="<?= htmlspecialchars($cliente['cep'] ?? '') ?>">
            </div>
            
            <div class="form-group campo-endereco">
                <label for="endereco">Endereço</label>
                <input type="text" id="endereco" name="endereco" value="<?= htmlspecialchars($cliente['endereco'] ?? '') ?>">
            </div>
            
            <div class="form-group campo-numero">
                <label for="numero">Número</label>
                <input type="text" id="numero" name="numero" value="<?= htmlspecialchars($cliente['numero'] ?? '') ?>">
            </div>
            
            <div class="form-group campo-complemento">
                <label for="complemento">Complemento</label>
                <input type="text" id="complemento" name="complemento" value="<?= htmlspecialchars($cliente['complemento'] ?? '') ?>">
            </div>
            
            <div class="form-group campo-bairro">
                <label for="bairro">Bairro</label>
                <input type="text" id="bairro" name="bairro" value="<?= htmlspecialchars($cliente['bairro'] ?? '') ?>">
            </div>
            
            <div class="form-group campo-cidade">
                <label for="cidade">Cidade</label>
                <input type="text" id="cidade" name="cidade" value="<?= htmlspecialchars($cliente['cidade'] ?? '') ?>">
            </div>
            
            <div class="form-group campo-estado">
                <label for="estado">Estado</label>
                <select id="estado" name="estado">
                    <option value="">Selecione</option>
                    <option value="AC" <?= ($cliente['estado'] ?? '') === 'AC' ? 'selected' : '' ?>>AC</option>
                    <option value="AL" <?= ($cliente['estado'] ?? '') === 'AL' ? 'selected' : '' ?>>AL</option>
                    <option value="AP" <?= ($cliente['estado'] ?? '') === 'AP' ? 'selected' : '' ?>>AP</option>
                    <option value="AM" <?= ($cliente['estado'] ?? '') === 'AM' ? 'selected' : '' ?>>AM</option>
                    <option value="BA" <?= ($cliente['estado'] ?? '') === 'BA' ? 'selected' : '' ?>>BA</option>
                    <option value="CE" <?= ($cliente['estado'] ?? '') === 'CE' ? 'selected' : '' ?>>CE</option>
                    <option value="DF" <?= ($cliente['estado'] ?? '') === 'DF' ? 'selected' : '' ?>>DF</option>
                    <option value="ES" <?= ($cliente['estado'] ?? '') === 'ES' ? 'selected' : '' ?>>ES</option>
                    <option value="GO" <?= ($cliente['estado'] ?? '') === 'GO' ? 'selected' : '' ?>>GO</option>
                    <option value="MA" <?= ($cliente['estado'] ?? '') === 'MA' ? 'selected' : '' ?>>MA</option>
                    <option value="MT" <?= ($cliente['estado'] ?? '') === 'MT' ? 'selected' : '' ?>>MT</option>
                    <option value="MS" <?= ($cliente['estado'] ?? '') === 'MS' ? 'selected' : '' ?>>MS</option>
                    <option value="MG" <?= ($cliente['estado'] ?? '') === 'MG' ? 'selected' : '' ?>>MG</option>
                    <option value="PA" <?= ($cliente['estado'] ?? '') === 'PA' ? 'selected' : '' ?>>PA</option>
                    <option value="PB" <?= ($cliente['estado'] ?? '') === 'PB' ? 'selected' : '' ?>>PB</option>
                    <option value="PR" <?= ($cliente['estado'] ?? '') === 'PR' ? 'selected' : '' ?>>PR</option>
                    <option value="PE" <?= ($cliente['estado'] ?? '') === 'PE' ? 'selected' : '' ?>>PE</option>
                    <option value="PI" <?= ($cliente['estado'] ?? '') === 'PI' ? 'selected' : '' ?>>PI</option>
                    <option value="RJ" <?= ($cliente['estado'] ?? '') === 'RJ' ? 'selected' : '' ?>>RJ</option>
                    <option value="RN" <?= ($cliente['estado'] ?? '') === 'RN' ? 'selected' : '' ?>>RN</option>
                    <option value="RS" <?= ($cliente['estado'] ?? '') === 'RS' ? 'selected' : '' ?>>RS</option>
                    <option value="RO" <?= ($cliente['estado'] ?? '') === 'RO' ? 'selected' : '' ?>>RO</option>
                    <option value="RR" <?= ($cliente['estado'] ?? '') === 'RR' ? 'selected' : '' ?>>RR</option>
                    <option value="SC" <?= ($cliente['estado'] ?? '') === 'SC' ? 'selected' : '' ?>>SC</option>
                    <option value="SP" <?= ($cliente['estado'] ?? '') === 'SP' ? 'selected' : '' ?>>SP</option>
                    <option value="SE" <?= ($cliente['estado'] ?? '') === 'SE' ? 'selected' : '' ?>>SE</option>
                    <option value="TO" <?= ($cliente['estado'] ?? '') === 'TO' ? 'selected' : '' ?>>TO</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Contato -->
    <div class="form-section">
        <h3>📞 Contato</h3>
        <div class="form-grid contato">
            <div class="form-group">
                <label for="telefone">Telefone</label>
                <input type="text" id="telefone" name="telefone" value="<?= htmlspecialchars($cliente['telefone'] ?? '') ?>">
            </div>
            
            <div class="form-group">
                <label for="celular">Celular</label>
                <input type="text" id="celular" name="celular" value="<?= htmlspecialchars($cliente['celular'] ?? '') ?>">
            </div>
            
            <div class="form-group">
                <label for="email">E-mail</label>
                <input type="email" id="email" name="email" value="<?= htmlspecialchars($cliente['email'] ?? '') ?>">
            </div>
        </div>
    </div>

    <!-- Dados Bancários -->
    <div class="form-section">
        <h3>🏦 Dados Bancários</h3>
        <div class="form-grid bancarios">
            <div class="form-group campo-banco">
                <label for="banco">Banco</label>
                <input type="text" id="banco" name="banco" value="<?= htmlspecialchars($cliente['banco'] ?? '') ?>">
            </div>
            
            <div class="form-group campo-agencia">
                <label for="agencia">Agência</label>
                <input type="text" id="agencia" name="agencia" value="<?= htmlspecialchars($cliente['agencia'] ?? '') ?>">
            </div>
            
            <div class="form-group campo-conta">
                <label for="conta">Conta</label>
                <input type="text" id="conta" name="conta" value="<?= htmlspecialchars($cliente['conta'] ?? '') ?>">
            </div>
            
            <div class="form-group campo-tipo-conta">
                <label for="tipo_conta">Tipo de Conta</label>
                <select id="tipo_conta" name="tipo_conta">
                    <option value="">Selecione</option>
                    <option value="Corrente" <?= ($cliente['tipo_conta'] ?? '') === 'Corrente' ? 'selected' : '' ?>>Corrente</option>
                    <option value="Poupança" <?= ($cliente['tipo_conta'] ?? '') === 'Poupança' ? 'selected' : '' ?>>Poupança</option>
                    <option value="Salário" <?= ($cliente['tipo_conta'] ?? '') === 'Salário' ? 'selected' : '' ?>>Salário</option>
                </select>
            </div>
            
            <div class="form-group campo-pix">
                <label for="pix">PIX</label>
                <input type="text" id="pix" name="pix" value="<?= htmlspecialchars($cliente['pix'] ?? '') ?>" placeholder="CPF, e-mail, telefone ou chave aleatória">
            </div>
        </div>
    </div>

    <!-- Informações Adicionais -->
    <div class="form-section">
        <h3>📝 Informações Adicionais</h3>
        <div class="form-grid adicionais">
            <div class="form-group campo-como-conheceu">
                <label for="como_conheceu">Como conheceu o escritório?</label>
                <input type="text" id="como_conheceu" name="como_conheceu" value="<?= htmlspecialchars($cliente['como_conheceu'] ?? '') ?>">
            </div>
            
            <div class="form-group campo-indicado">
                <label for="indicado_por">Indicado por</label>
                <input type="text" id="indicado_por" name="indicado_por" value="<?= htmlspecialchars($cliente['indicado_por'] ?? '') ?>">
            </div>
            
            <div class="form-group campo-observacoes">
                <label for="observacoes">Observações</label>
                <textarea id="observacoes" name="observacoes" placeholder="Informações adicionais sobre o cliente..."><?= htmlspecialchars($cliente['observacoes'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <div class="form-actions">
        <a href="visualizar.php?id=<?= $cliente['id'] ?>" class="btn btn-secondary">Cancelar</a>
        <button type="submit" class="btn btn-primary">💾 Salvar Alterações</button>
    </div>
</form>

<script>
    // Toggle entre pessoa física e jurídica
    document.querySelectorAll('input[name="tipo_pessoa"]').forEach(radio => {
        radio.addEventListener('change', function() {
            const fisicaFields = document.querySelectorAll('.pessoa-fisica-fields');
            const juridicaFields = document.querySelectorAll('.pessoa-juridica-fields');
            
            if (this.value === 'Física') {
                fisicaFields.forEach(field => field.classList.add('active'));
                juridicaFields.forEach(field => field.classList.remove('active'));
            } else {
                fisicaFields.forEach(field => field.classList.remove('active'));
                juridicaFields.forEach(field => field.classList.add('active'));
            }
        });
    });

    // Máscara para CPF/CNPJ
    document.getElementById('cpf_cnpj').addEventListener('input', function(e) {
        let value = e.target.value.replace(/\D/g, '');
        
        if (value.length <= 11) {
            value = value.replace(/(\d{3})(\d)/, '$1.$2');
            value = value.replace(/(\d{3})(\d)/, '$1.$2');
            value = value.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
        } else {
            value = value.replace(/(\d{2})(\d)/, '$1.$2');
            value = value.replace(/(\d{3})(\d)/, '$1.$2');
            value = value.replace(/(\d{3})(\d)/, '$1/$2');
            value = value.replace(/(\d{4})(\d{1,2})$/, '$1-$2');
        }
        
        e.target.value = value;
    });

    // Máscara para CEP
    document.getElementById('cep').addEventListener('input', function(e) {
        let value = e.target.value.replace(/\D/g, '');
        value = value.replace(/(\d{5})(\d)/, '$1-$2');
        e.target.value = value;
    });

    // Máscara para telefones
    function mascaraTelefone(input) {
        input.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            
            if (value.length <= 10) {
                value = value.replace(/(\d{2})(\d)/, '($1) $2');
                value = value.replace(/(\d{4})(\d)/, '$1-$2');
            } else {
                value = value.replace(/(\d{2})(\d)/, '($1) $2');
                value = value.replace(/(\d{5})(\d)/, '$1-$2');
            }
            
            e.target.value = value;
        });
    }

    mascaraTelefone(document.getElementById('telefone'));
    mascaraTelefone(document.getElementById('celular'));

    // Validação e formatação do ID da Pasta
    document.getElementById('id_pasta').addEventListener('input', function(e) {
        let value = e.target.value;
        value = value.replace(/[^A-Za-z0-9\-_]/g, '');
        value = value.toUpperCase();
        e.target.value = value;
    });

    // Buscar CEP
    document.getElementById('cep').addEventListener('blur', function() {
        const cep = this.value.replace(/\D/g, '');
        
        if (cep.length === 8) {
            fetch(`https://viacep.com.br/ws/${cep}/json/`)
                .then(response => response.json())
                .then(data => {
                    if (!data.erro) {
                        if (!document.getElementById('endereco').value) {
                            document.getElementById('endereco').value = data.logradouro;
                        }
                        if (!document.getElementById('bairro').value) {
                            document.getElementById('bairro').value = data.bairro;
                        }
                        if (!document.getElementById('cidade').value) {
                            document.getElementById('cidade').value = data.localidade;
                        }
                        if (!document.getElementById('estado').value) {
                            document.getElementById('estado').value = data.uf;
                        }
                    }
                })
                .catch(error => console.log('Erro ao buscar CEP:', error));
        }
    });
</script>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Editar Cliente', $conteudo, 'clientes');
?>