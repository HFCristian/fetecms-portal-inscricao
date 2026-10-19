<?php

namespace App\Services;

use App\Enums\Turno;
use App\Models\Edicao;
use App\Models\EstandeProjeto;
use App\Models\FotografiaEstande;
use App\Models\MapaLayout;
use App\Models\Projeto;
use App\Models\User;
use App\Support\PlantaEvento;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cerimonial → **Fotografias de estandes** (Sprint 247).
 *
 * A organização percorre o ginásio fotografando cada estande com o trabalho
 * montado. O mapa diz o que já foi fotografado, o que alguém está
 * fotografando agora e o que falta; clicar num estande é o pedido de
 * registrar a foto dele.
 *
 * - **Uma foto por projeto** na edição: o estande recebe um projeto de manhã e
 *   outro à tarde, e a foto é do trabalho exposto — remanejado, o projeto leva
 *   a foto junto.
 * - **"Em registro"** é a reserva de quem confirmou o estande e ainda não
 *   salvou: impede dois fotógrafos no mesmo estande e vence sozinha em
 *   {@see FotografiaEstande::MINUTOS_RESERVA} minutos.
 * - Registrar de novo **substitui** a foto (o arquivo antigo é apagado);
 *   excluir apaga a foto e libera o estande. A conta temporária do cerimonial
 *   (o voluntário fotógrafo) registra; excluir a foto de **outra** pessoa é
 *   da organização (admin permanente), como no credenciamento.
 * - Tudo fica em Registros → Cerimonial.
 */
class FotografiaEstandeService
{
    private const DISCO = 'local';

    public function __construct(
        private readonly LocaisFinalistasService $locais,
        private readonly RegistroAtividadeService $registros,
    ) {}

    /**
     * O mapa de um turno: a planta vigente (ou a padrão) e, em cada estande
     * ocupado, a situação da fotografia do projeto.
     *
     * @return array<string, mixed>
     */
    public function mapa(Turno $turno, User $user): array
    {
        $edicao = Edicao::atual();
        $planta = ($edicao === null ? null : MapaLayout::vigente($edicao))?->dados ?? PlantaEvento::padrao();
        $layout = array_map(
            fn (array $e) => ['numero' => (int) $e['numero'], 'x' => $e['x'], 'y' => $e['y']],
            $planta['estandes'] ?? [],
        );
        $naPlanta = array_fill_keys(array_column($layout, 'numero'), true);

        $alocacoes = $edicao === null ? collect() : EstandeProjeto::where('edicao_id', $edicao->id)
            ->where('turno', $turno->value)
            ->with(['projeto:id,titulo,categoria,area_id', 'projeto.area:id,nome'])
            ->orderBy('numero')
            ->get()
            ->filter(fn (EstandeProjeto $e) => $e->projeto !== null);

        $fotos = $edicao === null ? collect() : FotografiaEstande::where('edicao_id', $edicao->id)
            ->whereIn('projeto_id', $alocacoes->pluck('projeto_id'))
            ->with(['autor:id,name', 'fotografo:id,name'])
            ->get()
            ->keyBy('projeto_id');
        $locais = $alocacoes->isEmpty() ? [] : $this->locais->todos();

        $estandes = [];
        $fora = [];
        $resumo = ['registrado' => 0, 'em_registro' => 0, 'sem_registro' => 0];

        foreach ($alocacoes as $e) {
            $linha = $this->linha($e, $fotos->get($e->projeto_id), $user, $locais[$e->projeto_id]['codigo'] ?? null);
            $resumo[$linha['situacao']]++;

            if (isset($naPlanta[$e->numero])) {
                $estandes[$e->numero] = $linha;
            } else {
                $fora[] = $linha;
            }
        }

        return [
            'turno' => $turno->value,
            'turno_label' => $turno->label(),
            'turnos' => Turno::opcoes(),
            'layout' => $layout,
            'estandes' => (object) $estandes,
            'fora_da_planta' => $fora,
            'resumo' => $resumo,
            'total' => $alocacoes->count(),
            'minutos_reserva' => FotografiaEstande::MINUTOS_RESERVA,
            'max_mb' => intdiv(FotografiaEstande::MAX_KB, 1024),
        ];
    }

