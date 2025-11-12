<?php
// modules/publicacoes/api.php - VERSÃO 100% FUNCIONAL
// ✅ CORREÇÕES: Mapeamento de campos, tratamento de erros, logs detalhados

require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';
require_once '../../includes/ProcessoHistoricoHelper.php';
require_once '../../config/api.php';

header('Content-Type: application/json');

// Desabilitar output buffering
if (ob_get_level()) ob_end_clean();

$usuario_logado = Auth::user();
$usuario_id = $usuario_logado['usuario_id'];

// Pegar dados da requisição
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'get':
            $publicacao_id = $_GET['id'] ?? null;
            
            if (!$publicacao_id) {
                throw new Exception('ID não fornecido');
            }

            // Verificar permissão
            $sql_perm = "SELECT visualiza_publicacoes_nao_vinculadas FROM usuarios WHERE id = ? LIMIT 1";
            $stmt_perm = executeQuery($sql_perm, [$usuario_id]);
            $user_perm = $stmt_perm->fetch();
            $pode_ver_nao_vinculadas = $user_perm['visualiza_publicacoes_nao_vinculadas'] ?? 0;

            if (!$pode_ver_nao_vinculadas) {
                $sql_check = "SELECT p.id 
                              FROM publicacoes p
                              LEFT JOIN processos pr ON p.processo_id = pr.id
                              WHERE p.id = ? 
                              AND p.deleted_at IS NULL
                              AND (p.processo_id IS NOT NULL AND pr.responsavel_id = ?)";
                $stmt_check = executeQuery($sql_check, [$publicacao_id, $usuario_id]);
                
                if (!$stmt_check->fetch()) {
                    throw new Exception('Você não tem permissão para visualizar esta publicação');
                }
            }

            $sql = "SELECT p.*, 
                    pr.numero_processo as processo_numero,
                    pr.cliente_nome as processo_cliente,
                    u.nome as tratado_por_nome
                    FROM publicacoes p
                    LEFT JOIN processos pr ON p.processo_id = pr.id
                    LEFT JOIN usuarios u ON p.tratada_por_usuario_id = u.id
                    WHERE p.id = ? AND p.deleted_at IS NULL";
            
            $stmt = executeQuery($sql, [$publicacao_id]);
            $publicacao = $stmt->fetch();
            
            if (!$publicacao) {
                throw new Exception('Publicação não encontrada');
            }
            
            echo json_encode([
                'success' => true,
                'publicacao' => $publicacao
            ]);
            break;
            
        case 'concluir':
            $publicacao_id = $input['publicacao_id'] ?? null;
            
            if (!$publicacao_id) {
                throw new Exception('ID da publicação não fornecido');
            }

            $sql_pub = "SELECT processo_id, tipo_documento, numero_processo_cnj 
                        FROM publicacoes 
                        WHERE id = ? LIMIT 1";
            $stmt_pub = executeQuery($sql_pub, [$publicacao_id]);
            $pub = $stmt_pub->fetch();

            $sql = "UPDATE publicacoes 
                    SET status_tratamento = 'concluido',
                        tratada_por_usuario_id = ?,
                        data_tratamento = NOW()
                    WHERE id = ?";
            executeQuery($sql, [$usuario_id, $publicacao_id]);

            $sql_trat = "INSERT INTO publicacoes_tratamentos 
                         (publicacao_id, usuario_id, tipo_tratamento, observacao, data_tratamento)
                         VALUES (?, ?, 'concluido', 'Marcada como concluída sem necessidade de ação', NOW())";
            executeQuery($sql_trat, [$publicacao_id, $usuario_id]);

            if ($pub && $pub['processo_id']) {
                $doc_tipo = $pub['tipo_documento'] ?? 'Publicação';
                $num_proc = $pub['numero_processo_cnj'] ?? '';
                $descricao = "Publicação marcada como concluída - $doc_tipo";
                if ($num_proc) {
                    $descricao .= " ($num_proc)";
                }
                
                ProcessoHistoricoHelper::registrar(
                    $pub['processo_id'],
                    "Publicação Concluída",
                    $descricao,
                    'publicacao',
                    $publicacao_id
                );
            }

            echo json_encode(['success' => true, 'message' => 'Publicação concluída com sucesso']);
            break;

        case 'descartar':
            $publicacao_id = $input['publicacao_id'] ?? null;
            
            if (!$publicacao_id) {
                throw new Exception('ID da publicação não fornecido');
            }

            $sql_pub = "SELECT processo_id, tipo_documento, numero_processo_cnj 
                        FROM publicacoes 
                        WHERE id = ? LIMIT 1";
            $stmt_pub = executeQuery($sql_pub, [$publicacao_id]);
            $pub = $stmt_pub->fetch();

            $sql = "UPDATE publicacoes 
                    SET status_tratamento = 'descartado',
                        tratada_por_usuario_id = ?,
                        data_tratamento = NOW()
                    WHERE id = ?";
            executeQuery($sql, [$usuario_id, $publicacao_id]);

            $sql_trat = "INSERT INTO publicacoes_tratamentos 
                         (publicacao_id, usuario_id, tipo_tratamento, observacao, data_tratamento)
                         VALUES (?, ?, 'descartado', 'Publicação descartada (duplicada ou irrelevante)', NOW())";
            executeQuery($sql_trat, [$publicacao_id, $usuario_id]);

            if ($pub && $pub['processo_id']) {
                $doc_tipo = $pub['tipo_documento'] ?? 'Publicação';
                $num_proc = $pub['numero_processo_cnj'] ?? '';
                $descricao = "Publicação descartada - $doc_tipo";
                if ($num_proc) {
                    $descricao .= " ($num_proc)";
                }
                
                ProcessoHistoricoHelper::registrar(
                    $pub['processo_id'],
                    "Publicação Descartada",
                    $descricao,
                    'publicacao',
                    $publicacao_id
                );
            }

            echo json_encode(['success' => true, 'message' => 'Publicação descartada com sucesso']);
            break;
            
        case 'sincronizar':
            // ⚠️ CRÍTICO: Aumentar limites ANTES de tudo
            set_time_limit(300);
            ini_set('max_execution_time', 300);
            ini_set('memory_limit', '512M');
            ignore_user_abort(true);
            
            logSync("========================================");
            logSync("SINCRONIZAÇÃO INICIADA - " . date('Y-m-d H:i:s'));
            logSync("Usuário: {$usuario_logado['nome']} (ID: $usuario_id)");
            logSync("========================================");
            
            // Verificar disponibilidade da API
            $disponibilidade = apiDisponivel();
            if (!$disponibilidade['disponivel']) {
                throw new Exception($disponibilidade['motivo']);
            }
            logSync("✅ API disponível");
            
            try {
                // 1. Validar configuração
                logSync("Validando configuração...");
                if (strlen(PUBLICACOES_HASH_CLIENTE) !== 32) {
                    throw new Exception('Hash do cliente inválido');
                }
                logSync("✅ Hash validado");
                
                // 2. Buscar publicações dos últimos 7 dias
                logSync("Buscando publicações dos últimos 7 dias...");
                $resultado_api = buscarPublicacoesRecentes(7);
                
                if (!$resultado_api['success']) {
                    throw new Exception($resultado_api['message'] ?? 'Erro ao buscar publicações');
                }
                
                $publicacoes = $resultado_api['data'] ?? [];
                $total_recebidas = count($publicacoes);
                
                logSync("✅ Total recebidas: $total_recebidas");
                
                if ($total_recebidas === 0) {
                    logSync("Nenhuma publicação nova - sistema em dia");
                    
                    echo json_encode([
                        'success' => true,
                        'novas' => 0,
                        'vinculadas' => 0,
                        'duplicadas' => 0,
                        'message' => '✅ Sistema em dia! Nenhuma publicação nova.'
                    ]);
                    exit;
                }
                
                // 3. Processar publicações
                logSync("Iniciando processamento de $total_recebidas publicações...");
                
                $total_novas = 0;
                $total_duplicadas = 0;
                $total_vinculadas = 0;
                $total_erros = 0;
                $ids_processados = [];
                
                $pdo = getConnectionSafe(); // ✅ Usa conexão segura com reconexão automática
                
                foreach ($publicacoes as $index => $pub) {
                    try {
                        $num = $index + 1;
                        
                        // ✅ CORREÇÃO: Aceitar tanto idWs quanto idWS
                        $id_ws = $pub['idWs'] ?? $pub['idWS'] ?? $pub['id'] ?? null;
                        
                        if (!$id_ws) {
                            logSync("[$num/$total_recebidas] Sem ID - pulando", 'WARNING');
                            continue;
                        }
                        
                        logSync("[$num/$total_recebidas] Processando ID: $id_ws");
                        
                        // Verificar duplicata
                        $sql_check = "SELECT id FROM publicacoes WHERE id_ws = ? LIMIT 1";
                        $stmt_check = executeQuery($sql_check, [$id_ws]);
                        
                        if ($stmt_check->fetch()) {
                            logSync("  → Duplicada");
                            $total_duplicadas++;
                            $ids_processados[] = $id_ws;
                            continue;
                        }
                        
                        // ✅ MAPEAMENTO CORRETO DOS CAMPOS DA API
                        $numero_cnj = $pub['numeroProcessoCNJ'] ?? $pub['numeroCNJ'] ?? null;
                        $numero_proc = $pub['numeroProcesso'] ?? $pub['numeroPrincipal'] ?? null;
                        $tipo_doc = $pub['layout'] ?? 'Intimação';
                        $tribunal = $pub['orgao'] ?? $pub['tribunal'] ?? null;
                        $comarca = $pub['cidade'] ?? $pub['comarca'] ?? null;
                        $vara = $pub['vara'] ?? null;
                        
                        // UF: Pegar apenas o primeiro estado se vier múltiplos
                        $uf_raw = $pub['uf'] ?? null;
                        $uf = null;
                        if ($uf_raw) {
                            // Se vier "BR, SC, AC, AL..." pegar apenas o primeiro válido
                            $uf_array = array_map('trim', explode(',', $uf_raw));
                            foreach ($uf_array as $estado) {
                                if (strlen($estado) === 2 && $estado !== 'BR') {
                                    $uf = $estado;
                                    break;
                                }
                            }
                            // Se não encontrou um válido, pega o primeiro
                            if (!$uf && count($uf_array) > 0) {
                                $uf = $uf_array[0];
                            }
                        }
                        
                        // DATAS: A API retorna em formato DD/MM/YYYY ou DD/MM/YYYY HH:MM:SS
                        // data_publicacao = quando foi publicado pelo tribunal
                        // data_disponibilizacao = quando ficou disponível no webservice
                        $data_publicacao = $pub['dataDisponibilizacao'] ?? $pub['data'] ?? null;
                        $data_disponibilizacao = $pub['dataDisponibilizacaoWebservice'] ?? null;
                        
                        $conteudo = $pub['conteudo'] ?? null;
                        $polo_ativo = $pub['parte_autora'] ?? $pub['poloAtivo'] ?? null;
                        $polo_passivo = $pub['parte_reu'] ?? $pub['poloPassivo'] ?? null;
                        $md5 = $pub['md5'] ?? null;
                        
                        // Função para converter data brasileira para MySQL
                        $converterData = function($data_br) {
                            if (!$data_br) return null;
                            
                            // Tentar formato: DD/MM/YYYY HH:MM:SS
                            if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})\s+(\d{2}):(\d{2}):(\d{2})$/', $data_br, $matches)) {
                                return "{$matches[3]}-{$matches[2]}-{$matches[1]} {$matches[4]}:{$matches[5]}:{$matches[6]}";
                            }
                            
                            // Tentar formato: DD/MM/YYYY
                            if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $data_br, $matches)) {
                                return "{$matches[3]}-{$matches[2]}-{$matches[1]} 00:00:00";
                            }
                            
                            // Tentar formato: YYYY-MM-DD (já no formato correto)
                            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $data_br)) {
                                return $data_br;
                            }
                            
                            // Último recurso: tentar strtotime
                            try {
                                $timestamp = strtotime($data_br);
                                if ($timestamp !== false && $timestamp > 0) {
                                    return date('Y-m-d H:i:s', $timestamp);
                                }
                            } catch (Exception $e) {
                                // Ignorar erro
                            }
                            
                            return null;
                        };
                        
                        // Converter datas
                        $data_publicacao = $converterData($data_publicacao);
                        $data_disponibilizacao = $converterData($data_disponibilizacao);
                        
                        // Salvar JSON completo para debug
                        $json_completo = json_encode($pub, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        
                        $sql_insert = "INSERT INTO publicacoes (
                            id_ws, titulo, tipo_documento, numero_processo_cnj, numero_processo_tj,
                            tribunal, comarca, vara, uf, data_publicacao, data_disponibilizacao,
                            conteudo, polo_ativo, polo_passivo, md5_hash, json_data_completo,
                            status_tratamento, usuario_id, criado_por, created_at
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'nao_tratado', ?, ?, NOW())";
                        
                        executeQuery($sql_insert, [
                            $id_ws,
                            $tipo_doc,
                            $tipo_doc,
                            $numero_cnj,
                            $numero_proc,
                            $tribunal,
                            $comarca,
                            $vara,
                            $uf,
                            $data_publicacao,
                            $data_disponibilizacao,
                            $conteudo,
                            $polo_ativo,
                            $polo_passivo,
                            $md5,
                            $json_completo,
                            $usuario_id,
                            $usuario_id
                        ]);
                        
                        $publicacao_id = $pdo->lastInsertId();
                        $total_novas++;
                        $ids_processados[] = $id_ws;
                        
                        logSync("  ✅ Inserida (DB ID: $publicacao_id)");
                        
                        // Vincular processo
                        $numero_busca = $numero_cnj ?: $numero_proc;
                        if ($numero_busca && vincularProcesso($numero_busca, $publicacao_id)) {
                            $total_vinculadas++;
                            logSync("  ✅ Vinculada");
                        }
                        
                    } catch (Exception $e) {
                        $total_erros++;
                        logSync("  ❌ ERRO: " . $e->getMessage(), 'ERROR');
                    }
                }
                
                // 4. Marcar como processadas
                if (!empty($ids_processados)) {
                    logSync("Marcando " . count($ids_processados) . " como processadas");
                    marcarComoProcessadaAPI($ids_processados);
                }
                
                logSync("========================================");
                logSync("CONCLUÍDA COM SUCESSO");
                logSync("Novas: $total_novas | Vinculadas: $total_vinculadas | Duplicadas: $total_duplicadas | Erros: $total_erros");
                logSync("========================================");
                
                // Resposta
                $message = $total_novas > 0 
                    ? "✅ Sucesso! $total_novas nova(s) publicação(ões), $total_vinculadas vinculada(s)."
                    : "✅ Sistema em dia! Nenhuma publicação nova.";
                
                echo json_encode([
                    'success' => true,
                    'novas' => $total_novas,
                    'vinculadas' => $total_vinculadas,
                    'duplicadas' => $total_duplicadas,
                    'erros' => $total_erros,
                    'message' => $message
                ]);
                
            } catch (Exception $e) {
                logSync("========================================", 'ERROR');
                logSync("ERRO FATAL: " . $e->getMessage(), 'ERROR');
                logSync("========================================", 'ERROR');
                throw $e;
            }
            break;
            
        default:
            throw new Exception('Ação não reconhecida: ' . $action);
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>