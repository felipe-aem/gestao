<?php
require_once '../../includes/auth.php';
Auth::protect(['Admin', 'Socio', 'Diretor']);

require_once '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$usuario_id = $_POST['usuario_id'] ?? 0;
$nome = trim($_POST['nome'] ?? '');
$email = trim($_POST['email'] ?? '');
$cpf = trim($_POST['cpf'] ?? '');
$telefone = trim($_POST['telefone'] ?? '');
$nivel_acesso = $_POST['nivel_acesso'] ?? '';
$ativo = $_POST['ativo'] ?? 1;
$nucleos = $_POST['nucleos'] ?? [];
$acesso_financeiro = $_POST['acesso_financeiro'] ?? 'Nenhum';
$visualiza_publicacoes_nao_vinculadas = isset($_POST['visualiza_publicacoes_nao_vinculadas']) ? 1 : 0;

// Validações
$erros = [];

if (!$usuario_id) {
    $erros[] = 'Usuário não encontrado';
}

if (empty($nome)) {
    $erros[] = 'Nome é obrigatório';
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $erros[] = 'E-mail válido é obrigatório';
}

if (empty($nivel_acesso)) {
    $erros[] = 'Nível de acesso é obrigatório';
}

// Verificar se e-mail já existe (exceto para o próprio usuário)
if (!empty($email)) {
    $sql = "SELECT id FROM usuarios WHERE email = ? AND id != ?";
    $stmt = executeQuery($sql, [$email, $usuario_id]);
    if ($stmt->fetch()) {
        $erros[] = 'Este e-mail já está cadastrado';
    }
}

// Verificar se CPF já existe (se informado e exceto para o próprio usuário)
if (!empty($cpf)) {
    $cpf_limpo = preg_replace('/\D/', '', $cpf);
    if (strlen($cpf_limpo) === 11) {
        $sql = "SELECT id FROM usuarios WHERE cpf = ? AND id != ?";
        $stmt = executeQuery($sql, [$cpf_limpo, $usuario_id]);
        if ($stmt->fetch()) {
            $erros[] = 'Este CPF já está cadastrado';
        }
    } else {
        $erros[] = 'CPF inválido';
    }
}

// Verificar permissão para editar Admin
$usuario_logado = Auth::user();
if ($nivel_acesso === 'Admin' && $usuario_logado['nivel_acesso'] !== 'Admin') {
    $erros[] = 'Você não tem permissão para definir usuários como Admin';
}

// Verificar se está tentando editar seu próprio usuário para inativo
if ($usuario_id == $usuario_logado['usuario_id'] && $ativo == 0) {
    $erros[] = 'Você não pode desativar seu próprio usuário';
}

if (!empty($erros)) {
    $_SESSION['erro'] = implode('<br>', $erros);
    header("Location: editar.php?id=$usuario_id");
    exit;
}

try {
    $conn = getConnection();
    $conn->beginTransaction();
    
    // Atualizar usuário
    $cpf_limpo = !empty($cpf) ? preg_replace('/\D/', '', $cpf) : null;
    $telefone_limpo = !empty($telefone) ? preg_replace('/\D/', '', $telefone) : null;
    
    $sql = "UPDATE usuarios SET 
            nome = ?, 
            email = ?, 
            cpf = ?, 
            telefone = ?, 
            nivel_acesso = ?, 
            ativo = ?,
            acesso_financeiro = ?,
            visualiza_publicacoes_nao_vinculadas = ?
            WHERE id = ?";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        $nome,
        $email,
        $cpf_limpo,
        $telefone_limpo,
        $nivel_acesso,
        $ativo,
        $acesso_financeiro,
        $visualiza_publicacoes_nao_vinculadas,
        $usuario_id
    ]);
        
    // Remover núcleos existentes
    $sql = "DELETE FROM usuarios_nucleos WHERE usuario_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute([$usuario_id]);
    
    // Inserir novos núcleos
    if (!empty($nucleos)) {
        $sql_nucleo = "INSERT INTO usuarios_nucleos (usuario_id, nucleo_id) VALUES (?, ?)";
        $stmt_nucleo = $conn->prepare($sql_nucleo);
        
        foreach ($nucleos as $nucleo_id) {
            $stmt_nucleo->execute([$usuario_id, $nucleo_id]);
        }
    }
    
    $conn->commit();
    
    // Log da ação
    Auth::log('Editar Usuário', "Usuário {$nome} (ID: {$usuario_id}) editado com sucesso");
    
    $_SESSION['sucesso'] = 'Usuário atualizado com sucesso!';
    header('Location: index.php');
    exit;
    
} catch (Exception $e) {
    $conn->rollBack();
    $_SESSION['erro'] = 'Erro ao atualizar usuário: ' . $e->getMessage();
    header("Location: editar.php?id=$usuario_id");
    exit;
}
?>