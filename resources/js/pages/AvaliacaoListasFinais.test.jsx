import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));

const getListasFinais = vi.fn(() => Promise.resolve([
    {
        id: 3, nome: 'Oficial 2026', vigente: true, versao: 2, projetos: 120,
        gerada_por: 'Ana Admin', criada_em: '2026-09-01T10:00:00-04:00', atualizada_em: '2026-09-02T10:00:00-04:00',
    },
    {
        id: 2, nome: 'Prévia', vigente: false, versao: 1, projetos: 80,
        gerada_por: 'Ana Admin', criada_em: '2026-08-20T10:00:00-04:00', atualizada_em: '2026-08-20T10:00:00-04:00',
    },
]));
const baixarListaOficial = vi.fn(() => Promise.resolve());
vi.mock('../lib/admin.js', () => ({
    getListasFinais: (...a) => getListasFinais(...a),
    baixarListaOficial: (...a) => baixarListaOficial(...a),
}));

import AvaliacaoListasFinais from './AvaliacaoListasFinais.jsx';

describe('AvaliacaoListasFinais', () => {
    it('lista as oficiais marcando a vigente e a versão', async () => {
        render(<AvaliacaoListasFinais />);

        expect(await screen.findByText('Oficial 2026')).toBeInTheDocument();
        // A etiqueta da lista (o texto explicativo do topo também diz "vigente").
        expect(screen.getAllByText('vigente').filter((e) => e.tagName === 'SPAN')).toHaveLength(1);
        expect(screen.getByText(/versão 2 · 120 projetos · gerada por Ana Admin/)).toBeInTheDocument();
        expect(screen.getByText('Prévia')).toBeInTheDocument();
    });

    it('baixa o TXT da lista escolhida', async () => {
        render(<AvaliacaoListasFinais />);
        await screen.findByText('Oficial 2026');

        fireEvent.click(screen.getAllByRole('button', { name: /Baixar TXT/ })[0]);

        await waitFor(() => expect(baixarListaOficial).toHaveBeenCalledWith(3));
    });
});
