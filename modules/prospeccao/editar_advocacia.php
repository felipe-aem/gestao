<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';
require_once '../../includes/layout.php';

// Verificação de módulo em desenvolvimento
require_once __DIR__ . '/../../config/modules_config.php';
$moduloAtual = 'prospeccao';
$usuarioLogado = $_SESSION['usuario_id'] ?? null;

if (verificarModuloEmDesenvolvimento($moduloAtual, $usuarioLogado)) {
    include __DIR__ . '/../../config/paginas/em_desenvolvimento.html';
    exit;
}

$usuario_logado = Auth::user();
$nivel_acesso_logado = $usuario_logado['nivel_acesso'];
$usuario_id = $usuario_logado['usuario_id'] ?? $usuario_logado['id'] ?? $_SESSION['usuario_id'] ?? null;

// MÓDULO FIXO
$modulo_codigo = 'ADVOCACIA';

// Verificar permissão
$usuarios_especiais = [28];
$pode_editar = in_array($nivel_acesso_logado, ['Admin', 'Socio', 'Diretor', 'Gestor', 'Advogado']) 
                     || in_array($usuario_id, $usuarios_especiais);
if (!$pode_editar) {
    header('Location: advocacia.php');
    exit;
}

// Obter ID
$id = $_GET['id'] ?? null;
if (!$id) {
    header('Location: advocacia.php?erro=id_invalido');
    exit;
}

// Buscar prospecto
try {
    $pdo = getConnection();
    $sql = "SELECT * FROM prospeccoes WHERE id = ? AND ativo = 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $prospecto = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$prospecto || $prospecto['modulo_codigo'] !== $modulo_codigo) {
        header('Location: advocacia.php?erro=nao_encontrado');
        exit;
    }
} catch (Exception $e) {
    header('Location: advocacia.php?erro=busca');
    exit;
}

// Buscar núcleos do prospecto
try {
    $sql_nucleos_prospecto = "SELECT nucleo_id, percentual FROM prospeccoes_nucleos WHERE prospeccao_id = ?";
    $stmt_nucleos_prospecto = $pdo->prepare($sql_nucleos_prospecto);
    $stmt_nucleos_prospecto->execute([$id]);
    $nucleos_prospecto = [];
    while ($row = $stmt_nucleos_prospecto->fetch(PDO::FETCH_ASSOC)) {
        $nucleos_prospecto[$row['nucleo_id']] = $row['percentual'];
    }
} catch (Exception $e) {
    $nucleos_prospecto = [];
}

// Buscar núcleos
try {
    $sql_nucleos = "SELECT id, nome FROM nucleos WHERE ativo = 1 ORDER BY nome ASC";
    $stmt_nucleos = $pdo->query($sql_nucleos);
    $nucleos = $stmt_nucleos->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $nucleos = [];
}

// Buscar usuários
try {
    $sql_usuarios = "SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome ASC";
    $stmt_usuarios = $pdo->query($sql_usuarios);
    $usuarios = $stmt_usuarios->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $usuarios = [];
}

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Dados básicos
        $tipo_cliente = $_POST['tipo_cliente'] ?? 'PF';
        $nome = trim($_POST['nome']);
        $telefone = trim($_POST['telefone']);
        $email = trim($_POST['email'] ?? '');
        $cidade = trim($_POST['cidade']);
        $responsavel_id = $_POST['responsavel_id'];
        $meio = $_POST['meio'];
        $fase = $_POST['fase'];
        $valor_proposta = !empty($_POST['valor_proposta']) ? str_replace(['.', ','], ['', '.'], $_POST['valor_proposta']) : null;
        $percentual_exito = !empty($_POST['percentual_exito']) ? floatval($_POST['percentual_exito']) : null;
        $estimativa_ganho = !empty($_POST['estimativa_ganho']) ? str_replace(['.', ','], ['', '.'], $_POST['estimativa_ganho']) : null;
        $indicacao = $_POST['indicacao'] ?? null;
        $observacoes = trim($_POST['observacoes'] ?? '');
        $eh_recontratacao = isset($_POST['eh_recontratacao']) ? 1 : 0;
        
        // Campos PJ
        $cpf_cnpj = trim($_POST['cpf_cnpj'] ?? '');
        $responsavel_contato = $tipo_cliente === 'PJ' ? trim($_POST['responsavel_contato'] ?? '') : null;
        $cargo_responsavel = $tipo_cliente === 'PJ' ? trim($_POST['cargo_responsavel'] ?? '') : null;
        $proprietario_principal = $tipo_cliente === 'PJ' ? trim($_POST['proprietario_principal'] ?? '') : null;
        $segmento_atuacao = $tipo_cliente === 'PJ' ? trim($_POST['segmento_atuacao'] ?? '') : null;
        
        // Pegar primeiro núcleo para compatibilidade
        $nucleo_id = null;
        if (!empty($_POST['nucleos_percentuais'])) {
            $nucleo_id = array_key_first($_POST['nucleos_percentuais']);
        }
        
        // BUSCAR DADOS ANTIGOS PARA COMPARAÇÃO
