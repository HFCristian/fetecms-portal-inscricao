<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\StatusAvaliacao;
use App\Models\Avaliacao;
use App\Models\AvaliacaoPresencial;
use App\Models\Edicao;
use App\Models\Projeto;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Aba **Certificados**: os números de cada avaliador e a declaração nominal.
 *
 * - **Avaliadores por fase.** O certificado da fase online sai **antes** da
 *   presencial (há avaliador pedindo para processo seletivo com prazo), então
 *   as duas fases são contadas **separadas**. Conta a avaliação **concluída** —
 *   inclusive a que a organização desconsiderou depois, porque o trabalho
 *   aconteceu (a mesma regra do card de certificado do perfil). A avaliação
 *   feita *pela organização* não conta para ninguém: o avaliador dela é um admin.
 * - **Declaração nominal.** Os títulos dos projetos que um avaliador avaliou,
 *   em PDF, para quem pede a declaração com a lista.
 *
 * A lista de certificados em si (fases, público, carga horária, SICERT) mora no
 * {@see CertificadoListaService} (Sprints 259–260), que lê os avaliadores daqui.
 *
 * Tudo no recorte da edição em escopo (os projetos seguem o `EdicaoScope`) e sem
 * conta de ensaio (`is_demo`).
 */
class CertificadosService
{
    public const FASE_ONLINE = 'online';

    public const FASE_PRESENCIAL = 'presencial';

    public const FASE_TODAS = 'todas';

    // --- Avaliadores ----------------------------------------------------------

    /**
     * Avaliadores com o número de avaliações concluídas em cada fase. `fase`
     * diz quem entra: quem avaliou online, quem avaliou presencialmente, ou
     * quem avaliou em qualquer uma.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function avaliadores(string $fase = self::FASE_ONLINE, string $busca = ''): Collection
    {
        $busca = mb_strtolower(trim($busca));

        return $this->queryAvaliadores()
            ->when($busca !== '', fn (Builder $q) => $q->where(fn (Builder $s) => $s
                ->whereRaw('LOWER(name) LIKE ?', ['%'.$busca.'%'])
                ->orWhereRaw('LOWER(email) LIKE ?', ['%'.$busca.'%'])))
            ->get()
            ->map(fn (User $u) => [
                'id' => $u->id,
                'nome' => $u->name,
                'cpf' => $this->cpf($u->avaliadorProfile?->cpf),
                'email' => $u->email,
                'area' => $u->avaliadorProfile?->area?->nome,
                'subarea' => $u->avaliadorProfile?->subarea?->nome,
                'comissao_especial' => (bool) $u->avaliadorProfile?->comissao_especial,
                'online' => (int) $u->online_count,
                'presencial' => (int) $u->presencial_count,
            ])
            ->filter(fn (array $a) => match ($fase) {
                self::FASE_PRESENCIAL => $a['presencial'] > 0,
                self::FASE_TODAS => $a['online'] + $a['presencial'] > 0,
                default => $a['online'] > 0,
            })
            ->values();
    }

    // --- Declaração nominal ----------------------------------------------------

    /**
     * Os projetos que este avaliador avaliou, por fase — o conteúdo da
     * declaração nominal.
     *
     * @return array<string, mixed>
     */
    public function projetosDoAvaliador(User $avaliador): array
    {
        abort_unless($avaliador->role === Role::Avaliador, 404, 'Avaliador não encontrado.');
        $avaliador->loadMissing('avaliadorProfile.area');

        $online = Avaliacao::query()
            ->where('avaliador_id', $avaliador->id)
            ->where('status', StatusAvaliacao::Concluida->value)
            ->whereHas('projeto')
            ->with('projeto:id,titulo,area_id,categoria', 'projeto.area:id,nome')
            ->orderBy('concluida_em')
            ->get()
            ->map(fn (Avaliacao $a) => $this->linhaProjeto($a->projeto, $a->concluida_em));

        $presencial = AvaliacaoPresencial::query()
            ->where('avaliador_id', $avaliador->id)
            ->where('status', StatusAvaliacao::Concluida->value)
            ->whereHas('projeto')
            ->with('projeto:id,titulo,area_id,categoria', 'projeto.area:id,nome')
            ->orderBy('concluida_em')
            ->get()
            ->map(fn (AvaliacaoPresencial $a) => $this->linhaProjeto($a->projeto, $a->concluida_em));

        return [
            'avaliador' => [
                'id' => $avaliador->id,
                'nome' => $avaliador->name,
                'cpf' => $this->cpf($avaliador->avaliadorProfile?->cpf),
                'email' => $avaliador->email,
                'area' => $avaliador->avaliadorProfile?->area?->nome,
            ],
            'edicao' => Edicao::atual()?->nome,
            'online' => $online->values()->all(),
            'presencial' => $presencial->values()->all(),
        ];
    }

    public function declaracaoPdf(User $avaliador): string
    {
        return app(PdfService::class)->render('pdf.declaracao-avaliador', $this->projetosDoAvaliador($avaliador) + [
            'gerado_em' => now()->format('d/m/Y H:i'),
        ]);
    }

    // --- Internos ----------------------------------------------------------------

    /**
     * Avaliadores (não demo) com as concluídas de cada fase **desta edição** — o
     * `whereHas('projeto')` é o que traz o recorte da edição para a contagem.
     *
     * @return Builder<User>
     */
    private function queryAvaliadores(): Builder
    {
        $concluidas = fn ($q) => $q->where('status', StatusAvaliacao::Concluida->value)->whereHas('projeto');

        return User::query()
            ->where('role', Role::Avaliador->value)
            ->where('is_demo', false)
            ->with(['avaliadorProfile.area:id,nome', 'avaliadorProfile.subarea:id,nome'])
            ->withCount([
                'avaliacoes as online_count' => $concluidas,
                'avaliacoesPresenciais as presencial_count' => $concluidas,
            ])
            ->orderBy('name');
    }

    /** @return array<string, mixed> */
    private function linhaProjeto(?Projeto $projeto, $concluidaEm): array
    {
        return [
            'projeto_id' => $projeto?->id,
            'titulo' => $projeto?->titulo,
            'area' => $projeto?->area?->nome,
            'categoria' => $projeto?->categoria?->label(),
            'concluida_em' => $concluidaEm?->format('d/m/Y'),
        ];
    }

    private function cpf(?string $cpf): ?string
    {
        $d = preg_replace('/\D/', '', (string) $cpf);

        return strlen((string) $d) === 11
            ? preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $d)
            : null;
    }
}
