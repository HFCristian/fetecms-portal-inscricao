import http from 'k6/http';
import { check, sleep } from 'k6';

// Teste de carga "smoke/baseline" da API pública da XVI FETECMS.
// Uso:  k6 run load/k6-smoke.js   (opcional: -e BASE_URL=http://localhost:8000)

const BASE = __ENV.BASE_URL || 'http://localhost:8000';

export const options = {
    stages: [
        { duration: '30s', target: 20 }, // sobe para 20 usuários virtuais
        { duration: '1m', target: 20 },  // mantém 20 por 1 minuto
        { duration: '20s', target: 0 },  // desce
    ],
    thresholds: {
        http_req_failed: ['rate<0.01'],     // < 1% de erro
        http_req_duration: ['p(95)<500'],   // 95% das requisições < 500ms
    },
};

const endpoints = [
    '/api/v1/health',
    '/api/v1/catalogos/categorias',
    '/api/v1/catalogos/areas',
    '/api/v1/catalogos/instituicoes',
    '/api/v1/catalogos/palavras-chave?search=a',
];

export default function () {
    for (const path of endpoints) {
        const res = http.get(`${BASE}${path}`, { headers: { Accept: 'application/json' } });
        check(res, { [`200 ${path}`]: (r) => r.status === 200 });
    }
    sleep(1);
}
