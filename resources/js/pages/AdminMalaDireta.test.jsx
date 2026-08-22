import { render, screen, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../lib/auth.jsx', () => ({ extractErrors: () => ({ message: 'Erro', fields: {} }) }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));

const MALAS = [
    {
        id: 2,
        nome: 'Lembrete do prazo',
        assunto: 'Falta uma semana',
        status: 'concluida',
        enviado_em: '2026-08-20T10:00:00-04:00',
        autor_nome: 'Pedro Admin',
        totais: { total: 120, enviado: 118, falha: 2, invalido: 0, pendente: 0, processados: 120 },
    },
    {
        id: 1,
        nome: 'Boas-vindas',
        assunto: 'Inscrições abertas',
        status: 'concluida',
        enviado_em: '2026-08-01T09:00:00-04:00',
        autor_nome: 'Pedro Admin',
        totais: { total: 40, enviado: 40, falha: 0, invalido: 0, pendente: 0, processados: 40 },
    },
];

const getMalas = vi.fn(() => Promise.resolve({
    data: MALAS,
    meta: { pagina_atual: 1, ultima_pagina: 1, total: 2 },
}));

vi.mock('../lib/malaDireta.js', () => ({
    getMalas: (...args) => getMalas(...args),
}));

import AdminMalaDireta from './AdminMalaDireta.jsx';

describe('AdminMalaDireta', () => {
    beforeEach(() => {
        getMalas.mockClear();
    });

    it('lista as mensagens disparadas da mais recente para a mais antiga', async () => {
        render(<AdminMalaDireta />);

        await waitFor(() => expect(screen.getByText('Lembrete do prazo')).toBeInTheDocument());
        const titulos = screen.getAllByRole('heading', { level: 2 }).map((h) => h.textContent);
        expect(titulos).toEqual(['Lembrete do prazo', 'Boas-vindas']);
    });

    it('mostra quantos foram enviados e destaca os que tiveram problema', async () => {
        render(<AdminMalaDireta />);

        await waitFor(() => expect(screen.getByText('118 enviados')).toBeInTheDocument());
        expect(screen.getByText('2 com problema')).toBeInTheDocument();
        expect(screen.getByText('120 destinatários')).toBeInTheDocument();
    });

    it('oferece o atalho para compor uma nova mala', async () => {
        render(<AdminMalaDireta />);

        await waitFor(() => expect(screen.getByText('Nova mala direta')).toBeInTheDocument());
        expect(screen.getByText('Nova mala direta').closest('a')).toHaveAttribute('href', '/admin/mala-direta/nova');
    });

    it('avisa quando ainda não houve disparo', async () => {
        getMalas.mockResolvedValueOnce({ data: [], meta: { pagina_atual: 1, ultima_pagina: 1, total: 0 } });
        render(<AdminMalaDireta />);

        await waitFor(() => expect(screen.getByText('Nenhuma mensagem disparada ainda.')).toBeInTheDocument());
    });
});
