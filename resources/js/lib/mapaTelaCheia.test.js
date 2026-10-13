import { describe, it, expect } from 'vitest';
import { corDaArea, COR_AREA_OUTRAS, textoSobreArea } from './mapaTelaCheia.js';

describe('cores da tela cheia do mapa', () => {
    it('a cor segue a área, e área desconhecida cai em "Outras"', () => {
        expect(corDaArea('AGR')).toBe('#008300');
        expect(corDaArea('BIO')).toBe('#1baf7a');
        expect(corDaArea('XYZ')).toBe(COR_AREA_OUTRAS);
        // Faixa clara pede texto escuro.
        expect(textoSobreArea(corDaArea('HUM'))).toBe('#0b0b0b');
        expect(textoSobreArea(corDaArea('AGR'))).toBe('#ffffff');
    });
});
