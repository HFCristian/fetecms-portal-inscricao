import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.message ?? 'Erro.', fields: e?.fields ?? {} }),
}));

const PAPEIS = [
    { value: 'aluno', label: 'Aluno' },
    { value: 'orientador', label: 'Orientador' },
    { value: 'coorientador', label: 'Coorientador' },
];

const ITENS = [
    {
        id: 1, nome: 'Termo de uso de imagem', descricao: null, abrangencia: 'pessoa', papeis: ['aluno'],
        no_credenciamento: true, na_checagem: false, receber_antes: true, chave: 'termo_uso_imagem',
        ordem: 0, ativo: true, em_uso: false,
    },
    {
        id: 2, nome: 'Banner', descricao: 'Impresso', abrangencia: 'projeto', papeis: [],
        no_credenciamento: false, na_checagem: true, receber_antes: false, chave: null,
        ordem: 1, ativo: true, em_uso: true,
    },
];

const getItensConferencia = vi.fn();
const criarItemConferencia = vi.fn();
const atualizarItemConferencia = vi.fn();
const excluirItemConferencia = vi.fn();
vi.mock('../lib/itensConferencia.js', () => ({
    getItensConferencia: (...a) => getItensConferencia(...a),
    criarItemConferencia: (...a) => criarItemConferencia(...a),
    atualizarItemConferencia: (...a) => atualizarItemConferencia(...a),
    excluirItemConferencia: (...a) => excluirItemConferencia(...a),
}));

import ParametrizacaoItensConferencia from './ParametrizacaoItensConferencia.jsx';

describe('ParametrizacaoItensConferencia', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        getItensConferencia.mockResolvedValue({ itens: ITENS, papeis: PAPEIS });
        criarItemConferencia.mockResolvedValue({ data: { itens: ITENS, papeis: PAPEIS }, meta: { message: 'Item criado.' } });
        atualizarItemConferencia.mockResolvedValue({ data: { itens: ITENS, papeis: PAPEIS }, meta: { message: 'Item atualizado.' } });
    });

    it('lista os itens com de quem são, onde são conferidos e se vêm antes', async () => {
        render(<ParametrizacaoItensConferencia />);

        const termo = (await screen.findByText('Termo de uso de imagem')).closest('li');
        expect(within(termo).getByText('Aluno')).toBeInTheDocument();
        expect(within(termo).getByText('Credenciamento')).toBeInTheDocument();
        expect(within(termo).getByText('Recebido antes')).toBeInTheDocument();
        expect(within(termo).getByText('do sistema')).toBeInTheDocument();
        // Item do sistema e item já usado não se excluem.
        expect(screen.queryByRole('button', { name: 'Excluir Termo de uso de imagem' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Excluir Banner' })).not.toBeInTheDocument();

        const banner = screen.getByText('Banner').closest('li');
        expect(within(banner).getByText('Do projeto')).toBeInTheDocument();
        expect(within(banner).getByText('Checagem')).toBeInTheDocument();
    });

    it('cria um item de pessoa para dois papéis, nos dois balcões e recebido antes', async () => {
        render(<ParametrizacaoItensConferencia />);
        await screen.findByText('Banner');

        const novo = screen.getByText('Novo item').closest('section');
        fireEvent.change(within(novo).getByLabelText('Nome do item'), { target: { value: 'Documento com foto' } });
        fireEvent.click(within(novo).getByLabelText('Orientador'));
        fireEvent.click(within(novo).getByLabelText('Na checagem do estande'));
        fireEvent.click(within(novo).getByLabelText(/Receber antes do orientador/));
        fireEvent.click(within(novo).getByRole('button', { name: 'Adicionar item' }));

        await waitFor(() => expect(criarItemConferencia).toHaveBeenCalledWith({
            nome: 'Documento com foto', descricao: null, abrangencia: 'pessoa', papeis: ['aluno', 'orientador'],
            no_credenciamento: true, na_checagem: true, receber_antes: true,
        }));
        expect(await screen.findByText('Item criado.')).toBeInTheDocument();
    });

    it('item sem lugar nenhum ou de pessoa sem papel não pode ser salvo', async () => {
        render(<ParametrizacaoItensConferencia />);
        await screen.findByText('Banner');

        const novo = screen.getByText('Novo item').closest('section');
        fireEvent.change(within(novo).getByLabelText('Nome do item'), { target: { value: 'RG' } });
        fireEvent.click(within(novo).getByLabelText('No credenciamento'));
        expect(within(novo).getByRole('button', { name: 'Adicionar item' })).toBeDisabled();

        fireEvent.click(within(novo).getByLabelText('No credenciamento'));
        fireEvent.click(within(novo).getByLabelText('Aluno'));
        expect(within(novo).getByRole('button', { name: 'Adicionar item' })).toBeDisabled();
    });

    it('no item do sistema, de quem é e o recebimento antes ficam travados', async () => {
        render(<ParametrizacaoItensConferencia />);

        fireEvent.click(await screen.findByRole('button', { name: 'Editar Termo de uso de imagem' }));
        const form = screen.getByDisplayValue('Termo de uso de imagem').closest('form');

        expect(within(form).getByLabelText('Do projeto')).toBeDisabled();
        expect(within(form).getByLabelText(/Receber antes do orientador/)).toBeDisabled();
        fireEvent.click(within(form).getByLabelText('Na checagem do estande'));
        fireEvent.click(within(form).getByRole('button', { name: 'Salvar' }));

        await waitFor(() => expect(atualizarItemConferencia).toHaveBeenCalledWith(1, expect.objectContaining({
            na_checagem: true, abrangencia: 'pessoa', receber_antes: true,
        })));
    });
});
