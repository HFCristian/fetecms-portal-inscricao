import { describe, it, expect } from 'vitest';
import { foiVista } from './chat.js';

describe('foiVista', () => {
    const envio = '2026-06-25T12:00:00Z';

    it('é falso quando o outro lado ainda não viu', () => {
        expect(foiVista(envio, null)).toBe(false);
        expect(foiVista(envio, undefined)).toBe(false);
    });

    it('é falso quando o outro lado viu ANTES do envio', () => {
        expect(foiVista('2026-06-25T12:00:10Z', envio)).toBe(false);
    });

    it('é verdadeiro quando viu no mesmo instante ou depois', () => {
        expect(foiVista(envio, envio)).toBe(true);
        expect(foiVista(envio, '2026-06-25T12:05:00Z')).toBe(true);
    });

    it('é falso para datas ausentes ou inválidas', () => {
        expect(foiVista(null, envio)).toBe(false);
        expect(foiVista('xpto', envio)).toBe(false);
        expect(foiVista(envio, 'xpto')).toBe(false);
    });
});
