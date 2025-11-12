-- ============================================================
-- DEPRECAR TABELA ANTIGA DE REVISÕES
-- ============================================================
-- A tabela tarefa_revisoes não é mais usada no novo fluxo hierárquico
-- Os dados de teste podem ser removidos com segurança
-- ============================================================

-- OPÇÃO 1: RENOMEAR para manter backup (RECOMENDADO)
-- ============================================================
-- Renomeia a tabela para _old, mantendo como backup histórico
-- Execute este se quiser manter os dados antigos por segurança

-- RENAME TABLE tarefa_revisoes TO tarefa_revisoes_old;


-- OPÇÃO 2: LIMPAR DADOS DE TESTE
-- ============================================================
-- Remove apenas os 3 registros de teste
-- Mantém a estrutura da tabela vazia

-- TRUNCATE TABLE tarefa_revisoes;


-- OPÇÃO 3: REMOVER COMPLETAMENTE (Use com cuidado!)
-- ============================================================
-- Remove a tabela completamente
-- NÃO execute se não tiver certeza!

-- DROP TABLE IF EXISTS tarefa_revisoes;


-- ============================================================
-- INFORMAÇÕES
-- ============================================================
--
-- TABELA ANTIGA: tarefa_revisoes
-- - Usada no fluxo antigo de revisão
-- - Tinha 3 registros de teste
-- - Substituída pelo modelo hierárquico
--
-- NOVO MODELO:
-- - Usa parent_id nas próprias tabelas tarefas/prazos
-- - Campo tipo_fluxo (original, revisao, correcao, protocolo)
-- - Campo revisao_ciclo para controle de ciclos
-- - Helper: RevisaoHelperHierarquico
--
-- MIGRAÇÃO:
-- ✅ api_eventos.php - Migrado para usar novo modelo
-- ✅ View vw_revisoes_hierarquicas - Criada
-- ✅ Dashboard hierárquico - Criado
--
-- ============================================================

-- RECOMENDAÇÃO: Execute a OPÇÃO 1 (RENAME) por segurança
-- Depois de validar que tudo funciona, pode executar DROP

-- Comando recomendado:
-- RENAME TABLE tarefa_revisoes TO tarefa_revisoes_old;
