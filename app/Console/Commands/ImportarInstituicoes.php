<?php

namespace App\Console\Commands;

use App\Models\Cidade;
use App\Models\Estado;
use App\Models\Instituicao;
use Illuminate\Console\Command;

/**
 * Importa instituições de ensino de um CSV com colunas
 * MUNICÍPIO, ZONA, CÓDIGO DO INEP, UNIDADE ESCOLAR, TIPO.
 * Idempotente: usa o código do INEP como chave (updateOrCreate).
 */
class ImportarInstituicoes extends Command
{
    protected $signature = 'instituicoes:importar {--arquivo=} {--uf=MS}';

    protected $description = 'Importa instituições de ensino de um CSV (padrão: database/data/instituicoes/escolas_ms.csv).';

    private const TIPOS = [
        'FEDERAL' => 'publica_federal',
        'ESTADUAL' => 'publica_estadual',
        'MUNICIPAL' => 'publica_municipal',
        'PARTICULAR' => 'particular',
    ];

    public function handle(): int
    {
        $arquivo = $this->option('arquivo') ?: database_path('data/instituicoes/escolas_ms.csv');
        if (! is_file($arquivo)) {
            $this->error("Arquivo não encontrado: {$arquivo}");

            return self::FAILURE;
        }

        $uf = mb_strtoupper((string) $this->option('uf'));
        $estadoId = Estado::where('uf', $uf)->value('id');
        if (! $estadoId) {
            $this->error("Estado {$uf} não encontrado. Rode os seeders de catálogo primeiro.");

            return self::FAILURE;
        }

        // município (minúsculo) => cidade_id, restrito à UF.
        $cidades = Cidade::where('estado_id', $estadoId)->pluck('id', 'nome')
            ->mapWithKeys(fn ($id, $nome) => [mb_strtolower($nome) => $id]);

        $handle = fopen($arquivo, 'r');
        fgetcsv($handle); // descarta o cabeçalho

        $importadas = 0;
        $municipiosSemCidade = [];

        while (($cols = fgetcsv($handle)) !== false) {
            if (count($cols) < 5) {
                continue;
            }
            [$municipio, $zona, $inep, $nome, $tipo] = array_map(fn ($v) => trim((string) $v), $cols);
            if ($nome === '') {
                continue;
            }

            $cidadeId = $cidades[mb_strtolower($municipio)] ?? null;
            if ($cidadeId === null && $municipio !== '') {
                $municipiosSemCidade[$municipio] = true;
            }

            Instituicao::updateOrCreate(
                $inep !== '' ? ['codigo_inep' => $inep] : ['nome' => $nome, 'cidade_id' => $cidadeId],
                [
                    'nome' => $nome,
                    'cidade_id' => $cidadeId,
                    'tipo' => self::TIPOS[mb_strtoupper($tipo)] ?? null,
                    'zona' => $zona !== '' ? $zona : null,
                    'codigo_inep' => $inep !== '' ? $inep : null,
                ],
            );
            $importadas++;
        }
        fclose($handle);

        $this->info("Instituições importadas/atualizadas: {$importadas}.");
        if ($municipiosSemCidade !== []) {
            $this->warn(count($municipiosSemCidade).' município(s) sem cidade correspondente em '
                .$uf.': '.implode(', ', array_keys($municipiosSemCidade)));
        }

        return self::SUCCESS;
    }
}
