<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;
use SimpleXMLElement;
use ZipArchive;

/**
 * Leitor mínimo de planilha: **.xlsx** (a primeira aba) ou **.csv**.
 *
 * É o par do {@see PlanilhaXlsx}: o portal entrega um modelo em Excel, quem
 * organiza a equipe preenche e devolve — às vezes no próprio .xlsx, às vezes
 * salvo como CSV. Devolve uma lista de linhas, cada uma uma lista de **textos**
 * na ordem das colunas, sem linhas em branco. Interpretar as colunas é de quem
 * chama.
 *
 * O que o leitor resolve, porque é onde a planilha preenchida à mão tropeça:
 *
 * - **xlsx**: strings compartilhadas e inline, células puladas (a coluna "C"
 *   vazia não some — ela fica como texto vazio no lugar dela) e número vindo
 *   como número (`12345678909` não ganha notação científica). Datas chegam
 *   como o **número serial** do Excel; {@see self::dataExcel()} converte.
 * - **csv**: BOM, separador `;`, `,` ou tabulação (o Excel em português salva
 *   com `;`) e arquivo em Windows-1252, que é o que o Excel grava quando o
 *   usuário escolhe "CSV" e não "CSV UTF-8".
 *
 * Fórmula é lida pelo **valor em cache**, que é o que o Excel grava junto.
 */
final class LeitorPlanilha
{
    /** Teto de linhas: o lote é de uma equipe, não da base inteira. */
    public const MAX_LINHAS = 2000;

    /** @return list<list<string>> */
    public static function ler(string $caminho, string $extensao): array
    {
        $linhas = strtolower($extensao) === 'xlsx'
            ? self::xlsx($caminho)
            : self::csv((string) file_get_contents($caminho));

        $linhas = array_values(array_filter(
            $linhas,
            fn (array $l) => implode('', array_map('trim', $l)) !== '',
        ));

        if (count($linhas) > self::MAX_LINHAS) {
            throw ValidationException::withMessages([
                'arquivo' => 'A planilha tem mais de '.self::MAX_LINHAS.' linhas.',
            ]);
        }

        return $linhas;
    }

    /**
     * Número serial do Excel (dias desde 30/12/1899, fração = hora) para
     * "Y-m-d H:i". Devolve null para o que não é número.
     */
    public static function dataExcel(string $valor): ?string
    {
        if (! is_numeric($valor)) {
            return null;
        }

        $serial = (float) $valor;
        $segundos = (int) round(($serial - 25569) * 86400);

        return gmdate('Y-m-d H:i', $segundos);
    }

    /** @return list<list<string>> */
    private static function xlsx(string $caminho): array
    {
        $zip = new ZipArchive;

        if ($zip->open($caminho) !== true) {
            throw ValidationException::withMessages(['arquivo' => 'Não foi possível abrir a planilha. Confira se o arquivo é um .xlsx válido.']);
        }

        try {
            $compartilhadas = self::stringsCompartilhadas($zip);
            $aba = self::primeiraAba($zip);
            $xml = $aba === null ? false : $zip->getFromName($aba);

            if ($xml === false) {
                throw ValidationException::withMessages(['arquivo' => 'A planilha não tem nenhuma aba com dados.']);
            }

            $planilha = self::xml($xml);
            $planilha->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

            $linhas = [];

            foreach ($planilha->xpath('//m:sheetData/m:row') ?: [] as $row) {
                $valores = [];

                foreach ($row->c as $celula) {
                    $indice = self::indiceColuna((string) $celula['r']) ?? count($valores);

                    while (count($valores) < $indice) {
                        $valores[] = '';
                    }

                    $valores[$indice] = self::valor($celula, $compartilhadas);
                }

                $linhas[] = $valores;
            }

            return $linhas;
        } finally {
            $zip->close();
        }
    }

