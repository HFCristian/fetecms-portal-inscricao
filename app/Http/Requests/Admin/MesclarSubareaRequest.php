<?php

namespace App\Http\Requests\Admin;

use App\Models\Subarea;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

class MesclarSubareaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // rota já protegida por role:admin
    }

    public function rules(): array
    {
        $origem = $this->route('subarea');

        return [
            'destino_id' => [
                'required', 'integer', 'exists:subareas,id',
                function (string $attribute, mixed $value, Closure $fail) use ($origem) {
                    if ((int) $value === (int) $origem->id) {
                        $fail('Selecione uma subárea de destino diferente da que será mesclada.');

                        return;
                    }
                    $destino = Subarea::find($value);
                    if ($destino && $destino->area_id !== $origem->area_id) {
                        $fail('Só é possível mesclar subáreas da mesma área.');
                    }
                },
            ],
        ];
    }
}
