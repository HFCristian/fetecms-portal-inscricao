# Projeto-exemplo da aba "Ajustes e Pareceres" (orientador demo)

> **Desde a Sprint 146** as abas *Ajustes* e *Pareceres* são **uma só**:
> *Ajustes e Pareceres* (`/ajustes`). As sugestões a decidir e o parecer a ler
> saem da mesma avaliação, e a tela **não mostra nota nenhuma**.

As **sugestões** da aba só aparecem quando existe, no banco, um projeto
**submetido** que recebeu uma **avaliação concluída** em que o avaliador disse
que a classificação está errada. Sem esse conjunto a tela abre sem elas, mesmo
com o período aberto — não há bug a procurar.

> **Desde a Sprint 122** o comando monta **três avaliadores**, com **três
> sugestões**: uma de área (Avaliador 1), uma de subárea (Avaliador 2) e outra de
> área (Avaliador 3), que **disputa** com a primeira. É assim que a aba fica
> quando o projeto recebe o mínimo de avaliações da feira, e é o caso difícil que
> o orientador precisa ver antes: aceitar uma sugestão de área **desliga** a
> outra. O orientador padrão passou a ser **`orientador@fetecms.test`**.

Este documento explica como montar esse conjunto **em produção**: primeiro pelo
comando do portal (recomendado), depois em SQL puro, para quem só tem acesso ao
banco.

---

## Ajustes, pareceres, ou os dois?

São dois comandos, e a diferença está no que cada um põe nas **respostas da
rubrica**:

| Comando | O que monta | Sugestões | Parecer |
|---------|-------------|-----------|---------|
| `php artisan demo:ajustes` | projeto avaliado, com as 17 perguntas respondidas com o mesmo valor | 3 sugestões | **"Ponto forte" nas dez seções** |
| `php artisan demo:pareceres` | um **segundo** projeto, com as notas distribuídas por seção | 3 sugestões | 4 pontos fortes, 4 médios e 2 fracos |

Para ensaiar o **parecer** use o `demo:pareceres`: é ele que produz a mistura de
níveis que o orientador vai ler depois da feira — um exemplo em que tudo é ponto
forte não mostra como a tela fica.

```bash
php artisan demo:pareceres
```

Ele reaproveita as **mesmas quatro contas** deste documento (o orientador e os
três avaliadores, com os mesmos e-mails e CPFs), então rodar os dois não
multiplica cadastro: o orientador demo fica com **dois** projetos, um de cada
comando. As opções `--orientador`, `--avaliador` e `--senha` são as mesmas.

O restante deste documento — as tabelas envolvidas, o SQL equivalente e a
remoção do exemplo — vale para os dois.

---

## O que precisa existir

Quatro registros, nesta ordem de dependência:

| # | Tabela | Papel no exemplo |
|---|--------|------------------|
| 1 | `users` + `orientador_profiles` | o **orientador demo**, dono do projeto |
| 2 | `users` + `avaliador_profiles` | os **três avaliadores demo**, autores das avaliações (o SQL abaixo mostra um; repita para os outros dois, com CPFs distintos — a coluna é única) |
| 3 | `projetos` | o projeto de mentira, com `status = 'submetido'` |
| 4 | `avaliacoes` | as avaliações **concluídas** que carregam as sugestões |

As duas contas nascem com **`is_demo = 1`**. Isso não é enfeite: é o que mantém
o projeto de mentira **fora do painel, do ranking dos projetos, da lista final e
da distribuição automática** (`Projeto::semDemo()`). Se você criar as contas sem
essa coluna, o projeto-exemplo entra na contagem de camisetas que a organização
vai encomendar.

O que as sugestões leem da avaliação é este par de colunas:

- **`area_correta = 0`** e **`area_sugerida_id`** apontando para **outra** área
  → vira a linha "Área do conhecimento" na tela;
- **`subarea_correta = 0`** e **`subarea_sugerida_id`** → vira a linha "Subárea".

`comentario_video` e `comentario_projeto` viram as **recomendações escritas**,
que aparecem só para leitura.

