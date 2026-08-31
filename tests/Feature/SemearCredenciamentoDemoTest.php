<?php

namespace Tests\Feature;

use App\Models\Aluno;
use App\Models\DocumentoCredenciamento;
use App\Models\Edicao;
use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Models\User;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sprint 88 — `php artisan demo:credenciamento` monta os dados de ensaio do
 * balcão: projetos de mentira reunidos numa lista final **demo**, que só o modo
 * de teste enxerga.
 */
class SemearCredenciamentoDemoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CatalogoSeeder::class);
    }

    public function test_monta_a_lista_demo_com_projetos_e_equipe(): void
    {
        $this->artisan('demo:credenciamento')->assertSuccessful();

        $lista = ListaFinal::vigente(Edicao::padrao(), true);

        $this->assertNotNull($lista);
        $this->assertTrue($lista->demo);
        $this->assertSame(3, $lista->projetos()->count());

        $projeto = $lista->projetos()->first();
        $this->assertGreaterThan(0, Aluno::where('projeto_id', $projeto->id)->count());
        $this->assertNotNull($projeto->coorientador);
        // A série de cada aluno vem preenchida: é o que a ficha do balcão mostra.
        $this->assertNotNull(Aluno::where('projeto_id', $projeto->id)->value('ano_escolar'));
    }

    /** A lista demo não é a oficial — o balcão sem modo de teste segue vazio. */
    public function test_nao_publica_lista_oficial(): void
    {
        $this->artisan('demo:credenciamento')->assertSuccessful();

        $this->assertNull(ListaFinal::vigente(Edicao::padrao()));
    }

    /** Os projetos são de orientador demo, então ficam fora dos números da feira. */
    public function test_projetos_ficam_fora_dos_numeros(): void
    {
        $this->artisan('demo:credenciamento')->assertSuccessful();

        $this->assertSame(0, Projeto::semDemo()->count());
        $this->assertTrue(
            User::where('email', 'orientador.credenciamento@fetecms.test')->value('is_demo'),
        );
    }

    public function test_rodar_duas_vezes_nao_duplica(): void
    {
        $this->artisan('demo:credenciamento')->assertSuccessful();
        $this->artisan('demo:credenciamento')->assertSuccessful();

        $this->assertSame(1, ListaFinal::where('demo', true)->count());
        $this->assertSame(3, ListaFinal::vigente(Edicao::padrao(), true)->projetos()->count());
        $this->assertSame(6, Aluno::count());
    }

    /** O catálogo de documentos é global: só é semeado quando está vazio. */
    public function test_semeia_documentos_apenas_quando_o_catalogo_esta_vazio(): void
    {
        $this->artisan('demo:credenciamento')->assertSuccessful();
        $criados = DocumentoCredenciamento::count();
        $this->assertGreaterThan(0, $criados);

        $this->artisan('demo:credenciamento')->assertSuccessful();
        $this->assertSame($criados, DocumentoCredenciamento::count());
    }
}
