import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));

const LISTA = [
    { id: 2, nome: 'XVII FETECMS', ano: 2027, padrao: false, projetos: 0, pode_excluir: true },
    { id: 1, nome: 'XVI FETECMS', ano: 2026, padrao: true, projetos: 12, pode_excluir: false },
];

const getEdicoesAdmin = vi.fn(() => Promise.resolve(LISTA));
const criarEdicao = vi.fn(() => Promise.resolve(LISTA));
const atualizarEdicao = vi.fn(() => Promise.resolve(LISTA));
const definirEdicaoPadrao = vi.fn(() => Promise.resolve(LISTA));
const excluirEdicao = vi.fn(() => Promise.resolve(LISTA));
vi.mock('../lib/edicoes.js', () => ({
    getEdicoesAdmin: (...a) => getEdicoesAdmin(...a),
    criarEdicao: (...a) => criarEdicao(...a),
    atualizarEdicao: (...a) => atualizarEdicao(...a),
    definirEdicaoPadrao: (...a) => definirEdicaoPadrao(...a),
    excluirEdicao: (...a) => excluirEdicao(...a),
}));

import ParametrizacaoEdicoes from './ParametrizacaoEdicoes.jsx';

describe('ParametrizacaoEdicoes', () => {
    beforeEach(() => {
        criarEdicao.mockClear();
        definirEdicaoPadrao.mockClear();
        excluirEdicao.mockClear();
    });

    it('lista as edições marcando a padrão e quantos projetos cada uma tem', async () => {
        render(<ParametrizacaoEdicoes />);

        expect(await screen.findByText('12 projetos')).toBeInTheDocument();
        expect(screen.getByText('padrão')).toBeInTheDocument();
        expect(screen.getByText('12 projetos')).toBeInTheDocument();
        // A padrão não oferece "Tornar padrão" nem "Excluir".
        expect(screen.getAllByRole('button', { name: /Tornar padrão/ })).toHaveLength(1);
        expect(screen.getAllByRole('button', { name: /Excluir/ })).toHaveLength(1);
    });

    it('cria a edição herdando a parametrização da padrão', async () => {
        render(<ParametrizacaoEdicoes />);
        await screen.findByText('12 projetos');

        fireEvent.change(screen.getByPlaceholderText('XVII FETECMS'), { target: { value: 'XVIII FETECMS' } });
        fireEvent.click(screen.getByRole('button', { name: /Criar edição/ }));

        await waitFor(() => expect(criarEdicao).toHaveBeenCalledWith(
            expect.objectContaining({ nome: 'XVIII FETECMS', copiar_de: 1 }),
        ));
    });

    it('deixa desligar a cópia da parametrização', async () => {
        render(<ParametrizacaoEdicoes />);
        await screen.findByText('12 projetos');

        fireEvent.change(screen.getByPlaceholderText('XVII FETECMS'), { target: { value: 'Sem herança' } });
        fireEvent.click(screen.getByRole('switch'));
        fireEvent.click(screen.getByRole('button', { name: /Criar edição/ }));

        await waitFor(() => expect(criarEdicao).toHaveBeenCalledWith(
            expect.objectContaining({ nome: 'Sem herança', copiar_de: null }),
        ));
    });
});
