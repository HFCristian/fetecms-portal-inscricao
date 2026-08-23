import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
// O panorama tem teste próprio e repetiria os nomes das áreas nesta tela.
vi.mock('../components/PanoramaAvaliadores.jsx', () => ({ default: () => <div>panorama</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({ extractErrors: () => ({ message: '', fields: {} }) }));
vi.mock('../lib/admin.js', () => ({
    getAvaliacaoAvaliadores: vi.fn(() => Promise.resolve([
        {
            area_id: 1,
            area: 'Ciências Agrárias',
            avaliadores: [
                { id: 1, nome: 'Ana', em_avaliacao: 1, avaliou: 2, faltam: 1, limite: 2, is_demo: true },
                { id: 2, nome: 'Bruno', em_avaliacao: 0, avaliou: 0, faltam: 3, limite: null, is_demo: false },
            ],
        },
        {
            area_id: 2,
            area: 'Ciências Exatas',
            avaliadores: [{ id: 3, nome: 'Carla', em_avaliacao: 0, avaliou: 3, faltam: 0, limite: null, is_demo: false }],
        },
    ])),
    definirLimiteAvaliador: vi.fn(() => Promise.resolve({ meta: { message: 'ok' } })),
    definirDemoAvaliador: vi.fn(() => Promise.resolve({ meta: { message: 'ok' } })),
    limparDadosDeTeste: vi.fn(() => Promise.resolve({ meta: { message: '0 apagadas' } })),
}));

import AvaliacaoAvaliadores from './AvaliacaoAvaliadores.jsx';

const abrirArea = async (nome) => {
    fireEvent.click(await screen.findByText(nome));
};

describe('AvaliacaoAvaliadores', () => {
    it('começa com as áreas compactadas', async () => {
        render(<AvaliacaoAvaliadores />);
        expect(await screen.findByText('Ciências Agrárias')).toBeInTheDocument();
        expect(screen.queryByText('Ana')).not.toBeInTheDocument();
    });

    it('abre e fecha a lista da área ao clicar no nome dela', async () => {
        render(<AvaliacaoAvaliadores />);

        await abrirArea('Ciências Agrárias');
        expect(screen.getByText('Ana')).toBeInTheDocument();
        expect(screen.getByText('Bruno')).toBeInTheDocument();
        expect(screen.queryByText('Carla')).not.toBeInTheDocument();

        fireEvent.click(screen.getByText('Ciências Agrárias'));
        expect(screen.queryByText('Ana')).not.toBeInTheDocument();
    });

    it('expande e recolhe todas as áreas de uma vez', async () => {
        render(<AvaliacaoAvaliadores />);
        fireEvent.click(await screen.findByText('Expandir todas'));

        expect(screen.getByText('Ana')).toBeInTheDocument();
        expect(screen.getByText('Carla')).toBeInTheDocument();

        fireEvent.click(screen.getByText('Recolher todas'));
        expect(screen.queryByText('Ana')).not.toBeInTheDocument();
    });

    it('lista com métricas, limite e marca demo', async () => {
        render(<AvaliacaoAvaliadores />);
        await abrirArea('Ciências Agrárias');

        expect(screen.getByText('Limite 2')).toBeInTheDocument();
        // Botão "Demo" em cada linha de avaliador (controle discreto e rotulado).
        expect(screen.getAllByText('Demo')).toHaveLength(2);
        expect(screen.getByText('Limpar dados de teste')).toBeInTheDocument();
        expect(screen.getAllByText('Em avaliação').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Já avaliou').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Faltam').length).toBeGreaterThan(0);
    });

    it('ordena os avaliadores da área por cada métrica, nos dois sentidos', async () => {
        render(<AvaliacaoAvaliadores />);
        await abrirArea('Ciências Agrárias');

        const nomes = () => screen.getAllByRole('listitem').map((li) => li.querySelector('span.truncate').textContent);
        expect(nomes()).toEqual(['Ana', 'Bruno']); // nome A–Z

        const ordenar = screen.getByLabelText('Ordenar avaliadores de Ciências Agrárias');
        fireEvent.change(ordenar, { target: { value: 'faltam:desc' } });
        expect(nomes()).toEqual(['Bruno', 'Ana']);

        fireEvent.change(ordenar, { target: { value: 'avaliou:desc' } });
        expect(nomes()).toEqual(['Ana', 'Bruno']);

        fireEvent.change(ordenar, { target: { value: 'em_avaliacao:asc' } });
        expect(nomes()).toEqual(['Bruno', 'Ana']);
    });

    it('cada área tem a sua própria ordenação', async () => {
        render(<AvaliacaoAvaliadores />);
        fireEvent.click(await screen.findByText('Expandir todas'));

        expect(screen.getByLabelText('Ordenar avaliadores de Ciências Agrárias')).toBeInTheDocument();
        expect(screen.getByLabelText('Ordenar avaliadores de Ciências Exatas')).toBeInTheDocument();
    });

    it('abre o modal de limitar avaliador', async () => {
        render(<AvaliacaoAvaliadores />);
        await abrirArea('Ciências Agrárias');

        fireEvent.click(screen.getAllByTitle('Limitar avaliador')[0]);
        expect(await screen.findByText('Limitar avaliador')).toBeInTheDocument();
    });
});
