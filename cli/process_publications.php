<?php
/**
 * Script de Sincronização de Publicações - VERSÃO DEFINITIVA
 * Arquivo: cli/process_publications.php
 * 
 * CORREÇÕES APLICADAS baseadas em orientação do SUPORTE:
 * ✅ Usa index_pe.php (endpoint oficial para sincronização)
 * ✅ Busca por data (parâmetro obrigatório)
 * ✅ processadas='N' (não processadas)
 * ✅ Controle de rate limit (12 req/hora, min 5min)
 * ✅ Verificação de disponibilidade (domingo e horário)
 * ✅ Busca últimos 7 dias (configurável)
 */

// Permitir execução via CLI ou Web
if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/../includes/auth.php';
    Auth::protect();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/api.php';

// CONFIGURAÇÃO
define('DIAS_RETROATIVOS', 7); // Quantos dias buscar (recomendado: 7-15)

// Função para logar mensagens
function logMessage($message, $type = 'INFO') {
    $timestamp = date('Y-m-d H:i:s');
    $log = "[$timestamp] [$type] $message\n";
    
    $log_file = __DIR__ . '/../logs/sincronizacao_publicacoes.log';
    $log_dir = dirname($log_file);
    
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    file_put_contents($log_file, $log, FILE_APPEND);
    
    if (php_sapi_name() === 'cli') {
        echo $log;
    }
}

// Função para registrar no banco de dados
function registrarLog($status, $total_novas, $mensagem, $detalhes = null) {
    global $pdo;
    
    try {
        $sql_check = "SHOW TABLES LIKE 'publicacoes_sincronizacao_log'";
        $stmt = $pdo->query($sql_check);
        
        if ($stmt->rowCount() == 0) {
            $sql_create = "CREATE TABLE IF NOT EXISTS publicacoes_sincronizacao_log (
                id INT AUTO_INCREMENT PRIMARY KEY,
                status VARCHAR(20) NOT NULL,
                total_novas INT DEFAULT 0,
                mensagem TEXT,
                detalhes TEXT,
                criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_criado_em (criado_em),
                INDEX idx_status (status)
            )";
            $pdo->exec($sql_create);
        }
        
        $sql = "INSERT INTO publicacoes_sincronizacao_log (status, total_novas, mensagem, detalhes, criado_em)
                VALUES (?, ?, ?, ?, NOW())";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$status, $total_novas, $mensagem, $detalhes]);
        
    } catch (Exception $e) {
        logMessage("Erro ao registrar log no banco: " . $e->getMessage(), 'ERROR');
    }
}

