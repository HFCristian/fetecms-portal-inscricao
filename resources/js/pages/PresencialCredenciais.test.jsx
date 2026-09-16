import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.message ?? 'Erro.', fields: e?.fields ?? {} }),
}));

const getCredenciais = vi.fn();
const criarCredencial = vi.fn();
const atualizarCredencial = vi.fn();
const excluirCredencial = vi.fn();
const atribuirCredencial = vi.fn();
const retirarCredencial = vi.fn();
const baixarPremiacao = vi.fn();
vi.mock('../lib/presencial.js', () => ({
    getCredenciais: (...a) => getCredenciais(...a),
    criarCredencial: (...a) => criarCredencial(...a),
    atualizarCredencial: (...a) => atualizarCredencial(...a),
    excluirCredencial: (...a) => excluirCredencial(...a),
    atribuirCredencial: (...a) => atribuirCredencial(...a),
    retirarCredencial: (...a) => retirarCredencial(...a),
    baixarPremiacao: (...a) => baixarPremiacao(...a),
}));

import PresencialCredenciais from './PresencialCredenciais.jsx';

const CANDIDATOS = [
    { id: 7, titulo: 'Bioplástico', categoria: 'FETECMS', area: 'Agrárias', escola: 'EE Alfa', credenciais: [] },
];

const COM_VAGAS = {
    id: 1, nome: 'MOSTRATEC 2027', orgao: 'FUNDECT', descricao: null,
    vagas: 2, usadas: 0, disponiveis: 2, ativa: true, projetos: [],
};

const SEM_TETO = {
    id: 2, nome: 'Menção honrosa', orgao: null, descricao: null,
    vagas: null, usadas: 3, disponiveis: null, ativa: true, projetos: [],
};

describe('PresencialCredenciais', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        getCredenciais.mockResolvedValue({ data: [COM_VAGAS], meta: { candidatos: CANDIDATOS } });
        criarCredencial.mockResolvedValue([COM_VAGAS]);
        atribuirCredencial.mockResolvedValue({ data: [COM_VAGAS] });
        retirarCredencial.mockResolvedValue({ data: [COM_VAGAS] });
        atualizarCredencial.mockResolvedValue([COM_VAGAS]);
        excluirCredencial.mockResolvedValue([]);
    });

    it('mostra as vagas usadas e as disponíveis', async () => {
        render(<PresencialCredenciais />);

        expect(await screen.findByText(/MOSTRATEC 2027/)).toBeInTheDocument();
        expect(screen.getByText(/0 de 2 vaga\(s\)/)).toBeInTheDocument();
    });

    it('credencial sem teto não inventa número de vagas', async () => {
        getCredenciais.mockResolvedValue({ data: [SEM_TETO], meta: { candidatos: CANDIDATOS } });
        render(<PresencialCredenciais />);

        expect(await screen.findByText(/3 atribuída\(s\) · sem limite de vagas/)).toBeInTheDocument();
    });

    it('credencial esgotada é apontada', async () => {
        getCredenciais.mockResolvedValue({
            data: [{ ...COM_VAGAS, usadas: 2, disponiveis: 0 }],
            meta: { candidatos: CANDIDATOS },
        });
        render(<PresencialCredenciais />);

        expect(await screen.findByText(/2 de 2 vaga\(s\) · esgotada/)).toBeInTheDocument();
    });

    it('cadastra uma credencial nova', async () => {
        render(<PresencialCredenciais />);

        fireEvent.click(await screen.findByRole('button', { name: /Nova credencial/i }));
        fireEvent.change(screen.getByLabelText('Nome'), { target: { value: 'Feira Nacional' } });
        fireEvent.change(screen.getByLabelText('Vagas'), { target: { value: '5' } });
        fireEvent.click(screen.getByRole('button', { name: 'Cadastrar' }));

        await waitFor(() => expect(criarCredencial).toHaveBeenCalledWith({
            nome: 'Feira Nacional', orgao: null, descricao: null, vagas: 5,
        }));
    });

    it('credencial já dada não oferece excluir', async () => {
        getCredenciais.mockResolvedValue({
            data: [{
                ...COM_VAGAS, usadas: 1, disponiveis: 1,
                projetos: [{ id: 7, titulo: 'Bioplástico', categoria: 'FETECMS', area: 'Agrárias', observacao: null }],
            }],
            meta: { candidatos: CANDIDATOS },
        });
        render(<PresencialCredenciais />);

        expect(await screen.findByRole('button', { name: 'Retirar' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Excluir' })).not.toBeInTheDocument();
    });

    it('baixa a lista de premiação', async () => {
        render(<PresencialCredenciais />);

        fireEvent.click(await screen.findByRole('button', { name: /Lista de premiação/i }));

        expect(baixarPremiacao).toHaveBeenCalled();
    });
});
