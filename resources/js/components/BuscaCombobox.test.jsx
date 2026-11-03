import { render, screen, fireEvent, within } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import BuscaCombobox from './BuscaCombobox.jsx';

const OPCOES = [
    { id: 1, nome: 'FET.AGR-001 — Bioplástico' },
    { id: 2, nome: 'JR.BIO-001 — Abelhas nativas' },
];

describe('BuscaCombobox', () => {
    it('filtra pelo que foi digitado', () => {
        render(<BuscaCombobox options={OPCOES} placeholder="Buscar" />);
        const combo = screen.getByPlaceholderText('Buscar');
        fireEvent.change(combo, { target: { value: 'jr.bio' } });

        const lista = combo.parentElement;
        expect(within(lista).getByRole('button', { name: /Abelhas nativas/ })).toBeInTheDocument();
        expect(within(lista).queryByRole('button', { name: /Bioplástico/ })).not.toBeInTheDocument();
    });

    it('a seleção trocada por fora reescreve o texto; a mesma seleção não apaga o digitado', () => {
        const { rerender } = render(<BuscaCombobox options={OPCOES} value={OPCOES[0]} placeholder="Buscar" />);
        const combo = screen.getByPlaceholderText('Buscar');
        expect(combo).toHaveValue('FET.AGR-001 — Bioplástico');

        fireEvent.change(combo, { target: { value: 'abel' } });
        rerender(<BuscaCombobox options={OPCOES} value={{ ...OPCOES[0] }} placeholder="Buscar" />);
        expect(combo).toHaveValue('abel');

        rerender(<BuscaCombobox options={OPCOES} value={null} placeholder="Buscar" />);
        expect(combo).toHaveValue('');
    });
});
