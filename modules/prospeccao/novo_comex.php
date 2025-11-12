<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';
require_once '../../includes/layout.php';

$usuario_logado = Auth::user();
$nivel_acesso_logado = $usuario_logado['nivel_acesso'];
$usuario_id = $usuario_logado['usuario_id'] ?? $usuario_logado['user_id'] ?? $usuario_logado['id'];

// MÓDULO FIXO
$modulo_codigo = 'COMEX';

if (!$usuario_id) {
    die("Erro: Usuário não identificado. Faça login novamente.");
}

// Verificar permissão
$pode_criar = in_array($nivel_acesso_logado, ['Admin', 'Socio', 'Diretor', 'Gestor', 'Advogado']);
if (!$pode_criar) {
    header('Location: comex.php');
    exit;
}

// Buscar usuários
try {
    $sql_usuarios = "SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome ASC";
    $stmt_usuarios = executeQuery($sql_usuarios);
    $usuarios = $stmt_usuarios->fetchAll();
} catch (Exception $e) {
    $usuarios = [];
}

// Processar formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Dados básicos
        $tipo_cliente = $_POST['tipo_cliente'] ?? 'PF';
        $nome = trim($_POST['nome']);
        $telefone = trim($_POST['telefone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $cidade = trim($_POST['cidade']);
        $responsavel_id = $_POST['responsavel_id'];
        $meio = $_POST['meio'];
        $valor_proposta = !empty($_POST['valor_proposta']) ? str_replace(['.', ','], ['', '.'], $_POST['valor_proposta']) : null;
        $percentual_exito = !empty($_POST['percentual_exito']) ? floatval($_POST['percentual_exito']) : null;
        $observacoes = trim($_POST['observacoes'] ?? '');
        
        // Validação: telefone OU e-mail obrigatórios
        if (empty($telefone) && empty($email)) {
            throw new Exception('Informe pelo menos Telefone OU E-mail!');
        }
        
        // Campos PJ
        $responsavel_contato = $tipo_cliente === 'PJ' ? trim($_POST['responsavel_contato'] ?? '') : null;
        $cargo_responsavel = $tipo_cliente === 'PJ' ? trim($_POST['cargo_responsavel'] ?? '') : null;
        $proprietario_principal = $tipo_cliente === 'PJ' ? trim($_POST['proprietario_principal'] ?? '') : null;
        $segmento_atuacao = $tipo_cliente === 'PJ' ? trim($_POST['segmento_atuacao'] ?? '') : null;
        
        $pdo = getConnection();
        $pdo->beginTransaction();
        
        // Inserir prospecto
        $sql = "INSERT INTO prospeccoes (
                    modulo_codigo, tipo_cliente, nome, telefone, email, cidade, 
                    responsavel_id, meio, fase, valor_proposta, percentual_exito, observacoes,
                    responsavel_contato, cargo_responsavel, proprietario_principal, segmento_atuacao,
                    criado_por, ativo
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Prospecção', ?, ?, ?, ?, ?, ?, ?, ?, 1)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $modulo_codigo, $tipo_cliente, $nome, $telefone, $email, $cidade,
            $responsavel_id, $meio, $valor_proposta, $percentual_exito, $observacoes,
            $responsavel_contato, $cargo_responsavel, $proprietario_principal, $segmento_atuacao,
            $usuario_id
        ]);
        
        $prospecto_id = $pdo->lastInsertId();
        
        // Registrar no histórico
        $sql_hist = "INSERT INTO prospeccoes_historico (
                        prospeccao_id, fase_anterior, fase_nova, 
                        valor_informado, observacao, usuario_id
                     ) VALUES (?, NULL, 'Prospecção', ?, 'Prospecto criado', ?)";
        
        $stmt_hist = $pdo->prepare($sql_hist);
        $stmt_hist->execute([$prospecto_id, $valor_proposta, $usuario_id]);
        
        $pdo->commit();
        
        header('Location: comex.php?sucesso=1');
        exit;
        
    } catch (Exception $e) {
        if (isset($pdo)) {
            $pdo->rollBack();
        }
        $erro = "Erro ao cadastrar prospecto: " . $e->getMessage();
        error_log($erro);
    }
}

