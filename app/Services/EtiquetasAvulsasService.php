<?php

namespace App\Services;

use App\Enums\AbaAdmin;
use App\Enums\Role;
use App\Enums\Turno;
use App\Models\ContaTemporaria;
use App\Models\Edicao;
use App\Models\Interprete;
use App\Models\User;
use App\Support\CodigoParticipante;
use App\Support\EtiquetasPdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Credenciamento → **Etiquetas avulsas** (Sprint 218): reimprimir a etiqueta de
 * uma pessoa sem gerar a folha inteira de novo — o crachá molhou, rasgou ou
 * ficou em casa.
 *
 * Quatro origens, e a etiqueta sai **igual à da folha original** em todas, porque
 * o servidor monta o conteúdo a partir do cadastro (o cliente só diz de quem é):
 *
 * - **participantes** da lista final vigente — a mesma linha de
 *   {@see IdentificacaoService::participantes()}, com turno e estande;
 * - **avaliadores** e **voluntários** (contas temporárias dos balcões), que até
 *   aqui não tinham crachá: ganharam os papéis `V` e `T` do
 *   {@see CodigoParticipante}, no mesmo formato, e os leitores dos balcões os
 *   reconhecem para recusar dizendo de quem são;
 * - **intérpretes**, com o código `I` que já existia.
 *
 * E a **personalizada**, que leva só os campos da etiqueta normal (nome, papel,
 * turno, estande e código), digitados — para o caso que nenhuma das origens
 * cobre. O código dela é texto livre e opcional: em branco, a etiqueta sai sem
 * QR e sem barras.
 *
 * O PDF é a mesma folha A4 de 16 etiquetas, começando na posição escolhida: a
 * avulsa sai numa folha já usada. Cada geração entra em Registros →
 * Credenciamento, com de quem eram as etiquetas.
 */
class EtiquetasAvulsasService
{
    public const GRUPOS = ['participantes', 'avaliadores', 'interpretes', 'voluntarios'];

    public const PERSONALIZADA = 'personalizada';

    /** Teto de etiquetas por geração: quatro folhas. Avulsa não é a folha inteira. */
    public const MAXIMO = 64;

    /** Quantas pessoas a busca devolve de uma vez. */
    private const LIMITE_BUSCA = 30;

    public function __construct(
        private readonly CredenciamentoService $credenciamento,
        private readonly IdentificacaoService $identificacao,
        private readonly InterpretesService $interpretes,
        private readonly RegistroAtividadeService $registros,
    ) {}

    /**
     * As pessoas de um grupo que batem com a busca (nome, código, e o projeto
     * ou o e-mail, conforme o grupo), já com o que vai na etiqueta.
     *
     * @return list<array<string, mixed>>
     */
    public function buscar(string $grupo, string $busca, ?User $user = null, bool $teste = false): array
    {
        $termo = $this->normalizar($busca);
        $bate = fn (string ...$campos) => $termo === ''
            || collect($campos)->contains(fn (string $c) => str_contains($this->normalizar($c), $termo));

        return $this->candidatos($grupo, $user, $teste)
            ->filter(fn (array $c) => $bate($c['nome'], (string) $c['codigo'], (string) ($c['detalhe'] ?? ''), (string) ($c['email'] ?? '')))
            ->take(self::LIMITE_BUSCA)
            ->map(fn (array $c) => collect($c)->except('email')->all())
            ->values()
            ->all();
    }

    /**
     * O PDF das etiquetas pedidas, a partir da posição `$inicio` da primeira
     * folha. Cada item é `{grupo, id}` (o `id` do participante é o código do
     * crachá) ou `{grupo: personalizada, nome, papel, turno, estande, codigo}`.
     *
     * @param  list<array<string, mixed>>  $itens
     */
    public function pdf(array $itens, int $inicio, User $admin, bool $teste = false): string
    {
        $etiquetas = $this->resolver($itens, $admin, $teste);

        $pdf = EtiquetasPdf::gerar(
            'Etiquetas avulsas',
            sprintf('%s · gerado em %s · %d etiqueta(s)',
                Edicao::atual()?->nome ?? 'FETECMS', now()->format('d/m/Y H:i'), count($etiquetas)),
            $etiquetas,
            $inicio,
        );

        // Registra depois de o PDF existir: o que fica na trilha é o que saiu.
        $this->registros->etiquetasAvulsas(
            $admin,
            array_map(fn (array $e) => trim($e['nome'].' ('.($e['papel_label'] !== '' ? $e['papel_label'] : 'sem papel').')'), $etiquetas),
            $inicio,
            $this->credenciamento->emTeste($admin, $teste),
        );

        return $pdf;
    }

    /**
     * Do pedido à etiqueta, na ordem pedida. Quem não é achado barra a geração
     * inteira dizendo qual — imprimir as outras e calar sobre uma confundiria o
     * balcão.
     *
     * @param  list<array<string, mixed>>  $itens
     * @return list<array{papel_label: string, nome: string, codigo: ?string, turno: ?string, estande: ?int}>
     */
    private function resolver(array $itens, User $user, bool $teste): array
    {
        $porGrupo = [];
        $etiquetas = [];

        foreach ($itens as $n => $item) {
            $grupo = $item['grupo'];

            if ($grupo === self::PERSONALIZADA) {
                $etiquetas[] = $this->personalizada($item);

                continue;
            }

            $porGrupo[$grupo] ??= $this->candidatos($grupo, $user, $teste)->keyBy(fn (array $c) => (string) $c['id']);
            $achado = $porGrupo[$grupo]->get((string) $item['id']);

            if ($achado === null) {
                throw ValidationException::withMessages([
                    "itens.{$n}.id" => match ($grupo) {
                        'participantes' => 'Este participante não está mais na lista final vigente.',
                        'avaliadores' => 'Avaliador não encontrado ou inativo.',
                        'interpretes' => 'Intérprete não encontrado nesta edição.',
                        default => 'Voluntário não encontrado.',
                    },
                ]);
            }

            $etiquetas[] = array_intersect_key($achado, array_flip(['papel_label', 'nome', 'codigo', 'turno', 'estande']));
        }

        return $etiquetas;
    }

