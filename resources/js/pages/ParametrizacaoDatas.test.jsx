import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));

const getInscricoesConfig = vi.fn();
const definirInicioInscricoes = vi.fn();
const definirPrazoInscricoes = vi.fn();
const getAvaliacaoConfig = vi.fn();
const definirLiberacaoAvaliacao = vi.fn();
const definirEncerramentoAvaliacao = vi.fn();
const definirInicioAjustes = vi.fn();
const definirFimAjustes = vi.fn();
vi.mock('../lib/admin.js', () => ({
    getInscricoesConfig: (...a) => getInscricoesConfig(...a),
    definirInicioInscricoes: (...a) => definirInicioInscricoes(...a),
    definirPrazoInscricoes: (...a) => definirPrazoInscricoes(...a),
    getAvaliacaoConfig: (...a) => getAvaliacaoConfig(...a),
    definirLiberacaoAvaliacao: (...a) => definirLiberacaoAvaliacao(...a),
    definirEncerramentoAvaliacao: (...a) => definirEncerramentoAvaliacao(...a),
    definirInicioAjustes: (...a) => definirInicioAjustes(...a),
    definirFimAjustes: (...a) => definirFimAjustes(...a),
}));

const getParametrizacaoCredenciamento = vi.fn();
const definirJanelaEvento = vi.fn();
vi.mock('../lib/credenciamento.js', () => ({
    getParametrizacaoCredenciamento: (...a) => getParametrizacaoCredenciamento(...a),
    definirJanelaEvento: (...a) => definirJanelaEvento(...a),
}));

const getAgendaPresencial = vi.fn();
const salvarAgendaPresencial = vi.fn();
vi.mock('../lib/presencial.js', () => ({
    getAgendaPresencial: (...a) => getAgendaPresencial(...a),
    salvarAgendaPresencial: (...a) => salvarAgendaPresencial(...a),
}));

import ParametrizacaoDatas from './ParametrizacaoDatas.jsx';

const TURNOS = [{ value: 'A', label: 'Turno A (matutino)' }, { value: 'B', label: 'Turno B (vespertino)' }];
const agendaPresencial = {
    dias: [{ dia: '2026-10-20', label: 'ter, 20/10' }, { dia: '2026-10-21', label: 'qua, 21/10' }],
    turnos: TURNOS,
    margem_padrao: 30,
    agenda: {
        '2026-10-20': { A: { inicio: '08:00', fim: '12:00', avaliacao_inicio: '08:30', avaliacao_fim: '12:15' }, B: null },
        '2026-10-21': { A: null, B: null },
    },
    ocorrencias: [],
};

const inscricoes = {
    abertas: true, encerradas: false, nao_iniciadas: false,
    inicio_input: null, inicio_label: null, prazo_input: null, prazo_label: null,
};
const avaliacao = {
    liberada: false, encerrada: false,
    liberada_em_input: null, liberada_em_label: null,
    encerrada_em_input: null, encerrada_em_label: null,
    ajustes_abertos: false, ajustes_de_input: null, ajustes_de_label: null,
    ajustes_ate_input: null, ajustes_ate_label: null,
};

