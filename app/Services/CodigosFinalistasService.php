<?php

namespace App\Services;

use App\Enums\ModeloEmail;
use App\Enums\StatusDestinatario;
use App\Enums\TipoRegistro;
use App\Models\ListaFinal;
use App\Models\MalaDireta;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Lista final → **Código do projeto** (Sprint 159): mandar a cada finalista o
 * código do seu projeto (FET.AGR-001), para o credenciamento e a checagem dos
 * estandes não dependerem de alguém achar o título certo numa lista.
 *
 * Duas peças que já existiam fazem o trabalho:
 *
 * - o código é **fixado** na lista ({@see ListaFinalService::fixarCodigos()})
 *   antes de sair — depois do e-mail, o número de uma equipe não pode mudar;
 * - o envio é uma **mala direta** para a equipe inteira dos finalistas, com o
 *   texto do modelo `codigo_projeto` (editável em Comunicação → Modelos de
 *   e-mail) e a variável `{{projetos}}`, que vira código + título de quem
 *   recebe. Assim o envio ganha de graça a fila, o relatório por endereço e o
 *   reenvio das falhas.
 *
 * Só a lista **vigente** e oficial manda código: é dela que saem os finalistas
 * que a mala direta alcança, e mandar o código de uma prévia seria comunicar um
 * número que ainda pode não existir.
 */
class CodigosFinalistasService
{
    public function __construct(
        private readonly ListaFinalService $listas,
        private readonly MalaDiretaService $malas,
        private readonly ModeloEmailService $modelos,
        private readonly RegistroAtividadeService $registros,
    ) {}

    /** @return array<string, mixed> */
    public function painel(ListaFinal $lista): array
    {
        $texto = $this->modelos->texto(ModeloEmail::CodigoProjeto);
        $motivo = $this->impedimento($lista);

        return [
            'lista' => [
                'id' => $lista->id,
                'nome' => $lista->nome,
                'versao' => (int) $lista->versao,
                'codigos_congelados_em' => $lista->codigos_congelados_em?->toIso8601String(),
                'codigos_enviados_em' => $lista->codigos_enviados_em?->toIso8601String(),
                'codigos_mala_id' => $lista->codigos_mala_id,
            ],
            'pode_enviar' => $motivo === null,
            'motivo' => $motivo,
            'destinatarios' => $motivo === null ? $this->malas->resumoFinalistas() : null,
            'assunto' => $texto['assunto'],
            'corpo' => $texto['corpo'],
            'formato' => $texto['formato'],
            'projetos' => array_map(fn (array $i) => [
                'projeto_id' => $i['projeto_id'],
                'codigo' => $i['codigo'],
                'titulo' => $i['titulo'],
            ], $this->listas->itensDaLista($lista)),
        ];
    }

    /** Fixa os códigos sem mandar nada (para imprimir crachá antes do e-mail). */
    public function fixar(ListaFinal $lista, User $admin): int
    {
        $this->garantirPodeEnviar($lista);

        $jaFixada = $lista->codigos_congelados_em !== null;
        $gravados = $this->listas->fixarCodigos($lista);

        if (! $jaFixada) {
            $this->registros->atoNaLista(
                TipoRegistro::ListaFinalCodigosFixados, $admin, $lista->nome,
                "{$gravados} código(s) fixado(s) na versão {$lista->versao}",
            );
        }

        return $gravados;
    }

    /**
     * Fixa os códigos e dispara a mala direta para a equipe dos finalistas.
     * Mandar de novo é permitido (alguém trocou de e-mail, a lista ganhou um
     * projeto): os códigos já fixados não mudam, e cada envio vira uma mala
     * própria, com o seu relatório.
     */
    public function enviar(ListaFinal $lista, User $admin): MalaDireta
    {
        $this->garantirPodeEnviar($lista);

        $pendentes = $this->malas->resolver([], [], finalistas: true)
            ->where('status', StatusDestinatario::Pendente->value);

        if ($pendentes->isEmpty()) {
            throw ValidationException::withMessages([
                'lista' => 'Nenhum finalista desta lista tem e-mail cadastrado.',
            ]);
        }

        $this->fixar($lista, $admin);
        $texto = $this->modelos->texto(ModeloEmail::CodigoProjeto);

        // Sem transação por fora: a mala grava e enfileira na dela, e um job não
        // pode sair antes de a linha que ele lê estar gravada.
        $mala = $this->malas->criar([
            'nome' => "Código do projeto — {$lista->nome} (v{$lista->versao})",
            'justificativa' => 'Envio do código do projeto aos finalistas, para o credenciamento e a checagem dos estandes.',
            'solicitante' => null,
            'assunto' => $texto['assunto'],
            'corpo' => $texto['corpo'],
            'formato' => $texto['formato'],
            'publicos' => [],
            'destinatarios' => [],
            'finalistas' => true,
        ], $admin);

        $lista->update(['codigos_enviados_em' => now(), 'codigos_mala_id' => $mala->id]);

        $this->registros->atoNaLista(
            TipoRegistro::ListaFinalCodigosEnviados, $admin, $lista->nome,
            "código enviado a {$pendentes->count()} pessoa(s) · mala direta #{$mala->id}",
        );

        return $mala;
    }

    private function garantirPodeEnviar(ListaFinal $lista): void
    {
        if (($motivo = $this->impedimento($lista)) !== null) {
            throw ValidationException::withMessages(['lista' => $motivo]);
        }
    }

    private function impedimento(ListaFinal $lista): ?string
    {
        return match (true) {
            $lista->demo => 'A lista de demonstração não manda e-mail para ninguém.',
            $lista->ehPreliminar() => 'Lista preliminar não define finalista: os códigos saem da lista final ativa.',
            (bool) $lista->rascunho => 'Publique a lista antes de enviar os códigos: uma prévia ainda pode mudar.',
            ! $lista->vigente => 'Só a lista final ativa manda código — esta não é a ativa.',
            default => null,
        };
    }
}
