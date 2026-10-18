import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import axios from 'axios';
import http from './http.js';

/** Um adaptador falso: cada chamada responde com o próximo status da lista. */
function responder(statuses) {
    const chamadas = [];
    http.defaults.adapter = (config) => {
        chamadas.push(config);
        const status = statuses[Math.min(chamadas.length - 1, statuses.length - 1)];
        const response = { data: { status }, status, statusText: '', headers: {}, config };

        return status < 400
            ? Promise.resolve(response)
            : Promise.reject(Object.assign(new Error(String(status)), { config, response }));
    };

    return chamadas;
}

describe('http: sessão e CSRF', () => {
    const adaptadorOriginal = http.defaults.adapter;
    let csrf;

    beforeEach(() => {
        csrf = vi.spyOn(axios, 'get').mockResolvedValue({});
    });

    afterEach(() => {
        http.defaults.adapter = adaptadorOriginal;
        vi.restoreAllMocks();
    });

    it('no 419, renova o cookie CSRF e repete o pedido uma vez', async () => {
        const chamadas = responder([419, 200]);

        const r = await http.post('/avaliador/presencial/avaliacoes/5/rascunho', { respostas: {} });

        expect(r.status).toBe(200);
        expect(chamadas).toHaveLength(2);
        expect(chamadas[1].url).toBe('/avaliador/presencial/avaliacoes/5/rascunho');
        expect(csrf).toHaveBeenCalledWith('/sanctum/csrf-cookie', { withCredentials: true });
    });

    it('não repete de novo se o 419 voltar: a sessão se perdeu de verdade', async () => {
        const chamadas = responder([419, 419]);

        await expect(http.get('/projetos')).rejects.toMatchObject({ response: { status: 419 } });
        expect(chamadas).toHaveLength(2);
        expect(csrf).toHaveBeenCalledTimes(1);
    });

    it('os outros erros passam direto, sem repetir', async () => {
        const chamadas = responder([500]);

        await expect(http.get('/projetos')).rejects.toMatchObject({ response: { status: 500 } });
        expect(chamadas).toHaveLength(1);
        expect(csrf).not.toHaveBeenCalled();
    });
});
