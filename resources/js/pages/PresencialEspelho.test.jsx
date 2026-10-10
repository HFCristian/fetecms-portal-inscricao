import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({ extractErrors: () => ({ message: 'Erro.' }) }));

const getConfigPresencial = vi.fn();
const getEspelhoChecagem = vi.fn();
vi.mock('../lib/presencial.js', () => ({
    getConfigPresencial: (...a) => getConfigPresencial(...a),
    getEspelhoChecagem: (...a) => getEspelhoChecagem(...a),
    urlTermo: (id) => `/api/v1/documentos/${id}/preview`,
}));

import PresencialEspelho from './PresencialEspelho.jsx';

// O servidor manda só os itens ativos da checagem (lista única, Sprint 181).
const ITENS = [
    { id: 5, nome: 'Banner montado', abrangencia: 'projeto', papeis: [] },
    { id: 6, nome: 'Tomada', abrangencia: 'projeto', papeis: [] },
    { id: 8, nome: 'Crachá', abrangencia: 'pessoa', papeis: ['aluno'] },
];

describe('PresencialEspelho', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        getConfigPresencial.mockResolvedValue({ pode_testar: false, areas: [], categorias: [] });
        getEspelhoChecagem.mockResolvedValue({
            data: [
                {
                    id: 3, titulo: 'Bioplástico', area: 'Exatas', escola: 'EE Alfa', orientador: 'Marta',
                    local: { estande: 42, turno_label: 'Matutino' },
                    conferido_em: '2026-10-20T09:00:00-04:00', conferido_por: 'Admin', observacao: 'Tudo certo.',
                    itens: [{ id: 5, situacao: 'presente' }, { id: 6, situacao: 'ausente' }],
                    itens_pessoa: [{ id: 8, nome: 'Crachá', total: 2, presentes: 1, ausentes: 1 }],
                    termo: { id: 9, nome_original: 'termo.pdf', assinatura_motivo: 'ok' },
                },
                {
                    id: 4, titulo: 'Horta', area: 'Agrárias', escola: 'EE Beta', orientador: 'João',
                    local: { estande: null, turno_label: null },
                    conferido_em: null, conferido_por: null, observacao: null,
                    itens: [{ id: 5, situacao: null }, { id: 6, situacao: null }],
                    itens_pessoa: [{ id: 8, nome: 'Crachá', total: 3, presentes: 3, ausentes: 0 }],
                    termo: null,
                },
            ],
            meta: { itens: ITENS },
        });
    });

    it('monta a tabela com uma coluna por item, e o item de pessoa conta a equipe', async () => {
        render(<PresencialEspelho />);

        expect(await screen.findByText('Bioplástico')).toBeInTheDocument();
        expect(screen.getByRole('columnheader', { name: 'Banner montado' })).toBeInTheDocument();
        expect(screen.getByRole('columnheader', { name: 'Tomada' })).toBeInTheDocument();
        expect(screen.getByRole('columnheader', { name: 'Crachá' })).toBeInTheDocument();
        expect(screen.getByText('1/2')).toBeInTheDocument();
        expect(screen.getByText('3/3')).toBeInTheDocument();
    });

    it('marca presente, ausente e não conferido de formas diferentes', async () => {
        render(<PresencialEspelho />);

        expect(await screen.findByLabelText('Presente')).toBeInTheDocument();
        expect(screen.getByLabelText('Ausente')).toBeInTheDocument();
        // O projeto sem checagem fica com traços, não com zeros.
        expect(screen.getAllByTitle('Não conferido').length).toBe(2);
    });

    it('item enviado antes pelo portal e ainda não conferido aparece como online (Sprint 184)', async () => {
        getEspelhoChecagem.mockResolvedValue({
            data: [{
                id: 3, titulo: 'Bioplástico', area: 'Exatas', escola: 'EE Alfa', orientador: 'Marta',
                local: { estande: 42, turno_label: 'Matutino' }, conferido_em: null, conferido_por: null, observacao: null,
                itens: [{ id: 5, situacao: 'presente', pre_marcado: true, online: [{ id: 9, nome_original: 'termo.pdf' }] }, { id: 6, situacao: null }],
                itens_pessoa: [],
            }],
            meta: { itens: ITENS },
        });
        render(<PresencialEspelho />);

        expect(await screen.findByText('online')).toBeInTheDocument();
        expect(screen.queryByRole('columnheader', { name: 'Termo' })).not.toBeInTheDocument();
    });
});
