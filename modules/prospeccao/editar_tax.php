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
$modulo_codigo = 'TAX';

// Verificar permissão
$pode_editar = in_array($nivel_acesso_logado, ['Admin', 'Socio', 'Diretor', 'Gestor', 'Advogado']);
if (!$pode_editar) {
    header('Location: tax.php');
    exit;
}

// Obter ID
$id = $_GET['id'] ?? null;
if (!$id) {
    header('Location: tax.php?erro=id_invalido');
    exit;
}

// Buscar prospecto
try {
    $sql = "SELECT * FROM prospeccoes WHERE id = ? AND ativo = 1";
    $stmt = executeQuery($sql, [$id]);
    $prospecto = $stmt->fetch();
    
    if (!$prospecto || $prospecto['modulo_codigo'] !== $modulo_codigo) {
        header('Location: tax.php?erro=nao_encontrado');
        exit;
    }
} catch (Exception $e) {
    header('Location: tax.php?erro=busca');
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
        $fase = $_POST['fase'];
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
        
        // Campos de Visita Semanal e Revisitar
        $data_primeira_visita = ($fase === 'Visita Semanal' && !empty($_POST['data_primeira_visita'])) ? $_POST['data_primeira_visita'] : null;
        $periodicidade = ($fase === 'Visita Semanal' && !empty($_POST['periodicidade'])) ? $_POST['periodicidade'] : null;
        $data_revisita = ($fase === 'Revisitar' && !empty($_POST['data_revisita'])) ? $_POST['data_revisita'] : null;
        
        // Se mudou de fase E não é mais Visita Semanal, limpar campos de visita
        if ($fase !== 'Visita Semanal') {
            $data_primeira_visita = null;
            $periodicidade = null;
        }
        
        // Se mudou de fase E não é mais Revisitar, limpar data_revisita
        if ($fase !== 'Revisitar') {
            $data_revisita = null;
        }
        
        // Atualizar prospecto
        $sql = "UPDATE prospeccoes SET
                    tipo_cliente = ?, nome = ?, telefone = ?, email = ?, cidade = ?,
                    responsavel_id = ?, meio = ?, fase = ?,
                    valor_proposta = ?, percentual_exito = ?, observacoes = ?,
                    responsavel_contato = ?, cargo_responsavel = ?, 
                    proprietario_principal = ?, segmento_atuacao = ?,
                    data_primeira_visita = ?, periodicidade = ?, data_revisita = ?,
                    data_ultima_atualizacao = CURRENT_TIMESTAMP
                WHERE id = ?";
        
        executeQuery($sql, [
            $tipo_cliente, $nome, $telefone, $email, $cidade,
            $responsavel_id, $meio, $fase,
            $valor_proposta, $percentual_exito, $observacoes,
            $responsavel_contato, $cargo_responsavel,
            $proprietario_principal, $segmento_atuacao,
            $data_primeira_visita, $periodicidade, $data_revisita,
            $id
        ]);
        
        header('Location: visualizar_tax.php?id=' . $id . '&sucesso=1');
        exit;
        
    } catch (Exception $e) {
        error_log("Erro ao atualizar prospecto: " . $e->getMessage());
        $erro = "Erro ao atualizar prospecto: " . $e->getMessage();
    }
}

ob_start();
?>

