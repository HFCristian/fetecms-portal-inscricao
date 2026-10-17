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
4. Cada projeto leva os **documentos** que a rubrica presencial pergunta, em
   PDF fictício gerado na hora e anexado ao projeto (o avaliador abre em
   *Mostrar* → *Documentos*, no wizard): projeto de pesquisa, diário de bordo,
   pasta de referências, banner (A3) e, no projeto com estudantes, o TCLE/TALE.
   Todo PDF diz "DOCUMENTO FICTÍCIO" no topo. O conteúdo mora em
   `database/data/demo/projetos-presenciais.php`.
5. O avaliador fica **ativado** em todos os turnos da agenda que ainda não
   terminaram.
6. O catálogo de **itens da checagem** ganha três itens (banner, diário de
   bordo, protótipo) — **só se estiver vazio**.

Opções: `--avaliador=`, `--orientador=` e `--senha=`.

> A agenda vem de **Parametrização → Datas e períodos** (dias do evento) e de
> **Avaliação presencial → Distribuição presencial** (horário de cada turno).
> Sem ela os turnos não existem e nada é ativado — o *Modo de teste* do
> avaliador funciona assim mesmo, porque ignora o relógio.

---

## Os três cenários

Cada projeto passa por um caminho diferente da rubrica de 2026, para o ensaio
cobrir as perguntas condicionais e as sem valor. As respostas abaixo são as que
os documentos sustentam; o que é conversa no estande (domínio do tema, uso de
IA) o avaliador responde como se tivesse ouvido.

| Estande | Projeto | Documentos | O que exercita |
|---------|---------|------------|----------------|
| 901 | Horta vertical irrigada por gotejamento solar | projeto, diário, pasta, banner | 1.1 = Não (sem ética) · 4.3 = **Não se aplica (QR Code)** · 14.1 = Não (a 14.2 vale como Sim) |
| 902 | Jogo de tabuleiro para ensinar frações | projeto, diário, pasta, banner, **TCLE/TALE** | 1.1 = Sim e 1.2 = Sim (pesquisa com estudantes) · 5 principais destacadas · 14.1 = Sim e 14.2 = Sim (IA declarada no banner) |
| 903 | Filtro de água com carvão de casca de coco | projeto, pasta, banner | 3.1 = **Não** (sem diário; a 3.2 some e vale 0) · 5.1 = **Não** (banner sem a discussão) · 14.1 = Sim e 14.2 = Não (IA sem declarar) |

Para ver o **alerta de ética** na Distribuição presencial, responda 1.2 = Não
num deles (o 902, por exemplo) e envie.

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
Apagar o orientador demo leva junto projetos, documentos (inclusive os PDFs
no disco), credenciamentos, checagens e avaliações presenciais; apagar o avaliador leva as ativações e as avaliações
dele.

**Atenção:** gerar os **Turnos de Apresentação** de verdade (Mapa do Evento)
recria a lista de turnos da edição inteira, e os projetos de ensaio perdem o
turno. Rode o comando de novo depois disso.
