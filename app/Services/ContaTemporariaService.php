<?php

namespace App\Services;

use App\Enums\Role;
use App\Enums\StatusPresenca;
use App\Http\Requests\Concerns\NormalizaEmail;
use App\Models\ContaTemporaria;
use App\Models\User;
use App\Rules\Cpf;
use App\Support\LeitorPlanilha;
use App\Support\PlanilhaXlsx;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Contas temporárias de credenciamento (Credenciamento → Contas temporárias).
 *
 * O balcão do evento é atendido por gente de fora da organização — estudantes de
 * um curso, em geral. Em vez de virarem administradores plenos, elas ganham uma
 * conta igual à de admin (mesmo cadastro: nome, e-mail e senha) mais **CPF**,
 * **curso** e uma **janela de acesso**, e o portal as tranca na aba do **setor**
 * delas — Credenciamento ou Almoxarifado. As duas abas mantêm listas
 * independentes: cada uma cadastra, renova e encerra só as suas.
 *
 * A janela é contada em **horas** (padrão: 5, o tamanho de um turno de balcão) e
 * pode **começar depois**: informando `valido_de`, o admin deixa a equipe toda
 * cadastrada dias antes e cada conta acorda sozinha na hora marcada. Antes do
 * início a conta existe, aparece como *agendada* e o login é recusado com a
 * data — ela não é desativada, porque desativar a faria parecer encerrada.
 *
 * O **voluntário** da avaliação presencial (Sprint 133) usa a mesma conta com
 * uma diferença: ele trabalha em **turnos** — sábado de manhã e domingo à
 * tarde, por exemplo. Os turnos não substituem a janela; ela vira o
 * **envelope** deles (o primeiro início, o último fim), e o que eles
 * acrescentam é uma trava fina no login: **entre** um turno e outro a conta
 * existe, está no prazo e mesmo assim não abre.
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

    /** Quantas contas um lote cria de uma vez: uma equipe, não a base inteira. */
    public const MAX_LOTE = 300;

    /**
     * Onde uma conta temporária deixa rastro. Conta que aparece em qualquer
     * destas colunas já atendeu alguém e, ao ser removida, é **arquivada** em
     * vez de apagada: são essas fichas que dizem quem atendeu, e elas não
     * guardam o nome à parte.
     *
     * @var array<string, list<string>>
     */
    private const RASTROS = [
        'credenciamentos' => ['credenciado_por', 'iniciado_por'],
        'credenciamento_pessoas' => ['kit_registrado_por'],
        'almoxarifado_guardas' => ['registrado_por'],
        'almoxarifado_itens' => ['retirada_registrada_por'],
        'cerimonial_checkins' => ['registrado_por'],
        'checagens_estande' => ['verificado_por'],
        'credencial_projeto' => ['atribuida_por'],
        'registros_atividade' => ['user_id'],
    ];

    /**
     * As colunas do modelo de importação. A primeira palavra de cada título é
     * a que a leitura procura, então a ordem pode mudar na planilha.
     *
     * @var array<string, array{titulo: string, largura: int, texto?: bool}>
     */
    private const COLUNAS_LOTE = [
        'name' => ['titulo' => 'Nome completo', 'largura' => 34],
        'email' => ['titulo' => 'E-mail', 'largura' => 32],
        'cpf' => ['titulo' => 'CPF (só números ou com pontos)', 'largura' => 22, 'texto' => true],
        'curso' => ['titulo' => 'Curso', 'largura' => 28],
        'valido_de' => ['titulo' => 'Início do acesso (dd/mm/aaaa hh:mm — em branco usa o padrão da tela)', 'largura' => 30, 'texto' => true],
        'horas' => ['titulo' => 'Horas de acesso (em branco usa o padrão da tela)', 'largura' => 20],
        'turnos' => ['titulo' => 'Turnos (dd/mm/aaaa hh:mm-hh:mm; separe vários com ponto e vírgula)', 'largura' => 60, 'texto' => true],
    ];

    /**
     * As contas cadastradas, com a situação de cada uma. Vencidas são
     * desativadas antes de listar, para a tela nunca mostrar "ativa" para quem
     * já perdeu o acesso.
     *
     * @return array<string, mixed>
     */
    public function listar(string $setor = ContaTemporaria::SETOR_CREDENCIAMENTO): array
    {
        $this->expirarVencidas();

        $contas = ContaTemporaria::with(['user', 'autor:id,name', 'turnos', 'decisor:id,name'])
            ->where('setor', $setor)
            // Arquivadas não voltam à lista: para o setor, elas não existem mais.
            ->whereNull('removida_em')
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
    public function criar(array $dados, ?User $autor = null, string $setor = ContaTemporaria::SETOR_CREDENCIAMENTO): ContaTemporaria
    {
        $turnos = $this->turnos($dados);
        [$validoDe, $expiraEm] = $this->janela($dados, $turnos);

        return DB::transaction(function () use ($dados, $autor, $validoDe, $expiraEm, $setor, $turnos) {
            $user = User::create([
                'name' => trim($dados['name']),
                'email' => $dados['email'],
                'password' => $dados['password'],
                'role' => Role::Admin,
                'is_active' => true,
            ]);

            $conta = ContaTemporaria::create([
                'user_id' => $user->id,
                'cpf' => preg_replace('/\D/', '', (string) $dados['cpf']),
                'curso' => trim($dados['curso']),
                'setor' => $setor,
                'valido_de' => $validoDe,
                'expira_em' => $expiraEm,
                'criado_por' => $autor?->id,
            ]);

            $this->gravarTurnos($conta, $turnos);

            return $conta->fresh();
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
        $turnos = $this->turnos($dados);
        [$validoDe, $expiraEm] = $this->janela($dados, $turnos);

        DB::transaction(function () use ($conta, $validoDe, $expiraEm, $turnos, $dados) {
            $conta->update(['valido_de' => $validoDe, 'expira_em' => $expiraEm]);
            $conta->user?->update(['is_active' => true]);

            // Turnos só são mexidos quando o formulário os manda: renovar sem
            // citá-los é prorrogar a mesma escala, não apagá-la.
            if (array_key_exists('turnos', $dados)) {
                $conta->turnos()->delete();
                $this->gravarTurnos($conta, $turnos);
            }
        });

        return $conta->refresh();
    }

    /**
     * **Remove** a conta (Sprint 155). Desativar continua existindo e é
     * reversível; remover é para quem não devia estar ali — cadastro errado,
     * pessoa que não veio, linha duplicada de um lote.
     *
     * Sem rastro nenhum, a conta é **apagada**: o `users` some e leva junto a
     * linha temporária e os turnos (cascade). Com rastro ({@see self::RASTROS}),
     * ela é **arquivada**: o login morre de vez — conta inativa, e-mail trocado
     * por um endereço que não existe (o original fica livre para um cadastro
     * novo), senha embaralhada e sessões encerradas —, mas o nome continua
     * respondendo por quem credenciou, guardou ou fez check-in.
     *
     * @return 'excluida'|'arquivada'
     */
    public function remover(ContaTemporaria $conta): string
    {
        $user = $conta->user;

        if ($user === null) {
            $conta->delete();

            return 'excluida';
        }

        return DB::transaction(function () use ($conta, $user) {
            $this->encerrarSessoes($user);

            if (! $this->temRastro($user->id)) {
                $user->delete();

                return 'excluida';
            }

            $user->forceFill([
                'email' => sprintf('removida.%d.%s@conta-temporaria.invalid', $user->id, Str::lower(Str::random(6))),
                'password' => Hash::make(Str::random(40)),
                'is_active' => false,
                'remember_token' => null,
            ])->save();

            $conta->update(['removida_em' => now()]);

            return 'arquivada';
        });
    }

    /** Já atendeu alguém? Basta aparecer numa das colunas de rastro. */
    private function temRastro(int $userId): bool
    {
        foreach (self::RASTROS as $tabela => $colunas) {
            if (! Schema::hasTable($tabela)) {
                continue;
            }

            foreach ($colunas as $coluna) {
                if (DB::table($tabela)->where($coluna, $userId)->exists()) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Desconecta a pessoa agora, sem esperar a sessão expirar. */
    private function encerrarSessoes(User $user): void
    {
        $user->tokens()->delete();

        if (Schema::hasTable('sessions')) {
            DB::table('sessions')->where('user_id', $user->id)->delete();
        }
    }

    // --- Cadastro em lote (Sprint 155) ---------------------------------------

    /**
     * As colunas do modelo deste setor: o voluntário da avaliação presencial
     * informa **turnos**; os balcões informam início e horas.
     *
     * @return array<string, array{titulo: string, largura: int, texto?: bool}>
     */
    private function colunasLote(string $setor): array
    {
        $chaves = $setor === ContaTemporaria::SETOR_PRESENCIAL
            ? ['name', 'email', 'cpf', 'curso', 'turnos']
            : ['name', 'email', 'cpf', 'curso', 'valido_de', 'horas'];

        return array_intersect_key(self::COLUNAS_LOTE, array_flip($chaves));
    }

    /**
     * O modelo em Excel: só o cabeçalho, com o CPF e as datas em formato
     * **texto** — numa coluna "Geral" o Excel come o zero da frente do CPF e
     * transforma "04/10/2026 08:00" num número.
     *
     * Não leva linha de exemplo de propósito: um exemplo esquecido na planilha
     * viraria uma conta.
     */
    public function modeloLote(string $setor): string
    {
        $colunas = $this->colunasLote($setor);

        return PlanilhaXlsx::gerar(
            'Contas temporárias',
            array_column($colunas, 'titulo'),
            [],
            array_values(array_map(fn ($c) => ['largura' => $c['largura'], 'texto' => $c['texto'] ?? false], $colunas)),
        );
    }

    /**
     * Lê a planilha enviada e confere cada linha **sem criar nada**: é a prévia
     * que a tela mostra antes da confirmação, com o motivo de cada recusa.
     *
     * @param  array<string, mixed>  $padroes  o que vale para a linha em branco: valido_de, horas, turnos
     * @return array<string, mixed>
     */
    public function previaLote(UploadedFile $arquivo, string $setor, array $padroes = []): array
    {
        $linhas = $this->lerPlanilha($arquivo, $setor);

        return $this->conferirLote($linhas, $setor, $padroes);
    }

    /**
     * Cria as contas do lote. As linhas chegam da prévia, mas são **conferidas
     * de novo** aqui — entre uma e outra alguém pode ter cadastrado o mesmo
     * e-mail, e o que vem do navegador não é confiável. A linha que não passa
     * é pulada e devolvida com o motivo; as outras são criadas.
     *
     * Cada conta ganha uma **senha gerada**, devolvida uma única vez (o portal
     * guarda só o hash) — é dela que sai a planilha de acesso que o responsável
     * baixa e distribui.
     *
     * @param  list<array<string, mixed>>  $linhas
     * @param  array<string, mixed>  $padroes
     * @return array{criadas: list<array<string, mixed>>, ignoradas: list<array<string, mixed>>}
     */
    public function criarLote(array $linhas, array $padroes, User $autor, string $setor): array
    {
        if (count($linhas) > self::MAX_LOTE) {
            throw ValidationException::withMessages([
                'linhas' => 'Um lote cria no máximo '.self::MAX_LOTE.' contas de uma vez.',
            ]);
        }

        $conferido = $this->conferirLote($linhas, $setor, $padroes);
        $criadas = [];
        $ignoradas = [];

        foreach ($conferido['linhas'] as $linha) {
            if ($linha['erros'] !== []) {
                $ignoradas[] = $linha;

                continue;
            }

            $senha = $this->senhaGerada();

            try {
                $conta = $this->criar([
                    'name' => $linha['name'],
                    'email' => $linha['email'],
                    'password' => $senha,
                    'cpf' => $linha['cpf'],
                    'curso' => $linha['curso'],
                    'valido_de' => $linha['valido_de'],
                    'horas' => $linha['horas'],
                    'turnos' => $linha['turnos'],
                ], $autor, $setor);
            } catch (Throwable $e) {
                $ignoradas[] = array_merge($linha, ['erros' => [$e instanceof ValidationException
                    ? collect($e->errors())->flatten()->first()
                    : 'Não foi possível criar esta conta.']]);

                continue;
            }

            $criadas[] = [
                'linha' => $linha['linha'],
                'nome' => $conta->user->name,
                'email' => $conta->user->email,
                'senha' => $senha,
                'cpf' => $conta->cpfFormatado(),
                'curso' => $conta->curso,
                'acesso' => $linha['acesso_label'],
            ];
        }

        return ['criadas' => $criadas, 'ignoradas' => $ignoradas];
    }

    /**
     * A planilha de acesso do lote criado: nome, e-mail e a senha de cada um.
     *
     * @param  list<array<string, mixed>>  $criadas
     */
    public function planilhaAcessos(array $criadas): string
    {
        return PlanilhaXlsx::gerar(
            'Acessos',
            ['Nome', 'E-mail', 'Senha', 'CPF', 'Curso', 'Acesso'],
            array_map(fn (array $c) => [$c['nome'], $c['email'], $c['senha'], $c['cpf'], $c['curso'], $c['acesso']], $criadas),
            [['largura' => 34], ['largura' => 32], ['largura' => 14, 'texto' => true], ['largura' => 16], ['largura' => 26], ['largura' => 50]],
        );
    }

    /**
     * Linhas da planilha → dados de conta, pelo título de cada coluna.
     *
     * @return list<array<string, mixed>>
     */
    private function lerPlanilha(UploadedFile $arquivo, string $setor): array
    {
        $extensao = strtolower($arquivo->getClientOriginalExtension());
        $linhas = LeitorPlanilha::ler($arquivo->getRealPath(), $extensao === 'xlsx' ? 'xlsx' : 'csv');

        if ($linhas === []) {
            throw ValidationException::withMessages(['arquivo' => 'A planilha está vazia.']);
        }

        $mapa = $this->mapearCabecalho($linhas[0]);

        if (! isset($mapa['name'], $mapa['email'])) {
            throw ValidationException::withMessages([
                'arquivo' => 'Não encontrei as colunas "Nome completo" e "E-mail" na primeira linha. Use o modelo da tela.',
            ]);
        }

        $dados = [];

        foreach (array_slice($linhas, 1, null, true) as $i => $valores) {
            $linha = ['linha' => $i + 1];

            foreach ($mapa as $campo => $coluna) {
                $linha[$campo] = trim((string) ($valores[$coluna] ?? ''));
            }

            $dados[] = $linha;
        }

        if (count($dados) > self::MAX_LOTE) {
            throw ValidationException::withMessages([
                'arquivo' => 'Um lote cria no máximo '.self::MAX_LOTE.' contas de uma vez — divida a planilha.',
            ]);
        }

        if ($dados === []) {
            throw ValidationException::withMessages(['arquivo' => 'A planilha só tem o cabeçalho.']);
        }

        return $dados;
    }

    /**
     * Que coluna é qual: pelo começo do título, sem acento e sem caixa. É o que
     * deixa a planilha sobreviver a uma coluna movida ou renomeada de leve.
     *
     * @param  list<string>  $cabecalho
     * @return array<string, int>
     */
    private function mapearCabecalho(array $cabecalho): array
    {
        $chaves = [
            'email' => ['e-mail', 'email', 'mail'],
            'cpf' => ['cpf'],
            'curso' => ['curso'],
            'turnos' => ['turno'],
            'valido_de' => ['inicio', 'comeca'],
            'horas' => ['horas', 'duracao'],
            'name' => ['nome'],
        ];

        $mapa = [];

        foreach ($cabecalho as $i => $titulo) {
            $t = Str::lower(Str::ascii(trim((string) $titulo)));

            foreach ($chaves as $campo => $prefixos) {
                if (isset($mapa[$campo])) {
                    continue;
                }

                foreach ($prefixos as $prefixo) {
                    if (str_starts_with($t, $prefixo)) {
                        $mapa[$campo] = $i;

                        continue 3;
                    }
                }
            }
        }

        return $mapa;
    }

    /**
     * Confere cada linha: os campos do formulário de uma conta, a janela de
     * acesso e as repetições **dentro do próprio lote** (o mesmo e-mail ou CPF
     * duas vezes vira duas contas brigando pelo mesmo login).
     *
     * @param  list<array<string, mixed>>  $linhas
     * @param  array<string, mixed>  $padroes
     * @return array{linhas: list<array<string, mixed>>, validas: int, invalidas: int}
     */
    private function conferirLote(array $linhas, string $setor, array $padroes): array
    {
        $turnosNoModelo = $setor === ContaTemporaria::SETOR_PRESENCIAL;
        $emails = [];
        $cpfs = [];
        $resultado = [];

        foreach (array_values($linhas) as $i => $bruta) {
            $erros = [];

            $linha = [
                'linha' => (int) ($bruta['linha'] ?? $i + 2),
                'name' => trim((string) ($bruta['name'] ?? '')),
                'email' => NormalizaEmail::semEspacos((string) ($bruta['email'] ?? '')),
                'cpf' => $this->cpfDaPlanilha((string) ($bruta['cpf'] ?? '')),
                'curso' => trim((string) ($bruta['curso'] ?? '')),
                'valido_de' => null,
                'horas' => null,
                'turnos' => [],
            ];

            // Início e horas: o da linha, senão o padrão da tela.
            $inicio = trim((string) ($bruta['valido_de'] ?? ''));
            try {
                $linha['valido_de'] = $inicio !== ''
                    ? $this->dataDaPlanilha($inicio)
                    : (($padroes['valido_de'] ?? null) ?: null);
            } catch (ValidationException) {
                $erros[] = 'Início do acesso fora do formato dd/mm/aaaa hh:mm.';
            }

            $horas = trim((string) ($bruta['horas'] ?? ''));
            if ($horas !== '' && (! ctype_digit($horas) || (int) $horas < 1 || (int) $horas > self::HORAS_MAX)) {
                $erros[] = 'Horas de acesso precisa ser um número inteiro entre 1 e '.self::HORAS_MAX.'.';
            } else {
                $linha['horas'] = $horas !== '' ? (int) $horas : (isset($padroes['horas']) && $padroes['horas'] !== '' ? (int) $padroes['horas'] : null);
            }

            if ($turnosNoModelo) {
                $brutos = $bruta['turnos'] ?? '';

                if (is_array($brutos)) {
                    $linha['turnos'] = array_values($brutos);
                } else {
                    try {
                        $linha['turnos'] = trim((string) $brutos) !== ''
                            ? $this->turnosDaPlanilha((string) $brutos)
                            : array_values((array) ($padroes['turnos'] ?? []));
                    } catch (ValidationException $e) {
                        $erros[] = collect($e->errors())->flatten()->first();
                    }
                }

                if ($linha['turnos'] === [] && $erros === []) {
                    $erros[] = 'Informe ao menos um turno (na planilha ou no padrão da tela).';
                }
            }

            $validacao = Validator::make($linha, [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255', 'unique:users,email'],
                'cpf' => ['required', 'string', 'size:11', new Cpf],
                'curso' => ['required', 'string', 'max:255'],
            ], [
                'email.unique' => 'Este e-mail já tem conta no portal.',
            ], [
                'name' => 'nome', 'email' => 'e-mail', 'cpf' => 'CPF', 'curso' => 'curso',
            ]);

            $erros = array_merge($erros, $validacao->errors()->all());

            if ($linha['email'] !== '' && isset($emails[$linha['email']])) {
                $erros[] = "E-mail repetido na planilha (linha {$emails[$linha['email']]}).";
            }
            if ($linha['cpf'] !== '' && isset($cpfs[$linha['cpf']])) {
                $erros[] = "CPF repetido na planilha (linha {$cpfs[$linha['cpf']]}).";
            }
            $emails[$linha['email']] ??= $linha['linha'];
            $cpfs[$linha['cpf']] ??= $linha['linha'];

            // A janela é a mesma regra do cadastro avulso: começa no futuro ou
            // agora, termina depois de começar, turnos sem sobreposição.
            $linha['acesso_label'] = '';
            if ($erros === []) {
                try {
                    $turnos = $this->turnos($linha);
                    [$de, $ate] = $this->janela($linha, $turnos);
                    $linha['acesso_label'] = $turnos !== []
                        ? collect($turnos)->map(fn ($t) => $t['inicio']->format('d/m H:i').'–'.$t['fim']->format('H:i'))->implode('; ')
                        : ($de ? $de->format('d/m/Y H:i') : 'agora').' até '.$ate->format('d/m/Y H:i');
                } catch (ValidationException $e) {
                    $erros[] = collect($e->errors())->flatten()->first();
                }
            }

            $linha['cpf_formatado'] = strlen($linha['cpf']) === 11
                ? preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $linha['cpf'])
                : $linha['cpf'];
            $linha['erros'] = array_values(array_unique($erros));
            $resultado[] = $linha;
        }

        $invalidas = count(array_filter($resultado, fn ($l) => $l['erros'] !== []));

        return [
            'linhas' => $resultado,
            'validas' => count($resultado) - $invalidas,
            'invalidas' => $invalidas,
        ];
    }

    /** O CPF como a planilha deixar: com máscara, sem, ou número que perdeu o zero da frente. */
    private function cpfDaPlanilha(string $valor): string
    {
        $digitos = preg_replace('/\D/', '', $valor) ?? '';

        return $digitos !== '' && strlen($digitos) < 11 ? str_pad($digitos, 11, '0', STR_PAD_LEFT) : $digitos;
    }

    /**
     * "04/10/2026 08:00", "2026-10-04 08:00" ou o número serial que o Excel
     * grava quando a célula virou data. Devolve "Y-m-d H:i".
     */
    private function dataDaPlanilha(string $valor): string
    {
        if (($serial = LeitorPlanilha::dataExcel($valor)) !== null) {
            return $serial;
        }

        foreach (['d/m/Y H:i', 'd/m/Y H:i:s', 'd/m/Y', 'Y-m-d H:i', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d'] as $formato) {
            $data = CarbonImmutable::createFromFormat('!'.$formato, $valor, config('app.timezone'));

            if ($data !== false && $data->format($formato) === $valor) {
                return $data->format('Y-m-d H:i');
            }
        }

        throw ValidationException::withMessages(['valido_de' => 'Data inválida.']);
    }

    /**
     * "04/10/2026 08:00-12:00; 05/10/2026 13:00-17:00". O fim pode trazer a
     * data quando o turno vira a noite ("04/10/2026 22:00-05/10/2026 02:00").
     *
     * @return list<array{inicio: string, fim: string}>
     */
    private function turnosDaPlanilha(string $valor): array
    {
        $turnos = [];

        foreach (preg_split('/[;\n]+/', $valor) ?: [] as $pedaco) {
            $pedaco = trim($pedaco);

            if ($pedaco === '') {
                continue;
            }

            if (! preg_match('#^(\d{1,2}/\d{1,2}/\d{4})\s+(\d{1,2}:\d{2})\s*(?:-|–|a|até)\s*(?:(\d{1,2}/\d{1,2}/\d{4})\s+)?(\d{1,2}:\d{2})$#u', $pedaco, $m)) {
                throw ValidationException::withMessages([
                    'turnos' => "Turno \"{$pedaco}\" fora do formato dd/mm/aaaa hh:mm-hh:mm.",
                ]);
            }

            $inicio = CarbonImmutable::createFromFormat('!d/m/Y H:i', "{$m[1]} {$m[2]}", config('app.timezone'));
            $fim = CarbonImmutable::createFromFormat('!d/m/Y H:i', (($m[3] ?? '') !== '' ? $m[3] : $m[1])." {$m[4]}", config('app.timezone'));

            if ($inicio === false || $fim === false) {
                throw ValidationException::withMessages(['turnos' => "Turno \"{$pedaco}\" com data inválida."]);
            }

            $turnos[] = ['inicio' => $inicio->format('Y-m-d H:i'), 'fim' => $fim->format('Y-m-d H:i')];
        }

        return $turnos;
    }

    /**
     * Senha de 10 caracteres sem os que se confundem lidos num papel (0/O,
     * 1/l/I), com letra e número garantidos.
     */
    private function senhaGerada(): string
    {
        $letras = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
        $numeros = '23456789';
        $todos = $letras.$numeros;

        $senha = [$letras[random_int(0, strlen($letras) - 1)], $numeros[random_int(0, strlen($numeros) - 1)]];

        while (count($senha) < 10) {
            $senha[] = $todos[random_int(0, strlen($todos) - 1)];
        }

        // Fisher–Yates com random_int: o shuffle() do PHP não é criptográfico.
        for ($i = count($senha) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$senha[$i], $senha[$j]] = [$senha[$j], $senha[$i]];
        }

        return implode('', $senha);
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
     * A pessoa marca presença no primeiro acesso do turno.
     *
     * Só dentro da janela: antes dela não há plantão a assumir, e depois a
     * conta já venceu. Marcar de novo não reabre nada — quem já foi decidido
     * continua decidido, e uma segunda marcação só reescreveria o horário.
     */
    public function marcarPresenca(ContaTemporaria $conta): ContaTemporaria
    {
        if (! $conta->emVigor()) {
            throw ValidationException::withMessages([
                'presenca' => $conta->agendada()
                    ? 'O seu acesso ainda não começou.'
                    : 'O prazo desta conta já venceu.',
            ]);
        }

        if ($conta->presenca_status !== null) {
            throw ValidationException::withMessages([
                'presenca' => 'A sua presença já foi registrada.',
            ]);
        }

        $conta->update([
            'presenca_status' => StatusPresenca::Pendente,
            'presenca_em' => now(),
        ]);

        return $conta->refresh();
    }

    /**
     * O admin do setor aprova ou rejeita a presença.
     *
     * Rejeitar exige **motivo escrito** e **desativa** a conta: a pessoa está
     * ali, na frente de alguém, e "não pode entrar" sem explicação não se
     * sustenta — nem para ela, nem para quem for perguntar depois.
     */
    public function decidirPresenca(
        ContaTemporaria $conta,
        bool $aprovar,
        ?string $motivo,
        User $admin,
    ): ContaTemporaria {
        if ($conta->presenca_status === null) {
            throw ValidationException::withMessages([
                'presenca' => 'Esta pessoa ainda não marcou presença.',
            ]);
        }

        if (! $aprovar && trim((string) $motivo) === '') {
            throw ValidationException::withMessages([
                'motivo' => 'Explique por que a presença foi rejeitada.',
            ]);
        }

        DB::transaction(function () use ($conta, $aprovar, $motivo, $admin) {
            $conta->update([
                'presenca_status' => $aprovar ? StatusPresenca::Aprovada : StatusPresenca::Rejeitada,
                'presenca_decidida_por' => $admin->id,
                'presenca_decidida_em' => now(),
                'presenca_motivo' => $aprovar ? null : trim((string) $motivo),
            ]);

            // Rejeitada, a conta sai do ar na hora; aprovada, volta se estava
            // fora (o caso de quem foi rejeitado por engano).
            $conta->user?->update(['is_active' => $aprovar]);
        });

        return $conta->refresh();
    }

    /**
     * O que a conta temporária precisa saber sobre a própria presença — é o que
     * a tela de espera mostra.
     *
     * @return array<string, mixed>|null null quando a pessoa não é conta temporária
     */
    public function presencaDe(User $user): ?array
    {
        $conta = $user->contaTemporaria;

        if ($conta === null) {
            return null;
        }

        return [
            'setor' => $conta->setor,
            'setor_label' => $conta->quemDecide()?->label(),
            'status' => $conta->presenca_status?->value,
            'status_label' => $conta->presenca_status?->label(),
            'motivo' => $conta->presenca_motivo,
            'precisa_marcar' => $conta->precisaMarcarPresenca(),
            'aprovada' => $conta->presencaAprovada(),
            'agendada' => $conta->agendada(),
            'vencida' => $conta->vencida(),
            'valido_de_label' => $conta->valido_de?->format('d/m/Y H:i'),
            'expira_em_label' => $conta->expira_em->format('d/m/Y H:i'),
            'turnos' => $conta->turnos->map(fn ($t) => [
                'inicio_label' => $t->inicio->format('d/m/Y H:i'),
                'fim_label' => $t->fim->format('d/m/Y H:i'),
                'agora' => $t->agora(),
            ])->all(),
        ];
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

        // Voluntário com escala: dentro do prazo, mas fora do turno.
        if (! $conta->dentroDeTurno()) {
            $proximo = $conta->proximoTurno();

            return $proximo === null
                ? 'Você não tem turno de trabalho em aberto agora.'
                : 'Seu próximo turno começa em '.$proximo->inicio->format('d/m/Y \à\s H:i').'.';
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
     * Com **turnos** informados, a janela é o envelope deles: o primeiro
     * início e o último fim. É o que mantém a varredura de vencidas e a lista
     * funcionando sem saber de turno nenhum.
     *
     * @param  array<string, mixed>  $dados
     * @param  list<array{inicio: CarbonImmutable, fim: CarbonImmutable}>  $turnos
     * @return array{0: ?CarbonImmutable, 1: CarbonImmutable}
     */
    private function janela(array $dados, array $turnos = []): array
    {
        $agora = CarbonImmutable::now(config('app.timezone'));

        if ($turnos !== []) {
            $inicio = collect($turnos)->min('inicio');
            $fim = collect($turnos)->max('fim');

            return [$inicio->isPast() ? null : $inicio, $fim];
        }

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

    /**
     * Normaliza e valida os turnos do formulário, em ordem.
     *
     * @param  array<string, mixed>  $dados
     * @return list<array{inicio: CarbonImmutable, fim: CarbonImmutable}>
     */
    private function turnos(array $dados): array
    {
        $brutos = array_values(array_filter((array) ($dados['turnos'] ?? [])));

        if ($brutos === []) {
            return [];
        }

        $turnos = [];

        foreach ($brutos as $i => $turno) {
            $inicio = CarbonImmutable::parse($turno['inicio'], config('app.timezone'));
            $fim = CarbonImmutable::parse($turno['fim'], config('app.timezone'));

            if ($fim->lessThanOrEqualTo($inicio)) {
                throw ValidationException::withMessages([
                    "turnos.{$i}.fim" => 'O fim do turno precisa ser depois do início.',
                ]);
            }

            $turnos[] = ['inicio' => $inicio, 'fim' => $fim];
        }

        usort($turnos, fn (array $a, array $b) => $a['inicio'] <=> $b['inicio']);

        // Turnos sobrepostos quase sempre são erro de digitação — e, somados,
        // não significam nada diferente de um turno só.
        foreach ($turnos as $i => $turno) {
            if ($i > 0 && $turno['inicio']->lessThan($turnos[$i - 1]['fim'])) {
                throw ValidationException::withMessages([
                    'turnos' => 'Há turnos sobrepostos — confira os horários.',
                ]);
            }
        }

        if (collect($turnos)->max('fim')->isPast()) {
            throw ValidationException::withMessages([
                'turnos' => 'O último turno precisa terminar no futuro.',
            ]);
        }

        return $turnos;
    }

    /** @param  list<array{inicio: CarbonImmutable, fim: CarbonImmutable}>  $turnos */
    private function gravarTurnos(ContaTemporaria $conta, array $turnos): void
    {
        foreach ($turnos as $turno) {
            $conta->turnos()->create(['inicio' => $turno['inicio'], 'fim' => $turno['fim']]);
        }
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
            'setor' => $conta->setor,
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
            'turnos' => $conta->turnos->map(fn ($t) => [
                'id' => $t->id,
                'inicio' => $t->inicio->toIso8601String(),
                'fim' => $t->fim->toIso8601String(),
                'inicio_label' => $t->inicio->format('d/m/Y H:i'),
                'fim_label' => $t->fim->format('d/m/Y H:i'),
                'agora' => $t->agora(),
            ])->all(),
            // Com escala, estar no prazo não basta: tem de haver turno aberto.
            'em_turno' => $conta->dentroDeTurno(),
            'presenca' => $conta->presenca_status?->value,
            'presenca_label' => $conta->presenca_status?->label(),
            'presenca_em_label' => $conta->presenca_em?->format('d/m/Y H:i'),
            'presenca_motivo' => $conta->presenca_motivo,
            'presenca_decidida_por' => $conta->decisor?->name,
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
