import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.message ?? 'Erro.', fields: e?.fields ?? {} }),
}));

const getPresencial = vi.fn();
const responderPresencial = vi.fn();
const getPainelPresencial = vi.fn();
const iniciarAvaliacaoPresencial = vi.fn();
const salvarRascunhoPresencial = vi.fn();
const concluirAvaliacaoPresencial = vi.fn();
vi.mock('../lib/avaliacaoPresencial.js', () => ({
    getPresencial: (...a) => getPresencial(...a),
    responderPresencial: (...a) => responderPresencial(...a),
    getPainelPresencial: (...a) => getPainelPresencial(...a),
    iniciarAvaliacaoPresencial: (...a) => iniciarAvaliacaoPresencial(...a),
    salvarRascunhoPresencial: (...a) => salvarRascunhoPresencial(...a),
    concluirAvaliacaoPresencial: (...a) => concluirAvaliacaoPresencial(...a),
}));

const RUBRICA = {
    nota_maxima: 10,
    escala: [
        { valor: 0, rotulo: 'Não possui' },
        { valor: 8, rotulo: 'Bom' },
        { valor: 10, rotulo: 'Muito bom' },
    ],
    secoes: [
        {
            chave: 'apresentacao', titulo: 'Apresentação', icone: 'mic', ajuda: 'Clareza.',
            maximo: 1.5,
            perguntas: [{ chave: 'clareza', texto: 'A equipe apresentou com clareza?', ajuda: null, peso: 1.5 }],
        },
        { chave: 'final', titulo: 'Parecer', icone: 'rate_review', ajuda: '', perguntas: [] },
    ],
};

const PAINEL = {
    aberto: true, motivo_fechado: null, aceitou: true, max_por_projeto: 3,
    rubrica: RUBRICA,
    minhas: [],
    disponiveis: [
        {
            id: 7, titulo: 'Bioplástico', area: 'Agrárias', escola: 'EE Alfa',
            local: { estande: 42, turno_label: 'Matutino' }, avaliacoes: 1,
        },
    ],
};

const AVALIACAO = {
    id: 5, status: 'em_andamento', status_label: 'Em andamento',
    respostas: {}, comentario: null, nota: null, nota_maxima: 10,
    projeto: {
        id: 7, titulo: 'Bioplástico', categoria: 'FETECMS', area: 'Agrárias',
        escola: 'EE Alfa', orientador: 'Marta', alunos: ['Ana'],
        local: { estande: 42, turno_label: 'Matutino' },
    },
};

import AvaliadorPresencial from './AvaliadorPresencial.jsx';

const SEM_RESPOSTA = {
    respondido: false, presencial: null, pode_alterar: true, evento_iniciado: false,
    evento_de_label: '20/10/2026 08:00', evento_ate_label: '22/10/2026 18:00',
    informacoes: null, is_demo: false,
};

const ACEITOU = {
    ...SEM_RESPOSTA,
    respondido: true, presencial: true,
    informacoes: 'Chegue às 7h30 no ginásio, com o crachá.',
};

describe('AvaliadorPresencial', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        getPresencial.mockResolvedValue(SEM_RESPOSTA);
        getPainelPresencial.mockResolvedValue({ ...PAINEL, aceitou: false, aberto: false, disponiveis: [] });
        iniciarAvaliacaoPresencial.mockResolvedValue(AVALIACAO);
        concluirAvaliacaoPresencial.mockResolvedValue({
            data: { ...AVALIACAO, status: 'concluida', nota: 10 },
            meta: { message: 'Avaliação enviada.' },
        });
        responderPresencial.mockResolvedValue({
            data: ACEITOU,
            meta: { message: 'Participação presencial confirmada.' },
        });
    });

    it('pergunta e avisa que ainda não houve resposta', async () => {
        render(<AvaliadorPresencial />);

        expect(await screen.findByText(/Você quer avaliar presencialmente/i)).toBeInTheDocument();
        expect(screen.getByText('Você ainda não respondeu.')).toBeInTheDocument();
        // Sem "sim" não há orientações na tela.
        expect(screen.queryByText(/Orientações para o dia do evento/i)).not.toBeInTheDocument();
    });

    it('aceitar mostra as orientações da organização', async () => {
        render(<AvaliadorPresencial />);

        fireEvent.click(await screen.findByRole('button', { name: /Sim, quero participar/i }));

        await waitFor(() => expect(responderPresencial).toHaveBeenCalledWith(true, false));
        expect(await screen.findByText(/Chegue às 7h30 no ginásio/)).toBeInTheDocument();
        expect(screen.getByText('Participação presencial confirmada.')).toBeInTheDocument();
    });

    it('recusar esconde as orientações e mantém a pergunta', async () => {
        getPresencial.mockResolvedValue({ ...SEM_RESPOSTA, respondido: true, presencial: false });
        render(<AvaliadorPresencial />);

        expect(await screen.findByText(/sua avaliação online continua valendo/i)).toBeInTheDocument();
        expect(screen.queryByText(/Orientações para o dia do evento/i)).not.toBeInTheDocument();
        // Ainda dá para voltar atrás.
        expect(screen.getByRole('button', { name: /Sim, quero participar/i })).toBeEnabled();
    });

    it('quem aceitou mas ainda não tem orientações publicadas é avisado', async () => {
        getPresencial.mockResolvedValue({ ...ACEITOU, informacoes: null });
        render(<AvaliadorPresencial />);

        expect(await screen.findByText(/ainda não publicou as orientações/i)).toBeInTheDocument();
    });

    it('com o evento em curso os botões travam e a tela explica', async () => {
        getPresencial.mockResolvedValue({ ...ACEITOU, pode_alterar: false, evento_iniciado: true });
        render(<AvaliadorPresencial />);

        expect(await screen.findByText(/fale com a organização/i)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Não vou participar/i })).toBeDisabled();
    });
});

