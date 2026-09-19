import { describe, it, expect, vi, beforeEach } from 'vitest';

const patch = vi.fn();
const post = vi.fn();
vi.mock('./http.js', () => ({ default: { patch: (...a) => patch(...a), post: (...a) => post(...a) } }));

const {
    definirInicioAjustes, definirFimAjustes,
    definirLiberacaoAvaliacao, definirEncerramentoAvaliacao,
    desconsiderarNota, avaliarNoLugarDaNota,
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

// A escolha de substituição é montada na tela e precisa **viajar**: sem ela no
// corpo, designar o substituto no mesmo ato nunca chegava ao servidor — o
// recurso parecia existir e não existia, porque os testes de tela mockavam este
// helper e nunca viam o payload.
describe('desconsiderar uma nota', () => {
    beforeEach(() => post.mockReset().mockResolvedValue({ data: { data: { consideradas: 1 } } }));

    it('manda a substituição escolhida junto da justificativa', async () => {
        await desconsiderarNota(7, 'Avaliou o projeto errado.', { tipo: 'avaliador', avaliador_id: 3 });

        expect(post).toHaveBeenCalledWith('/admin/avaliacao/avaliacoes/7/desconsiderar', {
            justificativa: 'Avaliou o projeto errado.',
            substituicao: { tipo: 'avaliador', avaliador_id: 3 },
        });
    });

    // "Eu mesmo avalio" é outro endpoint desde a Sprint 144: ele abre a rubrica
    // e **não** desconsidera — quem desconsidera é o envio da avaliação.
    it('avaliar no lugar da nota é outra rota, e não desconsidera nada', async () => {
        await avaliarNoLugarDaNota(7, 'Nota incompatível com o trabalho.');

        expect(post).toHaveBeenCalledWith('/admin/avaliacao/avaliacoes/7/avaliar-no-lugar', {
            justificativa: 'Nota incompatível com o trabalho.',
        });
    });
});
