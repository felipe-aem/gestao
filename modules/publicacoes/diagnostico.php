<?php
/**
 * DIAGNÓSTICO - Publicações Não Aparecem
 * 
 * Execute este arquivo diretamente no navegador para ver o que está acontecendo
 * URL: gestao.alencarmartinazzo.adv.br/modules/publicacoes/diagnostico.php
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../../includes/auth.php';
require_once '../../config/database.php';

Auth::protect();

$usuario_logado = Auth::user();
$usuario_id = $usuario_logado['usuario_id'];

echo "<h1>🔍 DIAGNÓSTICO - Publicações</h1>";
echo "<hr>";

// ============================================================
// 1. VERIFICAR USUÁRIO LOGADO
// ============================================================
echo "<h2>1️⃣ Usuário Logado</h2>";
echo "<pre>";
print_r($usuario_logado);
echo "</pre>";

// ============================================================
// 2. VERIFICAR PERMISSÃO
// ============================================================
echo "<h2>2️⃣ Verificação de Permissão</h2>";

$sql_perm = "SELECT 
    id, 
    nome, 
    email, 
    nivel_acesso,
    visualiza_publicacoes_nao_vinculadas
FROM usuarios 
WHERE id = ?";

$stmt_perm = executeQuery($sql_perm, [$usuario_id]);
$user_perm = $stmt_perm->fetch();

echo "<pre>";
print_r($user_perm);
echo "</pre>";

$pode_ver_nao_vinculadas = $user_perm['visualiza_publicacoes_nao_vinculadas'] ?? 0;

echo "<p><strong>Pode ver publicações não vinculadas:</strong> ";
echo $pode_ver_nao_vinculadas ? "✅ SIM" : "❌ NÃO";
echo "</p>";

// ============================================================
// 3. TOTAL DE PUBLICAÇÕES NO BANCO
// ============================================================
echo "<h2>3️⃣ Total de Publicações no Banco</h2>";

$sql_total = "SELECT 
    COUNT(*) as total,
    SUM(processo_id IS NULL) as nao_vinculadas,
    SUM(processo_id IS NOT NULL) as vinculadas,
    SUM(status_tratamento = 'nao_tratado') as nao_tratadas,
    SUM(status_tratamento = 'tratada') as tratadas,
    SUM(status_tratamento = 'concluido') as concluidas,
    SUM(status_tratamento = 'descartado') as descartadas
FROM publicacoes 
WHERE deleted_at IS NULL";

$stmt_total = executeQuery($sql_total);
$totais = $stmt_total->fetch();

echo "<table border='1' cellpadding='10' style='border-collapse: collapse;'>";
echo "<tr><th>Métrica</th><th>Valor</th></tr>";
foreach ($totais as $key => $value) {
    echo "<tr><td><strong>$key</strong></td><td>$value</td></tr>";
}
echo "</table>";

// ============================================================
// 4. TESTE DA QUERY ORIGINAL (SEM FILTROS DE STATUS/DATAS)
// ============================================================
echo "<h2>4️⃣ Teste da Query COM Filtro de Permissão</h2>";

$where = ["p.deleted_at IS NULL"];
$params = [];

if (!$pode_ver_nao_vinculadas) {
    echo "<p><strong>Filtro Aplicado:</strong> Usuário SEM permissão - mostrando apenas processos onde é responsável</p>";
    
    $where[] = "(p.processo_id IS NOT NULL AND EXISTS (
        SELECT 1 FROM processos pr 
        WHERE pr.id = p.processo_id 
        AND pr.responsavel_id = ?
    ))";
    $params[] = $usuario_id;
} else {
    echo "<p><strong>Filtro Aplicado:</strong> Usuário COM permissão - mostrando não vinculadas + processos dele + processos sem responsável</p>";
    
    $where[] = "(
        p.processo_id IS NULL 
        OR EXISTS (
            SELECT 1 FROM processos pr 
            WHERE pr.id = p.processo_id 
            AND (pr.responsavel_id = ? OR pr.responsavel_id IS NULL)
        )
    )";
    $params[] = $usuario_id;
}

$where_clause = implode(' AND ', $where);

$sql_teste = "SELECT COUNT(*) as total
FROM publicacoes p
WHERE $where_clause";

echo "<p><strong>Query SQL:</strong></p>";
echo "<pre style='background: #f4f4f4; padding: 10px; overflow-x: auto;'>";
echo $sql_teste;
echo "</pre>";

echo "<p><strong>Parâmetros:</strong></p>";
echo "<pre>";
print_r($params);
echo "</pre>";

$stmt_teste = executeQuery($sql_teste, $params);
$resultado_teste = $stmt_teste->fetch();

echo "<p style='font-size: 20px;'><strong>Resultado:</strong> ";
echo "<span style='color: " . ($resultado_teste['total'] > 0 ? 'green' : 'red') . ";'>";
echo $resultado_teste['total'] . " publicações</span></p>";

// ============================================================
// 5. TESTE COM FILTRO DE STATUS
// ============================================================
echo "<h2>5️⃣ Teste com Filtro de Status = 'nao_tratado'</h2>";

$where_status = $where; // Copia o where anterior
$where_status[] = "p.status_tratamento = ?";
$params_status = $params;
$params_status[] = 'nao_tratado';

$where_status_clause = implode(' AND ', $where_status);

$sql_status = "SELECT COUNT(*) as total
FROM publicacoes p
WHERE $where_status_clause";

echo "<p><strong>Query SQL:</strong></p>";
echo "<pre style='background: #f4f4f4; padding: 10px; overflow-x: auto;'>";
echo $sql_status;
echo "</pre>";

$stmt_status = executeQuery($sql_status, $params_status);
$resultado_status = $stmt_status->fetch();

echo "<p style='font-size: 20px;'><strong>Resultado:</strong> ";
echo "<span style='color: " . ($resultado_status['total'] > 0 ? 'green' : 'red') . ";'>";
echo $resultado_status['total'] . " publicações não tratadas</span></p>";

// ============================================================
// 6. LISTAR ALGUMAS PUBLICAÇÕES (AMOSTRA)
// ============================================================
echo "<h2>6️⃣ Amostra de Publicações (primeiras 5)</h2>";

$sql_amostra = "SELECT 
    p.id, 
    p.numero_processo_cnj,
    p.numero_processo_tj,
    p.tipo_documento,
    p.tribunal,
    p.status_tratamento,
    p.processo_id,
    p.data_publicacao,
    pr.responsavel_id,
    u.nome as responsavel_nome
FROM publicacoes p
LEFT JOIN processos pr ON p.processo_id = pr.id
LEFT JOIN usuarios u ON pr.responsavel_id = u.id
WHERE $where_clause
ORDER BY p.data_publicacao DESC
LIMIT 5";

$stmt_amostra = executeQuery($sql_amostra, $params);
$amostra = $stmt_amostra->fetchAll();

if (empty($amostra)) {
    echo "<p style='color: red; font-size: 18px;'>❌ NENHUMA PUBLICAÇÃO ENCONTRADA!</p>";
} else {
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr>
        <th>ID</th>
        <th>Processo CNJ</th>
        <th>Tipo</th>
        <th>Tribunal</th>
        <th>Status</th>
        <th>Processo ID</th>
        <th>Responsável</th>
        <th>Data</th>
    </tr>";
    
    foreach ($amostra as $pub) {
        echo "<tr>";
        echo "<td>{$pub['id']}</td>";
        echo "<td>" . ($pub['numero_processo_cnj'] ?: '-') . "</td>";
        echo "<td>" . ($pub['tipo_documento'] ?: '-') . "</td>";
        echo "<td>" . ($pub['tribunal'] ?: '-') . "</td>";
        echo "<td>{$pub['status_tratamento']}</td>";
        echo "<td>" . ($pub['processo_id'] ?: '<em>NULL</em>') . "</td>";
        echo "<td>" . ($pub['responsavel_nome'] ?: '<em>Sem responsável</em>') . "</td>";
        echo "<td>" . date('d/m/Y', strtotime($pub['data_publicacao'])) . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
}

// ============================================================
// 7. VERIFICAR PUBLICAÇÕES NÃO VINCULADAS
// ============================================================
echo "<h2>7️⃣ Publicações NÃO Vinculadas (processo_id IS NULL)</h2>";

$sql_nao_vinc = "SELECT COUNT(*) as total
FROM publicacoes 
WHERE deleted_at IS NULL 
AND processo_id IS NULL";

$stmt_nao_vinc = executeQuery($sql_nao_vinc);
$total_nao_vinc = $stmt_nao_vinc->fetch();

echo "<p style='font-size: 18px;'>Total no banco: <strong>{$total_nao_vinc['total']}</strong></p>";

if ($pode_ver_nao_vinculadas) {
    echo "<p style='color: green;'>✅ Você TEM permissão para ver essas publicações</p>";
    
    $sql_nao_vinc_sample = "SELECT 
        id, numero_processo_cnj, tipo_documento, tribunal, status_tratamento, data_publicacao
    FROM publicacoes 
    WHERE deleted_at IS NULL 
    AND processo_id IS NULL
    ORDER BY data_publicacao DESC
    LIMIT 5";
    
    $stmt_sample = executeQuery($sql_nao_vinc_sample);
    $sample = $stmt_sample->fetchAll();
    
    echo "<h3>Amostra (5 primeiras):</h3>";
    echo "<table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>ID</th><th>Processo CNJ</th><th>Tipo</th><th>Tribunal</th><th>Status</th><th>Data</th></tr>";
    
    foreach ($sample as $s) {
        echo "<tr>";
        echo "<td>{$s['id']}</td>";
        echo "<td>" . ($s['numero_processo_cnj'] ?: '-') . "</td>";
        echo "<td>" . ($s['tipo_documento'] ?: '-') . "</td>";
        echo "<td>" . ($s['tribunal'] ?: '-') . "</td>";
        echo "<td>{$s['status_tratamento']}</td>";
        echo "<td>" . date('d/m/Y', strtotime($s['data_publicacao'])) . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
} else {
    echo "<p style='color: red;'>❌ Você NÃO tem permissão para ver essas publicações</p>";
}

// ============================================================
// 8. VERIFICAR PROCESSOS DO USUÁRIO
// ============================================================
echo "<h2>8️⃣ Processos Onde Você é Responsável</h2>";

$sql_meus_proc = "SELECT COUNT(*) as total
FROM processos 
WHERE responsavel_id = ?";

$stmt_meus = executeQuery($sql_meus_proc, [$usuario_id]);
$meus_proc = $stmt_meus->fetch();

echo "<p style='font-size: 18px;'>Total: <strong>{$meus_proc['total']}</strong> processos</p>";

// ============================================================
// 9. CONCLUSÃO
// ============================================================
echo "<h2>9️⃣ Conclusão do Diagnóstico</h2>";

if ($pode_ver_nao_vinculadas) {
    if ($resultado_teste['total'] > 0) {
        echo "<p style='color: green; font-size: 18px;'>✅ TUDO OK - As publicações deveriam estar aparecendo!</p>";
        echo "<p>Se ainda não estão aparecendo no index.php, o problema pode ser:</p>";
        echo "<ul>";
        echo "<li>Cache do navegador</li>";
        echo "<li>Filtros de data muito restritivos</li>";
        echo "<li>JavaScript com erro</li>";
        echo "<li>Arquivo index.php não foi substituído corretamente</li>";
        echo "</ul>";
    } else {
        echo "<p style='color: red; font-size: 18px;'>❌ PROBLEMA ENCONTRADO - A query não retorna nada!</p>";
        echo "<p>Possíveis causas:</p>";
        echo "<ul>";
        echo "<li>Todas as publicações foram deletadas (deleted_at não é NULL)</li>";
        echo "<li>Erro na lógica do filtro</li>";
        echo "</ul>";
    }
} else {
    if ($meus_proc['total'] == 0) {
        echo "<p style='color: orange; font-size: 18px;'>⚠️ ATENÇÃO - Você não é responsável por nenhum processo!</p>";
        echo "<p>Como você não tem permissão para ver publicações não vinculadas, não verá nenhuma publicação.</p>";
    } else {
        if ($resultado_teste['total'] > 0) {
            echo "<p style='color: green; font-size: 18px;'>✅ TUDO OK - Você vê apenas seus processos!</p>";
        } else {
            echo "<p style='color: red; font-size: 18px;'>❌ PROBLEMA - Você tem processos mas não vê publicações!</p>";
        }
    }
}

echo "<hr>";
echo "<p><em>Diagnóstico concluído em " . date('d/m/Y H:i:s') . "</em></p>";
?>

<style>
    body {
        font-family: Arial, sans-serif;
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
        background: #f5f5f5;
    }
    h1 { color: #333; border-bottom: 3px solid #667eea; padding-bottom: 10px; }
    h2 { color: #667eea; margin-top: 30px; }
    table { background: white; margin: 10px 0; }
    th { background: #667eea; color: white; }
    pre { background: #f4f4f4; padding: 10px; border-radius: 5px; overflow-x: auto; }
</style>