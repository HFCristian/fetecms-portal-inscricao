# Dados de ensaio do balcão de credenciamento

A aba **Credenciamento** só lista **finalistas**, e finalista é quem está na
**lista final vigente** da edição. Isso torna o balcão impossível de treinar
antes da hora: ou a organização já publicou a lista de verdade — e aí qualquer
ensaio mexe em gente real —, ou não há ninguém na tela.

A Sprint 88 resolveu isso com uma **segunda trilha de lista final**.

---

## Como funciona

`listas_finais` ganhou a coluna **`demo`**. Cada edição pode ter **duas**
listas vigentes ao mesmo tempo: a oficial (`demo = 0`) e a de demonstração
(`demo = 1`). Elas não se encostam — publicar uma **não** encerra a outra, e
`ListaFinal::vigente()` sem argumento continua devolvendo a oficial para todo o
resto do portal (ranking, registros, exportação do TXT).

Quem escolhe entre as duas é o **modo demo** da aba Credenciamento:

| Modo demo | Lista usada | Janela do evento |
|-----------|-------------|------------------|
| desligado | oficial     | respeitada       |
| ligado    | demonstração| ignorada         |

O interruptor só aparece para quem tem **`is_demo = 1`** (aba Administradores →
botão de modo demo na linha do administrador) **e** abre a aba Credenciamento
pelos escopos. Ligar o modo demo sem ter a permissão não faz nada: o backend
confere `is_demo` antes de trocar de lista (`CredenciamentoService::emTeste`).

Consequência prática: **o ensaio não alcança um finalista de verdade**, nem
forçando `?teste=1` na URL — a ficha de um projeto oficial responde 404 em modo
demo, e a de um projeto de demonstração responde 404 fora dele.

---

## Montando os dados

```bash
php artisan demo:credenciamento
```

O comando cria, sem duplicar nada se rodar de novo:

1. um **orientador demo** (`orientador.credenciamento@fetecms.test`, senha
   `fetecms-demo`), com `is_demo = 1` — é isso que mantém os projetos de
   mentira fora do painel, do ranking e da distribuição (`Projeto::semDemo()`);
2. **três projetos submetidos**, um por categoria, cada um com alunos (nome,
   série e camiseta preenchidos) e um coorientador;
3. a **lista final de demonstração** da edição padrão, com os três dentro;
4. a **lista de documentos** de aluno, orientador e coorientador — **só se o
   catálogo estiver vazio**. Ele é global (vale para todas as edições), então o
   comando nunca sobrescreve o que a organização já configurou em
   Parametrização → Credenciamento.

Opções: `--orientador=`, `--senha=` e `--projetos=` (1 a 3).

---

## Ensaiando

1. Marque o administrador como **demo** em **Administradores** (botão de modo
   demo na linha dele).
2. Entre com essa conta, abra **Credenciamento** e ligue **Modo demo**.
3. A tela avisa, em todas as etapas, que a lista é fictícia. Credencie à
   vontade: a conferência é gravada nos projetos de demonstração e entra em
   Registros → Credenciamento, sem tocar em nenhum finalista.

O interruptor vale **só para a sua conta e a sua sessão** (fica no
`sessionStorage`), então dois atendentes podem estar em modos diferentes ao
mesmo tempo.

---

## Limpando depois

Não há comando de limpeza: apagar o orientador demo em cascata leva os projetos,
os alunos e a composição da lista.

```sql
-- Confira o e-mail antes de rodar.
DELETE FROM users WHERE email = 'orientador.credenciamento@fetecms.test';
DELETE FROM listas_finais WHERE demo = 1;
```

Manter os dados também é seguro: nada com `is_demo = 1` entra em número algum da
feira.
