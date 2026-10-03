<?php

namespace App\Services;

use App\Enums\TipoRegistro;
use App\Models\CredenciamentoForaPrazo;
use App\Models\Projeto;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * **Credenciamento fora do prazo** aprovado (Sprint 161).
 *
 * Algumas equipes pediram para credenciar depois e não estarão no primeiro dia;
 * a organização aprovou. A marcação — com a data prevista de chegada, quando se
 * sabe — aparece nas telas de credenciamento e de avaliação
 * ({@see SinalizacaoProjetoService}), para quem está no balcão ou diante de um
 * estande vazio saber pelo próprio sistema por que o projeto não está lá.
 *
 * Marca quem administra o credenciamento (admin permanente — a conta
 * temporária atende, não aprova exceção), e cada marcação ou retirada vai para
 * Registros → Credenciamento.
 */
class ForaPrazoService
{
    public function __construct(
        private readonly CredenciamentoService $credenciamentos,
        private readonly RegistroAtividadeService $registros,
    ) {}

    public function marcar(Projeto $projeto, ?string $previstoEm, ?string $observacao, User $admin, bool $teste = false): CredenciamentoForaPrazo
    {
        if (! $this->credenciamentos->ehFinalista($projeto, $admin, $teste)) {
            throw ValidationException::withMessages([
                'projeto' => 'Este projeto não está na lista final vigente.',
            ]);
        }

        $previsto = $previstoEm ? CarbonImmutable::parse($previstoEm, config('app.timezone')) : null;
        $observacao = trim((string) $observacao) ?: null;

        $marca = CredenciamentoForaPrazo::updateOrCreate(
            ['projeto_id' => $projeto->id],
            ['previsto_em' => $previsto, 'observacao' => $observacao, 'registrado_por' => $admin->id],
        );

        $this->registros->atoNoProjeto(
            TipoRegistro::CredenciamentoForaPrazo, $projeto, $admin,
            'credenciamento fora do prazo aprovado'.($previsto ? ' · chegada prevista '.$previsto->format('d/m/Y H:i') : ''),
            $observacao,
        );

        return $marca;
    }

    public function remover(Projeto $projeto, User $admin): void
    {
        $marca = CredenciamentoForaPrazo::where('projeto_id', $projeto->id)->first();

        if ($marca === null) {
            return;
        }

        $marca->delete();

        $this->registros->atoNoProjeto(
            TipoRegistro::CredenciamentoForaPrazoRemovido, $projeto, $admin, 'marcação de credenciamento fora do prazo retirada',
        );
    }
}
