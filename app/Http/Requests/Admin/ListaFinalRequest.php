<?php

namespace App\Http\Requests\Admin;

use App\Enums\Categoria;
use App\Support\Cota;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cotas da lista final (Ranking dos projetos → Gerar lista final).
 *
 * As cotas são aninhadas — total → categoria → área → interior — e cada uma
 * pode ser número fixo ou porcentagem do recorte que a contém. Todas são
 * opcionais: em branco não limita nada; 0 deixa o recorte inteiro de fora.
 */
class ListaFinalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // rota já protegida por role:admin
    }

    public function rules(): array
    {
        // Uma cota é `{tipo, valor}`; o valor aceita decimal porque a
        // porcentagem pode ser quebrada (12,5%).
        $cota = fn (string $prefixo) => [
            $prefixo => ['nullable', 'array'],
            $prefixo.'.tipo' => ['nullable', Rule::in([Cota::FIXO, Cota::PERCENTUAL])],
            $prefixo.'.valor' => ['nullable', 'numeric', 'min:0', 'max:10000'],
        ];

        return array_merge(
            $cota('total'),
            [
                'categorias' => ['nullable', 'array'],
                'categorias.*' => ['nullable', 'array'],
                'categorias.*.areas' => ['nullable', 'array'],
                'categorias.*.areas.*' => ['nullable', 'array'],
            ],
            $cota('categorias.*.cota'),
            $cota('categorias.*.areas.*.cota'),
            $cota('categorias.*.areas.*.interior'),
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

    /**
     * As cotas prontas para o serviço, sem os campos em branco (o serviço já
     * trata cota ausente como "sem limite", mas limpar aqui deixa o payload
     * legível no log e nos testes).
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
                $limpo = array_filter([
                    'cota' => $this->cota($areaConfig['cota'] ?? null),
                    'interior' => $this->cota($areaConfig['interior'] ?? null),
                ]);

                if ($limpo !== []) {
                    $areas[$areaId] = $limpo;
                }
            }

            $limpo = array_filter([
                'cota' => $this->cota($config['cota'] ?? null),
                'areas' => $areas === [] ? null : $areas,
            ]);

            if ($limpo !== []) {
                $categorias[$categoria] = $limpo;
            }
        }

        return array_filter([
            'total' => $this->cota($this->input('total')),
            'categorias' => $categorias === [] ? null : $categorias,
        ], fn ($v) => $v !== null);
    }

    /** Normaliza uma cota do formulário; null quando o campo ficou em branco. */
    private function cota(mixed $bruta): ?array
    {
        $cota = Cota::de($bruta);

        return $cota === null ? null : ['tipo' => $cota->tipo, 'valor' => $cota->valor];
    }

    public function attributes(): array
    {
        return ['total.valor' => 'quantidade total de projetos'];
    }
}
