<?php

namespace App\Services;

use App\Enums\Turno;
use App\Models\Edicao;
use App\Models\MapaLayout;
use App\Models\User;
use App\Support\PlantaEvento;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Mapa do Evento → **a planta**.
 *
 * O desenho do ginásio com a ocupação em cima dele: clicar num estande mostra
 * quem apresenta nele de manhã (turno A) e à tarde (turno B).
 *
 * A planta é **da edição e versionada**. Ela nasce com a prancha da XVI FETECMS
 * ({@see PlantaEvento}) na primeira vez que a tela é aberta, e cada gravação
 * cria uma **versão nova** em vez de sobrescrever: o pavilhão muda de um ano
 * para o outro, e o desenho que valeu no ano passado precisa continuar
 * consultável — nem que seja para explicar uma foto antiga.
 *
 * O que a planta **não** decide: quem fica em cada estande. Isso vem da
 * distribuição (Sprint 119); aqui só se desenha o lugar físico. Por isso mexer
 * na planta nunca mexe na alocação, e um estande sem projeto aparece vazio em
 * vez de sumir.
 */
class MapaPlantaService
{
    public function __construct(private readonly EstandesProjetosService $estandes) {}

    /**
     * A planta em vigor + a ocupação de cada estande.
     *
     * @return array<string, mixed>
     */
    public function painel(): array
    {
        $edicao = Edicao::atual();
        $layout = $this->vigenteOuPadrao($edicao);
        $ocupacao = $this->estandes->porEstande();

        return [
            'edicao' => $edicao ? ['id' => $edicao->id, 'nome' => $edicao->nome] : null,
            'layout' => $layout === null ? PlantaEvento::padrao() : $layout->dados,
            'versao' => $layout?->versao,
            'versao_id' => $layout?->id,
            'salva' => $layout !== null,
            'turnos' => Turno::opcoes(),
            'ocupacao' => $ocupacao,
            'ocupados' => count($ocupacao),
            'versoes' => $this->versoes($edicao),
        ];
    }

    /**
     * Grava o desenho como uma **versão nova**, que passa a ser a vigente.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    public function salvar(array $dados, User $admin): array
    {
        $edicao = $this->edicaoOuFalha();
        $layout = PlantaEvento::normalizar($dados);

        if ($layout['estandes'] === []) {
            throw ValidationException::withMessages([
                'estandes' => 'A planta precisa de ao menos um estande.',
            ]);
        }

        DB::transaction(function () use ($edicao, $layout, $admin) {
            $versao = (int) MapaLayout::where('edicao_id', $edicao->id)->max('versao') + 1;

            MapaLayout::where('edicao_id', $edicao->id)->update(['vigente' => false]);

            MapaLayout::create([
                'edicao_id' => $edicao->id,
                'versao' => $versao,
                'nome' => $layout['nome'],
                'dados' => $layout,
                'vigente' => true,
                'criado_por' => $admin->id,
            ]);
        });

        return $this->painel();
    }

    /**
     * Volta a uma versão anterior — que também vira uma **versão nova**, e não
     * uma ressurreição da antiga: assim o histórico continua sendo a sequência
     * do que esteve em vigor, na ordem em que esteve.
     *
     * @return array<string, mixed>
     */
    public function restaurar(MapaLayout $layout, User $admin): array
    {
        $edicao = $this->edicaoOuFalha();

        if ($layout->edicao_id !== $edicao->id) {
            throw ValidationException::withMessages([
                'versao' => 'Esta planta é de outra edição.',
            ]);
        }

        return $this->salvar($layout->dados, $admin);
    }

    /** A planta que vale — ou nenhuma, quando a edição ainda não gravou uma. */
    private function vigenteOuPadrao(?Edicao $edicao): ?MapaLayout
    {
        return $edicao === null ? null : MapaLayout::vigente($edicao);
    }

    /**
     * O histórico, do mais recente para o mais antigo.
     *
     * @return list<array<string, mixed>>
     */
    private function versoes(?Edicao $edicao): array
    {
        if ($edicao === null) {
            return [];
        }

        return MapaLayout::where('edicao_id', $edicao->id)
            ->with('autor:id,name')
            ->orderByDesc('versao')
            ->get()
            ->map(fn (MapaLayout $l) => [
                'id' => $l->id,
                'versao' => $l->versao,
                'nome' => $l->nome,
                'vigente' => (bool) $l->vigente,
                'estandes' => count($l->dados['estandes'] ?? []),
                'autor' => $l->autor?->name,
                'criada_em' => $l->created_at?->toIso8601String(),
            ])
            ->all();
    }

    private function edicaoOuFalha(): Edicao
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            throw ValidationException::withMessages([
                'edicao' => 'Nenhuma edição em escopo. Crie a edição da feira antes.',
            ]);
        }

        return $edicao;
    }
}
