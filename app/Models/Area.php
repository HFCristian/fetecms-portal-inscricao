<?php

namespace App\Models;

use App\Enums\GrupoCorrelato;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Area extends Model
{
    protected $table = 'areas';

    protected $fillable = ['nome', 'grupo_correlato', 'sigla'];

    /**
     * Sigla de três letras usada na lista final da feira (FET.AGR-001). Cada
     * área guarda a sua porque o admin renomeia, mescla e cria áreas na
     * Parametrização — um mapa fixo por nome não sobreviveria a isso.
     */
    public const TAMANHO_SIGLA = 3;

    protected function casts(): array
    {
        return ['grupo_correlato' => GrupoCorrelato::class];
    }

    public function subareas(): HasMany
    {
        return $this->hasMany(Subarea::class);
    }

    /**
     * Sigla sugerida pelo NOME da área — usada no backfill da migration e como
     * palpite ao criar uma área nova. Casa por palavra-chave, sem acento e sem
     * caixa; o admin pode trocar depois na Parametrização.
     */
    public static function siglaPeloNome(string $nome): ?string
    {
        $normalizado = Str::lower(Str::ascii($nome));

        $palavras = [
            'agrar' => 'AGR',
            'biolog' => 'BIO',
            'saude' => 'SAU',
            'exatas' => 'EXA',
            'engenhar' => 'ENG',
            'linguist' => 'LIN',
            'letras' => 'LIN',
            'artes' => 'LIN',
            'sociais' => 'SOC',
            'human' => 'HUM',
        ];

        foreach ($palavras as $chave => $sigla) {
            if (str_contains($normalizado, $chave)) {
                return $sigla;
            }
        }

        return null;
    }

    /**
     * A sigla que vai para a lista final: a cadastrada ou, na falta dela, as
     * três primeiras letras do nome — melhor um palpite visível do que um
     * buraco no meio do código do projeto.
     */
    public function siglaDaLista(): string
    {
        return $this->sigla ?: Str::upper(Str::substr(Str::ascii($this->nome), 0, self::TAMANHO_SIGLA));
    }
}
