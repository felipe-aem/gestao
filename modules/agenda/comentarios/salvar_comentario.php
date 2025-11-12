<?php
/**
 * Salvar comentário + Histórico (opcional - não bloqueia)
 * VERSÃO ROBUSTA: Salva comentário sempre, histórico é bonus
 */

session_start();

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    if (!isset($_SESSION['usuario_id'])) {
        throw new Exception('Não autenticado');
    }
    
    require_once '../../../config/database.php';
    
    $usuario_id = $_SESSION['usuario_id'];
    $usuario_nome = $_SESSION['nome'] ?? 'Usuário';
    $tipo_item = $_POST['tipo_item'] ?? '';
    $item_id = $_POST['item_id'] ?? 0;
    $comentario = trim($_POST['comentario'] ?? '');
    
    if (!in_array($tipo_item, ['tarefa', 'prazo', 'audiencia'])) {
        throw new Exception('Tipo de item inválido');
    }
    
    if (empty($item_id) || empty($comentario)) {
        throw new Exception('Dados incompletos');
    }
    
    $pdo = getConnection();
    $pdo->beginTransaction();
    
    // ===== PROCESSAR MENÇÕES =====
    $mencoes = json_decode($_POST['mencoes'] ?? '[]', true);
    $mencoes_json = !empty($mencoes) ? json_encode($mencoes) : null;
    
    // Inserir comentário
    $sql = "INSERT INTO agenda_comentarios (
        tipo_item, item_id, usuario_id, comentario, mencoes, data_criacao
    ) VALUES (?, ?, ?, ?, ?, NOW())";
    
    executeQuery($sql, [$tipo_item, (int)$item_id, $usuario_id, $comentario, $mencoes_json]);
    $comentario_id = $pdo->lastInsertId();
    
    // ===== PROCESSAR ANEXOS =====
    $anexos_salvos = [];
    if (!empty($_FILES['anexos']['name'][0])) {
        $upload_dir = '../../../uploads/comentarios/';
        
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        foreach ($_FILES['anexos']['name'] as $key => $nome_original) {
            if ($_FILES['anexos']['error'][$key] === UPLOAD_ERR_OK) {
                $tmp_name = $_FILES['anexos']['tmp_name'][$key];
                $tamanho = $_FILES['anexos']['size'][$key];
                $tipo = $_FILES['anexos']['type'][$key];
                
                $extensao = pathinfo($nome_original, PATHINFO_EXTENSION);
                $nome_arquivo = uniqid() . '_' . time() . '.' . $extensao;
                $caminho_completo = $upload_dir . $nome_arquivo;
                
                if (move_uploaded_file($tmp_name, $caminho_completo)) {
                    $sql_anexo = "INSERT INTO agenda_comentarios_anexos (
                        comentario_id, nome_arquivo, nome_original, 
                        tamanho_arquivo, tipo_arquivo, caminho_arquivo, criado_por
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)";
                    
                    executeQuery($sql_anexo, [
                        $comentario_id, $nome_arquivo, $nome_original,
                        $tamanho, $tipo, $caminho_completo, $usuario_id
                    ]);
                    
                    $anexos_salvos[] = [
                        'id' => $pdo->lastInsertId(),
                        'nome' => $nome_original,
                        'tamanho' => $tamanho
                    ];
                }
            }
        }
    }
    
    // ===== CRIAR NOTIFICAÇÕES =====
    $nomes_mencionados = [];
    if (!empty($mencoes) && is_array($mencoes)) {
        $placeholders = implode(',', array_fill(0, count($mencoes), '?'));
        $sql_nomes = "SELECT id, nome FROM usuarios WHERE id IN ($placeholders)";
        $stmt_nomes = executeQuery($sql_nomes, $mencoes);
        $usuarios_mencionados = $stmt_nomes->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($usuarios_mencionados as $user) {
            $nomes_mencionados[] = $user['nome'];
            
            $sql_notif = "INSERT INTO notificacoes_sistema (
                usuario_id, tipo, titulo, mensagem, link, icone, cor,
                lida, prioridade, relacionado_tipo, relacionado_id, data_criacao
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            
            $titulo = "Você foi mencionado em um comentário";
            $mensagem = substr(strip_tags($comentario), 0, 100) . (strlen($comentario) > 100 ? '...' : '');
            $link = "/modules/agenda/visualizar.php?tipo={$tipo_item}&id={$item_id}#comentario-{$comentario_id}";
            
            executeQuery($sql_notif, [
                $user['id'], 'mencao_comentario', $titulo, $mensagem, $link,
                'fa-at', '#764ba2', 0, 'normal', $tipo_item, $item_id
            ]);
        }
    }
    
    // Commit do essencial
    $pdo->commit();
    
    // ===== TENTAR REGISTRAR NO HISTÓRICO (OPCIONAL) =====
    $historico_registrado = false;
    
    try {
        $preview_comentario = mb_substr(strip_tags($comentario), 0, 100);
        if (mb_strlen(strip_tags($comentario)) > 100) {
            $preview_comentario .= '...';
        }
        
        $descricao_historico = "Comentário adicionado por {$usuario_nome}";
        if (!empty($anexos_salvos)) {
            $descricao_historico .= " com " . count($anexos_salvos) . " anexo(s)";
        }
        if (!empty($nomes_mencionados)) {
            $descricao_historico .= " mencionando: " . implode(', ', $nomes_mencionados);
        }
        
        $detalhes_completos = [
            'tipo' => 'novo_comentario',
            'comentario_id' => $comentario_id,
            'autor' => $usuario_nome,
            'preview' => $preview_comentario,
            'texto_completo' => strip_tags($comentario)
        ];
        
        if (!empty($nomes_mencionados)) {
            $detalhes_completos['mencoes'] = $nomes_mencionados;
        }
        
        if (!empty($anexos_salvos)) {
            $detalhes_completos['anexos'] = array_column($anexos_salvos, 'nome');
        }
        
        $sql_historico = "INSERT INTO agenda_historico 
                          (agenda_id, usuario_id, acao, descricao_alteracao, 
                           campo_alterado, valor_anterior, valor_novo, ip_address, data_hora) 
                          VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())";
        
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
        
        executeQuery($sql_historico, [
            $item_id,
            $usuario_id,
            'comentario_adicionado',
            $descricao_historico,
            'comentarios',
            null,
            json_encode($detalhes_completos, JSON_UNESCAPED_UNICODE),
            $ip_address
        ]);
        
        $historico_registrado = true;
        
    } catch (Exception $e) {
        // Log do erro mas não falha
        error_log("Aviso: Não foi possível registrar no histórico: " . $e->getMessage());
    }
    
    // Buscar dados do comentário
    $sql_comentario = "SELECT c.*, u.nome as usuario_nome
                       FROM agenda_comentarios c
                       JOIN usuarios u ON c.usuario_id = u.id
                       WHERE c.id = ?";
    $comentario_dados = executeQuery($sql_comentario, [$comentario_id])->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'message' => 'Comentário salvo com sucesso!',
        'comentario' => $comentario_dados,
        'anexos' => $anexos_salvos,
        'mencoes_notificadas' => count($mencoes ?? []),
        'historico_registrado' => $historico_registrado
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    
    error_log("Erro ao salvar comentário: " . $e->getMessage());
    
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}