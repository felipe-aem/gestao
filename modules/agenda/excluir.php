<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';

$id = $_GET['id'] ?? 0;
$tipo = $_GET['tipo'] ?? 'tarefa';

if (!$id) {
    header('Location: index.php?erro=ID não informado');
    exit;
}

$usuario_logado = Auth::user();

// ====== VERIFICAR PERMISSÃO DE GESTOR ======
$nivel_acesso = $usuario_logado['nivel_acesso'] ?? '';
$niveis_permitidos = ['Admin', 'Gestor', 'Gerente'];
$eh_gestor = in_array($nivel_acesso, $niveis_permitidos);

if (!$eh_gestor) {
    header('Location: visualizar.php?id=' . $id . '&tipo=' . $tipo . '&erro=' . urlencode('Apenas gestores podem excluir itens da agenda'));
    exit;
}

// Determinar tabela
$tabela_map = [
    'tarefa' => 'tarefas',
    'prazo' => 'prazos',
    'audiencia' => 'audiencias',
    'compromisso' => 'agenda',
    'evento' => 'agenda'
];

$tabela = $tabela_map[$tipo] ?? 'tarefas';

try {
    // Buscar evento completo
    $sql = "SELECT * FROM {$tabela} WHERE id = ?";
    $stmt = executeQuery($sql, [$id]);
    $evento = $stmt->fetch();
    
    if (!$evento) {
        header('Location: index.php?erro=Evento não encontrado');
        exit;
    }
    
    // Buscar número do processo (se houver)
    $processo_numero = null;
    if (!empty($evento['processo_id'])) {
        $sql_proc = "SELECT numero_processo FROM processos WHERE id = ?";
        $stmt_proc = executeQuery($sql_proc, [$evento['processo_id']]);
        $processo = $stmt_proc->fetch();
        $processo_numero = $processo ? $processo['numero_processo'] : null;
    }
    
    $pdo = getConnection();
    $pdo->beginTransaction();
    
    try {
        // ====== REGISTRAR EXCLUSÃO NO HISTÓRICO ANTES DE EXCLUIR ======
        require_once __DIR__ . '/includes/HistoricoHelper.php';
        
        // Capturar dados completos para o histórico
        $dados_exclusao = [
            'titulo' => $evento['titulo'] ?? '',
            'status' => $evento['status'] ?? '',
            'processo_numero' => $processo_numero,
            'responsavel_id' => $evento['responsavel_id'] ?? null,
            'prioridade' => $evento['prioridade'] ?? null,
            'data_vencimento' => $evento['data_vencimento'] ?? ($evento['data_inicio'] ?? null)
        ];
        
        HistoricoHelper::registrarExclusao($tipo, $id, $dados_exclusao);
        
        // Soft delete (se a tabela tiver o campo deleted_at)
        $sql_check = "SHOW COLUMNS FROM {$tabela} LIKE 'deleted_at'";
        $has_soft_delete = executeQuery($sql_check)->fetch();
        
        if ($has_soft_delete) {
            $sql_delete = "UPDATE {$tabela} SET deleted_at = NOW() WHERE id = ?";
            executeQuery($sql_delete, [$id]);
        } else {
            // Hard delete apenas se não tiver soft delete
            $sql_delete = "DELETE FROM {$tabela} WHERE id = ?";
            executeQuery($sql_delete, [$id]);
        }
        
        // Excluir relacionamentos (apenas se for hard delete)
        if (!$has_soft_delete) {
            if ($tipo === 'tarefa') {
                executeQuery("DELETE FROM tarefa_envolvidos WHERE tarefa_id = ?", [$id]);
                executeQuery("DELETE FROM tarefa_etiquetas WHERE tarefa_id = ?", [$id]);
            } elseif ($tipo === 'prazo') {
                executeQuery("DELETE FROM prazo_envolvidos WHERE prazo_id = ?", [$id]);
                executeQuery("DELETE FROM prazo_etiquetas WHERE prazo_id = ?", [$id]);
            } elseif ($tipo === 'audiencia') {
                executeQuery("DELETE FROM audiencia_envolvidos WHERE audiencia_id = ?", [$id]);
                executeQuery("DELETE FROM audiencia_etiquetas WHERE audiencia_id = ?", [$id]);
            } elseif ($tipo === 'evento' || $tipo === 'compromisso') {
                executeQuery("DELETE FROM agenda_participantes WHERE agenda_id = ?", [$id]);
            }
        }
        
        // Registrar no histórico do processo
        if (!empty($evento['processo_id'])) {
            $sql_hist = "INSERT INTO processo_historico 
                         (processo_id, usuario_id, acao, descricao, data_acao)
                         VALUES (?, ?, ?, ?, NOW())";
            executeQuery($sql_hist, [
                $evento['processo_id'],
                $usuario_logado['usuario_id'],
                ucfirst($tipo) . ' Excluída',
                ucfirst($tipo) . " excluída por {$usuario_logado['nome']}: {$evento['titulo']}"
            ]);
        }
        
        $pdo->commit();
        
        header('Location: index.php?success=' . ucfirst($tipo) . ' excluída com sucesso');
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    error_log("Erro ao excluir {$tipo}: " . $e->getMessage());
    header('Location: visualizar.php?id=' . $id . '&tipo=' . $tipo . '&erro=' . urlencode($e->getMessage()));
    exit;
}