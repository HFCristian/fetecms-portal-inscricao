<?php

namespace App\Services;

use App\Enums\Role;
use App\Mail\MensagemTransacional;
use App\Models\AvaliadorProfile;
use App\Models\CadastroPendente;
use App\Models\OrientadorProfile;
use App\Models\User;
use App\Support\MensagemEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Confirmação do e-mail no cadastro de orientador e de avaliador.
 *
 * Nada é criado antes de o código de 6 dígitos ser digitado: o formulário
 * inteiro fica em `cadastros_pendentes` e só vira conta na confirmação. É por
 * isso que a pessoa pode repetir o MESMO CPF depois de errar o e-mail — o
 * cadastro anterior nunca ocupou `orientador_profiles`/`avaliador_profiles`.
 *
 * O código vale 15 minutos, aceita 5 tentativas e pode ser reenviado (com
 * intervalo mínimo, para o botão não virar disparador de e-mail).
 */
class ConfirmacaoCadastroService
{
    public const VALIDADE_MINUTOS = 15;

    public const MAX_TENTATIVAS = 5;

    public const INTERVALO_REENVIO_SEGUNDOS = 60;

    /** Assunto e corpo de fábrica — o admin edita em Comunicação → Modelos de e-mail. */
    public const ASSUNTO_PADRAO = 'Confirme seu e-mail — XVI FETECMS';

    public const CORPO_PADRAO = <<<'TXT'
        Olá, {{nome}}!

        Recebemos um cadastro de {{papel}} no portal da XVI FETECMS com este endereço de e-mail. Para concluir, digite o código abaixo na tela de cadastro:

        {{codigo}}

        O código vale por {{validade}} minutos. Se você não fez este cadastro, é só ignorar esta mensagem — nenhuma conta é criada sem a confirmação.
        TXT;

    public function __construct(
        private readonly OrientadorService $orientadores,
        private readonly AvaliadorService $avaliadores,
    ) {}

    /**
     * Guarda o cadastro preenchido e manda o código. O usuário ainda não existe.
     *
     * @param  array<string, mixed>  $dados  payload já validado do formulário
     */
    public function iniciar(Role $papel, array $dados): CadastroPendente
    {
        $this->limparAntigos();

        // Dois pendentes para o mesmo e-mail não fazem sentido: vale o último
        // preenchimento (é o caminho de quem recomeçou o cadastro).
        CadastroPendente::where('email', $dados['email'])->delete();

        // A senha nunca é gravada em claro: entra hasheada no payload e o cast
        // 'hashed' do User a mantém como está quando a conta for criada.
        $dados['password'] = Hash::make($dados['password']);
        unset($dados['password_confirmation']);

        $pendente = new CadastroPendente([
            'papel' => $papel->value,
            'nome' => $dados['name'],
            'email' => $dados['email'],
            'token' => Str::random(48),
            'payload' => $dados,
        ]);

        $this->gerarEEnviar($pendente);

        return $pendente;
    }

    /** Novo código para o mesmo e-mail (botão "reenviar"). */
    public function reenviar(CadastroPendente $pendente): CadastroPendente
    {
        $this->garantirIntervalo($pendente);
        $this->gerarEEnviar($pendente);

        return $pendente;
    }

    /**
     * A pessoa viu o e-mail errado na tela e corrigiu: troca o endereço e manda
     * um código novo para ele.
     */
    public function trocarEmail(CadastroPendente $pendente, string $email): CadastroPendente
    {
        if ($email !== $pendente->email) {
            $this->garantirIntervalo($pendente);

            CadastroPendente::where('email', $email)->whereKeyNot($pendente->getKey())->delete();

            $payload = $pendente->payload;
            $payload['email'] = $email;
            $pendente->fill(['email' => $email, 'payload' => $payload]);
        }

        $this->gerarEEnviar($pendente);

        return $pendente;
    }