$sql_old = "SELECT * FROM prospeccoes WHERE id = ?";
$stmt_old = $pdo->prepare($sql_old);
$stmt_old->execute([$id]);
$dados_antigos = $stmt_old->fetch(PDO::FETCH_ASSOC);

// Array para armazenar mudanças
$mudancas = [];

// Comparar campos e registrar mudanças
$campos_monitorar = [
    'tipo_cliente' => 'Tipo de Cliente',
    'nome' => 'Nome',
    'telefone' => 'Telefone',
    'email' => 'E-mail',
    'cidade' => 'Cidade',
    'cpf_cnpj' => 'CPF/CNPJ',
    'responsavel_id' => 'Responsável',
    'meio' => 'Meio',
    'fase' => 'Fase',
    'valor_proposta' => 'Valor Proposta',
    'percentual_exito' => 'Percentual Êxito',
    'estimativa_ganho' => 'Estimativa de Ganho',
    'indicacao' => 'Indicação',
    'responsavel_contato' => 'Responsável Contato (PJ)',
    'cargo_responsavel' => 'Cargo Responsável (PJ)',
    'proprietario_principal' => 'Proprietário Principal (PJ)',
    'segmento_atuacao' => 'Segmento de Atuação (PJ)',
    'eh_recontratacao' => 'Recontratação'
];

// Preparar valores novos
$valores_novos = [
    'tipo_cliente' => $tipo_cliente,
    'nome' => $nome,
    'telefone' => $telefone,
    'email' => $email,
    'cidade' => $cidade,
    'cpf_cnpj' => $cpf_cnpj,
    'responsavel_id' => $responsavel_id,
    'meio' => $meio,
    'fase' => $fase,
    'valor_proposta' => $valor_proposta,
    'percentual_exito' => $percentual_exito,
    'estimativa_ganho' => $estimativa_ganho,
    'indicacao' => $indicacao,
    'responsavel_contato' => $responsavel_contato,
    'cargo_responsavel' => $cargo_responsavel,
    'proprietario_principal' => $proprietario_principal,
    'segmento_atuacao' => $segmento_atuacao,
    'eh_recontratacao' => $eh_recontratacao
];

// Detectar mudanças
foreach ($campos_monitorar as $campo => $label) {
    $valor_antigo = $dados_antigos[$campo] ?? null;
    $valor_novo = $valores_novos[$campo] ?? null;
    
    // Normalizar para comparação
    if ($valor_antigo != $valor_novo) {
        // Formatar valores para exibição
        $valor_antigo_formatado = $valor_antigo;
        $valor_novo_formatado = $valor_novo;
        
        // Formatar valores monetários
        if (in_array($campo, ['valor_proposta', 'estimativa_ganho'])) {
            $valor_antigo_formatado = $valor_antigo ? 'R$ ' . number_format($valor_antigo, 2, ',', '.') : 'Não informado';
            $valor_novo_formatado = $valor_novo ? 'R$ ' . number_format($valor_novo, 2, ',', '.') : 'Não informado';
        }
        
        // Formatar percentual
        if ($campo === 'percentual_exito') {
            $valor_antigo_formatado = $valor_antigo ? $valor_antigo . '%' : 'Não informado';
            $valor_novo_formatado = $valor_novo ? $valor_novo . '%' : 'Não informado';
        }
        
        // Formatar booleanos
        if ($campo === 'eh_recontratacao') {
            $valor_antigo_formatado = $valor_antigo ? 'Sim' : 'Não';
            $valor_novo_formatado = $valor_novo ? 'Sim' : 'Não';
        }
        
        // Buscar nome do responsável se mudou
        if ($campo === 'responsavel_id') {
            $sql_resp_old = "SELECT nome FROM usuarios WHERE id = ?";
            $stmt_resp_old = $pdo->prepare($sql_resp_old);
            $stmt_resp_old->execute([$valor_antigo]);
            $resp_old = $stmt_resp_old->fetch();
            
            $sql_resp_new = "SELECT nome FROM usuarios WHERE id = ?";
            $stmt_resp_new = $pdo->prepare($sql_resp_new);
            $stmt_resp_new->execute([$valor_novo]);
            $resp_new = $stmt_resp_new->fetch();
            
            $valor_antigo_formatado = $resp_old ? $resp_old['nome'] : 'Não definido';
            $valor_novo_formatado = $resp_new ? $resp_new['nome'] : 'Não definido';
        }
        
        $mudancas[] = [
            'campo' => $campo,
            'label' => $label,
            'valor_antigo' => $valor_antigo_formatado,
            'valor_novo' => $valor_novo_formatado
        ];
    }
}
        $pdo->beginTransaction();
        
        // Atualizar prospecto
        $sql = "UPDATE prospeccoes SET
                    tipo_cliente = ?, nome = ?, telefone = ?, email = ?, cidade = ?,
                    nucleo_id = ?, cpf_cnpj = ?,
                    responsavel_id = ?, meio = ?, fase = ?,
                    valor_proposta = ?, percentual_exito = ?, estimativa_ganho = ?, indicacao = ?, observacoes = ?,
                    responsavel_contato = ?, cargo_responsavel = ?, 
                    proprietario_principal = ?, segmento_atuacao = ?,
                    eh_recontratacao = ?, data_ultima_atualizacao = CURRENT_TIMESTAMP
                WHERE id = ?";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $tipo_cliente, $nome, $telefone, $email, $cidade,
            $nucleo_id, $cpf_cnpj,
            $responsavel_id, $meio, $fase,
            $valor_proposta, $percentual_exito, $estimativa_ganho, $indicacao, $observacoes,
            $responsavel_contato, $cargo_responsavel,
            $proprietario_principal, $segmento_atuacao,
            $eh_recontratacao,
            $id
        ]);
        
        // Atualizar núcleos com percentuais
        if (!empty($_POST['nucleos_percentuais'])) {
            // Deletar núcleos antigos
            $sql_delete = "DELETE FROM prospeccoes_nucleos WHERE prospeccao_id = ?";
            $stmt_delete = $pdo->prepare($sql_delete);
            $stmt_delete->execute([$id]);
            
            // Inserir novos núcleos
            foreach ($_POST['nucleos_percentuais'] as $nucleo_id_loop => $percentual) {
                // Converter vírgula para ponto
                $percentual = str_replace(',', '.', $percentual);
                $percentual_float = floatval($percentual);
                
                if ($percentual_float > 0) {
                    $sql_nucleo = "INSERT INTO prospeccoes_nucleos (prospeccao_id, nucleo_id, percentual) 
                                   VALUES (?, ?, ?)";
                    $stmt_nucleo = $pdo->prepare($sql_nucleo);
                    $stmt_nucleo->execute([$id, $nucleo_id_loop, $percentual_float]);
                }
            }
        }
        
        // Registrar mudanças no histórico
        if (!empty($mudancas)) {
            foreach ($mudancas as $mudanca) {
                $sql_hist = "INSERT INTO prospeccoes_historico 
                             (prospeccao_id, tipo_acao, campo_alterado, valor_anterior, valor_novo, usuario_id, data_movimento)
                             VALUES (?, 'edicao_campo', ?, ?, ?, ?, NOW())";
                
                $stmt_hist = $pdo->prepare($sql_hist);
                $stmt_hist->execute([
                    $id,
                    $mudanca['label'],
                    $mudanca['valor_antigo'],
                    $mudanca['valor_novo'],
                    $usuario_id
                ]);
            }
            
            error_log("✅ Registradas " . count($mudancas) . " mudanças no histórico do prospecto #$id");
        }
        
        $pdo->commit();
        
        header('Location: visualizar_advocacia.php?id=' . $id . '&sucesso=1');
        exit;
        
    } catch (Exception $e) {
        if (isset($pdo)) {
            $pdo->rollBack();
        }
        error_log("Erro ao atualizar prospecto: " . $e->getMessage());
        $erro = "Erro ao atualizar prospecto: " . $e->getMessage();
    }
}

