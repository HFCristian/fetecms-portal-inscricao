# Backlog — Backend Laravel XVI FETECMS

> Issues/tasks para implementar a API e regras de negócio que substituem o `SistemaInscricao` (PHP legado) e alimentam o protótipo UI + **app mobile**.

**Legenda de prioridade:** `P0` bloqueante · `P1` essencial MVP · `P2` importante pós-MVP · `P3` futuro

**Status sugerido:** `todo` | `in_progress` | `review` | `done`

---

## Documentação técnica (leia antes de implementar)

| Documento | Conteúdo |
|-----------|----------|
| **[`docs/ESPECIFICACAO_LARAVEL.md`](docs/ESPECIFICACAO_LARAVEL.md)** | Padronização API (web + app), modelo de dados **novo**, CRUDs detalhados (request/response/validação), Policies, Services, checklist de submissão |
| [`CONTEXTO_PROJETO.md`](CONTEXTO_PROJETO.md) | Protótipo front e fluxos de tela |

**Decisões principais:**
- API REST versionada: `/api/v1/...`
- Laravel Sanctum — mesmo backend para **portal** e **app**
- Banco **redesenhado** (não obrigatório copiar schema legado) — ver mapeamento em `ESPECIFICACAO_LARAVEL.md` §6
- Padrão de código: `FormRequest` → `Controller` → `Service` → `Model` + `Policy` + `Resource`

---

## Épico 0 — Fundação do projeto Laravel

### FETEC-000 — Criar repositório e bootstrap Laravel
| Campo | Valor |
|-------|--------|
| **Prioridade** | P0 |
| **Descrição** | Novo repo `fetecms-api` (ou monorepo) com Laravel 11+, PHP 8.2+, estrutura padrão, `.env.example`, README de setup. |
| **Critérios de aceite** | `php artisan serve` sobe; health check `GET /api/health` retorna 200; CI básico (lint + tests) opcional. |
| **Notas** | Definir se API-only (Sanctum) ou Blade + API híbrido. Recomendado: **API REST + Sanctum** para consumir o front atual. |

### FETEC-001 — Configuração de ambiente e padrões
| Campo | Valor |
|-------|--------|
| **Prioridade** | P0 |
| **Descrição** | Timezone `America/Campo_Grande`, locale `pt_BR`, CORS para origem do front, logging, filas (database/redis). |
| **Critérios de aceite** | `.env` documentado; `config/cors.php` permite front local e produção; exception handler JSON para API. |

### FETEC-002 — Estrutura de pastas e convenções
| Campo | Valor |
|-------|--------|
| **Prioridade** | P1 |
| **Descrição** | Adotar: `app/Http/Controllers/Api`, `app/Http/Requests`, `app/Http/Resources`, `app/Services`, `app/Policies`, `app/Enums`. |
| **Critérios de aceite** | Documento `docs/ARCHITECTURE.md` com convenção de nomes e fluxo Controller → Service → Model. |

---

## Épico 1 — Autenticação e perfil do orientador

> Protótipo: `cadastro1–3`, `login.html`, `perfil.html` · Legado: `requisicao/cadastro.php`, sessão `usuario_login` tipo 4/6

### FETEC-010 — Modelagem `users` / `orientadores` e migração inicial
| Campo | Valor |
|-------|--------|
| **Prioridade** | P0 |
| **Descrição** | Tabelas equivalentes ao legado: orientador (dados pessoais, acadêmicos, endereço), login/credenciais. Avaliar unificar em `users` + `orientador_profiles` ou manter compatível com dump legado. |
| **Campos orientador (ref. protótipo)** | nome, cpf (único), email (único), telefone, data_nascimento, genero (+ genero_outro se "Outro"), camiseta, instituicao_id ou texto, titulacao, atestado_vinculo (arquivo), endereco completo (CEP, logradouro, número, complemento, bairro, cidade, UF). |
| **Critérios de aceite** | Migrations + Models + factories; índices únicos em cpf/email. |

### FETEC-011 — Registro do orientador (wizard 3 etapas)
| Campo | Valor |
|-------|--------|
| **Prioridade** | P0 |
| **Protótipo** | `cadastro1.html` → `cadastro2.html` → `cadastro3.html` |
| **API sugerida** | `POST /api/orientador/registro` (payload completo) **ou** `POST /api/orientador/registro/etapa/{1\|2\|3}` com rascunho em sessão/cache. |
| **Critérios de aceite** | Validação FormRequest; CPF válido; email único; cria user + orientador + hash senha; retorna token Sanctum; 422 com erros por campo. |

