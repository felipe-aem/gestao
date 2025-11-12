<?php
require_once '../../includes/auth.php';
Auth::protect(['Admin', 'Socio', 'Diretor']);

require_once '../../config/database.php';

$usuario_id = $_GET['id'] ?? 0;

if (!$usuario_id) {
    $_SESSION['erro'] = 'Usuário não encontrado';
    header('Location: index.php');
    exit;
}

// Buscar dados do usuário
$sql = "SELECT nome, email FROM usuarios WHERE id = ?";
$stmt = executeQuery($sql, [$usuario_id]);
$usuario = $stmt->fetch();

if (!$usuario) {
    $_SESSION['erro'] = 'Usuário não encontrado';
    header('Location: index.php');
    exit;
}

try {
    // Gerar nova senha temporária
    $nova_senha = 'temp' . rand(1000, 9999);
    $senha_hash = password_hash($nova_senha, PASSWORD_DEFAULT);
    
    // Atualizar senha no banco
    $sql = "UPDATE usuarios SET senha = ? WHERE id = ?";
    $stmt = executeQuery($sql, [$senha_hash, $usuario_id]);
    
    // Log da ação
    $usuario_logado = Auth::user();
    Auth::log('Reset Senha', "Senha resetada para usuário {$usuario['nome']} (ID: {$usuario_id})");
    
    $_SESSION['sucesso'] = "Senha resetada com sucesso!<br>Nova senha temporária: <strong>{$nova_senha}</strong><br>Informe ao usuário para alterar na primeira oportunidade.";
    header("Location: editar.php?id=$usuario_id");
    exit;
    
} catch (Exception $e) {
    $_SESSION['erro'] = 'Erro ao resetar senha: ' . $e->getMessage();
    header("Location: editar.php?id=$usuario_id");
    exit;
}
?>