<?php

namespace App\Services;

use App\Models\Edicao;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Janela de inscrição: quando abre e até quando vai. Fora dela a área do
 * orientador fica só de leitura — não dá para criar, editar, submeter,
 * cancelar a submissão nem excluir projeto (inclusive quem cancelou o envio
 * para editar não consegue reenviar depois do prazo). Antes da abertura o
 * cadastro de orientador também fica fechado. O admin passa por cima dos dois
 * lados (escape previsto no edital).
 *
 * Sem data definida, cada ponta fica aberta.
 */
class InscricoesService
{
    public const CODE = 'INSCRICOES_ENCERRADAS';

    public const CODE_NAO_INICIADAS = 'INSCRICOES_NAO_INICIADAS';

    public function edicao(): ?Edicao
    {
        return Edicao::atual();
    }

    public function prazo(): ?Carbon
    {
        return $this->edicao()?->submissoes_ate;
    }

    public function inicio(): ?Carbon
    {
        return $this->edicao()?->submissoes_de;
    }

    public function encerradas(): bool
    {
        return (bool) $this->edicao()?->inscricoesEncerradas();
    }

    /** Ainda não abriram (data de abertura definida e no futuro)? */
    public function naoIniciadas(): bool
    {
        return (bool) $this->edicao()?->inscricoesNaoIniciadas();
    }

    /** Está dentro da janela: já abriu e ainda não encerrou. */
    public function abertas(): bool
    {
        return ! $this->naoIniciadas() && ! $this->encerradas();
    }

    /** Este usuário está barrado pela janela? O admin nunca está. */
    public function bloqueadoPara(?User $user): bool
    {
        return ! ($user?->isAdmin() ?? false) && ! $this->abertas();
    }

    public function mensagem(): string
    {
        if ($this->naoIniciadas()) {
            $inicio = $this->inicio();

            return 'As inscrições ainda não começaram'
                .($inicio ? ' — abrem em '.$inicio->format('d/m/Y H:i').'.' : '.');
        }

        $prazo = $this->prazo();

        return $prazo
            ? 'As inscrições foram encerradas em '.$prazo->format('d/m/Y H:i').'. Não é mais possível criar, editar, submeter ou cancelar projetos.'
            : 'As inscrições estão encerradas.';
    }

    /** @return array{code: string, message: string} */
    public function motivo(): array
    {
        return [
            'code' => $this->naoIniciadas() ? self::CODE_NAO_INICIADAS : self::CODE,
            'message' => $this->mensagem(),
        ];
    }

    /**
     * O cadastro público de orientador só fica bloqueado antes da abertura —
     * depois do prazo a pessoa ainda pode criar a conta (só não cadastra projeto).
     */
    public function cadastroBloqueado(): bool
    {
        return $this->naoIniciadas();
    }

    /**
     * Estado da janela, usado tanto pela tela do admin quanto pelo aviso ao
     * orientador.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $prazo = $this->prazo();
        $inicio = $this->inicio();

        return [
            'abertas' => $this->abertas(),
            'encerradas' => $this->encerradas(),
            'nao_iniciadas' => $this->naoIniciadas(),
            // Valores para <input type="datetime-local"> e rótulos dd/MM/aaaa HH:mm,
            // ambos no fuso do app (evita o shift de UTC do navegador).
            'inicio_input' => $inicio?->format('Y-m-d\TH:i'),
            'inicio_label' => $inicio?->format('d/m/Y H:i'),
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
        $valor = $this->interpretar($data);

        $inicio = $this->inicio();
        if ($valor && $inicio && $valor->lessThanOrEqualTo($inicio)) {
            throw ValidationException::withMessages([
                'prazo' => 'O prazo de submissão precisa ser depois da abertura das inscrições ('.$inicio->format('d/m/Y H:i').').',
            ]);
        }

        $this->edicao()?->update(['submissoes_ate' => $valor]);

        return $this->config();
    }

    /** Define (ou remove, com null) a data de abertura das inscrições. */
    public function definirInicio(?string $data): array
    {
        $valor = $this->interpretar($data);

        $prazo = $this->prazo();
        if ($valor && $prazo && $valor->greaterThanOrEqualTo($prazo)) {
            throw ValidationException::withMessages([
                'inicio' => 'A abertura das inscrições precisa ser antes do prazo de submissão ('.$prazo->format('d/m/Y H:i').').',
            ]);
        }

        $this->edicao()?->update(['submissoes_de' => $valor]);

        return $this->config();
    }

    /**
     * A data chega como "hora de parede" local (ex.: 2026-09-30T23:59) e é
     * interpretada no fuso do app — 23:59 é 23:59 em Campo Grande, sem shift.
     */
    private function interpretar(?string $data): ?Carbon
    {
        return ($data !== null && $data !== '')
            ? Carbon::parse($data, config('app.timezone'))
            : null;
    }
}
