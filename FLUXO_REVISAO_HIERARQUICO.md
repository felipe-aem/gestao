# Fluxo de Revisão Hierárquico

## 📋 Visão Geral

Implementação do **modelo hierárquico** para o fluxo de revisão de tarefas e prazos. Em vez de usar uma tabela centralizada (`fluxo_revisao`), cada etapa do fluxo (revisão, correção, protocolo) é representada por uma tarefa/prazo **FILHA** real no sistema.

## 🎯 Objetivos

- ✅ Cada etapa é uma tarefa/prazo real com histórico próprio
- ✅ Hierarquia clara através de `parent_id`
- ✅ Cada etapa pode ter documentos, comentários e envolvidos próprios
- ✅ Rastreabilidade completa de todas as mudanças
- ✅ Transparência no processo de revisão

## 🔄 Fluxo Completo

```
┌─────────────────────┐
│ TAREFA/PRAZO        │
│ ORIGINAL (Pai)      │
│ tipo_fluxo='origin' │
└──────────┬──────────┘
           │
           │ Enviar para Revisão
           ↓
┌─────────────────────┐
│ TAREFA/PRAZO        │
│ DE REVISÃO (Filho)  │
│ tipo_fluxo='revis' │
│ parent_id = Pai     │
└──────────┬──────────┘
           │
     ┌─────┴─────┐
     │           │
  Aceitar    Recusar
     │           │
     ↓           ↓
┌────────┐  ┌────────────┐
│PROTOC. │  │ CORREÇÃO   │
│(Filho) │  │ (Filho)    │
└────────┘  └─────┬──────┘
                  │
           Reenvia para
           nova revisão
```

## 📁 Arquivos Criados

### 1. Helper Principal
**`includes/RevisaoHelperHierarquico.php`**
- `enviarParaRevisao()` - Cria tarefa/prazo filho de revisão
- `aceitarRevisao()` - Cria tarefa/prazo filho de protocolo
- `recusarRevisao()` - Cria tarefa/prazo filho de correção
- `listarRevisoesPendentes()` - Lista revisões pendentes do usuário
- `listarHistoricoCompleto()` - Lista pai + todos os filhos

### 2. APIs
**`api/tarefas_revisao_hierarquico.php`**
**`api/prazos_revisao_hierarquico.php`**

Endpoints:
- `POST /enviar_revisao` - Envia para revisão
- `POST /aceitar_revisao` - Aceita revisão
- `POST /recusar_revisao` - Recusa revisão
- `GET /pendentes_revisao` - Lista pendentes
- `GET /historico` - Histórico completo

### 3. Dashboard
**`modules/agenda/dashboard_revisao_hierarquico.php`**

Abas:
- **Revisões Pendentes** - `tipo_fluxo='revisao' AND status='pendente'`
- **Correções Pendentes** - `tipo_fluxo='correcao' AND status='pendente'`
- **Protocolos Pendentes** - `tipo_fluxo='protocolo' AND status='pendente'`
- **Histórico** - Todos os itens concluídos

### 4. View SQL
**`sql/view_revisoes_hierarquicas.sql`**

View unificada que consolida tarefas e prazos em uma única consulta com informações do pai, responsável, solicitante e SLA.

## 🗄️ Estrutura de Dados

### Campos Importantes nas Tabelas

**Tarefas/Prazos:**
```sql
parent_id          INT       -- ID do item pai (NULL para originais)
tipo_fluxo         ENUM      -- 'original', 'revisao', 'correcao', 'protocolo'
revisao_ciclo      INT       -- Número do ciclo de revisão (1, 2, 3...)
status             ENUM      -- Status normal: pendente, concluida, etc
responsavel_id     INT       -- Quem é responsável por esta etapa
criado_por         INT       -- Quem criou esta etapa
```

### Exemplo de Hierarquia

