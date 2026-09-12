<?php

namespace App\Services;

use App\Enums\ModeloEmail;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Os e-mails da designação em massa (Avaliação online → Designações).
 *
 * Dois destinatários, dois propósitos:
 *
 * - o **avaliador** recebe **um** e-mail com tudo que chegou para ele naquela
 *   operação. Um por projeto encheria a caixa de entrada de quem recebeu dez —
 *   e a pessoa precisa da lista, não de dez avisos iguais;
 * - o **admin que designou** recebe o resumo: quantas foram criadas e o que não
 *   pôde ser designado. Designar uma área inteira quase sempre esbarra em
 *   alguém que já avaliou aquele projeto, e ele precisa saber se a cobertura que
 *   queria de fato aconteceu.
 *
 * Tudo vai para a **fila** e qualquer falha é engolida com log: as designações
 * já estão gravadas quando chegamos aqui, e servidor de e-mail fora do ar não
 * pode derrubar a resposta nem desfazer o trabalho.
 */
class NotificacaoDesignacaoService
{
    public function __construct(private readonly ModeloEmailService $modelos) {}

    /**
     * Avisa um avaliador dos projetos que acabaram de ser designados a ele.
     *
     * @param  list<string>  $projetos  títulos, na ordem em que foram designados
     */
    public function avaliador(User $avaliador, array $projetos): void
    {
        if ($projetos === [] || blank($avaliador->email)) {
            return;
        }

        $mensagem = $this->modelos->mensagem(ModeloEmail::ProjetosDesignados, [
            'nome' => Str::before($avaliador->name, ' ') ?: $avaliador->name,
            'nome_completo' => $avaliador->name,
            'email' => $avaliador->email,
            'quantidade' => (string) count($projetos),
            // Um por linha, com marcador: é uma lista para ler, não uma frase.
            'projetos' => implode("\n", array_map(fn (string $t) => '• '.$t, $projetos)),
        ]);

        $this->enviar($avaliador->email, $mensagem, ['avaliador_id' => $avaliador->id]);
    }

    /**
     * Manda ao admin o resumo do que aconteceu.
     *
     * @param  array<string, mixed>  $resumo  saída de DesignacaoService::designar()
     */
    public function resumoParaAdmin(User $admin, array $resumo): void
    {
        if (blank($admin->email)) {
            return;
        }

        $mensagem = $this->modelos->mensagem(ModeloEmail::DesignacaoConcluida, [
            'nome' => Str::before($admin->name, ' ') ?: $admin->name,
            'nome_completo' => $admin->name,
            'email' => $admin->email,
            'resumo' => self::descreverResumo($resumo),
            'problemas' => self::descreverProblemas($resumo),
        ]);

        $this->enviar($admin->email, $mensagem, ['admin_id' => $admin->id]);
    }

    /**
     * A frase de "deu certo": quantas designações e para quantas pessoas.
     *
     * @param  array<string, mixed>  $resumo
     */
    public static function descreverResumo(array $resumo): string
    {
        $criadas = (int) ($resumo['designadas'] ?? 0) + (int) ($resumo['retomadas'] ?? 0);
        $avaliadores = count($resumo['por_avaliador'] ?? []);

        if ($criadas === 0) {
            return 'Nenhuma designação nova foi criada.';
        }

        return sprintf(
            '%d designação(ões) criada(s) para %d avaliador(es).',
            $criadas,
            $avaliadores,
        );
    }

    /**
     * A frase do que não deu — ou a confirmação de que deu tudo certo, que é
     * informação também: sem ela o admin fica na dúvida se o e-mail cortou.
     *
     * @param  array<string, mixed>  $resumo
     */
    public static function descreverProblemas(array $resumo): string
    {
        $ignoradas = $resumo['ignoradas'] ?? [];

        if ($ignoradas === []) {
            return 'Todas as designações foram realizadas, sem nenhum problema.';
        }

        $linhas = array_map(
            fn (array $i) => sprintf('• %s → %s: %s', $i['projeto'], $i['avaliador'], $i['motivo']),
            $ignoradas,
        );

        return sprintf(
            "%d designação(ões) não foram feitas:\n\n%s",
            count($ignoradas),
            implode("\n", $linhas),
        );
    }

    /** @param  array<string, mixed>  $contexto */
    private function enviar(string $email, mixed $mensagem, array $contexto): void
    {
        try {
            Mail::to($email)->queue($mensagem);
        } catch (\Throwable $e) {
            Log::warning('Falha ao enfileirar o aviso de designação.', $contexto + ['erro' => $e->getMessage()]);
        }
    }
}
