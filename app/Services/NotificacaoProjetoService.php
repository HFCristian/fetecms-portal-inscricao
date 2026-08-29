<?php

namespace App\Services;

use App\Enums\ModeloEmail;
use App\Models\Projeto;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * E-mails automáticos sobre o projeto. Hoje só o comprovante de submissão.
 *
 * O envio vai para a FILA e é engolido em caso de erro: a submissão é
 * irreversível e já está gravada quando chegamos aqui — servidor de e-mail fora
 * do ar não pode derrubar a resposta nem deixar o orientador sem saber se
 * submeteu.
 */
class NotificacaoProjetoService
{
    public function __construct(private readonly ModeloEmailService $modelos) {}

    /** Comprovante de submissão para o orientador dono do projeto. */
    public function submetido(Projeto $projeto): void
    {
        $user = $projeto->user;

        if ($user === null || blank($user->email)) {
            return;
        }

        $quando = ($projeto->submitted_at ?? now())->timezone(config('app.timezone'));

        $mensagem = $this->modelos->mensagem(ModeloEmail::ProjetoSubmetido, [
            'nome' => Str::before($user->name, ' ') ?: $user->name,
            'nome_completo' => $user->name,
            'email' => $user->email,
            'projeto' => (string) $projeto->titulo,
            'categoria' => $projeto->categoria?->label() ?? 'não informada',
            'area' => $projeto->area?->nome ?? 'não informada',
            'data' => $quando->format('d/m/Y'),
            'hora' => $quando->format('H:i'),
        ]);

        try {
            Mail::to($user->email)->queue($mensagem);
        } catch (\Throwable $e) {
            Log::warning('Falha ao enfileirar o comprovante de submissão.', [
                'projeto_id' => $projeto->id,
                'erro' => $e->getMessage(),
            ]);
        }
    }
}
