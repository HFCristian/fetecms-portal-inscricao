<?php

namespace App\Http\Requests\Admin;

use App\Enums\Categoria;
use App\Models\Edicao;
use App\Support\LimitesAvaliacao;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Limites da avaliação online: o par mínimo/máximo por avaliador e por projeto,
 * mais os pares por categoria. Cada card da tela salva o seu bloco, então tudo
 * é opcional — mas ao menos um campo precisa vir.
 */
class MinimosAvaliacaoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // rota já protegida por role:admin
    }

    /**
     * A tela salva um card por vez. Quando ela manda só o teto, o "gte" precisa
     * comparar com o mínimo que já está em vigor — então ele entra aqui.
     */
    protected function prepareForValidation(): void
    {
        $limites = Edicao::limites();

        if ($this->has('max_por_avaliador') && ! $this->has('min_por_avaliador')) {
            $this->merge(['min_por_avaliador' => $limites->minPorAvaliador()]);
        }

        if ($this->has('max_por_projeto') && ! $this->has('min_por_projeto')) {
            $this->merge(['min_por_projeto' => $limites->minPorProjeto()]);
        }
    }

    public function rules(): array
    {
        $teto = LimitesAvaliacao::MAXIMO;
        $outros = 'max_por_avaliador,min_por_projeto,max_por_projeto,categorias';

        $regras = [
            'min_por_avaliador' => ['required_without_all:'.$outros, 'integer', 'min:1', 'max:'.$teto],
            // Único que aceita nulo: nulo aqui quer dizer "sem teto".
            'max_por_avaliador' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.$teto, 'gte:min_por_avaliador'],
            'min_por_projeto' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.$teto],
            'max_por_projeto' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.$teto, 'gte:min_por_projeto'],
            'categorias' => ['sometimes', 'required', 'array'],
        ];

        // Um par por categoria; em branco, a categoria segue os números gerais.
        foreach (Categoria::cases() as $categoria) {
            $chave = 'categorias.'.$categoria->value;

            $regras[$chave] = ['array'];
            $regras[$chave.'.min'] = ['nullable', 'integer', 'min:1', 'max:'.$teto];
            // O teto da categoria é comparado no after(): o mínimo dela pode
            // estar em branco (seguindo o geral), e aí o gte não teria com o quê comparar.
            $regras[$chave.'.max'] = ['nullable', 'integer', 'min:1', 'max:'.$teto];
        }

        return $regras;
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function ($validator) {
                $geral = (int) ($this->input('min_por_projeto') ?? Edicao::limites()->minPorProjeto());

                foreach (Categoria::cases() as $categoria) {
                    $chave = 'categorias.'.$categoria->value;
                    $max = $this->input($chave.'.max');
                    $min = $this->input($chave.'.min') ?? $geral;

                    if ($max !== null && (int) $max < (int) $min) {
                        $validator->errors()->add(
                            $chave.'.max',
                            'O máximo da '.$categoria->label().' não pode ser menor que o mínimo ('.$min.').',
                        );
                    }
                }
            },
        ];
    }

    /** Os limites prontos para o serviço, com as chaves ausentes de fora. */
    public function limites(): array
    {
        $dados = $this->validated();

        if (array_key_exists('categorias', $dados)) {
            $dados['categorias'] = $this->categoriasNormalizadas($dados['categorias'] ?? []);
        }

        return $dados;
    }

    /**
     * Garante as três categorias no mapa gravado, cada uma com min/max (null
     * quando a categoria segue o geral).
     *
     * @param  array<string, mixed>  $bruto
     * @return array<string, array{min:int|null, max:int|null}>
     */
    private function categoriasNormalizadas(array $bruto): array
    {
        $mapa = [];

        foreach (Categoria::cases() as $categoria) {
            $item = $bruto[$categoria->value] ?? [];
            $mapa[$categoria->value] = [
                'min' => isset($item['min']) ? (int) $item['min'] : null,
                'max' => isset($item['max']) ? (int) $item['max'] : null,
            ];
        }

        return $mapa;
    }

    public function attributes(): array
    {
        $nomes = [
            'min_por_avaliador' => 'mínimo de avaliações por avaliador',
            'max_por_avaliador' => 'máximo de avaliações por avaliador',
            'min_por_projeto' => 'mínimo de avaliações por projeto',
            'max_por_projeto' => 'máximo de avaliações por projeto',
        ];

        foreach (Categoria::cases() as $categoria) {
            $chave = 'categorias.'.$categoria->value;
            $nomes[$chave.'.min'] = 'mínimo por projeto da '.$categoria->label();
            $nomes[$chave.'.max'] = 'máximo por projeto da '.$categoria->label();
        }

        return $nomes;
    }
}
