<?php
require_once '../../includes/auth.php';
Auth::protect();

require_once '../../config/database.php';
require_once '../../includes/layout.php';

// Buscar todos os núcleos ativos
$sql = "SELECT n.* FROM nucleos n WHERE n.ativo = 1 ORDER BY n.nome";
$stmt = executeQuery($sql, []);
$nucleos = $stmt->fetchAll();

// Estatísticas por núcleo
$stats_sql = "SELECT 
    n.nome as nucleo_nome,
    n.id as nucleo_id,
    COUNT(*) as total,
    SUM(CASE WHEN p.situacao_processual = 'Em Andamento' THEN 1 ELSE 0 END) as em_andamento,
    SUM(CASE WHEN p.situacao_processual = 'Transitado' THEN 1 ELSE 0 END) as transitado,
    SUM(CASE WHEN p.situacao_processual = 'Para Arquivamento' THEN 1 ELSE 0 END) as para_arquivamento,
    SUM(CASE WHEN p.situacao_processual = 'Em Processo de Renúncia' THEN 1 ELSE 0 END) as em_renuncia,
    SUM(CASE WHEN p.situacao_processual = 'Baixado' THEN 1 ELSE 0 END) as baixado,
    SUM(CASE WHEN p.situacao_processual = 'Renunciado' THEN 1 ELSE 0 END) as renunciado,
    SUM(CASE WHEN p.situacao_processual = 'Em Grau Recursal' THEN 1 ELSE 0 END) as em_recurso,
    SUM(CASE WHEN p.data_protocolo IS NULL THEN 1 ELSE 0 END) as sem_protocolo
    FROM processos p
    INNER JOIN nucleos n ON p.nucleo_id = n.id
    GROUP BY n.id, n.nome
    ORDER BY n.nome";

$stmt = executeQuery($stats_sql, []);
$stats_por_nucleo = $stmt->fetchAll();

ob_start();
?>
<style>
    /* Copie os estilos necessários do arquivo principal */
    .page-header {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        padding: 25px;
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        margin-bottom: 30px;
    }
    
    .page-header h2 {
        color: #1a1a1a;
        font-size: 24px;
        font-weight: 700;
    }
    
    .btn-voltar:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(108, 117, 125, 0.4);
    }
        
    .nucleos-overview {
        background: rgba(255, 255, 255, 0.95);
        backdrop-filter: blur(10px);
        border-radius: 15px;
        box-shadow: 0 8px 32px rgba(0,0,0,0.15);
        padding: 25px;
        margin-bottom: 30px;
    }
    
    .nucleos-stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
    }
    
    .nucleo-stat-card {
        background: rgba(255, 255, 255, 0.9);
        border: 2px solid #e9ecef;
        border-radius: 12px;
        padding: 20px;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    
    .nucleo-stat-card:hover {
        border-color: #007bff;
        transform: translateY(-5px);
        box-shadow: 0 10px 30px rgba(0,123,255,0.2);
    }
    
    .nucleo-icon {
        width: 24px;
        height: 24px;
        border-radius: 6px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        color: white;
        font-weight: bold;
        margin-right: 8px;
    }
    
    .stat-number {
        font-size: 36px;
        font-weight: 700;
        color: #007bff;
        margin: 12px 0;
    }
    
    .stat-breakdown {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
        margin-top: 15px;
    }
    
    .stat-item {
        font-size: 12px;
        color: #666;
        padding: 4px 8px;
        background: rgba(0,0,0,0.03);
        border-radius: 4px;
    }
    
    .stat-item.warning {
        color: #856404;
        background: rgba(255, 193, 7, 0.1);
        font-weight: 600;
    }
    
    .nucleo-stat-card[data-nucleo="Família"] .nucleo-icon { background: #e91e63; }
    .nucleo-stat-card[data-nucleo="Criminal"] .nucleo-icon { background: #f44336; }
    .nucleo-stat-card[data-nucleo="Bancário"] .nucleo-icon { background: #2196f3; }
    .nucleo-stat-card[data-nucleo="Trabalhista"] .nucleo-icon { background: #ff9800; }
    .nucleo-stat-card[data-nucleo="Previdenciário"] .nucleo-icon { background: #9c27b0; }
</style>

<div class="page-header" style="display: flex; justify-content: space-between; align-items: center;">
    <h2>📊 Estatísticas por Núcleo</h2>
    <a href="index.php" class="btn-voltar" style="padding: 12px 24px; background: linear-gradient(135deg, #6c757d 0%, #5a6268 100%); color: white; text-decoration: none; border-radius: 8px; font-weight: 600; transition: all 0.3s; box-shadow: 0 4px 15px rgba(108, 117, 125, 0.3);">
        ← Voltar para Processos
    </a>
</div>

<div class="nucleos-overview">
    <div class="nucleos-stats-grid">
        <?php foreach ($stats_por_nucleo as $stat): ?>
        <div class="nucleo-stat-card" data-nucleo="<?= htmlspecialchars($stat['nucleo_nome']) ?>" onclick="filtrarPorNucleo(<?= $stat['nucleo_id'] ?>)">
            <h4 style="font-size: 16px; font-weight: 700; margin-bottom: 8px;">
                <span class="nucleo-icon"><?= substr($stat['nucleo_nome'], 0, 1) ?></span>
                <?= htmlspecialchars($stat['nucleo_nome']) ?>
            </h4>
            <div class="stat-number"><?= $stat['total'] ?></div>
            <div class="stat-breakdown">
                <span class="stat-item">Em andamento: <?= $stat['em_andamento'] ?></span>
                <span class="stat-item">Transitados: <?= $stat['transitado'] ?></span>
                <span class="stat-item">Para Arquivamento: <?= $stat['para_arquivamento'] ?></span>
                <span class="stat-item">Baixados: <?= $stat['baixado'] ?></span>
                <span class="stat-item">Renunciados: <?= $stat['renunciado'] ?></span>
                <span class="stat-item">Em Grau Recursal: <?= $stat['em_recurso'] ?></span>
                <?php if ($stat['sem_protocolo'] > 0): ?>
                <span class="stat-item warning">Sem protocolo: <?= $stat['sem_protocolo'] ?></span>
                <?php else: ?>
                <span class="stat-item">Para Renúncia: <?= $stat['em_renuncia'] ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<script>
function filtrarPorNucleo(nucleoId) {
    // Redirecionar para index com filtro de núcleo limpo
    window.location.href = 'index.php?nucleos[]=' + nucleoId;
}
</script>

<?php
$conteudo = ob_get_clean();
echo renderLayout('Estatísticas - Processos', $conteudo, 'processos');
?>