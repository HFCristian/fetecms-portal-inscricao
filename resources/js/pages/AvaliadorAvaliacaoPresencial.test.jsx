import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../components/VideoPreview.jsx', () => ({ default: () => null }));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.message ?? 'Erro.', fields: e?.fields ?? {} }),
}));

const getPainelPresencial = vi.fn();
const getAvaliacaoPresencial = vi.fn();
const iniciarAvaliacaoPresencial = vi.fn();
const salvarRascunhoPresencial = vi.fn();
const concluirAvaliacaoPresencial = vi.fn();
vi.mock('../lib/avaliacaoPresencial.js', () => ({
    getPainelPresencial: (...a) => getPainelPresencial(...a),
    getAvaliacaoPresencial: (...a) => getAvaliacaoPresencial(...a),
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
            chave: 'apresentacao', titulo: 'Apresentação', icone: 'mic', ajuda: 'Clareza.', maximo: 10,
            perguntas: [{ chave: 'clareza', texto: 'A equipe apresentou com clareza?', ajuda: 'Considere a ordem.', peso: 10 }],
        },
        { chave: 'final', titulo: 'Parecer', icone: 'rate_review', ajuda: '', componente: 'comentarios', perguntas: [] },
    ],
};

const ITENS = [{ id: 3, nome: 'Banner montado', descricao: null }];

const PAINEL = {
    aberto: true, motivo_fechado: null, aceitou: true, modo_teste: false, is_demo: false,
    turno: { chave: '2026-10-20|A', rotulo: '20/10 · Turno A (matutino) · 08:00–12:00', prazo_label: '12:30' },
    meus_turnos: [], margem_minutos: 30, max_por_projeto: 3,
    rubrica: RUBRICA, itens: ITENS,
    minhas: [],
    disponiveis: [{ id: 7, titulo: 'Bioplástico', area: 'Agrárias', local: { estande: 42, turno_label: 'Turno A (matutino)' }, avaliacoes: 1 }],
};

const AVALIACAO = {
    id: 5, status: 'em_andamento', status_label: 'Em andamento', pode_escrever: true, prazo_label: '20/10 12:30',
    respostas: {}, itens: {}, comentario: null, nota: null, nota_maxima: 10,
    projeto: {
        id: 7, titulo: 'Bioplástico', categoria: 'FETECMS', area: 'Agrárias', escola: 'EE Alfa',
        orientador: 'Marta', alunos: ['Ana'], resumo: 'Plástico de mandioca.', palavras_chave: [],
        documentos: [{ id: 1, nome_original: 'plano.pdf', download_url: '/api/v1/documentos/1/download' }],
        local: { estande: 42, turno_label: 'Turno A (matutino)' },
    },
};

import AvaliadorAvaliacaoPresencial from './AvaliadorAvaliacaoPresencial.jsx';

/** Abre o estande disponível, passando pela confirmação. */
async function abrirDisponivel() {
    fireEvent.click(await screen.findByRole('button', { name: 'Avaliar Bioplástico' }));
    const confirmar = await screen.findByRole('button', { name: 'Iniciar' });
    fireEvent.click(confirmar);
}

