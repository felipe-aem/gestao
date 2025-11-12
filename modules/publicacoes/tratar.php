<?php
/**
 * TRATAMENTO DE PUBLICAÇÕES - MODAL COM FORMULÁRIOS
 * Versão corrigida com busca de processos, etiquetas e CSS completo
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../includes/auth.php';
Auth::protect();
require_once '../../config/database.php';
require_once '../../includes/ProcessoHistoricoHelper.php';

$usuario_logado = Auth::user();
$usuario_id = $usuario_logado['usuario_id'];
$session_token = $_SESSION['token'] ?? ''; // Token para popups
$publicacao_id = $_GET['id'] ?? null;

if (!$publicacao_id) {
    echo "<script>alert('ID da publicação não fornecido'); window.close();</script>";
    exit;
}

// ========================================
// PROCESSAMENTO (POST via AJAX)
// ========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $tipo = $_POST['tipo'] ?? '';
    
    try {
        $pdo = getConnection();
        $pdo->beginTransaction();
        
        $item_id = null;
        $tipo_nome = '';
        
        switch ($tipo) {
            case 'tarefa':
                $tipo_nome = 'Tarefa';
                
                if (empty($_POST['titulo'])) throw new Exception('Título é obrigatório');
                if (empty($_POST['responsavel_id'])) throw new Exception('Responsável é obrigatório');
                if (empty($_POST['data_vencimento'])) throw new Exception('Data de vencimento é obrigatória');
                
                $processo_id = !empty($_POST['processo_id']) ? (int)$_POST['processo_id'] : null;
                
                $sql = "INSERT INTO tarefas (
                    titulo, descricao, processo_id, responsavel_id,
                    data_vencimento, prioridade, status, criado_por,
                    publicacao_id
                ) VALUES (?, ?, ?, ?, ?, ?, 'pendente', ?, ?)";
                
                executeQuery($sql, [
                    trim($_POST['titulo']),
                    trim($_POST['descricao'] ?? ''),
                    $processo_id,
                    (int)$_POST['responsavel_id'],
                    $_POST['data_vencimento'] . ' ' . ($_POST['hora_vencimento'] ?? '23:59') . ':00',
                    $_POST['prioridade'] ?? 'normal',
                    $usuario_id,
                    $publicacao_id
                ]);
                
                $item_id = $pdo->lastInsertId();
                
                if (!empty($_POST['etiquetas']) && is_array($_POST['etiquetas'])) {
                    $sql_etiq = "INSERT INTO tarefa_etiquetas (tarefa_id, etiqueta_id, criado_por) VALUES (?, ?, ?)";
                    foreach ($_POST['etiquetas'] as $etiqueta_id) {
                        executeQuery($sql_etiq, [$item_id, (int)$etiqueta_id, $usuario_id]);
                    }
                }
                
                if (!empty($_POST['envolvidos']) && is_array($_POST['envolvidos'])) {
                    $sql_env = "INSERT INTO tarefa_envolvidos (tarefa_id, usuario_id) VALUES (?, ?)";
                    foreach ($_POST['envolvidos'] as $env_id) {
                        if ($env_id != $usuario_id) {
                            executeQuery($sql_env, [$item_id, (int)$env_id]);
                        }
                    }
                }
                
                $sql_update = "UPDATE publicacoes SET 
                    status_tratamento = 'tratada',
                    tarefa_id = ?,
                    processo_id = ?,
                    tratada_por_usuario_id = ?,
                    data_tratamento = NOW()
                    WHERE id = ?";
                executeQuery($sql_update, [$item_id, $processo_id, $usuario_id, $publicacao_id]);
                
                break;
                
            case 'prazo':
                $tipo_nome = 'Prazo';
                
                if (empty($_POST['titulo'])) throw new Exception('Título é obrigatório');
                if (empty($_POST['responsavel_id'])) throw new Exception('Responsável é obrigatório');
                if (empty($_POST['data_vencimento'])) throw new Exception('Data de vencimento é obrigatória');
                if (empty($_POST['processo_id'])) throw new Exception('Prazo precisa estar vinculado a um processo!');
                
                $processo_id = (int)$_POST['processo_id'];
                
                $sql = "INSERT INTO prazos (
                    titulo, descricao, processo_id, responsavel_id,
                    data_vencimento, tipo_prazo, prioridade, status,
                    criado_por, publicacao_id, dias_alerta
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pendente', ?, ?, ?)";
                
                executeQuery($sql, [
                    trim($_POST['titulo']),
                    trim($_POST['descricao'] ?? ''),
                    $processo_id,
                    (int)$_POST['responsavel_id'],
                    $_POST['data_vencimento'] . ' ' . ($_POST['hora_vencimento'] ?? '23:59') . ':00',
                    $_POST['tipo_prazo'] ?? 'processual',
                    $_POST['prioridade'] ?? 'alta',
                    $usuario_id,
                    $publicacao_id,
                    (int)($_POST['dias_alerta'] ?? 3)
                ]);
                
                $item_id = $pdo->lastInsertId();
                
                if (!empty($_POST['etiquetas']) && is_array($_POST['etiquetas'])) {
                    $sql_etiq = "INSERT INTO prazo_etiquetas (prazo_id, etiqueta_id, criado_por) VALUES (?, ?, ?)";
                    foreach ($_POST['etiquetas'] as $etiqueta_id) {
                        executeQuery($sql_etiq, [$item_id, (int)$etiqueta_id, $usuario_id]);
                    }
                }
                
                if (!empty($_POST['envolvidos']) && is_array($_POST['envolvidos'])) {
                    $sql_env = "INSERT INTO prazo_envolvidos (prazo_id, usuario_id) VALUES (?, ?)";
                    foreach ($_POST['envolvidos'] as $env_id) {
                        if ($env_id != $usuario_id) {
                            executeQuery($sql_env, [$item_id, (int)$env_id]);
                        }
                    }
                }
                
                $sql_update = "UPDATE publicacoes SET 
                    status_tratamento = 'tratada',
                    prazo_id = ?,
                    processo_id = ?,
                    tratada_por_usuario_id = ?,
                    data_tratamento = NOW()
                    WHERE id = ?";
                executeQuery($sql_update, [$item_id, $processo_id, $usuario_id, $publicacao_id]);
                
                if ($processo_id) {
                    ProcessoHistoricoHelper::registrar(
                        $processo_id,
                        "Prazo Cadastrado",
                        "Prazo criado via publicação: " . trim($_POST['titulo']),
                        'prazo',
                        $item_id
                    );
                }
                
                break;
                
            case 'audiencia':
                $tipo_nome = 'Audiência';
                
                if (empty($_POST['titulo'])) throw new Exception('Título é obrigatório');
                if (empty($_POST['responsavel_id'])) throw new Exception('Responsável é obrigatório');
                if (empty($_POST['data_audiencia'])) throw new Exception('Data da audiência é obrigatória');
                
                $processo_id = !empty($_POST['processo_id']) ? (int)$_POST['processo_id'] : null;
                
                $sql = "INSERT INTO audiencias (
                    titulo, descricao, processo_id, responsavel_id,
                    data_inicio, local_evento, tipo, prioridade,
                    status, criado_por, publicacao_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'agendada', ?, ?)";
                
                $data_hora = $_POST['data_audiencia'] . ' ' . ($_POST['hora_audiencia'] ?? '10:00') . ':00';
                
                executeQuery($sql, [
                    trim($_POST['titulo']),
                    trim($_POST['descricao'] ?? ''),
                    $processo_id,
                    (int)$_POST['responsavel_id'],
                    $data_hora,
                    trim($_POST['local_audiencia'] ?? ''),
                    $_POST['tipo_audiencia'] ?? 'Audiência',
                    $_POST['prioridade'] ?? 'Normal',
                    $usuario_id,
                    $publicacao_id
                ]);
                
                $item_id = $pdo->lastInsertId();
                
                if (!empty($_POST['etiquetas']) && is_array($_POST['etiquetas'])) {
                    $sql_etiq = "INSERT INTO audiencia_etiquetas (audiencia_id, etiqueta_id, criado_por) VALUES (?, ?, ?)";
                    foreach ($_POST['etiquetas'] as $etiqueta_id) {
                        executeQuery($sql_etiq, [$item_id, (int)$etiqueta_id, $usuario_id]);
                    }
                }
                
                if (!empty($_POST['envolvidos']) && is_array($_POST['envolvidos'])) {
                    $sql_env = "INSERT INTO audiencia_envolvidos (audiencia_id, usuario_id) VALUES (?, ?)";
                    foreach ($_POST['envolvidos'] as $env_id) {
                        if ($env_id != $usuario_id) {
                            executeQuery($sql_env, [$item_id, (int)$env_id]);
                        }
                    }
                }
                
                $sql_update = "UPDATE publicacoes SET 
                    status_tratamento = 'tratada',
                    audiencia_id = ?,
                    processo_id = ?,
                    tratada_por_usuario_id = ?,
                    data_tratamento = NOW()
                    WHERE id = ?";
                executeQuery($sql_update, [$item_id, $processo_id, $usuario_id, $publicacao_id]);
                
                if ($processo_id) {
                    ProcessoHistoricoHelper::registrar(
                        $processo_id,
                        "Audiência Agendada",
                        "Audiência agendada via publicação: " . trim($_POST['titulo']),
                        'audiencia',
                        $item_id
                    );
                }
                
                break;
                
            default:
                throw new Exception('Tipo inválido');
        }
        
        $pdo->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "$tipo_nome criada com sucesso!",
            'item_id' => $item_id
        ]);
        
    } catch (Exception $e) {
        if (isset($pdo)) $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
    
    exit;
}

// ========================================
// BUSCAR DADOS PARA O FORMULÁRIO
// ========================================
$sql_pub = "SELECT p.*, 
            proc.id as processo_id_vinculado, 
            proc.numero_processo as processo_numero,
            proc.cliente_nome as processo_cliente
            FROM publicacoes p
            LEFT JOIN processos proc ON p.processo_id = proc.id
            WHERE p.id = ?";
$pub = executeQuery($sql_pub, [$publicacao_id])->fetch();

if (!$pub) {
    echo "<script>alert('Publicação não encontrada'); window.close();</script>";
    exit;
}

$sql_usuarios = "SELECT id, nome FROM usuarios WHERE ativo = 1 ORDER BY nome";
$usuarios = executeQuery($sql_usuarios)->fetchAll();

$sql_processos = "SELECT id, numero_processo, cliente_nome FROM processos WHERE ativo = 1 ORDER BY numero_processo";
$processos = executeQuery($sql_processos)->fetchAll();

$sql_etiquetas = "SELECT id, nome, cor, icone, tipo 
                  FROM etiquetas 
                  WHERE ativo = 1 
                  ORDER BY tipo, nome";
$etiquetas = executeQuery($sql_etiquetas)->fetchAll();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tratar Publicação</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f6fa;
            padding: 20px;
        }
        
        .escolha-container {
            max-width: 800px;
            margin: 0 auto 20px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.15);
            padding: 25px;
        }
        
        .escolha-header {
            text-align: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(0,0,0,0.05);
        }
        
        .escolha-header h2 {
            color: #1a1a1a;
            font-size: 22px;
            font-weight: 700;
        }
        
        .pub-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            border-left: 4px solid #667eea;
            font-size: 13px;
        }
        
        .pub-info strong {
            color: #667eea;
        }
        
        .tipo-buttons {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
        }
        
        .tipo-button {
            padding: 25px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 12px;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
            text-align: center;
        }
        
        .tipo-button:hover {
            border-color: #667eea;
            transform: translateY(-3px);
            box-shadow: 0 5px 20px rgba(102, 126, 234, 0.2);
        }
        
        .tipo-button i {
            font-size: 35px;
            margin-bottom: 10px;
            display: block;
        }
        
        .tipo-button .label {
            font-size: 15px;
            font-weight: 600;
            color: #333;
        }
        
        .tipo-button .desc {
            font-size: 11px;
            color: #999;
            margin-top: 5px;
        }
        
        .form-inline-container {
            max-width: 900px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.15);
            padding: 25px;
            display: none;
        }
        
        .form-inline-container.active {
            display: block;
        }
        
        .form-inline-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(0,0,0,0.05);
        }
        
        .form-inline-header h3 {
            color: #1a1a1a;
            font-size: 22px;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
        }
        
        .btn-close-form {
            background: transparent;
            border: none;
            color: #999;
            font-size: 24px;
            cursor: pointer;
            transition: all 0.3s;
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
        }
        
        .btn-close-form:hover {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px 20px;
            margin-bottom: 20px;
        }
        
        .form-group-full {
            grid-column: 1 / -1;
        }
        
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: #333;
            margin-bottom: 6px;
        }
        
        .form-group label .required {
            color: #dc3545;
            margin-left: 3px;
        }
        
        .form-control {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        .form-control:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        textarea.form-control {
            min-height: 80px;
            resize: vertical;
        }
        
        /* CSS para busca de processos */
        .processo-busca-container {
            position: relative;
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .processo-busca-input {
            flex: 1;
            padding: 8px 35px 8px 12px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 14px;
        }
        
        .processo-busca-icon {
            position: absolute;
            right: calc(130px + 12px); /* Ajusta para não sobrepor o botão */
            top: 10px;
            color: #999;
            pointer-events: none;
        }
        
        .btn-novo-processo {
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
        
        .btn-novo-processo:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.4);
        }
        
        .processo-resultados {
            position: absolute;
            top: 100%;
            left: 0;
            right: calc(130px + 10px); /* Ajusta para não sobrepor o botão */
            background: white;
            border: 1px solid #ddd;
            border-top: none;
            border-radius: 0 0 8px 8px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .processo-resultados.active {
            display: block;
        }
        
        .processo-item {
            padding: 10px 12px;
            cursor: pointer;
            transition: all 0.2s;
            font-size: 13px;
            border-bottom: 1px solid rgba(0,0,0,0.05);
        }
        
        .processo-item:hover {
            background: rgba(102, 126, 234, 0.1);
        }
        
        .processo-numero {
            font-weight: 600;
            color: #667eea;
        }
        
        .processo-cliente {
            color: #666;
            font-size: 12px;
        }
        
        .processo-selecionado {
            padding: 8px 12px;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1) 0%, rgba(118, 75, 162, 0.1) 100%);
            border-radius: 8px;
            font-size: 13px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 8px;
        }
        
        .btn-remover-processo {
            background: transparent;
            border: none;
            color: #dc3545;
            cursor: pointer;
            font-size: 16px;
            padding: 0;
            width: 24px;
            height: 24px;
        }
        
        /* CSS para envolvidos - CORRIGIDO */
        .usuarios-selector select {
            width: 100%;
            min-height: 100px;
            padding: 6px;
            border: 1px solid #ddd;
            border-radius: 8px;
            font-size: 13px;
        }
        
        .usuarios-selector select:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .usuarios-selector select option:checked {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .etiquetas-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .btn-criar-etiqueta {
            padding: 4px 10px;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 11px;
            font-weight: 600;
            cursor: pointer;
        }
        
        .etiquetas-container {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            min-height: 32px;
        }
        
        .etiqueta-checkbox {
            display: none;
        }
        
        .etiqueta-label {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 6px 12px;
            border-radius: 16px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            border: 2px solid transparent;
            opacity: 0.6;
            transition: all 0.3s;
        }
        
        .etiqueta-checkbox:checked + .etiqueta-label {
            opacity: 1;
            border-color: rgba(0,0,0,0.2);
            transform: translateY(-2px);
        }
        
        .modal-etiqueta {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 10000;
            align-items: center;
            justify-content: center;
        }
        
        .modal-etiqueta.active {
            display: flex;
        }
        
        .modal-content-etiqueta {
            background: white;
            border-radius: 15px;
            padding: 25px;
            width: 90%;
            max-width: 500px;
        }
        
        .modal-header-etiqueta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid rgba(0,0,0,0.05);
        }
        
        .cores-etiqueta {
            display: grid;
            grid-template-columns: repeat(8, 1fr);
            gap: 10px;
            margin: 15px 0;
        }
        
        .cor-option {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            cursor: pointer;
            border: 3px solid transparent;
            transition: all 0.2s;
        }
        
        .cor-option.selected {
            border-color: #1a1a1a;
            transform: scale(1.1);
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            padding-top: 15px;
            border-top: 2px solid rgba(0,0,0,0.05);
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
        }
        
        .hint {
            font-size: 11px;
            color: #666;
            margin-top: 5px;
            display: block;
        }
    </style>