```
Tarefa #1: "Elaborar petição inicial" (original)
├── Tarefa #15: "REVISÃO: Elaborar petição inicial" (revisao - Ciclo 1)
│   └── Tarefa #23: "PROTOCOLO: Elaborar petição inicial" (protocolo)
│
└── Caso recusada:
    └── Tarefa #16: "CORREÇÃO: Elaborar petição inicial" (correcao - Ciclo 1)
        └── Tarefa #24: "REVISÃO: Elaborar petição inicial" (revisao - Ciclo 2)
```

## 🚀 Como Usar

### 1. Enviar Tarefa/Prazo para Revisão

```php
require_once 'includes/RevisaoHelperHierarquico.php';

$resultado = RevisaoHelperHierarquico::enviarParaRevisao(
    'tarefa',           // tipo: 'tarefa' ou 'prazo'
    123,                // ID da tarefa original
    25,                 // ID do revisor
    15,                 // ID do solicitante (usuário logado)
    'Por favor revisar urgente',  // Comentário (opcional)
    [45, 46]            // IDs dos arquivos anexados (opcional)
);

if ($resultado['success']) {
    echo "Enviado! ID da tarefa de revisão: " . $resultado['item_revisao_id'];
}
```

**Resultado:**
- Cria uma nova tarefa filha com `tipo_fluxo='revisao'`
- Título: "REVISÃO: [título original]"
- Responsável: O revisor escolhido
- Notifica o revisor
- Marca original como `status='em_revisao'`

### 2. Aceitar Revisão

```php
$resultado = RevisaoHelperHierarquico::aceitarRevisao(
    'tarefa',           // tipo
    150,                // ID da tarefa DE REVISÃO
    25,                 // ID do revisor (usuário logado)
    'Aprovado, pode protocolar',  // Comentário (opcional)
    [47]                // Arquivos (opcional)
);

if ($resultado['success']) {
    echo "Aprovado! ID da tarefa de protocolo: " . $resultado['item_protocolo_id'];
}
```

**Resultado:**
- Marca tarefa de revisão como `status='concluida'`
- Cria tarefa filha com `tipo_fluxo='protocolo'`
- Título: "PROTOCOLO: [título original]"
- Responsável: Volta para o solicitante original
- Notifica o solicitante
- Marca original como `status='aguardando_protocolo'`

### 3. Recusar Revisão

```php
$resultado = RevisaoHelperHierarquico::recusarRevisao(
    'tarefa',           // tipo
    150,                // ID da tarefa DE REVISÃO
    25,                 // ID do revisor
    'Necessário corrigir fundamentação legal',  // Observação (OBRIGATÓRIA)
    []                  // Arquivos (opcional)
);

if ($resultado['success']) {
    echo "Recusado! ID da tarefa de correção: " . $resultado['item_correcao_id'];
}
```

**Resultado:**
- Marca tarefa de revisão como `status='revisao_recusada'`
- Cria tarefa filha com `tipo_fluxo='correcao'`
- Título: "CORREÇÃO: [título original]"
- Responsável: Volta para o solicitante original
- Descrição inclui observações do revisor em destaque
- Prioridade alta/urgente
- Notifica o solicitante
- Marca original como `status='em_correcao'`

### 4. Listar Revisões Pendentes

```php
$revisoes = RevisaoHelperHierarquico::listarRevisoesPendentes('tarefa', $usuario_id);

foreach ($revisoes as $rev) {
    echo "- {$rev['titulo']} (Ciclo {$rev['revisao_ciclo']}) - {$rev['solicitante_nome']}\n";
}
```

### 5. Buscar Histórico Completo

```php
// Passa o ID de qualquer item (pai ou filho) - retorna toda a hierarquia
$historico = RevisaoHelperHierarquico::listarHistoricoCompleto('tarefa', 150);

foreach ($historico as $item) {
    echo "{$item['tipo_fluxo']}: {$item['titulo']} - {$item['status']}\n";
}
```

## 📊 Consultas SQL Úteis

