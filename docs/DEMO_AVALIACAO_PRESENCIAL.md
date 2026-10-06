# Ensaio da avaliação presencial

A avaliação no estande só abre **durante um turno do evento** e só para quem a
organização **ativou** naquele turno, na cabine da avaliação. Os projetos que
ela alcança precisam estar **credenciados** e com o estande **checado**. Antes
do evento, nada disso existe — e é justamente quando é preciso ensaiar.

O comando abaixo deixa a conta `avaliador@fetecms.test` pronta para isso, sem
tocar em nenhum finalista, lista ou avaliador de verdade.

---

## Montando os dados

```bash
php artisan demo:avaliacao-presencial
```

Na edição padrão, sem duplicar nada se rodar de novo:

1. **`avaliador@fetecms.test`** vira **conta demo** e **aceita** o presencial.
   A marca demo é o que libera o *Modo de teste* na tela dele, e também o tira
   da distribuição e dos rankings de verdade. Se a conta não existir, ela é
   criada (senha `password`, ou a de `--senha=`).
2. Um **orientador demo** (`orientador.presencial@fetecms.test`) com **três
   projetos** entra na **lista final de demonstração** — a mesma do
   `demo:credenciamento`, que corre em paralelo à oficial.
3. Os três ficam no turno que a agenda tem em foco, com estande **901, 902 e
   903** (fora da prancha), **credenciados** e com o estande **checado** na
   trilha de ensaio.
4. O avaliador fica **ativado** em todos os turnos da agenda que ainda não
   terminaram.
5. O catálogo de **itens da checagem** ganha três itens (banner, diário de
   bordo, protótipo) — **só se estiver vazio**.

Opções: `--avaliador=`, `--orientador=` e `--senha=`.

> A agenda vem de **Parametrização → Datas e períodos** (dias do evento) e de
> **Avaliação presencial → Distribuição presencial** (horário de cada turno).
> Sem ela os turnos não existem e nada é ativado — o *Modo de teste* do
> avaliador funciona assim mesmo, porque ignora o relógio.

---

## Ensaiando

**Como avaliador** — entre com `avaliador@fetecms.test`, abra a aba
**Avaliação presencial** e ligue o **Modo de teste**. Os três estandes aparecem
em *Estandes disponíveis*; *Avaliar* abre o wizard: leitura do projeto, um
passo por seção da rubrica, o checklist dos itens e o parecer.

**Como admin** — marque um administrador como demo (aba Administradores), abra
**Avaliação presencial → Distribuição presencial** e ligue o **Modo de teste**:
a tela passa a usar a lista de demonstração e só os avaliadores demo. Dali dá
para ativar/desativar o avaliador no turno, **Distribuir**, **Redistribuir**,
designar à mão e retirar.

---

## Limpando

Tudo nasce marcado como demonstração: **Parametrização → Dados de
demonstração** lista as contas e os projetos e apaga o que for preciso.
Apagar o orientador demo leva junto projetos, credenciamentos, checagens e
avaliações presenciais; apagar o avaliador leva as ativações e as avaliações
dele.

**Atenção:** gerar os **Turnos de Apresentação** de verdade (Mapa do Evento)
recria a lista de turnos da edição inteira, e os projetos de ensaio perdem o
turno. Rode o comando de novo depois disso.
