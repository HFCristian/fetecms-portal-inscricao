<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Sprint 175 — o **termo de uso de imagem** na ficha do credenciamento.
 *
 * O catálogo de documentos do balcão é texto livre (Parametrização →
 * Credenciamento), e o portal precisa reconhecer um item específico: o termo
 * de imagem do estudante, que já chega marcado quando o orientador o enviou
 * online. `chave` é esse identificador — nulo nos itens que a organização
 * cadastra, `termo_uso_imagem` no item do sistema.
 *
 * Se o catálogo já tem um item de estudante que fala de imagem ("Termo de
 * imagem", "Autorização de uso de imagem"), ele é **adotado** — o que já foi
 * conferido com ele continua valendo e o balcão não ganha um item duplicado.
 * Senão, o item nasce no fim da lista do estudante.
 */
return new class extends Migration
{
    private const CHAVE = 'termo_uso_imagem';

    public function up(): void
    {
        Schema::table('documentos_credenciamento', function (Blueprint $table) {
            $table->string('chave', 40)->nullable()->unique()->after('nome');
        });

        $existente = DB::table('documentos_credenciamento')
            ->where('tipo_pessoa', 'aluno')
            ->orderByDesc('ativo')
            ->orderBy('ordem')
            ->orderBy('id')
            ->get(['id', 'nome'])
            ->first(fn ($d) => Str::contains(Str::lower(Str::ascii((string) $d->nome)), 'imagem'));

        if ($existente !== null) {
            DB::table('documentos_credenciamento')->where('id', $existente->id)->update(['chave' => self::CHAVE]);

            return;
        }

        DB::table('documentos_credenciamento')->insert([
            'tipo_pessoa' => 'aluno',
            'nome' => 'Termo de uso de imagem',
            'chave' => self::CHAVE,
            'ordem' => (int) DB::table('documentos_credenciamento')->where('tipo_pessoa', 'aluno')->max('ordem') + 1,
            'ativo' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('documentos_credenciamento', function (Blueprint $table) {
            $table->dropUnique(['chave']);
            $table->dropColumn('chave');
        });
    }
};
