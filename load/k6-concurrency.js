import http from 'k6/http';
import { check, sleep } from 'k6';

/*
 * Cenários AUTENTICADOS e de CONCORRÊNCIA da API da XVI FETECMS.
 *
 * 1) auth_read_load  — login (cookie/CSRF do Sanctum) + GET /projetos sob carga.
 * 2) submit_race     — N usuários virtuais disparam POST /submeter no MESMO
 *                      projeto ao mesmo tempo, validando que a submissão é
 *                      atômica/idempotente (nunca 5xx, nunca dupla submissão).
 *                      Só roda se PROJETO_ID for informado.
 *
 * Uso:
 *   k6 run load/k6-concurrency.js
 *   k6 run -e BASE_URL=https://app.exemplo.org -e EMAIL=orientador@fetecms.test \
 *          -e PASSWORD=password -e PROJETO_ID=12 load/k6-concurrency.js
 *
 * Pré-requisitos:
 *   - App no ar e com o seed de desenvolvimento (orientador@fetecms.test / password).
 *   - O host em BASE_URL precisa constar em SANCTUM_STATEFUL_DOMAINS (por isso
 *     mandamos o header Origin — sem ele o Sanctum trata como request "stateless"
 *     e o login por cookie não autentica).
 *   - Para o submit_race, PROJETO_ID deve apontar para um rascunho COMPLETO do
 *     próprio orientador (passa no checklist). A primeira submissão efetiva; as
 *     demais devolvem 200 idempotente.
 */

const BASE = __ENV.BASE_URL || 'http://localhost:8000';
const EMAIL = __ENV.EMAIL || 'orientador@fetecms.test';
const PASSWORD = __ENV.PASSWORD || 'password';
const PROJETO_ID = __ENV.PROJETO_ID;

const scenarios = {
    auth_read_load: {
        executor: 'ramping-vus',
        exec: 'authRead',
        startVUs: 0,
        stages: [
            { duration: '20s', target: 15 },
            { duration: '40s', target: 15 },
            { duration: '10s', target: 0 },
        ],
    },
};

// O cenário de corrida só é montado quando há um projeto-alvo.
if (PROJETO_ID) {
    scenarios.submit_race = {
        executor: 'shared-iterations',
        exec: 'submitRace',
        vus: 20,
        iterations: 40,
        maxDuration: '30s',
        startTime: '75s', // começa depois do read_load para não competir
    };
}

export const options = {
    scenarios,
    thresholds: {
        http_req_failed: ['rate<0.02'],   // < 2% de falha
        http_req_duration: ['p(95)<800'], // p95 < 800ms (ajuste à infra AWS)
        checks: ['rate>0.99'],            // > 99% dos checks ok
    },
};

function headers(extra = {}) {
    return Object.assign(
        {
            Accept: 'application/json',
            Origin: BASE, // marca a request como "stateful" do SPA (Sanctum)
            'X-Requested-With': 'XMLHttpRequest',
        },
        extra,
    );
}

function xsrf() {
    const cookies = http.cookieJar().cookiesForURL(`${BASE}/`);
    return cookies['XSRF-TOKEN'] ? decodeURIComponent(cookies['XSRF-TOKEN'][0]) : '';
}

// Fluxo de login do Sanctum SPA: pega o cookie CSRF e autentica por sessão.
function login() {
    http.get(`${BASE}/sanctum/csrf-cookie`, { headers: headers() });
    const res = http.post(
        `${BASE}/api/v1/auth/login`,
        JSON.stringify({ email: EMAIL, password: PASSWORD }),
        { headers: headers({ 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() }) },
    );
    return check(res, { 'login 200': (r) => r.status === 200 });
}

export function authRead() {
    if (!login()) {
        return;
    }
    const res = http.get(`${BASE}/api/v1/projetos`, { headers: headers() });
    check(res, {
        'GET /projetos 200': (r) => r.status === 200,
        'payload tem data': (r) => typeof r.json('data') !== 'undefined',
    });
    sleep(1);
}

export function submitRace() {
    if (!login()) {
        return;
    }
    const res = http.post(`${BASE}/api/v1/projetos/${PROJETO_ID}/submeter`, null, {
        headers: headers({ 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrf() }),
    });
    // Invariantes da corrida: nunca erro de servidor; sempre 200 (submetido/
    // idempotente) ou 422 (checklist incompleto). Jamais um 5xx por dupla escrita.
    check(res, {
        'submit sem 5xx': (r) => r.status < 500,
        'submit 200 ou 422': (r) => r.status === 200 || r.status === 422,
    });
}
