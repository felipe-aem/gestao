<?php
require_once '../../includes/auth.php';
Auth::protect(['Admin']); // APENAS Admin pode fazer isso

require_once '../../config/database.php';

$usuario_id = $_GET['id'] ?? 0;

if (!$usuario_id) {
    $_SESSION['erro'] = 'Usuário não especificado';
    header('Location: index.php');
    exit;
}

// Buscar dados do usuário alvo
$sql = "SELECT u.*, GROUP_CONCAT(un.nucleo_id) as nucleos 
        FROM usuarios u 
        LEFT JOIN usuarios_nucleos un ON u.id = un.usuario_id 
        WHERE u.id = ? AND u.ativo = 1 
        GROUP BY u.id";

$stmt = executeQuery($sql, [$usuario_id]);
$usuario_alvo = $stmt->fetch();

if (!$usuario_alvo) {
    $_SESSION['erro'] = 'Usuário não encontrado ou inativo';
    header('Location: index.php');
    exit;
}

try {
    // Salvar dados do admin para poder voltar depois
    $_SESSION['admin_impersonating'] = [
        'admin_id' => $_SESSION['usuario_id'],
        'admin_nome' => $_SESSION['nome'],
        'admin_email' => $_SESSION['email'],
        'admin_nivel' => $_SESSION['nivel_acesso'],
        'admin_token' => $_SESSION['token']
    ];
    
    // Gerar novo token para o usuário alvo
    $token = bin2hex(random_bytes(32));
    
    // Criar sessão do usuário alvo no banco
    $insertSql = "INSERT INTO sessoes (usuario_id, token, ip_address, user_agent) 
                  VALUES (?, ?, ?, ?)";
    executeQuery($insertSql, [
        $usuario_alvo['id'],
        $token,
        $_SERVER['REMOTE_ADDR'] ?? '',
        $_SERVER['HTTP_USER_AGENT'] ?? ''
    ]);
    
    // Registrar o log com o admin
    Auth::log('Impersonação', "Admin acessou conta do usuário: {$usuario_alvo['nome']} (ID: {$usuario_alvo['id']})");
    
    // Atualizar sessão PHP para o usuário alvo
    $_SESSION['usuario_id'] = $usuario_alvo['id'];
    $_SESSION['nome'] = $usuario_alvo['nome'];
    $_SESSION['email'] = $usuario_alvo['email'];
    $_SESSION['nivel_acesso'] = $usuario_alvo['nivel_acesso'];
    $_SESSION['acesso_financeiro'] = $usuario_alvo['acesso_financeiro'] ?? 'Nenhum';
    $_SESSION['nucleos'] = explode(',', $usuario_alvo['nucleos'] ?? '');
    $_SESSION['token'] = $token;
    
    // Redirecionar para o dashboard
    $_SESSION['sucesso'] = "🎭 Você está agora navegando como: <strong>{$usuario_alvo['nome']}</strong><br><small>Clique em 'Voltar para Admin' no menu para retornar à sua conta</small>";
    header('Location: ../../modules/dashboard/');
    exit;
    
} catch (Exception $e) {
    $_SESSION['erro'] = 'Erro ao fazer login como usuário: ' . $e->getMessage();
    header('Location: index.php');
    exit;
}
?>