import { describe, it, expect } from 'vitest';
import { ABAS_ADMIN, abasPermitidas } from './abasAdmin.js';

/**
 * Sprint 92 — a ordem do menu vem do backend (Parametrização → Ordem do menu).
 * O helper só a respeita: é isso que mantém o menu lateral e a Home iguais.
 */
describe('abasPermitidas', () => {
    it('respeita a ordem em que as abas chegam', () => {
        const ordem = ['registros', 'projetos', 'credenciamento'];

        expect(abasPermitidas(ordem).map((a) => a.aba)).toEqual(ordem);
    });

    it('filtra o que a pessoa não abre', () => {
        expect(abasPermitidas(['credenciamento']).map((a) => a.aba)).toEqual(['credenciamento']);
    });

    /** Payload antigo em cache: mostra tudo, e o backend continua barrando. */
    it('sem lista, devolve o catálogo na ordem de referência', () => {
        expect(abasPermitidas(undefined)).toEqual(ABAS_ADMIN);
        expect(abasPermitidas(null)).toEqual(ABAS_ADMIN);
    });

    /** Aba que o backend cita mas o front ainda não conhece não vira buraco. */
    it('ignora aba desconhecida sem quebrar o menu', () => {
        expect(abasPermitidas(['projetos', 'aba_do_futuro']).map((a) => a.aba)).toEqual(['projetos']);
    });
});
