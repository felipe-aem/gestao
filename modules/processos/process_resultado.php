<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$processo_id = $_POST['processo_id'] ?? 0;
$tipo_resultado = $_POST['tipo_resultado'] ?? '';
$data_resultado = $_POST['data_resultado'] ?? '';
$descricao_resultado = trim($_POST['descricao_resultado'] ?? '');
$data_entrega_cliente = $_POST['data_entrega_cliente'] ?? null;
$observacoes = trim($_POST['observacoes'] ?? '');

// Validações
$erros = [];

if (!$processo_id) {
    $erros[] = 'Processo não encontrado';
}

if (empty($tipo_resultado)) {
    $erros[] = 'Tipo de resultado é obrigatório';
}

if (empty($data_resultado)) {
    $erros[] = 'Data do resultado é obrigatória';
}

if (empty($descricao_resultado)) {
    $erros[] = 'Descrição do resultado é obrigatória';
}

// Verificar se data de entrega não é anterior à data do resultado
if (!empty($data_resultado) && !empty($data_entrega_cliente)) {
    $data_res = new DateTime($data_resultado);
    $data_ent = new DateTime($data_entrega_cliente);
    
    if ($data_ent < $data_res) {
        $erros[] = 'Data de entrega ao cliente não pode ser anterior à data do resultado';
    }
}

// Verificar se processo existe e usuário tem acesso
$usuario_logado = Auth::user();
$nucleos_usuario = $usuario_logado['nucleos'] ?? [];

$sql = "SELECT p.numero_processo, p.cliente_nome, p.nucleo_id, n.nome as nucleo_nome
        FROM processos p 
        INNER JOIN nucleos n ON p.nucleo_id = n.id 
        WHERE p.id = ?";
$stmt = executeQuery($sql, [$processo_id]);
$processo = $stmt->fetch();

if (!$processo || !in_array($processo['nucleo_id'], $nucleos_usuario)) {
    $erros[] = 'Processo não encontrado ou você não tem acesso a ele';
}

if (!empty($erros)) {
    $_SESSION['erro_resultado'] = implode('<br>', $erros);
    header("Location: novo_resultado.php?id=$processo_id");
    exit;
}

try {
    // Inserir resultado
    $sql = "INSERT INTO processo_resultados (
        processo_id, 
        tipo_resultado, 
        descricao_resultado, 
        data_resultado, 
        data_entrega_cliente, 
        observacoes, 
        criado_por
    ) VALUES (?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = executeQuery($sql, [
        $processo_id,
        $tipo_resultado,
        $descricao_resultado,
        $data_resultado,
        !empty($data_entrega_cliente) ? $data_entrega_cliente : null,
        $observacoes,
        $usuario_logado['usuario_id']
    ]);
    
    // Log da ação
    Auth::log('Registrar Resultado', "Resultado {$tipo_resultado} registrado para processo {$processo['numero_processo']}");
    
    $_SESSION['sucesso_resultado'] = 'Resultado registrado com sucesso!';
    header("Location: visualizar.php?id=$processo_id");
    exit;
    
} catch (Exception $e) {
    $_SESSION['erro_resultado'] = 'Erro ao registrar resultado: ' . $e->getMessage();
    header("Location: novo_resultado.php?id=$processo_id");
    exit;
}
?>