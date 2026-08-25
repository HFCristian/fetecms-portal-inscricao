import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({ extractErrors: () => ({ message: '', fields: {} }) }));
vi.mock('../lib/catalogos.js', () => ({
    loadAreas: vi.fn(() => Promise.resolve([{ id: 1, nome: 'Ciências Agrárias' }])),
    loadSubareas: vi.fn(() => Promise.resolve([{ id: 5, nome: 'Agronomia' }])),
}));

const LINHAS = [
    {
        id: 1, titulo: 'Projeto X', area_id: 1, area: 'Ciências Agrárias', subarea: 'Agronomia',
        categoria: 'fetec_jr', categoria_label: 'FETEC Jr', realizadas: 2, em_avaliacao: 1, faltantes: 1,
    },
    {
        id: 2, titulo: 'Projeto Y', area_id: 2, area: 'Ciências Exatas', subarea: null,
        categoria: 'fetecms', categoria_label: 'FETECMS', realizadas: 0, em_avaliacao: 0, faltantes: 3,
    },
];

const META = {
    pagina_atual: 1, ultima_pagina: 1, total: 2, por_pagina: 50,
    areas: [{ id: 1, nome: 'Ciências Agrárias' }, { id: 2, nome: 'Ciências Exatas' }],
    categorias: [
        { value: 'fetec_jr', label: 'FETEC Jr' },
        { value: 'fetecms', label: 'FETECMS' },
        { value: 'fetecms_fundect', label: 'FETECMS FUNDECT' },
    ],
    ordenar: 'titulo', direcao: 'asc',
    min_por_projeto: 3,
    resumo_areas: [
        { area_id: 1, area: 'Ciências Agrárias', zero: 0, uma: 0, duas: 1, tres_ou_mais: 0, total: 1, completos: 0 },
        { area_id: 2, area: 'Ciências Exatas', zero: 1, uma: 0, duas: 0, tres_ou_mais: 0, total: 1, completos: 0 },
    ],
};

const getAvaliacaoProjetos = vi.fn(() => Promise.resolve({ data: LINHAS, meta: META }));
const exportarProjetosAvaliacaoCsv = vi.fn(() => Promise.resolve());
const designarProjeto = vi.fn(() => Promise.resolve({ data: { designadas: 1 }, meta: { message: '1 designação criada.' } }));
// Com `comissao`, devolve só os membros da comissão especial.
const getOpcoesAvaliadores = vi.fn((comissao) => Promise.resolve(comissao
    ? [{ id: 20, nome: 'Zilda Rocha', area: 'Ciências Exatas' }, { id: 30, nome: 'Célia Prado', area: 'Ciências Humanas' }]
    : [
        { id: 10, nome: 'Ana Lima', area: 'Ciências Agrárias' },
        { id: 20, nome: 'Zilda Rocha', area: 'Ciências Exatas' },
    ]));

vi.mock('../lib/admin.js', () => ({
    getAvaliacaoProjetos: (...a) => getAvaliacaoProjetos(...a),
    exportarProjetosAvaliacaoCsv: (...a) => exportarProjetosAvaliacaoCsv(...a),
    getOpcoesAvaliadores: (...a) => getOpcoesAvaliadores(...a),
    designarProjeto: (...a) => designarProjeto(...a),
}));

import AvaliacaoProjetos from './AvaliacaoProjetos.jsx';

