<?php

namespace App\Services;

use App\Models\Edicao;
use App\Models\User;
use Carbon\Carbon;

/**
 * Prazo de submissão das inscrições. Passada a data-limite, a área do orientador
 * fica só de leitura: não dá para criar, editar, submeter, cancelar a submissão
 * nem excluir projeto — inclusive quem cancelou o envio para editar não consegue
 * reenviar depois do prazo. O admin passa por cima (escape previsto no edital).
 *
 * Sem data definida, as inscrições ficam abertas.
 */
class InscricoesService
{
    public const CODE = 'INSCRICOES_ENCERRADAS';

    public function edicao(): ?Edicao
    {
        return Edicao::atual();
    }

    public function prazo(): ?Carbon
    {
        return $this->edicao()?->submissoes_ate;
    }

    public function encerradas(): bool
    {
        return (bool) $this->edicao()?->inscricoesEncerradas();
    }

    /** Este usuário está barrado pelo prazo? O admin nunca está. */
    public function bloqueadoPara(?User $user): bool
    {
        return ! ($user?->isAdmin() ?? false) && $this->encerradas();
    }

    public function mensagem(): string
    {
        $prazo = $this->prazo();

        return $prazo
            ? 'As inscrições foram encerradas em '.$prazo->format('d/m/Y H:i').'. Não é mais possível criar, editar, submeter ou cancelar projetos.'
            : 'As inscrições estão encerradas.';
    }

    /** @return array{code: string, message: string} */
    public function motivo(): array
    {
        return ['code' => self::CODE, 'message' => $this->mensagem()];
    }

    /**
     * Estado do prazo, usado tanto pela tela do admin quanto pelo aviso ao
     * orientador.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $prazo = $this->prazo();

        return [
            'encerradas' => $this->encerradas(),
            // Valor para <input type="datetime-local"> e rótulo dd/MM/aaaa HH:mm,
            // ambos no fuso do app (evita o shift de UTC do navegador).
            'prazo_input' => $prazo?->format('Y-m-d\TH:i'),
            'prazo_label' => $prazo?->format('d/m/Y H:i'),
            // Quanto falta, para a contagem regressiva do aviso. Null = sem prazo
            // ou prazo já vencido.
            'minutos_restantes' => $prazo && $prazo->isFuture()
                ? (int) ceil(now()->diffInMinutes($prazo, absolute: true))
                : null,
        ];
    }

    /** Define (ou remove, com null) a data-limite de submissão da edição atual. */
    public function definirPrazo(?string $data): array
    {
        // A data chega como "hora de parede" local (ex.: 2026-09-30T23:59) e é
        // interpretada no fuso do app — 23:59 é 23:59 em Campo Grande, sem shift.
        $valor = ($data !== null && $data !== '')
            ? Carbon::parse($data, config('app.timezone'))
            : null;

        $this->edicao()?->update(['submissoes_ate' => $valor]);

        return $this->config();
    }
}
