<?php
/**
 * HELPER PARA QUERIES COM SOFT DELETE
 * 
 * Adicione este arquivo em: modulos/agenda/includes/QueryHelper.php
 * 
 * Use estas funções para garantir que itens excluídos não apareçam
 */

class QueryHelper {
    
    /**
     * Adiciona filtro de deleted_at em uma query
     * 
     * @param string $sql Query SQL original
     * @param string $alias Alias da tabela (ex: 't', 'p', 'a', 'ag')
     * @return string Query com filtro adicionado
     */
    public static function addSoftDeleteFilter($sql, $alias) {
        // Verificar se já tem o filtro
        if (stripos($sql, "{$alias}.deleted_at") !== false) {
            return $sql; // Já tem o filtro
        }
        
        // Encontrar onde adicionar o filtro
        if (stripos($sql, 'WHERE') !== false) {
            // Já tem WHERE, adicionar com AND
            $sql = preg_replace(
                '/WHERE\s+/i',
                "WHERE {$alias}.deleted_at IS NULL AND ",
                $sql,
                1
            );
        } else {
            // Não tem WHERE, adicionar antes do ORDER BY ou GROUP BY
            $positions = [];
            
            if (stripos($sql, 'ORDER BY') !== false) {
                $positions[] = stripos($sql, 'ORDER BY');
            }
            if (stripos($sql, 'GROUP BY') !== false) {
                $positions[] = stripos($sql, 'GROUP BY');
            }
            if (stripos($sql, 'LIMIT') !== false) {
                $positions[] = stripos($sql, 'LIMIT');
            }
            
            if (!empty($positions)) {
                $pos = min($positions);
                $sql = substr($sql, 0, $pos) . 
                       "WHERE {$alias}.deleted_at IS NULL " . 
                       substr($sql, $pos);
            } else {
                // Adicionar no final
                $sql .= " WHERE {$alias}.deleted_at IS NULL";
            }
        }
        
        return $sql;
    }
    
    /**
     * Prepara query de tarefas com soft delete
     */
    public static function queryTarefas($whereClause = '', $orderBy = 'data_vencimento ASC') {
        $sql = "SELECT t.*, 
                       pr.numero_processo, 
                       pr.cliente_nome,
                       u.nome as responsavel_nome,
                       uc.nome as criador_nome
                FROM tarefas t
                LEFT JOIN processos pr ON t.processo_id = pr.id
                LEFT JOIN usuarios u ON t.responsavel_id = u.id
                LEFT JOIN usuarios uc ON t.criado_por = uc.id
                WHERE t.deleted_at IS NULL";
        
        if ($whereClause) {
            $sql .= " AND " . $whereClause;
        }
        
        if ($orderBy) {
            $sql .= " ORDER BY " . $orderBy;
        }
        
        return $sql;
    }
    
    /**
     * Prepara query de prazos com soft delete
     */
    public static function queryPrazos($whereClause = '', $orderBy = 'data_vencimento ASC') {
        $sql = "SELECT p.*, 
                       pr.numero_processo, 
                       pr.cliente_nome,
                       u.nome as responsavel_nome,
                       uc.nome as criador_nome
                FROM prazos p
                INNER JOIN processos pr ON p.processo_id = pr.id
                LEFT JOIN usuarios u ON p.responsavel_id = u.id
                LEFT JOIN usuarios uc ON p.criado_por = uc.id
                WHERE p.deleted_at IS NULL";
        
        if ($whereClause) {
            $sql .= " AND " . $whereClause;
        }
        
        if ($orderBy) {
            $sql .= " ORDER BY " . $orderBy;
        }
        
        return $sql;
    }
    
    /**
     * Prepara query de audiências com soft delete
     */
    public static function queryAudiencias($whereClause = '', $orderBy = 'data_inicio ASC') {
        $sql = "SELECT a.*, 
                       pr.numero_processo, 
                       pr.cliente_nome,
                       u.nome as responsavel_nome,
                       uc.nome as criador_nome
                FROM audiencias a
                INNER JOIN processos pr ON a.processo_id = pr.id
                LEFT JOIN usuarios u ON a.responsavel_id = u.id
                LEFT JOIN usuarios uc ON a.criado_por = uc.id
                WHERE a.deleted_at IS NULL";
        
        if ($whereClause) {
            $sql .= " AND " . $whereClause;
        }
        
        if ($orderBy) {
            $sql .= " ORDER BY " . $orderBy;
        }
        
        return $sql;
    }
    
    /**
     * Prepara query de agenda/eventos com soft delete
     */
    public static function queryAgenda($whereClause = '', $orderBy = 'data_inicio ASC') {
        $sql = "SELECT ag.*, 
                       pr.numero_processo,
                       c.nome as cliente_nome,
                       u.nome as criador_nome
                FROM agenda ag
                LEFT JOIN processos pr ON ag.processo_id = pr.id
                LEFT JOIN clientes c ON ag.cliente_id = c.id
                LEFT JOIN usuarios u ON ag.created_by = u.id
                WHERE ag.deleted_at IS NULL";
        
        if ($whereClause) {
            $sql .= " AND " . $whereClause;
        }
        
        if ($orderBy) {
            $sql .= " ORDER BY " . $orderBy;
        }
        
        return $sql;
    }
    
