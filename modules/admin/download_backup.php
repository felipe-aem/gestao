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

$filename = $_GET['file'] ?? '';

if (empty($filename)) {
    header('Location: configuracoes.php?erro=Arquivo não especificado');
    exit;
}

// Validar nome do arquivo (segurança)
if (!preg_match('/^backup_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{2}-[0-9]{2}-[0-9]{2}\.sql$/', $filename)) {
    header('Location: configuracoes.php?erro=Nome de arquivo inválido');
    exit;
}

$backup_dir = '../../backups/';
$filepath = $backup_dir . $filename;

if (!file_exists($filepath)) {
    header('Location: configuracoes.php?erro=Arquivo não encontrado');
    exit;
}

try {
    // Registrar download no log
    $sql_log = "INSERT INTO logs_sistema (usuario_id, acao, detalhes, ip_address) 
                VALUES (?, 'Download de Backup', ?, ?)";
    executeQuery($sql_log, [
        $usuario_logado['id'],
        "Download do backup: $filename",
        $_SERVER['REMOTE_ADDR'] ?? 'N/A'
    ]);
    
    // Forçar download
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filepath));
    header('Pragma: no-cache');
    header('Expires: 0');
    
    readfile($filepath);
    
} catch (Exception $e) {
    header('Location: configuracoes.php?erro=' . urlencode($e->getMessage()));
    exit;
}
?>