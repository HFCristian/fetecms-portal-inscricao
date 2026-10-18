import { describe, it, expect } from 'vitest';
import { casaBusca, filtrarPorBusca } from './buscaEstande.js';

const abelhas = { titulo: 'Abelhas nativas do Cerrado', local: { estande: 42 } };
const horta = { titulo: 'Horta vertical', estande: 7 };

describe('busca por título ou estande', () => {
    it('acha pelo título, sem acento e sem caixa', () => {
        expect(casaBusca(abelhas, 'CERRADO')).toBe(true);
        expect(casaBusca({ titulo: 'Água da chuva' }, 'agua')).toBe(true);
        expect(casaBusca(horta, 'abelhas')).toBe(false);
    });

    it('acha pelo número do estande, com ou sem zeros e "estande"', () => {
        expect(casaBusca(abelhas, '42')).toBe(true);
        expect(casaBusca(abelhas, '042')).toBe(true);
        expect(casaBusca(abelhas, 'Estande 42')).toBe(true);
        expect(casaBusca(horta, '007')).toBe(true);
        expect(casaBusca(abelhas, '4')).toBe(false);
    });

    it('sem busca, passa tudo', () => {
        expect(filtrarPorBusca([abelhas, horta], '  ')).toHaveLength(2);
        expect(filtrarPorBusca([abelhas, horta], 'horta')).toEqual([horta]);
        expect(filtrarPorBusca(null, 'x')).toEqual([]);
    });
});
