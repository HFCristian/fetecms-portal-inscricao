<?php

namespace App\Support;

use RuntimeException;
use ZipArchive;

/**
 * Escritor mínimo de planilha .xlsx (Office Open XML).
 *
 * O portal já exporta CSV, TXT e PDF; o .xlsx entrou porque a organização abre
 * o ranking no Excel e um CSV, por mais bem formado que seja, chega como texto:
 * a média com vírgula vira string, a coluna não soma e a ordenação sai
 * alfabética. Aqui o número vai como número.
 *
 * Por que escrever à mão em vez de instalar PhpSpreadsheet: o que o portal
 * precisa é **uma tabela plana** — cabeçalho em negrito e linhas. Um .xlsx é um
 * zip com meia dúzia de XMLs dentro, e o `ZipArchive` já vem no PHP. A
 * biblioteca resolveria fórmula, gráfico, imagem e leitura, nada disso usado
 * aqui, ao custo de ~10 MB no vendor e de mais uma dependência para atualizar
 * no deploy.
 *
 * O que este escritor **não** faz, de propósito: fórmula, mais de uma aba,
 * mesclagem, largura de coluna calculada e formatação condicional. Largura
 * **informada** e coluna em formato **texto** existem por causa dos modelos de
 * importação (contas temporárias em lote): um CPF digitado numa coluna
 * "Geral" perde o zero da frente. Precisando
 * de qualquer uma delas, a conta vira a favor da biblioteca — troque este
 * suporte por ela em vez de crescer o arquivo.
 *
 * Duas escolhas que evitam as armadilhas clássicas do formato:
 *
 * - **Strings inline** (`t="inlineStr"`), e não a tabela de strings
 *   compartilhadas. Poupa um XML inteiro e o índice que teria de ser mantido em
 *   sincronia; o arquivo fica um pouco maior, o que não pesa em listas de
 *   centenas de linhas.
 * - **Número é número**: `int` e `float` viram célula numérica, sem aspas e sem
 *   vírgula decimal. Quem formata para pt-BR é o Excel de quem abre — gravar
 *   "9,12" aqui é o erro que transforma a coluna em texto.
 */
class PlanilhaXlsx
{
    /**
     * Gera os bytes de um .xlsx com uma aba só.
     *
     * @param  string  $aba  nome da aba (o Excel corta em 31 caracteres)
     * @param  list<string>  $cabecalho  títulos da primeira linha, em negrito
     * @param  iterable<int, list<string|int|float|null>>  $linhas  uma lista por linha, na ordem do cabeçalho
     * @param  list<array{largura?: float|int, texto?: bool}>  $colunas  opcional, por coluna: largura em caracteres e formato texto para o que for digitado nela
     */
    public static function gerar(string $aba, array $cabecalho, iterable $linhas, array $colunas = []): string
    {
        $xml = self::planilha($cabecalho, $linhas, $colunas);

        $caminho = tempnam(sys_get_temp_dir(), 'xlsx');
        if ($caminho === false) {
            throw new RuntimeException('Não foi possível criar o arquivo temporário da planilha.');
        }

        $zip = new ZipArchive;
        if ($zip->open($caminho, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Não foi possível montar a planilha.');
        }

        foreach (self::partes($aba, $xml) as $nome => $conteudo) {
            $zip->addFromString($nome, $conteudo);
        }

        $zip->close();

        $bytes = (string) file_get_contents($caminho);
        unlink($caminho);

        return $bytes;
    }

    /**
     * Os XMLs fixos do pacote, mais a planilha. São poucos e pequenos: cada um
     * existe porque o Excel recusa o arquivo sem ele.
     *
     * @return array<string, string>
     */
    private static function partes(string $aba, string $planilha): array
    {
        $nomeAba = self::texto(mb_substr($aba, 0, 31));

        return [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                .'</Types>',

            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                .'</Relationships>',

            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
                .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<sheets><sheet name="'.$nomeAba.'" sheetId="1" r:id="rId1"/></sheets>'
                .'</workbook>',

            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                .'</Relationships>',

            // Dois estilos: o 0 é o normal (o Excel exige que ele exista) e o 1
            // é o negrito do cabeçalho, com fundo claro.
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                .'<fonts count="2">'
                .'<font><sz val="11"/><name val="Calibri"/></font>'
                .'<font><b/><sz val="11"/><name val="Calibri"/></font>'
                .'</fonts>'
                .'<fills count="3">'
                .'<fill><patternFill patternType="none"/></fill>'
                .'<fill><patternFill patternType="gray125"/></fill>'
                .'<fill><patternFill patternType="solid"><fgColor rgb="FFF3EDF7"/><bgColor indexed="64"/></patternFill></fill>'
                .'</fills>'
                .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
                .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                .'<cellXfs count="3">'
                .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
                .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
                // 49 é o formato "@" (texto) embutido no Excel.
                .'<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
                .'</cellXfs>'
                // O estilo "Normal" precisa estar nomeado: sem ele alguns
                // leitores avisam que a pasta não tem estilo padrão.
                .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
                .'</styleSheet>',

            'xl/worksheets/sheet1.xml' => $planilha,
        ];
    }

    /**
     * O XML da aba: uma `<row>` por linha, uma `<c>` por célula.
     *
     * @param  list<string>  $cabecalho
     * @param  iterable<int, list<string|int|float|null>>  $linhas
     * @param  list<array{largura?: float|int, texto?: bool}>  $colunas
     */
    private static function planilha(array $cabecalho, iterable $linhas, array $colunas = []): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .self::colunas($colunas)
            .'<sheetData>';

        $numero = 1;
        $xml .= self::linha($cabecalho, $numero, cabecalho: true);

        foreach ($linhas as $valores) {
            $numero++;
            $xml .= self::linha(array_values($valores), $numero, cabecalho: false);
        }

        return $xml.'</sheetData></worksheet>';
    }

