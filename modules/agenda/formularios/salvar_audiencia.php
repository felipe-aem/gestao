<?php
ob_start();

try {
    require_once '../../../includes/auth.php';
    Auth::protect();
    require_once '../../../config/database.php';
    require_once '../includes/HistoricoHelper.php';
    
    $usuario_logado = Auth::user();
    
    // Validações
    if (empty($_POST['titulo'])) throw new Exception('Título obrigatório');
    if (empty($_POST['data_inicio'])) throw new Exception('Data obrigatória');
    if (empty($_POST['processo_id'])) throw new Exception('Processo obrigatório');
    if (empty($_POST['responsavel_id'])) throw new Exception('Responsável obrigatório');
    
    // Dados
    $titulo = trim($_POST['titulo']);
    $descricao = trim($_POST['descricao'] ?? '');
    $data_inicio = $_POST['data_inicio'];
    $data_fim = $_POST['data_fim'] ?? null;
    $local_evento = trim($_POST['local_evento'] ?? '');
    $responsavel_id = (int)$_POST['responsavel_id'];
    $processo_id = (int)$_POST['processo_id'];
    $prioridade = $_POST['prioridade'] ?? 'Normal';
    $status = $_POST['status'] ?? 'agendada';
    $envolvidos = $_POST['envolvidos'] ?? [];
    $etiquetas = $_POST['etiquetas'] ?? [];
    
    if (!strtotime($data_inicio)) throw new Exception('Data inválida');
    
    // Validar responsável
    $stmt = executeQuery("SELECT id FROM usuarios WHERE id = ? AND ativo = 1", [$responsavel_id]);
    if (!$stmt->fetch()) throw new Exception('Responsável inválido');
    
    // Validar processo
    $stmt = executeQuery("SELECT id FROM processos WHERE id = ? AND deleted_at IS NULL", [$processo_id]);
    if (!$stmt->fetch()) throw new Exception('Processo inválido');
    
    $audiencia_id = !empty($_POST['id']) ? (int)$_POST['id'] : null;
    
    if ($audiencia_id) {
        // EDITAR - Buscar dados atuais primeiro
        $stmt_atual = executeQuery("SELECT * FROM audiencias WHERE id = ? AND deleted_at IS NULL", [$audiencia_id]);
        $audiencia_atual = $stmt_atual->fetch(PDO::FETCH_ASSOC);
        
        if (!$audiencia_atual) {
            throw new Exception('Audiência não encontrada');
        }
        
        // Atualizar audiência
        $sql = "UPDATE audiencias SET 
                titulo = ?, descricao = ?, data_inicio = ?, data_fim = ?,
                local_evento = ?, status = ?, prioridade = ?, 
                responsavel_id = ?, processo_id = ?, tipo = ?,
                data_atualizacao = NOW(), atualizado_por = ?
                WHERE id = ? AND deleted_at IS NULL";
        
        $tipo_audiencia = $_POST['tipo_audiencia'] ?? $_POST['tipo'] ?? 'Audiência';
        
        executeQuery($sql, [
            $titulo, $descricao, $data_inicio, $data_fim,
            $local_evento, $status, $prioridade,
            $responsavel_id, $processo_id, $tipo_audiencia,
            $usuario_logado['usuario_id'], $audiencia_id
        ]);
        
        // ====== REGISTRAR ALTERAÇÕES NO HISTÓRICO ======
        require_once __DIR__ . '/../includes/HistoricoHelper.php';
        
        $campos_monitorar = ['titulo', 'descricao', 'data_inicio', 'data_fim', 'local_evento', 'status', 'prioridade', 'responsavel_id', 'processo_id', 'tipo'];
        
        foreach ($campos_monitorar as $campo) {
            $valor_anterior = $audiencia_atual[$campo] ?? null;
            $valor_novo = null;
            
            // Mapear os valores do POST
            switch ($campo) {
                case 'tipo':
                    $valor_novo = $tipo_audiencia;
                    break;
                default:
                    $valor_novo = $$campo ?? null; // Variável dinâmica
                    break;
            }
            
            // Normalizar valores vazios
            if ($valor_anterior === '') $valor_anterior = null;
            if ($valor_novo === '') $valor_novo = null;
            
            // Comparação especial para datas
            if (in_array($campo, ['data_inicio', 'data_fim'])) {
                if ($valor_anterior !== null && $valor_novo !== null) {
                    $ts_anterior = strtotime($valor_anterior);
                    $ts_novo = strtotime($valor_novo);
                    if ($ts_anterior == $ts_novo) continue;
                }
            }
            
            // Registrar se houve mudança
            if ($valor_anterior != $valor_novo) {
                HistoricoHelper::registrarAlteracao('audiencia', $audiencia_id, $campo, $valor_anterior, $valor_novo);
            }
        }
        
        // Envolvidos
        executeQuery("DELETE FROM audiencia_envolvidos WHERE audiencia_id = ?", [$audiencia_id]);
        if (!empty($envolvidos)) {
            foreach ($envolvidos as $uid) {
                if ($uid) executeQuery("INSERT INTO audiencia_envolvidos (audiencia_id, usuario_id) VALUES (?, ?)", [$audiencia_id, (int)$uid]);
            }
        }
        
        // Etiquetas
        executeQuery("DELETE FROM audiencia_etiquetas WHERE audiencia_id = ?", [$audiencia_id]);
        if (!empty($etiquetas)) {
            foreach ($etiquetas as $eid) {
                if ($eid) executeQuery("INSERT INTO audiencia_etiquetas (audiencia_id, etiqueta_id) VALUES (?, ?)", [$audiencia_id, (int)$eid]);
            }
        }
        
        $msg = 'Audiência atualizada com sucesso';
        
    } else {
        // CRIAR
        $sql = "INSERT INTO audiencias (
                    titulo, descricao, data_inicio, data_fim,
                    local_evento, status, prioridade,
                    responsavel_id, processo_id, criado_por, data_criacao,
                    tipo
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'Audiência')";
        
        executeQuery($sql, [
            $titulo, $descricao, $data_inicio, $data_fim,
            $local_evento, $status, $prioridade,
            $responsavel_id, $processo_id, $usuario_logado['usuario_id']
        ]);
        
        // Pegar ID do último insert
        $result = executeQuery("SELECT LAST_INSERT_ID() as id");
        $row = $result->fetch(PDO::FETCH_ASSOC);
        $audiencia_id = $row['id'];
        
        // Pegar o ID da audiência
        $pdo = getConnection();
        $audiencia_id = $pdo->lastInsertId();
        
        // ====== REGISTRAR NO HISTÓRICO COM DETALHES ======
        require_once __DIR__ . '/../includes/HistoricoHelper.php';
        
        // Buscar nome do responsável
        $sql_resp = "SELECT nome FROM usuarios WHERE id = ?";
        $stmt_resp = executeQuery($sql_resp, [$responsavel_id]);
        $responsavel = $stmt_resp->fetch();
        $responsavel_nome = $responsavel ? $responsavel['nome'] : 'Desconhecido';
        
        // Buscar número do processo
        $sql_proc = "SELECT numero_processo FROM processos WHERE id = ?";
        $stmt_proc = executeQuery($sql_proc, [$processo_id]);
        $processo = $stmt_proc->fetch();
        $processo_numero = $processo ? $processo['numero_processo'] : 'N/A';
        
        // Montar motivo da criação
        $motivo = "Audiência criada via sistema de agenda";
        
        // Registrar criação com todos os detalhes
        HistoricoHelper::registrarCriacao('audiencia', $audiencia_id, $motivo, [
            'titulo' => $titulo,
            'responsavel_nome' => $responsavel_nome,
            'data_vencimento' => $data_inicio, // Para audiências, usar data_inicio
            'processo_numero' => $processo_numero
        ]);
        
        // Envolvidos
        if (!empty($envolvidos)) {
            foreach ($envolvidos as $uid) {
                if ($uid) executeQuery("INSERT INTO audiencia_envolvidos (audiencia_id, usuario_id) VALUES (?, ?)", [$audiencia_id, (int)$uid]);
            }
        }
        
        // Etiquetas
        if (!empty($etiquetas)) {
            foreach ($etiquetas as $eid) {
                if ($eid) executeQuery("INSERT INTO audiencia_etiquetas (audiencia_id, etiqueta_id) VALUES (?, ?)", [$audiencia_id, (int)$eid]);
            }
        }
        
        // Registrar no histórico do processo
        $sql_historico = "INSERT INTO processo_historico (
                            processo_id, usuario_id, acao, descricao, data_acao
                         ) VALUES (?, ?, ?, ?, NOW())";
        executeQuery($sql_historico, [
            $processo_id,
            $usuario_logado['usuario_id'],
            'Audiência Agendada',
            "Audiência '{$titulo}' agendada para " . date('d/m/Y H:i', strtotime($data_inicio))
        ]);
        
        $msg = 'Audiência criada com sucesso';
    }
    
    $result = [
        'success' => true,
        'sucesso' => true,
        'mensagem' => $msg,
        'id' => $audiencia_id
    ];
    
} catch (Exception $e) {
    http_response_code(400);
    $result = [
        'success' => false,
        'sucesso' => false,
        'erro' => $e->getMessage()
    ];
}

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');
echo json_encode($result, JSON_UNESCAPED_UNICODE);
exit;