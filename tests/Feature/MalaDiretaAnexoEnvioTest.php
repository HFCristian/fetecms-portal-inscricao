<?php

namespace Tests\Feature;

use App\Enums\PublicoMala;
use App\Jobs\EnviarMalaDireta;
use App\Models\MalaDireta;
use App\Models\MalaDiretaArquivo;
use App\Models\MalaDiretaDestinatario;
use App\Models\User;
use App\Services\MalaDiretaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mime\Email;
use Tests\TestCase;
use Throwable;

/**
 * Sprint 114 — o anexo tem de chegar **no e-mail**, não só no `attachments()`.
 *
 * O teste que existia parava em `assertCount(1, $mensagem->attachments())`: ele
 * confirmava que o Mailable sabia listar o anexo, e não que a mensagem enviada
 * o carregava — a mesma armadilha da Sprint 102, em que o e-mail saía com as
 * tags à mostra e o teste passava porque olhava só o texto.
 *
 * Aqui o caminho é o de produção inteiro: sobe o arquivo pela rota, dispara a
 * mala pela rota, e abre o **MIME** que saiu no transporte.
 */
class MalaDiretaAnexoEnvioTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // O transporte `array` guarda a mensagem já construída — é o que
        // permite abrir o MIME de verdade em vez de confiar no Mailable.
        config(['mail.default' => 'array']);
    }

    private function admin(): User
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function subir(string $tipo, UploadedFile $arquivo): array
    {
        return $this->postJson('/api/v1/admin/mala-direta/arquivos', compact('tipo') + ['arquivo' => $arquivo])
            ->assertCreated()
            ->json('data');
    }

    /** O primeiro e-mail que saiu de verdade pelo transporte. */
    private function primeiroEmail(): Email
    {
        $enviados = collect(app('mailer')->getSymfonyTransport()->messages())->all();
        $this->assertNotEmpty($enviados, 'nenhum e-mail chegou ao transporte');

        return $enviados[0]->getOriginalMessage();
    }

    private function disparar(array $over = []): MalaDireta
    {
        $this->postJson('/api/v1/admin/mala-direta', array_merge([
            'nome' => 'Comunicado com edital',
            'justificativa' => 'Envio do edital aos orientadores.',
            'assunto' => 'Edital da feira',
            'corpo' => '<p>Olá, <strong>{{nome}}</strong>! Segue o edital.</p>',
            'formato' => 'html',
            'publicos' => [PublicoMala::Todos->value],
        ], $over))->assertCreated();

        return MalaDireta::latest('id')->firstOrFail();
    }

    public function test_o_anexo_chega_no_mime_do_email_enviado(): void
    {
        Storage::fake('local');
        User::factory()->create(['name' => 'Orientador Um', 'email' => 'um@exemplo.test']);
        $this->admin();

        $anexo = $this->subir('anexo', UploadedFile::fake()->createWithContent('edital.pdf', '%PDF-conteudo-real'));

        $mala = $this->disparar(['anexos' => [$anexo['id']]]);
        $this->assertSame(1, $mala->anexos()->count(), 'o anexo precisa ficar preso à mala no disparo');

        $email = $this->primeiroEmail();
        $anexos = $email->getAttachments();

        $this->assertSame(['edital.pdf'], array_map(fn ($a) => $a->getFilename(), $anexos));
        // E o conteúdo vai junto: um anexo de 0 byte chegaria como anexo mesmo
        // assim, e é indistinguível de anexo nenhum na caixa de entrada.
        $this->assertSame('%PDF-conteudo-real', $anexos[0]->getBody());
    }

    public function test_varios_anexos_chegam_com_os_nomes_originais(): void
    {
        Storage::fake('local');
        User::factory()->create(['email' => 'um@exemplo.test']);
        $this->admin();

        $pdf = $this->subir('anexo', UploadedFile::fake()->createWithContent('edital.pdf', '%PDF-x'));
        $csv = $this->subir('anexo', UploadedFile::fake()->createWithContent('inscritos.csv', 'email;nome'));

        $this->disparar(['anexos' => [$pdf['id'], $csv['id']]]);

        $nomes = array_map(fn ($a) => $a->getFilename(), $this->primeiroEmail()->getAttachments());
        sort($nomes);

        $this->assertSame(['edital.pdf', 'inscritos.csv'], $nomes);
    }

    public function test_imagem_do_corpo_viaja_embutida_e_nao_como_anexo(): void
    {
        Storage::fake('local');
        User::factory()->create(['email' => 'um@exemplo.test']);
        $this->admin();

        $imagem = $this->subir('imagem', UploadedFile::fake()->createWithContent('cartaz.png', 'PNG-bytes'));
        $anexo = $this->subir('anexo', UploadedFile::fake()->createWithContent('edital.pdf', '%PDF-x'));

        $this->disparar([
            'corpo' => '<p>Veja:</p><p><img src="'.$imagem['url'].'" data-arquivo-id="'.$imagem['id'].'"></p>',
            'imagens' => [$imagem['id']],
            'anexos' => [$anexo['id']],
        ]);

        $email = $this->primeiroEmail();

        $soltos = array_values(array_filter(
            $email->getAttachments(),
            fn ($a) => $a->getPreparedHeaders()->getHeaderBody('content-disposition') !== 'inline',
        ));

        $this->assertSame(['edital.pdf'], array_map(fn ($a) => $a->getFilename(), $soltos));
        $this->assertStringContainsString('cid:', $email->getHtmlBody());
    }

    /**
     * O caso que fez a mala direta chegar sem anexo em produção.
     *
     * O disco privado roda com `throw => false`: um arquivo que sumiu do
     * storage — deploy que não preservou `storage/app/private`, worker noutra
     * máquina — devolve vazio em silêncio. O e-mail saía com um anexo de 0
     * byte, o relatório dizia "enviado" e o destinatário recebia uma mensagem
     * que prometia o edital sem entregá-lo.
     */
    public function test_anexo_sumido_do_disco_vira_falha_no_relatorio_em_vez_de_email_vazio(): void
    {
        Storage::fake('local');
        User::factory()->create(['email' => 'um@exemplo.test']);
        $this->admin();

        // A fila não roda no disparo: é o intervalo entre enfileirar e o worker
        // pegar o job que a produção vive, e é nele que o arquivo some.
        Queue::fake();

        $anexo = $this->subir('anexo', UploadedFile::fake()->createWithContent('edital.pdf', 'conteudo'));
        $mala = $this->disparar(['anexos' => [$anexo['id']]]);

        Storage::disk('local')->delete($mala->anexos()->firstOrFail()->path);

        $destinatario = MalaDiretaDestinatario::where('mala_direta_id', $mala->id)->firstOrFail();
        $job = new EnviarMalaDireta($destinatario->id);

        try {
            $job->handle(app(MalaDiretaService::class));
            $this->fail('o envio deveria falhar quando o anexo não está mais no disco');
        } catch (Throwable $e) {
            $this->assertStringContainsString('não está no storage', $e->getMessage());
            $job->failed($e);
        }

        // Nada saiu: melhor não enviar do que enviar prometendo um anexo vazio.
        $this->assertEmpty(app('mailer')->getSymfonyTransport()->messages());

        // E o relatório DIZ o que houve — silêncio aqui é o que faz o admin
        // achar que o e-mail saiu certo.
        $destinatario->refresh();
        $this->assertSame('falha', $destinatario->status->value);
        $this->assertStringContainsString('edital.pdf', $destinatario->erro);
    }

    /**
     * A mesma falha, um passo antes: se o arquivo já sumiu quando o admin
     * clica em disparar, ele descobre agora — com uma mensagem só — em vez de
     * pelo relatório, uma falha por destinatário.
     */
    public function test_disparo_e_recusado_quando_o_anexo_nao_esta_mais_no_servidor(): void
    {
        Storage::fake('local');
        User::factory()->create(['email' => 'um@exemplo.test']);
        $this->admin();

        $anexo = $this->subir('anexo', UploadedFile::fake()->createWithContent('edital.pdf', 'conteudo'));

        // O arquivo some entre a redação e o disparo.
        Storage::disk('local')->delete(
            MalaDiretaArquivo::findOrFail($anexo['id'])->path,
        );

        $this->postJson('/api/v1/admin/mala-direta', [
            'nome' => 'Comunicado com edital',
            'justificativa' => 'Envio do edital aos orientadores.',
            'assunto' => 'Edital da feira',
            'corpo' => 'Segue o edital.',
            'publicos' => [PublicoMala::Todos->value],
            'anexos' => [$anexo['id']],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('anexos');

        $this->assertEmpty(app('mailer')->getSymfonyTransport()->messages());
        $this->assertSame(0, MalaDiretaDestinatario::count());
    }
}