    /**
     * "Registrar fotografia do estande?" — confirmado: o estande fica
     * **em registro** com esta pessoa até a foto chegar (ou a reserva vencer).
     *
     * @return array<string, mixed>
     */
    public function reservar(Projeto $projeto, User $user): array
    {
        $alocacao = $this->alocacaoOuFalha($projeto);

        DB::transaction(function () use ($projeto, $user) {
            $foto = $this->fotoDe($projeto, travar: true);
            $this->garantirLivre($foto, $user);

            ($foto ?? new FotografiaEstande(['edicao_id' => Edicao::atual()->id, 'projeto_id' => $projeto->id]))
                ->fill(['em_registro_por' => $user->id, 'em_registro_desde' => now()])
                ->save();
        });

        return $this->linhaDe($alocacao, $user);
    }

    /** Desistiu antes de salvar: o estande volta à situação de antes. */
    public function cancelar(Projeto $projeto, User $user): void
    {
        $foto = $this->fotoDe($projeto);

        if ($foto === null || $foto->em_registro_por !== $user->id) {
            return;
        }

        if ($foto->temFoto()) {
            $foto->update(['em_registro_por' => null, 'em_registro_desde' => null]);
        } else {
            $foto->delete();
        }
    }

    /**
     * Grava a fotografia — tirada pela câmera ou enviada como arquivo — e
     * substitui a anterior, se havia.
     *
     * @return array<string, mixed>
     */
    public function registrar(Projeto $projeto, UploadedFile $arquivo, User $user): array
    {
        $alocacao = $this->alocacaoOuFalha($projeto);
        $edicao = Edicao::atual();
        $extensao = strtolower($arquivo->getClientOriginalExtension() ?: $arquivo->extension() ?: 'jpg');
        $caminho = $arquivo->storeAs(
            "fotografias-estandes/{$edicao->id}",
            $projeto->id.'-'.Str::random(12).'.'.$extensao,
            self::DISCO,
        );

        if ($caminho === false) {
            throw ValidationException::withMessages(['arquivo' => 'Não foi possível guardar a fotografia. Tente de novo.']);
        }

        $antigo = null;

        try {
            DB::transaction(function () use ($projeto, $arquivo, $user, $caminho, $edicao, $alocacao, &$antigo) {
                $foto = $this->fotoDe($projeto, travar: true);
                $this->garantirLivre($foto, $user);
                $antigo = $foto?->caminho;

                ($foto ?? new FotografiaEstande(['edicao_id' => $edicao->id, 'projeto_id' => $projeto->id]))
                    ->fill([
                        'caminho' => $caminho,
                        'nome_original' => Str::limit($arquivo->getClientOriginalName() ?: 'fotografia.'.$extensao, 250, ''),
                        'mime' => $arquivo->getMimeType(),
                        'tamanho' => $arquivo->getSize(),
                        'registrado_por' => $user->id,
                        'registrado_em' => now(),
                        'em_registro_por' => null,
                        'em_registro_desde' => null,
                    ])
                    ->save();

                $this->registros->cerimonialFotografia($projeto, $user, $this->rotuloEstande($alocacao), $antigo !== null);
            });
        } catch (\Throwable $e) {
            Storage::disk(self::DISCO)->delete($caminho);

            throw $e;
        }

        if ($antigo !== null) {
            Storage::disk(self::DISCO)->delete($antigo);
        }

        return $this->linhaDe($alocacao, $user);
    }

    /** Exclui a fotografia: o estande volta a "sem registro". */
    public function excluir(FotografiaEstande $foto, User $user): void
    {
        $this->garantirDaEdicao($foto);

        if (! $this->podeExcluir($foto, $user)) {
            abort(403, 'Só a organização exclui a fotografia registrada por outra pessoa.');
        }

        if (! $foto->temFoto()) {
            throw ValidationException::withMessages(['fotografia' => 'Este estande ainda não tem fotografia.']);
        }

        $caminho = $foto->caminho;
        $alocacao = EstandeProjeto::where('edicao_id', $foto->edicao_id)->where('projeto_id', $foto->projeto_id)->first();

        DB::transaction(function () use ($foto, $user, $alocacao) {
            $this->registros->cerimonialFotografiaExcluida(
                $foto->projeto,
                $user,
                $alocacao === null ? null : $this->rotuloEstande($alocacao),
                $foto->autor?->name,
            );

            // A reserva de quem está substituindo agora continua valendo.
            if ($foto->emRegistro()) {
                $foto->update([
                    'caminho' => null, 'nome_original' => null, 'mime' => null, 'tamanho' => null,
                    'registrado_por' => null, 'registrado_em' => null,
                ]);
            } else {
                $foto->delete();
            }
        });

        Storage::disk(self::DISCO)->delete($caminho);
    }

