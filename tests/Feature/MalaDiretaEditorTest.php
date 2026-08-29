<?php

namespace Tests\Feature;

use App\Enums\PublicoMala;
use App\Mail\MalaDiretaMensagem;
use App\Models\MalaDireta;
use App\Models\MalaDiretaArquivo;
use App\Models\User;
use App\Support\HtmlEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Editor de texto rico da mala direta: formatação, imagens no corpo e anexos.
 */
class MalaDiretaEditorTest extends TestCase
{
    use RefreshDatabase;

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

    private function mensagem(array $over = []): array
    {
        return array_merge([
            'nome' => 'Comunicado ilustrado',
            'justificativa' => 'Divulgação da programação.',
            'assunto' => 'Programação da feira',
            'corpo' => '<p>Olá, <strong>{{nome}}</strong>!</p>',
            'formato' => 'html',
            'publicos' => [PublicoMala::Todos->value],
        ], $over);
    }

    public function test_admin_sobe_imagem_e_anexo_e_recebe_a_url_da_previa(): void
    {
        Storage::fake('local');
        $this->admin();

        $imagem = $this->subir('imagem', UploadedFile::fake()->create('cartaz.png', 300, 'image/png'));
        $this->assertSame('imagem', $imagem['tipo']);
        $this->assertSame('cartaz.png', $imagem['nome']);
        $this->assertSame('/api/v1/admin/mala-direta/arquivos/'.$imagem['id'], $imagem['url']);

        $anexo = $this->subir('anexo', UploadedFile::fake()->create('edital.pdf', 200, 'application/pdf'));
        $this->assertSame('anexo', $anexo['tipo']);

        // O arquivo nasce solto: só o disparo o vincula a uma mala.
        $this->assertDatabaseHas('mala_direta_arquivos', ['id' => $imagem['id'], 'mala_direta_id' => null]);

        // E é servido pela rota autenticada do painel, nunca por link público.
        $this->get($imagem['url'])->assertOk();
    }

    public function test_imagem_grande_demais_e_recusada(): void
    {
        Storage::fake('local');
        $this->admin();

        $this->postJson('/api/v1/admin/mala-direta/arquivos', [
            'tipo' => 'imagem',
            'arquivo' => UploadedFile::fake()->create('enorme.png', MalaDiretaArquivo::MAX_IMAGEM_KB + 1, 'image/png'),
        ])->assertStatus(422)->assertJsonValidationErrors('arquivo');
    }

    public function test_anexo_aceita_ate_20mb(): void
    {
        Storage::fake('local');
        $this->admin();

        $this->postJson('/api/v1/admin/mala-direta/arquivos', [
            'tipo' => 'anexo',
            'arquivo' => UploadedFile::fake()->create('grande.pdf', MalaDiretaArquivo::MAX_ANEXO_KB, 'application/pdf'),
        ])->assertCreated();

        $this->postJson('/api/v1/admin/mala-direta/arquivos', [
            'tipo' => 'anexo',
            'arquivo' => UploadedFile::fake()->create('maior.pdf', MalaDiretaArquivo::MAX_ANEXO_KB + 1, 'application/pdf'),
        ])->assertStatus(422);
    }

