<?php
/**
 * PROCESSAMENTO DO FORMULÁRIO SIMPLIFICADO DE PROCESSO
 * Versão para popup - retorna JSON ou redireciona
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

$erros = [];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: novo_simplificado.php');
    exit;
}

$usuario_logado = Auth::user();
$modo_popup = isset($_GET['popup']) && $_GET['popup'] == '1';

// Receber dados do formulário
$nucleo_id = $_POST['nucleo_id'] ?? '';
$numero_processo = trim($_POST['numero_processo'] ?? '');
$tipo_processo = $_POST['tipo_processo'] ?? '';
$situacao_processual = $_POST['situacao_processual'] ?? '';
$comarca = trim($_POST['comarca'] ?? '');
$valor_causa = !empty($_POST['valor_causa']) ? str_replace(',', '.', $_POST['valor_causa']) : null;
$responsavel_id = $_POST['responsavel_id'] ?? '';
$fase_atual = trim($_POST['fase_atual'] ?? '');
$anotacoes = trim($_POST['anotacoes'] ?? '');
$partes = $_POST['partes'] ?? [];

// --- Validações ---

if (empty($nucleo_id)) {
    $erros[] = 'Núcleo é obrigatório';
}

if (empty($numero_processo)) {
    $erros[] = 'Número do processo é obrigatório';
}

if (empty($tipo_processo)) {
    $erros[] = 'Tipo de processo é obrigatório';
}

if (empty($situacao_processual)) {
    $erros[] = 'Situação processual é obrigatória';
}

if (empty($responsavel_id)) {
    $erros[] = 'Responsável pelo processo é obrigatório';
} else {
    // Validar se responsável tem acesso ao núcleo
    if (!empty($nucleo_id)) {
        $sql = "SELECT COUNT(*) as count FROM usuarios_nucleos WHERE usuario_id = ? AND nucleo_id = ?";
        $stmt = executeQuery($sql, [$responsavel_id, $nucleo_id]);
        $result = $stmt->fetch();
        if ($result['count'] == 0) {
            $erros[] = 'Responsável selecionado não tem acesso ao núcleo escolhido';
        }
    }
}

// Validar partes
if (empty($partes)) {
    $erros[] = 'É necessário adicionar pelo menos uma parte ao processo';
} else {
    $tem_nosso_cliente = false;
    foreach ($partes as $key => $parte) {
        if (empty($parte['tipo_parte'])) {
            $erros[] = "Parte #{$key}: Tipo de parte é obrigatório";
        }
        if (empty($parte['nome'])) {
            $erros[] = "Parte #{$key}: Nome da parte é obrigatório";
        }
        if (isset($parte['e_nosso_cliente']) && $parte['e_nosso_cliente'] == '1') {
            $tem_nosso_cliente = true;
        }
    }
}

// Verificar se número do processo já existe
if (!empty($numero_processo)) {
    $sql = "SELECT id FROM processos WHERE numero_processo = ?";
    $stmt = executeQuery($sql, [$numero_processo]);
    if ($stmt->fetch()) {
        $erros[] = 'Este número de processo já está cadastrado';
    }
}

// Se houver erros
if (!empty($erros)) {
    if ($modo_popup) {
        // Retornar JSON com erro
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => implode('<br>', $erros)
        ]);
        exit;
    } else {
        $_SESSION['erro'] = implode('<br>', $erros);
        header('Location: novo_simplificado.php');
        exit;
    }
}

// --- Inserção no Banco de Dados ---
try {
    $conn = getConnection();
    $conn->beginTransaction();
    
    // Pegar o primeiro cliente (nosso cliente) para compatibilidade
    $primeiro_cliente_id = null;
    $primeiro_cliente_nome = '';
    foreach ($partes as $parte) {
        if (isset($parte['e_nosso_cliente']) && $parte['e_nosso_cliente'] == '1') {
            $primeiro_cliente_id = !empty($parte['cliente_id']) ? $parte['cliente_id'] : null;
            $primeiro_cliente_nome = $parte['nome'];
            break;
        }
    }
    
    // Se não tiver nenhum marcado como nosso cliente, pega o primeiro
    if (empty($primeiro_cliente_nome)) {
        $primeira_parte = reset($partes);
        $primeiro_cliente_id = !empty($primeira_parte['cliente_id']) ? $primeira_parte['cliente_id'] : null;
        $primeiro_cliente_nome = $primeira_parte['nome'];
    }
    
    // Inserir processo
    $sql = "INSERT INTO processos (
        numero_processo, 
        cliente_id, 
        cliente_nome, 
        parte_contraria, 
        comarca, 
        valor_causa,
        tipo_processo, 
        situacao_processual, 
        responsavel_id, 
        nucleo_id, 
        fase_atual, 
        anotacoes, 
        usa_partes_multiplas,
        criado_por,
        data_criacao
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
    
    // Montar parte_contraria (todas as partes que não são nosso cliente)
    $partes_contrarias = [];
    foreach ($partes as $parte) {
        if (!isset($parte['e_nosso_cliente']) || $parte['e_nosso_cliente'] != '1') {
            $partes_contrarias[] = $parte['nome'];
        }
    }
    $parte_contraria_str = implode(', ', $partes_contrarias);
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        $numero_processo,
        $primeiro_cliente_id,
        $primeiro_cliente_nome,
        $parte_contraria_str,
        $comarca,
        $valor_causa,
        $tipo_processo,
        $situacao_processual,
        $responsavel_id,
        $nucleo_id,
        $fase_atual,
        $anotacoes,
        1, // usa_partes_multiplas = true
        $usuario_logado['usuario_id']
    ]);
    
    $processo_id = $conn->lastInsertId();
    
    // Inserir as partes do processo
    $sql_parte = "INSERT INTO processo_partes (
        processo_id,
        cliente_id,
        nome,
        tipo_parte,
        e_nosso_cliente,
        observacoes,
        ordem,
        criado_por
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt_parte = $conn->prepare($sql_parte);
    $ordem = 0;
    
    foreach ($partes as $parte) {
        $ordem++;
        $stmt_parte->execute([
            $processo_id,
            !empty($parte['cliente_id']) ? $parte['cliente_id'] : null,
            $parte['nome'],
            $parte['tipo_parte'],
            isset($parte['e_nosso_cliente']) && $parte['e_nosso_cliente'] == '1' ? 1 : 0,
            null,
            $ordem,
            $usuario_logado['usuario_id']
        ]);
    }
    
    // Registrar movimentação inicial
    $sql_mov = "INSERT INTO processo_movimentacoes (
        processo_id, 
        data_movimentacao, 
        descricao, 
        fase_nova, 
        responsavel_novo, 
        criado_por
    ) VALUES (?, ?, ?, ?, ?, ?)";
    
    $stmt_mov = $conn->prepare($sql_mov);
    $stmt_mov->execute([
        $processo_id,
        date('Y-m-d'),
        'Processo cadastrado no sistema',
        $fase_atual ?: 'Cadastrado',
        $responsavel_id,
        $usuario_logado['usuario_id']
    ]);
    
    $conn->commit();
    
    // Log da ação
    Auth::log('Criar Processo', "Processo #{$numero_processo} criado (simplificado) com " . count($partes) . " parte(s)");
    
    // Resposta de sucesso
    if ($modo_popup) {
        // Retornar JSON para o popup
        header('Content-Type: application/json');
        echo json_encode([
            'success' => true,
            'processo_id' => $processo_id,
            'numero_processo' => $numero_processo,
            'cliente_nome' => $primeiro_cliente_nome,
            'message' => 'Processo cadastrado com sucesso!'
        ]);
        exit;
    } else {
        // Redirecionar normalmente
        $_SESSION['sucesso'] = 'Processo cadastrado com sucesso!';
        header("Location: visualizar.php?id=$processo_id");
        exit;
    }
    
} catch (Exception $e) {
    if (isset($conn)) {
        $conn->rollBack();
    }
    
    $erro_msg = 'Erro ao cadastrar processo: ' . $e->getMessage();
    
    if ($modo_popup) {
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => $erro_msg
        ]);
        exit;
    } else {
        $_SESSION['erro'] = $erro_msg;
        header('Location: novo_simplificado.php');
        exit;
    }
}