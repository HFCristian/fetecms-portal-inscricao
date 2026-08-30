import { describe, it, expect } from 'vitest';
import { temChaveMaps, carregarMaps, motivoMapaIndisponivel } from './googleMaps.js';

describe('googleMaps', () => {
    it('sem VITE_GOOGLE_MAPS_API_KEY o mapa não carrega e explica o motivo', async () => {
        // O ambiente de teste não define a chave: é o mesmo caminho de um
        // servidor sem a variável configurada.
        expect(temChaveMaps()).toBe(false);

        await expect(carregarMaps()).rejects.toThrow('SEM_CHAVE');
        expect(motivoMapaIndisponivel(new Error('SEM_CHAVE')))
            .toContain('VITE_GOOGLE_MAPS_API_KEY');
        expect(motivoMapaIndisponivel(new Error('FALHA_CARREGAMENTO')))
            .toContain('Não foi possível carregar o mapa');
    });
});