    public function test_disparo_html_sanitiza_o_corpo_e_vincula_os_arquivos(): void
    {
        Storage::fake('local');
        Mail::fake();
        User::factory()->create(['name' => 'Ana Souza']);
        $this->admin();

        $imagem = $this->subir('imagem', UploadedFile::fake()->create('cartaz.png', 300, 'image/png'));
        $anexo = $this->subir('anexo', UploadedFile::fake()->create('edital.pdf', 100, 'application/pdf'));

        $resposta = $this->postJson('/api/v1/admin/mala-direta', $this->mensagem([
            'corpo' => '<p>Olá, <strong>{{nome}}</strong>!</p>'
                .'<script>alert(1)</script>'
                .'<p><img src="'.$imagem['url'].'" data-arquivo-id="'.$imagem['id'].'"></p>',
            'imagens' => [$imagem['id']],
            'anexos' => [$anexo['id']],
        ]))->assertCreated();

        $malaId = $resposta->json('data.id');

        $this->assertDatabaseHas('malas_diretas', ['id' => $malaId, 'formato' => 'html']);
        $this->assertDatabaseHas('mala_direta_arquivos', ['id' => $imagem['id'], 'mala_direta_id' => $malaId]);
        $this->assertDatabaseHas('mala_direta_arquivos', ['id' => $anexo['id'], 'mala_direta_id' => $malaId]);

        $corpo = MalaDireta::find($malaId)->corpo;
        $this->assertStringNotContainsString('<script>', $corpo);
        $this->assertStringNotContainsString('alert(1)', $corpo);
        $this->assertStringContainsString('<strong>{{nome}}</strong>', $corpo);
        $this->assertStringContainsString('data-arquivo-id="'.$imagem['id'].'"', $corpo);

        // A variável continua sendo trocada, agora dentro do HTML.
        Mail::assertSent(
            MalaDiretaMensagem::class,
            fn (MalaDiretaMensagem $mail) => str_contains($mail->corpo, '<strong>Ana</strong>')
                && $mail->mala->ehHtml(),
        );
    }

    public function test_versao_texto_do_email_vem_sem_as_tags(): void
    {
        $this->assertSame(
            "Olá!\n\n- um\n- dois",
            HtmlEmail::paraTexto('<p>Olá!</p><ul><li>um</li><li>dois</li></ul>'),
        );
    }

    public function test_arquivo_ja_disparado_nao_pode_ser_apagado(): void
    {
        Storage::fake('local');
        Mail::fake();
        User::factory()->create();
        $this->admin();

        $imagem = $this->subir('imagem', UploadedFile::fake()->create('cartaz.png', 300, 'image/png'));

        // Solto, pode.
        $outro = $this->subir('imagem', UploadedFile::fake()->create('outro.png', 100, 'image/png'));
        $this->deleteJson('/api/v1/admin/mala-direta/arquivos/'.$outro['id'])->assertOk();

        $this->postJson('/api/v1/admin/mala-direta', $this->mensagem([
            'corpo' => '<p>Oi <img src="'.$imagem['url'].'" data-arquivo-id="'.$imagem['id'].'"></p>',
            'imagens' => [$imagem['id']],
        ]))->assertCreated();

        $this->deleteJson('/api/v1/admin/mala-direta/arquivos/'.$imagem['id'])->assertStatus(422);
    }

    public function test_nao_admin_nao_sobe_arquivo(): void
    {
        Storage::fake('local');
        Sanctum::actingAs(User::factory()->create());

        $this->postJson('/api/v1/admin/mala-direta/arquivos', [
            'tipo' => 'imagem',
            'arquivo' => UploadedFile::fake()->create('x.png', 10, 'image/png'),
        ])->assertForbidden();
    }

    public function test_email_embute_a_imagem_no_corpo_e_leva_o_anexo(): void
    {
        Storage::fake('local');
        Mail::fake();
        User::factory()->create();
        $this->admin();

        $imagem = $this->subir('imagem', UploadedFile::fake()->create('cartaz.png', 300, 'image/png'));
        $anexo = $this->subir('anexo', UploadedFile::fake()->create('edital.pdf', 100, 'application/pdf'));

        $this->postJson('/api/v1/admin/mala-direta', $this->mensagem([
            'corpo' => '<p>Veja:</p><p><img src="'.$imagem['url'].'" data-arquivo-id="'.$imagem['id'].'"></p>',
            'imagens' => [$imagem['id']],
            'anexos' => [$anexo['id']],
        ]))->assertCreated();

        $mala = MalaDireta::firstOrFail();
        $mensagem = new MalaDiretaMensagem($mala, $mala->corpo);

        // O anexo vai junto do e-mail.
        $this->assertCount(1, $mensagem->attachments());

        // E a imagem do corpo troca o src pelo CID embutido — link para o
        // portal não abriria na caixa de entrada.
        $corpo = $mensagem->corpoComImagens(new Message(new Email));
        $this->assertStringContainsString('src="cid:', $corpo);
        $this->assertStringNotContainsString('/api/v1/admin/mala-direta/arquivos/', $corpo);
    }
}
