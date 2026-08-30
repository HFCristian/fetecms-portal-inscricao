import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));

const navigate = vi.fn();
vi.mock('react-router-dom', () => ({
    Link: ({ children, to }) => <a href={to}>{children}</a>,
    useNavigate: () => navigate,
}));

const RESPOSTA = {
    data: [
        {
            id: 10, titulo: 'Bioplástico de mandioca', categoria_label: 'FETECMS',
            area: 'Ciências Agrárias', orientador: 'Ana Orientadora', alunos: 3,
            pendencias: 0, pronto: true,
        },
        {
            id: 11, titulo: 'Robô seguidor', categoria_label: 'FETEC Jr',
            area: 'Engenharias', orientador: 'Bruno Silva', alunos: 1,
            pendencias: 4, pronto: false,
        },
    ],
    meta: {
        pagina_atual: 1, ultima_pagina: 1, total: 2,
        areas: [{ id: 1, nome: 'Ciências Agrárias' }],
        categorias: [{ value: 'fetecms', label: 'FETECMS' }],
        inscricoes: { encerradas: true },
    },
};

const getProjetosRascunho = vi.fn(() => Promise.resolve(RESPOSTA));
vi.mock('../lib/admin.js', () => ({ getProjetosRascunho: (...a) => getProjetosRascunho(...a) }));

import AdminProjetosRascunho from './AdminProjetosRascunho.jsx';

describe('AdminProjetosRascunho', () => {
    beforeEach(() => {
        navigate.mockClear();
        getProjetosRascunho.mockClear();
    });

    it('lista os rascunhos com orientador, equipe e situação do checklist', async () => {
        render(<AdminProjetosRascunho />);

        expect(await screen.findByText('Bioplástico de mandioca')).toBeInTheDocument();
        expect(screen.getByText(/Ana Orientadora · FETECMS · Ciências Agrárias/)).toBeInTheDocument();
        expect(screen.getByText('pronto para submeter')).toBeInTheDocument();
        expect(screen.getByText('4 pendências')).toBeInTheDocument();
    });

    it('leva ao resumo quando está pronto e ao formulário quando falta preencher', async () => {
        render(<AdminProjetosRascunho />);

        const botoes = await screen.findAllByRole('button', { name: /Submeter/ });
        fireEvent.click(botoes[0]);
        expect(navigate).toHaveBeenCalledWith('/admin/projetos-rascunho/10/resumo');

        fireEvent.click(botoes[1]);
        expect(navigate).toHaveBeenCalledWith('/admin/projetos-rascunho/11/editar');
    });

    it('manda a busca para a API', async () => {
        render(<AdminProjetosRascunho />);
        await screen.findByText('Bioplástico de mandioca');

        fireEvent.change(screen.getByPlaceholderText(/Buscar por título/), { target: { value: 'robô' } });

        await waitFor(() => {
            expect(getProjetosRascunho).toHaveBeenLastCalledWith({ page: 1, busca: 'robô' });
        });
    });
});
