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
        $this->seedCidades();
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

    /**
     * Todos os municípios do Brasil (base oficial do IBGE, versionada em
     * database/data/municipios.json). Idempotente: o índice único (estado_id, nome)
     * + insertOrIgnore permitem re-seed sem duplicar. Se o arquivo não existir,
     * cai para um conjunto mínimo de MS apenas para o ambiente não ficar vazio.
     */
    private function seedCidades(): void
    {
        $ufToId = Estado::pluck('id', 'uf'); // ['MS' => 12, 'SP' => 26, ...]
        $path = database_path('data/municipios.json');

        if (! is_file($path)) {
            $ms = $ufToId['MS'] ?? null;
            foreach ($ms ? ['Campo Grande', 'Dourados', 'Três Lagoas', 'Corumbá'] : [] as $nome) {
                Cidade::firstOrCreate(['estado_id' => $ms, 'nome' => $nome]);
            }

            return;
        }

        $municipios = json_decode((string) file_get_contents($path), true) ?: [];
        $now = now();

        $linhas = [];
        foreach ($municipios as $m) {
            $estadoId = $ufToId[$m['uf']] ?? null;
            if ($estadoId === null) {
                continue;
            }
            $linhas[] = [
                'estado_id' => $estadoId,
                'nome' => $m['nome'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        foreach (array_chunk($linhas, 1000) as $chunk) {
            Cidade::insertOrIgnore($chunk);
        }
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
        // Resolve a cidade DENTRO de MS: com o Brasil inteiro semeado, nomes como
        // "Campo Grande" existem em mais de um estado.
        $msId = Estado::where('uf', 'MS')->value('id');
        $cidade = fn (string $nome) => Cidade::where('estado_id', $msId)->where('nome', $nome)->value('id');

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
