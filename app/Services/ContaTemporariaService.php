<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\ContaTemporaria;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Contas temporárias de credenciamento (Credenciamento → Contas temporárias).
 *
 * O balcão do evento é atendido por gente de fora da organização — estudantes de
 * um curso, em geral. Em vez de virarem administradores plenos, elas ganham uma
 * conta igual à de admin (mesmo cadastro: nome, e-mail e senha) mais **CPF**,
 * **curso** e uma **janela de acesso**, e o portal as tranca na aba
 * Credenciamento.
 *
 * A janela é contada em **horas** (padrão: 5, o tamanho de um turno de balcão) e
 * pode **começar depois**: informando `valido_de`, o admin deixa a equipe toda
 * cadastrada dias antes e cada conta acorda sozinha na hora marcada. Antes do
 * início a conta existe, aparece como *agendada* e o login é recusado com a
 * data — ela não é desativada, porque desativar a faria parecer encerrada.
 *
 * Vencido o prazo, aí sim a conta é **desativada, não apagada**: reativar é
 * informar uma janela nova, sem recadastrar nada. A varredura roda a cada
 * leitura da lista e em cada tentativa de login, então não depende de agendador
 * — o mesmo caminho que a faxina das sessões do comitê usa.
 */
class ContaTemporariaService
{
    /** Quantas horas o formulário sugere quando ninguém escolhe. */
    public const HORAS_PADRAO = 5;

    /** Teto do campo de duração: um ano em horas, para o formulário e a API. */
    public const HORAS_MAX = 8760;

    /**
     * As contas cadastradas, com a situação de cada uma. Vencidas são
     * desativadas antes de listar, para a tela nunca mostrar "ativa" para quem
     * já perdeu o acesso.
     *
     * @return array<string, mixed>
     */
    public function listar(): array
    {
        $this->expirarVencidas();

        $contas = ContaTemporaria::with(['user', 'autor:id,name'])
            ->get()
            ->sortBy(fn (ContaTemporaria $c) => mb_strtolower($c->user?->name ?? ''))
            ->values();

        return [
            'contas' => $contas->map(fn (ContaTemporaria $c) => $this->resumo($c))->all(),
            'horas_padrao' => self::HORAS_PADRAO,
            'horas_max' => self::HORAS_MAX,
        ];
    }

    /**
     * Cria a conta: o `users` (role admin) e a linha que a restringe ao balcão.
     *
     * @param  array<string, mixed>  $dados
     */
    public function criar(array $dados, ?User $autor = null): ContaTemporaria
    {
        [$validoDe, $expiraEm] = $this->janela($dados);

        return DB::transaction(function () use ($dados, $autor, $validoDe, $expiraEm) {
            $user = User::create([
                'name' => trim($dados['name']),
                'email' => $dados['email'],
                'password' => $dados['password'],
                'role' => Role::Admin,
                'is_active' => true,
            ]);

            return ContaTemporaria::create([
                'user_id' => $user->id,
                'cpf' => preg_replace('/\D/', '', (string) $dados['cpf']),
                'curso' => trim($dados['curso']),
                'valido_de' => $validoDe,
                'expira_em' => $expiraEm,
                'criado_por' => $autor?->id,
            ]);
        });
    }

    /**
     * Renova o acesso: janela nova e conta reativada. É o caminho de quem venceu
     * — não se recadastra nome, e-mail, CPF nem curso. Também serve para
     * **reagendar** uma conta que ainda não começou.
     *
     * @param  array<string, mixed>  $dados
     */
    public function renovar(ContaTemporaria $conta, array $dados): ContaTemporaria
    {
        [$validoDe, $expiraEm] = $this->janela($dados);

        DB::transaction(function () use ($conta, $validoDe, $expiraEm) {
            $conta->update(['valido_de' => $validoDe, 'expira_em' => $expiraEm]);
            $conta->user?->update(['is_active' => true]);
        });

        return $conta->refresh();
    }

    /**
     * Desativa a conta antes do prazo (a pessoa saiu da equipe, por exemplo).
     * O prazo em si não muda: renovar continua sendo o caminho de volta.
     */
    public function desativar(ContaTemporaria $conta): ContaTemporaria
    {
        $conta->user?->update(['is_active' => false]);

        return $conta->refresh();
    }

    /**
     * Desativa quem passou do prazo. Idempotente: quem já está inativo não é
     * tocado, então rodar isto a cada leitura não custa escrita.
     *
     * @return int quantas contas foram desativadas nesta passada
     */
    public function expirarVencidas(): int
    {
        $vencidas = ContaTemporaria::where('expira_em', '<=', now())
            ->whereHas('user', fn ($q) => $q->where('is_active', true))
            ->pluck('user_id');

        if ($vencidas->isEmpty()) {
            return 0;
        }

        return User::whereIn('id', $vencidas)->update(['is_active' => false]);
    }