ob_start();
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

<style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    .form-container {
        max-width: 900px;
        margin: 20px auto;
        padding: 0 20px;
    }

    .page-header {
        background: white;
        border-radius: 12px;
        padding: 25px;
        margin-bottom: 25px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .page-header h1 {
        font-size: 24px;
        font-weight: 700;
        color: #2c3e50;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .form-card {
        background: white;
        border-radius: 12px;
        padding: 30px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }

    .section-title {
        font-size: 16px;
        font-weight: 700;
        color: #2c3e50;
        margin-bottom: 20px;
        padding-bottom: 12px;
        border-bottom: 2px solid #ecf0f1;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .form-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 20px;
        margin-bottom: 25px;
    }

    .form-group {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .form-group.full-width {
        grid-column: 1 / -1;
    }

    .form-group label {
        font-size: 13px;
        font-weight: 700;
        color: #2c3e50;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .form-group label .required {
        color: #e74c3c;
        margin-left: 3px;
    }

    .form-control {
        width: 100%;
        padding: 12px 15px;
        border: 2px solid #e0e0e0;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.3s ease;
    }

    .form-control:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }

    textarea.form-control {
        min-height: 100px;
        resize: vertical;
    }

    .campos-pj {
        display: none;
        background: #f8f9ff;
        border-radius: 10px;
        padding: 20px;
        border: 2px dashed #667eea;
        margin: 20px 0;
    }

    .campos-pj.active {
        display: block;
    }

    .campos-pj-header {
        font-size: 16px;
        font-weight: 700;
        color: #667eea;
        margin-bottom: 15px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .recontratacao-container {
        background: #f8f9fa;
        border: 2px solid #e0e0e0;
        border-radius: 10px;
        padding: 15px 18px;
        margin: 20px 0;
        display: flex;
        align-items: flex-start;
        gap: 12px;
        transition: all 0.3s ease;
        cursor: pointer;
    }

    .recontratacao-container:hover {
        border-color: #667eea;
        background: #f8f9ff;
    }

    .recontratacao-container input[type="checkbox"] {
        width: 20px;
        height: 20px;
        cursor: pointer;
        margin: 0;
        flex-shrink: 0;
        margin-top: 2px;
        accent-color: #667eea;
    }

    .recontratacao-content {
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .recontratacao-content strong {
        font-size: 14px;
        font-weight: 600;
        color: #2c3e50;
        cursor: pointer;
        line-height: 1.4;
    }

    .recontratacao-content small {
        font-size: 12px;
        color: #7f8c8d;
        font-style: italic;
        line-height: 1.3;
    }

    /* NÚCLEOS */
    .nucleos-box-always {
        background: #f8f9fa;
        border: 2px solid #e0e0e0;
        border-radius: 12px;
        padding: 0;
        margin: 20px 0;
        overflow: hidden;
    }

    .nucleos-header-always {
        background: linear-gradient(135deg, #3498db 0%, #2980b9 100%);
        color: white;
        padding: 15px 20px;
        font-weight: 600;
        font-size: 15px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .percentual-badge {
        background: rgba(255, 255, 255, 0.25);
        backdrop-filter: blur(10px);
        padding: 6px 16px;
        border-radius: 20px;
        font-size: 13px;
        font-weight: 700;
        min-width: 60px;
        text-align: center;
        border: 1px solid rgba(255, 255, 255, 0.3);
        transition: all 0.3s;
    }

    .percentual-badge.completo {
        background: #27ae60;
        border-color: rgba(255, 255, 255, 0.5);
        animation: pulse 0.5s;
    }

    .percentual-badge.incompleto {
        background: #e74c3c;
        border-color: rgba(255, 255, 255, 0.5);
    }

    .percentual-badge.zerado {
        background: rgba(255, 255, 255, 0.2);
    }

    @keyframes pulse {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.1); }
    }

    .nucleos-content-always {
        background: white;
        padding: 20px;
        max-height: 320px;
        overflow-y: auto;
    }

    .nucleos-content-always::-webkit-scrollbar {
        width: 8px;
    }

    .nucleos-content-always::-webkit-scrollbar-track {
        background: #f1f1f1;
        border-radius: 10px;
    }

    .nucleos-content-always::-webkit-scrollbar-thumb {
        background: #3498db;
        border-radius: 10px;
    }

    .nucleo-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 12px 15px;
        border: 2px solid #e0e0e0;
        border-radius: 8px;
        margin-bottom: 10px;
        transition: all 0.2s;
        background: white;
    }

    .nucleo-item:hover {
        border-color: #3498db;
        background: #f8fbfd;
        transform: translateX(3px);
    }

    .nucleo-item.selected {
        border-color: #3498db;
        background: #e8f4f8;
    }

    .nucleo-checkbox {
        display: flex;
        align-items: center;
        gap: 12px;
        flex: 1;
    }

    .nucleo-checkbox input[type="checkbox"] {
        width: 18px;
        height: 18px;
        cursor: pointer;
        margin: 0;
        flex-shrink: 0;
        accent-color: #3498db;
    }

    .nucleo-checkbox label {
        cursor: pointer;
        margin: 0;
        color: #2c3e50;
        font-size: 14px;
        font-weight: 500;
        text-transform: none;
        letter-spacing: normal;
    }

    .nucleo-percentual {
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .percentual-input {
        width: 70px;
        padding: 6px 10px;
        border: 2px solid #3498db;
        border-radius: 6px;
        font-size: 13px;
        font-weight: 600;
        text-align: center;
        transition: all 0.2s;
    }

    .percentual-input:focus {
        outline: none;
        border-color: #2980b9;
        box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.15);
        transform: scale(1.05);
    }

    .percentual-symbol {
        color: #3498db;
        font-weight: 700;
        font-size: 16px;
    }

    .btn-helper {
        flex: 1;
        padding: 10px 15px;
        background: #3498db;
        color: white;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-size: 13px;
        font-weight: 600;
        transition: all 0.3s;
    }

    .btn-helper:hover {
        background: #2980b9;
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(52, 152, 219, 0.3);
    }

    .btn-helper.btn-secondary {
        background: #95a5a6;
    }

    .btn-helper.btn-secondary:hover {
        background: #7f8c8d;
    }

    #alerta_percentual {
        padding: 12px;
        border-radius: 6px;
        margin-top: 15px;
        font-size: 13px;
    }

    .input-with-prefix {
        position: relative;
        display: flex;
        align-items: center;
    }

    .input-prefix {
        position: absolute;
        left: 15px;
        color: #27ae60;
        font-weight: 700;
        font-size: 16px;
        pointer-events: none;
        z-index: 1;
    }

    .input-with-prefix .form-control {
        padding-left: 50px;
    }

    .btn-group {
        display: flex;
        gap: 10px;
        justify-content: flex-end;
        margin-top: 30px;
        padding-top: 20px;
        border-top: 2px solid #f0f0f0;
    }

    .btn {
        padding: 12px 30px;
        border: none;
        border-radius: 8px;
        font-size: 15px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.3s ease;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
    }

    .btn-primary {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
    }

    .btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
    }

    .btn-secondary {
        background: #e9ecef;
        color: #495057;
    }

    .btn-secondary:hover {
        background: #dee2e6;
    }

    .alert {
        padding: 15px 20px;
        border-radius: 8px;
        margin-bottom: 20px;
        font-size: 14px;
    }

    .alert-error {
        background: #f8d7da;
        color: #721c24;
        border-left: 4px solid #dc3545;
    }

    .help-text {
        font-size: 12px;
        color: #7f8c8d;
        margin-top: 5px;
        font-style: italic;
    }

    @media (max-width: 768px) {
        .form-grid {
            grid-template-columns: 1fr;
        }

        .page-header {
            flex-direction: column;
            gap: 15px;
            text-align: center;
        }

        .btn-group {
            flex-direction: column;
        }

        .btn {
            width: 100%;
            justify-content: center;
        }

        .nucleo-item {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }

        .nucleo-percentual {
            width: 100%;
            justify-content: flex-end;
        }
    }
