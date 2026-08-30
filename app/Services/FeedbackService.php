<?php

namespace App\Services;

use App\Enums\StatusDestinatario;
use App\Enums\StatusFeedback;
use App\Enums\TipoPerguntaFeedback;
use App\Enums\UnidadeLimiteResposta;
use App\Jobs\EnviarConviteFeedback;
use App\Models\Edicao;
use App\Models\Feedback;
use App\Models\FeedbackDestinatario;
use App\Models\FeedbackParticipacao;
use App\Models\FeedbackPergunta;
use App\Models\FeedbackResposta;
use App\Models\User;
use App\Support\ModelosAlternativas;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Comunicação → Feedback: o admin monta um questionário, escolhe os públicos e
 * dispara; quem é alcançado vê um balão ao entrar e recebe um convite por
 * e-mail.
 *
 * **As respostas são anônimas.** O serviço nunca grava resposta junto de
 * usuário: `feedback_participacoes` registra quem respondeu (para não pedir
 * duas vezes e para o "quantos responderam"), e `feedback_respostas` guarda o
 * conteúdo agrupado por um `envio` aleatório que não leva a ninguém.
 *
 * O disparo dos convites segue o desenho da mala direta — um job por
 * destinatário, com relatório por endereço —, então a recusa de um servidor
 * vira uma linha de falha e não derruba o resto.
 */
class FeedbackService
{
    public function __construct(private readonly PublicoUsuariosService $publicos) {}