    /**
     * Código correto: a conta nasce agora, com o papel escolhido lá no começo.
     *
     * @throws ValidationException código errado, vencido ou estourado; e-mail/CPF
     *                             tomados por outra pessoa nesse meio-tempo
     */
    public function confirmar(CadastroPendente $pendente, string $codigo): User
    {
        if ($pendente->expirou()) {
            throw ValidationException::withMessages([
                'codigo' => 'O código expirou. Peça um novo código para continuar.',
            ]);
        }

        if ($pendente->tentativas >= self::MAX_TENTATIVAS) {
            throw ValidationException::withMessages([
                'codigo' => 'Número de tentativas esgotado. Peça um novo código para continuar.',
            ]);
        }

        if (! Hash::check($codigo, $pendente->codigo_hash)) {
            $pendente->increment('tentativas');

            throw ValidationException::withMessages([
                'codigo' => 'Código incorreto. Confira o e-mail e tente de novo.',
            ]);
        }

        $this->garantirQueAindaCabe($pendente);

        $papel = Role::from($pendente->papel);
        $user = $papel === Role::Avaliador
            ? $this->avaliadores->register($pendente->payload)
            : $this->orientadores->register($pendente->payload);

        $pendente->delete();

        return $user;
    }

    /** Gera um código novo, zera as tentativas e envia o e-mail. */
    private function gerarEEnviar(CadastroPendente $pendente): void
    {
        $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $pendente->fill([
            'codigo_hash' => Hash::make($codigo),
            'tentativas' => 0,
            'expira_em' => now()->addMinutes(self::VALIDADE_MINUTOS),
            'ultimo_envio_em' => now(),
        ])->save();

        Mail::to($pendente->email)->send($this->mensagem($pendente, $codigo));
    }

    /** A mensagem que a pessoa recebe, com as variáveis já trocadas. */
    private function mensagem(CadastroPendente $pendente, string $codigo): MensagemTransacional
    {
        $valores = [
            'nome' => Str::before($pendente->nome, ' ') ?: $pendente->nome,
            'nome_completo' => $pendente->nome,
            'email' => $pendente->email,
            'codigo' => $codigo,
            'validade' => (string) self::VALIDADE_MINUTOS,
            'papel' => Role::from($pendente->papel)->label(),
        ];

        return new MensagemTransacional(
            assunto: MensagemEmail::personalizar(self::ASSUNTO_PADRAO, $valores),
            corpo: MensagemEmail::personalizar(self::CORPO_PADRAO, $valores),
            destaque: $codigo,
        );
    }

    /** O botão de reenviar não pode virar disparador automático de e-mail. */
    private function garantirIntervalo(CadastroPendente $pendente): void
    {
        $ultimo = $pendente->ultimo_envio_em;

        if ($ultimo !== null && $ultimo->diffInSeconds(now()) < self::INTERVALO_REENVIO_SEGUNDOS) {
            $faltam = self::INTERVALO_REENVIO_SEGUNDOS - (int) $ultimo->diffInSeconds(now());

            throw ValidationException::withMessages([
                'codigo' => "Aguarde {$faltam} segundos para pedir um novo código.",
            ]);
        }
    }

    /**
     * Entre o preenchimento e a confirmação, outra pessoa pode ter levado o
     * e-mail ou o CPF. A validação do formulário já passou — é aqui que a
     * corrida é resolvida, antes de tentar gravar.
     */
    private function garantirQueAindaCabe(CadastroPendente $pendente): void
    {
        if (User::where('email', $pendente->email)->exists()) {
            throw ValidationException::withMessages([
                'email' => 'Este e-mail já está cadastrado. Faça login ou use outro endereço.',
            ]);
        }

        $cpf = $pendente->payload['cpf'] ?? null;

        if ($cpf !== null && (
            OrientadorProfile::where('cpf', $cpf)->exists() || AvaliadorProfile::where('cpf', $cpf)->exists()
        )) {
            throw ValidationException::withMessages([
                'cpf' => 'Este CPF já está cadastrado.',
            ]);
        }
    }

    /**
     * Pendentes vencidos há mais de um dia saem da tabela. A folga existe para
     * quem volta com o link antigo receber "o código expirou" em vez de um 404.
     */
    private function limparAntigos(): void
    {
        CadastroPendente::where('expira_em', '<', now()->subDay())->delete();
    }
}
