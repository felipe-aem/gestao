<?php
/**
 * Gerenciamento de Relacionamentos entre Processos
 * 
 * Funções para criar, editar, remover e listar relacionamentos
 * entre processos (agravos, recursos, embargos, etc)
 */

require_once __DIR__ . '/../../config/database.php';

class ProcessoRelacionamento {
    
    /**
     * Criar novo relacionamento entre processos
     */
    public static function criar($processo_origem_id, $processo_destino_id, $tipo_relacionamento, $descricao = null, $usuario_id) {
        // Validar que não está vinculando o processo a ele mesmo
        if ($processo_origem_id == $processo_destino_id) {
            return [
                'success' => false,
                'message' => 'Não é possível vincular um processo a ele mesmo.'
            ];
        }
        
        // Verificar se ambos os processos existem
        $sql = "SELECT id FROM processos WHERE id IN (?, ?) AND deleted_at IS NULL";
        $stmt = executeQuery($sql, [$processo_origem_id, $processo_destino_id]);
        $processos = $stmt->fetchAll();
        
        if (count($processos) != 2) {
            return [
                'success' => false,
                'message' => 'Um ou ambos os processos não foram encontrados.'
            ];
        }
        
        // Verificar se o relacionamento já existe
        $sql = "SELECT id FROM processo_relacionamentos 
                WHERE processo_origem_id = ? 
                AND processo_destino_id = ? 
                AND tipo_relacionamento = ?
                AND deleted_at IS NULL";
        $stmt = executeQuery($sql, [$processo_origem_id, $processo_destino_id, $tipo_relacionamento]);
        
        if ($stmt->rowCount() > 0) {
            return [
                'success' => false,
                'message' => 'Este relacionamento já existe entre os processos.'
            ];
        }
        
        // Criar relacionamento
        try {
            $sql = "INSERT INTO processo_relacionamentos 
                    (processo_origem_id, processo_destino_id, tipo_relacionamento, descricao, criado_por)
                    VALUES (?, ?, ?, ?, ?)";
            
            executeQuery($sql, [
                $processo_origem_id,
                $processo_destino_id,
                $tipo_relacionamento,
                $descricao,
                $usuario_id
            ]);
            
            return [
                'success' => true,
                'message' => 'Relacionamento criado com sucesso!',
                'relacionamento_id' => getLastInsertId()
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Erro ao criar relacionamento: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Atualizar relacionamento existente
     */
    public static function atualizar($relacionamento_id, $tipo_relacionamento, $descricao = null, $usuario_id) {
        try {
            $sql = "UPDATE processo_relacionamentos 
                    SET tipo_relacionamento = ?,
                        descricao = ?,
                        atualizado_por = ?,
                        data_atualizacao = NOW()
                    WHERE id = ? 
                    AND deleted_at IS NULL";
            
            executeQuery($sql, [
                $tipo_relacionamento,
                $descricao,
                $usuario_id,
                $relacionamento_id
            ]);
            
            return [
                'success' => true,
                'message' => 'Relacionamento atualizado com sucesso!'
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Erro ao atualizar relacionamento: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Remover relacionamento (soft delete)
     */
    public static function remover($relacionamento_id, $usuario_id) {
        try {
            $sql = "UPDATE processo_relacionamentos 
                    SET deleted_at = NOW(),
                        atualizado_por = ?
                    WHERE id = ? 
                    AND deleted_at IS NULL";
            
            $stmt = executeQuery($sql, [$usuario_id, $relacionamento_id]);
            
            if ($stmt->rowCount() > 0) {
                return [
                    'success' => true,
                    'message' => 'Relacionamento removido com sucesso!'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Relacionamento não encontrado.'
                ];
            }
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Erro ao remover relacionamento: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Listar todos os relacionamentos de um processo (bidirecional)
     */
    public static function listarPorProcesso($processo_id) {
        $sql = "SELECT * FROM vw_processos_relacionamentos_completos
                WHERE processo_origem_id = ?
                ORDER BY data_criacao DESC";
        
        $stmt = executeQuery($sql, [$processo_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Buscar relacionamento específico
     */
    public static function buscarPorId($relacionamento_id) {
        $sql = "SELECT r.*,
                po.numero_processo as numero_processo_origem,
                po.cliente_nome as cliente_origem,
                pd.numero_processo as numero_processo_destino,
                pd.cliente_nome as cliente_destino,
                u.nome as criado_por_nome
                FROM processo_relacionamentos r
                INNER JOIN processos po ON r.processo_origem_id = po.id
                INNER JOIN processos pd ON r.processo_destino_id = pd.id
                LEFT JOIN usuarios u ON r.criado_por = u.id
                WHERE r.id = ? AND r.deleted_at IS NULL";
        
        $stmt = executeQuery($sql, [$relacionamento_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Contar relacionamentos de um processo
     */
    public static function contarPorProcesso($processo_id) {
        $sql = "SELECT 
                (SELECT COUNT(*) FROM processo_relacionamentos 
                 WHERE processo_origem_id = ? AND deleted_at IS NULL) as processos_derivados,
                (SELECT COUNT(*) FROM processo_relacionamentos 
                 WHERE processo_destino_id = ? AND deleted_at IS NULL) as processos_origem";
        
        $stmt = executeQuery($sql, [$processo_id, $processo_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Buscar processos para autocomplete (usado no formulário)
     */
    public static function buscarProcessosParaVinculo($termo, $processo_atual_id = null, $limit = 20) {
        $termo_busca = "%{$termo}%";
        $params = [$termo_busca, $termo_busca, $termo_busca];
        
        $where_extra = "";
        if ($processo_atual_id) {
            $where_extra = " AND p.id != ?";
            $params[] = $processo_atual_id;
        }
        
        $sql = "SELECT 
                p.id,
                p.numero_processo,
                p.cliente_nome,
                p.situacao_processual,
                n.nome as nucleo_nome,
                u.nome as responsavel_nome
                FROM processos p
                LEFT JOIN nucleos n ON p.nucleo_id = n.id
                LEFT JOIN usuarios u ON p.responsavel_id = u.id
                WHERE p.deleted_at IS NULL
                AND (
                    p.numero_processo LIKE ? 
                    OR p.cliente_nome LIKE ?
                    OR p.parte_contraria LIKE ?
                )
                {$where_extra}
                ORDER BY p.data_criacao DESC
                LIMIT ?";
        
        $params[] = $limit;
        
        $stmt = executeQuery($sql, $params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Obter hierarquia completa de processos (árvore)
     * Útil para visualização em árvore
     */
    public static function obterHierarquia($processo_id, $nivel = 0, &$visitados = []) {
        // Evitar loop infinito
        if (in_array($processo_id, $visitados)) {
            return [];
        }
        $visitados[] = $processo_id;
        
        // Buscar processo atual
        $sql = "SELECT id, numero_processo, cliente_nome, tipo_processo, situacao_processual
                FROM processos 
                WHERE id = ? AND deleted_at IS NULL";
        $stmt = executeQuery($sql, [$processo_id]);
        $processo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$processo) {
            return [];
        }
        
        $processo['nivel'] = $nivel;
        $processo['filhos'] = [];
        
        // Buscar processos derivados (filhos)
        $sql = "SELECT r.*, p.numero_processo, p.cliente_nome, p.tipo_processo, p.situacao_processual
                FROM processo_relacionamentos r
                INNER JOIN processos p ON r.processo_destino_id = p.id
                WHERE r.processo_origem_id = ? 
                AND r.deleted_at IS NULL
                AND p.deleted_at IS NULL
                ORDER BY r.data_criacao";
        
        $stmt = executeQuery($sql, [$processo_id]);
        $filhos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($filhos as $filho) {
            $filho_completo = self::obterHierarquia($filho['processo_destino_id'], $nivel + 1, $visitados);
            if ($filho_completo) {
                $filho_completo['tipo_relacionamento'] = $filho['tipo_relacionamento'];
                $filho_completo['descricao_relacionamento'] = $filho['descricao'];
                $processo['filhos'][] = $filho_completo;
            }
        }
        
        return $processo;
    }
    
    /**
     * Obter tipos de relacionamento mais usados (para autocomplete)
     */
    public static function tiposMaisUsados($limit = 20) {
        $sql = "SELECT tipo_relacionamento, COUNT(*) as total
                FROM processo_relacionamentos
                WHERE deleted_at IS NULL
                GROUP BY tipo_relacionamento
                ORDER BY total DESC, tipo_relacionamento ASC
                LIMIT ?";
        
        $stmt = executeQuery($sql, [$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Verificar se existe relacionamento circular (A -> B -> C -> A)
     * Evita criar loops infinitos
     */
    public static function verificarCircular($processo_origem_id, $processo_destino_id, $max_depth = 10) {
        $visitados = [];
        return self::_verificarCircularRecursivo($processo_destino_id, $processo_origem_id, $visitados, 0, $max_depth);
    }
    
    private static function _verificarCircularRecursivo($atual, $alvo, &$visitados, $depth, $max_depth) {
        if ($depth > $max_depth) {
            return true; // Considera circular se muito profundo
        }
        
        if ($atual == $alvo) {
            return true; // Encontrou loop
        }
        
        if (in_array($atual, $visitados)) {
            return false; // Já visitado, mas não é o alvo
        }
        
        $visitados[] = $atual;
        
        // Buscar filhos
        $sql = "SELECT processo_destino_id 
                FROM processo_relacionamentos 
                WHERE processo_origem_id = ? 
                AND deleted_at IS NULL";
        $stmt = executeQuery($sql, [$atual]);
        $filhos = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($filhos as $filho) {
            if (self::_verificarCircularRecursivo($filho, $alvo, $visitados, $depth + 1, $max_depth)) {
                return true;
            }
        }
        
        return false;
    }
}

/**
 * Função auxiliar para obter último ID inserido
 */
function getLastInsertId() {
    global $pdo;
    return $pdo->lastInsertId();
}