### FETEC-012 — Login e logout (Sanctum)
| Campo | Valor |
|-------|--------|
| **Prioridade** | P0 |
| **Protótipo** | `login.html` |
| **API** | `POST /api/auth/login`, `POST /api/auth/logout`, `GET /api/auth/me` |
| **Critérios de aceite** | Apenas orientadores ativos; token Bearer; logout revoga token; `me` retorna dados para shell autenticado. |

### FETEC-013 — Recuperação de senha
| Campo | Valor |
|-------|--------|
| **Prioridade** | P2 |
| **API** | `POST /api/auth/forgot-password`, `POST /api/auth/reset-password` |
| **Critérios de aceite** | E-mail com link/token; expiração configurável. |

### FETEC-014 — Perfil do orientador (leitura e edição)
| Campo | Valor |
|-------|--------|
| **Prioridade** | P1 |
| **Protótipo** | `perfil.html` |
| **API** | `GET /api/orientador/perfil`, `PUT /api/orientador/perfil` |
| **Critérios de aceite** | Orientador só edita próprio perfil; upload opcional de atestado (ver FETEC-050). |

---

## Épico 2 — Catálogos e dados auxiliares

> Selects do protótipo e FKs do legado (`id_evento`, `id_area`, `id_subarea`, `id_cidade`, etc.)

### FETEC-020 — Migração de tabelas de domínio
| Campo | Valor |
|-------|--------|
| **Prioridade** | P0 |
| **Descrição** | Models/migrations: `eventos`, `areas`, `subareas`, `instituicoes`, `titulacoes`, `paises`, `estados`, `cidades`, categorias de projeto. |
| **Critérios de aceite** | Seeders com dados mínimos MS; subárea vinculada à área. |

### FETEC-021 — API de catálogos públicos/autenticados
| Campo | Valor |
|-------|--------|
| **Prioridade** | P0 |
| **API** | `GET /api/catalogos/instituicoes`, `areas`, `subareas?area_id=`, `estados`, `cidades?estado_id=`, `eventos` |
| **Critérios de aceite** | Respostas cacheáveis; formato `{ data: [...] }`; usado nos selects do wizard e cadastro de projeto. |

### FETEC-022 — Busca de CEP (integração ViaCEP)
| Campo | Valor |
|-------|--------|
| **Prioridade** | P1 |
| **Protótipo** | `cadastro3.html`, `cadastro-masks.js` |
| **API** | `GET /api/cep/{cep}` |
| **Critérios de aceite** | Normaliza CEP; timeout e fallback; não persiste automaticamente sem confirmação do usuário. |

---

## Épico 3 — Projetos (CRUD do orientador)

> Protótipo: `projetos.html`, `cadastro4.html` · Legado: `projeto`, `cadastra-projeto.php`, `editar-projeto.php`

### FETEC-030 — Modelagem `projetos` e status
| Campo | Valor |
|-------|--------|
| **Prioridade** | P0 |
| **Campos (ref. legado + protótipo)** | titulo, id_orientador, id_evento, id_area, id_subarea, instituicao, resumo, link_video, link_musica, tempo_pesquisa, continuacao (bool), feira_afiliada (bool), agenda_2030 (bool), categoria_agenda_2030, numero_credencial, id_pais, id_estado, id_cidade, finalizado/submetido, aprovado, timestamps. |
| **Status sugerido (enum)** | `rascunho`, `pendente`, `submetido`, `aprovado`, `rejeitado` — mapear `finalizado` legado para `submetido`. |
| **Critérios de aceite** | Policy: orientador só acessa próprios projetos; soft deletes opcional. |

### FETEC-031 — Listar projetos do orientador
| Campo | Valor |
|-------|--------|
| **Prioridade** | P0 |
| **Protótipo** | `projetos.html` (filtros: todos, rascunho, submetido, pendente) |
| **API** | `GET /api/projetos?status=&page=` |
| **Critérios de aceite** | Inclui contagem de alunos/coorientador; flags de pendências (vídeo, arquivo, aluno mínimo); ordenação por `updated_at` desc; Resource `ProjetoListResource`. |

