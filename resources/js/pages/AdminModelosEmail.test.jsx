import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const getModelosEmail = vi.fn();
const salvarModeloEmail = vi.fn();
const restaurarModeloEmail = vi.fn();

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));
vi.mock('../lib/admin.js', () => ({
    getModelosEmail: (...a) => getModelosEmail(...a),
    salvarModeloEmail: (...a) => salvarModeloEmail(...a),
    restaurarModeloEmail: (...a) => restaurarModeloEmail(...a),
}));

import AdminModelosEmail from './AdminModelosEmail.jsx';

const MODELO = {
    chave: 'confirmacao_cadastro',
    label: 'Confirmação de cadastro',
    descricao: 'Vai para quem acabou de preencher o cadastro.',
    assunto: 'Confirme seu e-mail',
    corpo: 'Olá, {{nome}}!',
    personalizado: false,
    autor_nome: null,
    variaveis: [
        { chave: 'nome', descricao: 'Primeiro nome' },
        { chave: 'codigo', descricao: 'O código de 6 dígitos' },
    ],
};

describe('AdminModelosEmail', () => {
    beforeEach(() => {
        getModelosEmail.mockResolvedValue([MODELO]);
        salvarModeloEmail.mockReset();
        restaurarModeloEmail.mockReset();
    });

    it('lista os modelos e diz quando o texto ainda é o padrão', async () => {
        render(<AdminModelosEmail />);
        expect(await screen.findByText('Confirmação de cadastro')).toBeInTheDocument();
        expect(screen.getByText('Usando o texto padrão da FETECMS.')).toBeInTheDocument();
        // O editor só abre no clique.
        expect(screen.queryByText('Salvar modelo')).not.toBeInTheDocument();
    });

    it('abre o editor, insere variável no cursor e salva', async () => {
        salvarModeloEmail.mockResolvedValue({ ...MODELO, personalizado: true, autor_nome: 'Ana' });
        render(<AdminModelosEmail />);

        fireEvent.click(await screen.findByText('Editar mensagem'));

        const corpo = screen.getByDisplayValue('Olá, {{nome}}!');
        fireEvent.change(corpo, { target: { value: 'Olá!' } });
        fireEvent.click(screen.getByText('{{codigo}}'));

        await waitFor(() => expect(screen.getByDisplayValue('Olá!{{codigo}}')).toBeInTheDocument());

        fireEvent.click(screen.getByText('Salvar modelo'));

        await waitFor(() => expect(salvarModeloEmail).toHaveBeenCalledWith('confirmacao_cadastro', {
            assunto: 'Confirme seu e-mail',
            corpo: 'Olá!{{codigo}}',
        }));
        expect(await screen.findByText(/Texto personalizado por Ana/)).toBeInTheDocument();
    });

    it('só oferece restaurar quando o texto foi personalizado', async () => {
        getModelosEmail.mockResolvedValue([{ ...MODELO, personalizado: true, autor_nome: 'Ana' }]);
        render(<AdminModelosEmail />);

        fireEvent.click(await screen.findByText('Editar mensagem'));
        expect(screen.getByText('Restaurar padrão')).toBeInTheDocument();
    });
});
