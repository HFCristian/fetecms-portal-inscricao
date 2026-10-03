import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.response?.data?.message ?? '', fields: e?.response?.data?.errors ?? {} }),
}));
vi.mock('../components/InstituicaoCombobox.jsx', () => ({
    default: ({ onChange }) => (
        <button type="button" onClick={() => onChange({ id: 7, nome: 'EE Maria Constança' })}>escolher escola</button>
    ),
}));
vi.mock('../lib/catalogos.js', () => ({
    loadAreas: () => Promise.resolve([{ id: 1, nome: 'Ciências Agrárias' }]),
    loadSubareas: () => Promise.resolve([]),
    buscarInstituicoes: vi.fn(),
    criarInstituicao: vi.fn(),
}));

const getProjetosManuais = vi.fn();
const criarProjetoManual = vi.fn();
const excluirProjetoManual = vi.fn();
vi.mock('../lib/admin.js', () => ({
    getProjetosManuais: (...a) => getProjetosManuais(...a),
    getProjetoManual: vi.fn(),
    criarProjetoManual: (...a) => criarProjetoManual(...a),
    atualizarProjetoManual: vi.fn(),
    excluirProjetoManual: (...a) => excluirProjetoManual(...a),
    buscarOrientadores: () => Promise.resolve([]),
}));

import AvaliacaoProjetosManuais from './AvaliacaoProjetosManuais.jsx';

const CATEGORIAS = [{ value: 'fetecms', label: 'FETECMS' }, { value: 'fetec_jr', label: 'FETEC Jr' }];
const PROJETO = {
    id: 5, titulo: 'Biofiltro', categoria: 'FETECMS', area: 'Ciências Agrárias', instituicao: 'EE Maria',
    orientador: 'Marta', coorientador: null, alunos: ['Ana'], origem: 'credencial',
    origem_label: 'Credencial de feira afiliada · Feira de Dourados', finalista: true, credenciado: true,
};

describe('AvaliacaoProjetosManuais', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        getProjetosManuais.mockResolvedValue({ data: { lista: { id: 1, nome: 'Oficial' }, projetos: [PROJETO] }, meta: { categorias: CATEGORIAS } });
    });

    it('lista os projetos manuais com origem e situação', async () => {
        render(<AvaliacaoProjetosManuais />);

        expect(await screen.findByText('Biofiltro')).toBeInTheDocument();
        expect(screen.getByText('Credencial de feira afiliada · Feira de Dourados')).toBeInTheDocument();
        expect(screen.getByText('na lista final')).toBeInTheDocument();
        expect(screen.getByText('credenciado')).toBeInTheDocument();
    });

    it('sem lista final publicada, avisa e não deixa cadastrar', async () => {
        getProjetosManuais.mockResolvedValue({ data: { lista: null, projetos: [] }, meta: { categorias: CATEGORIAS } });
        render(<AvaliacaoProjetosManuais />);

        expect(await screen.findByText(/Não há lista final oficial publicada/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Cadastrar projeto/ })).toBeDisabled();
    });

    it('cadastra com orientador novo, estudantes e credencial', async () => {
        criarProjetoManual.mockResolvedValue({ data: { lista: { id: 1 }, projetos: [PROJETO] }, meta: { message: 'Projeto cadastrado.' } });
        render(<AvaliacaoProjetosManuais />);

        fireEvent.click(await screen.findByRole('button', { name: /Cadastrar projeto/ }));
        fireEvent.change(screen.getByLabelText('Título'), { target: { value: 'Biofiltro' } });
        fireEvent.change(screen.getByLabelText('Categoria'), { target: { value: 'fetecms' } });
        fireEvent.click(screen.getByText('escolher escola'));
        await screen.findByRole('option', { name: 'Ciências Agrárias' });
        fireEvent.change(screen.getByLabelText('Área'), { target: { value: '1' } });
        fireEvent.click(screen.getByLabelText('Credencial de feira afiliada'));
        fireEvent.change(screen.getByLabelText('Feira afiliada'), { target: { value: 'Feira de Dourados' } });
        fireEvent.change(screen.getByLabelText('Nome do orientador'), { target: { value: 'Marta' } });
        fireEvent.change(screen.getByLabelText('E-mail do orientador'), { target: { value: 'marta@escola.test' } });
        fireEvent.change(screen.getByLabelText('Nome do estudante 1'), { target: { value: 'Ana' } });
        fireEvent.click(screen.getByRole('button', { name: /Acrescentar estudante/ }));
        fireEvent.change(screen.getByLabelText('Nome do estudante 2'), { target: { value: 'Bruno' } });
        fireEvent.click(screen.getByLabelText(/Já credenciado no balcão/));
        fireEvent.change(screen.getByLabelText('Justificativa'), { target: { value: 'Credencial da feira regional.' } });
        fireEvent.click(screen.getByRole('button', { name: 'Cadastrar projeto' }));

        await waitFor(() => expect(criarProjetoManual).toHaveBeenCalled());
        const payload = criarProjetoManual.mock.calls[0][0];
        expect(payload).toMatchObject({
            titulo: 'Biofiltro', categoria: 'fetecms', instituicao_id: 7, area_id: '1',
            origem: 'credencial', feira_afiliada_nome: 'Feira de Dourados',
            orientador: { nome: 'Marta', email: 'marta@escola.test' },
            coorientador: null, credenciado: true,
        });
        expect(payload.alunos.map((a) => a.nome)).toEqual(['Ana', 'Bruno']);
        expect(await screen.findByText('Projeto cadastrado.')).toBeInTheDocument();
    });

    it('excluir pede justificativa', async () => {
        excluirProjetoManual.mockResolvedValue({ data: { lista: { id: 1 }, projetos: [] }, meta: { message: 'Projeto excluído e retirado da lista final.' } });
        render(<AvaliacaoProjetosManuais />);

        fireEvent.click(await screen.findByRole('button', { name: /Excluir/ }));
        const confirmar = screen.getAllByRole('button', { name: 'Excluir' }).at(-1);
        expect(confirmar).toBeDisabled();
        fireEvent.change(screen.getByLabelText('Justificativa da exclusão'), { target: { value: 'Cadastro duplicado.' } });
        fireEvent.click(confirmar);

        await waitFor(() => expect(excluirProjetoManual).toHaveBeenCalledWith(5, 'Cadastro duplicado.'));
    });
});
