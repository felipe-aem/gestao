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
    u_resp_t.nome as responsavel_nome,
    u_resp_t.email as responsavel_email,
    -- Informações do criador (quem solicitou a revisão)
    u_criador_t.nome as solicitante_nome,
    u_criador_t.email as solicitante_email,
    -- Informações do processo
    proc_t.numero_processo,
    cli_t.nome as cliente_nome,
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
LEFT JOIN usuarios u_resp_t ON t.responsavel_id = u_resp_t.id
LEFT JOIN usuarios u_criador_t ON t.criado_por = u_criador_t.id
LEFT JOIN processos proc_t ON t.processo_id = proc_t.id
LEFT JOIN clientes cli_t ON proc_t.cliente_id = cli_t.id
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
    u_resp_p.nome as responsavel_nome,
    u_resp_p.email as responsavel_email,
    -- Informações do criador (quem solicitou a revisão)
    u_criador_p.nome as solicitante_nome,
    u_criador_p.email as solicitante_email,
    -- Informações do processo
    proc_p.numero_processo,
    cli_p.nome as cliente_nome,
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
LEFT JOIN usuarios u_resp_p ON p.responsavel_id = u_resp_p.id
LEFT JOIN usuarios u_criador_p ON p.criado_por = u_criador_p.id
LEFT JOIN processos proc_p ON p.processo_id = proc_p.id
LEFT JOIN clientes cli_p ON proc_p.cliente_id = cli_p.id
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
