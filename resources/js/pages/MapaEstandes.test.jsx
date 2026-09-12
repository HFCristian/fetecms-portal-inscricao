import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({
        message: e?.response?.data?.message ?? '',
        fields: e?.response?.data?.errors
            ? Object.fromEntries(Object.entries(e.response.data.errors).map(([k, v]) => [k, v[0]]))
            : {},
    }),
}));

const getEstandes = vi.fn();
const salvarConfigEstandes = vi.fn();
const gerarEstandes = vi.fn();
const moverProjetoEstande = vi.fn();
const exportarEstandes = vi.fn();
vi.mock('../lib/mapaEvento.js', () => ({
    getEstandes: (...a) => getEstandes(...a),
    salvarConfigEstandes: (...a) => salvarConfigEstandes(...a),
    gerarEstandes: (...a) => gerarEstandes(...a),
    moverProjetoEstande: (...a) => moverProjetoEstande(...a),
    exportarEstandes: (...a) => exportarEstandes(...a),
}));

import MapaEstandes from './MapaEstandes.jsx';

const configPadrao = () => ({
    regras: {
        fetecms: { ativa: false, faixa: '' },
        fetec_jr: { ativa: false, faixa: '' },
        fetecms_fundect: { ativa: false, faixa: '' },
    },
});

const listaVazia = () => ({
    gerada: false,
    total: 0,
    turnos: {
        A: { turno: 'A', label: 'Turno A (matutino)', total: 0, estandes: [] },
        B: { turno: 'B', label: 'Turno B (vespertino)', total: 0, estandes: [] },
    },
});

const listaGerada = () => ({
    gerada: true,
    total: 2,
    turnos: {
        A: {
            turno: 'A', label: 'Turno A (matutino)', total: 2,
            estandes: [
                {
                    projeto_id: 1, numero: 1, estande: '001', titulo: 'Bioplástico de mandioca',
                    categoria: 'FETECMS', area: 'Ciências Agrárias', escola: 'EE Maria Constança',
                    cidade: 'Campo Grande', uf: 'MS', orientador: 'Marta', turno: 'A', manual: false,
                },
                {
                    projeto_id: 2, numero: 2, estande: '002', titulo: 'Sensor de nível',
                    categoria: 'FETEC Jr', area: 'Engenharias', escola: 'EE Dourados',
                    cidade: 'Dourados', uf: 'MS', orientador: 'João', turno: 'A', manual: true,
                },
            ],
        },
        B: { turno: 'B', label: 'Turno B (vespertino)', total: 0, estandes: [] },
    },
});

const painel = (over = {}) => ({
    config: configPadrao(),
    categorias: [
        { value: 'fetecms', label: 'FETECMS', total: 12 },
        { value: 'fetec_jr', label: 'FETEC Jr', total: 8 },
        { value: 'fetecms_fundect', label: 'FETECMS FUNDECT', total: 5 },
    ],
    turnos_gerados: true,
    capacidade: { A: 230, B: 230 },
    gerado_em: null,
    gerado_por: null,
    lista: listaVazia(),
    ...over,
});

