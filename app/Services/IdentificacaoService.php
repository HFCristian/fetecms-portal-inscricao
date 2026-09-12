<?php

namespace App\Services;

use App\Models\ListaFinal;
use App\Models\Projeto;
use App\Support\CodigoParticipante;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Picqer\Barcode\Renderers\SvgRenderer;
use Picqer\Barcode\Types\TypeCode128;
use ZipArchive;

/**
 * A identificação dos participantes da lista final: um **QR Code** e um **código
 * de barras** para cada pessoa que sobe ao evento — alunos, orientadores e
 * coorientadores.
 *
 * O código é **derivado**, não guardado ({@see CodigoParticipante}): ano da
 * edição, id do projeto, três dígitos do CPF e o id da pessoa com o prefixo do
 * papel. Tudo isso é estável pela vida do cadastro, então o mesmo crachá vale
 * depois de a lista mudar de versão — e não há tabela para sair de sincronia com
 * a realidade.
 *
 * Os dois formatos existem porque o evento tem dois leitores: o **QR** é lido
 * pela câmera de qualquer celular no balcão; o **código de barras** (Code 128) é
 * lido pelo leitor USB, que é o que trabalha rápido numa fila.
 *
 * Tudo sai em **SVG**: é vetor (imprime nítido em qualquer tamanho), não
 * depende da extensão GD no servidor e cabe num ZIP sem pesar.
 */
class IdentificacaoService
{
    /** Lado do QR no SVG, em pixels de referência. */
    private const QR_TAMANHO = 220;

    /**
     * Os participantes da lista, com o código de cada um.
     *
     * @return array<string, mixed>
     */
    public function painel(ListaFinal $lista): array
    {
        $participantes = $this->participantes($lista);

        return [
            'lista' => [
                'id' => $lista->id,
                'nome' => $lista->nome,
                'versao' => (int) $lista->versao,
                'demo' => (bool) $lista->demo,
            ],
            'total' => $participantes->count(),
            'por_papel' => $participantes->groupBy('papel')->map->count(),
            'participantes' => $participantes->values()->all(),
        ];
    }

    /**
     * O SVG de um código.
     *
     * @param  'qr'|'barras'  $tipo
     */
    public function svg(string $codigo, string $tipo): string
    {
        if (CodigoParticipante::ler($codigo) === null) {
            throw ValidationException::withMessages(['codigo' => 'Código de identificação inválido.']);
        }

        return $tipo === 'barras' ? $this->barras($codigo) : $this->qr($codigo);
    }

    /** As etiquetas de todos os participantes, prontas para imprimir e recortar. */
    public function pdf(ListaFinal $lista): string
    {
        $participantes = $this->participantes($lista)
            ->map(fn (array $p) => $p + [
                'qr' => $this->svgInterno($this->qr($p['codigo'])),
                'barras' => $this->svgInterno($this->barras($p['codigo'])),
            ])
            ->values()
            ->all();

        return app(PdfService::class)->render('pdf.identificacao', [
            'lista' => $lista,
            'participantes' => $participantes,
            'gerado_em' => now()->format('d/m/Y H:i'),
        ]);
    }

    /**
     * Um ZIP com dois SVGs por participante.
     *
     * O arquivo é montado em disco temporário porque o `ZipArchive` só escreve
     * em arquivo; quem chama devolve e apaga.
     */
    public function zip(ListaFinal $lista): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'identificacao-').'.zip';
        $zip = new ZipArchive;

        if ($zip->open($caminho, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw ValidationException::withMessages([
                'zip' => 'Não foi possível montar o arquivo compactado.',
            ]);
        }

        foreach ($this->participantes($lista) as $p) {
            // Uma pasta por projeto: no balcão as etiquetas são separadas por
            // equipe, não por ordem alfabética geral.
            $pasta = $this->nomeDeArquivo(sprintf('%03d-%s', $p['projeto_id'], $p['projeto']));
            $arquivo = $this->nomeDeArquivo($p['codigo'].'-'.$p['nome']);

            $zip->addFromString("{$pasta}/{$arquivo}-qr.svg", $this->qr($p['codigo']));
            $zip->addFromString("{$pasta}/{$arquivo}-barras.svg", $this->barras($p['codigo']));
        }

        $zip->close();

        return $caminho;
    }

    /**
     * Todas as pessoas da lista final, com projeto, papel e código.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function participantes(ListaFinal $lista): Collection
    {
        $ano = (int) ($lista->edicao?->ano ?? now()->year);

        $projetos = Projeto::whereIn('id', $lista->projetos()->pluck('projetos.id'))
            ->with(['alunos:id,projeto_id,nome,cpf', 'coorientador', 'user.orientadorProfile', 'instituicao:id,nome'])
            ->orderBy('titulo')
            ->get();

        $linhas = collect();

        foreach ($projetos as $projeto) {
            $comum = [
                'projeto_id' => $projeto->id,
                'projeto' => $projeto->titulo,
                'categoria' => $projeto->categoria?->label(),
                'escola' => $projeto->instituicao?->nome,
            ];

            foreach ($projeto->alunos as $aluno) {
                $linhas->push($comum + $this->linha(
                    $ano, $projeto->id, $aluno->nome, $aluno->cpf,
                    CodigoParticipante::PAPEL_ALUNO, $aluno->id,
                ));
            }

            if ($projeto->user !== null) {
                $linhas->push($comum + $this->linha(
                    $ano, $projeto->id, $projeto->user->name,
                    $projeto->user->orientadorProfile?->cpf,
                    CodigoParticipante::PAPEL_ORIENTADOR, $projeto->user->id,
                ));
            }

            if ($projeto->coorientador !== null) {
                $linhas->push($comum + $this->linha(
                    $ano, $projeto->id, $projeto->coorientador->nome, $projeto->coorientador->cpf,
                    CodigoParticipante::PAPEL_COORIENTADOR, $projeto->coorientador->id,
                ));
            }
        }

        return $linhas;
    }

    /** @return array<string, mixed> */
    private function linha(int $ano, int $projetoId, string $nome, ?string $cpf, string $papel, int $id): array
    {
        return [
            'nome' => $nome,
            'papel' => $papel,
            'papel_label' => CodigoParticipante::papelLabel($papel),
            'participante_id' => $id,
            'codigo' => CodigoParticipante::montar($ano, $projetoId, $cpf, $papel, $id),
        ];
    }

    private function qr(string $codigo): string
    {
        $writer = new Writer(new ImageRenderer(
            new RendererStyle(self::QR_TAMANHO, 1),
            new SvgImageBackEnd,
        ));

        return $writer->writeString($codigo);
    }

    private function barras(string $codigo): string
    {
        $renderer = new SvgRenderer;

        // Altura generosa e barra fina: é o que o leitor USB lê de primeira num
        // crachá pendurado no pescoço, meio torto.
        return $renderer->render((new TypeCode128)->getBarcode($codigo), 360, 70);
    }

    /**
     * Tira o cabeçalho XML do SVG para ele poder ser embutido no HTML do PDF —
     * o Dompdf não aceita `<?xml …?>` no meio da página.
     */
    private function svgInterno(string $svg): string
    {
        return trim(preg_replace('/<\?xml.*?\?>/s', '', $svg) ?? $svg);
    }

    /** Nome de arquivo seguro: sem acento, sem barra, sem espaço. */
    private function nomeDeArquivo(string $texto): string
    {
        $limpo = preg_replace('/[^A-Za-z0-9\-]+/', '-', Str::ascii($texto));

        return trim(mb_substr((string) $limpo, 0, 80), '-');
    }
}