    /** O caminho da primeira aba, seguindo workbook → relações (nem sempre é sheet1). */
    private static function primeiraAba(ZipArchive $zip): ?string
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbook === false || $rels === false) {
            return $zip->locateName('xl/worksheets/sheet1.xml') !== false ? 'xl/worksheets/sheet1.xml' : null;
        }

        $wb = self::xml($workbook);
        $sheet = $wb->sheets->sheet[0] ?? null;

        if ($sheet === null) {
            return null;
        }

        $id = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];

        foreach (self::xml($rels)->Relationship as $rel) {
            if ((string) $rel['Id'] === $id) {
                $alvo = ltrim((string) $rel['Target'], '/');

                return str_starts_with($alvo, 'xl/') ? $alvo : 'xl/'.$alvo;
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function stringsCompartilhadas(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $strings = [];

        foreach (self::xml($xml)->si as $si) {
            $strings[] = self::textoRico($si);
        }

        return $strings;
    }

    /** @param  list<string>  $compartilhadas */
    private static function valor(SimpleXMLElement $celula, array $compartilhadas): string
    {
        $tipo = (string) $celula['t'];

        return match ($tipo) {
            's' => $compartilhadas[(int) $celula->v] ?? '',
            'inlineStr' => self::textoRico($celula->is),
            'b' => ((string) $celula->v) === '1' ? 'VERDADEIRO' : 'FALSO',
            default => self::numero((string) $celula->v),
        };
    }

    /** Um número sem notação científica e sem ".0" sobrando. */
    private static function numero(string $v): string
    {
        if ($v === '' || ! is_numeric($v)) {
            return $v;
        }

        if (stripos($v, 'e') !== false || str_ends_with($v, '.0')) {
            $f = (float) $v;

            return floor($f) === $f ? sprintf('%.0f', $f) : rtrim(rtrim(sprintf('%.10F', $f), '0'), '.');
        }

        return $v;
    }

    /** O texto de um `<si>`/`<is>`, juntando os trechos formatados (`<r>`). */
    private static function textoRico(?SimpleXMLElement $no): string
    {
        if ($no === null) {
            return '';
        }

        if (isset($no->t)) {
            return (string) $no->t;
        }

        $texto = '';

        foreach ($no->r as $trecho) {
            $texto .= (string) $trecho->t;
        }

        return $texto;
    }

    /** "C12" → 2. */
    private static function indiceColuna(string $ref): ?int
    {
        if (! preg_match('/^([A-Z]+)\d+$/', strtoupper($ref), $m)) {
            return null;
        }

        $indice = 0;

        foreach (str_split($m[1]) as $letra) {
            $indice = $indice * 26 + (ord($letra) - 64);
        }

        return $indice - 1;
    }

    private static function xml(string $conteudo): SimpleXMLElement
    {
        // Sem entidades externas: o arquivo vem de fora.
        $xml = @simplexml_load_string($conteudo, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);

        if ($xml === false) {
            throw ValidationException::withMessages(['arquivo' => 'A planilha está corrompida ou não é um .xlsx.']);
        }

        return $xml;
    }

    /** @return list<list<string>> */
    private static function csv(string $conteudo): array
    {
        $conteudo = preg_replace('/^\xEF\xBB\xBF/', '', $conteudo) ?? $conteudo;

        if (! mb_check_encoding($conteudo, 'UTF-8')) {
            $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'Windows-1252');
        }

        $primeira = strtok($conteudo, "\n") ?: '';
        $separador = collect([';', ',', "\t"])
            ->sortByDesc(fn ($s) => substr_count($primeira, $s))
            ->first();

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $conteudo);
        rewind($stream);

        $linhas = [];

        while (($linha = fgetcsv($stream, 0, $separador, '"', '')) !== false) {
            if ($linha === [null]) {
                continue;
            }

            $linhas[] = array_map(fn ($v) => (string) $v, $linha);
        }

        fclose($stream);

        return $linhas;
    }
}
