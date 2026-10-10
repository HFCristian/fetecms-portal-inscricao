<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Sprint 181 — **uma lista só** de itens para o credenciamento e a checagem.
 *
 * Até aqui eram dois catálogos: `documentos_credenciamento` (conferido por
 * pessoa, um item por papel) e `itens_checagem_estande` (conferido por
 * estande). A organização cadastrava a mesma coisa duas vezes e não tinha onde
 * dizer "este termo eu quero receber antes, pelo portal". Agora cada item diz:
 *
 * - **de quem** é: de cada pessoa (com os papéis — aluno, orientador,
 *   coorientador) ou do projeto;
 * - **onde** é conferido: no credenciamento, na checagem ou nos dois;
 * - se a organização quer **recebê-lo antes**, enviado pelo orientador na aba
 *   Documentos.
 *
 * A migração preserva o que já foi conferido:
 * - os itens da checagem entram com o **mesmo id** — as conferências dos
 *   estandes e o checklist do avaliador presencial (que guarda o id) seguem
 *   valendo sem remapear nada;
 * - os documentos do credenciamento com o mesmo nome em papéis diferentes viram
 *   **um** item com os papéis somados; nome igual ao de um item da checagem
 *   funde com ele, marcado nos dois lugares;
 * - as marcações do balcão são remapeadas para o item novo;
 * - os dois termos do finalista viram **itens do sistema** (`chave`): o de
 *   responsabilidade (do projeto, na checagem) e o de uso de imagem (do
 *   estudante, no credenciamento), os dois com "receber antes".
 */