try {
    logMessage("========================================");
    logMessage("SINCRONIZAÇÃO DE PUBLICAÇÕES - v2.0");
    logMessage("Método: index_pe.php (recomendado pelo suporte)");
    logMessage("========================================");
    
    // ==========================================
    // VERIFICAÇÕES INICIAIS
    // ==========================================
    
    // 1. Verificar hash
    if (!defined('PUBLICACOES_HASH_CLIENTE')) {
        throw new Exception('PUBLICACOES_HASH_CLIENTE não configurado em config/api.php');
    }
    
    if (!validarHashCliente()) {
        throw new Exception('Hash do cliente inválido (deve ter 32 caracteres)');
    }
    
    $hash_preview = substr(PUBLICACOES_HASH_CLIENTE, 0, 10) . '...';
    logMessage("Hash Cliente: $hash_preview");
    
    // 2. Verificar disponibilidade (domingo e horário)
    $disponibilidade = apiDisponivel();
    if (!$disponibilidade['disponivel']) {
        $msg = $disponibilidade['motivo'];
        logMessage($msg, 'INFO');
        registrarLog('info', 0, $msg);
        logMessage("========================================");
        exit(0);
    }
    
    logMessage("✓ API disponível");
    
    // 3. Verificar conexão com banco
    $pdo = getConnection();
    logMessage("✓ Conexão com banco OK");
    
    // ==========================================
    // BUSCAR PUBLICAÇÕES DOS ÚLTIMOS X DIAS
    // ==========================================
    
    logMessage("Buscando publicações dos últimos " . DIAS_RETROATIVOS . " dias...");
    
    $publicacoes_todas = [];
    $total_por_data = [];
    $datas_com_erro = [];
    
    for ($i = 0; $i <= DIAS_RETROATIVOS; $i++) {
        $data = date('Y-m-d', strtotime("-$i days"));
        
        try {
            logMessage("  Consultando: $data");
            
            // Buscar publicações desta data
            $resultado = buscarPublicacoesPorData($data, 'N'); // N = não processadas
            
            if (!$resultado['success']) {
                $datas_com_erro[] = $data;
                logMessage("    ⚠️ Erro: " . $resultado['message'], 'WARNING');
                continue;
            }
            
            if (!empty($resultado['data'])) {
                $total = $resultado['total'];
                $total_por_data[$data] = $total;
                $publicacoes_todas = array_merge($publicacoes_todas, $resultado['data']);
                logMessage("    ✓ $total publicações encontradas");
            } else {
                logMessage("    - Nenhuma publicação");
            }
            
        } catch (Exception $e) {
            $datas_com_erro[] = $data;
            logMessage("    ✗ Erro: " . $e->getMessage(), 'ERROR');
        }
    }
    
    // ==========================================
    // REMOVER DUPLICATAS
    // ==========================================
    
    $publicacoes_unicas = [];
    $ids_vistos = [];
    
    foreach ($publicacoes_todas as $pub) {
        $id = $pub['idWs'] ?? $pub['idWS'] ?? $pub['id'] ?? null;
        if ($id && !in_array($id, $ids_vistos)) {
            $publicacoes_unicas[] = $pub;
            $ids_vistos[] = $id;
        }
    }
    
    $total_recebidas = count($publicacoes_unicas);
    logMessage("Total de publicações únicas: $total_recebidas");
    
    if ($total_recebidas === 0) {
        $msg = "✓ Nenhuma publicação nova (sistema em dia)";
        logMessage($msg);
        registrarLog('sucesso', 0, $msg, json_encode(['datas_consultadas' => DIAS_RETROATIVOS]));
        logMessage("========================================");
        exit(0);
    }
    
    // ==========================================
    // PROCESSAR PUBLICAÇÕES
    // ==========================================
    
    logMessage("Processando publicações...");
    
    $total_novas = 0;
    $total_duplicadas = 0;
    $total_vinculadas = 0;
    $erros = 0;
    $ids_processados = [];
    
    foreach ($publicacoes_unicas as $pub) {
        try {
            // ID único da publicação
            $id_ws = $pub['idWs'] ?? $pub['idWS'] ?? $pub['id'] ?? null;
            
            if (!$id_ws) {
                logMessage("  ⚠ Publicação sem idWs, ignorando", 'WARNING');
                continue;
            }
            
            // Verificar duplicidade
            $sql_check = "SELECT id FROM publicacoes WHERE id_ws = ? LIMIT 1";
            $stmt_check = $pdo->prepare($sql_check);
            $stmt_check->execute([$id_ws]);
            
            if ($stmt_check->fetch()) {
                $total_duplicadas++;
                $ids_processados[] = $id_ws;
                continue;
            }
            
            // Extrair dados (suportar variações de nomes nos campos)
            $numero_cnj = $pub['numeroProcessoCNJ'] ?? $pub['numero_cnj'] ?? null;
            $numero_proc = $pub['numeroProcesso'] ?? $pub['numero_processo'] ?? null;
            $tipo_doc = $pub['layout'] ?? $pub['tipo_documento'] ?? 'Intimação';
            $tribunal = $pub['orgao'] ?? $pub['tribunal'] ?? null;
            $comarca = $pub['cidade'] ?? $pub['comarca'] ?? null;
            $vara = $pub['vara'] ?? null;
            $uf = $pub['uf'] ?? null;
            $data_pub = $pub['dataPublicacao'] ?? $pub['data'] ?? null;
            $data_disp = $pub['dataDisponibilizacao'] ?? $pub['dataDisponibilizacaoWebservice'] ?? null;
            $conteudo = $pub['conteudo'] ?? null;
            $polo_ativo = $pub['parte_autora'] ?? $pub['poloAtivo'] ?? null;
            $polo_passivo = $pub['parte_reu'] ?? $pub['poloPassivo'] ?? null;
            $md5 = $pub['md5'] ?? null;
            
            // Converter datas para MySQL (formato pode vir como DD/MM/YYYY ou YYYY-MM-DD)
            $data_pub_mysql = null;
            if ($data_pub && $data_pub != '00/00/0000') {
                try {
                    if (strpos($data_pub, '/') !== false) {
                        // Formato DD/MM/YYYY ou DD/MM/YYYY HH:MM:SS
                        $data_pub_mysql = date('Y-m-d H:i:s', strtotime(str_replace('/', '-', $data_pub)));
                    } else {
                        // Formato YYYY-MM-DD
                        $data_pub_mysql = date('Y-m-d H:i:s', strtotime($data_pub));
                    }
                } catch (Exception $e) {
                    $data_pub_mysql = null;
                }
            }
            
            $data_disp_mysql = null;
            if ($data_disp && $data_disp != '00/00/0000') {
                try {
                    if (strpos($data_disp, '/') !== false) {
                        $data_disp_mysql = date('Y-m-d H:i:s', strtotime(str_replace('/', '-', $data_disp)));
                    } else {
                        $data_disp_mysql = date('Y-m-d H:i:s', strtotime($data_disp));
                    }
                } catch (Exception $e) {
                    $data_disp_mysql = null;
                }
            }
            
            // Inserir publicação
            $sql_insert = "INSERT INTO publicacoes (
                id_ws, titulo, tipo_documento, numero_processo_cnj, numero_processo_tj,
                tribunal, comarca, vara, uf, data_publicacao, data_disponibilizacao,
                conteudo, polo_ativo, polo_passivo, md5_hash, status_tratamento,
                json_data_completo, usuario_id, criado_por, data_criacao
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'nao_tratado', ?, 0, 0, NOW())";
            
            $stmt_insert = $pdo->prepare($sql_insert);
            $stmt_insert->execute([
                $id_ws,
                $tipo_doc,
                $tipo_doc,
                $numero_cnj,
                $numero_proc,
                $tribunal,
                $comarca,
                $vara,
                $uf,
                $data_pub_mysql,
                $data_disp_mysql,
                $conteudo,
                $polo_ativo,
                $polo_passivo,
                $md5,
                json_encode($pub, JSON_UNESCAPED_UNICODE)
            ]);
            
            $publicacao_id = $pdo->lastInsertId();
            $total_novas++;
            $ids_processados[] = $id_ws;
            
            // Tentar vincular automaticamente
            $numero_busca = $numero_cnj ?: $numero_proc;
            if ($numero_busca && vincularProcesso($numero_busca, $publicacao_id)) {
                $total_vinculadas++;
                logMessage("  ✓ #$publicacao_id inserida e vinculada - $tipo_doc");
            } else {
                logMessage("  ✓ #$publicacao_id inserida - $tipo_doc");
            }
            
        } catch (Exception $e) {
            $erros++;
            logMessage("  ✗ Erro ao processar: " . $e->getMessage(), 'ERROR');
        }
    }
    
    // ==========================================
    // MARCAR COMO PROCESSADAS NA API
    // ==========================================
    
    if (!empty($ids_processados)) {
        logMessage("Marcando " . count($ids_processados) . " publicações como processadas...");
        
        if (marcarComoProcessadaAPI($ids_processados)) {
            logMessage("✓ Publicações marcadas como processadas na API");
        } else {
            logMessage("⚠ Não foi possível marcar como processadas", 'WARNING');
        }
    }
    
    // ==========================================
    // LOG FINAL
    // ==========================================
    
    $msg = "Sincronização concluída: $total_novas novas, $total_vinculadas vinculadas, $total_duplicadas duplicadas, $erros erros";
    logMessage($msg);
    
    if (!empty($total_por_data)) {
        logMessage("Publicações por data:");
        foreach ($total_por_data as $data => $total) {
            logMessage("  - $data: $total publicações");
        }
    }
    
    if (!empty($datas_com_erro)) {
        logMessage("Datas com erro: " . implode(', ', $datas_com_erro), 'WARNING');
    }
    
    $detalhes = json_encode([
        'dias_consultados' => DIAS_RETROATIVOS,
        'total_recebidas' => $total_recebidas,
        'novas' => $total_novas,
        'vinculadas' => $total_vinculadas,
        'duplicadas' => $total_duplicadas,
        'erros' => $erros,
        'por_data' => $total_por_data,
        'datas_erro' => $datas_com_erro
    ], JSON_UNESCAPED_UNICODE);
    
    registrarLog('sucesso', $total_novas, $msg, $detalhes);
    logMessage("========================================");
    
    exit(0);
    
} catch (Exception $e) {
    $msg = "ERRO: " . $e->getMessage();
    logMessage($msg, 'ERROR');
    registrarLog('erro', 0, $msg, $e->getTraceAsString());
    logMessage("========================================");
    exit(1);
}
?>