    /**
     * Query unificada para todos os tipos (para calendário/lista)
     */
    public static function queryTodosCompromissos($filtros = []) {
        $where_conditions = [];
        
        // Filtros comuns
        if (!empty($filtros['data_inicio'])) {
            $where_conditions['tarefas'][] = "t.data_vencimento >= '{$filtros['data_inicio']}'";
            $where_conditions['prazos'][] = "p.data_vencimento >= '{$filtros['data_inicio']}'";
            $where_conditions['audiencias'][] = "a.data_inicio >= '{$filtros['data_inicio']}'";
            $where_conditions['agenda'][] = "ag.data_inicio >= '{$filtros['data_inicio']}'";
        }
        
        if (!empty($filtros['data_fim'])) {
            $where_conditions['tarefas'][] = "t.data_vencimento <= '{$filtros['data_fim']}'";
            $where_conditions['prazos'][] = "p.data_vencimento <= '{$filtros['data_fim']}'";
            $where_conditions['audiencias'][] = "a.data_fim <= '{$filtros['data_fim']}'";
            $where_conditions['agenda'][] = "ag.data_fim <= '{$filtros['data_fim']}'";
        }
        
        if (!empty($filtros['status'])) {
            $status = $filtros['status'];
            $where_conditions['tarefas'][] = "t.status = '{$status}'";
            $where_conditions['prazos'][] = "p.status = '{$status}'";
            $where_conditions['audiencias'][] = "a.status = '{$status}'";
            $where_conditions['agenda'][] = "ag.status = '{$status}'";
        }
        
        // Construir WHERE clauses
        $where_tarefas = !empty($where_conditions['tarefas']) ? 
                         implode(' AND ', $where_conditions['tarefas']) : '1=1';
        $where_prazos = !empty($where_conditions['prazos']) ? 
                        implode(' AND ', $where_conditions['prazos']) : '1=1';
        $where_audiencias = !empty($where_conditions['audiencias']) ? 
                            implode(' AND ', $where_conditions['audiencias']) : '1=1';
        $where_agenda = !empty($where_conditions['agenda']) ? 
                        implode(' AND ', $where_conditions['agenda']) : '1=1';
        
        $sql = "
            -- TAREFAS
            SELECT 
                'tarefa' as tipo_compromisso,
                t.id,
                t.titulo,
                t.descricao,
                t.data_vencimento as data_compromisso,
                t.data_vencimento as data_fim,
                t.status,
                t.prioridade,
                'Tarefa' as subtipo,
                NULL as local_evento,
                pr.cliente_nome,
                pr.numero_processo,
                u.nome as responsavel_nome
            FROM tarefas t
            LEFT JOIN processos pr ON t.processo_id = pr.id
            LEFT JOIN usuarios u ON t.responsavel_id = u.id
            WHERE t.deleted_at IS NULL AND {$where_tarefas}
            
            UNION ALL
            
            -- PRAZOS
            SELECT 
                'prazo' as tipo_compromisso,
                p.id,
                p.titulo,
                p.descricao,
                p.data_vencimento as data_compromisso,
                p.data_vencimento as data_fim,
                p.status,
                p.prioridade,
                'Prazo Processual' as subtipo,
                NULL as local_evento,
                pr.cliente_nome,
                pr.numero_processo,
                u.nome as responsavel_nome
            FROM prazos p
            INNER JOIN processos pr ON p.processo_id = pr.id
            LEFT JOIN usuarios u ON p.responsavel_id = u.id
            WHERE p.deleted_at IS NULL AND {$where_prazos}
            
            UNION ALL
            
            -- AUDIÊNCIAS
            SELECT 
                'audiencia' as tipo_compromisso,
                a.id,
                a.titulo,
                a.descricao,
                a.data_inicio as data_compromisso,
                a.data_fim,
                a.status,
                a.prioridade,
                a.tipo as subtipo,
                a.local_evento,
                pr.cliente_nome,
                pr.numero_processo,
                u.nome as responsavel_nome
            FROM audiencias a
            INNER JOIN processos pr ON a.processo_id = pr.id
            LEFT JOIN usuarios u ON a.responsavel_id = u.id
            WHERE a.deleted_at IS NULL AND {$where_audiencias}
            
            UNION ALL
            
            -- AGENDA/EVENTOS
            SELECT 
                'evento' as tipo_compromisso,
                ag.id,
                ag.titulo,
                ag.descricao,
                ag.data_inicio as data_compromisso,
                ag.data_fim,
                ag.status,
                ag.prioridade,
                ag.tipo as subtipo,
                ag.local_evento,
                c.nome as cliente_nome,
                pr.numero_processo,
                NULL as responsavel_nome
            FROM agenda ag
            LEFT JOIN processos pr ON ag.processo_id = pr.id
            LEFT JOIN clientes c ON ag.cliente_id = c.id
            WHERE ag.deleted_at IS NULL AND {$where_agenda}
            
            ORDER BY data_compromisso ASC
        ";
        
        return $sql;
    }
}