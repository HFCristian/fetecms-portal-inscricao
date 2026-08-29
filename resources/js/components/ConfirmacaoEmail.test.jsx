import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const reenviarCodigo = vi.fn();
const trocarEmailCadastro = vi.fn();

vi.mock('../lib/cadastro.js', () => ({
    reenviarCodigo: (...a) => reenviarCodigo(...a),
    trocarEmailCadastro: (...a) => trocarEmailCadastro(...a),
}));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.message ?? 'erro', fields: e?.fields ?? {} }),
}));

import ConfirmacaoEmail from './ConfirmacaoEmail.jsx';

const PENDENTE = { token: 'tok123', email: 'joao@escola.com', validade_minutos: 15 };

describe('ConfirmacaoEmail', () => {
    beforeEach(() => {
        reenviarCodigo.mockReset();
        trocarEmailCadastro.mockReset();
    });

    it('mostra na tela o e-mail cadastrado e o prazo do código', () => {
        render(<ConfirmacaoEmail pendente={PENDENTE} onConfirmar={vi.fn()} onConfirmado={vi.fn()} />);
        expect(screen.getByText('joao@escola.com')).toBeInTheDocument();
        expect(screen.getByText(/15 minutos/)).toBeInTheDocument();
    });

    it('só habilita o envio com os 6 dígitos e confirma com o código digitado', async () => {
        const onConfirmar = vi.fn(() => Promise.resolve({ role: 'orientador' }));
        const onConfirmado = vi.fn();
        render(<ConfirmacaoEmail pendente={PENDENTE} onConfirmar={onConfirmar} onConfirmado={onConfirmado} />);

        const botao = screen.getByRole('button', { name: /Confirmar e concluir/i });
        expect(botao).toBeDisabled();

        // Só dígitos entram, e no máximo seis.
        const campo = screen.getByPlaceholderText('000000');
        fireEvent.change(campo, { target: { value: 'a12b3456789' } });
        expect(campo).toHaveValue('123456');

        fireEvent.click(botao);
        await waitFor(() => expect(onConfirmar).toHaveBeenCalledWith('tok123', '123456'));
        expect(onConfirmado).toHaveBeenCalled();
    });

    it('permite corrigir o e-mail sem refazer o cadastro', async () => {
        trocarEmailCadastro.mockResolvedValue({
            data: { ...PENDENTE, email: 'certo@escola.com' },
            meta: { message: 'Enviamos o código para certo@escola.com.' },
        });
        render(<ConfirmacaoEmail pendente={PENDENTE} onConfirmar={vi.fn()} onConfirmado={vi.fn()} />);

        fireEvent.click(screen.getByRole('button', { name: /Corrigir/i }));
        fireEvent.change(screen.getByDisplayValue('joao@escola.com'), { target: { value: 'certo@escola.com' } });
        fireEvent.click(screen.getByRole('button', { name: /Salvar e reenviar/i }));

        await waitFor(() => expect(trocarEmailCadastro).toHaveBeenCalledWith('tok123', 'certo@escola.com'));
        expect(await screen.findByText('certo@escola.com')).toBeInTheDocument();
    });

    it('trava o reenvio até a contagem zerar', () => {
        render(<ConfirmacaoEmail pendente={PENDENTE} onConfirmar={vi.fn()} onConfirmado={vi.fn()} />);
        expect(screen.getByRole('button', { name: /Reenviar código/i })).toBeDisabled();
        expect(screen.getByText(/disponível em/i)).toBeInTheDocument();
    });
});
