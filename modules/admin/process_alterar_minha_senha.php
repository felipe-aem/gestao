<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: alterar_minha_senha.php');
    exit;
}

$usuario_logado = Auth::user();
$usuario_id = $usuario_logado['usuario_id'];

$senha_atual = $_POST['senha_atual'] ?? '';
$nova_senha = $_POST['nova_senha'] ?? '';
$confirmar_senha = $_POST['confirmar_senha'] ?? '';

$erros = [];

// Validações
if (empty($senha_atual)) {
    $erros[] = 'Senha atual é obrigatória';
}

if (empty($nova_senha)) {
    $erros[] = 'Nova senha é obrigatória';
}

if (empty($confirmar_senha)) {
    $erros[] = 'Confirmação de senha é obrigatória';
}

if (strlen($nova_senha) < 6) {
    $erros[] = 'A nova senha deve ter no mínimo 6 caracteres';
}

if ($nova_senha !== $confirmar_senha) {
    $erros[] = 'A nova senha e a confirmação não coincidem';
}

if ($senha_atual === $nova_senha) {
    $erros[] = 'A nova senha deve ser diferente da senha atual';
}

// Se houver erros, redireciona
if (!empty($erros)) {
    $_SESSION['erro'] = implode('<br>', $erros);
    header('Location: alterar_minha_senha.php');
    exit;
}

try {
    // Buscar senha atual do banco
    $sql = "SELECT senha FROM usuarios WHERE id = ?";
    $stmt = executeQuery($sql, [$usuario_id]);
    $usuario = $stmt->fetch();
    
    if (!$usuario) {
        $_SESSION['erro'] = 'Usuário não encontrado';
        header('Location: alterar_minha_senha.php');
        exit;
    }
    
    // Verificar se a senha atual está correta
    if (!password_verify($senha_atual, $usuario['senha'])) {
        $_SESSION['erro'] = 'Senha atual incorreta';
        header('Location: alterar_minha_senha.php');
        exit;
    }
    
    // Hash da nova senha
    $senha_hash = password_hash($nova_senha, PASSWORD_DEFAULT);
    
    // Atualizar senha no banco
    $sql = "UPDATE usuarios SET senha = ? WHERE id = ?";
    executeQuery($sql, [$senha_hash, $usuario_id]);
    
    // Registrar no log
    Auth::log('Alterar Senha', 'Usuário alterou a própria senha');
    
    // Invalidar todas as outras sessões do usuário (exceto a atual)
    $sql = "DELETE FROM sessoes WHERE usuario_id = ? AND token != ?";
    executeQuery($sql, [$usuario_id, $_SESSION['token']]);
    
    $_SESSION['sucesso'] = '✅ Senha alterada com sucesso!';
    header('Location: alterar_minha_senha.php');
    exit;
    
} catch (Exception $e) {
    $_SESSION['erro'] = 'Erro ao alterar senha: ' . $e->getMessage();
    header('Location: alterar_minha_senha.php');
    exit;
}
?>