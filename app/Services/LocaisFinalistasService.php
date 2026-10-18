<?php

namespace App\Services;

use App\Enums\Turno;
use App\Models\Edicao;
use App\Models\EstandeProjeto;
use App\Models\ListaFinal;
use App\Models\TurnoApresentacao;
use Illuminate\Support\Str;

/**
 * Onde cada projeto está no evento: o **código** na lista final vigente
 * (FET.AGR-001), o **turno** e o **número do estande** (Mapa do Evento).
 *
 * Nenhum dos três é coluna do projeto — o código é numerado pela lista, o turno
 * e o estande moram nas tabelas do mapa —, então quem quer buscar ou filtrar
 * por eles monta o mapa uma vez por requisição e casa os ids aqui, em vez de
 * cada tela repetir a junção.
 *
 * O turno é o do estande; sem estande gerado, o de Mapa do Evento → Turnos — a
 * mesma regra das etiquetas e do balcão.
 */
class LocaisFinalistasService
{
    public function __construct(private readonly ListaFinalService $listas) {}

    /**
     * Os projetos da edição em escopo que têm código, turno ou estande. O
     * código sai da lista informada — a de demonstração, no balcão em modo de
     * teste —, ou da final vigente.
     *
     * @return array<int, array{codigo: ?string, turno: ?string, turno_label: ?string, estande: ?int}>
     */
    public function todos(?ListaFinal $lista = null): array
    {
        $edicao = Edicao::atual();

        if ($edicao === null) {
            return [];
        }

        $lista ??= ListaFinal::vigente($edicao);
        $codigos = $lista === null ? [] : $this->listas->codigosDaLista($lista);
        $estandes = EstandeProjeto::where('edicao_id', $edicao->id)->get()->keyBy('projeto_id');
        $turnos = TurnoApresentacao::where('edicao_id', $edicao->id)->get()->keyBy('projeto_id');

        $ids = array_unique(array_merge(
            array_keys($codigos),
            $estandes->keys()->all(),
            $turnos->keys()->all(),
        ));

        $locais = [];

        foreach ($ids as $id) {
            $estande = $estandes->get($id);
            $turno = $estande?->turno ?? $turnos->get($id)?->turno;

            $locais[$id] = [
                'codigo' => $codigos[$id] ?? null,
                'turno' => $turno?->value,
                'turno_label' => $turno?->label(),
                'estande' => $estande?->numero,
            ];
        }

        return $locais;
    }

    /**
     * Os ids que o texto livre alcança pelo local: o código que contém o texto,
     * o estande com aquele número ("42", "042" ou "estande 42") e o turno cujo
     * nome contém o texto ("matutino", "turno b"). O turno pede ao menos três
     * letras — "a" sozinho casaria com meio evento.
     *
     * @param  array<int, array<string, mixed>>  $locais
     * @return list<int>
     */
    public function casamBusca(array $locais, string $texto): array
    {
        $termo = Str::lower(trim($texto));

        if ($termo === '') {
            return [];
        }

        $numero = preg_match('/^(?:estande\s*)?(\d{1,4})$/', $termo, $m) ? (int) $m[1] : null;
        $turnos = mb_strlen($termo) >= 3
            ? array_values(array_map(
                fn (Turno $t) => $t->value,
                array_filter(Turno::cases(), fn (Turno $t) => str_contains(Str::lower($t->label()), $termo)),
            ))
            : [];

        $ids = [];

        foreach ($locais as $id => $local) {
            if (($local['codigo'] !== null && str_contains(Str::lower($local['codigo']), $termo))
                || ($numero !== null && $local['estande'] === $numero)
                || in_array($local['turno'], $turnos, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * Os ids que passam por **todos** os filtros informados — `codigo` (parte
     * do código), `estande` (o número) e `turno` (A ou B). Sem filtro nenhum,
     * `null`: não há recorte a aplicar.
     *
     * @param  array<int, array<string, mixed>>  $locais
     * @param  array{codigo?: ?string, estande?: int|string|null, turno?: ?string}  $filtros
     * @return list<int>|null
     */
    public function filtrar(array $locais, array $filtros): ?array
    {
        $codigo = Str::lower(trim((string) ($filtros['codigo'] ?? '')));
        $estande = ($filtros['estande'] ?? null) !== null && $filtros['estande'] !== '' ? (int) $filtros['estande'] : null;
        $turno = ($filtros['turno'] ?? null) ?: null;

        if ($codigo === '' && $estande === null && $turno === null) {
            return null;
        }

        return array_keys(array_filter($locais, fn (array $local) => ($codigo === ''
                || ($local['codigo'] !== null && str_contains(Str::lower($local['codigo']), $codigo)))
            && ($estande === null || $local['estande'] === $estande)
            && ($turno === null || $local['turno'] === $turno)));
    }
}