</style>

<div class="form-container">
    <div class="page-header">
        <h1><i class="fas fa-edit"></i> Editar Prospecto - Advocacia</h1>
        <a href="visualizar_advocacia.php?id=<?= $id ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Voltar
        </a>
    </div>

    <?php if (isset($erro)): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <?= htmlspecialchars($erro) ?>
        </div>
    <?php endif; ?>

    <div class="form-card">
        <form method="POST" id="formProspecto">
            <div class="section-title">
                <i class="fas fa-info-circle"></i>
                INFORMAÇÕES BÁSICAS
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label>Tipo de Cliente <span class="required">*</span></label>
                    <select name="tipo_cliente" id="tipo_cliente" class="form-control" required>
                        <option value="PF" <?= $prospecto['tipo_cliente'] === 'PF' ? 'selected' : '' ?>>👤 Pessoa Física</option>
                        <option value="PJ" <?= $prospecto['tipo_cliente'] === 'PJ' ? 'selected' : '' ?>>🏢 Pessoa Jurídica</option>
                    </select>
                </div>

                <div class="form-group">
                    <label><span id="label-nome">Nome/Razão Social</span> <span class="required">*</span></label>
                    <input type="text" name="nome" class="form-control" required
                           value="<?= htmlspecialchars($prospecto['nome']) ?>">
                </div>

                <div class="form-group">
                    <label>Telefone <span class="required">*</span></label>
                    <input type="text" name="telefone" id="telefone" class="form-control" required
                           value="<?= htmlspecialchars($prospecto['telefone']) ?>">
                </div>

                <div class="form-group">
                    <label>E-mail</label>
                    <input type="email" name="email" class="form-control"
                           value="<?= htmlspecialchars($prospecto['email'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label>Cidade <span class="required">*</span></label>
                    <input type="text" name="cidade" class="form-control" required
                           value="<?= htmlspecialchars($prospecto['cidade']) ?>">
                </div>
                
                <div class="form-group full-width">
                    <div class="recontratacao-container" onclick="toggleCheckboxContainer(this)">
                        <input type="checkbox" 
                               id="eh_recontratacao" 
                               name="eh_recontratacao" 
                               value="1"
                               <?= $prospecto['eh_recontratacao'] ? 'checked' : '' ?>
                               onclick="event.stopPropagation()">
                        <div class="recontratacao-content">
                            <strong>🔄 É uma Recontratação?</strong>
                            <small>Marque se este cliente já foi atendido anteriormente e está retornando</small>
                        </div>
                    </div>
                </div>

                <div class="form-group full-width">
                    <label style="font-size: 16px; font-weight: 600; color: #2c3e50; margin-bottom: 10px; display: block;">
                        🏢 Núcleos Participantes *
                    </label>
                    <small style="color: #7f8c8d; display: block; margin-bottom: 15px;">
                        Selecione os núcleos envolvidos e defina o percentual de cada um. A soma deve ser 100%.
                    </small>
                    
                    <div class="nucleos-box-always">
                        <div class="nucleos-header-always">
                            <span>Distribuição entre Núcleos</span>
                            <span id="percentual_total" class="percentual-badge zerado">0%</span>
                        </div>
                        
                        <div class="nucleos-content-always">
                            <div id="lista_nucleos_percentuais">
                                <?php foreach ($nucleos as $nucleo): 
                                    $checked = isset($nucleos_prospecto[$nucleo['id']]);
                                    $percentual = $checked ? $nucleos_prospecto[$nucleo['id']] : '';
                                ?>
                                    <div class="nucleo-item <?= $checked ? 'selected' : '' ?>" id="item-nucleo-<?= $nucleo['id'] ?>">
                                        <div class="nucleo-checkbox">
                                            <input type="checkbox" 
                                                   class="nucleo-check" 
                                                   id="nucleo-<?= $nucleo['id'] ?>" 
                                                   value="<?= $nucleo['id'] ?>"
                                                   <?= $checked ? 'checked' : '' ?>
                                                   onchange="togglePercentualInput(<?= $nucleo['id'] ?>)">
                                            <label for="nucleo-<?= $nucleo['id'] ?>">
                                                <strong><?= htmlspecialchars($nucleo['nome']) ?></strong>
                                            </label>
                                        </div>
                                        <div class="nucleo-percentual" id="percentual-container-<?= $nucleo['id'] ?>" style="display: <?= $checked ? 'flex' : 'none' ?>;">
                                            <input type="text" 
                                                   name="nucleos_percentuais[<?= $nucleo['id'] ?>]"
                                                   inputmode="decimal" 
                                                   id="percentual-<?= $nucleo['id'] ?>"
                                                   class="percentual-input" 
                                                   value="<?= $percentual ? number_format($percentual, 2, ',', '') : '' ?>"
                                                   placeholder="0"
                                                   oninput="formatarPercentual(this); atualizarTotalPercentual()">
                                            <span class="percentual-symbol">%</span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div style="display: flex; gap: 10px; margin-top: 15px;">
                                <button type="button" id="btn_distribuir_igual" class="btn-helper">
                                    ⚡ Distribuir Igualmente
                                </button>
                                <button type="button" id="btn_limpar" class="btn-helper btn-secondary">
                                    🗑️ Limpar Seleção
                                </button>
                            </div>
                            
                            <div id="alerta_percentual" style="display: none;">
                                <strong>⚠️ Atenção:</strong> <span id="mensagem_percentual"></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-group">
                    <label>Responsável <span class="required">*</span></label>
                    <select name="responsavel_id" class="form-control" required>
                        <option value="">Selecione...</option>
                        <?php foreach ($usuarios as $usuario): ?>
                            <option value="<?= $usuario['id'] ?>" 
                                    <?= $prospecto['responsavel_id'] == $usuario['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($usuario['nome']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Meio de Prospecção <span class="required">*</span></label>
                    <select name="meio" class="form-control" required>
                        <option value="">Selecione...</option>
                        <option value="Online" <?= $prospecto['meio'] === 'Online' ? 'selected' : '' ?>>Online</option>
                        <option value="Presencial" <?= $prospecto['meio'] === 'Presencial' ? 'selected' : '' ?>>Presencial</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Fase Atual <span class="required">*</span></label>
                    <select name="fase" class="form-control" required>
                        <option value="Prospecção" <?= $prospecto['fase'] === 'Prospecção' ? 'selected' : '' ?>>Prospecção</option>
                        <option value="Negociação" <?= $prospecto['fase'] === 'Negociação' ? 'selected' : '' ?>>Negociação</option>
                        <option value="Fechados" <?= $prospecto['fase'] === 'Fechados' ? 'selected' : '' ?>>Fechados</option>
                        <option value="Perdidos" <?= $prospecto['fase'] === 'Perdidos' ? 'selected' : '' ?>>Perdidos</option>
                        <option value="Revisitar" <?= $prospecto['fase'] === 'Revisitar' ? 'selected' : '' ?>>Revisitar</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Valor Proposta (R$)</label>
                    <input type="text" name="valor_proposta" class="form-control money-input"
                           value="<?= $prospecto['valor_proposta'] ? number_format($prospecto['valor_proposta'], 2, ',', '.') : '' ?>"
                           placeholder="0,00">
                </div>

                <div class="form-group">
                    <label>Percentual de Honorários de Êxito (%)</label>
                    <input type="number" name="percentual_exito" class="form-control"
                           value="<?= $prospecto['percentual_exito'] ?? '' ?>"
                           placeholder="Ex: 30" 
                           min="0" 
                           max="100" 
                           step="0.01">
                    <small class="help-text">
                        💡 Percentual que o escritório receberá do valor da causa em caso de êxito
                    </small>
                </div>
                
                <div class="form-group">
                    <label for="estimativa_ganho">
                        💰 Previsão de Ganho (Êxito Estimado)
                    </label>
                    <div class="input-with-prefix">
                        <span class="input-prefix">R$</span>
                        <input type="text" 
                               id="estimativa_ganho" 
                               name="estimativa_ganho" 
                               class="form-control money-input"
                               value="<?= $prospecto['estimativa_ganho'] ? number_format($prospecto['estimativa_ganho'], 2, ',', '.') : '' ?>"
                               placeholder="0,00">
                    </div>
                    <small class="help-text">
                        💡 Este valor será usado para acompanhar suas previsões futuramente
                    </small>
                </div>
                
                <!-- Campo Indicação -->
                <div class="form-group">
                    <label for="indicacao">
                        <i class="fas fa-users"></i> Indicação
                    </label>
                    <input 
                        type="text" 
                        class="form-control" 
                        id="indicacao" 
                        name="indicacao" 
                        maxlength="255"
                        value="<?= htmlspecialchars($prospecto['indicacao'] ?? '') ?>"
                        placeholder="Ex: João Silva, Maria Cliente, Site, etc."
                    >
                    <small class="help-text">
                        👥 Quem indicou este prospecto?
                    </small>
                </div>

                <div class="form-group full-width">
                    <label>
                        Observações Iniciais
                        <small style="color: #dc3545; font-weight: normal;">(não editável)</small>
                    </label>
                    <textarea 
                        name="observacoes" 
                        class="form-control"
                        readonly
                        style="background: #f8f9fa; cursor: not-allowed; color: #495057; border: 2px dashed #dee2e6;"
                    ><?= htmlspecialchars($prospecto['observacoes'] ?? '') ?></textarea>
                    <small class="help-text" style="color: #6c757d; display: block; margin-top: 8px;">
                        🔒 A observação inicial não pode ser editada após a criação. Use o histórico de interações para adicionar novas informações sobre este prospecto.
                    </small>
                </div>
            </div>

            <!-- Campos Específicos para PJ -->
            <div class="campos-pj <?= $prospecto['tipo_cliente'] === 'PJ' ? 'active' : '' ?>" id="campos-pj">
                <div class="campos-pj-header">
                    <i class="fas fa-building"></i>
                    Informações da Empresa
                </div>
                <div class="form-grid">
                    <div class="form-group">
                        <label>CPF/CNPJ</label>
                        <input type="text" name="cpf_cnpj" class="form-control"
                               value="<?= htmlspecialchars($prospecto['cpf_cnpj'] ?? '') ?>"
                               placeholder="000.000.000-00">
                    </div>

                    <div class="form-group">
                        <label>Responsável pelo Contato</label>
                        <input type="text" name="responsavel_contato" class="form-control"
                               value="<?= htmlspecialchars($prospecto['responsavel_contato'] ?? '') ?>"
                               placeholder="Nome do contato">
                    </div>

                    <div class="form-group">
                        <label>Cargo do Responsável</label>
                        <input type="text" name="cargo_responsavel" class="form-control"
                               value="<?= htmlspecialchars($prospecto['cargo_responsavel'] ?? '') ?>"
                               placeholder="Ex: Diretor Financeiro">
                    </div>

                    <div class="form-group">
                        <label>Proprietário Principal</label>
                        <input type="text" name="proprietario_principal" class="form-control"
                               value="<?= htmlspecialchars($prospecto['proprietario_principal'] ?? '') ?>"
                               placeholder="Nome do proprietário">
                    </div>

                    <div class="form-group full-width">
                        <label>Segmento de Atuação</label>
                        <input type="text" name="segmento_atuacao" class="form-control"
                               value="<?= htmlspecialchars($prospecto['segmento_atuacao'] ?? '') ?>"
                               placeholder="Ex: Tecnologia, Comércio, etc">
                    </div>
                </div>
            </div>

            <div class="btn-group">
                <a href="visualizar_advocacia.php?id=<?= $id ?>" class="btn btn-secondary">
                    <i class="fas fa-times"></i>
                    Cancelar
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i>
                    Salvar Alterações
                </button>
            </div>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>

<script>
$(document).ready(function() {
    $('[name="telefone"]').mask('(00) 00000-0000');
    $('.money-input').mask('#.##0,00', { reverse: true });
});

function toggleCheckboxContainer(container) {
    const checkbox = container.querySelector('input[type="checkbox"]');
    if (checkbox && !checkbox.disabled) {
        checkbox.checked = !checkbox.checked;
    }
}

function selecionarTipo(tipo) {
    const camposPJ = document.getElementById('campos-pj');
    const labelNome = document.getElementById('label-nome');
    
    if (tipo === 'PJ') {
        camposPJ.classList.add('active');
        labelNome.textContent = 'Razão Social / Nome Fantasia';
    } else {
        camposPJ.classList.remove('active');
        labelNome.textContent = 'Nome Completo';
    }
}

// Inicializar ao carregar
document.addEventListener('DOMContentLoaded', function() {
    const tipoCliente = document.getElementById('tipo_cliente');
    const camposPJ = document.getElementById('campos-pj');
    const labelNome = document.getElementById('label-nome');
    
    function toggleCamposPJ() {
        if (tipoCliente.value === 'PJ') {
            camposPJ.classList.add('active');
            labelNome.textContent = 'Razão Social / Nome Fantasia';
        } else {
            camposPJ.classList.remove('active');
            labelNome.textContent = 'Nome Completo';
        }
    }
    
    toggleCamposPJ();
    tipoCliente.addEventListener('change', toggleCamposPJ);
    
    // Inicializar cálculo de percentual
    atualizarTotalPercentual();
});

function togglePercentualInput(nucleoId) {
    const checkbox = document.getElementById('nucleo-' + nucleoId);
    const container = document.getElementById('percentual-container-' + nucleoId);
    const input = document.getElementById('percentual-' + nucleoId);
    const item = document.getElementById('item-nucleo-' + nucleoId);
    
    if (checkbox.checked) {
        container.style.display = 'flex';
        item.classList.add('selected');
        input.focus();
    } else {
        container.style.display = 'none';
        item.classList.remove('selected');
        input.value = '';
    }
    
    atualizarTotalPercentual();
}

function formatarPercentual(input) {
    let value = input.value;
    value = value.replace(/[^\d,]/g, '');
    value = value.replace('.', ',');
    
    const parts = value.split(',');
    if (parts.length > 2) {
        value = parts[0] + ',' + parts[1];
    }
    if (parts[1] && parts[1].length > 2) {
        value = parts[0] + ',' + parts[1].substring(0, 2);
    }
    
    const numValue = parseFloat(value.replace(',', '.'));
    if (numValue > 100) {
        value = '100';
    }
    
    input.value = value;
}

function valorPercentual(inputId) {
    const input = document.getElementById(inputId);
    if (!input || !input.value) return 0;
    return parseFloat(input.value.replace(',', '.')) || 0;
}

function atualizarTotalPercentual() {
    let total = 0;
    let count = 0;
    
    document.querySelectorAll('.nucleo-check:checked').forEach(cb => {
        const nucleoId = cb.value;
        const valor = valorPercentual('percentual-' + nucleoId);
        total += valor;
        count++;
    });
    
    const badge = document.getElementById('percentual_total');
    const alerta = document.getElementById('alerta_percentual');
    const mensagem = document.getElementById('mensagem_percentual');
    
    badge.textContent = total.toFixed(1) + '%';
    
    if (count === 0) {
        badge.className = 'percentual-badge zerado';
        alerta.style.display = 'none';
    } else if (Math.abs(total - 100) < 0.1) {
        badge.className = 'percentual-badge completo';
        alerta.style.display = 'none';
    } else {
        badge.className = 'percentual-badge incompleto';
        alerta.style.display = 'block';
        
        if (total < 100) {
            alerta.style.borderLeft = '4px solid #f39c12';
            alerta.style.background = '#fff3cd';
            mensagem.textContent = `Faltam ${(100 - total).toFixed(1)}% para completar 100%`;
        } else {
            alerta.style.borderLeft = '4px solid #e74c3c';
            alerta.style.background = '#fadbd8';
            mensagem.textContent = `Total ultrapassou 100%! Remova ${(total - 100).toFixed(1)}%`;
        }
    }
}

document.getElementById('btn_distribuir_igual').addEventListener('click', function() {
    const selecionados = document.querySelectorAll('.nucleo-check:checked');
    
    if (selecionados.length === 0) {
        alert('⚠️ Selecione pelo menos um núcleo primeiro!');
        return;
    }
    
    const percentualPorNucleo = (100 / selecionados.length).toFixed(2);
    let somaAcumulada = 0;
    
    selecionados.forEach((cb, index) => {
        const nucleoId = cb.value;
        const input = document.getElementById('percentual-' + nucleoId);
        
        if (index === selecionados.length - 1) {
            input.value = (100 - somaAcumulada).toFixed(2).replace('.', ',');
        } else {
            input.value = percentualPorNucleo.replace('.', ',');
            somaAcumulada += parseFloat(percentualPorNucleo);
        }
    });
    
    atualizarTotalPercentual();
});

document.getElementById('btn_limpar').addEventListener('click', function() {
    if (!confirm('🗑️ Tem certeza que deseja limpar toda a seleção?')) {
        return;
    }
    
    document.querySelectorAll('.nucleo-check').forEach(cb => {
        cb.checked = false;
        const nucleoId = cb.value;
        const item = document.getElementById('item-nucleo-' + nucleoId);
        document.getElementById('percentual-container-' + nucleoId).style.display = 'none';
        document.getElementById('percentual-' + nucleoId).value = '';
        item.classList.remove('selected');
    });
    
    atualizarTotalPercentual();
});

$('#formProspecto').on('submit', function(e) {
    // Desabilitar botão
    const submitBtn = $(this).find('button[type="submit"]');
    if (submitBtn.prop('disabled')) {
        e.preventDefault();
        return false;
    }
    submitBtn.prop('disabled', true);
    submitBtn.html('<i class="fas fa-spinner fa-spin"></i> Salvando...');
    
    // Converter vírgulas para pontos
    document.querySelectorAll('.percentual-input').forEach(function(input) {
        if (input.value) {
            input.value = input.value.replace(',', '.');
        }
    });
    
    // Validar núcleos
    const selecionados = document.querySelectorAll('.nucleo-check:checked');
    
    if (selecionados.length === 0) {
        e.preventDefault();
        submitBtn.prop('disabled', false);
        submitBtn.html('<i class="fas fa-save"></i> Salvar Alterações');
        alert('⚠️ Você precisa selecionar pelo menos UM núcleo!');
        document.querySelector('.nucleos-box-always').scrollIntoView({ 
            behavior: 'smooth', 
            block: 'center' 
        });
        return false;
    }
    
    let total = 0;
    selecionados.forEach(cb => {
        const nucleoId = cb.value;
        const valor = valorPercentual('percentual-' + nucleoId);
        total += valor;
    });
    
    if (Math.abs(total - 100) > 0.1) {
        e.preventDefault();
        submitBtn.prop('disabled', false);
        submitBtn.html('<i class="fas fa-save"></i> Salvar Alterações');
        alert('⚠️ O total de percentuais deve ser 100%!\n\nAtual: ' + total.toFixed(1) + '%');
        document.querySelector('.nucleos-box-always').scrollIntoView({ 
            behavior: 'smooth', 
            block: 'center' 
        });
        return false;
    }
    
    return true;
});
</script>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Editar Prospecto - Advocacia', $conteudo, 'prospeccao');
?>