    /**
     * Esta pessoa está barrada por ser uma conta temporária fora da janela?
     * Devolve a mensagem a mostrar no login, ou `null` quando pode entrar.
     *
     * Chamado no login, para a janela valer mesmo que ninguém abra a tela de
     * contas. **Vencida** desativa a conta na passagem; **agendada** não —
     * ela ainda vai valer, e desativar a faria parecer encerrada na lista.
     */
    public function impedimentoDeLogin(User $user): ?string
    {
        $conta = $user->contaTemporaria;

        if ($conta === null) {
            return null;
        }

        if ($conta->vencida()) {
            $user->update(['is_active' => false]);

            return 'O prazo de acesso desta conta temporária venceu. Peça a renovação à organização.';
        }

        if ($conta->agendada()) {
            return 'O acesso desta conta temporária começa em '
                .$conta->valido_de->format('d/m/Y \à\s H:i').'.';
        }

        return null;
    }

    /**
     * A janela [início, fim] a partir do que o formulário mandou.
     *
     * O **início** é `valido_de` (em branco, agora: a conta já vale). O **fim**
     * é uma data explícita (`expira_em`) ou uma quantidade de **horas** contada
     * a partir do início — é isso que faz "5 horas a partir das 8h de sábado"
     * ser exatamente o que se digita, em vez de 5 horas a partir do cadastro.
     *
     * @param  array<string, mixed>  $dados
     * @return array{0: ?CarbonImmutable, 1: CarbonImmutable}
     */
    private function janela(array $dados): array
    {
        $agora = CarbonImmutable::now(config('app.timezone'));

        $validoDe = empty($dados['valido_de'])
            ? null
            : CarbonImmutable::parse($dados['valido_de'], config('app.timezone'));

        if ($validoDe !== null && $validoDe->isPast()) {
            // Início no passado é o mesmo que "vale desde já": guardar a data
            // antiga só encheria a tela de "agendada" que já começou.
            $validoDe = null;
        }

        $inicio = $validoDe ?? $agora;

        if (! empty($dados['expira_em'])) {
            $fim = CarbonImmutable::parse($dados['expira_em'], config('app.timezone'));
        } else {
            $horas = (int) ($dados['horas'] ?? self::HORAS_PADRAO);
            $fim = $inicio->addHours(max(1, $horas));
        }

        if ($fim->isPast()) {
            throw ValidationException::withMessages([
                'expira_em' => 'O prazo precisa ser no futuro.',
            ]);
        }

        if ($fim->lessThanOrEqualTo($inicio)) {
            throw ValidationException::withMessages([
                'expira_em' => 'O prazo precisa ser depois do início do acesso.',
            ]);
        }

        return [$validoDe, $fim];
    }

    /** @return array<string, mixed> */
    private function resumo(ContaTemporaria $conta): array
    {
        $user = $conta->user;
        $vencida = $conta->vencida();
        $agendada = $conta->agendada();

        return [
            'id' => $conta->id,
            'user_id' => $conta->user_id,
            'nome' => $user?->name,
            'email' => $user?->email,
            'cpf' => $conta->cpfFormatado(),
            'curso' => $conta->curso,
            // Início da janela: nulo é "vale desde a criação".
            'valido_de' => $conta->valido_de?->toIso8601String(),
            'valido_de_input' => $conta->valido_de?->format('Y-m-d\TH:i'),
            'valido_de_label' => $conta->valido_de?->format('d/m/Y H:i'),
            'expira_em' => $conta->expira_em->toIso8601String(),
            'expira_em_input' => $conta->expira_em->format('Y-m-d\TH:i'),
            'expira_em_label' => $conta->expira_em->format('d/m/Y H:i'),
            'ativa' => (bool) $user?->is_active,
            'vencida' => $vencida,
            'agendada' => $agendada,
            // Só faz sentido enquanto ela ainda vale; vencida, o número seria negativo.
            'horas_restantes' => $vencida ? 0 : (int) ceil(now()->floatDiffInHours($conta->expira_em)),
            'duracao_label' => $this->duracaoLabel($conta),
            'criada_por' => $conta->autor?->name,
            'criada_em' => $conta->created_at?->format('d/m/Y H:i'),
        ];
    }

    /**
     * Quanto tempo ainda resta, na unidade que cabe: minutos na última hora,
     * horas no dia, dias acima disso. A conta agendada mostra o tamanho da
     * janela que vai receber, não o tempo até ela abrir.
     */
    private function duracaoLabel(ContaTemporaria $conta): string
    {
        if ($conta->vencida()) {
            return 'encerrado';
        }

        $de = $conta->agendada() ? $conta->valido_de : now();
        $minutos = (int) ceil($de->floatDiffInMinutes($conta->expira_em));

        if ($minutos < 60) {
            return $minutos.($minutos === 1 ? ' minuto' : ' minutos');
        }

        if ($minutos < 60 * 48) {
            $horas = (int) ceil($minutos / 60);

            return $horas.($horas === 1 ? ' hora' : ' horas');
        }

        $dias = (int) ceil($minutos / (60 * 24));

        return $dias.' dias';
    }
}
