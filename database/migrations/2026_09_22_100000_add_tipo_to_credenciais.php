<?php

use App\Enums\TipoCredencial;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * *Credenciais* vira **Credenciais e Prêmios**.
 *
 * A tela nasceu para as vagas de premiação (indicação, bolsa, convite de
 * parceiro), mas a cerimônia entrega também o que não é vaga nenhuma — o
 * destaque, a menção honrosa. Cadastrar isso noutro lugar significaria
 * conferir duas listas no palco.
 *
 * A ficha é a mesma; o que muda é `tipo`. Ele separa o que tem **objeto
 * físico a separar** no balcão do cerimonial (credencial) do que só se
 * anuncia (prêmio) — as duas coisas fazem o projeto ser **premiado**, mas só
 * a primeira entra no card de credenciais a separar.
 *
 * O que já existe é credencial, que era o único tipo que havia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credenciais', function (Blueprint $table) {
            $table->string('tipo', 20)->default(TipoCredencial::Credencial->value)->after('edicao_id');
        });
    }

    public function down(): void
    {
        Schema::table('credenciais', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
    }
};