describe('MapaEstandes', () => {
    beforeEach(() => {
        getEstandes.mockReset().mockResolvedValue(painel());
        salvarConfigEstandes.mockReset();
        gerarEstandes.mockReset();
        moverProjetoEstande.mockReset();
        exportarEstandes.mockReset();
    });

    it('tem uma regra por categoria, desligadas por padrão', async () => {
        render(<MapaEstandes />);

        const switches = await screen.findAllByRole('switch');

        expect(switches).toHaveLength(3);
        expect(switches[0]).toHaveAttribute('aria-label', 'FETECMS');
        expect(switches.every((s) => s.getAttribute('aria-checked') === 'false')).toBe(true);
        // Cada categoria informa quantos finalistas tem, para dimensionar a faixa.
        expect(screen.getByText('12 projeto(s) nesta categoria entre os finalistas.')).toBeInTheDocument();
    });

    it('pede a faixa só da categoria ligada', async () => {
        render(<MapaEstandes />);
        const switches = await screen.findAllByRole('switch');

        expect(screen.queryByPlaceholderText('1-40, 61-70')).not.toBeInTheDocument();

        fireEvent.click(switches[0]);

        expect(await screen.findByPlaceholderText('1-40, 61-70')).toBeInTheDocument();
        expect(screen.getByText(/Números avulsos e intervalos/)).toBeInTheDocument();
    });

    it('avisa e bloqueia a distribuição enquanto não houver turnos', async () => {
        getEstandes.mockResolvedValue(painel({ turnos_gerados: false }));

        render(<MapaEstandes />);

        expect(await screen.findByText(/A lista de turnos ainda não foi gerada/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Distribuir estandes/ })).toBeDisabled();
    });

    it('manda a faixa digitada ao distribuir', async () => {
        gerarEstandes.mockResolvedValue({ data: listaGerada(), meta: { message: 'Estandes distribuídos.' } });

        render(<MapaEstandes />);
        const switches = await screen.findAllByRole('switch');

        fireEvent.click(switches[0]);
        fireEvent.change(await screen.findByPlaceholderText('1-40, 61-70'), { target: { value: '1-40, 61-70' } });
        fireEvent.click(screen.getByRole('button', { name: /Distribuir estandes/ }));

        await waitFor(() => expect(gerarEstandes).toHaveBeenCalled());
        expect(gerarEstandes.mock.calls[0][0].regras.fetecms).toEqual({ ativa: true, faixa: '1-40, 61-70' });
    });

    it('mostra os dois turnos com o número de cada estande', async () => {
        getEstandes.mockResolvedValue(painel({ lista: listaGerada() }));

        render(<MapaEstandes />);

        expect(await screen.findByText('Turno A (matutino) — 2 estande(s)')).toBeInTheDocument();
        expect(screen.getByText('001')).toBeInTheDocument();
        expect(screen.getByText('Bioplástico de mandioca')).toBeInTheDocument();
        // O que foi movido à mão fica marcado, para a próxima geração não surpreender.
        expect(screen.getByText('Movido à mão')).toBeInTheDocument();
    });

    it('troca um projeto de estande pelo número', async () => {
        getEstandes.mockResolvedValue(painel({ lista: listaGerada() }));
        moverProjetoEstande.mockResolvedValue({ data: listaGerada(), meta: { message: 'Projeto movido de estande.' } });

        render(<MapaEstandes />);

        fireEvent.click(await screen.findByRole('button', { name: 'Trocar o estande de Bioplástico de mandioca' }));

        // O diálogo avisa que mandar para um número ocupado troca os dois.
        expect(await screen.findByText(/os dois trocam de lugar/)).toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Novo número'), { target: { value: '42' } });
        fireEvent.click(screen.getByRole('button', { name: 'Trocar' }));

        await waitFor(() => expect(moverProjetoEstande).toHaveBeenCalledWith(1, 42));
    });

    it('avisa antes de redistribuir que as trocas manuais se perdem', async () => {
        getEstandes.mockResolvedValue(painel({ lista: listaGerada() }));
        gerarEstandes.mockResolvedValue({ data: listaGerada(), meta: { message: 'Estandes distribuídos.' } });

        render(<MapaEstandes />);

        fireEvent.click(await screen.findByRole('button', { name: /Distribuir de novo/ }));

        expect(await screen.findByText(/inclusive 1 troca\(s\) que você fez à mão/)).toBeInTheDocument();
        expect(gerarEstandes).not.toHaveBeenCalled();

        // O botão da página e o da confirmação têm o mesmo nome: o do diálogo é o segundo.
        fireEvent.click(screen.getAllByRole('button', { name: 'Distribuir de novo' })[1]);

        await waitFor(() => expect(gerarEstandes).toHaveBeenCalled());
    });

    it('mostra as ressalvas da distribuição', async () => {
        gerarEstandes.mockResolvedValue({
            data: listaGerada(),
            meta: {
                message: 'Estandes distribuídos, com ressalvas — veja os avisos abaixo.',
                avisos: [{
                    turno: 'Turno A (matutino)', categoria: 'FETECMS', sobraram: 2,
                    motivo: 'A faixa de FETECMS tem 10 estande(s) para 12 projeto(s); o excedente foi para os números livres.',
                }],
            },
        });

        render(<MapaEstandes />);

        fireEvent.click(await screen.findByRole('button', { name: /Distribuir estandes/ }));

        expect(await screen.findByText(/o excedente foi para os números livres/)).toBeInTheDocument();
    });

    it('mostra o motivo quando duas categorias disputam o mesmo estande', async () => {
        gerarEstandes.mockRejectedValue({
            response: { data: { errors: { regras: ['Estes estandes estão em mais de uma categoria: 8-10.'] } } },
        });

        render(<MapaEstandes />);

        fireEvent.click(await screen.findByRole('button', { name: /Distribuir estandes/ }));

        expect(await screen.findByText(/em mais de uma categoria: 8-10/)).toBeInTheDocument();
    });

    it('exporta nos três formatos', async () => {
        getEstandes.mockResolvedValue(painel({ lista: listaGerada() }));

        render(<MapaEstandes />);

        fireEvent.click(await screen.findByRole('button', { name: 'Exportar PDF' }));
        fireEvent.click(screen.getByRole('button', { name: 'Exportar TXT' }));
        fireEvent.click(screen.getByRole('button', { name: 'Exportar CSV' }));

        expect(exportarEstandes.mock.calls.map((c) => c[0])).toEqual(['pdf', 'txt', 'csv']);
    });
});