    /**
     * Quem pode receber etiqueta num grupo, no formato da busca: `id` (a
     * referência que volta no pedido), o que vai na etiqueta e um `detalhe`
     * para a tela distinguir homônimos.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function candidatos(string $grupo, ?User $user, bool $teste): Collection
    {
        return match ($grupo) {
            'participantes' => $this->participantes($user, $teste),
            'avaliadores' => $this->avaliadores(),
            'interpretes' => $this->interpretesDaEdicao(),
            'voluntarios' => $this->voluntarios(),
            default => collect(),
        };
    }

    /** A equipe dos finalistas — da lista demo, no modo de teste. */
    private function participantes(?User $user, bool $teste): Collection
    {
        $lista = $this->credenciamento->listaFinal($user, $teste);

        if ($lista === null) {
            return collect();
        }

        return $this->identificacao->participantes($lista)->map(fn (array $p) => [
            'grupo' => 'participantes',
            'id' => $p['codigo'],
            'nome' => $p['nome'],
            'papel_label' => $p['papel_label'],
            'codigo' => $p['codigo'],
            'turno' => $p['turno'],
            'estande' => $p['estande'],
            'detalhe' => $p['projeto'],
        ]);
    }

    private function avaliadores(): Collection
    {
        $ano = $this->ano();

        return User::query()
            ->where('role', Role::Avaliador)
            ->where('is_active', true)
            ->with('avaliadorProfile.area:id,nome')
            ->orderBy('name')
            ->get()
            ->map(fn (User $u) => [
                'grupo' => 'avaliadores',
                'id' => $u->id,
                'nome' => $u->name,
                'papel_label' => CodigoParticipante::papelLabel(CodigoParticipante::PAPEL_AVALIADOR),
                'codigo' => CodigoParticipante::montar($ano, 0, $u->avaliadorProfile?->cpf, CodigoParticipante::PAPEL_AVALIADOR, $u->id),
                'turno' => null,
                'estande' => null,
                'detalhe' => $u->avaliadorProfile?->area?->nome,
                'email' => $u->email,
            ]);
    }

    private function interpretesDaEdicao(): Collection
    {
        $etiquetas = $this->interpretes->etiquetas();
        $cpfs = Interprete::whereIn('id', array_column($etiquetas, 'interprete_id'))->pluck('cpf', 'id');

        return collect($etiquetas)->map(fn (array $e) => [
            'grupo' => 'interpretes',
            'id' => $e['interprete_id'],
            'nome' => $e['nome'],
            'papel_label' => $e['papel_label'],
            'codigo' => $e['codigo'],
            'turno' => $e['turno'] === '' ? null : $e['turno'],
            'estande' => null,
            'detalhe' => $e['turno'] === '' ? null : $e['turno'],
            // A busca também acha pelo CPF, como o check-in de intérprete.
            'email' => (string) ($cpfs[$e['interprete_id']] ?? ''),
        ]);
    }

    /** As contas temporárias dos balcões que não foram removidas. */
    private function voluntarios(): Collection
    {
        $ano = $this->ano();

        return ContaTemporaria::query()
            ->whereNull('removida_em')
            ->with('user:id,name,email')
            ->get()
            ->filter(fn (ContaTemporaria $c) => $c->user !== null)
            ->sortBy(fn (ContaTemporaria $c) => $this->normalizar($c->user->name))
            ->map(fn (ContaTemporaria $c) => [
                'grupo' => 'voluntarios',
                'id' => $c->user_id,
                'nome' => $c->user->name,
                'papel_label' => CodigoParticipante::papelLabel(CodigoParticipante::PAPEL_VOLUNTARIO),
                'codigo' => CodigoParticipante::montar($ano, 0, $c->cpf, CodigoParticipante::PAPEL_VOLUNTARIO, $c->user_id),
                'turno' => null,
                'estande' => null,
                'detalhe' => implode(' · ', array_filter([AbaAdmin::tryFrom((string) $c->setor)?->label(), $c->curso])),
                'email' => $c->user->email,
            ])
            ->values();
    }

    /**
     * A etiqueta digitada. Os campos são os da etiqueta normal; o turno é um
     * dos dois da feira, escrito como nas outras.
     *
     * @param  array<string, mixed>  $item
     * @return array{papel_label: string, nome: string, codigo: ?string, turno: ?string, estande: ?int}
     */
    private function personalizada(array $item): array
    {
        $codigo = trim((string) ($item['codigo'] ?? ''));

        return [
            'papel_label' => trim((string) ($item['papel'] ?? '')),
            'nome' => trim((string) $item['nome']),
            'codigo' => $codigo === '' ? null : $codigo,
            'turno' => Turno::tryFrom((string) ($item['turno'] ?? ''))?->label(),
            'estande' => isset($item['estande']) && $item['estande'] !== '' ? (int) $item['estande'] : null,
        ];
    }

    private function ano(): int
    {
        return (int) (Edicao::atual()?->ano ?? now()->year);
    }

    private function normalizar(string $texto): string
    {
        return Str::lower(Str::ascii(trim($texto)));
    }
}