    /**
     * Uma linha da planilha.
     *
     * @param  list<string|int|float|null>  $valores
     */
    private static function linha(array $valores, int $numero, bool $cabecalho): string
    {
        $celulas = '';

        foreach ($valores as $coluna => $valor) {
            $ref = self::coluna($coluna).$numero;
            $estilo = $cabecalho ? ' s="1"' : '';

            if ($valor === null || $valor === '') {
                $celulas .= '<c r="'.$ref.'"'.$estilo.'/>';

                continue;
            }

            // Número vai como número: é o que deixa a coluna somável e
            // ordenável, e o que faz a média aparecer na vírgula de quem abre.
            if (is_int($valor) || is_float($valor)) {
                $celulas .= '<c r="'.$ref.'"'.$estilo.'><v>'.$valor.'</v></c>';

                continue;
            }

            $celulas .= '<c r="'.$ref.'"'.$estilo.' t="inlineStr"><is><t xml:space="preserve">'
                .self::texto((string) $valor).'</t></is></c>';
        }

        return '<row r="'.$numero.'">'.$celulas.'</row>';
    }

    /**
     * O bloco `<cols>`: largura e estilo-padrão de cada coluna. O estilo vale
     * para a célula que o usuário ainda vai digitar — é o que mantém o zero da
     * frente de um CPF.
     *
     * @param  list<array{largura?: float|int, texto?: bool}>  $colunas
     */
    private static function colunas(array $colunas): string
    {
        $xml = '';

        foreach ($colunas as $i => $c) {
            $largura = (float) ($c['largura'] ?? 14);
            $estilo = ! empty($c['texto']) ? ' style="2"' : '';
            $n = $i + 1;
            $xml .= '<col min="'.$n.'" max="'.$n.'" width="'.$largura.'" customWidth="1"'.$estilo.'/>';
        }

        return $xml === '' ? '' : '<cols>'.$xml.'</cols>';
    }

    /** Índice 0 vira "A", 26 vira "AA" — a numeração de colunas do Excel. */
    private static function coluna(int $indice): string
    {
        $nome = '';

        for ($i = $indice; $i >= 0; $i = intdiv($i, 26) - 1) {
            $nome = chr(65 + ($i % 26)).$nome;
        }

        return $nome;
    }

    /**
     * Escapa o texto e tira os caracteres de controle que o XML 1.0 não aceita
     * — um título colado de um PDF costuma trazer um deles, e o Excel recusa o
     * arquivo inteiro por causa de um byte.
     */
    private static function texto(string $valor): string
    {
        $limpo = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $valor) ?? $valor;

        return htmlspecialchars($limpo, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
