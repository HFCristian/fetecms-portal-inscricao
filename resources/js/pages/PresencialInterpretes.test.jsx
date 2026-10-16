import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));

let usuario = { id: 1, role: 'admin', conta_temporaria: false };
vi.mock('../lib/auth.jsx', () => ({
    useAuth: () => ({ user: usuario }),
    extractErrors: (e) => ({
        message: e?.response?.data?.message ?? '',
        fields: e?.response?.data?.errors
            ? Object.fromEntries(Object.entries(e.response.data.errors).map(([k, v]) => [k, v[0]]))
            : {},
    }),
}));

const getInterpretes = vi.fn();
const buscarFinalistasInterprete = vi.fn();
const criarInterprete = vi.fn();
const atualizarInterprete = vi.fn();
const excluirInterprete = vi.fn();
const atenderPedidoInterprete = vi.fn();
const exportarInterpretes = vi.fn();
const baixarEtiquetasInterpretes = vi.fn();
vi.mock('../lib/interpretes.js', async (importOriginal) => ({
    ...(await importOriginal()),
    getInterpretes: (...a) => getInterpretes(...a),
    buscarFinalistasInterprete: (...a) => buscarFinalistasInterprete(...a),
    criarInterprete: (...a) => criarInterprete(...a),
    atualizarInterprete: (...a) => atualizarInterprete(...a),
    excluirInterprete: (...a) => excluirInterprete(...a),
    atenderPedidoInterprete: (...a) => atenderPedidoInterprete(...a),
    exportarInterpretes: (...a) => exportarInterpretes(...a),
    baixarEtiquetasInterpretes: (...a) => baixarEtiquetasInterpretes(...a),
}));

import PresencialInterpretes from './PresencialInterpretes.jsx';

const TIPOS = [
    { value: 'espanhol', label: 'Espanhol', grupo: 'lingua' },
    { value: 'lingua_outra', label: 'Outra língua', grupo: 'lingua' },
    { value: 'libras', label: 'Libras', grupo: 'pcd' },
    { value: 'pcd_outro', label: 'Outro atendimento', grupo: 'pcd' },
];

const helena = () => ({
    id: 7, nome: 'Helena Souza', cpf: '529.982.247-25', cpf_digitos: '52998224725',
    data_nascimento: null, data_nascimento_label: null, tipos: ['libras'], tipos_labels: ['Libras'],
    outra_lingua: null, outro_pcd: null, codigo: '2026-0-529-I7',
    vinculos: [{
        id: 1, projeto_id: 31, projeto_titulo: 'Bioplástico', codigo: 'FET.AGR-001', na_lista: true,
        pessoa_tipo: null, pessoa_id: null, pessoa_nome: null, pessoa_papel: null, origem: 'mapa', turno: 'A', numero: 12,
    }],
    locais: [{ turno: 'A', numero: 12 }],
    locais_label: 'Turno A · Estande 012',
});

const painel = (over = {}) => ({ interpretes: [helena()], tipos: TIPOS, pedidos: [], tem_lista: true, ...over });

