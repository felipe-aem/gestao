<?php
require_once '../../includes/auth.php';
Auth::protect(['Admin', 'Socio', 'Diretor']);

require_once '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: novo.php');
    exit;
}

$nome = trim($_POST['nome'] ?? '');
$email = trim($_POST['email'] ?? '');
$cpf = trim($_POST['cpf'] ?? '');
$telefone = trim($_POST['telefone'] ?? '');
$nivel_acesso = $_POST['nivel_acesso'] ?? '';
$senha = $_POST['senha'] ?? '';
$nucleos = $_POST['nucleos'] ?? [];
$acesso_financeiro = $_POST['acesso_financeiro'] ?? 'Nenhum';
$visualiza_publicacoes_nao_vinculadas = isset($_POST['visualiza_publicacoes_nao_vinculadas']) ? 1 : 0;

// Validações
$erros = [];

if (empty($nome)) {
    $erros[] = 'Nome é obrigatório';
}

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $erros[] = 'E-mail válido é obrigatório';
}

if (empty($nivel_acesso)) {
    $erros[] = 'Nível de acesso é obrigatório';
}

if (empty($senha) || strlen($senha) < 6) {
    $erros[] = 'Senha deve ter pelo menos 6 caracteres';
}

// Verificar se e-mail já existe
if (!empty($email)) {
    $sql = "SELECT id FROM usuarios WHERE email = ?";
    $stmt = executeQuery($sql, [$email]);
    if ($stmt->fetch()) {
        $erros[] = 'Este e-mail já está cadastrado';
    }
}

// Verificar se CPF já existe (se informado)
if (!empty($cpf)) {
    $cpf_limpo = preg_replace('/\D/', '', $cpf);
    if (strlen($cpf_limpo) === 11) {
        $sql = "SELECT id FROM usuarios WHERE cpf = ?";
        $stmt = executeQuery($sql, [$cpf]);
        if ($stmt->fetch()) {
            $erros[] = 'Este CPF já está cadastrado';
        }
    } else {
        $erros[] = 'CPF inválido';
    }
}

// Verificar permissão para criar Admin
$usuario_logado = Auth::user();
if ($nivel_acesso === 'Admin' && $usuario_logado['nivel_acesso'] !== 'Admin') {
    $erros[] = 'Você não tem permissão para criar usuários Admin';
}

if (!empty($erros)) {
    $_SESSION['erro'] = implode('<br>', $erros);
    header('Location: novo.php');
    exit;
}

try {
    $conn = getConnection();
    $conn->beginTransaction();
    
    // Inserir usuário
    $senha_hash = password_hash($senha, PASSWORD_DEFAULT);
    $cpf_limpo = !empty($cpf) ? preg_replace('/\D/', '', $cpf) : null;
    $telefone_limpo = !empty($telefone) ? preg_replace('/\D/', '', $telefone) : null;
    
    $sql = "INSERT INTO usuarios (nome, email, cpf, telefone, nivel_acesso, senha, acesso_financeiro, visualiza_publicacoes_nao_vinculadas, criado_por) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([
        $nome,
        $email,
        $cpf_limpo,
        $telefone_limpo,
        $nivel_acesso,
        $senha_hash,
        $acesso_financeiro,
        $visualiza_publicacoes_nao_vinculadas,
        $usuario_logado['usuario_id']
    ]);
    
    $usuario_id = $conn->lastInsertId();
    
    // Inserir núcleos
    if (!empty($nucleos)) {
        $sql_nucleo = "INSERT INTO usuarios_nucleos (usuario_id, nucleo_id) VALUES (?, ?)";
        $stmt_nucleo = $conn->prepare($sql_nucleo);
        
        foreach ($nucleos as $nucleo_id) {
            $stmt_nucleo->execute([$usuario_id, $nucleo_id]);
        }
    }
    
    $conn->commit();
    
    // Log da ação
    Auth::log('Criar Usuário', "Usuário {$nome} ({$email}) criado com sucesso");
    
    $_SESSION['sucesso'] = 'Usuário criado com sucesso!';
    header('Location: index.php');
    exit;
    
} catch (Exception $e) {
    $conn->rollBack();
    $_SESSION['erro'] = 'Erro ao criar usuário: ' . $e->getMessage();
    header('Location: novo.php');
    exit;
}
?>