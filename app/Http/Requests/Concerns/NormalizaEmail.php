<?php

namespace App\Http\Requests\Concerns;

/**
 * Tira os espaços dos campos de e-mail antes de validar.
 *
 * Não é só trim das pontas: quem copia o endereço de uma conversa traz espaço
 * (e espaço não-quebrável) no meio do texto — "joao @gmail. com" —, e um
 * endereço com espaço não chega a lugar nenhum. Nenhum e-mail é gravado no
 * portal sem passar por aqui.
 */
trait NormalizaEmail
{
    /**
     * Campos de e-mail deste formulário. Sobrescreva quando houver mais de um
     * ou quando o nome for outro.
     *
     * @return list<string>
     */
    protected function camposDeEmail(): array
    {
        return ['email'];
    }

    /** Chame no prepareForValidation(). */
    protected function limparEmails(): void
    {
        $limpos = [];

        foreach ($this->camposDeEmail() as $campo) {
            $valor = $this->input($campo);

            if (is_string($valor)) {
                $limpos[$campo] = self::semEspacos($valor);
            }
        }

        if ($limpos !== []) {
            $this->merge($limpos);
        }
    }

    /** Espaço em qualquer posição sai — inclusive o não-quebrável do copiar/colar. */
    public static function semEspacos(string $email): string
    {
        return preg_replace('/[\s\x{00A0}\x{200B}\x{FEFF}]+/u', '', $email) ?? $email;
    }
}
