<?php
/**
 * Constantes e configurações para o módulo administrativo
 * Arquivo: /includes/admin_constants.php
 */

// Níveis de acesso administrativo
define('ADMIN_LEVELS', [
    'Admin',
    'Administrador', 
    'Socio',
    'Diretor'
]);

// Função helper para verificar se usuário é admin
function isAdmin($usuario) {
    return in_array($usuario['nivel_acesso'], ADMIN_LEVELS);
}

// Função para verificar nível administrativo e redirecionar se necessário
function requireAdminAccess($usuario_logado = null) {
    if (!$usuario_logado) {
        $usuario_logado = Auth::user();
    }
    
    if (!isAdmin($usuario_logado)) {
        header('Location: ' . SITE_URL . '/modules/dashboard/?erro=Acesso negado');
        exit;
    }
    
    return $usuario_logado;
}

// Configurações de backup
define('BACKUP_DIR', '../../backups/');
define('BACKUP_RETENTION_DAYS', 30); // Manter backups por 30 dias

// Configurações de logs
define('LOG_RETENTION_DAYS', 90); // Manter logs por 90 dias por padrão
define('MAX_LOGS_PER_PAGE', 50);

// Configurações de usuários
define('MIN_PASSWORD_LENGTH', 6);
define('MAX_LOGIN_ATTEMPTS', 5);
define('SESSION_TIMEOUT_MINUTES', 30);

// Tipos de ação para logs (para padronizar)
define('LOG_ACTIONS', [
    'LOGIN' => 'Login',
    'LOGOUT' => 'Logout',
    'USER_CREATED' => 'Usuário Criado',
    'USER_UPDATED' => 'Usuário Atualizado',
    'USER_ACTIVATED' => 'Usuário Ativado',
    'USER_DEACTIVATED' => 'Usuário Desativado',
    'CONFIG_UPDATED' => 'Configurações Alteradas',
    'BACKUP_CREATED' => 'Backup Criado',
    'BACKUP_DOWNLOADED' => 'Download de Backup',
    'LOGS_CLEANED' => 'Logs Limpos',
    'CLIENT_CREATED' => 'Cliente Criado',
    'CLIENT_UPDATED' => 'Cliente Atualizado',
    'EVENT_CREATED' => 'Evento Criado',
    'EVENT_UPDATED' => 'Evento Atualizado'
]);

// Níveis de acesso válidos para o sistema
define('USER_LEVELS', [
    'Admin',
    'Administrador',
    'Socio', 
    'Diretor',
    'Usuário'
]);

// Configurações de sistema
define('SYSTEM_CONFIG_KEYS', [
    'nome_sistema',
    'email_sistema', 
    'timezone',
    'backup_automatico',
    'logs_retention_days',
    'max_login_attempts',
    'session_timeout',
    'manutencao_modo',
    'manutencao_mensagem'
]);

// Timezones permitidos
define('ALLOWED_TIMEZONES', [
    'America/Sao_Paulo' => 'São Paulo (UTC-3)',
    'America/Rio_Branco' => 'Rio Branco (UTC-5)', 
    'America/Manaus' => 'Manaus (UTC-4)',
    'America/Recife' => 'Recife (UTC-3)'
]);
?>