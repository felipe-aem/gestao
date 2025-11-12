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

// VERIFICAR ACESSO COMPLETO (apenas Diretores/Sócios podem editar)
$acesso_financeiro = $usuario_logado['acesso_financeiro'] ?? 'Nenhum';

if ($acesso_financeiro !== 'Completo') {
    $_SESSION['erro'] = 'Apenas usuários com acesso completo podem editar receitas';
    header('Location: index.php');
    exit;
}

// Receber dados do formulário
$receita_id = $_POST['receita_id'] ?? 0;
$processo_id = $_POST['processo_id'] ?? 0;
$tipo_receita = $_POST['tipo_receita'] ?? '';
$valor = trim($_POST['valor'] ?? '');
$data_recebimento = $_POST['data_recebimento'] ?? '';
$forma_recebimento = $_POST['forma_recebimento'] ?? '';
$numero_parcela = !empty($_POST['numero_parcela']) ? $_POST['numero_parcela'] : null;
$observacoes = trim($_POST['observacoes'] ?? '');

// --- Validações ---

if (!$receita_id) {
    $erros[] = 'Receita não especificada';
}

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

// Verificar se receita existe
$sql = "SELECT pr.*, p.numero_processo 
        FROM processo_receitas pr
        INNER JOIN processos p ON pr.processo_id = p.id
        WHERE pr.id = ?";
$stmt = executeQuery($sql, [$receita_id]);
$receita = $stmt->fetch();

if (!$receita) {
    $erros[] = 'Receita não encontrada';
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
    header("Location: editar_receita.php?id=$receita_id");
    exit;
}

// --- Atualização no Banco de Dados ---
try {
    $sql = "UPDATE processo_receitas SET
        tipo_receita = ?,
        valor = ?,
        data_recebimento = ?,
        forma_recebimento = ?,
        numero_parcela = ?,
        observacoes = ?
        WHERE id = ?";
    
    $stmt = executeQuery($sql, [
        $tipo_receita,
        $valor_numerico,
        $data_recebimento,
        $forma_recebimento,
        $numero_parcela,
        $observacoes,
        $receita_id
    ]);
    
    // Log da ação
    Auth::log(
        'Editar Receita', 
        "Receita #$receita_id editada - Processo: {$receita['numero_processo']} - Novo valor: R$ " . 
        number_format($valor_numerico, 2, ',', '.')
    );
    
    $_SESSION['sucesso'] = 'Receita atualizada com sucesso!';
    header("Location: index.php");
    exit;
    
} catch (Exception $e) {
    $_SESSION['erro'] = 'Erro ao atualizar receita: ' . $e->getMessage();
    header("Location: editar_receita.php?id=$receita_id");
    exit;
}
?>