return new class extends Migration
{
    private const IMAGEM = 'termo_uso_imagem';

    private const RESPONSABILIDADE = 'termo_responsabilidade';

    public function up(): void
    {
        Schema::create('itens_conferencia', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 120)->unique();
            $table->string('descricao')->nullable();
            // pessoa: conferido em cada pessoa dos papéis; projeto: uma vez por projeto.
            $table->string('abrangencia', 10)->default('pessoa');
            $table->json('papeis')->nullable();
            $table->boolean('no_credenciamento')->default(false);
            $table->boolean('na_checagem')->default(false);
            $table->boolean('receber_antes')->default(false);
            $table->string('chave', 40)->nullable()->unique();
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });

        $agora = now();
        $normalizar = fn (string $nome) => Str::lower(Str::squish(Str::ascii($nome)));
        $porNome = [];

        // 1. Itens da checagem, com o mesmo id.
        foreach (DB::table('itens_checagem_estande')->orderBy('id')->get() as $item) {
            DB::table('itens_conferencia')->insert([
                'id' => $item->id,
                'nome' => $item->nome,
                'descricao' => $item->descricao,
                'abrangencia' => 'projeto',
                'papeis' => null,
                'no_credenciamento' => false,
                'na_checagem' => true,
                'receber_antes' => false,
                'chave' => null,
                'ordem' => $item->ordem,
                'ativo' => $item->ativo,
                'created_at' => $item->created_at ?? $agora,
                'updated_at' => $agora,
            ]);
            $porNome[$normalizar($item->nome)] = $item->id;
        }

        $this->acertarSequencia();

        // 2. Documentos do credenciamento, agrupados pelo nome.
        $mapa = [];
        $documentos = DB::table('documentos_credenciamento')->orderBy('ordem')->orderBy('id')->get();

        foreach ($documentos->groupBy(fn ($d) => $normalizar($d->nome)) as $nome => $grupo) {
            $papeis = $grupo->pluck('tipo_pessoa')->unique()->values()->all();
            $chave = $grupo->pluck('chave')->filter()->first();
            $ativo = $grupo->contains(fn ($d) => (bool) $d->ativo);

            if (isset($porNome[$nome])) {
                // Mesmo nome que um item da checagem: um item só, nos dois lugares.
                $id = $porNome[$nome];
                DB::table('itens_conferencia')->where('id', $id)->update([
                    'abrangencia' => 'pessoa',
                    'papeis' => json_encode($papeis),
                    'no_credenciamento' => true,
                    'chave' => $chave,
                    'ativo' => $ativo || (bool) DB::table('itens_conferencia')->where('id', $id)->value('ativo'),
                ]);
            } else {
                $id = DB::table('itens_conferencia')->insertGetId([
                    'nome' => $grupo->first()->nome,
                    'descricao' => null,
                    'abrangencia' => 'pessoa',
                    'papeis' => json_encode($papeis),
                    'no_credenciamento' => true,
                    'na_checagem' => false,
                    'receber_antes' => false,
                    'chave' => $chave,
                    'ordem' => (int) $grupo->min('ordem'),
                    'ativo' => $ativo,
                    'created_at' => $agora,
                    'updated_at' => $agora,
                ]);
                $porNome[$nome] = $id;
            }

            foreach ($grupo as $documento) {
                $mapa[$documento->id] = $id;
            }
        }

        // 3. Os dois termos do finalista são itens do sistema, recebidos antes.
        $imagem = DB::table('itens_conferencia')->where('chave', self::IMAGEM)->value('id');
        if ($imagem === null) {
            $imagem = DB::table('itens_conferencia')->insertGetId([
                'nome' => $this->nomeLivre('Termo de uso de imagem', $porNome, $normalizar),
                'abrangencia' => 'pessoa', 'papeis' => json_encode(['aluno']),
                'no_credenciamento' => true, 'na_checagem' => false, 'receber_antes' => true,
                'chave' => self::IMAGEM, 'ordem' => 90, 'ativo' => true,
                'created_at' => $agora, 'updated_at' => $agora,
            ]);
        }
        DB::table('itens_conferencia')->where('id', $imagem)->update(['receber_antes' => true]);

        $responsabilidade = $porNome[$normalizar('Termo de responsabilidade')] ?? null;
        if ($responsabilidade !== null && DB::table('itens_conferencia')->where('id', $responsabilidade)->value('abrangencia') === 'projeto') {
            DB::table('itens_conferencia')->where('id', $responsabilidade)->update([
                'chave' => self::RESPONSABILIDADE, 'na_checagem' => true, 'receber_antes' => true,
            ]);
        } else {
            DB::table('itens_conferencia')->insert([
                'nome' => $this->nomeLivre('Termo de responsabilidade', $porNome, $normalizar),
                'abrangencia' => 'projeto', 'papeis' => null,
                'no_credenciamento' => false, 'na_checagem' => true, 'receber_antes' => true,
                'chave' => self::RESPONSABILIDADE, 'ordem' => 0, 'ativo' => true,
                'created_at' => $agora, 'updated_at' => $agora,
            ]);
        }

        // 4. As marcações do balcão passam a apontar para o item da lista nova.
        Schema::table('credenciamento_documentos', function (Blueprint $table) {
            $table->foreignId('item_id')->nullable()->after('credenciamento_id')
                ->constrained('itens_conferencia')->cascadeOnDelete();
        });

        foreach ($mapa as $antigo => $novo) {
            DB::table('credenciamento_documentos')->where('documento_credenciamento_id', $antigo)->update(['item_id' => $novo]);
        }

        Schema::table('credenciamento_documentos', function (Blueprint $table) {
            $table->dropUnique('credenciamento_documento_pessoa_unico');
            $table->dropForeign(['documento_credenciamento_id']);
            $table->dropColumn('documento_credenciamento_id');
            $table->unique(['credenciamento_id', 'item_id', 'pessoa_tipo', 'pessoa_id'], 'credenciamento_item_pessoa_unico');
        });

        // 5. A checagem passa a conferir também por pessoa.
        Schema::table('checagem_estande_itens', function (Blueprint $table) {
            $table->dropUnique(['checagem_id', 'item_id']);
            $table->dropForeign(['item_id']);
        });

        Schema::table('checagem_estande_itens', function (Blueprint $table) {
            $table->foreign('item_id')->references('id')->on('itens_conferencia')->nullOnDelete();
            $table->string('pessoa_tipo', 20)->default('projeto')->after('item_id');
            $table->unsignedBigInteger('pessoa_id')->nullable()->after('pessoa_tipo');
        });

        // O item do projeto é marcado com o próprio projeto como "pessoa".
        foreach (DB::table('checagens_estande')->get(['id', 'projeto_id']) as $checagem) {
            DB::table('checagem_estande_itens')->where('checagem_id', $checagem->id)->update(['pessoa_id' => $checagem->projeto_id]);
        }

        Schema::table('checagem_estande_itens', function (Blueprint $table) {
            $table->unique(['checagem_id', 'item_id', 'pessoa_tipo', 'pessoa_id'], 'checagem_item_pessoa_unico');
        });

        Schema::dropIfExists('documentos_credenciamento');
        Schema::dropIfExists('itens_checagem_estande');
    }

    public function down(): void
    {
        Schema::create('documentos_credenciamento', function (Blueprint $table) {
            $table->id();
            $table->string('tipo_pessoa');
            $table->string('nome');
            $table->string('chave', 40)->nullable()->unique();
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->unique(['tipo_pessoa', 'nome']);
        });

        Schema::create('itens_checagem_estande', function (Blueprint $table) {
            $table->id();
            $table->string('nome')->unique();
            $table->string('descricao')->nullable();
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->boolean('ativo')->default(true);
            $table->timestamps();
        });

        $agora = now();
        $porItemEPapel = [];

        foreach (DB::table('itens_conferencia')->orderBy('id')->get() as $item) {
            if ($item->na_checagem && $item->abrangencia === 'projeto') {
                DB::table('itens_checagem_estande')->insert([
                    'id' => $item->id, 'nome' => $item->nome, 'descricao' => $item->descricao,
                    'ordem' => $item->ordem, 'ativo' => $item->ativo, 'created_at' => $agora, 'updated_at' => $agora,
                ]);
            }

            if ($item->no_credenciamento && $item->abrangencia === 'pessoa') {
                foreach (json_decode((string) $item->papeis, true) ?: [] as $papel) {
                    $porItemEPapel[$item->id.':'.$papel] = DB::table('documentos_credenciamento')->insertGetId([
                        'tipo_pessoa' => $papel, 'nome' => $item->nome,
                        'chave' => $item->chave === self::IMAGEM && $papel === 'aluno' ? self::IMAGEM : null,
                        'ordem' => $item->ordem, 'ativo' => $item->ativo, 'created_at' => $agora, 'updated_at' => $agora,
                    ]);
                }
            }
        }

        Schema::table('credenciamento_documentos', function (Blueprint $table) {
            $table->unsignedBigInteger('documento_credenciamento_id')->nullable()->after('credenciamento_id');
        });

        foreach (DB::table('credenciamento_documentos')->get(['id', 'item_id', 'pessoa_tipo']) as $marca) {
            $antigo = $porItemEPapel[$marca->item_id.':'.$marca->pessoa_tipo] ?? null;
            $antigo === null
                ? DB::table('credenciamento_documentos')->where('id', $marca->id)->delete()
                : DB::table('credenciamento_documentos')->where('id', $marca->id)->update(['documento_credenciamento_id' => $antigo]);
        }

        Schema::table('credenciamento_documentos', function (Blueprint $table) {
            $table->dropUnique('credenciamento_item_pessoa_unico');
            $table->dropForeign(['item_id']);
            $table->dropColumn('item_id');
            $table->foreign('documento_credenciamento_id')->references('id')->on('documentos_credenciamento')->cascadeOnDelete();
            $table->unique(['credenciamento_id', 'documento_credenciamento_id', 'pessoa_tipo', 'pessoa_id'], 'credenciamento_documento_pessoa_unico');
        });

        DB::table('checagem_estande_itens')->where('pessoa_tipo', '!=', 'projeto')->delete();
        DB::table('checagem_estande_itens')
            ->whereNotIn('item_id', DB::table('itens_checagem_estande')->select('id'))
            ->update(['item_id' => null]);

        Schema::table('checagem_estande_itens', function (Blueprint $table) {
            $table->dropUnique('checagem_item_pessoa_unico');
            $table->dropForeign(['item_id']);
            $table->dropColumn(['pessoa_tipo', 'pessoa_id']);
        });

        Schema::table('checagem_estande_itens', function (Blueprint $table) {
            $table->foreign('item_id')->references('id')->on('itens_checagem_estande')->nullOnDelete();
            $table->unique(['checagem_id', 'item_id']);
        });

        Schema::dropIfExists('itens_conferencia');
    }

    /** Depois de inserir com id explícito, o Postgres precisa saber de onde seguir. */
    private function acertarSequencia(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("SELECT setval(pg_get_serial_sequence('itens_conferencia', 'id'), COALESCE((SELECT MAX(id) FROM itens_conferencia), 0) + 1, false)");
        }
    }

    /** O nome pedido, ou com um sufixo se ele já existir com outro sentido. */
    private function nomeLivre(string $nome, array $porNome, callable $normalizar): string
    {
        return isset($porNome[$normalizar($nome)]) ? $nome.' (enviado antes)' : $nome;
    }
};
