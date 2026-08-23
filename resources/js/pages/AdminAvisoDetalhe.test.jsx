import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({
    Link: ({ children, to }) => <a href={to}>{children}</a>,
    useParams: () => ({ id: '9' }),
}));

const getAviso = vi.fn();
const getAvisoLeitores = vi.fn();
const exportarAvisoCsv = vi.fn(() => Promise.resolve());
vi.mock('../lib/admin.js', () => ({
    getAviso: (...a) => getAviso(...a),
    getAvisoLeitores: (...a) => getAvisoLeitores(...a),
    exportarAvisoCsv: (...a) => exportarAvisoCsv(...a),
}));

import AdminAvisoDetalhe from './AdminAvisoDetalhe.jsx';

const AVISO = {
    id: 9,
    titulo: 'As inscrições estão se encerrando',
    mensagem: 'Faltam 45 minutos.',
    titulo_original: 'As inscrições estão se encerrando',
    mensagem_original: 'Faltam {{tempo_restante}}.',
    autor_nome: 'Admin FETECMS',
    publicado_em: '22/08/2026 19:00',
    encerrado_em: null,
    ativo: true,
    destinatarios: 3,
    vistos: 2,
    fechados: 1,
    nao_vistos: 1,
};

const LEITORES = {
    data: [
        { id: 1, nome: 'Ana Lima', email: 'ana@fetecms.test', situacao: 'fechado', situacao_label: 'Fechou o aviso', visto_em: '22/08/2026 19:05', fechado_em: '22/08/2026 19:06' },
        { id: 2, nome: 'Bruno Alves', email: 'bruno@fetecms.test', situacao: 'visto', situacao_label: 'Viu e deixou aberto', visto_em: '22/08/2026 19:10', fechado_em: null },
        { id: 3, nome: 'Carla Souza', email: 'carla@fetecms.test', situacao: 'nao_visto', situacao_label: 'Ainda não viu', visto_em: null, fechado_em: null },
    ],
    meta: { pagina: 1, ultima_pagina: 1, total: 3 },
};

describe('AdminAvisoDetalhe', () => {
    beforeEach(() => {
        getAviso.mockReset();
        getAvisoLeitores.mockReset();
        exportarAvisoCsv.mockClear();
        getAviso.mockResolvedValue({ data: AVISO });
        getAvisoLeitores.mockResolvedValue(LEITORES);
    });

    it('mostra o aviso e os quatro números do relatório', async () => {
        render(<AdminAvisoDetalhe />);

        expect(await screen.findByText('As inscrições estão se encerrando')).toBeInTheDocument();
        expect(screen.getByText('No ar')).toBeInTheDocument();
        expect(screen.getByText('Orientadores ativos')).toBeInTheDocument();
        // "Viram"/"Fecharam"/"Não viram" aparecem duas vezes: no número e no filtro.
        expect(screen.getAllByText('Viram')).toHaveLength(2);
        expect(screen.getAllByText('Fecharam')).toHaveLength(2);
        expect(screen.getAllByText('Não viram')).toHaveLength(2);
    });

    it('lista cada pessoa com a sua situação', async () => {
        render(<AdminAvisoDetalhe />);

        expect(await screen.findByText('Ana Lima')).toBeInTheDocument();
        expect(screen.getByText('Fechou o aviso')).toBeInTheDocument();
        expect(screen.getByText('Viu e deixou aberto')).toBeInTheDocument();
        expect(screen.getByText('Ainda não viu')).toBeInTheDocument();
        expect(screen.getByText('Fechou em 22/08/2026 19:06')).toBeInTheDocument();
        expect(screen.getByText('Viu em 22/08/2026 19:10')).toBeInTheDocument();
    });

    it('filtra por situação', async () => {
        render(<AdminAvisoDetalhe />);

        await screen.findByText('Ana Lima');
        fireEvent.click(screen.getByRole('button', { name: 'Não viram' }));

        await waitFor(() => expect(getAvisoLeitores).toHaveBeenLastCalledWith('9', { situacao: 'nao_visto', q: '', page: 1 }));
    });

    it('busca por nome ou e-mail', async () => {
        render(<AdminAvisoDetalhe />);

        fireEvent.change(await screen.findByLabelText('Buscar por nome ou e-mail'), { target: { value: ' bruno ' } });
        fireEvent.click(screen.getByText('Buscar'));

        await waitFor(() => expect(getAvisoLeitores).toHaveBeenLastCalledWith('9', { situacao: '', q: 'bruno', page: 1 }));
    });

    it('exporta o CSV do mesmo recorte da tela', async () => {
        render(<AdminAvisoDetalhe />);

        await screen.findByText('Ana Lima');
        fireEvent.click(screen.getByRole('button', { name: 'Fecharam' }));
        fireEvent.click(screen.getByText('Exportar CSV'));

        await waitFor(() => expect(exportarAvisoCsv).toHaveBeenCalledWith('9', { situacao: 'fechado', q: '' }));
    });

    it('mostra o texto original quando o aviso usa variáveis', async () => {
        render(<AdminAvisoDetalhe />);

        expect(await screen.findByText('Ver o texto com as variáveis')).toBeInTheDocument();
        expect(screen.getByText('Faltam {{tempo_restante}}.')).toBeInTheDocument();
    });

    it('avisa quando o recorte não tem ninguém', async () => {
        getAvisoLeitores.mockResolvedValue({ data: [], meta: { pagina: 1, ultima_pagina: 1, total: 0 } });
        render(<AdminAvisoDetalhe />);

        expect(await screen.findByText('Ninguém neste recorte.')).toBeInTheDocument();
    });
});
