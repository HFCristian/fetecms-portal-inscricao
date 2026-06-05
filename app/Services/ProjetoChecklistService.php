<?php

namespace App\Services;

use App\Models\Projeto;

/**
 * Regra de negócio central da submissão (espelha o edital). Avalia o projeto e
 * devolve a lista de pendências. Tudo é obrigatório, exceto os 3 toggles da
 * seção 4 quando desmarcados; se um toggle está marcado, seu campo/anexo
 * dependente torna-se obrigatório.
 */
class ProjetoChecklistService
{
    private const RESUMO_MIN = 150;
    private const RESUMO_MAX = 250;

    /** @return array<int, array{code: string, message: string}> */
    public function pendencias(Projeto $projeto): array
    {
        $projeto->loadMissing(['alunos', 'documentos']);

        $pend = [];
        $falta = function (string $code, string $message) use (&$pend) {
            $pend[] = ['code' => $code, 'message' => $message];
        };

        // Seção 1 — Dados do projeto
        if (blank($projeto->titulo)) {
            $falta('TITULO', 'Informe o título do projeto.');
        }
        if (! $projeto->instituicao_id) {
            $falta('INSTITUICAO', 'Selecione a instituição de ensino.');
        }
        if ($projeto->categoria === null) {
            $falta('CATEGORIA', 'Selecione a categoria do projeto.');
        }
        if (! $projeto->area_id) {
            $falta('AREA', 'Selecione a área do conhecimento.');
        }
        if (! $projeto->subarea_id) {
            $falta('SUBAREA', 'Selecione a subárea.');
        }
        $kw = is_array($projeto->palavras_chave) ? count($projeto->palavras_chave) : 0;
        if ($kw < 3 || $kw > 5) {
            $falta('PALAVRAS_CHAVE', 'Informe de 3 a 5 palavras-chave.');
        }

        // Seção 2 — Localização
        if (blank($projeto->pais)) {
            $falta('PAIS', 'Informe o país.');
        }
        if (! $projeto->estado_id) {
            $falta('ESTADO', 'Selecione o estado.');
        }
        if (! $projeto->cidade_id) {
            $falta('CIDADE', 'Selecione a cidade.');
        }

        // Seção 3 — Conteúdo e arquivos
        if (! $this->videoValido($projeto->link_video)) {
            $falta('VIDEO', 'Informe um link de vídeo válido (YouTube, Vimeo ou Google Drive).');
        }
        $palavrasResumo = $this->contarPalavras($projeto->resumo);
        if ($palavrasResumo < self::RESUMO_MIN || $palavrasResumo > self::RESUMO_MAX) {
            $falta('RESUMO', 'O resumo deve ter entre 150 e 250 palavras.');
        }
        if (! $this->temDocumento($projeto, 'plano_pesquisa')) {
            $falta('PLANO_PESQUISA', 'Anexe o Projeto de Pesquisa (PDF ou DOCX).');
        }

        // Equipe
        $nAlunos = $projeto->alunos->count();
        if ($nAlunos < 1) {
            $falta('ALUNOS_MIN', 'Cadastre pelo menos 1 aluno.');
        }
        $max = $projeto->maxAlunos();
        if ($max !== null && $nAlunos > $max) {
            $falta('ALUNOS_MAX', "A categoria permite no máximo {$max} alunos.");
        }

        // Seção 5 — E-mail e declaração
        if (blank($projeto->email_comunicacao)) {
            $falta('EMAIL', 'Informe o e-mail para comunicação.');
        }
        if (! $projeto->declaracao_email) {
            $falta('DECLARACAO', 'Marque a declaração de leitura do e-mail.');
        }

        // Seção 4 — Condicionais (só obrigatórios quando o toggle está marcado)
        if ($projeto->continuacao && ! $this->temDocumento($projeto, 'projeto_continuacao')) {
            $falta('CONTINUACAO_DOC', 'Anexe o documento do Projeto de Continuação.');
        }
        if ($projeto->feira_afiliada && blank($projeto->feira_afiliada_nome)) {
            $falta('FEIRA_NOME', 'Informe o nome da feira afiliada.');
        }
        if ($projeto->necessita_termo_etica && ! $this->temDocumento($projeto, 'termo_etica')) {
            $falta('TERMO_ETICA_DOC', 'Anexe o Termo do Comitê Escolar de Ética (ANEXO V).');
        }

        return $pend;
    }

    public function podeSubmeter(Projeto $projeto): bool
    {
        return $projeto->status->editavel() && empty($this->pendencias($projeto));
    }

    private function temDocumento(Projeto $projeto, string $tipo): bool
    {
        return $projeto->documentos->contains(fn ($d) => $d->tipo->value === $tipo);
    }

    private function contarPalavras(?string $texto): int
    {
        if (blank($texto)) {
            return 0;
        }

        return count(preg_split('/\s+/', trim($texto), -1, PREG_SPLIT_NO_EMPTY));
    }

    private function videoValido(?string $url): bool
    {
        if (blank($url)) {
            return false;
        }

        return (bool) preg_match(
            '~(youtube\.com/(watch\?v=|embed/|shorts/)|youtu\.be/|vimeo\.com/|drive\.google\.com/file/d/)~i',
            $url,
        );
    }
}
