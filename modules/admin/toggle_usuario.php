<?php
require_once '../../includes/auth.php';
Auth::protect();

// Verificar se o usuário é administrador
$usuario_logado = Auth::user();
$niveis_admin = ['Admin', 'Administrador', 'Socio', 'Diretor'];
if (!in_array($usuario_logado['nivel_acesso'], $niveis_admin)) {
    header('Location: ' . SITE_URL . '/modules/dashboard/?erro=Acesso negado');
    exit;
}

require_once '../../config/database.php';

$usuario_id = $_GET['id'] ?? 0;

if (!$usuario_id) {
    header('Location: usuarios.php?erro=Usuário não encontrado');
    exit;
}

// Não permitir desativar a si mesmo
if ($usuario_id == $usuario_logado['id']) {
    header('Location: usuarios.php?erro=Você não pode desativar sua própria conta');
    exit;
}

try {
    // Buscar usuário atual
    $sql = "SELECT * FROM usuarios WHERE id = ?";
    $stmt = executeQuery($sql, [$usuario_id]);
    $usuario = $stmt->fetch();
    
    if (!$usuario) {
        header('Location: usuarios.php?erro=Usuário não encontrado');
        exit;
    }
    
    // Alternar status
    $novo_status = $usuario['ativo'] ? 0 : 1;
    $acao = $novo_status ? 'Reativado' : 'Desativado';
    
    $sql = "UPDATE usuarios SET ativo = ? WHERE id = ?";
    executeQuery($sql, [$novo_status, $usuario_id]);
    
    // Registrar no log
    $sql_log = "INSERT INTO logs_sistema (usuario_id, acao, detalhes, ip_address) 
                VALUES (?, 'Usuário $acao', ?, ?)";
    executeQuery($sql_log, [
        $usuario_logado['id'],
        "Usuário {$usuario['nome']} foi $acao",
        $_SERVER['REMOTE_ADDR'] ?? 'N/A'
    ]);
    
    header("Location: visualizar_usuario.php?id={$usuario_id}&success=status");
    exit;
    
} catch (Exception $e) {
    header("Location: visualizar_usuario.php?id={$usuario_id}&erro=" . urlencode($e->getMessage()));
    exit;
}
?>