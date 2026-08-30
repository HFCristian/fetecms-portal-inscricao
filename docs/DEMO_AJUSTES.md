# Projeto-exemplo da aba "Ajustes" (orientador demo)

A aba **Ajustes** do orientador só mostra alguma coisa quando existe, no banco,
um projeto **submetido** que recebeu uma **avaliação concluída** em que o
avaliador disse que a classificação está errada. Sem esse conjunto a tela abre
vazia, mesmo com o período aberto — não há bug a procurar.

Este documento explica como montar esse conjunto **em produção**: primeiro pelo
comando do portal (recomendado), depois em SQL puro, para quem só tem acesso ao
banco.

---

## O que precisa existir

Quatro registros, nesta ordem de dependência:

| # | Tabela | Papel no exemplo |
|---|--------|------------------|
| 1 | `users` + `orientador_profiles` | o **orientador demo**, dono do projeto |
| 2 | `users` + `avaliador_profiles` | o **avaliador demo**, autor da avaliação |
| 3 | `projetos` | o projeto de mentira, com `status = 'submetido'` |
| 4 | `avaliacoes` | a avaliação **concluída** que carrega as sugestões |

As duas contas nascem com **`is_demo = 1`**. Isso não é enfeite: é o que mantém
o projeto de mentira **fora do painel, do ranking dos projetos, da lista final e
da distribuição automática** (`Projeto::semDemo()`). Se você criar as contas sem
essa coluna, o projeto-exemplo entra na contagem de camisetas que a organização
vai encomendar.

O que a aba Ajustes lê da avaliação é este par de colunas:

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

Ele cria (ou atualiza, sem duplicar) as duas contas, o projeto e a avaliação
completa, com as respostas da rubrica preenchidas e a nota calculada.

```bash
# personalizando os e-mails e a senha inicial
php artisan demo:ajustes \
  --orientador=orientador.demo@fetecms.test \
  --avaliador=avaliador.demo@fetecms.test \
  --senha='uma-senha-forte'
```

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
    'Projeto fictício, criado para demonstrar a aba Ajustes do orientador.',
    'submetido',
    NOW(), NOW(), NOW()
);
```

`status` precisa ser exatamente **`'submetido'`** e `submitted_at` não pode ser
nulo — a aba Ajustes só olha projetos submetidos.

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
- **`respostas`** é uma coluna JSON. `'{}'` basta para o exemplo: a aba Ajustes
  não lê as respostas, só a classificação e os comentários. A **nota**, porém,
  fica descolada das respostas — se quiser as duas coerentes, use o comando do
  Caminho 1.
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
DELETE FROM users WHERE email IN ('orientador.demo@fetecms.test', 'avaliador.demo@fetecms.test');
```

Enquanto ele existir, não atrapalha: as contas `is_demo` já ficam fora de todo
número e de toda decisão do portal.
