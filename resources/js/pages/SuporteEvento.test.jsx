import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.response?.data?.message ?? '', fields: e?.response?.data?.errors ?? {} }),
}));

const TIPOS = [
    { value: 'acompanhante', label: 'Acompanhante' },
    { value: 'interprete_libras', label: 'Intérprete de Libras' },
    { value: 'interprete_lingua', label: 'Intérprete de outra língua' },
];
const PAINEL = {
    janela: { aberta: true, tem_lista: true, is_demo: false, tipos: TIPOS },
    projetos: [{
        id: 3, titulo: 'Biofiltro', area: 'Agrárias', categoria: 'FETECMS',
        alunos: [{ id: 30, nome: 'Ana Aluna' }],
        suportes: [{
            id: 9, tipo: 'interprete_libras', tipo_label: 'Intérprete de Libras', aluno_id: 30, aluno: 'Ana Aluna',
            status: 'recusado', status_label: 'Recusado', motivo: 'A organização já tem intérprete no turno.',
            resumo: 'Intérprete de Libras — para Ana Aluna',
        }],
    }],
};

const getSuporte = vi.fn();
const pedirSuporte = vi.fn();
vi.mock('../lib/suporte.js', () => ({
    getSuporte: (...a) => getSuporte(...a),
    pedirSuporte: (...a) => pedirSuporte(...a),
    alterarSuporte: vi.fn(),
    excluirSuporte: vi.fn(),
}));

import SuporteEvento from './SuporteEvento.jsx';

describe('SuporteEvento', () => {
    beforeEach(() => {
        getSuporte.mockReset().mockResolvedValue(PAINEL);
        pedirSuporte.mockReset().mockResolvedValue({ data: PAINEL, meta: { message: 'Pedido enviado. A organização vai analisar.' } });
    });

    it('mostra os pedidos com a situação e o motivo da recusa', async () => {
        render(<SuporteEvento />);

        expect(await screen.findByText('Intérprete de Libras — para Ana Aluna')).toBeInTheDocument();
        expect(screen.getByText(/A organização já tem intérprete/)).toBeInTheDocument();
    });

    it('pede acompanhante com nome, documento e vínculo', async () => {
        render(<SuporteEvento />);

        fireEvent.click(await screen.findByRole('button', { name: /Pedir suporte/ }));
        fireEvent.change(screen.getByLabelText('Estudante'), { target: { value: '30' } });
        fireEvent.change(screen.getByLabelText('Nome completo do acompanhante'), { target: { value: 'Maria Aluna' } });
        fireEvent.change(screen.getByLabelText('Documento do acompanhante'), { target: { value: 'RG 123' } });
        fireEvent.change(screen.getByLabelText('Vínculo com o estudante'), { target: { value: 'Mãe' } });
        fireEvent.click(screen.getByRole('button', { name: 'Enviar pedido' }));

        await waitFor(() => expect(pedirSuporte).toHaveBeenCalledWith(3, expect.objectContaining({
            tipo: 'acompanhante', aluno_id: '30', acompanhante_nome: 'Maria Aluna',
            acompanhante_documento: 'RG 123', acompanhante_vinculo: 'Mãe',
        }), false));
        expect(await screen.findByText(/Pedido enviado/)).toBeInTheDocument();
    });

    it('sem lista final, explica', async () => {
        getSuporte.mockResolvedValue({ janela: { aberta: false, tem_lista: false, tipos: TIPOS }, projetos: [] });
        render(<SuporteEvento />);

        expect(await screen.findByText(/A lista final ainda não saiu/)).toBeInTheDocument();
    });
});
