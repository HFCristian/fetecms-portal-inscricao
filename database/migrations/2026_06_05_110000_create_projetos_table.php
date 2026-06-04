<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projetos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // orientador dono
            $table->foreignId('edicao_id')->nullable()->constrained('edicoes')->nullOnDelete();

            $table->string('titulo')->nullable();
            $table->string('categoria')->nullable(); // App\Enums\Categoria (define limite de alunos)
            $table->foreignId('instituicao_id')->nullable()->constrained('instituicoes')->nullOnDelete();
            $table->foreignId('area_id')->nullable()->constrained('areas')->nullOnDelete();
            $table->foreignId('subarea_id')->nullable()->constrained('subareas')->nullOnDelete();

            $table->text('resumo')->nullable();
            $table->string('link_video')->nullable();
            $table->json('palavras_chave')->nullable(); // 3 a 5 termos

            // Localização do projeto
            $table->string('pais')->default('BR');
            $table->foreignId('estado_id')->nullable()->constrained('estados')->nullOnDelete();
            $table->foreignId('cidade_id')->nullable()->constrained('cidades')->nullOnDelete();

            // Informações adicionais (cadastro4 §4)
            $table->boolean('continuacao')->default(false);
            $table->unsignedSmallInteger('tempo_pesquisa_meses')->nullable();
            $table->boolean('feira_afiliada')->default(false);
            $table->string('numero_credencial')->nullable();
            $table->boolean('agenda_2030')->default(false);
            $table->string('categoria_agenda_2030')->nullable();
            $table->string('email_comunicacao')->nullable();

            $table->string('status')->default('rascunho')->index(); // App\Enums\ProjetoStatus
            $table->timestamp('submitted_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projetos');
    }
};