### FETEC-032 — Criar projeto (rascunho)
| Campo | Valor |
|-------|--------|
| **Prioridade** | P0 |
| **Protótipo** | Botão "Nova inscrição" → `cadastro4.html` |
| **API** | `POST /api/projetos` |
| **Critérios de aceite** | Cria com status `rascunho`; associa `orientador_id` do token; retorna `id` para redirecionar ao formulário. |

### FETEC-033 — Obter e atualizar projeto
| Campo | Valor |
|-------|--------|
| **Prioridade** | P0 |
| **Protótipo** | `cadastro4.html` |
| **API** | `GET /api/projetos/{id}`, `PUT/PATCH /api/projetos/{id}` |
| **Critérios de aceite** | Bloqueio de edição se `submetido`/`aprovado` (exceto campos admin — futuro); validação parcial permitida em rascunho; 403 se não for dono. |

### FETEC-034 — Excluir projeto (rascunho)
| Campo | Valor |
|-------|--------|
| **Prioridade** | P2 |
| **API** | `DELETE /api/projetos/{id}` |
| **Critérios de aceite** | Apenas rascunho; remove integrantes e arquivos em cascade. |

### FETEC-035 — Validação de link de vídeo (servidor)
| Campo | Valor |
|-------|--------|
| **Prioridade** | P1 |
| **Protótipo** | `video-preview.js` (oEmbed client-side) |
| **API** | `POST /api/projetos/validar-video` body: `{ url }` |
| **Critérios de aceite** | Valida YouTube/Vimeo via oEmbed; opcional HEAD em Google Drive; retorna `{ valid, provider, embed_url, title }`; usado antes de salvar e na submissão. |

---

## Épico 4 — Integrantes (alunos e coorientador)

> Protótipo: `integrantes.html`, `cadastro5.html`, `cadastro6.html` · Legado: `aluno`, `coorientador`

### FETEC-040 — Modelagem `alunos` e `coorientadores`
| Campo | Valor |
|-------|--------|
| **Prioridade** | P0 |
| **Aluno** | nome, email, cpf, telefone, data_nascimento, genero, etnia (IBGE), camiseta, id_projeto, endereço (se no protótipo), ferramentas videoconferência (legado temp). |
| **Coorientador** | nome, email, cpf, telefone, data_nascimento, genero, camiseta, id_projeto (unique por projeto). |
| **Regras** | Mín. 1 aluno, máx. 3 alunos; máx. 1 coorientador por projeto. |
| **Critérios de aceite** | Unique cpf por projeto ou global (definir com negócio); foreign keys com cascade. |

### FETEC-041 — Listar integrantes do projeto
| Campo | Valor |
|-------|--------|
| **Prioridade** | P0 |
| **Protótipo** | `integrantes.html?projeto={id}` |
| **API** | `GET /api/projetos/{id}/integrantes` |
| **Critérios de aceite** | Retorna orientador (owner), lista alunos, coorientador ou null, contagem `alunos_count`, limites `max_alunos: 3`. |

### FETEC-042 — CRUD de alunos
| Campo | Valor |
|-------|--------|
| **Prioridade** | P0 |
| **Protótipo** | `cadastro5.html` |
| **API** | `POST /api/projetos/{id}/alunos`, `GET .../alunos/{alunoId}`, `PUT .../alunos/{alunoId}`, `DELETE .../alunos/{alunoId}` |
| **Critérios de aceite** | Impede 4º aluno; valida CPF; projeto em rascunho para mutações; e-mail único por projeto. |

### FETEC-043 — CRUD de coorientador
| Campo | Valor |
|-------|--------|
| **Prioridade** | P0 |
| **Protótipo** | `cadastro6.html` |
| **API** | `PUT /api/projetos/{id}/coorientador` (upsert), `DELETE /api/projetos/{id}/coorientador` |
| **Critérios de aceite** | Apenas um registro; substituir = update; delete deixa vaga opcional. |

### FETEC-044 — Envio de e-mail ao aluno (hash de confirmação)
| Campo | Valor |
|-------|--------|
| **Prioridade** | P2 |
| **Legado** | `hash_aluno`, e-mail comentado em `cadastra-aluno.php` |
| **Critérios de aceite** | Job em fila; template FETECMS; link de confirmação com token. |

