<?php

namespace App\Enums;

use Illuminate\Support\Str;

/**
 * Grupos de áreas correlatas ("áreas irmãs") do edital. Quando um projeto não
 * encontra avaliador disponível na própria área, a distribuição cai para as
 * outras áreas do MESMO grupo antes de deixá-lo sub-coberto.
 *
 * O grupo é uma coluna de `areas` (e não um mapa fixo aqui) porque o admin
 * renomeia, mescla e cria áreas na Parametrização — cada área aponta para o
 * seu grupo, e área sem grupo simplesmente não tem irmã.
 */
enum GrupoCorrelato: string
{
    case Vida = 'vida';
    case ExatasEngenharias = 'exatas_engenharias';
    case Humanidades = 'humanidades';

    public function label(): string
    {
        return match ($this) {
            self::Vida => 'Ciências da vida',
            self::ExatasEngenharias => 'Exatas e engenharias',
            self::Humanidades => 'Linguagens, humanas e sociais',
        };
    }

    public function descricao(): string
    {
        return match ($this) {
            self::Vida => 'Agrárias, Biológicas e Saúde',
            self::ExatasEngenharias => 'Engenharias e Exatas',
            self::Humanidades => 'Linguagens, Humanas e Sociais',
        };
    }

    /** @return array<int, array{value:string, label:string, descricao:string}> */
    public static function opcoes(): array
    {
        return array_map(
            fn (self $g) => ['value' => $g->value, 'label' => $g->label(), 'descricao' => $g->descricao()],
            self::cases(),
        );
    }

    /**
     * Grupo sugerido pelo NOME da área — usado só para semear o catálogo e para
     * o backfill da migration. Casa por palavra-chave, sem acento e sem caixa,
     * para pegar tanto "Ciências da Saúde" quanto "Saúde".
     */
    public static function peloNome(string $nome): ?self
    {
        $normalizado = Str::lower(Str::ascii($nome));

        $palavras = [
            self::Vida->value => ['agrar', 'biolog', 'saude'],
            self::ExatasEngenharias->value => ['engenhar', 'exatas'],
            self::Humanidades->value => ['linguist', 'linguagen', 'letras', 'artes', 'human', 'sociais'],
        ];

        foreach ($palavras as $grupo => $chaves) {
            foreach ($chaves as $chave) {
                if (str_contains($normalizado, $chave)) {
                    return self::from($grupo);
                }
            }
        }

        return null;
    }
}
