import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../lib/auth.jsx', () => ({ extractErrors: () => ({ message: 'Erro', fields: {} }) }));

const REGISTROS = [
    {
        id: 2,
        tipo: 'exclusao',
        tipo_label: 'Exclusão',
        ocorrido_em: '2026-08-10T14:30:00-04:00',
        autor_email: 'ana@escola.test',
        autor_nome: 'Ana Orientadora',
        autor_role: 'orientador',
        projeto_titulo: 'Bioplástico de Mandioca',
        dono_email: 'ana@escola.test',
        por_terceiro: false,
        detalhes_texto: '',
    },
    {
        id: 1,
        tipo: 'submissao',
        tipo_label: 'Submissão',
        ocorrido_em: '2026-08-01T09:00:00-04:00',
        autor_email: 'ana@escola.test',
        autor_nome: 'Ana Orientadora',
        autor_role: 'orientador',
        projeto_titulo: 'Bioplástico de Mandioca',
        dono_email: 'ana@escola.test',
        por_terceiro: false,
        detalhes_texto: '',
    },
];

const META = {
    pagina_atual: 1,
    ultima_pagina: 2,
    total: 2,
    por_pagina: 25,
    totais_por_tipo: { submissao: 1, cancelamento: 0, exclusao: 1, troca_email: 0 },
    tipos: [
        { value: 'submissao', label: 'Submissão' },
        { value: 'cancelamento', label: 'Cancelamento' },
        { value: 'exclusao', label: 'Exclusão' },
        { value: 'troca_email', label: 'Troca de e-mail' },
    ],
};

const getRegistros = vi.fn(() => Promise.resolve({ data: REGISTROS, meta: META }));
const exportarRegistrosCsv = vi.fn(() => Promise.resolve());

vi.mock('../lib/admin.js', () => ({
    getRegistros: (...args) => getRegistros(...args),
    exportarRegistrosCsv: (...args) => exportarRegistrosCsv(...args),
}));

import AdminRegistros from './AdminRegistros.jsx';

describe('AdminRegistros', () => {
    beforeEach(() => {
        getRegistros.mockClear();
        exportarRegistrosCsv.mockClear();
    });

    it('lista os registros com tag, e-mail e projeto', async () => {
        render(<AdminRegistros />);

        // Cada tipo aparece duas vezes: na tag da linha e no filtro do topo.
        expect(await screen.findAllByText('Exclusão')).toHaveLength(2);
        expect(screen.getAllByText('Submissão')).toHaveLength(2);
        expect(screen.getAllByText('ana@escola.test')).toHaveLength(2);
        expect(screen.getAllByText(/Bioplástico de Mandioca/)).toHaveLength(2);
        expect(screen.getByText('2 registros.')).toBeInTheDocument();
        expect(screen.getByText('Página 1 de 2')).toBeInTheDocument();
    });

    it('filtra por tipo ao clicar na tag', async () => {
        render(<AdminRegistros />);
        await screen.findByText('2 registros.');

        fireEvent.click(screen.getByRole('button', { name: 'Filtrar por Exclusão' }));

        await waitFor(() => {
            expect(getRegistros).toHaveBeenLastCalledWith(
                expect.objectContaining({ tipos: ['exclusao'], page: 1 }),
            );
        });
    });

    it('exporta o CSV com os filtros aplicados', async () => {
        render(<AdminRegistros />);
        await screen.findByText('2 registros.');

        fireEvent.click(screen.getByRole('button', { name: /Exportar CSV/ }));

        await waitFor(() => expect(exportarRegistrosCsv).toHaveBeenCalled());
    });

    it('busca por e-mail, nome ou título (com debounce)', async () => {
        render(<AdminRegistros />);
        await screen.findByText('2 registros.');

        fireEvent.change(screen.getByLabelText('Buscar nos registros'), { target: { value: 'ana@' } });

        await waitFor(() => {
            expect(getRegistros).toHaveBeenLastCalledWith(expect.objectContaining({ busca: 'ana@' }));
        });
    });
});