---

## Épico 5 — Upload e armazenamento de arquivos

> Protótipo: `file-upload-preview.js` · Legado: `projetos/PROJETO-{id}.pdf`, `envia-arquivo.php`, `arquivos/`

### FETEC-050 — Configurar storage (local/S3) e política de upload
| Campo | Valor |
|-------|--------|
| **Prioridade** | P0 |
| **Descrição** | Disco `projetos`, `documentos`, `atestados`; mime whitelist `pdf, docx`; max 10MB (protótipo). |
| **Critérios de aceite** | `config/filesystems.php` documentado; variáveis `FILESYSTEM_DISK`. |

### FETEC-051 — Upload arquivo do projeto de pesquisa
| Campo | Valor |
|-------|--------|
| **Prioridade** | P0 |
| **Protótipo** | Seção "Upload de Arquivos" em `cadastro4.html` |
| **API** | `POST /api/projetos/{id}/arquivos` multipart; `DELETE /api/projetos/{id}/arquivos/{arquivoId}` |
| **Critérios de aceite** | Salva metadados (nome original, mime, size, path); substitui versão anterior se política for arquivo único; validação submissão exige arquivo (legado). |

### FETEC-052 — Upload atestado de vínculo (orientador)
| Campo | Valor |
|-------|--------|
| **Prioridade** | P1 |
| **Protótipo** | `cadastro2.html` |
| **API** | `POST /api/orientador/atestado` |
| **Critérios de aceite** | PDF/imagem; associado ao perfil; URL assinada para download admin. |

### FETEC-053 — Preview/download seguro de arquivos
| Campo | Valor |
|-------|--------|
| **Prioridade** | P1 |
| **API** | `GET /api/projetos/{id}/arquivos/{arquivoId}/download` |
| **Critérios de aceite** | Autorização via Policy; headers corretos; não expor path real. |

---

## Épico 6 — Submissão e resumo da inscrição

> Protótipo: `cadastro7.html` · Legado: `submeter-projeto.php`

### FETEC-060 — Endpoint de resumo pré-submissão
| Campo | Valor |
|-------|--------|
| **Prioridade** | P0 |
| **Protótipo** | `cadastro7.html` |
| **API** | `GET /api/projetos/{id}/resumo` |
| **Critérios de aceite** | Retorna projeto, integrantes, checklist booleano (vídeo ok, arquivo ok, alunos ok, coorientador opcional), lista `pendencias[]` com mensagens. |

### FETEC-061 — Submeter projeto (transação)
| Campo | Valor |
|-------|--------|
| **Prioridade** | P0 |
| **Protótipo** | Botão "Confirmar submissão" em `cadastro7.html` |
| **API** | `POST /api/projetos/{id}/submeter` |
| **Regras (ref. `submeter-projeto.php`)** | título, resumo, link_video, link_musica, evento, área, subárea, instituição, país/estado/cidade, arquivo PDF projeto, ≥1 aluno. |
| **Critérios de aceite** | Se falhar: 422 com `pendencias`; se ok: status → `submetido`, `finalizado=true`, `submitted_at`; idempotente se já submetido. |

### FETEC-062 — Salvar rascunho sem validar submissão
| Campo | Valor |
|-------|--------|
| **Prioridade** | P1 |
| **Protótipo** | "Salvar rascunho" em `cadastro4` / `cadastro7` |
| **Critérios de aceite** | `PATCH` projeto sem checklist completo; status permanece `rascunho`. |

---

## Épico 7 — Integração front-end ↔ API

### FETEC-070 — Definir contrato OpenAPI / Swagger
| Campo | Valor |
|-------|--------|
| **Prioridade** | P1 |
| **Critérios de aceite** | `openapi.yaml` na raiz ou Scramble/L5-Swagger gerado; versionamento `/api/v1`. |

### FETEC-071 — Substituir mocks do front por chamadas API
| Campo | Valor |
|-------|--------|
| **Prioridade** | P1 |
| **Telas** | `projetos.html`, `integrantes.html`, wizard, `cadastro4–7` |
| **Critérios de aceite** | Módulo JS `api.js` com fetch + token; tratamento 401 → login; loading/error states. |

