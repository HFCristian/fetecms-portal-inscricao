<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cadastro manual de projeto pelo admin (Sprint 157).
 *
 * Projetos que vão à fase presencial sem ter passado pela inscrição — vagas de
 * feira afiliada, finalistas que chegaram só com o nome — entram por uma tela do
 * painel. `projetos.cadastro_manual` os marca: eles **não** entram na avaliação
 * online (distribuição, fila do avaliador, Projetos submetidos), que já acabou
 * para eles.
 *
 * A organização recebe essas equipes **só com o nome**, então o CPF de alunos,
 * coorientador e orientador e o e-mail de alunos e coorientador passam a ser
 * opcionais **no banco**. Os formulários da inscrição continuam exigindo tudo:
 * quem decide o que é obrigatório é o FormRequest de cada caminho, não a coluna.
 * O telefone e a data de nascimento do orientador seguem o mesmo motivo — a
 * conta dele nasce do cadastro manual, e ele completa o perfil quando entrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projetos', function (Blueprint $table) {
            $table->boolean('cadastro_manual')->default(false)->after('status');
        });

        Schema::table('alunos', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('cpf', 11)->nullable()->change();
        });

        Schema::table('coorientadores', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('cpf', 11)->nullable()->change();
        });

        Schema::table('orientador_profiles', function (Blueprint $table) {
            $table->string('cpf', 11)->nullable()->change();
            $table->string('telefone', 20)->nullable()->change();
            $table->date('data_nascimento')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('projetos', function (Blueprint $table) {
            $table->dropColumn('cadastro_manual');
        });

        // As colunas continuam anuláveis: voltar a NOT NULL quebraria qualquer
        // projeto manual já cadastrado. Desfazer de verdade é apagar esses
        // projetos antes.
    }
};
