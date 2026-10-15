import { renderHook, act } from '@testing-library/react';
import { describe, it, expect, beforeEach } from 'vitest';
import { TODOS, eventoDaPorta, useEventoCerimonial } from './eventoCerimonial.js';

const A = { id: 1, nome: 'Premiação FETECMS' };
const B = { id: 2, nome: 'Premiação Jr' };

describe('useEventoCerimonial', () => {
    beforeEach(() => localStorage.clear());

    it('na porta, com um evento só, ele já vem escolhido', () => {
        const { result } = renderHook(() => useEventoCerimonial([A], 'porta'));
        expect(result.current[0]).toBe(1);
    });

    it('na porta, com dois eventos, ninguém escolhe pelo atendente', () => {
        const { result } = renderHook(() => useEventoCerimonial([A, B], 'porta'));
        expect(result.current[0]).toBeNull();
    });

    it('a escolha fica guardada neste aparelho e volta', () => {
        const { result } = renderHook(() => useEventoCerimonial([A, B], 'porta'));
        act(() => result.current[1](2));

        expect(result.current[0]).toBe(2);
        expect(eventoDaPorta()).toBe(2);
        expect(renderHook(() => useEventoCerimonial([A, B], 'porta')).result.current[0]).toBe(2);
    });

    it('evento guardado que deixou de existir não vale', () => {
        localStorage.setItem('fetec:cerimonial:evento:porta', '9');
        const { result } = renderHook(() => useEventoCerimonial([A, B], 'porta'));
        expect(result.current[0]).toBeNull();
    });

    it('no painel o padrão é "Todos", e a porta e o painel não se misturam', () => {
        localStorage.setItem('fetec:cerimonial:evento:porta', '2');
        const { result } = renderHook(() => useEventoCerimonial([A, B], 'painel'));
        expect(result.current[0]).toBe(TODOS);

        act(() => result.current[1](1));
        expect(result.current[0]).toBe(1);
        expect(eventoDaPorta()).toBe(2);
    });
});