describe('AvaliacaoProjetos — tabela única', () => {
    beforeEach(() => {
        getAvaliacaoProjetos.mockClear();
        exportarProjetosAvaliacaoCsv.mockClear();
        designarProjeto.mockClear();
    });

    it('mostra todos os projetos numa tabela só, sem acordeão por área', async () => {
        render(<AvaliacaoProjetos />);

        expect(await screen.findByText('Projeto X')).toBeInTheDocument();
        expect(screen.getByText('Projeto Y')).toBeInTheDocument();
        expect(screen.getByText('2 projetos.')).toBeInTheDocument();
        expect(screen.queryByText('Expandir todas')).not.toBeInTheDocument();

        const tabela = within(screen.getByRole('table'));
        expect(tabela.getByText('Ciências Agrárias')).toBeInTheDocument();
        expect(tabela.getByText('FETEC Jr')).toBeInTheDocument();
    });

    it('busca pelo título (com debounce)', async () => {
        render(<AvaliacaoProjetos />);
        await screen.findByText('Projeto X');

        fireEvent.change(screen.getByLabelText('Buscar projeto'), { target: { value: 'bioplástico' } });

        await waitFor(() => {
            expect(getAvaliacaoProjetos).toHaveBeenLastCalledWith(expect.objectContaining({ q: 'bioplástico', page: 1 }));
        }, { timeout: 3000 });
    });

    it('filtra por área e por categoria', async () => {
        render(<AvaliacaoProjetos />);
        await screen.findByText('Projeto X');

        fireEvent.change(screen.getByLabelText('Filtrar por área do conhecimento'), { target: { value: '2' } });
        await waitFor(() => expect(getAvaliacaoProjetos).toHaveBeenLastCalledWith(expect.objectContaining({ areaId: '2' })));

        fireEvent.change(screen.getByLabelText('Filtrar por categoria'), { target: { value: 'fetecms' } });
        await waitFor(() => expect(getAvaliacaoProjetos).toHaveBeenLastCalledWith(expect.objectContaining({ categoria: 'fetecms' })));
    });

    it('ordena por coluna, alternando asc e desc', async () => {
        render(<AvaliacaoProjetos />);
        await screen.findByText('Projeto X');

        fireEvent.click(screen.getByLabelText('Ordenar por Faltantes'));
        await waitFor(() => {
            expect(getAvaliacaoProjetos).toHaveBeenLastCalledWith(expect.objectContaining({ ordenar: 'faltantes', direcao: 'asc' }));
        });

        fireEvent.click(screen.getByLabelText('Ordenar por Faltantes'));
        await waitFor(() => {
            expect(getAvaliacaoProjetos).toHaveBeenLastCalledWith(expect.objectContaining({ ordenar: 'faltantes', direcao: 'desc' }));
        });
    });

    it('exporta o CSV com os filtros em vigor', async () => {
        render(<AvaliacaoProjetos />);
        await screen.findByText('Projeto X');

        fireEvent.change(screen.getByLabelText('Filtrar por área do conhecimento'), { target: { value: '1' } });
        fireEvent.click(screen.getByText('Exportar CSV'));

        await waitFor(() => {
            expect(exportarProjetosAvaliacaoCsv).toHaveBeenLastCalledWith(expect.objectContaining({ areaId: '1' }));
        });
    });

    it('designa o projeto a um avaliador escolhido por busca', async () => {
        render(<AvaliacaoProjetos />);
        await screen.findByText('Projeto X');

        fireEvent.click(screen.getByLabelText('Designar Projeto X'));
        expect(await screen.findByText('Designar avaliação')).toBeInTheDocument();

        const buscaAvaliador = screen.getByPlaceholderText('Digite o nome do avaliador…');
        fireEvent.change(buscaAvaliador, { target: { value: 'zil' } });
        // O combobox seleciona no mouseDown da opção.
        fireEvent.mouseDown(screen.getByText('Zilda Rocha', { exact: false }));
        expect(buscaAvaliador.value).toBe('Zilda Rocha');

        // O botão do modal, não os "Designar" das linhas da tabela.
        fireEvent.click(within(screen.getByRole('dialog')).getByText('Designar'));

        await waitFor(() => expect(designarProjeto).toHaveBeenCalledWith(1, { tipo: 'avaliador', alvo_id: 20 }));
        expect(await screen.findByText('1 designação criada.')).toBeInTheDocument();
    });

    it('mostra um card de resumo por área, com as quatro faixas', async () => {
        render(<AvaliacaoProjetos />);
        await screen.findByText('Projeto X');

        const resumo = within(screen.getByRole('region', { name: 'Resumo por área do conhecimento' }));
        expect(resumo.getByText('Ciências Agrárias')).toBeInTheDocument();
        expect(resumo.getByText('Ciências Exatas')).toBeInTheDocument();
        // Os dois cards têm um projeto cada, nenhum com o mínimo ainda.
        expect(resumo.getAllByText('1 projeto · 0 com o mínimo de 3')).toHaveLength(2);
        // Faixas 0, 1, 2 e 3+ em cada card.
        expect(resumo.getAllByText('0')).not.toHaveLength(0);
        expect(resumo.getAllByText('3+')).toHaveLength(2);
    });

    it('os cards acompanham o filtro da tabela', async () => {
        render(<AvaliacaoProjetos />);
        await screen.findByText('Projeto X');

        getAvaliacaoProjetos.mockResolvedValueOnce({
            data: [LINHAS[1]],
            meta: {
                ...META,
                total: 1,
                resumo_areas: [{ area_id: 2, area: 'Ciências Exatas', zero: 1, uma: 0, duas: 0, tres_ou_mais: 0, total: 1, completos: 0 }],
            },
        });

        fireEvent.change(screen.getByLabelText('Filtrar por área do conhecimento'), { target: { value: '2' } });

        await waitFor(() => {
            const resumo = within(screen.getByRole('region', { name: 'Resumo por área do conhecimento' }));
            expect(resumo.queryByText('Ciências Agrárias')).not.toBeInTheDocument();
            expect(resumo.getByText('Ciências Exatas')).toBeInTheDocument();
        });
    });

    it('designa o projeto para a comissão especial inteira', async () => {
        render(<AvaliacaoProjetos />);
        await screen.findByText('Projeto X');

        fireEvent.click(screen.getByLabelText('Designar Projeto X'));
        fireEvent.change(await screen.findByLabelText('Designar para'), { target: { value: 'comissao' } });

        expect(await screen.findByText(/membros da/)).toBeInTheDocument();
        expect(getOpcoesAvaliadores).toHaveBeenCalledWith(true);

        fireEvent.click(within(screen.getByRole('dialog')).getByText('Designar'));

        await waitFor(() => expect(designarProjeto).toHaveBeenCalledWith(1, { tipo: 'comissao', avaliador_ids: [] }));
    });

    it('designa o projeto para membros selecionados da comissão', async () => {
        render(<AvaliacaoProjetos />);
        await screen.findByText('Projeto X');

        fireEvent.click(screen.getByLabelText('Designar Projeto X'));
        fireEvent.change(await screen.findByLabelText('Designar para'), { target: { value: 'comissao_selecionada' } });

        const dialogo = within(screen.getByRole('dialog'));
        // Sem ninguém marcado, o botão não designa.
        expect(await dialogo.findByText('Célia Prado')).toBeInTheDocument();
        expect(dialogo.getByText('Designar').closest('button')).toBeDisabled();

        fireEvent.click(dialogo.getByText('Célia Prado'));
        fireEvent.click(dialogo.getByText('Designar'));

        await waitFor(() => expect(designarProjeto).toHaveBeenCalledWith(1, { tipo: 'comissao', avaliador_ids: [30] }));
    });
});