> Uma sugestão só aparece quando o campo `*_correta` é **`0` (falso)** *e* existe
> um `*_sugerida_id`. Deixar o campo nulo não gera linha nenhuma.

---

## Caminho 1 — o comando do portal (recomendado)

```bash
php artisan demo:ajustes
```

Ele cria (ou atualiza, sem duplicar) as **quatro contas** (um orientador e três
avaliadores), o projeto e as **três avaliações** completas, com as respostas da
rubrica preenchidas e a nota calculada.

```bash
# personalizando os e-mails e a senha inicial
php artisan demo:ajustes \
  --orientador=orientador@fetecms.test \
  --avaliador=avaliador.demo@fetecms.test \
  --senha='uma-senha-forte'
```

Os outros dois avaliadores saem do e-mail informado em `--avaliador`, com o
número no fim do usuário: `avaliador.demo2@…` e `avaliador.demo3@…`.

**Prefira este caminho.** A senha passa pelo hash do framework, as respostas da
rubrica são gravadas no formato JSON que o portal lê, a nota sai coerente com os
pesos do edital e rodar duas vezes não duplica nada — três coisas que é fácil
errar escrevendo `INSERT` à mão.

Se as contas já existirem, o comando **não troca a senha delas** — ele só
garante `is_demo = 1` e reaproveita o cadastro.

### Depois de rodar

1. Entre no portal com o **orientador demo**.
2. Abra **Ajustes** no menu.
3. Ligue o **Modo de teste** (o interruptor aparece só para conta demo) — ele
   ignora o período definido pela organização, então você não precisa esperar a
   data de início dos ajustes.
4. O projeto aparece com **2 sugestões pendentes** (área e subárea) e **2
   recomendações escritas**. Aceitar a troca de área aplica no projeto na hora;
   desfazer devolve o valor anterior.

---

## Caminho 2 — direto no banco (SQL)

Use quando não houver como rodar `artisan` no servidor. O SQL abaixo é
**PostgreSQL** (o banco de produção); as diferenças para SQLite estão anotadas.

### Antes de começar

Descubra os ids que vai usar:

```sql
-- a edição em que o projeto vai entrar (normalmente a padrão)
SELECT id, nome, padrao FROM edicoes ORDER BY id;

-- duas áreas DIFERENTES: a que o projeto terá e a que o avaliador vai sugerir
SELECT id, nome FROM areas ORDER BY nome;

-- uma subárea de cada uma delas
SELECT id, nome, area_id FROM subareas WHERE area_id IN (<area_atual>, <area_sugerida>);
```

### 1. O orientador demo

A senha precisa ser um hash **bcrypt**. Gere-o fora do SQL — por exemplo com
`php -r "echo password_hash('a-senha', PASSWORD_BCRYPT);"` — e cole no lugar de
`<hash_bcrypt>`. **Nunca** grave a senha em texto puro: o portal não conseguirá
autenticar e a conta fica inutilizável.

```sql
INSERT INTO users (name, email, password, role, is_active, is_demo, created_at, updated_at)
VALUES ('Orientador Demonstração', 'orientador.demo@fetecms.test',
        '<hash_bcrypt>', 'orientador', true, true, NOW(), NOW());

INSERT INTO orientador_profiles (user_id, cpf, telefone, data_nascimento, created_at, updated_at)
VALUES ((SELECT id FROM users WHERE email = 'orientador.demo@fetecms.test'),
        '00000000191', '67900000000', '1990-01-01', NOW(), NOW());
```

`cpf`, `telefone` e `data_nascimento` são obrigatórios em
`orientador_profiles`. O CPF precisa ser único na tabela — se `00000000191` já
existir, use outro.

### 2. O avaliador demo

```sql
INSERT INTO users (name, email, password, role, is_active, is_demo, created_at, updated_at)
VALUES ('Avaliador Demonstração', 'avaliador.demo@fetecms.test',
        '<hash_bcrypt>', 'avaliador', true, true, NOW(), NOW());

INSERT INTO avaliador_profiles (user_id, cpf, titulacao, area_id, created_at, updated_at)
VALUES ((SELECT id FROM users WHERE email = 'avaliador.demo@fetecms.test'),
        '00000000272', 'Mestrado (concluído)', <area_sugerida>, NOW(), NOW());
```

