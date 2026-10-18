import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));

const A = { chave: '2026-10-20|A', dia: '2026-10-20', turno: 'A', rotulo: '20/10 · Turno A (matutino) · 08:00–12:00', situacao: 'em_andamento', inicio_label: '08:00', fim_label: '12:00', avaliacao_inicio_label: '08:30', prazo_label: '12:30' };
const B = { chave: '2026-10-20|B', dia: '2026-10-20', turno: 'B', rotulo: '20/10 · Turno B (vespertino) · 13:30–17:30', situacao: 'futuro', fim_label: '17:30', prazo_label: '18:00' };

const PAINEL = {
    fila_avaliador: 5,
    por_projeto: 3,
    comuns_por_projeto: 2,
    comite_por_projeto: 1,
    peso_comite: 2,
    configurada: true,
    evento_de_label: '20/10/2026',
    evento_ate_label: '21/10/2026',
    ocorrencias: [A, B],
    foco: A,
    turnos: [{ value: 'A', label: 'Turno A (matutino)' }, { value: 'B', label: 'Turno B (vespertino)' }],
    lista: { id: 1, nome: 'Final', versao: 1, demo: false },
    modo_teste: false,
    is_demo: false,
    avaliadores: [
        { id: 7, nome: 'Ana Avaliadora', area: 'Agrárias', subarea: null, comissao: true, liberado: true, no_turno: 0, concluidas: 0 },
        { id: 8, nome: 'Bruno', area: 'Biológicas', subarea: null, comissao: false, liberado: true, no_turno: 1, concluidas: 0 },
        { id: 9, nome: 'Carla Pendente', area: 'Biológicas', subarea: null, comissao: false, liberado: false, no_turno: 0, concluidas: 0 },
    ],
    resumo: { projetos: 3, prontos: 1, sem_credenciamento: 1, sem_checagem: 1, cobertos: 0, designacoes: 1, concluidas: 0, teto: 3 },
    projetos: [{ id: 30, titulo: 'Abelhas', estande: 12, pronto: true, avaliacoes: 1, comuns: 0, comite: 1 }],
    designacoes: [{ id: 90, projeto_id: 30, projeto: 'Abelhas', estande: 12, avaliador_id: 8, avaliador: 'Bruno', status: 'designada', status_label: 'Designada', expirada: false, designacao_manual: false, vaga: 'comite' }],
};

const getDistribuicaoPresencial = vi.fn(() => Promise.resolve(PAINEL));
const designarPresencial = vi.fn(() => Promise.resolve({ data: PAINEL, meta: { message: '1 designação(ões) criada(s).', resultado: { ignoradas: [] } } }));
const distribuirPresencial = vi.fn(() => Promise.resolve({
    data: PAINEL,
    meta: { message: '1 designação(ões) criada(s) para 2 avaliador(es).', resultado: { designadas: 1 } },
}));
const retirarDesignacaoPresencial = vi.fn(() => Promise.resolve({ data: { ...PAINEL, designacoes: [] }, meta: { message: 'Designação retirada.' } }));
const salvarDistribuicaoPresencial = vi.fn(() => Promise.resolve({ data: PAINEL, meta: { message: 'Turnos e números da distribuição salvos.' } }));

vi.mock('../lib/presencial.js', () => ({
    getDistribuicaoPresencial: (...a) => getDistribuicaoPresencial(...a),
    salvarDistribuicaoPresencial: (...a) => salvarDistribuicaoPresencial(...a),
    distribuirPresencial: (...a) => distribuirPresencial(...a),
    redistribuirPresencial: vi.fn(),
    designarPresencial: (...a) => designarPresencial(...a),
    retirarDesignacaoPresencial: (...a) => retirarDesignacaoPresencial(...a),
}));

import PresencialDistribuicao from './PresencialDistribuicao.jsx';

describe('PresencialDistribuicao', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('mostra o turno em foco, o horário do turno e o da avaliação, e o resumo', async () => {
        render(<PresencialDistribuicao />);

        expect(await screen.findByText('Acontecendo agora')).toBeInTheDocument();
        expect(screen.getByText(/Turno das 08:00 às 12:00; avaliação das/)).toHaveTextContent('avaliação das 08:30 às 12:30');
        expect(screen.getByText('Sem checagem')).toBeInTheDocument();
    });

    it('lista quem confirmou o presencial e designa só quem foi liberado', async () => {
        render(<PresencialDistribuicao />);

        expect(await screen.findByText('3 confirmado(s) · 2 liberado(s)')).toBeInTheDocument();
        expect(screen.getByText('Comissão especial')).toBeInTheDocument();
        expect(screen.getByText('Aguardando liberação')).toBeInTheDocument();
        // Só os liberados podem receber designação.
        expect(screen.queryByRole('option', { name: 'Carla Pendente' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Ativar/ })).not.toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Projeto para designar'), { target: { value: '30' } });
        fireEvent.change(screen.getByLabelText('Avaliador para designar'), { target: { value: '7' } });
        fireEvent.click(screen.getByRole('button', { name: 'Designar' }));

        await waitFor(() => expect(designarPresencial).toHaveBeenCalledWith(
            { dia: '2026-10-20', turno: 'A' }, [30], [7], false,
        ));
    });

    it('distribui o turno e mostra o resultado', async () => {
        render(<PresencialDistribuicao />);

        fireEvent.click(await screen.findByRole('button', { name: /Distribuir/ }));

        await waitFor(() => expect(distribuirPresencial).toHaveBeenCalledWith({ dia: '2026-10-20', turno: 'A' }, false));
        expect(await screen.findByText(/1 designação\(ões\) criada\(s\)/)).toBeInTheDocument();
    });

    it('retira uma designação que ainda não virou nota', async () => {
        render(<PresencialDistribuicao />);

        fireEvent.click(await screen.findByRole('button', { name: 'Retirar Abelhas de Bruno' }));

        await waitFor(() => expect(retirarDesignacaoPresencial).toHaveBeenCalledWith(90, { dia: '2026-10-20', turno: 'A' }, false));
        expect(await screen.findByText('Nenhuma designação neste turno.')).toBeInTheDocument();
    });

    it('salva as vagas comuns, as da comissão e o peso', async () => {
        render(<PresencialDistribuicao />);

        expect(await screen.findByText('· comissão')).toBeInTheDocument();
        expect(screen.getByRole('option', { name: /Abelhas \(0 comum · 1 comissão\)/ })).toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Avaliações comuns por projeto'), { target: { value: '3' } });
        fireEvent.change(screen.getByLabelText('Avaliações da comissão por projeto'), { target: { value: '0' } });
        fireEvent.change(screen.getByLabelText('Peso da comissão na média'), { target: { value: '' } });
        fireEvent.click(screen.getByRole('button', { name: 'Salvar' }));

        await waitFor(() => expect(salvarDistribuicaoPresencial).toHaveBeenCalledWith(
            expect.objectContaining({ comuns_por_projeto: 3, comite_por_projeto: 0, peso_comite: null }),
            { dia: '2026-10-20', turno: 'A' },
            false,
        ));
    });

    it('os horários moram em Datas e períodos: aqui só os números', async () => {
        render(<PresencialDistribuicao />);

        expect(await screen.findByText('Números da distribuição')).toBeInTheDocument();
        expect(screen.queryByLabelText('Fim do Turno A (matutino)')).not.toBeInTheDocument();
        expect(screen.getByText('Parametrização → Datas e períodos')).toBeInTheDocument();
        expect(screen.getByText(/Turno das 08:00 às 12:00; avaliação das/)).toBeInTheDocument();
    });
});
