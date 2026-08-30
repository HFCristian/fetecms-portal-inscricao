import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));

// O mapa real depende do Google Maps; aqui basta um botão por marcador.
vi.mock('../components/MapaGoogle.jsx', () => ({
    default: ({ marcadores, onSelecionar }) => (
        <div data-testid="mapa">
            {marcadores.map((m) => (
                <button key={m.id} type="button" onClick={() => onSelecionar(m.id)}>{m.titulo}</button>
            ))}
        </div>
    ),
}));

const PONTOS = [
    {
        id: 1, responsavel: 'Rita', pessoas: 3, transporte_label: 'Van',
        posicao: { lat: -20.47, lng: -54.67 },
        destino: { nome: 'UFMS' }, distancia_m: 8200, duracao_s: 900,
        chegada_em: '2026-10-01T09:15:00-04:00', expira_em: '2026-10-01T10:00:00-04:00',
    },
    // Ligado, mas ainda sem posição: não vira marcador.
    {
        id: 2, responsavel: 'Léo', pessoas: 1, transporte_label: 'Carro',
        posicao: null, destino: { nome: 'Hotel' }, expira_em: '2026-10-01T10:00:00-04:00',
    },
];

const DETALHE = {
    ...PONTOS[0],
    acompanhantes: [
        { nome: 'Bia', area: 'Ciências Agrárias' },
        { nome: null, area: 'Engenharias' },
    ],
};

const getMapaComite = vi.fn(() => Promise.resolve({ data: PONTOS, meta: { intervalo_segundos: 5 } }));
const getPontoComite = vi.fn(() => Promise.resolve(DETALHE));
vi.mock('../lib/comite.js', () => ({
    getMapaComite: (...a) => getMapaComite(...a),
    getPontoComite: (...a) => getPontoComite(...a),
}));

import ComiteMapa from './ComiteMapa.jsx';

describe('ComiteMapa', () => {
    beforeEach(() => {
        vi.useFakeTimers({ shouldAdvanceTime: true });
        getPontoComite.mockClear();
    });
    afterEach(() => vi.useRealTimers());

    it('põe no mapa só quem já tem posição e conta quem ainda não tem', async () => {
        render(<ComiteMapa />);

        expect(await screen.findByText('Rita · 3 pessoas')).toBeInTheDocument();
        expect(screen.queryByText(/Léo/)).not.toBeInTheDocument();
    });

    it('ao clicar num ponto mostra pessoas, nomes, áreas, distância e chegada', async () => {
        render(<ComiteMapa />);

        fireEvent.click(await screen.findByText('Rita · 3 pessoas'));

        await waitFor(() => expect(getPontoComite).toHaveBeenCalledWith(1));

        expect(await screen.findByText('Rita')).toBeInTheDocument();
        // O número fica num <strong>: a asserção olha o texto do parágrafo inteiro.
        expect(screen.getByText((_, el) => el?.tagName === 'P' && /3\s+pessoas.*Van/.test(el.textContent)))
            .toBeInTheDocument();
        expect(screen.getByText('Bia — Ciências Agrárias')).toBeInTheDocument();
        // Acompanhante sem nome ainda aparece pela área.
        expect(screen.getByText('Sem nome — Engenharias')).toBeInTheDocument();
        expect(screen.getByText('8,2 km')).toBeInTheDocument();
        expect(screen.getByText('15 min')).toBeInTheDocument();
        expect(screen.getByText('UFMS')).toBeInTheDocument();
    });

    it('atualiza o mapa no intervalo do polling', async () => {
        render(<ComiteMapa />);
        await screen.findByText('Rita · 3 pessoas');

        const antes = getMapaComite.mock.calls.length;
        await vi.advanceTimersByTimeAsync(5000);

        expect(getMapaComite.mock.calls.length).toBeGreaterThan(antes);
    });
});
