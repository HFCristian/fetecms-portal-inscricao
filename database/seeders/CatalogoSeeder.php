<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Cidade;
use App\Models\Edicao;
use App\Models\Estado;
use App\Models\Instituicao;
use Illuminate\Database\Seeder;

/**
 * Dados de catálogo. Áreas/subáreas seguem o padrão CNPq; escolas e cidades
 * são um conjunto representativo de MS (substituível por lista/importação real).
 */
class CatalogoSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedEdicao();
        $this->seedEstados();
        $this->seedCidadesMS();
        $this->seedAreas();
        $this->seedInstituicoes();
    }

    private function seedEdicao(): void
    {
        Edicao::firstOrCreate(
            ['nome' => 'XVI FETECMS', 'ano' => 2026],
            ['inscricoes_abertas' => true],
        );
    }

    private function seedEstados(): void
    {
        $estados = [
            'AC' => 'Acre', 'AL' => 'Alagoas', 'AP' => 'Amapá', 'AM' => 'Amazonas',
            'BA' => 'Bahia', 'CE' => 'Ceará', 'DF' => 'Distrito Federal', 'ES' => 'Espírito Santo',
            'GO' => 'Goiás', 'MA' => 'Maranhão', 'MT' => 'Mato Grosso', 'MS' => 'Mato Grosso do Sul',
            'MG' => 'Minas Gerais', 'PA' => 'Pará', 'PB' => 'Paraíba', 'PR' => 'Paraná',
            'PE' => 'Pernambuco', 'PI' => 'Piauí', 'RJ' => 'Rio de Janeiro', 'RN' => 'Rio Grande do Norte',
            'RS' => 'Rio Grande do Sul', 'RO' => 'Rondônia', 'RR' => 'Roraima', 'SC' => 'Santa Catarina',
            'SP' => 'São Paulo', 'SE' => 'Sergipe', 'TO' => 'Tocantins',
        ];

        foreach ($estados as $uf => $nome) {
            Estado::firstOrCreate(['uf' => $uf], ['nome' => $nome]);
        }
    }

    private function seedCidadesMS(): void
    {
        $ms = Estado::where('uf', 'MS')->first();
        $cidades = [
            'Campo Grande', 'Dourados', 'Três Lagoas', 'Corumbá', 'Ponta Porã',
            'Naviraí', 'Nova Andradina', 'Aquidauana', 'Sidrolândia', 'Maracaju',
            'Coxim', 'Paranaíba',
        ];
        foreach ($cidades as $nome) {
            Cidade::firstOrCreate(['estado_id' => $ms->id, 'nome' => $nome]);
        }

        // Algumas capitais p/ variedade nos selects.
        $sp = Estado::where('uf', 'SP')->first();
        Cidade::firstOrCreate(['estado_id' => $sp->id, 'nome' => 'São Paulo']);
    }

    private function seedAreas(): void
    {
        $arvore = [
            'Ciências Exatas e da Terra' => ['Matemática', 'Física', 'Química', 'Ciência da Computação', 'Geociências'],
            'Ciências Biológicas' => ['Genética', 'Botânica', 'Zoologia', 'Microbiologia', 'Ecologia'],
            'Engenharias' => ['Engenharia Civil', 'Engenharia Elétrica', 'Engenharia Mecânica', 'Engenharia de Produção', 'Engenharia de Materiais'],
            'Ciências da Saúde' => ['Medicina', 'Enfermagem', 'Nutrição', 'Farmácia', 'Odontologia'],
            'Ciências Agrárias' => ['Agronomia', 'Medicina Veterinária', 'Ciência e Tecnologia de Alimentos', 'Recursos Florestais'],
            'Ciências Sociais Aplicadas' => ['Administração', 'Direito', 'Economia', 'Comunicação'],
            'Ciências Humanas' => ['Educação', 'História', 'Geografia', 'Psicologia', 'Sociologia'],
            'Linguística, Letras e Artes' => ['Linguística', 'Letras', 'Artes'],
            'Multidisciplinar' => ['Biotecnologia', 'Meio Ambiente', 'Materiais'],
        ];

        foreach ($arvore as $area => $subareas) {
            $a = Area::firstOrCreate(['nome' => $area]);
            foreach ($subareas as $sub) {
                $a->subareas()->firstOrCreate(['nome' => $sub]);
            }
        }
    }

    private function seedInstituicoes(): void
    {
        $cidade = fn (string $nome) => Cidade::where('nome', $nome)->value('id');

        $escolas = [
            ['nome' => 'EE Prof. João Mendes', 'cidade' => 'Campo Grande', 'tipo' => 'publica_estadual'],
            ['nome' => 'Colégio Estadual Dom Aquino', 'cidade' => 'Campo Grande', 'tipo' => 'publica_estadual'],
            ['nome' => 'EE Maria Constança', 'cidade' => 'Campo Grande', 'tipo' => 'publica_estadual'],
            ['nome' => 'IFMS Campus Três Lagoas', 'cidade' => 'Três Lagoas', 'tipo' => 'publica_federal'],
            ['nome' => 'IFMS Campus Dourados', 'cidade' => 'Dourados', 'tipo' => 'publica_federal'],
            ['nome' => 'EE Fauze Scaff Gattass Filho', 'cidade' => 'Dourados', 'tipo' => 'publica_estadual'],
            ['nome' => 'Colégio Adventista de Campo Grande', 'cidade' => 'Campo Grande', 'tipo' => 'privada'],
            ['nome' => 'EE Cândido Mariano', 'cidade' => 'Corumbá', 'tipo' => 'publica_estadual'],
        ];

        foreach ($escolas as $e) {
            Instituicao::firstOrCreate(
                ['nome' => $e['nome']],
                ['cidade_id' => $cidade($e['cidade']), 'tipo' => $e['tipo']],
            );
        }
    }
}