### FETEC-072 — Migrar front para Blade ou manter SPA estático
| Campo | Valor |
|-------|--------|
| **Prioridade** | P2 |
| **Opções** | (A) Laravel serve views Blade reutilizando CSS FETEC; (B) front estático em CDN + API; (C) Inertia/Vue. |
| **Critérios de aceite** | Decisão registrada em ADR; uma rota piloto funcionando. |

---

## Épico 8 — Migração do sistema legado

### FETEC-080 — Script de migração de dados (ETL)
| Campo | Valor |
|-------|--------|
| **Prioridade** | P1 |
| **Descrição** | Command `php artisan fetec:migrate-legacy` lendo banco/arquivos antigos. |
| **Critérios de aceite** | Migra orientadores, projetos, alunos, coorientadores, arquivos; log de erros; relatório CSV. |

### FETEC-081 — Mapeamento de IDs legados
| Campo | Valor |
|-------|--------|
| **Prioridade** | P1 |
| **Descrição** | Tabela `legacy_id_map` (entity, old_id, new_id) para rollback e suporte. |

### FETEC-082 — Período de convivência (read-only legado)
| Campo | Valor |
|-------|--------|
| **Prioridade** | P2 |
| **Critérios de aceite** | Plano documentado: freeze legado, redirect, backup BD antes do corte. |

---

## Épico 9 — Administração e pós-inscrição (futuro)

### FETEC-090 — Painel admin (áreas, eventos, usuários)
| Prioridade | P3 |

### FETEC-091 — Aprovação de projetos e edição de área/subárea
| Prioridade | P3 · Legado: `editar-area.php` |

### FETEC-092 — Relatórios e exportação (finalistas, planilhas)
| Prioridade | P3 · Legado: `gerar-projetos-finalistas.php` |

### FETEC-093 — Pagamento de inscrição (se mantido)
| Prioridade | P3 · Legado: referências comentadas a pagamento |

---

## Épico 10 — Qualidade, segurança e operação

### FETEC-100 — Testes automatizados
| Campo | Valor |
|-------|--------|
| **Prioridade** | P1 |
| **Descrição** | Feature tests: registro, login, CRUD projeto, limites alunos, submissão com pendências. |
| **Critérios de aceite** | Cobertura mínima 70% nos Services críticos. |

### FETEC-101 — Rate limiting e proteção API
| Prioridade | P1 · throttle login, registro, upload |

### FETEC-102 — Auditoria (activity log)
| Prioridade | P2 · submissão, alteração integrantes |

### FETEC-103 — Observabilidade
| Prioridade | P2 · logs estruturados, Horizon para filas |

---

## Ordem sugerida de implementação (MVP)

```
Sprint 1: FETEC-000 → 001 → 010 → 012 → 020 → 021
Sprint 2: FETEC-011 → 014 → 030 → 031 → 032 → 033
Sprint 3: FETEC-040 → 041 → 042 → 043 → 050 → 051
Sprint 4: FETEC-035 → 060 → 061 → 070 → 071
Sprint 5: FETEC-080 → 100 → 101 (+ P2 conforme tempo)
```

---

## Matriz protótipo → API

| Tela protótipo | Endpoints principais |
|----------------|----------------------|
| `cadastro1–3` | `POST /orientador/registro`, catálogos, `GET /cep/{cep}` |
| `login.html` | `POST /auth/login` |
| `projetos.html` | `GET /projetos` |
| `cadastro4.html` | `GET\|PATCH /projetos/{id}`, upload arquivos, validar vídeo |
| `integrantes.html` | `GET /projetos/{id}/integrantes` |
| `cadastro5.html` | CRUD `/projetos/{id}/alunos` |
| `cadastro6.html` | PUT/DELETE `/projetos/{id}/coorientador` |
| `cadastro7.html` | `GET /projetos/{id}/resumo`, `POST /projetos/{id}/submeter` |
| `perfil.html` | `GET\|PUT /orientador/perfil` |

---

## Referências

- Protótipo UI: `fetecms-portal-inscricao` + `CONTEXTO_PROJETO.md`
- Sistema legado: `/SistemaInscricao` — tabelas `orientador`, `projeto`, `aluno`, `coorientador`, `login`
- Validação submissão: `requisicao/submeter-projeto.php`

---

*Criado em maio/2026. Atualizar este arquivo ao fechar issues ou mudar escopo do MVP.*
