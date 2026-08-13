<?php

use App\Enums\ProjetoStatus;
use App\Enums\TipoRegistro;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Trilha de auditoria das submissões (painel do admin): quem submeteu,
     * cancelou ou excluiu, quando e de qual projeto — além das trocas de e-mail,
     * já que é o e-mail que identifica a pessoa nesta listagem.
     *
     * Os dados do autor e do projeto ficam DESNORMALIZADOS de propósito: o
     * registro precisa continuar legível mesmo que o projeto seja excluído ou
     * que a conta troque de e-mail depois.
     */
    public function up(): void
    {
        Schema::create('registros_atividade', function (Blueprint $table) {
            $table->id();
            $table->string('tipo');

            // Autor da ação (pode ser o próprio dono ou um admin agindo por ele).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('autor_email');
            $table->string('autor_nome')->nullable();
            $table->string('autor_role')->nullable();

            // Projeto envolvido (nulo em troca de e-mail).
            $table->foreignId('projeto_id')->nullable()->constrained('projetos')->nullOnDelete();
            $table->string('projeto_titulo')->nullable();
            $table->string('projeto_categoria')->nullable();
            $table->string('dono_email')->nullable();
            $table->string('dono_nome')->nullable();

            // Contexto extra do evento (ex.: troca de e-mail guarda "de" e "para").
            $table->json('detalhes')->nullable();
            $table->timestamps();

            $table->index(['tipo', 'created_at']);
            $table->index('created_at');
            $table->index('autor_email');
        });

        $this->backfillSubmissoes();
    }

    /**
     * Histórico: cada projeto já submetido vira um registro de submissão datado
     * do próprio `submitted_at`, para o painel não nascer vazio.
     */
    private function backfillSubmissoes(): void
    {
        $submetidos = [
            ProjetoStatus::Submetido->value,
            ProjetoStatus::Aprovado->value,
            ProjetoStatus::Rejeitado->value,
        ];

        DB::table('projetos')
            ->join('users', 'users.id', '=', 'projetos.user_id')
            ->whereIn('projetos.status', $submetidos)
            ->whereNotNull('projetos.submitted_at')
            ->whereNull('projetos.deleted_at')
            ->select([
                'projetos.id as projeto_id',
                'projetos.titulo as projeto_titulo',
                'projetos.categoria as projeto_categoria',
                'projetos.submitted_at',
                'users.id as user_id',
                'users.email',
                'users.name',
                'users.role',
            ])
            ->orderBy('projetos.id')
            ->chunk(500, function ($projetos) {
                $linhas = $projetos->map(fn ($p) => [
                    'tipo' => TipoRegistro::Submissao->value,
                    'user_id' => $p->user_id,
                    'autor_email' => $p->email,
                    'autor_nome' => $p->name,
                    'autor_role' => $p->role,
                    'projeto_id' => $p->projeto_id,
                    'projeto_titulo' => $p->projeto_titulo,
                    'projeto_categoria' => $p->projeto_categoria,
                    'dono_email' => $p->email,
                    'dono_nome' => $p->name,
                    'detalhes' => json_encode(['origem' => 'historico']),
                    'created_at' => $p->submitted_at,
                    'updated_at' => $p->submitted_at,
                ])->all();

                DB::table('registros_atividade')->insert($linhas);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('registros_atividade');
    }
};