### Revisor: Minhas Revisões Pendentes
```sql
SELECT * FROM vw_revisoes_hierarquicas
WHERE responsavel_id = ?
AND tipo_fluxo = 'revisao'
AND status = 'pendente'
ORDER BY prioridade DESC, dias_aguardando DESC;
```

### Solicitante: Minhas Correções Pendentes
```sql
SELECT * FROM vw_revisoes_hierarquicas
WHERE responsavel_id = ?
AND tipo_fluxo = 'correcao'
AND status = 'pendente'
ORDER BY data_vencimento ASC;
```

### Histórico de um Item Específico
```sql
-- Retorna o original + todos os filhos (revisões, correções, protocolos)
SELECT * FROM vw_revisoes_hierarquicas
WHERE item_original_id = ? OR item_id = ?
ORDER BY data_criacao ASC, tipo_fluxo;
```

## 🔄 Migração do Modelo Antigo

### Arquivos Antigos (Não usar mais)
- ❌ `includes/RevisaoHelper.php` (modelo com fluxo_revisao)
- ❌ `api/tarefas_revisao.php` (usa fluxo_revisao)
- ❌ `api/prazos_revisao.php` (usa fluxo_revisao)
- ❌ `modules/agenda/dashboard_revisao.php` (consulta fluxo_revisao)

### Arquivos Novos (Usar)
- ✅ `includes/RevisaoHelperHierarquico.php`
- ✅ `api/tarefas_revisao_hierarquico.php`
- ✅ `api/prazos_revisao_hierarquico.php`
- ✅ `modules/agenda/dashboard_revisao_hierarquico.php`

### Passos para Migração

1. **Aplicar a View SQL:**
```bash
mysql -u usuario -p banco < sql/view_revisoes_hierarquicas.sql
```

2. **Atualizar Links no Sistema:**
Substituir links que apontam para:
- `/api/tarefas_revisao.php` → `/api/tarefas_revisao_hierarquico.php`
- `/api/prazos_revisao.php` → `/api/prazos_revisao_hierarquico.php`
- `/modules/agenda/dashboard_revisao.php` → `/modules/agenda/dashboard_revisao_hierarquico.php`

3. **Dados Existentes na `fluxo_revisao`:**
Os dados antigos na tabela `fluxo_revisao` permanecem intactos para consulta histórica, mas novos fluxos usarão apenas o modelo hierárquico.

## 🎨 Vantagens do Novo Modelo

### ✅ Transparência
Cada etapa é visível na agenda/calendário do responsável

### ✅ Histórico Completo
Cada tarefa/prazo filho tem seu próprio histórico de alterações

### ✅ Documentos por Etapa
Cada etapa pode ter documentos específicos anexados

### ✅ Notificações Nativas
Usa o sistema de notificações padrão de tarefas/prazos

### ✅ Relatórios Simplificados
Consultas SQL diretas nas tabelas tarefas/prazos

### ✅ Hierarquia Clara
Estrutura pai-filho facilmente navegável

## 🐛 Troubleshooting

### Problema: Não aparece no dashboard
**Verificar:**
1. `tipo_fluxo` está correto? (revisao, correcao, protocolo)
2. `status` é 'pendente'?
3. `responsavel_id` é o usuário logado?
4. `deleted_at IS NULL`?

### Problema: Não encontra item original
**Verificar:**
1. `parent_id` está preenchido corretamente?
2. Item pai não foi deletado?

### Problema: Ciclo de revisão incorreto
O ciclo é calculado automaticamente contando quantas revisões já existem para aquele item original.

## 📞 Suporte

Para dúvidas ou problemas com o novo fluxo, consulte:
- Este documento (FLUXO_REVISAO_HIERARQUICO.md)
- Código-fonte: `includes/RevisaoHelperHierarquico.php`
- View SQL: `sql/view_revisoes_hierarquicas.sql`
- Dashboard de exemplo: `modules/agenda/dashboard_revisao_hierarquico.php`
