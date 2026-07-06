import { describe, it, expect } from 'vitest';
import { tempoRelativo } from './tempo.js';

describe('tempoRelativo', () => {
    it('retorna vazio para entrada ausente ou inválida', () => {
        expect(tempoRelativo(null)).toBe('');
        expect(tempoRelativo('')).toBe('');
        expect(tempoRelativo('not-a-date')).toBe('');
    });

    it('mostra "agora mesmo" para segundos recentes', () => {
        expect(tempoRelativo(new Date().toISOString())).toBe('agora mesmo');
    });

    it('mostra minutos', () => {
        const iso = new Date(Date.now() - 5 * 60 * 1000).toISOString();
        expect(tempoRelativo(iso)).toBe('há 5 min');
    });

    it('mostra horas', () => {
        const iso = new Date(Date.now() - 3 * 60 * 60 * 1000).toISOString();
        expect(tempoRelativo(iso)).toBe('há 3 h');
    });

    it('usa singular para 1 dia', () => {
        const iso = new Date(Date.now() - 26 * 60 * 60 * 1000).toISOString();
        expect(tempoRelativo(iso)).toBe('há 1 dia');
    });

    it('usa plural para vários dias', () => {
        const iso = new Date(Date.now() - 3 * 24 * 60 * 60 * 1000).toISOString();
        expect(tempoRelativo(iso)).toBe('há 3 dias');
    });
});
