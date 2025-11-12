<?php
// api/busca_global.php - API de busca global com normalização

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/search_helpers.php';

// Verificar autenticação
if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

require_once __DIR__ . '/../config/database.php';

$query = $_GET['q'] ?? '';

// Normalizar o termo de busca (remove pontuação)
$query_normalizado = normalizarParaBusca($query);
$search_term_normalizado = "%$query_normalizado%";

if (strlen($query) < 2) {
    echo json_encode(['success' => false, 'error' => 'Query muito curta']);
    exit;
}

try {
    $search_term = "%$query%";
    $resultados = [];
    
    // Normalizar o termo de busca (remove pontuação)
    $query_normalizado = normalizarParaBusca($query);
    $search_term_normalizado = "%$query_normalizado%";
    
    // 1. Buscar PROCESSOS (com normalização)
    $sql_processos = "SELECT id, numero_processo, cliente_nome 
                     FROM processos 
                     WHERE (
                         numero_processo LIKE ? 
                         OR cliente_nome LIKE ?
                         OR REPLACE(REPLACE(REPLACE(REPLACE(numero_processo, '.', ''), '-', ''), '/', ''), ' ', '') LIKE ?
                     )
                     AND ativo = 1
                     LIMIT 5";
    $stmt = executeQuery($sql_processos, [$search_term, $search_term, $search_term_normalizado]);
    $resultados['processos'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 2. Buscar CLIENTES (com normalização de CPF/CNPJ)
    $sql_clientes = "SELECT id, nome, cpf_cnpj, email 
                    FROM clientes 
                    WHERE (
                        nome LIKE ? 
                        OR cpf_cnpj LIKE ? 
                        OR email LIKE ?
                        OR REPLACE(REPLACE(REPLACE(cpf_cnpj, '.', ''), '-', ''), '/', '') LIKE ?
                    )
                    AND ativo = 1
                    LIMIT 5";
    $stmt = executeQuery($sql_clientes, [$search_term, $search_term, $search_term, $search_term_normalizado]);
    $resultados['clientes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 3. Buscar PUBLICAÇÕES (com normalização de números de processo)
   $sql_publicacoes = "SELECT id, numero_processo_cnj, numero_processo_tj, tipo_documento, titulo
                       FROM publicacoes 
                       WHERE (
                           numero_processo_cnj LIKE ? 
                           OR numero_processo_tj LIKE ? 
                           OR conteudo LIKE ?
                           OR REPLACE(REPLACE(REPLACE(REPLACE(numero_processo_cnj, '.', ''), '-', ''), '/', ''), ' ', '') LIKE ?
                           OR REPLACE(REPLACE(REPLACE(REPLACE(numero_processo_tj, '.', ''), '-', ''), '/', ''), ' ', '') LIKE ?
                       )
                       AND deleted_at IS NULL
                       LIMIT 5";
    $stmt = executeQuery($sql_publicacoes, [
        $search_term, 
        $search_term, 
        $search_term, 
        $search_term_normalizado,
        $search_term_normalizado
    ]);
    $resultados['publicacoes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 4. Buscar TAREFAS
    $sql_tarefas = "SELECT id, titulo, status, data_vencimento
                   FROM tarefas 
                   WHERE (titulo LIKE ? OR descricao LIKE ?)
                   LIMIT 5";
    $stmt = executeQuery($sql_tarefas, [$search_term, $search_term]);
    $resultados['tarefas'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 5. Buscar PROSPECÇÕES (KANBAN) - com normalização de telefone
    $sql_prospeccoes = "SELECT p.id, p.nome, p.telefone, p.cidade, p.fase, 
                              p.valor_proposta, p.valor_fechado,
                              n.nome as nucleo_nome,
                              u.nome as responsavel_nome
                       FROM prospeccoes p
                       LEFT JOIN nucleos n ON p.nucleo_id = n.id
                       LEFT JOIN usuarios u ON p.responsavel_id = u.id
                       WHERE (
                           p.nome LIKE ? 
                           OR p.telefone LIKE ?
                           OR p.cidade LIKE ?
                           OR p.observacoes LIKE ?
                           OR REPLACE(REPLACE(REPLACE(REPLACE(p.telefone, '(', ''), ')', ''), '-', ''), ' ', '') LIKE ?
                       )
                       AND p.ativo = 1
                       LIMIT 5";
    $stmt = executeQuery($sql_prospeccoes, [
        $search_term, 
        $search_term, 
        $search_term,
        $search_term,
        $search_term_normalizado
    ]);
    $resultados['prospeccoes'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calcular total
    $total = count($resultados['processos']) + 
             count($resultados['clientes']) + 
             count($resultados['publicacoes']) + 
             count($resultados['tarefas']) +
             count($resultados['prospeccoes']);
    
    echo json_encode([
        'success' => true,
        'total' => $total,
        'processos' => $resultados['processos'],
        'clientes' => $resultados['clientes'],
        'publicacoes' => $resultados['publicacoes'],
        'tarefas' => $resultados['tarefas'],
        'prospeccoes' => $resultados['prospeccoes']
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}