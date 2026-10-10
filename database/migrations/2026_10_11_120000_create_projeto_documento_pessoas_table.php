<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 183 — o arquivo enviado antes vale para **pessoas**, não só para
 * estudantes.
 *
 * O termo de uso de imagem (Sprint 174) dizia para quais estudantes valia
 * (`projeto_documento_alunos`). Agora qualquer item de pessoa da lista única
 * pode ser recebido antes — inclusive de orientador e coorientador —, então o
 * vínculo guarda o papel junto do id, como o balcão já faz
 * (`pessoa_tipo` + `pessoa_id`). Os vínculos que existiam viram `aluno`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projeto_documento_pessoas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('projeto_documento_id')->constrained('projeto_documentos')->cascadeOnDelete();
            $table->string('pessoa_tipo', 20);
            $table->unsignedBigInteger('pessoa_id');
            $table->timestamps();

            $table->unique(['projeto_documento_id', 'pessoa_tipo', 'pessoa_id'], 'projeto_documento_pessoa_unico');
        });

        foreach (DB::table('projeto_documento_alunos')->get() as $vinculo) {
            DB::table('projeto_documento_pessoas')->insert([
                'projeto_documento_id' => $vinculo->projeto_documento_id,
                'pessoa_tipo' => 'aluno',
                'pessoa_id' => $vinculo->aluno_id,
                'created_at' => $vinculo->created_at,
                'updated_at' => $vinculo->updated_at,
            ]);
        }

        Schema::dropIfExists('projeto_documento_alunos');
    }

    public function down(): void
    {
        Schema::create('projeto_documento_alunos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('projeto_documento_id')->constrained('projeto_documentos')->cascadeOnDelete();
            $table->foreignId('aluno_id')->constrained('alunos')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['projeto_documento_id', 'aluno_id']);
        });

        $alunos = DB::table('alunos')->pluck('id')->all();

        foreach (DB::table('projeto_documento_pessoas')->where('pessoa_tipo', 'aluno')->get() as $vinculo) {
            if (in_array($vinculo->pessoa_id, $alunos)) {
                DB::table('projeto_documento_alunos')->insert([
                    'projeto_documento_id' => $vinculo->projeto_documento_id,
                    'aluno_id' => $vinculo->pessoa_id,
                    'created_at' => $vinculo->created_at,
                    'updated_at' => $vinculo->updated_at,
                ]);
            }
        }

        Schema::dropIfExists('projeto_documento_pessoas');
    }
};
