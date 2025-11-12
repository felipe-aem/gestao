<?php
require_once '../../includes/auth.php';
Auth::protect();
require_once '../../config/database.php';

$evento_id = $_GET['id'] ?? 0;
$acao = $_GET['acao'] ?? '';

if (!$evento_id || !in_array($acao, ['aceitar', 'recusar'])) {
    header('Location: index.php?erro=Parâmetros inválidos');
    exit;
}

$usuario_id = Auth::user()['usuario_id'];
$usuario_nome = Auth::user()['nome'] ?? 'Usuário';
$novo_status = $acao === 'aceitar' ? 'Confirmado' : 'Recusado';

try {
    // Verificar se o usuário tem convite pendente e buscar dados do evento
    $sql = "SELECT ap.*, a.titulo, a.data_inicio, a.status as evento_status,
                   org.nome as organizador_nome, org_ap.usuario_id as organizador_id
            FROM agenda_participantes ap
            INNER JOIN agenda a ON ap.agenda_id = a.id
            LEFT JOIN agenda_participantes org_ap ON a.id = org_ap.agenda_id AND org_ap.status_participacao = 'Organizador'
            LEFT JOIN usuarios org ON org_ap.usuario_id = org.id
            WHERE ap.agenda_id = ? AND ap.usuario_id = ? AND ap.status_participacao = 'Convidado'";
    
    $stmt = executeQuery($sql, [$evento_id, $usuario_id]);
    $convite = $stmt->fetch();
    
    if (!$convite) {
        header('Location: index.php?erro=Convite não encontrado ou já respondido');
        exit;
    }
    
    // Verificar se o evento ainda está válido para resposta
    if ($convite['evento_status'] === 'Cancelado') {
        header('Location: index.php?erro=Este evento foi cancelado');
        exit;
    }
    
    if ($convite['evento_status'] === 'Concluído') {
        header('Location: index.php?erro=Este evento já foi concluído');
        exit;
    }
    
    // Verificar se o evento já passou (para aceitação)
    if ($acao === 'aceitar' && strtotime($convite['data_inicio']) < time()) {
        header('Location: index.php?erro=Não é possível aceitar convite para evento que já passou');
        exit;
    }
    
    // Atualizar status
    $sql = "UPDATE agenda_participantes 
            SET status_participacao = ?, data_resposta = NOW()
            WHERE agenda_id = ? AND usuario_id = ?";
    
    executeQuery($sql, [$novo_status, $evento_id, $usuario_id]);
    
    // Registrar no histórico
    $acao_texto = $acao === 'aceitar' ? 'aceitou participar' : 'recusou participar';
    $descricao = "{$usuario_nome} {$acao_texto} do evento '{$convite['titulo']}'";
    
    $sql_hist = "INSERT INTO agenda_historico (agenda_id, acao, descricao_alteracao, usuario_id) 
                 VALUES (?, ?, ?, ?)";
    executeQuery($sql_hist, [
        $evento_id, 
        'Resposta ao Convite', 
        $descricao, 
        $usuario_id
    ]);
    
    // Verificar se deve atualizar status do evento automaticamente
    if ($acao === 'aceitar') {
        $sql_check = "SELECT COUNT(*) as confirmados FROM agenda_participantes 
                      WHERE agenda_id = ? AND status_participacao IN ('Organizador', 'Confirmado')";
        $stmt = executeQuery($sql_check, [$evento_id]);
        $confirmados = $stmt->fetch()['confirmados'];
        
        if ($confirmados >= 1) {
            $sql_update_evento = "UPDATE agenda SET status = 'Confirmado' WHERE id = ? AND status = 'Agendado'";
            executeQuery($sql_update_evento, [$evento_id]);
        }
    }
    
    // Criar notificação para o organizador (se tabela existir)
    if (!empty($convite['organizador_id'])) {
        $titulo_notif = $acao === 'aceitar' ? "✅ Convite Aceito" : "❌ Convite Recusado";
        $mensagem_notif = $acao === 'aceitar' ? 
            "{$usuario_nome} confirmou participação no evento '{$convite['titulo']}'" :
            "{$usuario_nome} recusou o convite para o evento '{$convite['titulo']}'";
        $link_notif = "/modules/agenda/visualizar.php?id={$evento_id}";
        
        $sql_notif = "INSERT INTO notificacoes (usuario_id, tipo, titulo, mensagem, link, data_criacao) 
                     VALUES (?, 'resposta_convite', ?, ?, ?, NOW())";
        
        try {
            executeQuery($sql_notif, [$convite['organizador_id'], $titulo_notif, $mensagem_notif, $link_notif]);
        } catch (Exception $e) {
            // Falha na notificação não deve impedir o processo principal
            error_log("Erro ao criar notificação: " . $e->getMessage());
        }
    }
    
    $mensagem = $acao === 'aceitar' ? 
        'Participação confirmada com sucesso!' : 
        'Convite recusado. Obrigado pela resposta.';
    
    header("Location: index.php?success=" . urlencode($mensagem));
    exit;
    
} catch (Exception $e) {
    error_log("Erro ao responder convite: " . $e->getMessage());
    header("Location: index.php?erro=" . urlencode('Erro interno. Tente novamente.'));
    exit;
}
?>