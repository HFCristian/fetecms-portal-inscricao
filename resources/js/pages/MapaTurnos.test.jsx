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

const getTurnos = vi.fn();
const buscarFinalistas = vi.fn();
const salvarConfigTurnos = vi.fn();
const gerarTurnos = vi.fn();
const moverProjetoTurno = vi.fn();
const exportarTurnos = vi.fn();
vi.mock('../lib/mapaEvento.js', () => ({
    getTurnos: (...a) => getTurnos(...a),
    buscarFinalistas: (...a) => buscarFinalistas(...a),
    salvarConfigTurnos: (...a) => salvarConfigTurnos(...a),
    gerarTurnos: (...a) => gerarTurnos(...a),
    moverProjetoTurno: (...a) => moverProjetoTurno(...a),
    exportarTurnos: (...a) => exportarTurnos(...a),
}));

import MapaTurnos from './MapaTurnos.jsx';

const CATALOGO = [
    { value: 'vestibular', label: 'Justificativa por Vestibular', tipo: 'listas', descricao: 'Vestibular.' },
    { value: 'justificativa', label: 'Justificativa (Outras)', tipo: 'listas', descricao: 'Outros impedimentos.' },
    { value: 'fora_ms', label: 'Projetos de fora do MS', tipo: 'localidade', descricao: 'Outro estado.' },
    { value: 'fora_capital', label: 'Projetos de fora da capital', tipo: 'localidade', descricao: 'Interior.' },
    { value: 'capital', label: 'Projetos de Campo Grande MS', tipo: 'localidade', descricao: 'Capital.' },
];

const configPadrao = () => ({
    capacidade: { A: 230, B: 230 },
    regras: {
        vestibular: { ativa: false, listas: [] },
        justificativa: { ativa: false, listas: [] },
        fora_ms: { ativa: false, turno: 'A' },
        fora_capital: { ativa: false, turno: 'A' },
        capital: { ativa: false, turno: 'A' },
    },
});

const listaVazia = () => ({
    gerada: false,
    total: 0,
    turnos: {
        A: { turno: 'A', label: 'Turno A (matutino)', total: 0, projetos: [] },
        B: { turno: 'B', label: 'Turno B (vespertino)', total: 0, projetos: [] },
    },
});

const listaGerada = () => ({
    gerada: true,
    total: 2,
    turnos: {
        A: {
            turno: 'A', label: 'Turno A (matutino)', total: 1,
            projetos: [{
                projeto_id: 1, titulo: 'Bioplástico de mandioca', categoria: 'FETECMS',
                area: 'Ciências Agrárias', escola: 'EE Maria Constança', cidade: 'Campo Grande',
                uf: 'MS', orientador: 'Marta', turno: 'A', regra: 'capital',
                regra_label: 'Projetos de Campo Grande MS', manual: false,
            }],
        },
        B: {
            turno: 'B', label: 'Turno B (vespertino)', total: 1,
            projetos: [{
                projeto_id: 2, titulo: 'Sensor de nível', categoria: 'FETEC Jr',
                area: 'Engenharias', escola: 'EE Dourados', cidade: 'Dourados',
                uf: 'MS', orientador: 'João', turno: 'B', regra: 'equilibrio',
                regra_label: 'Equilíbrio entre os turnos', manual: false,
            }],
        },
    },
});

const painel = (over = {}) => ({
    edicao: { id: 1, nome: 'XVI FETECMS' },
    lista_final: { id: 3, nome: 'Lista oficial', versao: 1, projetos: 2 },
    config: configPadrao(),
    catalogo: CATALOGO,
    origens: [{ value: 'email', label: 'E-mail' }, { value: 'whatsapp', label: 'WhatsApp' }],
    turnos: [
        { value: 'A', label: 'Turno A (matutino)', curto: 'Matutino' },
        { value: 'B', label: 'Turno B (vespertino)', curto: 'Vespertino' },
    ],
    gerado_em: null,
    gerado_por: null,
    lista: listaVazia(),
    ...over,
});