describe('PresencialInterpretes', () => {
    beforeEach(() => {
        usuario = { id: 1, role: 'admin', conta_temporaria: false };
        getInterpretes.mockReset().mockResolvedValue(painel());
        buscarFinalistasInterprete.mockReset().mockResolvedValue([]);
        criarInterprete.mockReset().mockResolvedValue({ data: helena(), meta: { message: 'Intérprete cadastrado.' } });
        atualizarInterprete.mockReset().mockResolvedValue({ data: helena(), meta: { message: 'Intérprete atualizado.' } });
        excluirInterprete.mockReset();
        atenderPedidoInterprete.mockReset();
        exportarInterpretes.mockReset().mockResolvedValue(undefined);
        baixarEtiquetasInterpretes.mockReset().mockResolvedValue(undefined);
    });

    it('lista o cadastro com tipos, vínculos, onde atende e o código', async () => {
        render(<PresencialInterpretes />);

        expect(await screen.findByText('Helena Souza')).toBeInTheDocument();
        expect(screen.getByText('Libras')).toBeInTheDocument();
        expect(screen.getByText('FET.AGR-001 · Bioplástico (projeto inteiro)')).toBeInTheDocument();
        expect(screen.getByText('Turno A · Estande 012')).toBeInTheDocument();
        expect(screen.getByText('2026-0-529-I7')).toBeInTheDocument();
    });

    it('cadastra só com nome e CPF, com vínculo escolhido na busca', async () => {
        buscarFinalistasInterprete.mockResolvedValue([{
            id: 40, titulo: 'Sensor de nível', codigo: 'FET.ENG-002', turno: 'B', numero: 3, area: 'Engenharias',
            pessoas: [{ tipo: 'aluno', id: 90, nome: 'Ana Surda', papel_label: 'Estudante' }],
        }]);

        render(<PresencialInterpretes />);
        fireEvent.click(await screen.findByRole('button', { name: /Novo intérprete/ }));

        const dialogo = screen.getByRole('dialog', { name: 'Novo intérprete' });
        fireEvent.change(within(dialogo).getByLabelText('Nome completo'), { target: { value: 'Bruno Lima' } });
        fireEvent.change(within(dialogo).getByLabelText('CPF'), { target: { value: '11144477735' } });
        fireEvent.change(within(dialogo).getByLabelText('Buscar finalista para vincular'), { target: { value: 'sensor' } });

        fireEvent.click(await within(dialogo).findByRole('button', { name: 'Ana Surda · Estudante' }));
        expect(within(dialogo).getByText('Ana Surda (Estudante) — FET.ENG-002 · Sensor de nível')).toBeInTheDocument();

        fireEvent.click(within(dialogo).getByRole('button', { name: 'Salvar' }));

        await waitFor(() => expect(criarInterprete).toHaveBeenCalledWith(expect.objectContaining({
            nome: 'Bruno Lima',
            cpf: '111.444.777-35',
            tipos: [],
            vinculos: [{ projeto_id: 40, pessoa_tipo: 'aluno', pessoa_id: 90 }],
        })));
        expect(await screen.findByText('Intérprete cadastrado.')).toBeInTheDocument();
    });

    it('marcar "Outros" pede qual', async () => {
        render(<PresencialInterpretes />);
        fireEvent.click(await screen.findByRole('button', { name: /Novo intérprete/ }));

        const dialogo = screen.getByRole('dialog', { name: 'Novo intérprete' });
        expect(within(dialogo).queryByLabelText('Qual outra língua')).not.toBeInTheDocument();
        fireEvent.click(within(dialogo).getAllByLabelText('Outros')[0]);
        expect(within(dialogo).getByLabelText('Qual outra língua')).toBeInTheDocument();
    });

    it('editar mantém os vínculos que já existiam, inclusive os do mapa', async () => {
        render(<PresencialInterpretes />);
        fireEvent.click(await screen.findByRole('button', { name: 'Editar Helena Souza' }));

        const dialogo = screen.getByRole('dialog', { name: 'Editar intérprete' });
        expect(within(dialogo).getByText('· pelo mapa')).toBeInTheDocument();
        fireEvent.click(within(dialogo).getByRole('button', { name: 'Salvar' }));

        await waitFor(() => expect(atualizarInterprete).toHaveBeenCalledWith(7, expect.objectContaining({
            vinculos: [{ projeto_id: 31, pessoa_tipo: null, pessoa_id: null }],
        })));
    });

    it('vincula um intérprete ao pedido de suporte sem atendimento', async () => {
        getInterpretes.mockResolvedValue(painel({
            pedidos: [{
                id: 5, tipo: 'interprete_libras', tipo_label: 'Intérprete de Libras', idioma: null,
                projeto_id: 40, projeto_titulo: 'Sensor de nível', codigo: 'FET.ENG-002', aluno: 'Ana', turno: 'B', numero: 3,
            }],
        }));
        atenderPedidoInterprete.mockResolvedValue({ data: painel(), meta: { message: 'Intérprete vinculado ao pedido.' } });

        render(<PresencialInterpretes />);

        expect(await screen.findByText(/Pedidos de intérprete sem atendimento/)).toBeInTheDocument();
        fireEvent.change(screen.getByLabelText('Intérprete para Sensor de nível'), { target: { value: '7' } });
        fireEvent.click(screen.getByRole('button', { name: 'Vincular' }));

        await waitFor(() => expect(atenderPedidoInterprete).toHaveBeenCalledWith(7, 5));
        expect(await screen.findByText('Intérprete vinculado ao pedido.')).toBeInTheDocument();
    });

    it('exporta a lista e as etiquetas', async () => {
        render(<PresencialInterpretes />);
        await screen.findByText('Helena Souza');

        fireEvent.click(screen.getByRole('button', { name: 'Excel' }));
        fireEvent.click(screen.getByRole('button', { name: /Etiquetas \(PDF\)/ }));

        await waitFor(() => expect(exportarInterpretes).toHaveBeenCalledWith('xlsx'));
        expect(baixarEtiquetasInterpretes).toHaveBeenCalledWith();
    });

    it('a conta temporária vê só o atalho do check-in, sem carregar o cadastro', async () => {
        usuario = { id: 2, role: 'admin', conta_temporaria: true };

        render(<PresencialInterpretes />);

        expect(screen.getByText('Check-in')).toBeInTheDocument();
        expect(screen.queryByText('Mapa de designação')).not.toBeInTheDocument();
        expect(screen.queryByText('Acompanhamento')).not.toBeInTheDocument();
        expect(getInterpretes).not.toHaveBeenCalled();
    });
});