describe('AvaliadorPresencial — a avaliação no estande', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        getPresencial.mockResolvedValue(ACEITOU);
        getPainelPresencial.mockResolvedValue(PAINEL);
        responderPresencial.mockResolvedValue({ data: ACEITOU, meta: { message: '' } });
        iniciarAvaliacaoPresencial.mockResolvedValue(AVALIACAO);
        salvarRascunhoPresencial.mockResolvedValue({ data: AVALIACAO, meta: { message: 'Rascunho salvo.' } });
        concluirAvaliacaoPresencial.mockResolvedValue({
            data: { ...AVALIACAO, status: 'concluida', nota: 10 },
            meta: { message: 'Avaliação enviada.' },
        });
    });

    it('lista os estandes disponíveis com o número e quantas avaliações já têm', async () => {
        render(<AvaliadorPresencial />);

        expect(await screen.findByText('Estandes disponíveis')).toBeInTheDocument();
        expect(screen.getByText('Bioplástico')).toBeInTheDocument();
        expect(screen.getByText(/Estande 42 · Matutino · Agrárias · 1 de 3 avaliações/)).toBeInTheDocument();
    });

    it('abre a rubrica do estande escolhido', async () => {
        render(<AvaliadorPresencial />);

        fireEvent.click(await screen.findByRole('button', { name: 'Avaliar' }));

        await waitFor(() => expect(iniciarAvaliacaoPresencial).toHaveBeenCalledWith(7, false));
        expect(await screen.findByText('A equipe apresentou com clareza?')).toBeInTheDocument();
        // A nota parcial não aparece: ela é calculada no envio, no servidor.
        expect(screen.getByText('0 de 1 perguntas respondidas')).toBeInTheDocument();
    });

    it('só envia com a rubrica inteira respondida', async () => {
        render(<AvaliadorPresencial />);

        fireEvent.click(await screen.findByRole('button', { name: 'Avaliar' }));
        const enviar = await screen.findByRole('button', { name: /Enviar avaliação/i });

        expect(enviar).toBeDisabled();

        fireEvent.click(screen.getByRole('button', { name: 'Muito bom' }));
        expect(await screen.findByText('1 de 1 perguntas respondidas')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /Enviar avaliação/i }));

        await waitFor(() => expect(concluirAvaliacaoPresencial).toHaveBeenCalledWith(
            5, { respostas: { clareza: 10 }, comentario: null }, false,
        ));
    });

    it('avisa quando o projeto já foi tomado por outro avaliador', async () => {
        iniciarAvaliacaoPresencial.mockRejectedValue({
            fields: { projeto: 'Este projeto já recebeu o número máximo de avaliações presenciais.' },
        });
        render(<AvaliadorPresencial />);

        fireEvent.click(await screen.findByRole('button', { name: 'Avaliar' }));

        expect(await screen.findByText(/número máximo de avaliações presenciais/)).toBeInTheDocument();
    });

    it('fora do evento a lista some e o motivo aparece', async () => {
        getPainelPresencial.mockResolvedValue({
            ...PAINEL, aberto: false, disponiveis: [],
            motivo_fechado: 'A avaliação presencial abre no início do evento.',
        });
        render(<AvaliadorPresencial />);

        expect(await screen.findByText('A avaliação presencial abre no início do evento.')).toBeInTheDocument();
        expect(screen.queryByText('Estandes disponíveis')).not.toBeInTheDocument();
    });
});
