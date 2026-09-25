import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const getAjustes = vi.fn();
const getAjustesProjeto = vi.fn();
const decidirAjuste = vi.fn();

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../lib/auth.jsx', () => ({ extractErrors: () => ({ message: 'erro', fields: {} }) }));
vi.mock('../lib/ajustes.js', () => ({
    getAjustes: (...a) => getAjustes(...a),
    getAjustesProjeto: (...a) => getAjustesProjeto(...a),
    decidirAjuste: (...a) => decidirAjuste(...a),
}));

import AjustesPareceres from './AjustesPareceres.jsx';

const PROJETO = {
    id: 7, titulo: 'Bioplástico de mandioca', area: 'Ciências Exatas e da Terra', subarea: null,
    avaliacoes: 2, sugestoes: 1, pendentes: 1, aceitas: 0, recomendacoes: 1,
};

const DETALHE = {
    id: 7, titulo: 'Bioplástico de mandioca', area: 'Ciências Exatas e da Terra', subarea: null,
    avaliacoes: 2,
    sugestoes: [{
        avaliacao_id: 3, avaliador: 'Avaliador 1', tipo: 'area', tipo_label: 'Área do conhecimento',
        atual: 'Ciências Exatas e da Terra', sugerido: 'Ciências Agrárias', sugerido_id: 2,
        aceito: false, decidido_em: null,
    }],
    secoes: [
        { chave: 'titulo', titulo: 'Título', nivel: 'forte', nivel_label: 'Ponto forte' },
        { chave: 'resumo', titulo: 'Resumo', nivel: 'medio', nivel_label: 'Ponto médio' },
        { chave: 'metodologia', titulo: 'Metodologia', nivel: 'fraco', nivel_label: 'Ponto fraco' },
        { chave: 'video', titulo: 'Vídeo', nivel: null, nivel_label: 'Não avaliado' },
    ],
    recomendacoes: [{
        avaliacao_id: 3, avaliador: 'Avaliador 1', tipo: 'video',
        titulo: 'Sobre o vídeo', texto: 'Melhore o áudio.',
    }],
};

const ABERTA = { aberta: true, leitura: true, iniciada: true, encerrada: false, de_label: '01/09/2026 08:00', ate_label: '10/09/2026 23:59', is_demo: false, modo_teste: false };