    /**
     * Cria o pedido, monta a lista de convites e enfileira o envio.
     *
     * @param  array<string, mixed>  $dados
     */
    public function criar(array $dados, ?User $autor = null): Feedback
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            throw ValidationException::withMessages([
                'feedback' => 'Nenhuma edição em curso para publicar o feedback.',
            ]);
        }

        $publicos = $this->publicos->normalizar($dados['publicos'] ?? []);

        if ($publicos === []) {
            throw ValidationException::withMessages([
                'publicos' => 'Escolha ao menos um público para o feedback.',
            ]);
        }

        $feedback = DB::transaction(function () use ($dados, $autor, $edicao, $publicos) {
            $feedback = Feedback::create([
                'edicao_id' => $edicao->id,
                'titulo' => trim($dados['titulo']),
                'descricao' => isset($dados['descricao']) ? trim((string) $dados['descricao']) : null,
                'publicos' => array_map(fn ($p) => $p->value, $publicos),
                'status' => StatusFeedback::Enviando,
                'criado_por' => $autor?->id,
            ]);

            foreach (array_values($dados['perguntas'] ?? []) as $i => $pergunta) {
                $this->criarPergunta($feedback, $pergunta, $i);
            }

            $this->montarDestinatarios($feedback);

            return $feedback;
        });

        // Fora da transação: um job por destinatário, como na mala direta.
        foreach ($feedback->destinatarios()->pluck('id') as $id) {
            EnviarConviteFeedback::dispatch($id);
        }

        return $feedback->fresh(['perguntas']);
    }

    /**
     * Grava uma pergunta, normalizando o que depende do tipo. As opções de um
     * **modelo** são copiadas aqui: mexer no catálogo depois não altera pedido
     * já criado, o que manteria respostas antigas comparáveis com escalas novas.
     *
     * @param  array<string, mixed>  $dados
     */
    private function criarPergunta(Feedback $feedback, array $dados, int $ordem): FeedbackPergunta
    {
        $tipo = TipoPerguntaFeedback::tryFrom((string) ($dados['tipo'] ?? '')) ?? TipoPerguntaFeedback::Dissertativa;
        $alternativa = $tipo === TipoPerguntaFeedback::Alternativa;

        $opcoes = null;
        if ($alternativa) {
            $opcoes = ! empty($dados['modelo'])
                ? ModelosAlternativas::opcoesDe((string) $dados['modelo'])
                : array_values(array_filter(array_map(
                    fn ($o) => trim((string) $o),
                    (array) ($dados['opcoes'] ?? []),
                ), fn (string $o) => $o !== ''));

            if (count($opcoes) < 2) {
                throw ValidationException::withMessages([
                    'perguntas' => 'Cada pergunta de alternativas precisa de ao menos duas opções.',
                ]);
            }
        }

        return FeedbackPergunta::create([
            'feedback_id' => $feedback->id,
            'ordem' => $ordem,
            'tipo' => $tipo,
            'enunciado' => trim((string) $dados['enunciado']),
            'obrigatoria' => (bool) ($dados['obrigatoria'] ?? true),
            'opcoes' => $opcoes,
            'unidade' => $alternativa
                ? null
                : (UnidadeLimiteResposta::tryFrom((string) ($dados['unidade'] ?? '')) ?? UnidadeLimiteResposta::Palavras),
            'minimo' => $alternativa ? null : ($dados['minimo'] ?? null),
            'maximo' => $alternativa ? null : ($dados['maximo'] ?? null),
        ]);
    }

    /**
     * Congela a lista de quem recebe. É um snapshot de propósito: o relatório
     * precisa dizer para quem foi mesmo que a pessoa mude de e-mail ou saia do
     * público depois.
     */
    private function montarDestinatarios(Feedback $feedback): void
    {
        $usuarios = $this->publicos->queryUniao($feedback->publicosEnum())
            ->select(['id', 'name', 'email'])
            ->get();

        $agora = now();
        $linhas = $usuarios->map(fn (User $u) => [
            'feedback_id' => $feedback->id,
            'user_id' => $u->id,
            'nome' => $u->name,
            'email' => $u->email,
            // E-mail malformado entra como inválido no relatório em vez de
            // barrar o disparo inteiro — mesma regra da mala direta.
            'status' => filter_var($u->email, FILTER_VALIDATE_EMAIL)
                ? StatusDestinatario::Pendente->value
                : StatusDestinatario::Invalido->value,
            'erro' => filter_var($u->email, FILTER_VALIDATE_EMAIL) ? null : 'E-mail inválido.',
            'created_at' => $agora,
            'updated_at' => $agora,
        ])->all();

        foreach (array_chunk($linhas, 500) as $lote) {
            FeedbackDestinatario::insert($lote);
        }
    }

    /**
     * Situação do disparo — o que a barra de progresso e o relatório leem.
     *
     * @return array{total:int, pendente:int, enviado:int, falha:int, invalido:int, processados:int}
     */
    public function progressoEnvio(Feedback $feedback): array
    {
        $contagem = FeedbackDestinatario::where('feedback_id', $feedback->id)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $totais = ['total' => 0];
        foreach (StatusDestinatario::cases() as $status) {
            $totais[$status->value] = (int) ($contagem[$status->value] ?? 0);
            $totais['total'] += $totais[$status->value];
        }
        $totais['processados'] = $totais['total'] - $totais[StatusDestinatario::Pendente->value];

        return $totais;
    }

    /**
     * Marca o pedido como "no ar" quando não sobrou convite pendente. Chamado
     * pelo job a cada envio — é barato e evita depender de agendador.
     */
    public function concluirEnvioSeTerminou(Feedback $feedback): void
    {
        if ($feedback->status !== StatusFeedback::Enviando) {
            return;
        }

        $pendentes = FeedbackDestinatario::where('feedback_id', $feedback->id)
            ->where('status', StatusDestinatario::Pendente->value)
            ->exists();

        if (! $pendentes) {
            $feedback->update(['status' => StatusFeedback::Ativo]);
        }
    }

    /** Reenfileira só os que falharam — o "tentar de novo" do relatório. */
    public function reenviarFalhas(Feedback $feedback): int
    {
        $falhas = FeedbackDestinatario::where('feedback_id', $feedback->id)
            ->where('status', StatusDestinatario::Falha->value)
            ->pluck('id');

        if ($falhas->isEmpty()) {
            return 0;
        }

        FeedbackDestinatario::whereIn('id', $falhas)->update([
            'status' => StatusDestinatario::Pendente->value,
            'erro' => null,
        ]);
        $feedback->update(['status' => StatusFeedback::Enviando]);

        foreach ($falhas as $id) {
            EnviarConviteFeedback::dispatch($id);
        }

        return $falhas->count();
    }

    public function encerrar(Feedback $feedback): Feedback
    {
        $feedback->update([
            'status' => StatusFeedback::Encerrado,
            'encerrado_em' => now(),
        ]);

        return $feedback->fresh();
    }

    // ----------------------------------------------------------------- //
    // Lado de quem responde                                             //
    // ----------------------------------------------------------------- //

    /**
     * O feedback pendente desta pessoa — o que o **balão** mostra. `null` quando
     * não há nenhum, quando ela já respondeu ou quando dispensou.
     *
     * @return array<string, mixed>|null
     */
    public function pendentePara(User $user): ?array
    {
        $feedback = $this->pendentesDe($user)->first();

        return $feedback === null ? null : $this->paraResponder($feedback);
    }

    /**
     * Todos os que ainda esperam esta pessoa, **incluindo os que ela dispensou**
     * — é a lista do perfil, a segunda chance de quem fechou o balão.
     *
     * @return list<array<string, mixed>>
     */
    public function listarPara(User $user): array
    {
        return $this->pendentesDe($user, incluirDispensados: true)
            ->map(fn (Feedback $f) => $this->paraResponder($f))
            ->values()
            ->all();
    }

    /**
     * Os feedbacks no ar que ainda esperam esta pessoa.
     *
     * Quem já **respondeu** nunca entra. Quem **dispensou** sai do balão, mas
     * continua na lista do perfil. O alcance é conferido em PHP porque cada
     * feedback tem a sua combinação de públicos — não dá para resolver numa
     * consulta só sem reescrever o `PublicoUsuariosService`.
     *
     * @return Collection<int, Feedback>
     */
    private function pendentesDe(User $user, bool $incluirDispensados = false): Collection
    {
        return Feedback::query()
            ->where('status', StatusFeedback::Ativo->value)
            ->whereDoesntHave('participacoes', function ($q) use ($user, $incluirDispensados) {
                $q->where('user_id', $user->id)
                    ->where(function ($p) use ($incluirDispensados) {
                        $p->whereNotNull('respondido_em');
                        if (! $incluirDispensados) {
                            $p->orWhereNotNull('dispensado_em');
                        }
                    });
            })
            ->with('perguntas')
            ->orderBy('created_at')
            ->get()
            ->filter(fn (Feedback $f) => $this->publicos->alcanca($user, $f->publicosEnum()));
    }

    /** @return array<string, mixed> */
    private function paraResponder(Feedback $feedback): array
    {
        return [
            'id' => $feedback->id,
            'titulo' => $feedback->titulo,
            'descricao' => $feedback->descricao,
            'perguntas' => $feedback->perguntas->map(fn (FeedbackPergunta $p) => $p->paraApi())->all(),
        ];
    }

    /** Registra que o balão apareceu para esta pessoa. */
    public function marcarVisto(Feedback $feedback, User $user): void
    {
        $p = $this->participacao($feedback, $user);

        if ($p->visto_em === null) {
            $p->update(['visto_em' => now()]);
        }
    }

    /** A pessoa fechou o balão: ele não volta, mas ela ainda pode responder no perfil. */
    public function dispensar(Feedback $feedback, User $user): void
    {
        $this->participacao($feedback, $user)->update(['dispensado_em' => now()]);
    }

    /**
     * Grava um preenchimento. As respostas vão **sem dono**, agrupadas por um
     * `envio` aleatório; só a participação registra que esta pessoa respondeu.
     *
     * @param  array<int|string, mixed>  $respostas  pergunta_id => valor
     */
    public function responder(Feedback $feedback, User $user, array $respostas): void
    {
        if (! $feedback->aberto()) {
            throw ValidationException::withMessages([
                'feedback' => 'Este pedido de feedback já foi encerrado.',
            ]);
        }

        $participacao = $this->participacao($feedback, $user);

        if ($participacao->respondido_em !== null) {
            throw ValidationException::withMessages([
                'feedback' => 'Você já respondeu a este feedback.',
            ]);
        }

        $valores = $this->validarRespostas($feedback, $respostas);
        $envio = (string) Str::uuid();
        $agora = now();

        DB::transaction(function () use ($feedback, $participacao, $valores, $envio, $agora) {
            $linhas = [];
            foreach ($valores as $perguntaId => $valor) {
                $linhas[] = [
                    'feedback_id' => $feedback->id,
                    'pergunta_id' => $perguntaId,
                    'envio' => $envio,
                    'valor' => $valor,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ];
            }

            if ($linhas !== []) {
                FeedbackResposta::insert($linhas);
            }

            $participacao->update(['respondido_em' => $agora]);
        });
    }

    /**
     * Confere cada resposta contra a sua pergunta e devolve os valores limpos.
     *
     * @param  array<int|string, mixed>  $respostas
     * @return array<int, string>
     */
    private function validarRespostas(Feedback $feedback, array $respostas): array
    {
        $limpas = [];
        $erros = [];

        foreach ($feedback->perguntas as $pergunta) {
            $bruto = $respostas[$pergunta->id] ?? $respostas[(string) $pergunta->id] ?? null;
            $valor = is_string($bruto) ? trim($bruto) : '';
            $campo = "respostas.{$pergunta->id}";

            if ($valor === '') {
                if ($pergunta->obrigatoria) {
                    $erros[$campo] = 'Esta pergunta é obrigatória.';
                }

                continue;
            }

            if ($pergunta->ehAlternativa()) {
                if (! in_array($valor, $pergunta->opcoes ?? [], true)) {
                    $erros[$campo] = 'Escolha uma das alternativas.';

                    continue;
                }
            } else {
                $erro = $this->erroDeLimite($pergunta, $valor);
                if ($erro !== null) {
                    $erros[$campo] = $erro;

                    continue;
                }
            }

            $limpas[$pergunta->id] = $valor;
        }

        if ($erros !== []) {
            throw ValidationException::withMessages($erros);
        }

        return $limpas;
    }

    /** A resposta cabe no limite da pergunta? Devolve a mensagem, ou null. */
    private function erroDeLimite(FeedbackPergunta $pergunta, string $valor): ?string
    {
        $unidade = $pergunta->unidade ?? UnidadeLimiteResposta::Palavras;
        $medida = $unidade->medir($valor);

        if ($pergunta->minimo !== null && $medida < $pergunta->minimo) {
            return "Escreva ao menos {$pergunta->minimo} {$unidade->label()}.";
        }

        if ($pergunta->maximo !== null && $medida > $pergunta->maximo) {
            return "Escreva no máximo {$pergunta->maximo} {$unidade->label()}.";
        }

        return null;
    }

    private function participacao(Feedback $feedback, User $user): FeedbackParticipacao
    {
        return FeedbackParticipacao::firstOrCreate([
            'feedback_id' => $feedback->id,
            'user_id' => $user->id,
        ]);
    }
}
