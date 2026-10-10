import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
const navigate = vi.fn();
vi.mock('react-router-dom', () => ({
    Link: ({ children, to }) => <a href={to}>{children}</a>,
    useNavigate: () => navigate,
}));
vi.mock('../lib/auth.jsx', () => ({ extractErrors: () => ({ message: 'erro', fields: {} }) }));

const LISTAS = [
    {
        id: 3, nome: 'Final 2026', tipo: 'final', vigente: true, rascunho: false, versao: 2, projetos: 120,
        gerada_por: 'Ana Admin', gerada_em: '2026-09-02T10:00:00-04:00', criada_em: '2026-09-01T10:00:00-04:00',
        origens: [{ id: 5, nome: 'Preliminar FUNDECT' }], descricao: 'Aprovada na reunião de 30/09',
    },
    {
        id: 1, nome: 'Final antiga', tipo: 'final', vigente: false, rascunho: false, versao: 1, projetos: 100,
        gerada_por: 'Ana Admin', gerada_em: '2026-08-20T10:00:00-04:00', criada_em: '2026-08-20T10:00:00-04:00', origens: [],
    },
    {
        id: 5, nome: 'Preliminar FUNDECT', tipo: 'preliminar', vigente: false, rascunho: false, versao: 1, projetos: 40,
        gerada_por: 'Ana Admin', gerada_em: '2026-08-25T10:00:00-04:00', criada_em: '2026-08-25T10:00:00-04:00', origens: [],
    },
    {
        id: 6, nome: 'Preliminar em revisão', tipo: 'preliminar', vigente: false, rascunho: true, versao: 1, projetos: 30,
        gerada_por: 'Ana Admin', gerada_em: null, criada_em: '2026-08-26T10:00:00-04:00', origens: [],
    },
];

const getListasFinais = vi.fn();
const baixarListaOficial = vi.fn(() => Promise.resolve());
const montarListaPorUniao = vi.fn(() => Promise.resolve({ lista: { id: 9 } }));
const reativarListaFinal = vi.fn(() => Promise.resolve({ data: {}, meta: { message: 'Esta lista final voltou a ser a ativa.' } }));
vi.mock('../lib/admin.js', () => ({
    getListasFinais: (...a) => getListasFinais(...a),
    baixarListaOficial: (...a) => baixarListaOficial(...a),
    montarListaPorUniao: (...a) => montarListaPorUniao(...a),
    reativarListaFinal: (...a) => reativarListaFinal(...a),
}));

import AvaliacaoListasFinais from './AvaliacaoListasFinais.jsx';

describe('AvaliacaoListasFinais', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        getListasFinais.mockResolvedValue(LISTAS);
    });

    it('separa finais e preliminares, com a ativa marcada e a origem da final', async () => {
        render(<AvaliacaoListasFinais />);

        expect(await screen.findByText('Final 2026')).toBeInTheDocument();
        // As etiquetas das listas (o texto do topo também fala em "ativa").
        const etiqueta = (txt) => screen.getAllByText(txt).filter((e) => e.tagName === 'SPAN');
        expect(etiqueta('ativa')).toHaveLength(1);
        expect(etiqueta('inativa')).toHaveLength(1);
        expect(screen.getByText(/Montada das listas: Preliminar FUNDECT/)).toBeInTheDocument();
        expect(screen.getByText('Aprovada na reunião de 30/09')).toBeInTheDocument();
        expect(screen.getByText('Preliminar em revisão')).toBeInTheDocument();
        expect(etiqueta('rascunho')).toHaveLength(1);
    });

    it('baixa o TXT da lista escolhida', async () => {
        render(<AvaliacaoListasFinais />);
        await screen.findByText('Final 2026');

        fireEvent.click(screen.getAllByRole('button', { name: /TXT/ })[0]);

        await waitFor(() => expect(baixarListaOficial).toHaveBeenCalledWith(3));
    });

    it('monta uma lista final a partir de finais e preliminares geradas', async () => {
        render(<AvaliacaoListasFinais />);
        fireEvent.click(await screen.findByRole('button', { name: /Montar a partir de outras listas/ }));

        const dialogo = screen.getByRole('dialog');
        // O rascunho não é oferecido; a final ativa e a inativa, sim.
        expect(within(dialogo).queryByText(/Preliminar em revisão/)).not.toBeInTheDocument();
        fireEvent.click(within(dialogo).getByLabelText('Final 2026'));
        fireEvent.click(within(dialogo).getByLabelText('Preliminar FUNDECT'));
        fireEvent.change(within(dialogo).getByLabelText('Apelido da lista'), { target: { value: 'Final com suplentes' } });
        fireEvent.change(within(dialogo).getByLabelText('Descrição da lista'), { target: { value: 'Final de setembro + FUNDECT' } });
        fireEvent.click(within(dialogo).getByRole('button', { name: 'Montar rascunho' }));

        await waitFor(() => expect(montarListaPorUniao).toHaveBeenCalledWith({
            listas: [3, 5], tipo: 'final', nome: 'Final com suplentes', descricao: 'Final de setembro + FUNDECT',
        }));
        expect(navigate).toHaveBeenCalledWith('/admin/avaliacao/listas-finais/9');
    });

    it('monta uma lista preliminar a partir de uma final', async () => {
        render(<AvaliacaoListasFinais />);
        fireEvent.click(await screen.findByRole('button', { name: /Montar a partir de outras listas/ }));

        const dialogo = screen.getByRole('dialog');
        fireEvent.click(within(dialogo).getByLabelText('Lista preliminar'));
        fireEvent.click(within(dialogo).getByLabelText('Final antiga'));
        fireEvent.click(within(dialogo).getByRole('button', { name: 'Montar rascunho' }));

        await waitFor(() => expect(montarListaPorUniao).toHaveBeenCalledWith({
            listas: [1], tipo: 'preliminar', nome: null, descricao: null,
        }));
    });

    it('reativa uma final antiga com justificativa', async () => {
        render(<AvaliacaoListasFinais />);
        fireEvent.click(await screen.findByRole('button', { name: /Tornar ativa/ }));

        const confirmar = screen.getAllByRole('button', { name: 'Tornar ativa' }).at(-1);
        expect(confirmar).toBeDisabled();
        fireEvent.change(screen.getByLabelText('Justificativa da troca'), { target: { value: 'Recurso deferido.' } });
        fireEvent.click(confirmar);

        await waitFor(() => expect(reativarListaFinal).toHaveBeenCalledWith(1, 'Recurso deferido.'));
    });
});
