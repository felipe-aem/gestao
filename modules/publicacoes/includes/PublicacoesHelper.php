<?php
/**
 * PublicacoesHelper
 * Funções auxiliares para tratamento de publicações
 */

class PublicacoesHelper {
    
    /**
     * Verificar se usuário pode ver publicações não vinculadas
     */
    public static function podeVerNaoVinculadas($usuario_id) {
        try {
            $sql = "SELECT pode_ver_publicacoes_nao_vinculadas FROM usuarios WHERE id = ?";
            $stmt = executeQuery($sql, [$usuario_id]);
            $user = $stmt->fetch();
            
            return $user && $user['pode_ver_publicacoes_nao_vinculadas'] == 1;
        } catch (Exception $e) {
            error_log("Erro ao verificar permissão: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Verificar se processo está bloqueado
     */
    public static function processoBloqueado($numero_processo) {
        try {
            // Limpar número do processo (apenas números)
            $numero_limpo = preg_replace('/[^0-9]/', '', $numero_processo);
            
            if (empty($numero_limpo)) {
                return false;
            }
            
            $sql = "SELECT id FROM processos_bloqueados 
                    WHERE numero_processo_limpo = ? 
                    LIMIT 1";
            $stmt = executeQuery($sql, [$numero_limpo]);
            
            return $stmt->fetch() !== false;
            
        } catch (Exception $e) {
            error_log("Erro ao verificar bloqueio: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Bloquear processo
     */
    public static function bloquearProcesso($numero_processo, $usuario_id, $motivo = null) {
        try {
            $pdo = getConnection();
            
            // Limpar número
            $numero_limpo = preg_replace('/[^0-9]/', '', $numero_processo);
            
            if (empty($numero_limpo)) {
                throw new Exception("Número de processo inválido");
            }
            
            // Verificar se já está bloqueado
            if (self::processoBloqueado($numero_processo)) {
                throw new Exception("Este processo já está bloqueado");
            }
            
            // Inserir bloqueio
            $sql = "INSERT INTO processos_bloqueados (
                        numero_processo, 
                        numero_processo_limpo, 
                        bloqueado_por, 
                        motivo
                    ) VALUES (?, ?, ?, ?)";
            
            executeQuery($sql, [
                $numero_processo,
                $numero_limpo,
                $usuario_id,
                $motivo
            ]);
            
            $bloqueio_id = $pdo->lastInsertId();
            
            // Descartar todas as publicações pendentes deste processo
            $sql_update = "UPDATE publicacoes 
                          SET status_tratamento = 'descartado',
                              tratada_por_usuario_id = ?,
                              data_tratamento = NOW(),
                              motivo_descarte = ?
                          WHERE (numero_processo_cnj = ? OR numero_processo_tj = ?)
                          AND status_tratamento = 'nao_tratado'
                          AND deleted_at IS NULL";
            
            executeQuery($sql_update, [
                $usuario_id,
                "Processo bloqueado: " . ($motivo ?? 'Sem motivo informado'),
                $numero_processo,
                $numero_processo
            ]);
            
            // Registrar no histórico
            $sql_pubs = "SELECT id FROM publicacoes 
                        WHERE (numero_processo_cnj = ? OR numero_processo_tj = ?)
                        AND status_tratamento = 'descartado'
                        AND deleted_at IS NULL";
            $stmt_pubs = executeQuery($sql_pubs, [$numero_processo, $numero_processo]);
            
            while ($pub = $stmt_pubs->fetch()) {
                $sql_hist = "INSERT INTO publicacoes_historico (
                                publicacao_id, 
                                usuario_id, 
                                acao, 
                                detalhes,
                                processo_bloqueado_id
                            ) VALUES (?, ?, 'processo_bloqueado', ?, ?)";
                
                executeQuery($sql_hist, [
                    $pub['id'],
                    $usuario_id,
                    "Processo bloqueado: " . ($motivo ?? 'Sem motivo'),
                    $bloqueio_id
                ]);
            }
            
            return [
                'success' => true,
                'bloqueio_id' => $bloqueio_id,
                'message' => 'Processo bloqueado com sucesso'
            ];
            
        } catch (Exception $e) {
            error_log("Erro ao bloquear processo: " . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Registrar tratamento de publicação
     */
    public static function registrarTratamento($publicacao_id, $usuario_id, $tipo, $item_id = null, $detalhes = null) {
        try {
            $acoes_validas = [
                'tarefa' => 'vinculada_tarefa',
                'prazo' => 'vinculada_prazo',
                'audiencia' => 'vinculada_audiencia',
                'descartada' => 'descartada'
            ];
            
            if (!isset($acoes_validas[$tipo])) {
                throw new Exception("Tipo de ação inválida");
            }
            
            $acao = $acoes_validas[$tipo];
            
            // Preparar dados
            $campo_id = null;
            $valor_id = null;
            
            if ($tipo === 'tarefa') {
                $campo_id = 'tarefa_id';
                $valor_id = $item_id;
            } elseif ($tipo === 'prazo') {
                $campo_id = 'prazo_id';
                $valor_id = $item_id;
            } elseif ($tipo === 'audiencia') {
                $campo_id = 'audiencia_id';
                $valor_id = $item_id;
            }
            
            // Inserir no histórico
            $sql = "INSERT INTO publicacoes_historico (
                        publicacao_id,
                        usuario_id,
                        acao,
                        detalhes,
                        " . ($campo_id ? "$campo_id," : "") . "
                        created_at
                    ) VALUES (?, ?, ?, ?, " . ($campo_id ? "?," : "") . " NOW())";
            
            $params = [$publicacao_id, $usuario_id, $acao, $detalhes];
            if ($valor_id) {
                $params[] = $valor_id;
            }
            
            executeQuery($sql, $params);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Erro ao registrar tratamento: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Buscar histórico de tratamento
     */
    public static function buscarHistorico($filtros = []) {
        try {
            $sql = "SELECT 
                        ph.id,
                        ph.publicacao_id,
                        ph.usuario_id,
                        u.nome as usuario_nome,
                        u.nucleo_id,
                        n.nome as nucleo_nome,
                        ph.acao,
                        ph.detalhes,
                        ph.tarefa_id,
                        ph.prazo_id,
                        ph.audiencia_id,
                        ph.processo_bloqueado_id,
                        ph.created_at,
                        p.titulo as publicacao_titulo,
                        p.numero_processo_cnj,
                        p.numero_processo_tj,
                        p.processo_id,
                        CASE 
                            WHEN ph.acao = 'tratada' THEN '✅ Tratada'
                            WHEN ph.acao = 'descartada' THEN '🗑️ Descartada'
                            WHEN ph.acao = 'processo_bloqueado' THEN '🚫 Processo Bloqueado'
                            WHEN ph.acao = 'vinculada_tarefa' THEN '📝 Vinculada a Tarefa'
                            WHEN ph.acao = 'vinculada_prazo' THEN '⏰ Vinculada a Prazo'
                            WHEN ph.acao = 'vinculada_audiencia' THEN '📅 Vinculada a Audiência'
                            ELSE ph.acao
                        END as acao_formatada
                    FROM publicacoes_historico ph
                    INNER JOIN usuarios u ON ph.usuario_id = u.id
                    LEFT JOIN nucleos n ON u.nucleo_id = n.id
                    LEFT JOIN publicacoes p ON ph.publicacao_id = p.id
                    WHERE 1=1";
            
            $params = [];
            
            // Filtro por usuário
            if (!empty($filtros['usuario_id'])) {
                $sql .= " AND ph.usuario_id = ?";
                $params[] = $filtros['usuario_id'];
            }
            
            // Filtro por núcleo
            if (!empty($filtros['nucleo_id'])) {
                $sql .= " AND u.nucleo_id = ?";
                $params[] = $filtros['nucleo_id'];
            }
            
            // Filtro por ação
            if (!empty($filtros['acao'])) {
                $sql .= " AND ph.acao = ?";
                $params[] = $filtros['acao'];
            }
            
            // Filtro por data
            if (!empty($filtros['data_inicio'])) {
                $sql .= " AND DATE(ph.created_at) >= ?";
                $params[] = $filtros['data_inicio'];
            }
            
            if (!empty($filtros['data_fim'])) {
                $sql .= " AND DATE(ph.created_at) <= ?";
                $params[] = $filtros['data_fim'];
            }
            
            $sql .= " ORDER BY ph.created_at DESC";
            
            if (!empty($filtros['limit'])) {
                $sql .= " LIMIT ?";
                $params[] = (int)$filtros['limit'];
            }
            
            $stmt = executeQuery($sql, $params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Erro ao buscar histórico: " . $e->getMessage());
            return [];
        }
    }
}