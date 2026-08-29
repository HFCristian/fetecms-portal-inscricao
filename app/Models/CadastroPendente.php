<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Um cadastro preenchido que ainda não virou conta: espera o código de 6
 * dígitos enviado por e-mail (ver ConfirmacaoCadastroService).
 */
class CadastroPendente extends Model
{
    protected $table = 'cadastros_pendentes';

    protected $fillable = [
        'papel', 'nome', 'email', 'token', 'codigo_hash',
        'tentativas', 'expira_em', 'ultimo_envio_em', 'payload',
    ];

    /** O código nunca sai daqui: só o hash é guardado e comparado. */
    protected $hidden = ['codigo_hash', 'payload'];

    protected function casts(): array
    {
        return [
            'expira_em' => 'datetime',
            'ultimo_envio_em' => 'datetime',
            'payload' => 'array',
            'tentativas' => 'integer',
        ];
    }

    public function expirou(): bool
    {
        return now()->greaterThan($this->expira_em);
    }
}