describe('ParametrizacaoDatas', () => {
    beforeEach(() => {
        getInscricoesConfig.mockReset().mockResolvedValue(inscricoes);
        getAvaliacaoConfig.mockReset().mockResolvedValue(avaliacao);
        getParametrizacaoCredenciamento.mockReset().mockResolvedValue({
            config: { inicio_input: '2026-11-25T08:00', fim_input: '' },
        });
        definirInicioInscricoes.mockReset();
        definirLiberacaoAvaliacao.mockReset();
        definirInicioAjustes.mockReset();
        definirFimAjustes.mockReset();
        definirJanelaEvento.mockReset();
        getAgendaPresencial.mockReset().mockResolvedValue(agendaPresencial);
        salvarAgendaPresencial.mockReset().mockResolvedValue({ data: agendaPresencial, meta: { message: 'Horários dos turnos salvos.' } });
    });

    it('turnos da avaliação presencial: cada dia com o horário do turno e o da avaliação', async () => {
        render(<ParametrizacaoDatas />);

        expect(await screen.findByLabelText('ter, 20/10 · Turno A (matutino) · Início da avaliação')).toHaveValue('08:30');
        expect(screen.getByLabelText('qua, 21/10 · Turno B (vespertino) · Início do turno')).toHaveValue('');

        fireEvent.change(screen.getByLabelText('qua, 21/10 · Turno B (vespertino) · Início do turno'), { target: { value: '13:00' } });
        fireEvent.change(screen.getByLabelText('qua, 21/10 · Turno B (vespertino) · Fim do turno'), { target: { value: '17:00' } });
        fireEvent.click(screen.getByRole('button', { name: /Salvar horários/ }));

        await waitFor(() => expect(salvarAgendaPresencial).toHaveBeenCalledWith(expect.objectContaining({
            '2026-10-20': expect.objectContaining({ A: { inicio: '08:00', fim: '12:00', avaliacao_inicio: '08:30', avaliacao_fim: '12:15' } }),
            '2026-10-21': expect.objectContaining({ B: { inicio: '13:00', fim: '17:00', avaliacao_inicio: '', avaliacao_fim: '' } }),
        })));
        expect(await screen.findByText('Horários dos turnos salvos.')).toBeInTheDocument();
    });

    it('turnos: copia o primeiro dia para os demais e limpa um turno', async () => {
        render(<ParametrizacaoDatas />);

        fireEvent.click(await screen.findByRole('button', { name: /Copiar o primeiro dia para os demais/ }));
        expect(screen.getByLabelText('qua, 21/10 · Turno A (matutino) · Fim da avaliação')).toHaveValue('12:15');

        fireEvent.click(screen.getByRole('button', { name: 'Limpar Turno A (matutino) de qua, 21/10' }));
        expect(screen.getByLabelText('qua, 21/10 · Turno A (matutino) · Início do turno')).toHaveValue('');
    });

    it('turnos: sem período do evento, pede para definir antes', async () => {
        getAgendaPresencial.mockResolvedValue({ ...agendaPresencial, dias: [], agenda: {} });
        render(<ParametrizacaoDatas />);

        expect(await screen.findByText(/Defina o período do evento \(acima\)/)).toBeInTheDocument();
    });

    it('turnos: mostra o erro do servidor', async () => {
        salvarAgendaPresencial.mockRejectedValue({ response: { status: 422, data: {
            message: 'Dados inválidos.',
            errors: { 'agenda.2026-10-20.A.fim': ['20/10, Turno A (matutino): o turno precisa terminar depois de começar.'] },
        } } });
        render(<ParametrizacaoDatas />);

        fireEvent.click(await screen.findByRole('button', { name: /Salvar horários/ }));

        expect(await screen.findByText(/o turno precisa terminar depois de começar/)).toBeInTheDocument();
    });

    it('reúne as quatro janelas da edição numa tela só', async () => {
        render(<ParametrizacaoDatas />);

        expect(await screen.findByText('Inscrições')).toBeInTheDocument();
        expect(screen.getByText('Avaliação online')).toBeInTheDocument();
        expect(screen.getByText('Ajustes do orientador')).toBeInTheDocument();
        expect(screen.getByText('Credenciamento (evento)')).toBeInTheDocument();

        // As oito pontas de data estão todas aqui.
        expect(screen.getByLabelText('Data de abertura das inscrições')).toBeInTheDocument();
        expect(screen.getByLabelText('Data-limite de submissão')).toBeInTheDocument();
        expect(screen.getByLabelText('Data de liberação da avaliação')).toBeInTheDocument();
        expect(screen.getByLabelText('Data de encerramento da avaliação')).toBeInTheDocument();
        expect(screen.getByLabelText('Data de início do período de ajustes')).toBeInTheDocument();
        expect(screen.getByLabelText('Data de fim do período de ajustes')).toBeInTheDocument();
        expect(screen.getByLabelText('Início do período do evento')).toBeInTheDocument();
        expect(screen.getByLabelText('Fim do período do evento')).toBeInTheDocument();
    });

    it('salva a abertura das inscrições', async () => {
        definirInicioInscricoes.mockResolvedValue({
            data: { ...inscricoes, inicio_input: '2026-09-01T08:00', inicio_label: '01/09/2026 08:00', nao_iniciadas: true },
            meta: { message: 'Abertura salva.' },
        });
        render(<ParametrizacaoDatas />);

        fireEvent.change(await screen.findByLabelText('Data de abertura das inscrições'), {
            target: { value: '2026-09-01T08:00' },
        });
        fireEvent.click(screen.getByText('Salvar abertura'));

        await waitFor(() => expect(definirInicioInscricoes).toHaveBeenCalledWith('2026-09-01T08:00'));
        expect(await screen.findByText('Abertura salva.')).toBeInTheDocument();
        expect(screen.getByText('Abre em 01/09/2026 08:00')).toBeInTheDocument();
    });

    // Regressão do erro relatado em produção: o card repassa `resp.data` ao
    // `setConfig`, então o helper precisa devolver o envelope inteiro.
    it('salva as duas pontas do período de ajustes sem derrubar a tela', async () => {
        definirInicioAjustes.mockResolvedValue({
            data: { ...avaliacao, ajustes_de_input: '2026-11-01T08:00', ajustes_de_label: '01/11/2026 08:00' },
            meta: { message: 'Período de ajustes atualizado.' },
        });
        definirFimAjustes.mockResolvedValue({
            data: { ...avaliacao, ajustes_ate_input: '2026-11-20T18:00', ajustes_ate_label: '20/11/2026 18:00' },
            meta: { message: 'Período de ajustes atualizado.' },
        });
        render(<ParametrizacaoDatas />);

        fireEvent.change(await screen.findByLabelText('Data de início do período de ajustes'), {
            target: { value: '2026-11-01T08:00' },
        });
        fireEvent.click(screen.getByText('Salvar início dos ajustes'));

        await waitFor(() => expect(definirInicioAjustes).toHaveBeenCalledWith('2026-11-01T08:00'));
        expect(await screen.findByText('Abre em 01/11/2026 08:00')).toBeInTheDocument();
        // A tela continua de pé: os outros campos seguem renderizados.
        expect(screen.getByLabelText('Data de liberação da avaliação')).toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Data de fim do período de ajustes'), {
            target: { value: '2026-11-20T18:00' },
        });
        fireEvent.click(screen.getByText('Salvar fim dos ajustes'));

        await waitFor(() => expect(definirFimAjustes).toHaveBeenCalledWith('2026-11-20T18:00'));
        expect(await screen.findByText('Ajustes até 20/11/2026 18:00')).toBeInTheDocument();
    });

    it('sem início, avisa que a aba de ajustes fica fechada', async () => {
        render(<ParametrizacaoDatas />);

        expect(await screen.findByText('Aba fechada')).toBeInTheDocument();
    });

    it('salva o período do evento com as duas pontas de uma vez', async () => {
        definirJanelaEvento.mockResolvedValue({
            config: { inicio_input: '2026-11-25T08:00', fim_input: '2026-11-27T18:00' },
        });
        render(<ParametrizacaoDatas />);

        const fim = await screen.findByLabelText('Fim do período do evento');
        fireEvent.change(fim, { target: { value: '2026-11-27T18:00' } });
        fireEvent.click(screen.getByText('Salvar período'));

        await waitFor(() => expect(definirJanelaEvento)
            .toHaveBeenCalledWith('2026-11-25T08:00', '2026-11-27T18:00'));
        expect(await screen.findByText('Período do evento salvo.')).toBeInTheDocument();
    });

    it('mostra o erro de ordem vindo do backend', async () => {
        definirLiberacaoAvaliacao.mockRejectedValue({
            response: { status: 422, data: { message: 'A liberação precisa ser antes do encerramento.' } },
        });
        render(<ParametrizacaoDatas />);

        fireEvent.change(await screen.findByLabelText('Data de liberação da avaliação'), {
            target: { value: '2026-11-01T08:00' },
        });
        fireEvent.click(screen.getByText('Salvar início'));

        expect(await screen.findByText('A liberação precisa ser antes do encerramento.')).toBeInTheDocument();
    });
});
