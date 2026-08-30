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
 * **curso** e **prazo**, e o portal as tranca na aba Credenciamento.
 *
 * Vencido o prazo, a conta é **desativada, não apagada**: reativar é informar um
 * prazo novo, sem recadastrar nada. A varredura roda a cada leitura da lista e
 * em cada tentativa de login, então não depende de agendador — o mesmo caminho
 * que a faxina das sessões do comitê usa.
 */
class ContaTemporariaService
{
    /** Quantos dias o formulário sugere quando ninguém escolhe. */
    public const DIAS_PADRAO = 7;

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
            'dias_padrao' => self::DIAS_PADRAO,
        ];
    }

    /**
     * Cria a conta: o `users` (role admin) e a linha que a restringe ao balcão.
     *
     * @param  array<string, mixed>  $dados
     */
    public function criar(array $dados, ?User $autor = null): ContaTemporaria
    {
        $expiraEm = $this->prazo($dados);

        return DB::transaction(function () use ($dados, $autor, $expiraEm) {
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
                'expira_em' => $expiraEm,
                'criado_por' => $autor?->id,
            ]);
        });
    }

    /**
     * Renova o acesso: prazo novo e conta reativada. É o caminho de quem venceu
     * — não se recadastra nome, e-mail, CPF nem curso.
     *
     * @param  array<string, mixed>  $dados
     */
    public function renovar(ContaTemporaria $conta, array $dados): ContaTemporaria
    {
        $expiraEm = $this->prazo($dados);

        DB::transaction(function () use ($conta, $expiraEm) {
            $conta->update(['expira_em' => $expiraEm]);
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
     * Esta pessoa está barrada por ser uma conta temporária vencida? Chamado no
     * login, para o vencimento valer mesmo que ninguém abra a tela de contas.
     */
    public function venceuParaLogin(User $user): bool
    {
        $conta = $user->contaTemporaria;

        if ($conta === null || ! $conta->vencida()) {
            return false;
        }

        $user->update(['is_active' => false]);

        return true;
    }

    /**
     * A data de vencimento a partir do que o formulário mandou: uma data
     * explícita (`expira_em`) ou uma quantidade de dias a contar de agora.
     *
     * @param  array<string, mixed>  $dados
     */
    private function prazo(array $dados): CarbonImmutable
    {
        if (! empty($dados['expira_em'])) {
            $data = CarbonImmutable::parse($dados['expira_em'], config('app.timezone'));
        } else {
            $dias = (int) ($dados['dias'] ?? self::DIAS_PADRAO);
            $data = CarbonImmutable::now(config('app.timezone'))->addDays(max(1, $dias))->endOfDay();
        }

        if ($data->isPast()) {
            throw ValidationException::withMessages([
                'expira_em' => 'O prazo precisa ser no futuro.',
            ]);
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function resumo(ContaTemporaria $conta): array
    {
        $user = $conta->user;
        $vencida = $conta->vencida();

        return [
            'id' => $conta->id,
            'user_id' => $conta->user_id,
            'nome' => $user?->name,
            'email' => $user?->email,
            'cpf' => $conta->cpfFormatado(),
            'curso' => $conta->curso,
            'expira_em' => $conta->expira_em->toIso8601String(),
            'expira_em_input' => $conta->expira_em->format('Y-m-d\TH:i'),
            'expira_em_label' => $conta->expira_em->format('d/m/Y H:i'),
            'ativa' => (bool) $user?->is_active,
            'vencida' => $vencida,
            // Só faz sentido enquanto ela ainda vale; vencida, o número seria negativo.
            'dias_restantes' => $vencida ? 0 : (int) ceil(now()->floatDiffInDays($conta->expira_em)),
            'criada_por' => $conta->autor?->name,
            'criada_em' => $conta->created_at?->format('d/m/Y H:i'),
        ];
    }
}
