import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.message ?? '', fields: e?.fields ?? {} }),
}));
vi.mock('../components/SubareaCombobox.jsx', () => ({ default: () => <div data-testid="subarea" /> }));
vi.mock('../lib/catalogos.js', () => ({
    loadAreas: () => Promise.resolve([{ id: 3, nome: 'Ciências Agrárias' }]),
    loadSubareas: () => Promise.resolve([]),
}));

const getAvaliadoresCadastrados = vi.fn();
const cadastrarAvaliadorPresencial = vi.fn();
const novaSenhaAvaliadorPresencial = vi.fn();
vi.mock('../lib/presencial.js', () => ({
    getAvaliadoresCadastrados: (...a) => getAvaliadoresCadastrados(...a),
    cadastrarAvaliadorPresencial: (...a) => cadastrarAvaliadorPresencial(...a),
    novaSenhaAvaliadorPresencial: (...a) => novaSenhaAvaliadorPresencial(...a),
}));

import PresencialAvaliadores from './PresencialAvaliadores.jsx';

const ROSA = {
    id: 9, nome: 'Rosa Pantaneira', email: 'rosa@ufms.br', titulacao: 'Doutorado (concluído)',
    area: 'Ciências Agrárias', subarea: null, ativo: true, comissao_especial: false,
    avaliacoes_presenciais: 0, cadastrado_por: 'Admin', cadastrado_em: '2026-10-13T10:00:00-04:00',
};

async function preencher() {
    fireEvent.change(screen.getByLabelText('Nome completo'), { target: { value: 'Rosa Pantaneira' } });
    fireEvent.change(screen.getByLabelText('E-mail'), { target: { value: 'rosa@ufms.br' } });
    fireEvent.change(screen.getByLabelText('CPF'), { target: { value: '52998224725' } });
    fireEvent.change(screen.getByLabelText('Titulação'), { target: { value: 'Doutorado (concluído)' } });
    await screen.findByRole('option', { name: 'Ciências Agrárias' });
    fireEvent.change(screen.getByLabelText('Área de atuação'), { target: { value: '3' } });
}

describe('PresencialAvaliadores', () => {
    beforeEach(() => {
        getAvaliadoresCadastrados.mockReset().mockResolvedValue([]);
        cadastrarAvaliadorPresencial.mockReset().mockResolvedValue({ data: ROSA, meta: { senha: 'Ab3kP9xQ2m' } });
        novaSenhaAvaliadorPresencial.mockReset().mockResolvedValue({ data: ROSA, meta: { senha: 'Zz7nM4tR8w' } });
    });

    it('não pede camiseta, idiomas, cidade, estado nem senha', async () => {
        render(<PresencialAvaliadores />);
        await screen.findByText(/Nenhum avaliador cadastrado/);

        for (const rotulo of [/Camiseta/, /Idiomas/, /Cidade/, /Estado/, /Senha/]) {
            expect(screen.queryByLabelText(rotulo)).not.toBeInTheDocument();
        }
    });

    it('cadastra e mostra a senha gerada uma vez', async () => {
        render(<PresencialAvaliadores />);
        await preencher();

        fireEvent.click(screen.getByRole('button', { name: /Cadastrar e gerar senha/ }));

        await waitFor(() => expect(cadastrarAvaliadorPresencial).toHaveBeenCalledWith({
            name: 'Rosa Pantaneira', email: 'rosa@ufms.br', cpf: expect.any(String),
            titulacao: 'Doutorado (concluído)', area_id: 3, subarea_id: null, subarea_nome: null,
        }));
        expect(await screen.findByTestId('senha-gerada')).toHaveTextContent('Ab3kP9xQ2m');
        expect(getAvaliadoresCadastrados).toHaveBeenCalledTimes(2);

        fireEvent.click(screen.getByRole('button', { name: /Anotei, fechar/ }));
        expect(screen.queryByTestId('senha-gerada')).not.toBeInTheDocument();
    });

    it('mostra o erro de validação do servidor', async () => {
        cadastrarAvaliadorPresencial.mockRejectedValue({
            message: 'Verifique os campos.',
            fields: { cpf: ['Este CPF já está cadastrado como orientador. Um orientador não pode ser avaliador.'] },
        });
        render(<PresencialAvaliadores />);
        await preencher();

        fireEvent.click(screen.getByRole('button', { name: /Cadastrar e gerar senha/ }));

        expect(await screen.findByText(/Um orientador não pode ser avaliador/)).toBeInTheDocument();
        expect(screen.queryByTestId('senha-gerada')).not.toBeInTheDocument();
    });

    it('lista os cadastrados e gera senha nova', async () => {
        getAvaliadoresCadastrados.mockResolvedValue([ROSA]);
        render(<PresencialAvaliadores />);

        expect(await screen.findByText('Rosa Pantaneira')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /Gerar nova senha/ }));

        await waitFor(() => expect(novaSenhaAvaliadorPresencial).toHaveBeenCalledWith(9));
        expect(await screen.findByTestId('senha-gerada')).toHaveTextContent('Zz7nM4tR8w');
    });

    it('diz quem ainda aguarda a liberação e quem já foi liberado', async () => {
        getAvaliadoresCadastrados.mockResolvedValue([
            { ...ROSA, liberado: false },
            { ...ROSA, id: 10, nome: 'Davi Liberado', email: 'davi@ufms.br', liberado: true },
        ]);
        render(<PresencialAvaliadores />);

        expect(await screen.findByText('Rosa Pantaneira')).toBeInTheDocument();
        expect(screen.getByText('Rosa Pantaneira').closest('li')).toHaveTextContent('Aguardando liberação');
        expect(screen.getByText('Davi Liberado').closest('li')).toHaveTextContent('Liberado');
        expect(screen.getByText('Davi Liberado').closest('li')).not.toHaveTextContent('Aguardando');
    });
});
