import { describe, it, expect, vi, beforeEach } from 'vitest';

const patch = vi.fn();
vi.mock('./http.js', () => ({ default: { patch: (...a) => patch(...a) } }));

const {
    definirInicioAjustes, definirFimAjustes,
    definirLiberacaoAvaliacao, definirEncerramentoAvaliacao,
} = await import('./admin.js');

// O `CampoDataCard` faz `onSalvo(resp.data)` e `resp.meta?.message`: todo helper
// de data precisa devolver o envelope inteiro. Desembrulhar (`r.data.data`)
// entregava `undefined` ao card e quebrava o render seguinte em
// `config.liberada_em_input`.
describe('helpers de data da parametrização', () => {
    const envelope = { data: { liberada_em_input: null }, meta: { message: 'Salvo.' } };

    beforeEach(() => patch.mockReset().mockResolvedValue({ data: envelope }));

    it.each([
        ['definirInicioAjustes', definirInicioAjustes, { ponta: 'de', data: '2026-11-01T08:00' }],
        ['definirFimAjustes', definirFimAjustes, { ponta: 'ate', data: '2026-11-01T08:00' }],
    ])('%s devolve o envelope { data, meta } que o card espera', async (_nome, fn, corpo) => {
        const resp = await fn('2026-11-01T08:00');

        expect(patch).toHaveBeenCalledWith('/admin/avaliacao/ajustes', corpo);
        expect(resp).toEqual(envelope);
    });

    it('as datas da avaliação seguem o mesmo contrato', async () => {
        expect(await definirLiberacaoAvaliacao('2026-11-01T08:00')).toEqual(envelope);
        expect(await definirEncerramentoAvaliacao(null)).toEqual(envelope);
    });
});
