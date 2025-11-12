# 🔄 Migração do Fluxo de Revisão - Modelo Hierárquico

## ✅ Status: CONCLUÍDO

Data: 12 de Novembro de 2025

---

## 📋 O Que Foi Feito

### 1. Modelo Antigo (Descontinuado)
- ❌ Tabela `tarefa_revisoes` - Tabela centralizada
- ❌ Campos `tipo_tarefa` / `tipo_prazo` nas tabelas
- ❌ Lógica em `api_eventos.php` (versão antiga)

### 2. Modelo Novo (Atual)
- ✅ Modelo hierárquico com `parent_id`
- ✅ Campo `tipo_fluxo` ENUM('original','revisao','correcao','protocolo')
- ✅ Campo `revisao_ciclo` para controle de ciclos
- ✅ Helper `RevisaoHelperHierarquico.php`
- ✅ APIs específicas (`tarefas_revisao_hierarquico.php`, `prazos_revisao_hierarquico.php`)
- ✅ Dashboard `dashboard_revisao_hierarquico.php`
- ✅ View SQL `vw_revisoes_hierarquicas`

---

## 📂 Arquivos Modificados

### Arquivo Migrado:
```
modules/agenda/api_eventos.php
```
**Mudança:** Agora usa `RevisaoHelperHierarquico` em vez da tabela `tarefa_revisoes`

### Arquivos Criados:
```
includes/RevisaoHelperHierarquico.php
api/tarefas_revisao_hierarquico.php
api/prazos_revisao_hierarquico.php
modules/agenda/dashboard_revisao_hierarquico.php
sql/view_revisoes_hierarquicas.sql
sql/deprecar_tabela_antiga_revisao.sql
FLUXO_REVISAO_HIERARQUICO.md
MIGRACAO_REVISAO.md
```

---

## 🗄️ Banco de Dados

### View Criada:
```sql
vw_revisoes_hierarquicas
```
Consolida tarefas e prazos de revisão em uma única consulta.

### Tabela Antiga:
```sql
tarefa_revisoes
```
**Status:** DEPRECADA
- Continha 3 registros de teste (confirmado pelo usuário que podem ser removidos)
- Não é mais usada no novo fluxo

**Ação Recomendada:**
```sql
RENAME TABLE tarefa_revisoes TO tarefa_revisoes_old;
```

Ou para remover completamente após validação:
```sql
DROP TABLE IF EXISTS tarefa_revisoes;
```

---

## 🔄 Fluxo Atual

### 1. Enviar para Revisão
```php
RevisaoHelperHierarquico::enviarParaRevisao('tarefa', 123, 25, 15, 'comentário', []);
```
**Cria:** Tarefa/Prazo filho com `tipo_fluxo='revisao'`

### 2. Aceitar Revisão (pelo Revisor)
```php
RevisaoHelperHierarquico::aceitarRevisao('tarefa', 150, 25, 'aprovado', []);
```
**Cria:** Tarefa/Prazo filho com `tipo_fluxo='protocolo'`

### 3. Recusar Revisão (pelo Revisor)
```php
RevisaoHelperHierarquico::recusarRevisao('tarefa', 150, 25, 'observação', []);
```
**Cria:** Tarefa/Prazo filho com `tipo_fluxo='correcao'`

---

## 🎯 Compatibilidade

### API Endpoints (Mantidos compatíveis)
- ✅ `POST /modules/agenda/api_eventos.php`
  - Parâmetros: `revisao_id`, `acao` (aceitar/recusar), `comentario_revisor`
  - Retorna: `{success: true, message: '...', item_protocolo_id: ...}`

### Formato de Resposta (Compatível)
O novo modelo retorna as mesmas estruturas JSON, garantindo compatibilidade com frontend existente.

---

## 📊 Estrutura Hierárquica

```
Tarefa #1: "Elaborar petição" (original)
├── Tarefa #10: "REVISÃO: Elaborar petição" (revisao - Ciclo 1)
│   └── Aceita → Tarefa #15: "PROTOCOLO: Elaborar petição" (protocolo)
│   └── Recusada → Tarefa #16: "CORREÇÃO: Elaborar petição" (correcao)
│       └── Reenvia → Tarefa #20: "REVISÃO: Elaborar petição" (revisao - Ciclo 2)
```

---

## ✅ Checklist de Validação

- [x] View SQL criada e funcionando
- [x] `api_eventos.php` migrado
- [x] Helper hierárquico implementado
- [x] Dashboard criado
- [x] Documentação completa
- [ ] Testar fluxo completo em produção
- [ ] Validar notificações
- [ ] Remover/renomear tabela antiga

---

## 🚀 Próximos Passos

1. **Testar o fluxo completo:**
   - Criar tarefa/prazo original
   - Enviar para revisão
   - Aceitar/Recusar pelo revisor
   - Verificar criação de protocolo/correção

2. **Validar interface:**
   - Verificar modais funcionando
   - Testar upload de arquivos
   - Conferir notificações

3. **Limpar banco:**
   ```sql
   RENAME TABLE tarefa_revisoes TO tarefa_revisoes_old;
   ```

4. **Atualizar documentação do usuário** (se necessário)

---

## 📞 Suporte

Para dúvidas sobre o novo fluxo:
- **Documentação técnica:** `FLUXO_REVISAO_HIERARQUICO.md`
- **Helper principal:** `includes/RevisaoHelperHierarquico.php`
- **View SQL:** `sql/view_revisoes_hierarquicas.sql`

---

## 🎉 Benefícios da Migração

1. ✅ **Histórico Completo:** Cada etapa registrada separadamente
2. ✅ **Hierarquia Clara:** Relacionamento pai-filho visível
3. ✅ **Flexibilidade:** Cada etapa pode ter documentos/comentários próprios
4. ✅ **Rastreabilidade:** Timeline completa de alterações
5. ✅ **Escalabilidade:** Suporta múltiplos ciclos de revisão
6. ✅ **Simplicidade:** Uma única tabela para cada tipo (tarefas/prazos)

---

**Migração concluída com sucesso!** 🎊
