import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({ extractErrors: () => ({ message: '', fields: {} }) }));
vi.mock('../lib/catalogos.js', () => ({
    loadAreas: vi.fn(() => Promise.resolve([])),
    loadSubareas: vi.fn(() => Promise.resolve([])),
}));
vi.mock('../lib/admin.js', () => ({
    getAvaliacaoProjetos: vi.fn(() => Promise.resolve([
        {
            area_id: 1,
            area: 'Ciências Agrárias',
            projetos: [
                { id: 1, titulo: 'Projeto X', realizadas: 2, em_avaliacao: 1, faltantes: 1 },
                { id: 2, titulo: 'Projeto Y', realizadas: 0, em_avaliacao: 0, faltantes: 3 },
            ],
        },
        {
            area_id: 2,
            area: 'Ciências Exatas',
            projetos: [{ id: 3, titulo: 'Projeto Z', realizadas: 3, em_avaliacao: 0, faltantes: 0 }],
        },
    ])),
    // Vem agrupado por área e fora de ordem alfabética de propósito.
    getAvaliacaoAvaliadores: vi.fn(() => Promise.resolve([
        { area_id: 2, area: 'Ciências Exatas', avaliadores: [{ id: 20, nome: 'Zilda Rocha' }, { id: 21, nome: 'Bruno Alves' }] },
        { area_id: 1, area: 'Ciências Agrárias', avaliadores: [{ id: 10, nome: 'Ana Lima' }] },
    ])),
    designarProjeto: vi.fn(() => Promise.resolve({ data: { designadas: 1 }, meta: { message: '1 designação criada.' } })),
}));

import { designarProjeto } from '../lib/admin.js';
import AvaliacaoProjetos from './AvaliacaoProjetos.jsx';

const abrirArea = async (nome) => {
    fireEvent.click(await screen.findByText(nome));
};

describe('AvaliacaoProjetos', () => {
    it('começa com as áreas compactadas', async () => {
        render(<AvaliacaoProjetos />);
        expect(await screen.findByText('Ciências Agrárias')).toBeInTheDocument();
        expect(screen.queryByText('Projeto X')).not.toBeInTheDocument();
    });

    it('abre e fecha a lista da área ao clicar no nome dela', async () => {
        render(<AvaliacaoProjetos />);

        await abrirArea('Ciências Agrárias');
        expect(screen.getByText('Projeto X')).toBeInTheDocument();
        expect(screen.getByText('Projeto Y')).toBeInTheDocument();
        // A outra área continua fechada — cada uma abre sozinha.
        expect(screen.queryByText('Projeto Z')).not.toBeInTheDocument();

        fireEvent.click(screen.getByText('Ciências Agrárias'));
        expect(screen.queryByText('Projeto X')).not.toBeInTheDocument();
    });

    it('expande e recolhe todas as áreas de uma vez', async () => {
        render(<AvaliacaoProjetos />);
        fireEvent.click(await screen.findByText('Expandir todas'));

        expect(screen.getByText('Projeto X')).toBeInTheDocument();
        expect(screen.getByText('Projeto Z')).toBeInTheDocument();

        fireEvent.click(screen.getByText('Recolher todas'));
        expect(screen.queryByText('Projeto X')).not.toBeInTheDocument();
    });

    it('mostra as 3 métricas por projeto', async () => {
        render(<AvaliacaoProjetos />);
        await abrirArea('Ciências Agrárias');

        expect(screen.getAllByText('Realizadas').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Em avaliação').length).toBeGreaterThan(0);
        expect(screen.getAllByText('Faltantes').length).toBeGreaterThan(0);
    });

    it('ordena os projetos da área por cada métrica, nos dois sentidos', async () => {
        render(<AvaliacaoProjetos />);
        await abrirArea('Ciências Agrárias');

        const titulos = () => screen.getAllByRole('listitem').map((li) => li.querySelector('span.truncate').textContent);
        expect(titulos()).toEqual(['Projeto X', 'Projeto Y']); // título A–Z

        const ordenar = screen.getByLabelText('Ordenar projetos de Ciências Agrárias');
        fireEvent.change(ordenar, { target: { value: 'faltantes:desc' } });
        expect(titulos()).toEqual(['Projeto Y', 'Projeto X']);

        fireEvent.change(ordenar, { target: { value: 'realizadas:desc' } });
        expect(titulos()).toEqual(['Projeto X', 'Projeto Y']);

        fireEvent.change(ordenar, { target: { value: 'realizadas:asc' } });
        expect(titulos()).toEqual(['Projeto Y', 'Projeto X']);
    });

    it('cada área tem a sua própria ordenação', async () => {
        render(<AvaliacaoProjetos />);
        fireEvent.click(await screen.findByText('Expandir todas'));

        expect(screen.getByLabelText('Ordenar projetos de Ciências Agrárias')).toBeInTheDocument();
        expect(screen.getByLabelText('Ordenar projetos de Ciências Exatas')).toBeInTheDocument();
    });

    it('busca o avaliador pelo nome no modal de designação', async () => {
        render(<AvaliacaoProjetos />);
        await abrirArea('Ciências Agrárias');
        fireEvent.click(screen.getAllByText('Designar')[0]);

        const busca = await screen.findByPlaceholderText('Digite o nome do avaliador…');
        fireEvent.focus(busca);

        // Lista completa em ordem alfabética, ignorando o agrupamento por área da API.
        const opcoes = [...busca.parentElement.querySelectorAll('ul button')].map((b) => b.textContent);
        expect(opcoes.slice(0, 3)).toEqual([
            'Ana Lima — Ciências Agrárias',
            'Bruno Alves — Ciências Exatas',
            'Zilda Rocha — Ciências Exatas',
        ]);

        fireEvent.change(busca, { target: { value: 'zil' } });
        expect(screen.getByText('Zilda Rocha', { exact: false })).toBeInTheDocument();
        expect(screen.queryByText('Ana Lima', { exact: false })).not.toBeInTheDocument();

        fireEvent.mouseDown(screen.getByText('Zilda Rocha', { exact: false }));
        expect(busca.value).toBe('Zilda Rocha');

        fireEvent.click(screen.getByText('Designar avaliação').closest('div').parentElement.querySelector('button[type="button"]:last-of-type'));
        expect(designarProjeto).toHaveBeenCalledWith(1, { tipo: 'avaliador', alvo_id: 20 });
    });
});