    /** O arquivo da foto, para a prévia na tela. */
    public function arquivo(FotografiaEstande $foto): StreamedResponse
    {
        $this->garantirDaEdicao($foto);
        abort_unless($foto->temFoto() && Storage::disk(self::DISCO)->exists($foto->caminho), 404, 'Fotografia não encontrada.');

        return Storage::disk(self::DISCO)->response($foto->caminho, $foto->nome_original, [
            'Content-Type' => $foto->mime ?? 'application/octet-stream',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    // --- Interno ---------------------------------------------------------

    /** @return array<string, mixed> */
    private function linha(EstandeProjeto $e, ?FotografiaEstande $foto, User $user, ?string $codigo): array
    {
        $emRegistro = $foto?->emRegistro() ?? false;

        return [
            'numero' => $e->numero,
            'estande' => str_pad((string) $e->numero, 3, '0', STR_PAD_LEFT),
            'turno' => $e->turno->value,
            'turno_label' => $e->turno->label(),
            'projeto_id' => $e->projeto_id,
            'titulo' => $e->projeto->titulo,
            'codigo' => $codigo,
            'area' => $e->projeto->area?->nome,
            'categoria' => $e->projeto->categoria?->label(),
            // Quem está fotografando agora manda na cor, mesmo que já haja foto:
            // é o que impede o segundo fotógrafo de chegar ao mesmo estande.
            'situacao' => $emRegistro ? 'em_registro' : ($foto?->temFoto() ? 'registrado' : 'sem_registro'),
            'em_registro' => $emRegistro ? [
                'por' => $foto->fotografo?->name,
                'meu' => $foto->em_registro_por === $user->id,
                'desde_label' => $foto->em_registro_desde->format('H:i'),
            ] : null,
            'foto' => $foto?->temFoto() ? [
                'id' => $foto->id,
                'url' => "/api/v1/admin/cerimonial/fotografias/{$foto->id}/arquivo?v=".$foto->registrado_em?->timestamp,
                'nome_original' => $foto->nome_original,
                'tamanho' => $foto->tamanho,
                'registrado_por' => $foto->autor?->name,
                'registrado_em_label' => $foto->registrado_em?->format('d/m/Y H:i'),
                'pode_excluir' => $this->podeExcluir($foto, $user),
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    private function linhaDe(EstandeProjeto $alocacao, User $user): array
    {
        $alocacao->load(['projeto:id,titulo,categoria,area_id', 'projeto.area:id,nome']);
        $foto = $this->fotoDe($alocacao->projeto)?->load(['autor:id,name', 'fotografo:id,name']);

        return $this->linha($alocacao, $foto, $user, $this->locais->todos()[$alocacao->projeto_id]['codigo'] ?? null);
    }

    private function alocacaoOuFalha(Projeto $projeto): EstandeProjeto
    {
        $alocacao = EstandeProjeto::where('edicao_id', Edicao::atual()?->id)->where('projeto_id', $projeto->id)->first();

        if ($alocacao === null) {
            throw ValidationException::withMessages([
                'projeto' => 'Este projeto não tem estande no Mapa do Evento.',
            ]);
        }

        return $alocacao;
    }

    private function fotoDe(Projeto $projeto, bool $travar = false): ?FotografiaEstande
    {
        return FotografiaEstande::where('edicao_id', Edicao::atual()?->id)
            ->where('projeto_id', $projeto->id)
            ->when($travar, fn ($q) => $q->lockForUpdate())
            ->first();
    }

    private function garantirLivre(?FotografiaEstande $foto, User $user): void
    {
        if ($foto !== null && $foto->reservadaPorOutro($user)) {
            $foto->loadMissing('fotografo:id,name');

            throw ValidationException::withMessages([
                'projeto' => sprintf(
                    '%s está registrando a fotografia deste estande desde as %s.',
                    $foto->fotografo?->name ?? 'Outra pessoa',
                    $foto->em_registro_desde->format('H:i'),
                ),
            ]);
        }
    }

    private function garantirDaEdicao(FotografiaEstande $foto): void
    {
        abort_unless($foto->edicao_id === Edicao::atual()?->id, 404, 'Fotografia não encontrada.');
    }

    /** A conta temporária exclui só o que ela mesma registrou. */
    private function podeExcluir(FotografiaEstande $foto, User $user): bool
    {
        return ! $user->ehContaTemporaria() || $foto->registrado_por === $user->id;
    }

    private function rotuloEstande(EstandeProjeto $e): string
    {
        return str_pad((string) $e->numero, 3, '0', STR_PAD_LEFT).' · '.$e->turno->label();
    }
}