describe('MapaTurnos', () => {
    beforeEach(() => {
        getTurnos.mockReset().mockResolvedValue(painel());
        buscarFinalistas.mockReset().mockResolvedValue([]);
        salvarConfigTurnos.mockReset();
        gerarTurnos.mockReset();
        moverProjetoTurno.mockReset();
        exportarTurnos.mockReset();
    });

    it('mostra as cinco regras na ordem de prioridade, numeradas', async () => {
        render(<MapaTurnos />);

        const switches = await screen.findAllByRole('switch');

        expect(switches).toHaveLength(5);
        expect(switches[0]).toHaveAttribute('aria-label', 'Justificativa por Vestibular');
        expect(switches[4]).toHaveAttribute('aria-label', 'Projetos de Campo Grande MS');
        // O número ao lado de cada regra é a prioridade dela.
        expect(screen.getByText('1')).toBeInTheDocument();
        expect(screen.getByText('5')).toBeInTheDocument();
    });

    it('avisa e desabilita a geração quando não há lista final vigente', async () => {
        getTurnos.mockResolvedValue(painel({ lista_final: null }));

        render(<MapaTurnos />);

        expect(await screen.findByText(/Não há lista final oficial vigente/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Gerar lista de turnos/ })).toBeDisabled();
    });

    it('uma regra de localidade só pede o turno quando ligada', async () => {
        render(<MapaTurnos />);
        const switches = await screen.findAllByRole('switch');

        expect(screen.queryByLabelText('Turno destes projetos')).not.toBeInTheDocument();

        fireEvent.click(switches[2]); // Projetos de fora do MS

        expect(await screen.findByText('Turno destes projetos')).toBeInTheDocument();
    });

    it('a justificativa pergunta o turno IMPOSSÍVEL, não o destino', async () => {
        render(<MapaTurnos />);
        const switches = await screen.findAllByRole('switch');

        fireEvent.click(switches[1]); // Justificativa (Outras)
        fireEvent.click(await screen.findByRole('button', { name: 'Adicionar justificativa' }));

        expect(await screen.findByText('Não pode estar no turno')).toBeInTheDocument();
        expect(screen.getByText('O projeto vai para o outro turno.')).toBeInTheDocument();
        expect(screen.getByText('Chegou por')).toBeInTheDocument();
    });

    it('acha um finalista pela busca e o põe na lista do vestibular', async () => {
        buscarFinalistas.mockResolvedValue([
            { id: 9, titulo: 'Horta na escola', escola: 'EE Central', cidade: 'Dourados', pessoas: ['Zuleica Nunes'] },
        ]);
        gerarTurnos.mockResolvedValue({ data: listaGerada(), meta: { message: 'Lista de turnos gerada.' } });

        render(<MapaTurnos />);
        const switches = await screen.findAllByRole('switch');

        fireEvent.click(switches[0]); // Vestibular
        fireEvent.click(await screen.findByRole('button', { name: 'Adicionar vestibular' }));

        fireEvent.change(screen.getByLabelText('Buscar por projeto ou participante'), {
            target: { value: 'zuleica' },
        });

        const resultado = await screen.findByText('Horta na escola', {}, { timeout: 2000 });
        fireEvent.click(resultado);

        // O projeto escolhido vira um chip removível na lista da regra.
        expect(await screen.findByRole('button', { name: 'Remover Horta na escola' })).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /Gerar lista de turnos/ }));

        await waitFor(() => expect(gerarTurnos).toHaveBeenCalled());
        const enviado = gerarTurnos.mock.calls[0][0];
        expect(enviado.regras.vestibular.ativa).toBe(true);
        expect(enviado.regras.vestibular.listas[0].projetos).toEqual([9]);
    });

    it('mostra os dois turnos lado a lado depois de gerar', async () => {
        getTurnos.mockResolvedValue(painel({ lista: listaGerada(), gerado_em: '2026-09-12T10:00:00-04:00' }));

        render(<MapaTurnos />);

        expect(await screen.findByText('Turno A (matutino) — 1 projeto(s)')).toBeInTheDocument();
        expect(screen.getByText('Turno B (vespertino) — 1 projeto(s)')).toBeInTheDocument();
        expect(screen.getByText('Bioplástico de mandioca')).toBeInTheDocument();
        // A tela diz por que cada projeto está no turno em que está — o
        // rótulo também é o nome da regra lá em cima, daí o getAllByText.
        expect(screen.getAllByText('Projetos de Campo Grande MS').length).toBeGreaterThan(1);
        expect(screen.getByText('Equilíbrio entre os turnos')).toBeInTheDocument();
    });

    it('avisa antes de regerar que a lista atual será substituída', async () => {
        getTurnos.mockResolvedValue(painel({ lista: listaGerada() }));
        gerarTurnos.mockResolvedValue({ data: listaGerada(), meta: { message: 'Lista de turnos gerada.' } });

        render(<MapaTurnos />);

        fireEvent.click(await screen.findByRole('button', { name: /Gerar lista de novo/ }));

        expect(await screen.findByText(/A lista atual será substituída/)).toBeInTheDocument();
        expect(gerarTurnos).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Gerar de novo' }));

        await waitFor(() => expect(gerarTurnos).toHaveBeenCalled());
    });

    it('move um projeto para o outro turno direto da lista', async () => {
        getTurnos.mockResolvedValue(painel({ lista: listaGerada() }));
        moverProjetoTurno.mockResolvedValue({ data: listaGerada(), meta: { message: 'Projeto movido de turno.' } });

        render(<MapaTurnos />);

        fireEvent.click(await screen.findByRole('button', {
            name: 'Mover Bioplástico de mandioca para o turno B',
        }));

        await waitFor(() => expect(moverProjetoTurno).toHaveBeenCalledWith(1, 'B'));
    });

    it('lista os projetos que não couberam no turno pedido pela regra', async () => {
        gerarTurnos.mockResolvedValue({
            data: listaGerada(),
            meta: {
                message: 'Lista de turnos gerada. 1 projeto(s) foram para o outro turno por falta de estande.',
                realocados: [{
                    projeto: 'Sensor de nível', de: 'Turno A (matutino)', para: 'Turno B (vespertino)',
                    motivo: 'O Turno A (matutino) ficou sem estande livre.',
                }],
            },
        });

        render(<MapaTurnos />);

        fireEvent.click(await screen.findByRole('button', { name: /Gerar lista de turnos/ }));

        expect(await screen.findByText(/1 projeto\(s\) não couberam no turno pedido/)).toBeInTheDocument();
        expect(screen.getByText(/Sensor de nível: Turno A \(matutino\) → Turno B/)).toBeInTheDocument();
    });

    it('mostra o motivo quando o servidor recusa a geração por falta de estandes', async () => {
        gerarTurnos.mockRejectedValue({
            response: { data: { errors: { capacidade: ['Os dois turnos somam 2 estandes e a lista final tem 3 projetos. Faltam 1 lugares.'] } } },
        });

        render(<MapaTurnos />);

        fireEvent.click(await screen.findByRole('button', { name: /Gerar lista de turnos/ }));

        expect(await screen.findByText(/Faltam 1 lugares/)).toBeInTheDocument();
    });

    it('exporta nos três formatos', async () => {
        getTurnos.mockResolvedValue(painel({ lista: listaGerada() }));

        render(<MapaTurnos />);

        fireEvent.click(await screen.findByRole('button', { name: 'Exportar PDF' }));
        fireEvent.click(screen.getByRole('button', { name: 'Exportar TXT' }));
        fireEvent.click(screen.getByRole('button', { name: 'Exportar CSV' }));

        expect(exportarTurnos.mock.calls.map((c) => c[0])).toEqual(['pdf', 'txt', 'csv']);
    });

    it('guarda a configuração sem gerar nada', async () => {
        salvarConfigTurnos.mockResolvedValue({ data: configPadrao(), meta: { message: 'Configuração dos turnos salva.' } });

        render(<MapaTurnos />);

        fireEvent.click(await screen.findByRole('button', { name: 'Salvar configuração' }));

        await waitFor(() => expect(salvarConfigTurnos).toHaveBeenCalled());
        expect(gerarTurnos).not.toHaveBeenCalled();
        expect(await screen.findByText('Configuração dos turnos salva.')).toBeInTheDocument();
    });
});
