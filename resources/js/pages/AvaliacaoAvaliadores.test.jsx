import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
// O panorama tem teste próprio e repetiria os nomes das áreas nesta tela.
vi.mock('../components/PanoramaAvaliadores.jsx', () => ({ default: () => <div>panorama</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({ extractErrors: () => ({ message: '', fields: {} }) }));
vi.mock('../lib/catalogos.js', () => ({
    loadAreas: vi.fn(() => Promise.resolve([{ id: 1, nome: 'Ciências Agrárias' }, { id: 2, nome: 'Ciências Exatas' }])),
    loadSubareas: vi.fn(() => Promise.resolve([{ id: 5, nome: 'Física' }])),
}));

const LINHAS = [
    {
        id: 1, nome: 'Ana', email: 'ana@teste.com', area_id: 1, area: 'Ciências Agrárias',
        subarea: 'Agronomia', em_avaliacao: 1, avaliou: 2, faltam: 1, limite: 2, is_demo: true,
        comissao_especial: false, areas_extras: [],
        criado_em: '2026-03-01T10:00:00-04:00', criado_em_label: '01/03/2026',
    },
    {
        id: 2, nome: 'Bruno', email: 'bruno@teste.com', area_id: 2, area: 'Ciências Exatas',
        subarea: null, em_avaliacao: 0, avaliou: 0, faltam: 3, limite: null, is_demo: false,
        comissao_especial: true,
        areas_extras: [{ id: 7, area_id: 1, area: 'Ciências Agrárias', subarea_id: null, subarea: null }],
        criado_em: '2026-04-15T10:00:00-04:00', criado_em_label: '15/04/2026',
    },
];

const META = {
    pagina_atual: 1, ultima_pagina: 1, total: 2, por_pagina: 50,
    areas: [{ id: 1, nome: 'Ciências Agrárias' }, { id: 2, nome: 'Ciências Exatas' }],
    ordenar: 'nome', direcao: 'asc',
};

const getAvaliacaoAvaliadores = vi.fn(() => Promise.resolve({ data: LINHAS, meta: META }));
const exportarAvaliadoresCsv = vi.fn(() => Promise.resolve());
const definirDemoAvaliador = vi.fn(() => Promise.resolve({ meta: { message: 'ok' } }));
const definirComissaoAvaliador = vi.fn(() => Promise.resolve({ meta: { message: 'Avaliador incluído na comissão especial.' } }));
const adicionarAreaExtra = vi.fn();
const removerAreaExtra = vi.fn();

vi.mock('../lib/admin.js', () => ({
    getAvaliacaoAvaliadores: (...a) => getAvaliacaoAvaliadores(...a),
    exportarAvaliadoresCsv: (...a) => exportarAvaliadoresCsv(...a),
    definirLimiteAvaliador: vi.fn(() => Promise.resolve({ meta: { message: 'ok' } })),
    definirDemoAvaliador: (...a) => definirDemoAvaliador(...a),
    limparDadosDeTeste: vi.fn(() => Promise.resolve({ meta: { message: '0 apagadas' } })),
    definirComissaoAvaliador: (...a) => definirComissaoAvaliador(...a),
    adicionarAreaExtra: (...a) => adicionarAreaExtra(...a),
    removerAreaExtra: (...a) => removerAreaExtra(...a),
}));

import AvaliacaoAvaliadores from './AvaliacaoAvaliadores.jsx';

describe('AvaliacaoAvaliadores — tabela única', () => {
    beforeEach(() => {
        getAvaliacaoAvaliadores.mockClear();
        exportarAvaliadoresCsv.mockClear();
        definirDemoAvaliador.mockClear();
        definirComissaoAvaliador.mockClear();
        adicionarAreaExtra.mockReset();
        removerAreaExtra.mockReset();
    });

    it('mostra todos os avaliadores numa tabela só, sem acordeão por área', async () => {
        render(<AvaliacaoAvaliadores />);

        expect(await screen.findByText('Ana')).toBeInTheDocument();
        expect(screen.getByText('Bruno')).toBeInTheDocument();
        expect(screen.getByText('2 avaliadores.')).toBeInTheDocument();
        expect(screen.queryByText('Expandir todas')).not.toBeInTheDocument();
        expect(screen.getByRole('table')).toBeInTheDocument();
    });

    it('traz nome, área, métricas, cadastro, demo e bloqueio em cada linha', async () => {
        render(<AvaliacaoAvaliadores />);
        await screen.findByText('Ana');

        // A área aparece na linha e também como opção do filtro: olha só a tabela.
        const tabela = within(screen.getByRole('table'));
        expect(tabela.getByText('ana@teste.com')).toBeInTheDocument();
        expect(tabela.getByText('Ciências Agrárias')).toBeInTheDocument();
        expect(tabela.getByText('Agronomia')).toBeInTheDocument();
        expect(screen.getByText('01/03/2026')).toBeInTheDocument();
        expect(screen.getByText('Limite 2')).toBeInTheDocument();
        // O botão de demo virou só o frasco: quem o identifica é o aria-label.
        expect(screen.getByLabelText('Demo de Ana')).toBeInTheDocument();
        expect(screen.getByLabelText('Limitar Ana')).toBeInTheDocument();
    });

    /** O botão de áreas abre a fileira de ações: era o mais difícil de achar. */
    it('põe o botão de áreas antes do de demo em cada linha', async () => {
        render(<AvaliacaoAvaliadores />);
        await screen.findByText('Ana');

        const areas = screen.getByLabelText('Áreas de Ana');
        const demo = screen.getByLabelText('Demo de Ana');

        expect(areas.compareDocumentPosition(demo) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        // Só o ícone, sem rótulo escrito.
        expect(demo.textContent.trim()).toBe('science');
    });

    it('busca por nome ou e-mail (com debounce)', async () => {
        render(<AvaliacaoAvaliadores />);
        await screen.findByText('Ana');

        fireEvent.change(screen.getByLabelText('Buscar avaliador'), { target: { value: 'ana' } });

        // 300ms de debounce + recarga: folga para a suíte cheia, que roda em paralelo.
        await waitFor(() => {
            expect(getAvaliacaoAvaliadores).toHaveBeenLastCalledWith(expect.objectContaining({ q: 'ana', page: 1 }));
        }, { timeout: 3000 });
    });

    it('filtra por área do conhecimento', async () => {
        render(<AvaliacaoAvaliadores />);
        await screen.findByText('Ana');

        fireEvent.change(screen.getByLabelText('Filtrar por área do conhecimento'), { target: { value: '2' } });

        await waitFor(() => {
            expect(getAvaliacaoAvaliadores).toHaveBeenLastCalledWith(expect.objectContaining({ areaId: '2' }));
        });
    });

    it('ordena por coluna, alternando asc e desc', async () => {
        render(<AvaliacaoAvaliadores />);
        await screen.findByText('Ana');

        fireEvent.click(screen.getByLabelText('Ordenar por Faltantes'));
        await waitFor(() => {
            expect(getAvaliacaoAvaliadores).toHaveBeenLastCalledWith(
                expect.objectContaining({ ordenar: 'faltam', direcao: 'asc' }),
            );
        });

        fireEvent.click(screen.getByLabelText('Ordenar por Faltantes'));
        await waitFor(() => {
            expect(getAvaliacaoAvaliadores).toHaveBeenLastCalledWith(
                expect.objectContaining({ ordenar: 'faltam', direcao: 'desc' }),
            );
        });
    });

    it('ordena também por data de cadastro', async () => {
        render(<AvaliacaoAvaliadores />);
        await screen.findByText('Ana');

        fireEvent.click(screen.getByLabelText('Ordenar por Cadastro'));

        await waitFor(() => {
            expect(getAvaliacaoAvaliadores).toHaveBeenLastCalledWith(expect.objectContaining({ ordenar: 'criado_em' }));
        });
    });

    it('exporta o CSV com os filtros em vigor', async () => {
        render(<AvaliacaoAvaliadores />);
        await screen.findByText('Ana');

        fireEvent.change(screen.getByLabelText('Filtrar por área do conhecimento'), { target: { value: '1' } });
        fireEvent.click(screen.getByText('Exportar CSV'));

        await waitFor(() => {
            expect(exportarAvaliadoresCsv).toHaveBeenLastCalledWith(expect.objectContaining({ areaId: '1' }));
        });
    });

    it('marca e desmarca o avaliador como demo', async () => {
        render(<AvaliacaoAvaliadores />);
        await screen.findByText('Ana');

        fireEvent.click(screen.getByLabelText('Demo de Bruno'));

        await waitFor(() => expect(definirDemoAvaliador).toHaveBeenCalledWith(2, true));
    });

    it('abre o modal de limitar avaliador', async () => {
        render(<AvaliacaoAvaliadores />);
        await screen.findByText('Ana');

        fireEvent.click(screen.getByLabelText('Limitar Ana'));

        expect(await screen.findByText('Máximo de avaliações que pode assumir')).toBeInTheDocument();
    });

    it('marca o avaliador como comissão especial', async () => {
        render(<AvaliacaoAvaliadores />);
        await screen.findByText('Ana');

        // Bruno já é da comissão: a linha traz o selo.
        expect(screen.getByText('Comissão')).toBeInTheDocument();

        fireEvent.click(screen.getByLabelText('Comissão especial de Ana'));

        await waitFor(() => expect(definirComissaoAvaliador).toHaveBeenCalledWith(1, true));
    });

    it('filtra por comissão especial', async () => {
        render(<AvaliacaoAvaliadores />);
        await screen.findByText('Ana');

        fireEvent.change(screen.getByLabelText('Filtrar por situação'), { target: { value: 'comissao' } });

        await waitFor(() => {
            expect(getAvaliacaoAvaliadores).toHaveBeenLastCalledWith(expect.objectContaining({ situacao: 'comissao' }));
        });
    });

    it('mostra quantas áreas extras o avaliador tem', async () => {
        render(<AvaliacaoAvaliadores />);
        await screen.findByText('Bruno');

        expect(screen.getByText('+ 1 área liberada')).toBeInTheDocument();
    });

    it('libera outra área para o avaliador', async () => {
        adicionarAreaExtra.mockResolvedValue({
            data: { ...LINHAS[0], areas_extras: [{ id: 9, area_id: 2, area: 'Ciências Exatas', subarea_id: 5, subarea: 'Física' }] },
            meta: { message: 'Área liberada para o avaliador.' },
        });
        render(<AvaliacaoAvaliadores />);
        await screen.findByText('Ana');

        fireEvent.click(screen.getByLabelText('Áreas de Ana'));
        expect(await screen.findByText('Áreas do avaliador')).toBeInTheDocument();
        expect(screen.getByText('Nenhuma área extra liberada.')).toBeInTheDocument();

        fireEvent.change(await screen.findByLabelText('Área a liberar'), { target: { value: '2' } });
        fireEvent.change(await screen.findByLabelText('Subárea a liberar (opcional)'), { target: { value: '5' } });
        fireEvent.click(screen.getByText('Liberar'));

        await waitFor(() => expect(adicionarAreaExtra).toHaveBeenCalledWith(1, 2, 5));
        expect(await screen.findByText('Ciências Exatas · Física')).toBeInTheDocument();
    });

    it('remove uma área extra', async () => {
        removerAreaExtra.mockResolvedValue({ data: { ...LINHAS[1], areas_extras: [] }, meta: { message: 'Área removida.' } });
        render(<AvaliacaoAvaliadores />);
        await screen.findByText('Bruno');

        fireEvent.click(screen.getByLabelText('Áreas de Bruno'));
        fireEvent.click(await screen.findByLabelText('Remover Ciências Agrárias'));

        await waitFor(() => expect(removerAreaExtra).toHaveBeenCalledWith(2, 7));
        expect(await screen.findByText('Nenhuma área extra liberada.')).toBeInTheDocument();
    });
});
