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

// Script para criar/verificar tabelas do sistema
function criarTabelasSistema() {
    try {
        // Tabela de configurações
        $sql = "CREATE TABLE IF NOT EXISTS configuracoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            chave VARCHAR(100) NOT NULL UNIQUE,
            valor TEXT,
            descricao TEXT,
            data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            data_atualizacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        executeQuery($sql);
        
        // Tabela de logs do sistema
        $sql = "CREATE TABLE IF NOT EXISTS logs_sistema (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT,
            acao VARCHAR(100) NOT NULL,
            detalhes TEXT,
            ip_address VARCHAR(45),
            user_agent TEXT,
            data_acao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_usuario_id (usuario_id),
            INDEX idx_data_acao (data_acao),
            INDEX idx_acao (acao)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        executeQuery($sql);
        
        // Tabela de notificações
        $sql = "CREATE TABLE IF NOT EXISTS notificacoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            usuario_id INT NOT NULL,
            tipo VARCHAR(50) NOT NULL,
            titulo VARCHAR(255) NOT NULL,
            mensagem TEXT,
            link VARCHAR(500),
            lida BOOLEAN DEFAULT FALSE,
            data_criacao TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            data_leitura TIMESTAMP NULL,
            INDEX idx_usuario_id (usuario_id),
            INDEX idx_lida (lida),
            INDEX idx_data_criacao (data_criacao)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        executeQuery($sql);
        
        // Verificar e adicionar colunas que podem estar faltando
        
        // Tabela usuarios
        $colunas_usuarios = [
            'ultimo_login' => 'ADD COLUMN ultimo_login TIMESTAMP NULL',
            'ip_ultimo_login' => 'ADD COLUMN ip_ultimo_login VARCHAR(45)',
            'tentativas_login' => 'ADD COLUMN tentativas_login INT DEFAULT 0',
            'bloqueado_ate' => 'ADD COLUMN bloqueado_ate TIMESTAMP NULL'
        ];
        
        foreach ($colunas_usuarios as $coluna => $sql_add) {
            try {
                $sql_check = "SHOW COLUMNS FROM usuarios LIKE '$coluna'";
                $stmt = executeQuery($sql_check);
                if (!$stmt->fetch()) {
                    $sql_alter = "ALTER TABLE usuarios $sql_add";
                    executeQuery($sql_alter);
                }
            } catch (Exception $e) {
                // Coluna já existe ou erro, continuar
            }
        }
        
        // Tabela agenda - adicionar campos de lembrete
        $colunas_agenda = [
            'lembrete_enviado' => 'ADD COLUMN lembrete_enviado BOOLEAN DEFAULT FALSE'
        ];
        
        foreach ($colunas_agenda as $coluna => $sql_add) {
            try {
                $sql_check = "SHOW COLUMNS FROM agenda LIKE '$coluna'";
                $stmt = executeQuery($sql_check);
                if (!$stmt->fetch()) {
                    $sql_alter = "ALTER TABLE agenda $sql_add";
                    executeQuery($sql_alter);
                }
            } catch (Exception $e) {
                // Coluna já existe ou erro, continuar
            }
        }
        
        return ['success' => true, 'message' => 'Tabelas do sistema criadas/verificadas com sucesso'];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Erro ao criar tabelas: ' . $e->getMessage()];
    }
}

// Executar se chamado diretamente
if (isset($_GET['action']) && $_GET['action'] === 'install') {
    $result = criarTabelasSistema();
    
    if ($result['success']) {
        header('Location: configuracoes.php?success=' . urlencode($result['message']));
    } else {
        header('Location: configuracoes.php?erro=' . urlencode($result['message']));
    }
    exit;
}
?>