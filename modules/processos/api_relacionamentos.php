<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';

header('Content-Type: application/json');

// Aceitar tanto 'action' quanto 'acao'
$action = $_GET['action'] ?? $_GET['acao'] ?? $_POST['action'] ?? '';

try {
    switch($action) {
        // BUSCAR PROCESSOS (para autocomplete)
        case 'buscar':
        case 'buscar_processos':
            $termo = $_GET['termo'] ?? '';
            
            if (strlen($termo) < 2) {
                echo json_encode(['success' => true, 'processos' => []]);
                exit;
            }
            
            // Normalizar termo para busca
            $termoNormalizado = preg_replace('/[^0-9]/', '', $termo);
            
            $sql = "SELECT 
                        p.id, 
                        p.numero_processo, 
                        p.cliente_nome,
                        p.situacao_processual,
                        p.responsavel_id,
                        u.nome as responsavel_nome,
                        n.nome as nucleo_nome
                    FROM processos p
                    LEFT JOIN usuarios u ON p.responsavel_id = u.id
                    LEFT JOIN nucleos n ON p.nucleo_id = n.id
                    WHERE (
                        p.numero_processo LIKE ? 
                        OR p.cliente_nome LIKE ?
                        OR REPLACE(REPLACE(REPLACE(REPLACE(p.numero_processo, '.', ''), '-', ''), '/', ''), ' ', '') LIKE ?
                    )
                    ORDER BY p.data_criacao DESC
                    LIMIT 10";
            
            $stmt = executeQuery($sql, [
                "%$termo%", 
                "%$termo%",
                "%$termoNormalizado%"
            ]);
            
            $processos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Retornar no formato esperado pelo novo.php
            echo json_encode([
                'success' => true,
                'processos' => $processos
            ]);
            break;
        
        // LISTAR RELACIONAMENTOS
        case 'listar':
            $processoId = intval($_GET['processo_id'] ?? 0);
            
            if (!$processoId) {
                echo json_encode([]);
                exit;
            }
            
            $sql = "SELECT 
                        pr.id,
                        pr.processo_origem_id,
                        pr.processo_destino_id,
                        pr.tipo_relacionamento,
                        pr.descricao,
                        p_origem.numero_processo as numero_processo_origem,
                        p_destino.numero_processo as numero_processo_destino
                    FROM processo_relacionamentos pr
                    INNER JOIN processos p_origem ON pr.processo_origem_id = p_origem.id
                    INNER JOIN processos p_destino ON pr.processo_destino_id = p_destino.id
                    WHERE pr.processo_origem_id = ? OR pr.processo_destino_id = ?
                    ORDER BY pr.id DESC";
            
            $stmt = executeQuery($sql, [$processoId, $processoId]);
            $relacionamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode($relacionamentos);
            break;
            
        // ADICIONAR RELACIONAMENTO
        case 'adicionar':
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (!$data) {
                throw new Exception('Dados inválidos');
            }
            
            $sql = "INSERT INTO processo_relacionamentos 
                    (processo_origem_id, processo_destino_id, tipo_relacionamento, descricao) 
                    VALUES (?, ?, ?, ?)";
            
            $stmt = executeQuery($sql, [
                $data['processo_origem_id'],
                $data['processo_destino_id'],
                $data['tipo_relacionamento'],
                $data['descricao'] ?? null
            ]);
            
            echo json_encode([
                'success' => true, 
                'id' => getConnection()->lastInsertId()
            ]);
            break;
            
        // REMOVER RELACIONAMENTO
        case 'remover':
            $input = file_get_contents('php://input');
            $data = json_decode($input, true);
            
            if (!$data || !isset($data['relacionamento_id'])) {
                throw new Exception('ID não informado');
            }
            
            $sql = "DELETE FROM processo_relacionamentos WHERE id = ?";
            executeQuery($sql, [$data['relacionamento_id']]);
            
            echo json_encode(['success' => true]);
            break;
            
        default:
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => 'Ação inválida: ' . $action
            ]);
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}