<style>
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    .form-container {
        max-width: 1000px;
        margin: 0 auto;
        padding: 20px;
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

    .form-control:disabled {
        background: #f8f9fa;
        cursor: not-allowed;
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
    }
</style>

<div class="form-container">
    <div class="page-header">
        <h1>✏️ Editar Prospecto - TAX</h1>
        <a href="visualizar_tax.php?id=<?= $id ?>" class="btn btn-secondary">
            ← Voltar
        </a>
    </div>

    <?php if (isset($erro)): ?>
        <div class="alert alert-error">
            ⚠️ <?= htmlspecialchars($erro) ?>
        </div>
    <?php endif; ?>

    <div class="form-card">
        <form method="POST">
            <div class="section-title">
                📋 INFORMAÇÕES BÁSICAS
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
                    <label>Nome/Razão Social <span class="required">*</span></label>
                    <input type="text" name="nome" class="form-control" required
                           value="<?= htmlspecialchars($prospecto['nome']) ?>">
                </div>

                <div class="form-group">
                    <label>Telefone <span class="required">*</span></label>
                    <input type="text" name="telefone" id="telefone" class="form-control"
                           value="<?= htmlspecialchars($prospecto['telefone']) ?>"
                           placeholder="(00) 00000-0000">
                    <small class="help-text">* Telefone OU E-mail são obrigatórios</small>
                </div>

                <div class="form-group">
                    <label>E-mail <span class="required">*</span></label>
                    <input type="email" name="email" id="email" class="form-control"
                           value="<?= htmlspecialchars($prospecto['email'] ?? '') ?>"
                           placeholder="email@exemplo.com">
                    <small class="help-text">* Telefone OU E-mail são obrigatórios</small>
                </div>

                <div class="form-group">
                    <label>Cidade <span class="required">*</span></label>
                    <input type="text" name="cidade" class="form-control" required
                           value="<?= htmlspecialchars($prospecto['cidade']) ?>">
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
                    <select name="fase" id="fase" class="form-control" required onchange="toggleCamposVisita()">
                        <option value="Prospecção" <?= $prospecto['fase'] === 'Prospecção' ? 'selected' : '' ?>>Prospecção</option>
                        <option value="Negociação" <?= $prospecto['fase'] === 'Negociação' ? 'selected' : '' ?>>Negociação</option>
                        <option value="Visita Semanal" <?= $prospecto['fase'] === 'Visita Semanal' ? 'selected' : '' ?>>📅 Visita Semanal</option>
                        <option value="Revisitar" <?= $prospecto['fase'] === 'Revisitar' ? 'selected' : '' ?>>🔄 Revisitar</option>
                        <option value="Fechados" <?= $prospecto['fase'] === 'Fechados' ? 'selected' : '' ?>>Fechados</option>
                        <option value="Perdidos" <?= $prospecto['fase'] === 'Perdidos' ? 'selected' : '' ?>>Perdidos</option>
                    </select>
                </div>

                <!-- Campos para Visita Semanal -->
                <div id="campos-visita-semanal" style="display: none; background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #9b59b6;">
                    <h4 style="color: #9b59b6; margin-top: 0;">📅 Dados da Visita Semanal</h4>
                    
                    <div class="form-group">
                        <label>Data da Primeira Visita</label>
                        <input type="date" name="data_primeira_visita" id="data_primeira_visita" class="form-control"
                               value="<?= $prospecto['data_primeira_visita'] ?? '' ?>"
                               min="<?= date('Y-m-d') ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Periodicidade</label>
                        <select name="periodicidade" id="periodicidade" class="form-control">
                            <option value="">Selecione...</option>
                            <option value="semanal" <?= ($prospecto['periodicidade'] ?? '') === 'semanal' ? 'selected' : '' ?>>Semanal (7 dias)</option>
                            <option value="quinzenal" <?= ($prospecto['periodicidade'] ?? '') === 'quinzenal' ? 'selected' : '' ?>>Quinzenal (15 dias)</option>
                            <option value="mensal" <?= ($prospecto['periodicidade'] ?? '') === 'mensal' ? 'selected' : '' ?>>Mensal (30 dias)</option>
                        </select>
                    </div>
                </div>

                <!-- Campos para Revisitar -->
                <div id="campos-revisitar" style="display: none; background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #e67e22;">
                    <h4 style="color: #e67e22; margin-top: 0;">🔄 Dados da Revisita</h4>
                    
                    <div class="form-group">
                        <label>Data da Revisita</label>
                        <input type="date" name="data_revisita" id="data_revisita" class="form-control"
                               value="<?= $prospecto['data_revisita'] ?? '' ?>"
                               min="<?= date('Y-m-d') ?>">
                    </div>
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

                <div class="form-group full-width">
                    <label>Observações</label>
                    <textarea name="observacoes" class="form-control"><?= htmlspecialchars($prospecto['observacoes'] ?? '') ?></textarea>
                </div>
            </div>

            <!-- Campos Específicos para PJ -->
            <div class="campos-pj" id="campos-pj">
                <div class="campos-pj-header">
                    🏢 Informações da Empresa
                </div>
                <div class="form-grid">
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

                    <div class="form-group">
                        <label>Segmento de Atuação</label>
                        <input type="text" name="segmento_atuacao" class="form-control"
                               value="<?= htmlspecialchars($prospecto['segmento_atuacao'] ?? '') ?>"
                               placeholder="Ex: Tecnologia, Comércio, etc">
                    </div>
                </div>
            </div>

            <div class="btn-group">
                <a href="visualizar_tax.php?id=<?= $id ?>" class="btn btn-secondary">
                    Cancelar
                </a>
                <button type="submit" class="btn btn-primary">
                    💾 Salvar Alterações
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// Mostrar/ocultar campos PJ
document.addEventListener('DOMContentLoaded', function() {
    const tipoCliente = document.getElementById('tipo_cliente');
    const camposPJ = document.getElementById('campos-pj');
    
    function toggleCamposPJ() {
        if (tipoCliente.value === 'PJ') {
            camposPJ.classList.add('active');
        } else {
            camposPJ.classList.remove('active');
        }
    }
    
    toggleCamposPJ(); // Inicializar
    tipoCliente.addEventListener('change', toggleCamposPJ);
    
    // Máscara de dinheiro
    document.querySelectorAll('.money-input').forEach(input => {
        input.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            value = (value / 100).toFixed(2);
            value = value.replace('.', ',');
            value = value.replace(/(\d)(?=(\d{3})+\,)/g, '$1.');
            e.target.value = value;
        });
    });
});

// Mostrar/ocultar campos de Visita Semanal e Revisitar
function toggleCamposVisita() {
    const fase = document.getElementById('fase').value;
    const camposVisita = document.getElementById('campos-visita-semanal');
    const camposRevisita = document.getElementById('campos-revisitar');
    
    if (fase === 'Visita Semanal') {
        camposVisita.style.display = 'block';
        camposRevisita.style.display = 'none';
    } else if (fase === 'Revisitar') {
        camposVisita.style.display = 'none';
        camposRevisita.style.display = 'block';
    } else {
        camposVisita.style.display = 'none';
        camposRevisita.style.display = 'none';
    }
}

// Inicializar ao carregar a página
document.addEventListener('DOMContentLoaded', function() {
    toggleCamposVisita();
});
</script>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Editar Prospecto - TAX', $conteudo, 'prospeccao');
?>