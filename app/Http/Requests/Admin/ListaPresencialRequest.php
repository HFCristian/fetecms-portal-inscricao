<?php

namespace App\Http\Requests\Admin;

use App\Enums\Categoria;
use App\Models\ListaPresencial;
use App\Support\Cota;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Gerar lista do ranking presencial (Sprints 251 e 255).
 *
 * Em cada área de cada categoria, **até que lugar** premiar (`lugares`;
 * padrão 4, nulo sem limite, 0 deixa a área de fora). Por cima, tetos
 * opcionais por categoria e no total, em número ou porcentagem.
 */
class ListaPresencialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // rota já protegida por role:admin e admin.permanente
    }

    public function rules(): array
    {
        $cota = fn (string $prefixo) => [
            $prefixo => ['nullable', 'array'],
            $prefixo.'.tipo' => ['nullable', Rule::in([Cota::FIXO, Cota::PERCENTUAL])],
            $prefixo.'.valor' => ['nullable', 'numeric', 'min:0', 'max:10000'],
        ];

        return array_merge(
            $cota('total'),
            [
                'tipo' => ['nullable', Rule::in([ListaPresencial::TIPO_PRELIMINAR, ListaPresencial::TIPO_FINAL])],
                'nome' => ['nullable', 'string', 'max:120'],
                'descricao' => ['nullable', 'string', 'max:1000'],
                'categorias' => ['nullable', 'array'],
                'categorias.*' => ['nullable', 'array'],
                'categorias.*.areas' => ['nullable', 'array'],
                'categorias.*.areas.*' => ['nullable', 'array'],
                'categorias.*.areas.*.lugares' => ['nullable', 'integer', 'min:0', 'max:99'],
            ],
            $cota('categorias.*.cota'),
        );
    }

    public function after(): array
    {
        return [
            function ($validator) {
                foreach (array_keys((array) $this->input('categorias', [])) as $chave) {
                    if (Categoria::tryFrom((string) $chave) === null) {
                        $validator->errors()->add("categorias.{$chave}", 'Categoria desconhecida.');
                    }
                }
            },
        ];
    }

    public function attributes(): array
    {
        return [
            'categorias.*.areas.*.lugares' => 'colocação',
            'total.valor' => 'quantidade total de projetos',
        ];
    }

    /**
     * O pedido pronto para o serviço. A colocação da área viaja mesmo nula —
     * nula é "sem limite", e a ausência é "o padrão".
     *
     * @return array<string, mixed>
     */
    public function cotas(): array
    {
        $categorias = [];

        foreach ((array) $this->input('categorias', []) as $categoria => $config) {
            $config = (array) $config;
            $areas = [];

            foreach ((array) ($config['areas'] ?? []) as $areaId => $areaConfig) {
                $areaConfig = (array) $areaConfig;
                if (array_key_exists('lugares', $areaConfig)) {
                    $areas[$areaId] = ['lugares' => $areaConfig['lugares'] === null ? null : (int) $areaConfig['lugares']];
                }
            }

            $limpo = array_filter(['cota' => $this->cota($config['cota'] ?? null)]);
            if ($areas !== []) {
                $limpo['areas'] = $areas;
            }

            if ($limpo !== []) {
                $categorias[$categoria] = $limpo;
            }
        }

        return array_filter([
            'total' => $this->cota($this->input('total')),
            'categorias' => $categorias === [] ? null : $categorias,
        ], fn ($v) => $v !== null);
    }

    private function cota(mixed $bruta): ?array
    {
        $cota = Cota::de($bruta);

        return $cota === null ? null : ['tipo' => $cota->tipo, 'valor' => $cota->valor];
    }
}