describe('Ajustes e Pareceres — orientador', () => {
    beforeEach(() => {
        getAjustes.mockReset();
        getAjustesProjeto.mockReset();
        decidirAjuste.mockReset();
        getAjustes.mockResolvedValue({ janela: ABERTA, projetos: [PROJETO] });
        getAjustesProjeto.mockResolvedValue(DETALHE);
    });

    it('reúne numa tela só as sugestões, as etapas e as recomendações', async () => {
        render(<AjustesPareceres />);

        fireEvent.click(await screen.findByText('Bioplástico de mandioca'));
        await waitFor(() => expect(getAjustesProjeto).toHaveBeenCalledWith(7, false));

        // Metade "ajustes": a sugestão a decidir.
        expect(await screen.findByText('Ciências Agrárias')).toBeInTheDocument();
        expect(screen.getByText('Área do conhecimento · Avaliador 1')).toBeInTheDocument();
        expect(screen.getByText('Aceitar')).toBeInTheDocument();

        // Metade "pareceres": as etapas em níveis e o texto escrito.
        expect(screen.getByText('Pontos fortes').closest('div')).toHaveTextContent('Título');
        expect(screen.getByText('Pontos médios').closest('div')).toHaveTextContent('Resumo');
        expect(screen.getByText('Pontos fracos').closest('div')).toHaveTextContent('Metodologia');
        // Etapa sem resposta é dita como tal, não vira ponto fraco.
        expect(screen.getByText(/Sem resposta registrada em: Vídeo/)).toBeInTheDocument();
        expect(screen.getByText('Melhore o áudio.')).toBeInTheDocument();
    });

    /** A nota é da organização: nem a do projeto nem a de cada etapa aparecem. */
    it('não mostra nota nenhuma, na lista nem no detalhe', async () => {
        getAjustes.mockResolvedValue({
            janela: ABERTA,
            // Mesmo que o servidor mandasse a nota, a tela não tem onde pô-la.
            projetos: [{ ...PROJETO, media: 7.35, nota_maxima: 10 }],
        });
        render(<AjustesPareceres />);

        expect(await screen.findByText('Bioplástico de mandioca')).toBeInTheDocument();
        expect(screen.queryByText('7,35')).not.toBeInTheDocument();
        expect(screen.queryByText(/de 10,00/)).not.toBeInTheDocument();

        fireEvent.click(screen.getByText('Bioplástico de mandioca'));

        expect(await screen.findByText(/Resultado de 2 avaliação\(ões\) concluída\(s\)/)).toBeInTheDocument();
        expect(screen.queryByText('7,35')).not.toBeInTheDocument();
    });

    it('aceita a sugestão e ela continua na tela, agora marcada', async () => {
        decidirAjuste.mockResolvedValue({
            data: {
                ...DETALHE,
                area: 'Ciências Agrárias',
                sugestoes: [{ ...DETALHE.sugestoes[0], aceito: true, decidido_em: '2026-09-02T10:00:00-04:00' }],
            },
            meta: { message: 'Sugestão aceita — a classificação do projeto foi atualizada.' },
        });

        render(<AjustesPareceres />);
        fireEvent.click(await screen.findByText('Bioplástico de mandioca'));
        fireEvent.click(await screen.findByText('Aceitar'));

        await waitFor(() => expect(decidirAjuste).toHaveBeenCalledWith(
            7, { avaliacao_id: 3, tipo: 'area', aceito: true }, false,
        ));

        expect(await screen.findByText('Em vigor')).toBeInTheDocument();
        // Continua listada, agora com a opção de desfazer.
        expect(screen.getByText('Desfazer')).toBeInTheDocument();
        // E o parecer segue na mesma tela, sem recarregar nada.
        expect(screen.getByText('Melhore o áudio.')).toBeInTheDocument();
    });

    it('fora do período explica que a aba está fechada', async () => {
        getAjustes.mockResolvedValue({
            janela: { ...ABERTA, aberta: false, leitura: false, iniciada: false, encerrada: false },
            projetos: [],
        });
        render(<AjustesPareceres />);

        expect(await screen.findByText('O período de ajustes ainda não começou')).toBeInTheDocument();
        expect(screen.getByText(/A aba abre em 01\/09\/2026 08:00/)).toBeInTheDocument();
        expect(screen.queryByText('Bioplástico de mandioca')).not.toBeInTheDocument();
    });

    it('orientador demo tem o modo de teste para ver a aba fora do período', async () => {
        getAjustes.mockResolvedValue({
            janela: { ...ABERTA, aberta: false, leitura: false, iniciada: false, is_demo: true },
            projetos: [],
        });
        render(<AjustesPareceres />);

        expect(await screen.findByText('Modo de teste')).toBeInTheDocument();

        getAjustes.mockResolvedValue({ janela: { ...ABERTA, is_demo: true, modo_teste: true }, projetos: [PROJETO] });
        fireEvent.click(screen.getByRole('switch'));

        await waitFor(() => expect(getAjustes).toHaveBeenLastCalledWith(true));
        expect(await screen.findByText('Bioplástico de mandioca')).toBeInTheDocument();
    });
    /**
     * O prazo fecha os botões, não a aba: o parecer é a devolutiva do trabalho
     * de um ano, e sumir num prazo administrativo apagaria justamente o que o
     * orientador leva para a edição seguinte.
     */
    it('depois do prazo o parecer continua à vista, sem os botões', async () => {
        getAjustes.mockResolvedValue({
            janela: { ...ABERTA, aberta: false, leitura: true, encerrada: true },
            projetos: [PROJETO],
        });
        getAjustesProjeto.mockResolvedValue({
            ...DETALHE,
            sugestoes: [{ ...DETALHE.sugestoes[0], aceito: true, decidido_em: '2026-09-05T10:00:00-04:00' }],
        });
        render(<AjustesPareceres />);

        expect(await screen.findByText(/O período de ajustes terminou em 10\/09\/2026 23:59/)).toBeInTheDocument();
        fireEvent.click(await screen.findByText('Bioplástico de mandioca'));

        // O parecer inteiro continua lá.
        expect(await screen.findByText('Melhore o áudio.')).toBeInTheDocument();
        expect(screen.getByText('Título')).toBeInTheDocument();

        // A sugestão fica, com o que ficou valendo — e sem o botão.
        expect(screen.getByText('Ciências Agrárias')).toBeInTheDocument();
        expect(screen.getByText('Em vigor')).toBeInTheDocument();
        expect(screen.getByText('Aceita por você')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Desfazer' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Aceitar' })).not.toBeInTheDocument();
    });
});
