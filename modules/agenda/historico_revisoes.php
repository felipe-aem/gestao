<?php
require_once '../../includes/auth.php';
Auth::protect();
require_once '../../config/database.php';
require_once '../../includes/layout.php';

$usuario_logado = Auth::user();
$usuario_id = $usuario_logado['usuario_id'];

// Buscar histórico de revisões
$sql = "SELECT 
            tr.*,
            u_solicitante.nome as solicitante_nome,
            u_revisor.nome as revisor_nome,
            CASE 
                WHEN tr.tipo_origem = 'tarefa' THEN t.titulo 
                ELSE p.titulo 
            END as titulo_origem,
            CASE 
                WHEN tr.tipo_origem = 'tarefa' THEN 'Tarefa'
                ELSE 'Prazo'
            END as tipo_texto
        FROM tarefa_revisoes tr
        INNER JOIN usuarios u_solicitante ON tr.usuario_solicitante_id = u_solicitante.id
        INNER JOIN usuarios u_revisor ON tr.usuario_revisor_id = u_revisor.id
        LEFT JOIN tarefas t ON tr.tarefa_origem_id = t.id AND tr.tipo_origem = 'tarefa'
        LEFT JOIN prazos p ON tr.tarefa_origem_id = p.id AND tr.tipo_origem = 'prazo'
        WHERE tr.usuario_solicitante_id = ? OR tr.usuario_revisor_id = ?
        ORDER BY tr.data_solicitacao DESC
        LIMIT 50";

$stmt = executeQuery($sql, [$usuario_id, $usuario_id]);
$revisoes = $stmt->fetchAll();

ob_start();
?>

<style>
.revisoes-container {
    background: white;
    border-radius: 12px;
    padding: 25px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

.revisao-card {
    border: 1px solid #e0e0e0;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 15px;
    transition: all 0.3s;
}

.revisao-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.1);
    transform: translateY(-2px);
}

.revisao-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.status-badge {
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 600;
}

.status-pendente {
    background: #ffc107;
    color: #000;
}

.status-aceita {
    background: #28a745;
    color: white;
}

.status-recusada {
    background: #dc3545;
    color: white;
}
</style>

<div class="revisoes-container">
    <h2>📋 Histórico de Revisões</h2>
    <p style="color: #666; margin-bottom: 25px;">
        Revisões solicitadas e recebidas
    </p>
    
    <?php if (empty($revisoes)): ?>
        <div style="text-align: center; padding: 40px; color: #999;">
            <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 15px;"></i>
            <p>Nenhuma revisão encontrada</p>
        </div>
    <?php else: ?>
        <?php foreach ($revisoes as $rev): ?>
            <?php
            $eh_solicitante = $rev['usuario_solicitante_id'] == $usuario_id;
            $status_class = 'status-' . $rev['status'];
            $status_texto = [
                'pendente' => '⏳ Pendente',
                'aceita' => '✅ Aceita',
                'recusada' => '❌ Recusada'
            ][$rev['status']];
            ?>
            
            <div class="revisao-card">
                <div class="revisao-header">
                    <div>
                        <strong><?= htmlspecialchars($rev['titulo_origem']) ?></strong>
                        <br>
                        <small style="color: #666;">
                            <?= $rev['tipo_texto'] ?> • 
                            <?php if ($eh_solicitante): ?>
                                Enviado para <strong><?= htmlspecialchars($rev['revisor_nome']) ?></strong>
                            <?php else: ?>
                                Recebido de <strong><?= htmlspecialchars($rev['solicitante_nome']) ?></strong>
                            <?php endif; ?>
                        </small>
                    </div>
                    <span class="status-badge <?= $status_class ?>">
                        <?= $status_texto ?>
                    </span>
                </div>
                
                <div style="font-size: 13px; color: #666;">
                    <div style="margin-bottom: 5px;">
                        📅 Solicitado em: <?= date('d/m/Y H:i', strtotime($rev['data_solicitacao'])) ?>
                    </div>
                    
                    <?php if ($rev['data_resposta']): ?>
                        <div style="margin-bottom: 5px;">
                            📅 Respondido em: <?= date('d/m/Y H:i', strtotime($rev['data_resposta'])) ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($rev['comentario_solicitante'] && $eh_solicitante): ?>
                        <div style="margin-top: 10px; background: #f8f9fa; padding: 10px; border-radius: 6px;">
                            <strong>Seu comentário:</strong><br>
                            <?= nl2br(htmlspecialchars($rev['comentario_solicitante'])) ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($rev['comentario_revisor']): ?>
                        <div style="margin-top: 10px; background: <?= $rev['status'] === 'aceita' ? '#d4edda' : '#f8d7da' ?>; padding: 10px; border-radius: 6px;">
                            <strong>Resposta do revisor:</strong><br>
                            <?= nl2br(htmlspecialchars($rev['comentario_revisor'])) ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Histórico de Revisões', $conteudo, 'agenda');
?>