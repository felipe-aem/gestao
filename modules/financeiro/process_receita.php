<?php
require_once __DIR__ . '/../../includes/auth.php';
Auth::protect();

require_once __DIR__ . '/../../config/database.php';

$erros = [];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$usuario_logado = Auth::user();

// VERIFICAR ACESSO AO MÓDULO FINANCEIRO
$acesso_financeiro = $usuario_logado['acesso_financeiro'] ?? 'Nenhum';

if ($acesso_financeiro === 'Nenhum') {
    $_SESSION['erro'] = 'Você não tem permissão para acessar o módulo financeiro';
    header('Location: ../dashboard/');
    exit;
}

// Receber dados do formulário
$processo_id = $_POST['processo_id'] ?? 0;
$tipo_receita = $_POST['tipo_receita'] ?? '';
$valor = trim($_POST['valor'] ?? '');
$data_recebimento = $_POST['data_recebimento'] ?? '';
$forma_recebimento = $_POST['forma_recebimento'] ?? '';
$numero_parcela = !empty($_POST['numero_parcela']) ? $_POST['numero_parcela'] : null;
$observacoes = trim($_POST['observacoes'] ?? '');

// --- Validações ---

if (!$processo_id) {
    $erros[] = 'Processo não especificado';
}

if (empty($tipo_receita)) {
    $erros[] = 'Tipo de receita é obrigatório';
}

if (empty($valor)) {
    $erros[] = 'Valor é obrigatório';
}

if (empty($data_recebimento)) {
    $erros[] = 'Data de recebimento é obrigatória';
}

if (empty($forma_recebimento)) {
    $erros[] = 'Forma de recebimento é obrigatória';
}

// Validar valor
$valor_numerico = 0;
if (!empty($valor)) {
    $valor_numerico = (float) str_replace(',', '.', $valor);
    if ($valor_numerico <= 0) {
        $erros[] = 'O valor deve ser maior que zero';
    }
}

// Validar se tipo é "Parcela" e número da parcela não foi informado
if ($tipo_receita === 'Parcela' && empty($numero_parcela)) {
    $erros[] = 'Número da parcela é obrigatório quando o tipo for "Parcela"';
}

// Verificar se processo existe e usuário tem acesso
$nucleos_usuario = $usuario_logado['nucleos'] ?? [];

if ($acesso_financeiro === 'Completo') {
    $sql = "SELECT p.*, n.nome as nucleo_nome 
            FROM processos p
            INNER JOIN nucleos n ON p.nucleo_id = n.id
            WHERE p.id = ?";
    $stmt = executeQuery($sql, [$processo_id]);
} else {
    // Gestores só podem registrar receitas de processos do seu núcleo
    $placeholders = str_repeat('?,', count($nucleos_usuario) - 1) . '?';
    $sql = "SELECT p.*, n.nome as nucleo_nome 
            FROM processos p
            INNER JOIN nucleos n ON p.nucleo_id = n.id
            WHERE p.id = ? AND p.nucleo_id IN ($placeholders)";
    $params = array_merge([$processo_id], $nucleos_usuario);
    $stmt = executeQuery($sql, $params);
}

$processo = $stmt->fetch();

if (!$processo) {
    $erros[] = 'Processo não encontrado ou você não tem acesso a ele';
}

// Validar data de recebimento
if (!empty($data_recebimento)) {
    $data_rec = new DateTime($data_recebimento);
    $hoje = new DateTime();
    
    if ($data_rec > $hoje) {
        $erros[] = 'A data de recebimento não pode ser futura';
    }
}

// Se houver erros, redireciona
if (!empty($erros)) {
    $_SESSION['erro'] = implode('<br>', $erros);
    header("Location: registrar_receita.php?processo_id=$processo_id");
    exit;
}

// --- Inserção no Banco de Dados ---
try {
    $sql = "INSERT INTO processo_receitas (
        processo_id,
        tipo_receita,
        valor,
        data_recebimento,
        forma_recebimento,
        numero_parcela,
        observacoes,
        criado_por
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = executeQuery($sql, [
        $processo_id,
        $tipo_receita,
        $valor_numerico,
        $data_recebimento,
        $forma_recebimento,
        $numero_parcela,
        $observacoes,
        $usuario_logado['usuario_id']
    ]);
    
    // Log da ação
    Auth::log(
        'Registrar Receita', 
        "Receita de R$ " . number_format($valor_numerico, 2, ',', '.') . 
        " registrada para o processo {$processo['numero_processo']}"
    );
    
    $_SESSION['sucesso'] = 'Receita registrada com sucesso!';
    
    // Redirecionar de volta para o formulário de registro (para adicionar mais receitas) 
    // ou para a visualização do processo
    if (isset($_POST['adicionar_outra'])) {
        header("Location: registrar_receita.php?processo_id=$processo_id");
    } else {
        header("Location: ../processos/visualizar.php?id=$processo_id");
    }
    exit;
    
} catch (Exception $e) {
    $_SESSION['erro'] = 'Erro ao registrar receita: ' . $e->getMessage();
    header("Location: registrar_receita.php?processo_id=$processo_id");
    exit;
}
?>