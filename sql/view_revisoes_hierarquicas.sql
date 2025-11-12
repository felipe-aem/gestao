-- ============================================================
-- VIEW PARA MODELO HIERÁRQUICO DE REVISÕES
-- ============================================================
-- Esta view consolida tarefas e prazos de revisão em uma única consulta
-- Facilita a busca de revisões pendentes, correções e protocolos
-- ============================================================

-- Remover view antiga se existir
DROP VIEW IF EXISTS vw_revisoes_hierarquicas;

-- Criar view unificada
CREATE VIEW vw_revisoes_hierarquicas AS
SELECT
    'tarefa' as tipo_item,
    t.id as item_id,
    t.titulo,
    t.descricao,
    t.prioridade,
    t.status,
    t.tipo_fluxo,
    t.revisao_ciclo,
    t.parent_id,
    t.processo_id,
    t.responsavel_id,
    t.criado_por,
    t.data_vencimento,
    t.data_criacao,
    t.data_conclusao,
    t.concluido_por,
    -- Informações do responsável
    u_resp.nome as responsavel_nome,
    u_resp.email as responsavel_email,
    -- Informações do criador (quem solicitou a revisão)
    u_criador.nome as solicitante_nome,
    u_criador.email as solicitante_email,
    -- Informações do processo
    proc.numero_processo,
    cli.nome as cliente_nome,
    -- Informações do item PAI (original)
    COALESCE(t_pai.titulo, t.titulo) as titulo_original,
    t_pai.id as item_original_id,
    -- Cálculos
    DATEDIFF(NOW(), t.data_criacao) as dias_aguardando,
    CASE
        WHEN DATEDIFF(NOW(), t.data_criacao) > 2 THEN 'atrasado'
        WHEN DATEDIFF(NOW(), t.data_criacao) > 1 THEN 'atencao'
        ELSE 'ok'
    END as status_sla,
    -- Badge descritivo
    CASE
        WHEN t.tipo_fluxo = 'original' THEN 'Original'
        WHEN t.tipo_fluxo = 'revisao' THEN 'Revisão'
        WHEN t.tipo_fluxo = 'correcao' THEN 'Correção'
        WHEN t.tipo_fluxo = 'protocolo' THEN 'Protocolo'
    END as tipo_badge
FROM tarefas t
LEFT JOIN tarefas t_pai ON t.parent_id = t_pai.id
LEFT JOIN usuarios u_resp ON t.responsavel_id = u_resp.id
LEFT JOIN usuarios u_criador ON t.criado_por = u_criador.id
LEFT JOIN processos proc ON t.processo_id = proc.id
LEFT JOIN clientes cli ON proc.cliente_id = cli.id
WHERE t.deleted_at IS NULL

UNION ALL

SELECT
    'prazo' as tipo_item,
    p.id as item_id,
    p.titulo,
    p.descricao,
    p.prioridade,
    p.status,
    p.tipo_fluxo,
    p.revisao_ciclo,
    p.parent_id,
    p.processo_id,
    p.responsavel_id,
    p.criado_por,
    p.data_vencimento,
    p.data_criacao,
    p.data_conclusao,
    p.concluido_por,
    -- Informações do responsável
    u_resp.nome as responsavel_nome,
    u_resp.email as responsavel_email,
    -- Informações do criador (quem solicitou a revisão)
    u_criador.nome as solicitante_nome,
    u_criador.email as solicitante_email,
    -- Informações do processo
    proc.numero_processo,
    cli.nome as cliente_nome,
    -- Informações do item PAI (original)
    COALESCE(p_pai.titulo, p.titulo) as titulo_original,
    p_pai.id as item_original_id,
    -- Cálculos
    DATEDIFF(NOW(), p.data_criacao) as dias_aguardando,
    CASE
        WHEN DATEDIFF(NOW(), p.data_criacao) > 2 THEN 'atrasado'
        WHEN DATEDIFF(NOW(), p.data_criacao) > 1 THEN 'atencao'
        ELSE 'ok'
    END as status_sla,
    -- Badge descritivo
    CASE
        WHEN p.tipo_fluxo = 'original' THEN 'Original'
        WHEN p.tipo_fluxo = 'revisao' THEN 'Revisão'
        WHEN p.tipo_fluxo = 'correcao' THEN 'Correção'
        WHEN p.tipo_fluxo = 'protocolo' THEN 'Protocolo'
    END as tipo_badge
FROM prazos p
LEFT JOIN prazos p_pai ON p.parent_id = p_pai.id
LEFT JOIN usuarios u_resp ON p.responsavel_id = u_resp.id
LEFT JOIN usuarios u_criador ON p.criado_por = p_criador.id
LEFT JOIN processos proc ON p.processo_id = proc.id
LEFT JOIN clientes cli ON proc.cliente_id = cli.id
WHERE p.deleted_at IS NULL;

-- ============================================================
-- EXEMPLOS DE CONSULTAS ÚTEIS
-- ============================================================

-- 1. Listar todas as revisões pendentes de um usuário
-- SELECT * FROM vw_revisoes_hierarquicas
-- WHERE responsavel_id = [ID_USUARIO]
-- AND tipo_fluxo = 'revisao'
-- AND status = 'pendente'
-- ORDER BY prioridade DESC, dias_aguardando DESC;

-- 2. Listar todas as correções pendentes de um usuário
-- SELECT * FROM vw_revisoes_hierarquicas
-- WHERE responsavel_id = [ID_USUARIO]
-- AND tipo_fluxo = 'correcao'
-- AND status = 'pendente'
-- ORDER BY data_vencimento ASC;

-- 3. Listar todos os protocolos pendentes de um usuário
-- SELECT * FROM vw_revisoes_hierarquicas
-- WHERE responsavel_id = [ID_USUARIO]
-- AND tipo_fluxo = 'protocolo'
-- AND status = 'pendente'
-- ORDER BY data_vencimento ASC;

-- 4. Buscar histórico completo de um item (pai + todos os filhos)
-- SELECT * FROM vw_revisoes_hierarquicas
-- WHERE item_original_id = [ID_ITEM]
-- OR item_id = [ID_ITEM]
-- ORDER BY data_criacao ASC;

-- 5. Estatísticas por usuário
-- SELECT
--     responsavel_id,
--     responsavel_nome,
--     COUNT(CASE WHEN tipo_fluxo = 'revisao' AND status = 'pendente' THEN 1 END) as revisoes_pendentes,
--     COUNT(CASE WHEN tipo_fluxo = 'correcao' AND status = 'pendente' THEN 1 END) as correcoes_pendentes,
--     COUNT(CASE WHEN tipo_fluxo = 'protocolo' AND status = 'pendente' THEN 1 END) as protocolos_pendentes,
--     COUNT(CASE WHEN status_sla = 'atrasado' THEN 1 END) as atrasados
-- FROM vw_revisoes_hierarquicas
-- WHERE status = 'pendente'
-- GROUP BY responsavel_id, responsavel_nome;
