<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';

// Verificar se está em modo de impersonação
if (!isset($_SESSION['admin_impersonating'])) {
    $_SESSION['erro'] = 'Você não está em modo de impersonação';
    header('Location: ../../modules/dashboard/');
    exit;
}

try {
    $admin_data = $_SESSION['admin_impersonating'];
    
    // Registrar log antes de voltar
    Auth::log('Fim de Impersonação', "Voltando para conta de admin: {$admin_data['admin_nome']}");
    
    // Remover sessão do usuário alvo
    if (isset($_SESSION['token'])) {
        $sql = "DELETE FROM sessoes WHERE token = ?";
        executeQuery($sql, [$_SESSION['token']]);
    }
    
    // Restaurar dados do admin
    $_SESSION['usuario_id'] = $admin_data['admin_id'];
    $_SESSION['nome'] = $admin_data['admin_nome'];
    $_SESSION['email'] = $admin_data['admin_email'];
    $_SESSION['nivel_acesso'] = $admin_data['admin_nivel'];
    $_SESSION['token'] = $admin_data['admin_token'];
    
    // Buscar nucleos do admin
    $sql = "SELECT GROUP_CONCAT(nucleo_id) as nucleos FROM usuarios_nucleos WHERE usuario_id = ?";
    $stmt = executeQuery($sql, [$admin_data['admin_id']]);
    $result = $stmt->fetch();
    $_SESSION['nucleos'] = explode(',', $result['nucleos'] ?? '');
    
    // Buscar acesso financeiro do admin
    $sql = "SELECT acesso_financeiro FROM usuarios WHERE id = ?";
    $stmt = executeQuery($sql, [$admin_data['admin_id']]);
    $result = $stmt->fetch();
    $_SESSION['acesso_financeiro'] = $result['acesso_financeiro'] ?? 'Nenhum';
    
    // Remover dados de impersonação
    unset($_SESSION['admin_impersonating']);
    
    // Atualizar última atividade da sessão do admin
    $sql = "UPDATE sessoes SET ultima_atividade = NOW() WHERE token = ?";
    executeQuery($sql, [$admin_data['admin_token']]);
    
    $_SESSION['sucesso'] = '✅ Você voltou para sua conta de administrador';
    header('Location: ../../modules/admin/usuarios.php');
    exit;
    
} catch (Exception $e) {
    $_SESSION['erro'] = 'Erro ao voltar para conta de admin: ' . $e->getMessage();
    header('Location: ../../modules/dashboard/');
    exit;
}
?>