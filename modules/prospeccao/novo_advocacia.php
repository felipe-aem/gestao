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
$usuario_id = $usuario_logado['usuario_id'] ?? $usuario_logado['user_id'] ?? $usuario_logado['id'];

// MÓDULO FIXO
$modulo_codigo = 'ADVOCACIA';

if (!$usuario_id) {
    die("Erro: Usuário não identificado. Faça login novamente.");
}

// Verificar permissão
$usuarios_especiais = [28];
$pode_criar = in_array($nivel_acesso_logado, ['Admin', 'Socio', 'Diretor', 'Gestor', 'Advogado']) 
                     || in_array($usuario_id, $usuarios_especiais);
if (!$pode_criar) {
    header('Location: advocacia.php');
    exit;
}

// Buscar núcleos
try {
    $pdo = getConnection();
    $sql_nucleos = "SELECT id, nome FROM nucleos WHERE ativo = 1 ORDER BY nome ASC";
    $stmt_nucleos = $pdo->query($sql_nucleos);
    $nucleos = $stmt_nucleos->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $nucleos = [];
}

// Buscar usuários
try {
    $pdo = getConnection();
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
        $telefone = trim($_POST['telefone'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $cidade = trim($_POST['cidade']);
        $responsavel_id = $_POST['responsavel_id'];
        $meio = $_POST['meio'];
        $valor_proposta = !empty($_POST['valor_proposta']) ? str_replace(['.', ','], ['', '.'], $_POST['valor_proposta']) : null;
        $percentual_exito = !empty($_POST['percentual_exito']) ? floatval($_POST['percentual_exito']) : null;
        $estimativa_ganho = !empty($_POST['estimativa_ganho']) ? str_replace(['.', ','], ['', '.'], $_POST['estimativa_ganho']) : null;
        $indicacao = $_POST['indicacao'] ?? null;
        $observacoes = trim($_POST['observacoes'] ?? '');
        $eh_recontratacao = isset($_POST['eh_recontratacao']) ? 1 : 0;
        $em_analise = isset($_POST['em_analise']) ? 1 : 0;
        
        // Validação: telefone OU e-mail obrigatórios
        if (empty($telefone) && empty($email)) {
            throw new Exception('Informe pelo menos Telefone OU E-mail!');
        }
        
        // Campos PJ
        $cpf_cnpj = trim($_POST['cpf_cnpj'] ?? '');
        $responsavel_contato = $tipo_cliente === 'PJ' ? trim($_POST['responsavel_contato'] ?? '') : null;
        $cargo_responsavel = $tipo_cliente === 'PJ' ? trim($_POST['cargo_responsavel'] ?? '') : null;
        $proprietario_principal = $tipo_cliente === 'PJ' ? trim($_POST['proprietario_principal'] ?? '') : null;
        $segmento_atuacao = $tipo_cliente === 'PJ' ? trim($_POST['segmento_atuacao'] ?? '') : null;
        
        // Campos de agendamento
        $agendar_atendimento = isset($_POST['agendar_atendimento']) ? 1 : 0;
        $data_atendimento_data = $_POST['data_atendimento_data'] ?? null;
        $data_atendimento_hora = $_POST['data_atendimento_hora'] ?? null;
        $responsaveis_atendimento = $_POST['responsaveis_atendimento'] ?? [];
        
        // Combinar data e hora
        $data_atendimento = null;
        if ($agendar_atendimento && $data_atendimento_data && $data_atendimento_hora) {
            $data_atendimento = $data_atendimento_data . ' ' . $data_atendimento_hora . ':00';
        }
        
        // Fase padrão
        $fase = 'Prospecção';
        
        $pdo = getConnection();
        $pdo->beginTransaction();
        
        // Inserir prospecto
        $sql = "INSERT INTO prospeccoes (
            nome, telefone, email, tipo_cliente, cpf_cnpj,
            responsavel_contato, cargo_responsavel, proprietario_principal,
            segmento_atuacao, cidade, nucleo_id, responsavel_id,
            meio, fase, valor_proposta, percentual_exito, estimativa_ganho, indicacao, observacoes,
            criado_por, modulo_codigo,
            agendar_atendimento, data_atendimento, eh_recontratacao, em_analise
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        // Pegar primeiro núcleo para compatibilidade
        $nucleo_id = null;
        if (!empty($_POST['nucleos_percentuais'])) {
            $nucleo_id = array_key_first($_POST['nucleos_percentuais']);
        }
        
        $params = [
            $nome, $telefone, $email, $tipo_cliente, $cpf_cnpj,
            $responsavel_contato, $cargo_responsavel, $proprietario_principal,
            $segmento_atuacao, $cidade, $nucleo_id, $responsavel_id,
            $meio, $fase, $valor_proposta, $percentual_exito, $estimativa_ganho, $indicacao, $observacoes,
            $usuario_id, $modulo_codigo,
            $agendar_atendimento, $data_atendimento, $eh_recontratacao, $em_analise
        ];
        
        // ===== USAR PDO DIRETO COM DEBUG =====
        try {
            // Debug: ver SQL e params
            error_log("========== INSERT PROSPECTO ==========");
            error_log("SQL: " . $sql);
            error_log("Params count: " . count($params));
            error_log("Params: " . print_r($params, true));
            
            $stmt = $pdo->prepare($sql);
            
            if (!$stmt) {
                $errorInfo = $pdo->errorInfo();
                throw new Exception('Erro no prepare: ' . $errorInfo[2]);
            }
            
            $result = $stmt->execute($params);
            
            if (!$result) {
                $errorInfo = $stmt->errorInfo();
                throw new Exception('Erro no execute: ' . $errorInfo[2]);
            }
            
            $prospecto_id = $pdo->lastInsertId();
            error_log("✅ LastInsertId: " . $prospecto_id);
            error_log("✅ Row Count: " . $stmt->rowCount());
            
            if (!$prospecto_id || $prospecto_id <= 0) {
                // Ver erro completo
                $errorInfo = $pdo->errorInfo();
                $stmtErrorInfo = $stmt->errorInfo();
                
                $erroCompleto = "LastInsertId retornou zero!\n";
                $erroCompleto .= "PDO Error: " . print_r($errorInfo, true) . "\n";
                $erroCompleto .= "Stmt Error: " . print_r($stmtErrorInfo, true);
                
                error_log("❌ " . $erroCompleto);
                throw new Exception($erroCompleto);
            }
            
        } catch (PDOException $e) {
            $erro = 'PDO Exception: ' . $e->getMessage() . ' | Code: ' . $e->getCode();
            error_log("❌ " . $erro);
            throw new Exception($erro);
        }
        // =========================================
        
        if (!$result) {
            $errorInfo = $stmt->errorInfo();
            throw new Exception('Erro ao inserir prospecto: ' . $errorInfo[2]);
        }
        
        $prospecto_id = $pdo->lastInsertId();
        
        if (!$prospecto_id || $prospecto_id <= 0) {
            throw new Exception('Erro ao obter ID do prospecto!');
        }
        // ===========================
        
        // Inserir núcleos com percentuais
        if (!empty($_POST['nucleos_percentuais'])) {
            // Deletar núcleos antigos (evita duplicação)
            $sql_delete = "DELETE FROM prospeccoes_nucleos WHERE prospeccao_id = ?";
            $stmt_delete = $pdo->prepare($sql_delete);
            $stmt_delete->execute([$prospecto_id]);
            
            foreach ($_POST['nucleos_percentuais'] as $nucleo_id_loop => $percentual) {
                // Converter vírgula para ponto
                $percentual = str_replace(',', '.', $percentual);
                $percentual_float = floatval($percentual);
                
                if ($percentual_float > 0) {
                    $sql_nucleo = "INSERT INTO prospeccoes_nucleos (prospeccao_id, nucleo_id, percentual) 
                                   VALUES (?, ?, ?)";
                    $stmt_nucleo = $pdo->prepare($sql_nucleo);
                    $stmt_nucleo->execute([$prospecto_id, $nucleo_id_loop, $percentual_float]);
                }
            }
        }

        // Criar eventos na agenda para cada responsável
        error_log("========== CRIAR AGENDA ==========");
        error_log("Agendar: " . $agendar_atendimento);
        error_log("Data: " . $data_atendimento);
        error_log("Responsáveis: " . print_r($responsaveis_atendimento, true));
        
        if ($agendar_atendimento && $data_atendimento && !empty($responsaveis_atendimento)) {
            $responsaveis_atendimento = array_unique($responsaveis_atendimento);
    
            error_log("Responsáveis após array_unique: " . print_r($responsaveis_atendimento, true));
            // ==========================
            
            foreach ($responsaveis_atendimento as $resp_id) {
                error_log("Criando agenda para responsável: " . $resp_id);
                $sql_agenda = "INSERT INTO agenda (
                    titulo, descricao, data_inicio, data_fim,
                    usuario_id, tipo, status, prioridade, cor,
                    criado_por, observacoes
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                
                $titulo = "Atendimento: " . $nome;
                $descricao = "Atendimento de prospecção\n" .
                             "Cliente: " . $nome . "\n" .
                             "Telefone: " . $telefone . "\n" .
                             "Cidade: " . $cidade;
                
                $data_fim = date('Y-m-d H:i:s', strtotime($data_atendimento . ' +1 hour'));
                
                $params_agenda = [
                    $titulo,
                    $descricao,
                    $data_atendimento,
                    $data_fim,
                    $resp_id,
                    'Atendimento',
                    'Agendado',
                    'Normal',
                    '#667eea',
                    $usuario_id,
                    "Prospecto ID: {$prospecto_id}"
                ];
                
                $stmt_agenda = $pdo->prepare($sql_agenda);
                $stmt_agenda->execute($params_agenda);
                $agenda_id = $pdo->lastInsertId();
                
                $sql_resp = "INSERT INTO prospeccoes_atendimento_responsaveis 
                             (prospeccao_id, usuario_id, agenda_id) 
                             VALUES (?, ?, ?)";
                $stmt_resp = $pdo->prepare($sql_resp);
                $stmt_resp->execute([$prospecto_id, $resp_id, $agenda_id]);
            
                try {
                    $stmt_agenda = $pdo->prepare($sql_agenda);
                    $result_agenda = $stmt_agenda->execute($params_agenda);
                    
                    if (!$result_agenda) {
                        $errorInfo = $stmt_agenda->errorInfo();
                        error_log("❌ Erro agenda: " . $errorInfo[2]);
                    } else {
                        $agenda_id = $pdo->lastInsertId();
                        error_log("✅ Agenda ID: " . $agenda_id);
                    }
                } catch (Exception $e) {
                    error_log("❌ Exception: " . $e->getMessage());
                }
            }
        } else {
            error_log("❌ NÃO entrou no IF");
        }
        
        // Registrar atendimento no histórico (se agendado)
        if ($agendar_atendimento && $data_atendimento && !empty($responsaveis_atendimento)) {
            // Formatar data para o histórico
            $data_formatada = date('d/m/Y H:i', strtotime($data_atendimento));
            
            // Montar lista de responsáveis
            $nomes_responsaveis = [];
            foreach ($responsaveis_atendimento as $resp_id) {
                $sql_nome = "SELECT nome FROM usuarios WHERE id = ?";
                $stmt_nome = $pdo->prepare($sql_nome);
                $stmt_nome->execute([$resp_id]);
                $nome = $stmt_nome->fetchColumn();
                if ($nome) {
                    $nomes_responsaveis[] = $nome;
                }
            }
            
            $texto_responsaveis = implode(', ', $nomes_responsaveis);
            
            // Registrar no histórico
            $sql_hist_atend = "INSERT INTO prospeccoes_historico (
                                prospeccao_id, fase_anterior, fase_nova, 
                                observacao, usuario_id
                             ) VALUES (?, NULL, 'Atendimento Agendado', ?, ?)";
            
            $observacao_atend = "📅 Atendimento agendado para {$data_formatada}\n" .
                                "👥 Responsáveis: {$texto_responsaveis}\n" .
                                "📝 Evento criado automaticamente na agenda";
            
            $stmt_hist_atend = $pdo->prepare($sql_hist_atend);
            $stmt_hist_atend->execute([$prospecto_id, $observacao_atend, $usuario_id]);
            
            error_log("✅ Atendimento registrado no histórico para prospecto ID: " . $prospecto_id);
        }

        
        // Registrar no histórico
        $sql_hist = "INSERT INTO prospeccoes_historico (
                        prospeccao_id, fase_anterior, fase_nova, 
                        valor_informado, observacao, usuario_id
                     ) VALUES (?, NULL, 'Prospecção', ?, 'Prospecto criado', ?)";
        
        $stmt_hist = $pdo->prepare($sql_hist);
        $stmt_hist->execute([$prospecto_id, $valor_proposta, $usuario_id]);
        
        $pdo->commit();
        
        header('Location: advocacia.php?sucesso=1');
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
    /* Reset CSS */
    * {
        margin: 0;
        padding: 0;
        box-sizing: border-box;
    }

    .form-container {
        max-width: 900px;
        margin: 20px auto;
        background: white;
        border-radius: 12px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.1);
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
        font-size: 13px;
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
        font-style: italic;
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

    /* CHECKBOX RECONTRATAÇÃO (CORRIGIDO) */
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
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .recontratacao-content small {
        font-size: 12px;
        color: #7f8c8d;
        font-style: italic;
        line-height: 1.3;
    }

    /* NÚCLEOS PARTICIPANTES (CORRIGIDO COM SCROLL) */
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

    .nucleos-content-always::-webkit-scrollbar-thumb:hover {
        background: #2980b9;
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

    /* AGENDAR ATENDIMENTO (CORRIGIDO) */
    .agendar-container {
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

    .agendar-container:hover {
        border-color: #667eea;
        background: #f8f9ff;
    }

    .agendar-container input[type="checkbox"] {
        width: 20px;
        height: 20px;
        cursor: pointer;
        margin: 0;
        flex-shrink: 0;
        margin-top: 2px;
        accent-color: #667eea;
    }

    .agendar-icon {
        font-size: 24px;
        flex-shrink: 0;
    }

    .agendar-content {
        flex: 1;
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .agendar-content strong {
        font-size: 14px;
        font-weight: 600;
        color: #2c3e50;
        cursor: pointer;
        line-height: 1.4;
    }

    .agendar-content small {
        font-size: 12px;
        color: #7f8c8d;
        font-style: italic;
        line-height: 1.3;
    }

    #campos_atendimento {
        background: #f8f9fa;
        padding: 20px;
        border-radius: 10px;
        margin-top: 15px;
        border-left: 4px solid #667eea;
        display: none;
        animation: slideDown 0.3s ease;
    }

    @keyframes slideDown {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .custom-multiselect {
        position: relative;
        width: 100%;
    }

    .multiselect-button {
        width: 100%;
        padding: 12px 15px;
        border: 2px solid #e0e0e0;
        border-radius: 8px;
        background: white;
        cursor: pointer;
        display: flex;
        justify-content: space-between;
        align-items: center;
        font-size: 14px;
        transition: all 0.3s ease;
        min-height: 42px;
    }

    .multiselect-button:hover {
        border-color: #667eea;
    }

    .multiselect-button.active {
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }

    .multiselect-dropdown {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        margin-top: 4px;
        background: white;
        border: 2px solid #667eea;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        max-height: 250px;
        overflow-y: auto;
        z-index: 1000;
        display: none;
    }

    .multiselect-dropdown.active {
        display: block;
    }

    .multiselect-option {
        padding: 10px 15px;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 10px;
        transition: background 0.2s;
    }

    .multiselect-option:hover {
        background: #f5f7ff;
    }

    .multiselect-option input[type="checkbox"] {
        cursor: pointer;
        width: 18px;
        height: 18px;
    }

    .multiselect-option label {
        cursor: pointer;
        margin: 0;
        flex: 1;
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

        .nucleo-item {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }

        .nucleo-percentual {
            width: 100%;
            justify-content: flex-end;
        }

        .nucleos-content-always {
            max-height: 280px;
        }
    }
</style>

<div class="form-container">
    <div class="form-header">
        <h1><i class="fas fa-balance-scale"></i> Novo Prospecto - Advocacia</h1>
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
            
            <div class="form-group full-width">
                <div class="recontratacao-container" onclick="toggleCheckboxContainer(this)">
                    <input type="checkbox" 
                           id="eh_recontratacao" 
                           name="eh_recontratacao" 
                           value="1"
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
                            <?php foreach ($nucleos as $nucleo): ?>
                                <div class="nucleo-item" id="item-nucleo-<?= $nucleo['id'] ?>">
                                    <div class="nucleo-checkbox">
                                        <input type="checkbox" 
                                               class="nucleo-check" 
                                               id="nucleo-<?= $nucleo['id'] ?>" 
                                               value="<?= $nucleo['id'] ?>"
                                               onchange="togglePercentualInput(<?= $nucleo['id'] ?>)">
                                        <label for="nucleo-<?= $nucleo['id'] ?>">
                                            <strong><?= htmlspecialchars($nucleo['nome']) ?></strong>
                                        </label>
                                    </div>
                                    <div class="nucleo-percentual" id="percentual-container-<?= $nucleo['id'] ?>" style="display: none;">
                                        <input type="text" 
                                               name="nucleos_percentuais[<?= $nucleo['id'] ?>]"
                                               inputmode="decimal" 
                                               id="percentual-<?= $nucleo['id'] ?>"
                                               class="percentual-input" 
                                               min="0" 
                                               max="100" 
                                               step="0.1"
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
                                <?= $usuario['id'] == $usuario_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($usuario['nome']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group full-width">
                <div class="agendar-container">
                    <label for="agendar_atendimento" style="display: flex; align-items: flex-start; gap: 12px; cursor: pointer; margin: 0; width: 100%;">
                        <input type="checkbox" 
                               id="agendar_atendimento" 
                               name="agendar_atendimento" 
                               value="1">
                        <span class="agendar-icon">📅</span>
                        <div class="agendar-content">
                            <strong>Agendar Atendimento Futuro</strong>
                            <small>Cria evento automático na agenda dos responsáveis</small>
                        </div>
                    </label>
                </div>
                
                <div id="campos_atendimento">
                    <div class="form-grid" style="margin-top: 15px;">
                        <div class="form-group">
                            <label for="data_atendimento_data">Data do Atendimento *</label>
                            <input type="date" 
                                   id="data_atendimento_data" 
                                   name="data_atendimento_data" 
                                   class="form-control"
                                   min="<?= date('Y-m-d') ?>">
                        </div>
                        
                        <div class="form-group">
                            <label for="data_atendimento_hora">Hora do Atendimento *</label>
                            <input type="time" 
                                   id="data_atendimento_hora" 
                                   name="data_atendimento_hora" 
                                   class="form-control"
                                   value="09:00">
                        </div>
                    </div>
                    
                    <div class="form-group full-width" style="margin-top: 15px;">
                        <label>👥 Responsáveis pelo Atendimento *</label>
                        <small style="color: #7f8c8d; display: block; margin-bottom: 8px;">
                            Selecione os profissionais que participarão do atendimento
                        </small>
                        <div class="custom-multiselect">
                            <div class="multiselect-button" onclick="toggleMultiselect('responsaveis_atendimento')">
                                <span id="responsaveis_atendimento-text">Selecione os responsáveis</span>
                                <span>▼</span>
                            </div>
                            <div class="multiselect-dropdown" id="responsaveis_atendimento-dropdown">
                                <div class="multiselect-option">
                                    <input type="checkbox" id="responsaveis_atendimento-todos" onchange="selectAllMulti('responsaveis_atendimento')">
                                    <label for="responsaveis_atendimento-todos"><strong>Todos</strong></label>
                                </div>
                                <?php foreach ($usuarios as $usuario): ?>
                                    <div class="multiselect-option">
                                        <input type="checkbox" 
                                               name="responsaveis_atendimento[]" 
                                               value="<?= $usuario['id'] ?>" 
                                               id="resp-atend-<?= $usuario['id'] ?>"
                                               onchange="updateMultiText('responsaveis_atendimento')">
                                        <label for="resp-atend-<?= $usuario['id'] ?>"><?= htmlspecialchars($usuario['nome']) ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
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
            
            <div class="form-group">
                <label>Percentual de Honorários de Êxito (%)</label>
                <input type="number" name="percentual_exito" class="form-control" 
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
                    placeholder="Ex: João Silva, Maria Cliente, Site, etc."
                >
                <small class="help-text">
                    👥 Quem indicou este prospecto?
                </small>
            </div>
            
            <!-- Campo Em análise -->
            <div class="form-group">
                <label style="margin-bottom: 12px; display: block;">&nbsp;</label>
                <div class="recontratacao-container" onclick="toggleCheckboxContainer(this)" style="background: rgba(255, 193, 7, 0.1); border: 2px solid rgba(255, 193, 7, 0.3);">
                    <input type="checkbox" 
                           id="em_analise" 
                           name="em_analise" 
                           value="1"
                           onclick="event.stopPropagation()">
                    <div class="recontratacao-content">
                        <strong style="color: #856404;">⏳ Em Análise</strong>
                        <small style="color: #856404;">Prospecto sendo analisado pela equipe</small>
                    </div>
                </div>
            </div>
            
            <div class="form-group full-width">
                <label>Observações</label>
                <textarea name="observacoes" class="form-control" rows="4"></textarea>
            </div>
        </div>
        
        <div class="campos-pj" id="campos-pj">
            <div class="campos-pj-header">
                <i class="fas fa-building"></i>
                Informações da Empresa
            </div>
            <div class="campos-pj-grid">
                <div class="form-group">
                    <label>CPF/CNPJ</label>
                    <input type="text" name="cpf_cnpj" class="form-control"
                           placeholder="000.000.000-00">
                </div>

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

                <div class="form-group full-width">
                    <label>Segmento de Atuação</label>
                    <input type="text" name="segmento_atuacao" class="form-control"
                           placeholder="Ex: Comércio, Indústria, Serviços">
                </div>
            </div>
        </div>

        <div class="btn-group">
            <a href="advocacia.php" class="btn btn-secondary">
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
$(document).ready(function() {
    $('[name="telefone"]').mask('(00) 00000-0000');
    $('.money-input').mask('#.##0,00', { reverse: true });
});
</script>

<script>
function toggleCheckboxContainer(container) {
    const checkbox = container.querySelector('input[type="checkbox"]');
    if (checkbox && !checkbox.disabled) {
        checkbox.checked = !checkbox.checked;
        checkbox.dispatchEvent(new Event('change'));
    }
}

function selecionarTipo(tipo) {
    document.getElementById('tipo_cliente').value = tipo;
    
    document.querySelectorAll('.tipo-option').forEach(opt => {
        opt.classList.remove('active');
    });
    document.querySelector(`[data-tipo="${tipo}"]`).classList.add('active');
    
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

// Formatar percentual permitindo vírgula
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

// Converter vírgula para número
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
        const input = document.getElementById('percentual-' + nucleoId);
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

function toggleCamposAtendimento(checkbox) {
    const campos = document.getElementById('campos_atendimento');
    const data = document.getElementById('data_atendimento_data');
    const hora = document.getElementById('data_atendimento_hora');
    
    if (checkbox.checked) {
        campos.style.display = 'block';
        data.required = true;
        hora.required = true;
        
        setTimeout(() => {
            campos.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }, 100);
    } else {
        campos.style.display = 'none';
        data.required = false;
        hora.required = false;
        
        document.querySelectorAll('input[name="responsaveis_atendimento[]"]').forEach(cb => {
            cb.checked = false;
        });
        updateMultiText('responsaveis_atendimento');
    }
}

function toggleMultiselect(id) {
    const dropdown = document.getElementById(id + '-dropdown');
    const button = dropdown.previousElementSibling;
    
    document.querySelectorAll('.multiselect-dropdown').forEach(d => {
        if (d.id !== dropdown.id) {
            d.classList.remove('active');
        }
    });
    
    document.querySelectorAll('.multiselect-button').forEach(b => {
        if (b !== button) {
            b.classList.remove('active');
        }
    });
    
    dropdown.classList.toggle('active');
    button.classList.toggle('active');
}

function selectAllMulti(id) {
    const checkbox = document.getElementById(id + '-todos');
    const checkboxes = document.querySelectorAll(`input[name="${id}[]"]`);
    
    checkboxes.forEach(cb => {
        cb.checked = checkbox.checked;
    });
    
    updateMultiText(id);
}

function updateMultiText(id) {
    const checkboxes = Array.from(document.querySelectorAll(`input[name="${id}[]"]:checked`));
    const text = document.getElementById(id + '-text');
    const allCheckbox = document.getElementById(id + '-todos');
    const totalCheckboxes = document.querySelectorAll(`input[name="${id}[]"]`).length;
    
    if (checkboxes.length === 0) {
        text.textContent = 'Selecione os responsáveis';
        text.style.color = '#95a5a6';
        allCheckbox.checked = false;
    } else if (checkboxes.length === totalCheckboxes) {
        text.textContent = '✓ Todos selecionados';
        text.style.color = '#27ae60';
        allCheckbox.checked = true;
    } else if (checkboxes.length === 1) {
        text.textContent = '✓ ' + checkboxes[0].nextElementSibling.textContent;
        text.style.color = '#667eea';
        allCheckbox.checked = false;
    } else {
        text.textContent = `✓ ${checkboxes.length} responsáveis selecionados`;
        text.style.color = '#667eea';
        allCheckbox.checked = false;
    }
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('.custom-multiselect')) {
        document.querySelectorAll('.multiselect-dropdown').forEach(d => {
            d.classList.remove('active');
        });
        document.querySelectorAll('.multiselect-button').forEach(b => {
            b.classList.remove('active');
        });
    }
});

$(document).ready(function() {
    // Função para carregar jQuery Mask dinamicamente
    function carregarjQueryMask(callback) {
        // Verificar se já está carregado
        if (typeof $.fn.mask === 'function') {
            console.log('✅ jQuery Mask já está carregado');
            callback();
            return;
        }
        
        console.log('📥 Carregando jQuery Mask...');
        
        // Carregar script dinamicamente
        var script = document.createElement('script');
        script.src = 'https://cdnjs.cloudflare.com/ajax/libs/jquery.mask/1.14.16/jquery.mask.min.js';
        script.onload = function() {
            console.log('✅ jQuery Mask carregado com sucesso!');
            callback();
        };
        script.onerror = function() {
            console.error('❌ Erro ao carregar jQuery Mask');
        };
        document.head.appendChild(script);
    }
    
    // Carregar e aplicar máscaras
    carregarjQueryMask(function() {
        console.log('🎭 Aplicando máscaras...');
        
        // Máscara de telefone
        $('[name="telefone"]').mask('(00) 00000-0000');
        console.log('✅ Máscara de telefone aplicada');
        
        // Máscara de dinheiro
        $('.money-input').mask('#.##0,00', {
            reverse: true,
            placeholder: '0,00'
        });
        console.log('✅ Máscaras de dinheiro aplicadas');
    });
    
    // Validação do formulário (não depende de máscaras)
    $('#formProspecto').on('submit', function(e) {
        // Prevenir múltiplos submits
        const submitBtn = $(this).find('button[type="submit"]');
        if (submitBtn.prop('disabled')) {
            e.preventDefault();
            return false;
        }
        submitBtn.prop('disabled', true);
        submitBtn.html('<i class="fas fa-spinner fa-spin"></i> Cadastrando...');


        // Converter vírgulas para pontos antes de validar/enviar
        document.querySelectorAll('.percentual-input').forEach(function(input) {
            if (input.value) {
                input.value = input.value.replace(',', '.');
            }
        });
        
        // ================================
        const telefone = $('#telefone').val().trim();
        const email = $('#email').val().trim();
        
        if (!telefone && !email) {
            e.preventDefault();
            alert('❌ Informe pelo menos Telefone OU E-mail!');
            return false;
        }
        
        // Validação dos núcleos
        const selecionados = document.querySelectorAll('.nucleo-check:checked');
        
        if (selecionados.length === 0) {
            e.preventDefault();
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
            const valor = valorPercentual('percentual-' + nucleoId);  // ✅ USAR A FUNÇÃO
            total += valor;
        });
        
        if (Math.abs(total - 100) > 0.1) {
            e.preventDefault();
            alert('⚠️ O total de percentuais deve ser 100%!\n\nAtual: ' + total.toFixed(1) + '%');
            document.querySelector('.nucleos-box-always').scrollIntoView({ 
                behavior: 'smooth', 
                block: 'center' 
            });
            return false;
        }
        
        return true;
    });
});

// ============================================
// AGENDAMENTO - CÓDIGO ÚNICO E LIMPO
// ============================================

document.addEventListener('DOMContentLoaded', function() {
    // Elementos
    const checkboxAgendamento = document.getElementById('agendar_atendimento');
    const camposAgendamento = document.getElementById('campos_atendimento');
    const dataInput = document.getElementById('data_atendimento_data');
    const horaInput = document.getElementById('data_atendimento_hora');
    
    // Verificar se existem
    if (!checkboxAgendamento || !camposAgendamento) {
        console.error('Elementos de agendamento não encontrados');
        return;
    }
    
    // Ocultar no início
    camposAgendamento.style.display = 'none';
    
    // Listener único
    checkboxAgendamento.addEventListener('change', function() {
        if (this.checked) {
            // Mostrar campos
            camposAgendamento.style.display = 'block';
            if (dataInput) dataInput.required = true;
            if (horaInput) horaInput.required = true;
            
            // Scroll suave
            setTimeout(() => {
                camposAgendamento.scrollIntoView({ 
                    behavior: 'smooth', 
                    block: 'nearest' 
                });
            }, 100);
        } else {
            // Ocultar campos
            camposAgendamento.style.display = 'none';
            if (dataInput) {
                dataInput.required = false;
                dataInput.value = '';
            }
            if (horaInput) {
                horaInput.required = false;
                horaInput.value = '09:00';
            }
            
            // Limpar responsáveis
            document.querySelectorAll('input[name="responsaveis_atendimento[]"]').forEach(cb => {
                cb.checked = false;
            });
            
            // Resetar multiselect
            const textoResp = document.getElementById('responsaveis_atendimento-text');
            if (textoResp) {
                textoResp.textContent = 'Selecione os responsáveis';
                textoResp.style.color = '#95a5a6';
            }
        }
    });
});
</script>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Novo Prospecto - Advocacia', $conteudo, 'prospeccao');
?>