</head>
<body>
    <!-- Tela de Escolha -->
    <div id="escolhaContainer" class="escolha-container">
        <div class="escolha-header">
            <h2>Escolha o tipo de tratamento</h2>
        </div>
        
        <div class="pub-info">
            <strong>📄 Publicação:</strong> 
            <?= htmlspecialchars(substr($pub['conteudo'], 0, 100)) ?>...
            <?php if ($pub['processo_id_vinculado']): ?>
            <br><strong>⚖️ Processo:</strong> <?= htmlspecialchars($pub['processo_numero']) ?>
            <?php if ($pub['processo_cliente']): ?>
            <br><strong>👤 Cliente:</strong> <?= htmlspecialchars($pub['processo_cliente']) ?>
            <?php endif; ?>
            <?php endif; ?>
        </div>
        
        <div class="tipo-buttons">
            <div class="tipo-button" onclick="mostrarFormulario('tarefa')">
                <i class="fas fa-tasks" style="color: #ffc107;"></i>
                <div class="label">Tarefa</div>
                <div class="desc">Criar uma tarefa geral</div>
            </div>
            
            <div class="tipo-button" onclick="mostrarFormulario('prazo')">
                <i class="fas fa-clock" style="color: #dc3545;"></i>
                <div class="label">Prazo</div>
                <div class="desc">Criar um prazo processual</div>
            </div>
            
            <div class="tipo-button" onclick="mostrarFormulario('audiencia')">
                <i class="fas fa-gavel" style="color: #667eea;"></i>
                <div class="label">Audiência</div>
                <div class="desc">Agendar uma audiência</div>
            </div>
        </div>
    </div>
    
    <!-- FORMULÁRIO DE TAREFA -->
    <div id="form-tarefa" class="form-inline-container">
        <div class="form-inline-header">
            <h3><i class="fas fa-tasks" style="color: #ffc107;"></i> Nova Tarefa</h3>
            <button type="button" class="btn-close-form" onclick="voltarParaEscolha()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form id="formulario-tarefa" onsubmit="salvarTratamento(event, 'tarefa')">
            <input type="hidden" name="tipo" value="tarefa">
            
            <div class="form-grid">
                <div class="form-group form-group-full">
                    <label>Título <span class="required">*</span></label>
                    <input type="text" name="titulo" class="form-control" required 
                           placeholder="Ex: Revisar contrato">
                </div>
                
                <div class="form-group">
                    <label>Processo</label>
                    <div class="processo-busca-container">
                        <input type="text" 
                               class="processo-busca-input" 
                               id="processo_busca_tarefa" 
                               placeholder="Digite para buscar..."
                               autocomplete="off">
                        <i class="fas fa-search processo-busca-icon"></i>
                        <button type="button" class="btn-novo-processo" onclick="abrirCadastroProcesso('tarefa')" title="Cadastrar Novo Processo">
                            <i class="fas fa-plus"></i> Novo Processo
                        </button>
                        <div class="processo-resultados" id="processoResultadosTarefa"></div>
                    </div>
                    <input type="hidden" name="processo_id" id="processo_id_tarefa" value="<?= $pub['processo_id_vinculado'] ?? '' ?>">
                    <div id="processoSelecionadoTarefa">
                        <?php if ($pub['processo_id_vinculado']): ?>
                        <div class="processo-selecionado">
                            <div>
                                <div class="processo-numero"><?= htmlspecialchars($pub['processo_numero']) ?></div>
                                <?php if ($pub['processo_cliente']): ?>
                                <div class="processo-cliente"><?= htmlspecialchars($pub['processo_cliente']) ?></div>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="btn-remover-processo" onclick="removerProcesso('tarefa')">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Responsável <span class="required">*</span></label>
                    <select name="responsavel_id" class="form-control" required>
                        <?php foreach ($usuarios as $user): ?>
                        <option value="<?= $user['id'] ?>" <?= $user['id'] == $usuario_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($user['nome']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Data de Vencimento <span class="required">*</span></label>
                    <input type="date" name="data_vencimento" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Hora de Vencimento</label>
                    <input type="time" name="hora_vencimento" class="form-control" value="23:59">
                </div>
                
                <div class="form-group">
                    <label>Alertar com antecedência</label>
                    <select name="dias_alerta" class="form-control">
                        <option value="0">No dia do vencimento</option>
                        <option value="1">1 dia antes</option>
                        <option value="2">2 dias antes</option>
                        <option value="3" selected>3 dias antes</option>
                        <option value="5">5 dias antes</option>
                        <option value="7">7 dias antes</option>
                        <option value="10">10 dias antes</option>
                        <option value="15">15 dias antes</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Prioridade</label>
                    <select name="prioridade" class="form-control">
                        <option value="baixa">🟢 Baixa</option>
                        <option value="normal" selected>🟡 Normal</option>
                        <option value="alta">🔴 Alta</option>
                        <option value="urgente">⚠️ Urgente</option>
                    </select>
                </div>
                
                <div class="form-group form-group-full">
                    <label>Descrição</label>
                    <textarea name="descricao" class="form-control" 
                              placeholder="Descreva os detalhes da tarefa..."></textarea>
                </div>
                
                <div class="form-group">
                    <div class="etiquetas-header">
                        <label>Etiquetas</label>
                        <button type="button" class="btn-criar-etiqueta" onclick="abrirModalEtiqueta('tarefa')">
                            <i class="fas fa-plus"></i> Nova
                        </button>
                    </div>
                    <div class="etiquetas-container" id="etiquetasContainerTarefa">
                        <?php 
                        $etiquetas_tarefa = array_filter($etiquetas, function($e) {
                            return in_array($e['tipo'], ['tarefa', 'geral']);
                        });
                        ?>
                        <?php if (empty($etiquetas_tarefa)): ?>
                            <small style="color: #999; font-size: 11px;">Clique em "Nova"</small>
                        <?php else: ?>
                            <?php foreach ($etiquetas_tarefa as $etiqueta): ?>
                                <input type="checkbox" class="etiqueta-checkbox" 
                                       id="etiq_tarefa_<?= $etiqueta['id'] ?>" 
                                       name="etiquetas[]" value="<?= $etiqueta['id'] ?>">
                                <label for="etiq_tarefa_<?= $etiqueta['id'] ?>" 
                                       class="etiqueta-label" 
                                       style="background: <?= htmlspecialchars($etiqueta['cor']) ?>; color: white;">
                                    <?= htmlspecialchars($etiqueta['nome']) ?>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Envolvidos</label>
                    <div class="usuarios-selector">
                        <select name="envolvidos[]" class="form-control" multiple size="4">
                            <?php foreach ($usuarios as $user): ?>
                            <option value="<?= $user['id'] ?>">
                                <?= htmlspecialchars($user['nome']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <small class="hint">💡 Ctrl+clique para múltiplos</small>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="voltarParaEscolha()">
                    <i class="fas fa-arrow-left"></i> Voltar
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Salvar
                </button>
            </div>
        </form>
    </div>
    
    <!-- FORMULÁRIO DE PRAZO -->
    <div id="form-prazo" class="form-inline-container">
        <div class="form-inline-header">
            <h3><i class="fas fa-clock" style="color: #667eea;"></i> Novo Prazo</h3>
            <button type="button" class="btn-close-form" onclick="voltarParaEscolha()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form id="formulario-prazo" onsubmit="salvarTratamento(event, 'prazo')">
            <input type="hidden" name="tipo" value="prazo">
            
            <div class="form-grid">
                <div class="form-group form-group-full">
                    <label>Título <span class="required">*</span></label>
                    <input type="text" name="titulo" class="form-control" required 
                           placeholder="Ex: Responder contestação">
                </div>
                
                <div class="form-group form-group-full">
                    <label>Processo <span class="required">*</span></label>
                    <?php if ($pub['processo_id_vinculado']): ?>
                    <!-- Processo vinculado existe -->
                    <div class="processo-busca-container">
                        <input type="text" 
                               class="processo-busca-input" 
                               id="processo_busca_prazo" 
                               placeholder="Digite para buscar outro processo..."
                               autocomplete="off">
                        <i class="fas fa-search processo-busca-icon"></i>
                        <button type="button" class="btn-novo-processo" onclick="abrirCadastroProcesso('prazo')" title="Cadastrar Novo Processo">
                            <i class="fas fa-plus"></i> Novo Processo
                        </button>
                        <div class="processo-resultados" id="processoResultadosPrazo"></div>
                    </div>
                    <input type="hidden" name="processo_id" id="processo_id_prazo" value="<?= $pub['processo_id_vinculado'] ?>" required>
                    <div id="processoSelecionadoPrazo">
                        <div class="processo-selecionado">
                            <div>
                                <div class="processo-numero"><?= htmlspecialchars($pub['processo_numero']) ?></div>
                                <?php if ($pub['processo_cliente']): ?>
                                <div class="processo-cliente"><?= htmlspecialchars($pub['processo_cliente']) ?></div>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="btn-remover-processo" onclick="removerProcesso('prazo')">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    <?php else: ?>
                    <!-- Processo não existe - mostrar botão para criar -->
                    <div style="background: #fff3cd; border: 1px solid #ffc107; border-radius: 8px; padding: 12px; margin-bottom: 10px;">
                        <div style="color: #856404; font-size: 13px; margin-bottom: 8px;">
                            ⚠️ Este prazo precisa estar vinculado a um processo.
                        </div>
                        <button type="button" class="btn btn-primary" onclick="abrirCadastroProcesso('prazo')">
                            <i class="fas fa-plus"></i> Criar Novo Processo
                        </button>
                    </div>
                    <div class="processo-busca-container">
                        <input type="text" 
                               class="processo-busca-input" 
                               id="processo_busca_prazo" 
                               placeholder="Ou busque um processo existente..."
                               autocomplete="off">
                        <i class="fas fa-search processo-busca-icon"></i>
                        <button type="button" class="btn-novo-processo" onclick="abrirCadastroProcesso('prazo')" title="Cadastrar Novo Processo">
                            <i class="fas fa-plus"></i> Novo
                        </button>
                        <div class="processo-resultados" id="processoResultadosPrazo"></div>
                    </div>
                    <input type="hidden" name="processo_id" id="processo_id_prazo" required>
                    <div id="processoSelecionadoPrazo"></div>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label>Responsável <span class="required">*</span></label>
                    <select name="responsavel_id" class="form-control" required>
                        <?php foreach ($usuarios as $user): ?>
                        <option value="<?= $user['id'] ?>" <?= $user['id'] == $usuario_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($user['nome']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Data de Vencimento <span class="required">*</span></label>
                    <input type="date" name="data_vencimento" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Hora de Vencimento</label>
                    <input type="time" name="hora_vencimento" class="form-control" value="23:59">
                </div>
                
                <div class="form-group">
                    <label>Alertar com antecedência</label>
                    <select name="dias_alerta" class="form-control">
                        <option value="0">No dia do vencimento</option>
                        <option value="1">1 dia antes</option>
                        <option value="2">2 dias antes</option>
                        <option value="3" selected>3 dias antes</option>
                        <option value="5">5 dias antes</option>
                        <option value="7">7 dias antes</option>
                        <option value="10">10 dias antes</option>
                        <option value="15">15 dias antes</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Tipo de Prazo</label>
                    <select name="tipo_prazo" class="form-control">
                        <option value="processual">Processual</option>
                        <option value="interno">Interno</option>
                        <option value="fatal">Fatal</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Prioridade</label>
                    <select name="prioridade" class="form-control">
                        <option value="baixa">🟢 Baixa</option>
                        <option value="normal">🟡 Normal</option>
                        <option value="alta" selected>🔴 Alta</option>
                        <option value="urgente">⚠️ Urgente</option>
                    </select>
                </div>
                
                <div class="form-group form-group-full">
                    <label>Descrição</label>
                    <textarea name="descricao" class="form-control" 
                              placeholder="Descreva os detalhes do prazo..."></textarea>
                </div>
                
                <div class="form-group">
                    <div class="etiquetas-header">
                        <label>Etiquetas</label>
                        <button type="button" class="btn-criar-etiqueta" onclick="abrirModalEtiqueta('prazo')">
                            <i class="fas fa-plus"></i> Nova
                        </button>
                    </div>
                    <div class="etiquetas-container" id="etiquetasContainerPrazo">
                        <?php 
                        $etiquetas_prazo = array_filter($etiquetas, function($e) {
                            return in_array($e['tipo'], ['prazo', 'geral']);
                        });
                        ?>
                        <?php if (empty($etiquetas_prazo)): ?>
                            <small style="color: #999; font-size: 11px;">Clique em "Nova"</small>
                        <?php else: ?>
                            <?php foreach ($etiquetas_prazo as $etiqueta): ?>
                                <input type="checkbox" class="etiqueta-checkbox" 
                                       id="etiq_prazo_<?= $etiqueta['id'] ?>" 
                                       name="etiquetas[]" value="<?= $etiqueta['id'] ?>">
                                <label for="etiq_prazo_<?= $etiqueta['id'] ?>" 
                                       class="etiqueta-label" 
                                       style="background: <?= htmlspecialchars($etiqueta['cor']) ?>; color: white;">
                                    <?= htmlspecialchars($etiqueta['nome']) ?>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Envolvidos</label>
                    <div class="usuarios-selector">
                        <select name="envolvidos[]" class="form-control" multiple size="4">
                            <?php foreach ($usuarios as $user): ?>
                            <option value="<?= $user['id'] ?>">
                                <?= htmlspecialchars($user['nome']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <small class="hint">💡 Ctrl+clique para múltiplos</small>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="voltarParaEscolha()">
                    <i class="fas fa-arrow-left"></i> Voltar
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Salvar
                </button>
            </div>
        </form>
    </div>
    
    <!-- FORMULÁRIO DE AUDIÊNCIA -->
    <div id="form-audiencia" class="form-inline-container">
        <div class="form-inline-header">
            <h3><i class="fas fa-gavel" style="color: #667eea;"></i> Nova Audiência</h3>
            <button type="button" class="btn-close-form" onclick="voltarParaEscolha()">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <form id="formulario-audiencia" onsubmit="salvarTratamento(event, 'audiencia')">
            <input type="hidden" name="tipo" value="audiencia">
            
            <div class="form-grid">
                <div class="form-group form-group-full">
                    <label>Título <span class="required">*</span></label>
                    <input type="text" name="titulo" class="form-control" required 
                           placeholder="Ex: Audiência de Instrução e Julgamento">
                </div>
                
                <div class="form-group form-group-full">
                    <label>Processo</label>
                    <div class="processo-busca-container">
                        <input type="text" 
                               class="processo-busca-input" 
                               id="processo_busca_audiencia" 
                               placeholder="Digite para buscar..."
                               autocomplete="off">
                        <i class="fas fa-search processo-busca-icon"></i>
                        <button type="button" class="btn-novo-processo" onclick="abrirCadastroProcesso('audiencia')" title="Cadastrar Novo Processo">
                            <i class="fas fa-plus"></i> Novo Processo
                        </button>
                        <div class="processo-resultados" id="processoResultadosAudiencia"></div>
                    </div>
                    <input type="hidden" name="processo_id" id="processo_id_audiencia" value="<?= $pub['processo_id_vinculado'] ?? '' ?>">
                    <div id="processoSelecionadoAudiencia">
                        <?php if ($pub['processo_id_vinculado']): ?>
                        <div class="processo-selecionado">
                            <div>
                                <div class="processo-numero"><?= htmlspecialchars($pub['processo_numero']) ?></div>
                                <?php if ($pub['processo_cliente']): ?>
                                <div class="processo-cliente"><?= htmlspecialchars($pub['processo_cliente']) ?></div>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="btn-remover-processo" onclick="removerProcesso('audiencia')">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Responsável <span class="required">*</span></label>
                    <select name="responsavel_id" class="form-control" required>
                        <?php foreach ($usuarios as $user): ?>
                        <option value="<?= $user['id'] ?>" <?= $user['id'] == $usuario_id ? 'selected' : '' ?>>
                            <?= htmlspecialchars($user['nome']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Data da Audiência <span class="required">*</span></label>
                    <input type="date" name="data_audiencia" class="form-control" required>
                </div>
                
                <div class="form-group">
                    <label>Horário <span class="required">*</span></label>
                    <input type="time" name="hora_audiencia" class="form-control" value="09:00" required>
                </div>
                
                <div class="form-group">
                    <label>Duração (min)</label>
                    <select name="duracao_estimada" class="form-control">
                        <option value="">-</option>
                        <option value="15">15 min</option>
                        <option value="30" selected>30 min</option>
                        <option value="60">1h</option>
                        <option value="90">1h 30min</option>
                        <option value="120">2h</option>
                        <option value="180">3h</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Local</label>
                    <input type="text" name="local_audiencia" class="form-control" 
                           placeholder="Ex: Fórum de Pato Branco, Sala Virtual">
                </div>
                
                <div class="form-group">
                    <label>Tipo de Audiência</label>
                    <select name="tipo_audiencia" class="form-control">
                        <option value="inicial">Inicial</option>
                        <option value="conciliacao">Conciliação</option>
                        <option value="instrucao">Instrução</option>
                        <option value="julgamento">Julgamento</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Prioridade</label>
                    <select name="prioridade" class="form-control">
                        <option value="Baixa">🟢 Baixa</option>
                        <option value="Normal" selected>🟡 Normal</option>
                        <option value="Alta">🔴 Alta</option>
                        <option value="Urgente">⚠️ Urgente</option>
                    </select>
                </div>
                
                <div class="form-group form-group-full">
                    <label>Descrição / Observações</label>
                    <textarea name="descricao" class="form-control" 
                              placeholder="Informações adicionais sobre a audiência, pauta, documentos necessários..."></textarea>
                </div>
                
                <div class="form-group">
                    <div class="etiquetas-header">
                        <label>Etiquetas</label>
                        <button type="button" class="btn-criar-etiqueta" onclick="abrirModalEtiqueta('audiencia')">
                            <i class="fas fa-plus"></i> Nova
                        </button>
                    </div>
                    <div class="etiquetas-container" id="etiquetasContainerAudiencia">
                        <?php 
                        $etiquetas_audiencia = array_filter($etiquetas, function($e) {
                            return in_array($e['tipo'], ['audiencia', 'geral']);
                        });
                        ?>
                        <?php if (empty($etiquetas_audiencia)): ?>
                            <small style="color: #999; font-size: 11px;">Clique em "Nova"</small>
                        <?php else: ?>
                            <?php foreach ($etiquetas_audiencia as $etiqueta): ?>
                                <input type="checkbox" class="etiqueta-checkbox" 
                                       id="etiq_audiencia_<?= $etiqueta['id'] ?>" 
                                       name="etiquetas[]" value="<?= $etiqueta['id'] ?>">
                                <label for="etiq_audiencia_<?= $etiqueta['id'] ?>" 
                                       class="etiqueta-label" 
                                       style="background: <?= htmlspecialchars($etiqueta['cor']) ?>; color: white;">
                                    <?= htmlspecialchars($etiqueta['nome']) ?>
                                </label>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Envolvidos</label>
                    <div class="usuarios-selector">
                        <select name="envolvidos[]" class="form-control" multiple size="4">
                            <?php foreach ($usuarios as $user): ?>
                            <option value="<?= $user['id'] ?>">
                                <?= htmlspecialchars($user['nome']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <small class="hint">💡 Ctrl+clique para múltiplos</small>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="button" class="btn btn-secondary" onclick="voltarParaEscolha()">
                    <i class="fas fa-arrow-left"></i> Voltar
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Salvar
                </button>
            </div>
        </form>
    </div>
    
    <!-- Modal para criar etiqueta -->
    <div class="modal-etiqueta" id="modalEtiqueta">
        <div class="modal-content-etiqueta">
            <div class="modal-header-etiqueta">
                <h4><i class="fas fa-tag"></i> Nova Etiqueta</h4>
                <button type="button" class="btn-close-form" onclick="fecharModalEtiqueta()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form id="formEtiqueta" onsubmit="salvarEtiqueta(event)">
                <div style="margin-bottom: 15px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">
                        Nome <span style="color: #dc3545;">*</span>
                    </label>
                    <input type="text" class="form-control" id="nomeEtiqueta" 
                           name="nome" required placeholder="Ex: Urgente">
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Cor</label>
                    <div class="cores-etiqueta">
                        <div class="cor-option selected" data-cor="#667eea" style="background: #667eea;"></div>
                        <div class="cor-option" data-cor="#dc3545" style="background: #dc3545;"></div>
                        <div class="cor-option" data-cor="#28a745" style="background: #28a745;"></div>
                        <div class="cor-option" data-cor="#ffc107" style="background: #ffc107;"></div>
                        <div class="cor-option" data-cor="#17a2b8" style="background: #17a2b8;"></div>
                        <div class="cor-option" data-cor="#6f42c1" style="background: #6f42c1;"></div>
                        <div class="cor-option" data-cor="#fd7e14" style="background: #fd7e14;"></div>
                        <div class="cor-option" data-cor="#e83e8c" style="background: #e83e8c;"></div>
                    </div>
                    <input type="hidden" name="cor" id="corEtiqueta" value="#667eea">
                </div>
                
                <div style="margin-bottom: 15px;">
                    <label style="display: block; font-weight: 600; margin-bottom: 8px;">Tipo</label>
                    <select class="form-control" name="tipo" id="tipoEtiqueta">
                        <option value="tarefa">Tarefa</option>
                        <option value="prazo">Prazo</option>
                        <option value="audiencia">Audiência</option>
                        <option value="geral">Geral</option>
                    </select>
                </div>
                
                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="fecharModalEtiqueta()">
                        Cancelar
                    </button>
                    <button type="submit" class="btn btn-success">
                        Criar
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        let tipoAtualEtiqueta = null;
        
        function mostrarFormulario(tipo) {
            document.getElementById('escolhaContainer').style.display = 'none';
            document.getElementById('form-' + tipo).classList.add('active');
        }
        
        function voltarParaEscolha() {
            document.querySelectorAll('.form-inline-container').forEach(f => {
                f.classList.remove('active');
            });
            document.getElementById('escolhaContainer').style.display = 'block';
        }
        
        function abrirModalEtiqueta(tipo) {
            tipoAtualEtiqueta = tipo;
            document.getElementById('tipoEtiqueta').value = tipo;
            document.getElementById('modalEtiqueta').classList.add('active');
            setTimeout(() => document.getElementById('nomeEtiqueta').focus(), 100);
        }
        
        function fecharModalEtiqueta() {
            document.getElementById('modalEtiqueta').classList.remove('active');
            document.getElementById('formEtiqueta').reset();
            document.querySelectorAll('.cor-option').forEach(c => c.classList.remove('selected'));
            document.querySelector('.cor-option').classList.add('selected');
            document.getElementById('corEtiqueta').value = '#667eea';
        }
        
        // Cores
        document.querySelectorAll('.cor-option').forEach(opt => {
            opt.addEventListener('click', function() {
                document.querySelectorAll('.cor-option').forEach(o => o.classList.remove('selected'));
                this.classList.add('selected');
                document.getElementById('corEtiqueta').value = this.dataset.cor;
            });
        });
        
        // Fechar modal clicando fora
        document.getElementById('modalEtiqueta').addEventListener('click', function(e) {
            if (e.target === this) fecharModalEtiqueta();
        });
        
        // Salvar etiqueta
        async function salvarEtiqueta(event) {
            event.preventDefault();
            
            const form = event.target;
            const formData = new FormData(form);
            const btnSubmit = form.querySelector('button[type="submit"]');
            
            btnSubmit.disabled = true;
            btnSubmit.textContent = '⏳ Salvando...';
            
            try {
                const response = await fetch('/modules/agenda/formularios/salvar_etiqueta.php', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Adicionar ao container correto
                    const container = document.getElementById('etiquetasContainer' + tipoAtualEtiqueta.charAt(0).toUpperCase() + tipoAtualEtiqueta.slice(1));
                    
                    if (container) {
                        const small = container.querySelector('small');
                        if (small) small.remove();
                        
                        const prefixo = tipoAtualEtiqueta.toLowerCase();
                        container.insertAdjacentHTML('beforeend', `
                            <input type="checkbox" class="etiqueta-checkbox" 
                                   id="etiq_${prefixo}_${result.etiqueta_id}" 
                                   name="etiquetas[]" value="${result.etiqueta_id}" checked>
                            <label for="etiq_${prefixo}_${result.etiqueta_id}" 
                                   class="etiqueta-label" 
                                   style="background: ${result.cor}; color: white;">
                                ${result.nome}
                            </label>
                        `);
                    }
                    
                    fecharModalEtiqueta();
                } else {
                    alert('❌ Erro: ' + result.message);
                }
            } catch (error) {
                console.error(error);
                alert('❌ Erro ao salvar: ' + error.message);
            } finally {
                btnSubmit.disabled = false;
                btnSubmit.textContent = 'Criar';
            }
        }
        
        // Busca de processos
        function inicializarBuscaProcesso(tipo) {
            const inputBusca = document.getElementById(`processo_busca_${tipo}`);
            const divResultados = document.getElementById(`processoResultados${tipo.charAt(0).toUpperCase() + tipo.slice(1)}`);
            let timeout = null;
            
            if (!inputBusca || !divResultados) return;
            
            inputBusca.addEventListener('input', function() {
                const termo = this.value.trim();
                
                clearTimeout(timeout);
                
                if (termo.length < 2) {
                    divResultados.classList.remove('active');
                    divResultados.innerHTML = '';
                    return;
                }
                
                timeout = setTimeout(() => buscarProcessos(termo, tipo), 300);
            });
            
            document.addEventListener('click', function(e) {
                if (!inputBusca.contains(e.target) && !divResultados.contains(e.target)) {
                    divResultados.classList.remove('active');
                }
            });
        }
        
        async function buscarProcessos(termo, tipo) {
            const divResultados = document.getElementById(`processoResultados${tipo.charAt(0).toUpperCase() + tipo.slice(1)}`);
            
            try {
                const response = await fetch(`/modules/agenda/formularios/buscar_processos.php?termo=${encodeURIComponent(termo)}`);
                const processos = await response.json();
                
                if (processos.length === 0) {
                    divResultados.innerHTML = '<div class="processo-item" style="color:#999">Nenhum encontrado</div>';
                } else {
                    divResultados.innerHTML = processos.map(p => `
                        <div class="processo-item" data-id="${p.id}" data-numero="${p.numero_processo}" data-cliente="${p.cliente_nome || ''}">
                            <div class="processo-numero">${p.numero_processo}</div>
                            ${p.cliente_nome ? `<div class="processo-cliente">${p.cliente_nome}</div>` : ''}
                        </div>
                    `).join('');
                    
                    divResultados.querySelectorAll('.processo-item').forEach(item => {
                        item.addEventListener('click', function() {
                            selecionarProcesso(this.dataset.id, this.dataset.numero, this.dataset.cliente, tipo);
                        });
                    });
                }
                
                divResultados.classList.add('active');
            } catch (error) {
                console.error('Erro ao buscar processos:', error);
            }
        }
        
        function selecionarProcesso(id, numero, cliente, tipo) {
            const tipoCapitalizado = tipo.charAt(0).toUpperCase() + tipo.slice(1);
            const inputId = document.getElementById(`processo_id_${tipo}`);
            const inputBusca = document.getElementById(`processo_busca_${tipo}`);
            const divSelecionado = document.getElementById(`processoSelecionado${tipoCapitalizado}`);
            const divResultados = document.getElementById(`processoResultados${tipoCapitalizado}`);
            
            if (inputId) inputId.value = id;
            if (inputBusca) inputBusca.value = '';
            if (divResultados) divResultados.classList.remove('active');
            
            if (divSelecionado) {
                divSelecionado.innerHTML = `
                    <div class="processo-selecionado">
                        <div>
                            <div class="processo-numero">${numero}</div>
                            ${cliente ? `<div class="processo-cliente">${cliente}</div>` : ''}
                        </div>
                        <button type="button" class="btn-remover-processo" onclick="removerProcesso('${tipo}')">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                `;
            }
        }
        
        function removerProcesso(tipo) {
            const inputId = document.getElementById(`processo_id_${tipo}`);
            const tipoCapitalizado = tipo.charAt(0).toUpperCase() + tipo.slice(1);
            const divSelecionado = document.getElementById(`processoSelecionado${tipoCapitalizado}`);
            
            if (inputId) inputId.value = '';
            if (divSelecionado) divSelecionado.innerHTML = '';
        }
        
        // Guardar referência do tipo para callback
        let tipoProcessoAtual = null;
        const sessionToken = '<?= $session_token ?>'; // Token para autenticação
        
        function abrirCadastroProcesso(tipo) {
            tipoProcessoAtual = tipo;
            const url = '<?= SITE_URL ?>/modules/processos/novo_simplificado.php?popup=1&token=' + encodeURIComponent(sessionToken);
            window.open(url, 'CadastroProcesso', 'width=1000,height=800,scrollbars=yes');
        }
        
        // Função chamada pelo popup de processo quando um novo processo é criado
        window.selecionarProcessoCriado = function(processoId, numeroProcesso, clienteNome) {
            const tipo = tipoProcessoAtual;
            if (!tipo) return;
            
            selecionarProcesso(processoId, numeroProcesso, clienteNome, tipo);
        };
        
        // Inicializar buscas de processo
        inicializarBuscaProcesso('tarefa');
        inicializarBuscaProcesso('prazo');
        inicializarBuscaProcesso('audiencia');
        
        async function salvarTratamento(event, tipo) {
            event.preventDefault();
            
            const form = event.target;
            const formData = new FormData(form);
            const btnSalvar = form.querySelector('button[type="submit"]');
            
            btnSalvar.disabled = true;
            btnSalvar.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Salvando...';
            
            try {
                const response = await fetch('tratar.php?id=<?= $publicacao_id ?>', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    if (window.opener && !window.opener.closed) {
                        window.opener.postMessage({
                            type: 'publicacao_tratada',
                            success: true,
                            message: result.message
                        }, '*');
                    }
                    
                    alert('✅ ' + result.message);
                    setTimeout(() => window.close(), 500);
                } else {
                    alert('❌ ' + result.message);
                    btnSalvar.disabled = false;
                    btnSalvar.innerHTML = '<i class="fas fa-save"></i> Salvar';
                }
            } catch (error) {
                console.error(error);
                alert('❌ Erro ao salvar: ' + error.message);
                btnSalvar.disabled = false;
                btnSalvar.innerHTML = '<i class="fas fa-save"></i> Salvar';
            }
        }
        
        // ESC para voltar ou fechar
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const modalEtiqueta = document.getElementById('modalEtiqueta');
                if (modalEtiqueta.classList.contains('active')) {
                    fecharModalEtiqueta();
                } else {
                    const formAtivo = document.querySelector('.form-inline-container.active');
                    if (formAtivo) {
                        voltarParaEscolha();
                    } else {
                        window.close();
                    }
                }
            }
        });
    </script>
</body>
</html>