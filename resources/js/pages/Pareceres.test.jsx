import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../lib/auth.jsx', () => ({ extractErrors: (e) => ({ message: e?.message ?? 'Erro.' }) }));

const getPareceres = vi.fn();
const getParecerProjeto = vi.fn();
vi.mock('../lib/pareceres.js', () => ({
    getPareceres: (...a) => getPareceres(...a),
    getParecerProjeto: (...a) => getParecerProjeto(...a),
}));

import Pareceres from './Pareceres.jsx';

const JANELA_ABERTA = { aberta: true, iniciada: true, encerrada: false, is_demo: false };

const PROJETO = {
    id: 3, titulo: 'Bioplástico de mandioca', area: 'Exatas', subarea: null,
    avaliacoes: 2, media: 7.35, nota_maxima: 10, recomendacoes: 2,
};

const DETALHE = {
    ...PROJETO,
    secoes: [
        { chave: 'titulo', titulo: 'Título', nivel: 'forte', nivel_label: 'Ponto forte' },
        { chave: 'resumo', titulo: 'Resumo', nivel: 'medio', nivel_label: 'Ponto médio' },
        { chave: 'metodologia', titulo: 'Metodologia', nivel: 'fraco', nivel_label: 'Ponto fraco' },
        { chave: 'video', titulo: 'Vídeo', nivel: null, nivel_label: 'Não avaliado' },
    ],
    recomendacoes: [
        { avaliador: 'Avaliador 1', tipo: 'video', titulo: 'Sobre o vídeo', texto: 'Mostre o experimento.' },
        { avaliador: 'Avaliador 2', tipo: 'projeto', titulo: 'Sobre o projeto', texto: 'Detalhe a metodologia.' },
    ],
};

describe('Pareceres', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        getPareceres.mockResolvedValue({ janela: JANELA_ABERTA, projetos: [PROJETO] });
        getParecerProjeto.mockResolvedValue(DETALHE);
    });

    it('lista os projetos com a nota média', async () => {
        render(<Pareceres />);

        expect(await screen.findByText('Bioplástico de mandioca')).toBeInTheDocument();
        expect(screen.getByText('7,35')).toBeInTheDocument();
    });

    it('agrupa as etapas em pontos fortes, médios e fracos, sem mostrar nota', async () => {
        render(<Pareceres />);

        fireEvent.click(await screen.findByText('Bioplástico de mandioca'));
        await waitFor(() => expect(getParecerProjeto).toHaveBeenCalledWith(3, false));

        const forte = (await screen.findByText('Pontos fortes')).closest('div');
        expect(forte).toHaveTextContent('Título');

        const medio = screen.getByText('Pontos médios').closest('div');
        expect(medio).toHaveTextContent('Resumo');

        const fraco = screen.getByText('Pontos fracos').closest('div');
        expect(fraco).toHaveTextContent('Metodologia');

        // Etapa sem resposta é dita como tal, não vira ponto fraco.
        expect(screen.getByText(/Sem resposta registrada em: Vídeo/)).toBeInTheDocument();
    });

    it('mostra as observações sem identificar o avaliador', async () => {
        render(<Pareceres />);

        fireEvent.click(await screen.findByText('Bioplástico de mandioca'));

        expect(await screen.findByText('Mostre o experimento.')).toBeInTheDocument();
        expect(screen.getByText(/Sobre o vídeo · Avaliador 1/)).toBeInTheDocument();
        expect(screen.getByText(/Sobre o projeto · Avaliador 2/)).toBeInTheDocument();
    });

    it('fora da janela explica o motivo e não lista projeto', async () => {
        getPareceres.mockResolvedValue({
            janela: { aberta: false, iniciada: false, encerrada: false, is_demo: false, de_label: '20/10/2026 08:00' },
            projetos: [],
        });
        render(<Pareceres />);

        expect(await screen.findByText(/ainda não foram liberados/i)).toBeInTheDocument();
        expect(screen.getByText(/A aba abre em 20\/10\/2026 08:00/)).toBeInTheDocument();
        expect(screen.queryByText('Bioplástico de mandioca')).not.toBeInTheDocument();
    });

    it('orientador demo pode ligar o modo de teste', async () => {
        getPareceres.mockResolvedValue({
            janela: { aberta: false, iniciada: false, encerrada: false, is_demo: true },
            projetos: [],
        });
        render(<Pareceres />);

        fireEvent.click(await screen.findByRole('switch', { name: 'Modo de teste' }));

        await waitFor(() => expect(getPareceres).toHaveBeenLastCalledWith(true));
    });
});
