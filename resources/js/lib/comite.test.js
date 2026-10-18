import { describe, it, expect, vi, beforeEach } from 'vitest';

const post = vi.fn();
vi.mock('./http.js', () => ({ default: { post: (...a) => post(...a) } }));

const { retirarDesignacoesComite } = await import('./comite.js');

describe('retirarDesignacoesComite', () => {
    beforeEach(() => post.mockReset().mockResolvedValue({ data: { data: { retiradas: 2 }, meta: { message: 'ok' } } }));

    it('separa os ids da tela pela origem e devolve o envelope inteiro', async () => {
        const r = await retirarDesignacoesComite(5, ['online-12', 'estande-3', 'online-7']);

        expect(post).toHaveBeenCalledWith('/admin/comite/acompanhamento/avaliadores/5/retirar', {
            online_ids: [12, 7],
            estande_ids: [3],
        });
        expect(r.meta.message).toBe('ok');
    });
});
