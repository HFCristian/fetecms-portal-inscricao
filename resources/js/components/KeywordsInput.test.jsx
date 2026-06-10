import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import KeywordsInput from './KeywordsInput.jsx';

// Sem chamadas reais à API de sugestões globais.
vi.mock('../lib/catalogos.js', () => ({
    buscarPalavrasChave: () => Promise.resolve([]),
}));

describe('KeywordsInput', () => {
    it('adiciona uma palavra-chave válida (1 a 5 palavras) com Enter', () => {
        const onChange = vi.fn();
        render(<KeywordsInput value={[]} onChange={onChange} />);
        const input = screen.getByRole('textbox');

        fireEvent.change(input, { target: { value: 'Energia Solar' } });
        fireEvent.keyDown(input, { key: 'Enter' });

        expect(onChange).toHaveBeenCalledWith(['Energia Solar']);
    });

    it('não adiciona palavra-chave com mais de 5 palavras', () => {
        const onChange = vi.fn();
        render(<KeywordsInput value={[]} onChange={onChange} />);
        const input = screen.getByRole('textbox');

        fireEvent.change(input, { target: { value: 'uma duas tres quatro cinco seis' } });
        fireEvent.keyDown(input, { key: 'Enter' });

        expect(onChange).not.toHaveBeenCalled();
    });
});