### 3. O projeto

```sql
INSERT INTO projetos (
    user_id, edicao_id, titulo, categoria, area_id, subarea_id,
    resumo, status, submitted_at, created_at, updated_at
) VALUES (
    (SELECT id FROM users WHERE email = 'orientador.demo@fetecms.test'),
    <edicao_id>,
    'Projeto de demonstração — ajustes de classificação',
    'fetecms',
    <area_atual>,
    <subarea_atual>,
    'Projeto fictício, criado para demonstrar a aba Ajustes e Pareceres do orientador.',
    'submetido',
    NOW(), NOW(), NOW()
);
```

`status` precisa ser exatamente **`'submetido'`** e `submitted_at` não pode ser
nulo — a aba só olha projetos submetidos.

### 4. A avaliação concluída — é ela que gera as sugestões

```sql
INSERT INTO avaliacoes (
    projeto_id, avaliador_id, status, nota, respostas,
    area_correta, area_sugerida_id,
    subarea_correta, subarea_sugerida_id,
    comentario_video, comentario_projeto,
    designacao_manual, concluida_em, created_at, updated_at
) VALUES (
    (SELECT id FROM projetos WHERE titulo = 'Projeto de demonstração — ajustes de classificação'),
    (SELECT id FROM users WHERE email = 'avaliador.demo@fetecms.test'),
    'concluida',
    8.00,
    '{}',
    false, <area_sugerida>,
    false, <subarea_sugerida>,
    'Exemplo de recomendação sobre o vídeo: melhorar o áudio da narração.',
    'Exemplo de recomendação sobre o projeto: detalhar a metodologia.',
    true,
    NOW(), NOW(), NOW()
);
```

Pontos que costumam dar errado aqui:

- **`status = 'concluida'`** (sem acento). Avaliação em rascunho ou em andamento
  não aparece na aba.
- **`area_correta = false`** — não nulo. É o falso explícito que significa "o
  avaliador disse que está errada".
- **`respostas`** é uma coluna JSON. `'{}'` basta para as **sugestões**, que só
  leem a classificação e os comentários — mas então o **parecer** da mesma tela
  sai com todas as etapas em "Não avaliado", porque é das respostas que saem os
  níveis. Para ver as duas metades cheias (e a nota coerente com elas), use o
  comando do Caminho 1.
- **`designacao_manual = true`** deixa claro na auditoria que essa avaliação não
  veio do algoritmo.

### Diferenças no SQLite

Troque `NOW()` por `CURRENT_TIMESTAMP` e `true`/`false` por `1`/`0`.

---

## Conferindo

```sql
-- deve devolver exatamente uma linha
SELECT p.titulo, a.status, a.area_correta, a.area_sugerida_id
FROM avaliacoes a
JOIN projetos p ON p.id = a.projeto_id
JOIN users u ON u.id = p.user_id
WHERE u.is_demo = true AND a.status = 'concluida';
```

Depois entre como o orientador demo, abra **Ajustes** e ligue o **Modo de
teste**. Se a tela ainda estiver vazia, confira, nesta ordem: `projetos.status`,
`avaliacoes.status`, `area_correta` (precisa ser falso, não nulo) e
`area_sugerida_id` (precisa apontar para uma área **diferente** da do projeto).

## Removendo o exemplo

Apagar o orientador demo leva junto o projeto e a avaliação (as FKs são
`cascadeOnDelete`):

```sql
DELETE FROM users WHERE email IN (
  'orientador@fetecms.test',
  'avaliador.demo@fetecms.test',
  'avaliador.demo2@fetecms.test',
  'avaliador.demo3@fetecms.test'
);
```

Enquanto ele existir, não atrapalha: as contas `is_demo` já ficam fora de todo
número e de toda decisão do portal.

Desde a Sprint 117 há um caminho de tela para isso: **Parametrização → Dados de
demonstração** lista tudo que é de ensaio (contas, projetos, avaliações, listas,
credenciamentos) e apaga item a item ou de uma vez — sem SQL.
