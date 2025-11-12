<?php
/**
 * Configuração de Módulos em Desenvolvimento
 * SIGAM - Sistema de Gestão
 */
// ========================================
// CONFIGURAÇÕES - EDITE AQUI
// ========================================
// Lista dos módulos que estão em desenvolvimento
// Adicione ou remova conforme necessário
$modulosEmDesenvolvimento = [
    //'agenda',
    //'processos',
    //'publicacoes',
    //'etiquetas',
    //'prospeccao',
    //'atendimento',
    //'clientes'
];
// Defina seu usuário admin (escolha UMA das opções abaixo)
// Opção 1: Por ID do usuário
$usuarioAdminId = 2; // Seu ID no banco de dados
// Opção 2: Por email (comente a linha acima e descomente esta)
// $usuarioAdminEmail = 'seu_email@empresa.com';

// ========================================
// FUNÇÃO DE VERIFICAÇÃO
// ========================================
/**
 * Verifica se deve mostrar a página de desenvolvimento
 * 
 * @param string $moduloAtual - Nome do módulo sendo acessado
 * @param mixed $usuarioLogado - Array, ID ou email do usuário logado
 * @return bool - TRUE se deve mostrar página de desenvolvimento
 */
function verificarModuloEmDesenvolvimento($moduloAtual, $usuarioLogado) {
    global $modulosEmDesenvolvimento, $usuarioAdminId, $usuarioAdminEmail;
    
    // ===== EXTRAI O ID E EMAIL DO USUÁRIO =====
    $userId = null;
    $userEmail = null;
    
    if (is_array($usuarioLogado)) {
        // Se for array, tenta pegar id e email
        $userId = $usuarioLogado['id'] ?? null;
        $userEmail = $usuarioLogado['email'] ?? null;
    } elseif (is_numeric($usuarioLogado)) {
        // Se for número, é o ID direto
        $userId = $usuarioLogado;
    } elseif (is_string($usuarioLogado) && filter_var($usuarioLogado, FILTER_VALIDATE_EMAIL)) {
        // Se for string e for email válido
        $userEmail = $usuarioLogado;
    }
    
    // ===== VERIFICA SE É ADMIN =====
    // Por ID
    if (isset($usuarioAdminId) && $userId == $usuarioAdminId) {
        return false; // É admin, não mostra página de desenvolvimento
    }
    
    // Por Email
    if (isset($usuarioAdminEmail) && $userEmail == $usuarioAdminEmail) {
        return false; // É admin, não mostra página de desenvolvimento
    }
    
    // ===== VERIFICA SE MÓDULO ESTÁ EM DESENVOLVIMENTO =====
    if (in_array($moduloAtual, $modulosEmDesenvolvimento)) {
        return true; // Mostra página de desenvolvimento
    }
    
    // Permite acesso normal
    return false;
}

/**
 * Função auxiliar para adicionar módulo em desenvolvimento
 */
function adicionarModuloDesenvolvimento($nomeModulo) {
    global $modulosEmDesenvolvimento;
    if (!in_array($nomeModulo, $modulosEmDesenvolvimento)) {
        $modulosEmDesenvolvimento[] = $nomeModulo;
    }
}

/**
 * Função auxiliar para remover módulo de desenvolvimento
 */
function removerModuloDesenvolvimento($nomeModulo) {
    global $modulosEmDesenvolvimento;
    $key = array_search($nomeModulo, $modulosEmDesenvolvimento);
    if ($key !== false) {
        unset($modulosEmDesenvolvimento[$key]);
    }
}

/**
 * Função para listar módulos em desenvolvimento
 */
function listarModulosDesenvolvimento() {
    global $modulosEmDesenvolvimento;
    return $modulosEmDesenvolvimento;
}
?>