describe('AvaliadorAvaliacaoPresencial', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        getPainelPresencial.mockResolvedValue(PAINEL);
        iniciarAvaliacaoPresencial.mockResolvedValue(AVALIACAO);
        salvarRascunhoPresencial.mockResolvedValue({ data: AVALIACAO, meta: { message: 'Rascunho salvo.' } });
        concluirAvaliacaoPresencial.mockResolvedValue({ data: { ...AVALIACAO, status: 'concluida', status_label: 'Concluída', nota: 10 }, meta: {} });
    });

    it('mostra o turno, o prazo e os estandes disponíveis', async () => {
        render(<AvaliadorAvaliacaoPresencial />);

        expect(await screen.findByText(/avaliações aceitas até as 12:30/)).toBeInTheDocument();
        expect(screen.getByText(/Estande 42 · Turno A \(matutino\) · Agrárias · 1 de 3 avaliações/)).toBeInTheDocument();
    });

    it('fora do turno a aba explica e não oferece estande', async () => {
        getPainelPresencial.mockResolvedValue({
            ...PAINEL, aberto: false, turno: null, disponiveis: [],
            motivo_fechado: 'A avaliação presencial abre durante os turnos do evento. Próximo turno: 20/10 · Turno B.',
        });
        render(<AvaliadorAvaliacaoPresencial />);

        expect(await screen.findByText(/Próximo turno: 20\/10 · Turno B/)).toBeInTheDocument();
        expect(screen.queryByText('Estandes disponíveis')).not.toBeInTheDocument();
    });

    it('wizard: uma seção por passo, checklist dos itens e envio só completo', async () => {
        render(<AvaliadorAvaliacaoPresencial />);
        await abrirDisponivel();

        await waitFor(() => expect(iniciarAvaliacaoPresencial).toHaveBeenCalledWith(7, false));
        expect(await screen.findByText('A equipe apresentou com clareza?', { selector: 'p' })).toBeInTheDocument();
        expect(screen.getByText('Passo 1 de 3')).toBeInTheDocument();
        // A leitura do projeto vem junto, com o documento para baixar.
        fireEvent.click(screen.getByRole('button', { name: /Mostrar/ }));
        expect(screen.getByRole('link', { name: /plano.pdf/ })).toHaveAttribute('href', '/api/v1/documentos/1/download');

        fireEvent.click(screen.getByLabelText('Muito bom (10)'));
        fireEvent.click(screen.getByRole('button', { name: 'Avançar' }));
        expect(await screen.findByRole('heading', { name: 'Itens do estande' })).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Avançar' }));
        const enviar = await screen.findByRole('button', { name: /Enviar avaliação/ });
        // Falta o checklist.
        expect(enviar).toBeDisabled();

        fireEvent.click(screen.getByRole('button', { name: 'Ir para Itens do estande' }));
        fireEvent.click(screen.getByLabelText('Banner montado: Presente'));
        fireEvent.click(screen.getByRole('button', { name: 'Ir para Parecer' }));
        fireEvent.click(screen.getByRole('button', { name: /Enviar avaliação/ }));
        fireEvent.click(await screen.findByRole('button', { name: 'Enviar' }));

        await waitFor(() => expect(concluirAvaliacaoPresencial).toHaveBeenCalledWith(
            5, { respostas: { clareza: 10 }, itens: { 3: 'presente' }, comentario: null }, false,
        ));
        expect(await screen.findByText(/Avaliação concluída — nota/)).toBeInTheDocument();
    });

    it('salva o rascunho com o checklist', async () => {
        render(<AvaliadorAvaliacaoPresencial />);
        await abrirDisponivel();

        fireEvent.click(await screen.findByLabelText('Bom (8)'));
        fireEvent.click(screen.getByRole('button', { name: /Salvar rascunho/ }));

        await waitFor(() => expect(salvarRascunhoPresencial).toHaveBeenCalledWith(
            5, { respostas: { clareza: 8 }, itens: {}, comentario: null }, false,
        ));
    });

    it('designada pela distribuição: abre para ler e pede para iniciar', async () => {
        getPainelPresencial.mockResolvedValue({
            ...PAINEL,
            minhas: [{ id: 5, projeto_id: 7, titulo: 'Bioplástico', status: 'designada', status_label: 'Designada', pode_escrever: true, prazo_label: '20/10 12:30', local: {} }],
        });
        getAvaliacaoPresencial.mockResolvedValue({ ...AVALIACAO, status: 'designada', status_label: 'Designada' });
        render(<AvaliadorAvaliacaoPresencial />);

        fireEvent.click(await screen.findByRole('button', { name: 'Abrir Bioplástico' }));
        expect(await screen.findByText('Plástico de mandioca.')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Iniciar avaliação' }));
        fireEvent.click(await screen.findByRole('button', { name: 'Iniciar' }));

        await waitFor(() => expect(iniciarAvaliacaoPresencial).toHaveBeenCalledWith(7, false));
    });

    it('avaliação com prazo encerrado abre só para leitura', async () => {
        getPainelPresencial.mockResolvedValue({
            ...PAINEL,
            minhas: [{ id: 5, projeto_id: 7, titulo: 'Bioplástico', status: 'em_andamento', status_label: 'Prazo encerrado', expirada: true, pode_escrever: false, local: {} }],
        });
        getAvaliacaoPresencial.mockResolvedValue({ ...AVALIACAO, status_label: 'Prazo encerrado', pode_escrever: false });
        render(<AvaliadorAvaliacaoPresencial />);

        fireEvent.click(await screen.findByRole('button', { name: 'Abrir Bioplástico' }));

        expect(await screen.findByText(/O prazo desta avaliação acabou com o turno/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Salvar rascunho/ })).not.toBeInTheDocument();
    });
});