ob_start();
?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">

<style>
    .form-container {
        max-width: 900px;
        margin: 20px auto;
        background: white;
        border-radius: 15px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        padding: 30px;
    }

    .form-header {
        text-align: center;
        margin-bottom: 30px;
        padding-bottom: 20px;
        border-bottom: 3px solid #667eea;
    }

    .form-header h1 {
        color: #2c3e50;
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 8px;
    }

    .form-header p {
        color: #7f8c8d;
        font-size: 14px;
    }

    .tipo-cliente-selector {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
        margin-bottom: 30px;
    }

    .tipo-option {
        padding: 20px;
        border: 2px solid #e0e0e0;
        border-radius: 10px;
        text-align: center;
        cursor: pointer;
        transition: all 0.3s ease;
    }

    .tipo-option:hover {
        border-color: #667eea;
        background: #f8f9ff;
    }

    .tipo-option.active {
        border-color: #667eea;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
    }

    .tipo-option i {
        font-size: 32px;
        margin-bottom: 10px;
        display: block;
    }

    .tipo-option.active i {
        color: white;
    }

    .form-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
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
        font-weight: 600;
        color: #2c3e50;
        margin-bottom: 8px;
        font-size: 14px;
    }

    .form-group label .required {
        color: #e74c3c;
        margin-left: 3px;
    }

    .help-text {
        display: block;
        font-size: 11px;
        color: #7f8c8d;
        margin-top: 4px;
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

    .campos-pj {
        display: none;
        grid-column: 1 / -1;
        padding: 20px;
        background: #f8f9ff;
        border-radius: 10px;
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

    .campos-pj-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
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
        box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
    }

    .btn-secondary {
        background: #e0e0e0;
        color: #2c3e50;
    }

    .btn-secondary:hover {
        background: #d0d0d0;
    }

    .alert {
        padding: 15px 20px;
        border-radius: 8px;
        margin-bottom: 20px;
    }

    .alert-danger {
        background: #fee;
        border: 1px solid #fcc;
        color: #c33;
    }

    @media (max-width: 768px) {
        .form-grid,
        .campos-pj-grid,
        .tipo-cliente-selector {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="form-container">
    <div class="form-header">
        <h1><i class="fas fa-globe-americas"></i> Novo Prospecto - COMEX</h1>
        <p>Preencha os dados do prospecto</p>
    </div>

    <?php if (isset($erro)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-circle"></i>
            <?= htmlspecialchars($erro) ?>
        </div>
    <?php endif; ?>

    <form method="POST" id="formProspecto">
        <input type="hidden" name="tipo_cliente" id="tipo_cliente" value="PF">
        
        <!-- Seletor de Tipo de Cliente -->
        <div class="tipo-cliente-selector">
            <div class="tipo-option active" data-tipo="PF" onclick="selecionarTipo('PF')">
                <i class="fas fa-user"></i>
                <strong>Pessoa Física</strong>
            </div>
            <div class="tipo-option" data-tipo="PJ" onclick="selecionarTipo('PJ')">
                <i class="fas fa-building"></i>
                <strong>Pessoa Jurídica</strong>
            </div>
        </div>

        <!-- Campos Básicos -->
        <div class="form-grid">
            <div class="form-group full-width">
                <label>
                    <span id="label-nome">Nome Completo</span>
                    <span class="required">*</span>
                </label>
                <input type="text" name="nome" class="form-control" required>
            </div>

            <div class="form-group">
                <label>Telefone <span class="required">*</span></label>
                <input type="text" name="telefone" id="telefone" class="form-control" 
                       placeholder="(00) 00000-0000">
                <small class="help-text">* Telefone OU E-mail são obrigatórios</small>
            </div>

            <div class="form-group">
                <label>E-mail <span class="required">*</span></label>
                <input type="email" name="email" id="email" class="form-control" 
                       placeholder="email@exemplo.com">
                <small class="help-text">* Telefone OU E-mail são obrigatórios</small>
            </div>
            
            <div class="form-group">
                <label>Cidade <span class="required">*</span></label>
                <input type="text" name="cidade" class="form-control" required>
            </div>

            <div class="form-group">
                <label>Responsável <span class="required">*</span></label>
                <select name="responsavel_id" class="form-control" required>
                    <option value="">Selecione...</option>
                    <?php foreach ($usuarios as $usuario): ?>
                        <option value="<?= $usuario['id'] ?>" 
                                <?= $usuario['id'] == $usuario_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($usuario['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label>Meio de Prospecção <span class="required">*</span></label>
                <select name="meio" class="form-control" required>
                    <option value="">Selecione...</option>
                    <option value="Online">Online</option>
                    <option value="Presencial">Presencial</option>
                </select>
            </div>

            <div class="form-group">
                <label>Valor Proposta (R$)</label>
                <input type="text" name="valor_proposta" class="form-control money-input" 
                       placeholder="0,00">
            </div>
            
            <!-- NOVO CAMPO -->
            <div class="form-group">
                <label>Percentual de Honorários de Êxito (%)</label>
                <input type="number" name="percentual_exito" class="form-control" 
                       placeholder="Ex: 30" 
                       min="0" 
                       max="100" 
                       step="0.01">
                <small style="color: #7f8c8d; font-size: 12px; margin-top: 5px; display: block;">
                    💡 Percentual que o escritório receberá do valor da causa em caso de êxito
                </small>
            </div>
            
            <div class="form-group full-width">
                <label>Observações</label>
                <textarea name="observacoes" class="form-control" rows="4"></textarea>
            </div>
        </div>

        <!-- Campos Específicos para PJ -->
        <div class="campos-pj" id="campos-pj">
            <div class="campos-pj-header">
                <i class="fas fa-building"></i>
                Informações da Empresa
            </div>
            <div class="campos-pj-grid">
                <div class="form-group">
                    <label>Responsável pelo Contato</label>
                    <input type="text" name="responsavel_contato" class="form-control"
                           placeholder="Nome do contato">
                </div>

                <div class="form-group">
                    <label>Cargo do Responsável</label>
                    <input type="text" name="cargo_responsavel" class="form-control"
                           placeholder="Ex: Diretor, Gerente">
                </div>

                <div class="form-group">
                    <label>Proprietário Principal</label>
                    <input type="text" name="proprietario_principal" class="form-control"
                           placeholder="Nome do proprietário/sócio">
                </div>

                <div class="form-group">
                    <label>Segmento de Atuação</label>
                    <input type="text" name="segmento_atuacao" class="form-control"
                           placeholder="Ex: Comércio, Indústria, Serviços">
                </div>
            </div>
        </div>

        <!-- Botões -->
        <div class="btn-group">
            <a href="comex.php" class="btn btn-secondary">
                <i class="fas fa-times"></i>
                Cancelar
            </a>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-check"></i>
                Cadastrar Prospecto
            </button>
        </div>
    </form>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js"></script>

<script>
// Seletor de tipo de cliente
function selecionarTipo(tipo) {
    // Atualizar campo hidden
    document.getElementById('tipo_cliente').value = tipo;
    
    // Atualizar visual dos botões
    document.querySelectorAll('.tipo-option').forEach(opt => {
        opt.classList.remove('active');
    });
    document.querySelector(`[data-tipo="${tipo}"]`).classList.add('active');
    
    // Mostrar/ocultar campos PJ
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

// Máscaras
$(document).ready(function() {
    $('[name="telefone"]').mask('(00) 00000-0000');
    
    $('.money-input').mask('#.##0,00', {
        reverse: true,
        placeholder: '0,00'
    });
});
</script>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Novo Prospecto - COMEX', $conteudo, 'prospeccao');
?>