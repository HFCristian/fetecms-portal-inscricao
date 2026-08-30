import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

import SubmeterRascunhoDialog from './SubmeterRascunhoDialog.jsx';

const projeto = { titulo: 'Bioplástico de mandioca' };

describe('SubmeterRascunhoDialog', () => {
    it('só habilita o envio depois de uma justificativa com conteúdo', () => {
        const onSubmeter = vi.fn();
        render(<SubmeterRascunhoDialog projeto={projeto} onSubmeter={onSubmeter} onFechar={() => {}} />);

        const botao = screen.getByRole('button', { name: /Submeter/ });
        expect(botao).toBeDisabled();

        // Espaço em branco não vale como justificativa.
        fireEvent.change(screen.getByRole('textbox'), { target: { value: '    ' } });
        expect(botao).toBeDisabled();

        fireEvent.change(screen.getByRole('textbox'), { target: { value: 'Prazo perdido por falha no envio.' } });
        expect(botao).toBeEnabled();

        fireEvent.click(botao);
        expect(onSubmeter).toHaveBeenCalledWith('Prazo perdido por falha no envio.');
    });

    it('mostra de quem é a inscrição e avisa que o envio é irreversível', () => {
        render(
            <SubmeterRascunhoDialog
                projeto={projeto}
                orientador="Ana Orientadora"
                onSubmeter={() => {}}
                onFechar={() => {}}
            />,
        );

        expect(screen.getByText('Bioplástico de mandioca')).toBeInTheDocument();
        expect(screen.getByText(/Ana Orientadora/)).toBeInTheDocument();
        expect(screen.getByText(/irreversível/)).toBeInTheDocument();
    });
});
