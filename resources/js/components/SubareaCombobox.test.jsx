import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import SubareaCombobox from './SubareaCombobox.jsx';

const opcoes = [{ id: 1, nome: 'Genética' }, { id: 2, nome: 'Botânica' }];

describe('SubareaCombobox', () => {
    it('seleciona uma subárea existente ao filtrar', () => {
        const onChange = vi.fn();
        render(<SubareaCombobox options={opcoes} value={null} onChange={onChange} />);
        const input = screen.getByRole('textbox');

        fireEvent.focus(input);
        fireEvent.change(input, { target: { value: 'Gen' } });
        fireEvent.mouseDown(screen.getByRole('button', { name: 'Genética' }));

        expect(onChange).toHaveBeenCalledWith({ id: 1, nome: 'Genética' });
    });

    it('no modo registro (sem create) emite { id: null, nome } ao criar', () => {
        const onChange = vi.fn();
        render(<SubareaCombobox options={[]} value={null} onChange={onChange} />);
        const input = screen.getByRole('textbox');

        fireEvent.focus(input);
        fireEvent.change(input, { target: { value: 'Bioinformática' } });
        fireEvent.mouseDown(screen.getByRole('button', { name: /Criar/ }));

        expect(onChange).toHaveBeenCalledWith({ id: null, nome: 'Bioinformática' });
    });

    it('no modo autenticado chama create e emite a subárea com id real', async () => {
        const onChange = vi.fn();
        const create = vi.fn().mockResolvedValue({ id: 99, nome: 'Mecatrônica' });
        render(<SubareaCombobox options={[]} value={null} onChange={onChange} create={create} />);
        const input = screen.getByRole('textbox');

        fireEvent.focus(input);
        fireEvent.change(input, { target: { value: 'Mecatrônica' } });
        fireEvent.mouseDown(screen.getByRole('button', { name: /Criar/ }));

        await waitFor(() => expect(create).toHaveBeenCalledWith('Mecatrônica'));
        await waitFor(() => expect(onChange).toHaveBeenCalledWith({ id: 99, nome: 'Mecatrônica' }));
    });
});
