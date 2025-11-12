<?php
require_once '../../includes/auth.php';
Auth::protect(['Admin', 'Socio', 'Diretor']);

require_once '../../config/database.php';

$usuario_id = $_GET['id'] ?? 0;
$usuario_logado = Auth::user();

if (!$usuario_id) {
    $_SESSION['erro'] = 'Usuário não encontrado';
    header('Location: index.php');
    exit;
}

// Verificar se não está tentando desativar a si mesmo
if ($usuario_id == $usuario_logado['usuario_id']) {
    $_SESSION['erro'] = 'Você não pode desativar seu próprio usuário';
    header('Location: index.php');
    exit;
}

// Buscar dados do usuário
$sql = "SELECT nome, ativo FROM usuarios WHERE id = ?";
$stmt = executeQuery($sql, [$usuario_id]);
$usuario = $stmt->fetch();

if (!$usuario) {
    $_SESSION['erro'] = 'Usuário não encontrado';
    header('Location: index.php');
    exit;
}

try {
    // Alternar status
    $novo_status = $usuario['ativo'] ? 0 : 1;
    $sql = "UPDATE usuarios SET ativo = ? WHERE id = ?";
    $stmt = executeQuery($sql, [$novo_status, $usuario_id]);
    
    // Se desativando, remover todas as sessões do usuário
    if ($novo_status == 0) {
        $sql = "DELETE FROM sessoes WHERE usuario_id = ?";
        executeQuery($sql, [$usuario_id]);
    }
    
    // Log da ação
    $acao = $novo_status ? 'ativado' : 'desativado';
    Auth::log('Toggle Usuário', "Usuário {$usuario['nome']} (ID: {$usuario_id}) {$acao}");
    
    $_SESSION['sucesso'] = "Usuário {$acao} com sucesso!";
    header('Location: index.php');
    exit;
    
} catch (Exception $e) {
    $_SESSION['erro'] = 'Erro ao alterar status do usuário: ' . $e->getMessage();
    header('Location: index.php');
    exit;
}
?>