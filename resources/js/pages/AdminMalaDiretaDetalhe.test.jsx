import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../lib/auth.jsx', () => ({ extractErrors: () => ({ message: 'Erro', fields: {} }) }));
vi.mock('react-router-dom', () => ({
    Link: ({ children, to }) => <a href={to}>{children}</a>,
    useParams: () => ({ id: '7' }),
}));

const SITUACOES = [
    { value: 'pendente', label: 'Na fila' },
    { value: 'enviado', label: 'Enviado' },
    { value: 'falha', label: 'Falha no envio' },
    { value: 'invalido', label: 'E-mail inválido' },
];

const malaConcluida = {
    id: 7,
    nome: 'Lembrete do prazo',
    assunto: 'Falta uma semana',
    justificativa: 'O prazo fecha sexta.',
    solicitante: 'Coordenação',
    corpo: 'Olá!',
    status: 'concluida',
    publicos_labels: ['Todos os usuários'],
    emails_personalizados: 1,
    enviado_em: '2026-08-20T10:00:00-04:00',
    autor_nome: 'Pedro Admin',
    totais: { total: 3, enviado: 1, falha: 1, invalido: 1, pendente: 0, processados: 3 },
};

const DESTINATARIOS = [
    {
        id: 1, email: 'falhou@escola.test', nome: 'Ana', papel_label: 'Orientador',
        origens_labels: ['Todos os usuários'], projetos_total: 1, status: 'falha',
        status_label: 'Falha no envio', erro: 'Caixa postal inexistente', enviado_em: null,
    },
    {
        id: 2, email: 'sem-arroba', nome: null, papel_label: null,
        origens_labels: ['Lista personalizada'], projetos_total: 0, status: 'invalido',
        status_label: 'E-mail inválido', erro: 'Endereço de e-mail inválido.', enviado_em: null,
    },
];

const getMala = vi.fn(() => Promise.resolve(malaConcluida));
const getMalaDestinatarios = vi.fn(() => Promise.resolve({
    data: DESTINATARIOS,
    meta: { pagina_atual: 1, ultima_pagina: 1, total: 2, situacoes: SITUACOES },
}));
const exportarMalaCsv = vi.fn(() => Promise.resolve());
const reenviarFalhasMala = vi.fn(() => Promise.resolve({
    data: { ...malaConcluida, status: 'enviando', totais: { ...malaConcluida.totais, falha: 0, pendente: 1, processados: 2 } },
    meta: { reenviados: 1 },
}));

vi.mock('../lib/malaDireta.js', () => ({
    getMala: (...a) => getMala(...a),
    getMalaDestinatarios: (...a) => getMalaDestinatarios(...a),
    exportarMalaCsv: (...a) => exportarMalaCsv(...a),
    reenviarFalhasMala: (...a) => reenviarFalhasMala(...a),
}));

import AdminMalaDiretaDetalhe from './AdminMalaDiretaDetalhe.jsx';

describe('AdminMalaDiretaDetalhe', () => {
    beforeEach(() => {
        getMala.mockClear();
        getMala.mockResolvedValue(malaConcluida);
        getMalaDestinatarios.mockClear();
        reenviarFalhasMala.mockClear();
    });

    it('mostra a barra de progresso enquanto a mala está enviando', async () => {
        getMala.mockResolvedValue({
            ...malaConcluida,
            status: 'enviando',
            totais: { total: 4, enviado: 2, falha: 0, invalido: 0, pendente: 2, processados: 2 },
        });
        render(<AdminMalaDiretaDetalhe />);

        await waitFor(() => expect(screen.getByText('Enviando as mensagens…')).toBeInTheDocument());
        expect(screen.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '50');
        expect(screen.getByText(/2 de 4 processados/)).toBeInTheDocument();
    });

    it('não mostra a barra depois que o envio termina', async () => {
        render(<AdminMalaDiretaDetalhe />);

        await waitFor(() => expect(screen.getByText('Lembrete do prazo')).toBeInTheDocument());
        expect(screen.queryByRole('progressbar')).not.toBeInTheDocument();
    });

    it('mostra no relatório o motivo de cada e-mail que não chegou', async () => {
        render(<AdminMalaDiretaDetalhe />);

        await waitFor(() => expect(screen.getByText('Caixa postal inexistente')).toBeInTheDocument());
        expect(screen.getByText('Endereço de e-mail inválido.')).toBeInTheDocument();
        expect(screen.getByText(/2 endereços não receberam a mensagem/)).toBeInTheDocument();
    });

    it('filtra os destinatários por situação', async () => {
        render(<AdminMalaDiretaDetalhe />);
        await waitFor(() => expect(screen.getByText('Falha no envio', { selector: 'button' })).toBeInTheDocument());

        fireEvent.click(screen.getByText('Falha no envio', { selector: 'button' }));

        await waitFor(() => expect(getMalaDestinatarios).toHaveBeenCalledWith('7', expect.objectContaining({ status: 'falha' })));
    });

    it('reenvia as falhas depois de confirmar', async () => {
        render(<AdminMalaDiretaDetalhe />);
        await waitFor(() => expect(screen.getByText('Reenviar falhas')).toBeInTheDocument());

        fireEvent.click(screen.getByText('Reenviar falhas'));
        await waitFor(() => expect(screen.getByText('Reenviar as falhas')).toBeInTheDocument());
        expect(reenviarFalhasMala).not.toHaveBeenCalled();

        fireEvent.click(screen.getByText('Reenviar'));

        await waitFor(() => expect(reenviarFalhasMala).toHaveBeenCalledWith('7'));
        await waitFor(() => expect(screen.getByText('1 e-mail(s) recolocado(s) na fila.')).toBeInTheDocument());
    });

    it('exporta o relatório em CSV', async () => {
        render(<AdminMalaDiretaDetalhe />);
        await waitFor(() => expect(screen.getByText('Exportar CSV')).toBeInTheDocument());

        fireEvent.click(screen.getByText('Exportar CSV'));

        await waitFor(() => expect(exportarMalaCsv).toHaveBeenCalledWith('7'));
    });
});
