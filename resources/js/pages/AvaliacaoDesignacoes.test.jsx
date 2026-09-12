import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));
// Espelha o extractErrors de verdade: mensagem geral + os erros por campo, que
// é de onde saem as recusas de validação (422) que a tela mostra.
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({
        message: e?.response?.data?.message ?? e?.message ?? '',
        fields: e?.response?.data?.errors
            ? Object.fromEntries(Object.entries(e.response.data.errors).map(([k, v]) => [k, v[0]]))
            : {},
    }),
}));

const LINHAS = [
    {
        id: 10, projeto_id: 1, projeto: 'Robô seguidor', area: 'Ciências Exatas',
        categoria: 'fetecms', categoria_label: 'FETECMS',
        avaliador_id: 5, avaliador: 'Ana Souza', situacao: 'designada', situacao_label: 'Designada',
        designacao_manual: false, designado_em_label: '20/08/2026 09:00', horas: 72,
        tempo_label: 'há 3 dias', pode_retirar: true,
    },
    {
        id: 11, projeto_id: 2, projeto: 'Horta vertical', area: 'Ciências Agrárias',
        categoria: 'fetec_jr', categoria_label: 'FETEC Jr',
        avaliador_id: 6, avaliador: 'Bruno Lima', situacao: 'em_andamento', situacao_label: 'Em andamento',
        designacao_manual: true, designado_em_label: '22/08/2026 10:00', horas: 20,
        tempo_label: 'há 20 horas', pode_retirar: true,
    },
    {
        id: 12, projeto_id: 3, projeto: 'Ponte de palito', area: 'Ciências Exatas',
        categoria: 'fetecms', categoria_label: 'FETECMS',
        avaliador_id: 7, avaliador: 'Carla Dias', situacao: 'concluida', situacao_label: 'Concluída',
        designacao_manual: false, designado_em_label: '10/08/2026 08:00', horas: 400,
        tempo_label: 'há 16 dias', pode_retirar: false,
    },
];

const META = {
    pagina_atual: 1, ultima_pagina: 1, total: 3, por_pagina: 25,
    resumo: { total: 3, designada: 1, em_andamento: 1, concluida: 1 },
    areas: [{ id: 1, nome: 'Ciências Exatas' }],
    categorias: [{ value: 'fetecms', label: 'FETECMS' }],
    situacoes: [
        { value: 'designada', label: 'Designada' },
        { value: 'em_andamento', label: 'Em andamento' },
        { value: 'concluida', label: 'Concluída' },
    ],
    ordenar: 'designado_em', direcao: 'asc',
};

const getDesignacoes = vi.fn();
const retirarDesignacoes = vi.fn();
const getOpcoesDesignacao = vi.fn();
const designarEmMassa = vi.fn();
const verNotasDaDesignacao = vi.fn();
vi.mock('../lib/admin.js', () => ({
    getDesignacoes: (...a) => getDesignacoes(...a),
    retirarDesignacoes: (...a) => retirarDesignacoes(...a),
    getOpcoesAvaliadores: () => Promise.resolve([{ id: 5, nome: 'Ana Souza', area: 'Ciências Exatas' }]),
    getOpcoesDesignacao: (...a) => getOpcoesDesignacao(...a),
    designarEmMassa: (...a) => designarEmMassa(...a),
    verNotasDaDesignacao: (...a) => verNotasDaDesignacao(...a),
}));

const NOTAS = {
    avaliacao_id: 12,
    projeto: { id: 3, titulo: 'Ponte de palito', area: 'Ciências Exatas', categoria: 'FETECMS' },
    avaliador: 'Carla Dias',
    concluida_em: '2026-08-20T10:00:00-04:00',
    concluida_em_label: '20/08/2026 10:00',
    secoes: [
        {
            chave: 'introducao', titulo: 'Introdução', pontos: 0.86, maximo: 1.08,
            perguntas: [
                { chave: 'intro_1', rotulo: 'Contextualização', pergunta: 'O tema está contextualizado?', tipo: 'escala', resposta: '8 — Bom', respondida: true, peso: 0.54, pontos: 0.43 },
                { chave: 'intro_2', rotulo: 'Justificativa', pergunta: 'A justificativa é clara?', tipo: 'escala', resposta: null, respondida: false, peso: 0.54, pontos: 0 },
            ],
        },
        {
            chave: 'video', titulo: 'Vídeo', pontos: 2, maximo: 2,
            perguntas: [
                { chave: 'video_1', rotulo: 'Domínio do tema', pergunta: 'A equipe domina o tema?', tipo: 'sim_nao', resposta: 'Sim', respondida: true, peso: 2, pontos: 2 },
            ],
        },
    ],
    nota: 8.45,
    nota_calculada: 8.45,
    nota_maxima: 10,
    recomendacao_video: 'Melhorar o áudio da narração.',
    recomendacao_projeto: 'Detalhar a metodologia.',
};

const OPCOES = {
    projetos: [
        { id: 1, titulo: 'Robô seguidor', area: 'Ciências Exatas', categoria: 'FETECMS', concluidas: 1 },
        { id: 2, titulo: 'Horta vertical', area: 'Ciências Agrárias', categoria: 'FETEC Jr', concluidas: 0 },
    ],
    avaliadores: [
        { id: 5, nome: 'Ana Souza', email: 'ana@ex.test', area: 'Ciências Exatas', na_fila: 3 },
        { id: 6, nome: 'Bruno Lima', email: 'bruno@ex.test', area: 'Ciências Agrárias', na_fila: 1 },
    ],
};

import AvaliacaoDesignacoes from './AvaliacaoDesignacoes.jsx';

describe('AvaliacaoDesignacoes', () => {
    beforeEach(() => {
        getDesignacoes.mockReset().mockResolvedValue({ data: LINHAS, meta: META });
        retirarDesignacoes.mockReset().mockResolvedValue({
            data: { retiradas: 1, redesignadas: 1, sem_avaliador: [] },
            meta: { message: '1 designação(ões) retirada(s), 1 redesignada(s) na hora.' },
        });
        getOpcoesDesignacao.mockReset().mockResolvedValue(OPCOES);
        designarEmMassa.mockReset();
        verNotasDaDesignacao.mockReset().mockResolvedValue(NOTAS);
    });

    /**
     * Abre o diálogo de designação em massa e devolve o escopo dele — a tabela
     * atrás tem caixas de marcação com os mesmos nomes de projeto.
     */
    async function abrirDesignar() {
        fireEvent.click(await screen.findByRole('button', { name: 'Designar' }));
        await screen.findByText('Designar projetos');
        await waitFor(() => expect(getOpcoesDesignacao).toHaveBeenCalled());

        return within(screen.getByRole('dialog'));
    }

    it('designa vários projetos para vários avaliadores de uma vez', async () => {
        designarEmMassa.mockResolvedValue({
            data: {
                designadas: 4, retomadas: 0, ignoradas: [],
                avaliadores: [
                    { id: 5, nome: 'Ana Souza', projetos: ['Robô seguidor', 'Horta vertical'] },
                    { id: 6, nome: 'Bruno Lima', projetos: ['Robô seguidor', 'Horta vertical'] },
                ],
                resumo: '4 designação(ões) criada(s) para 2 avaliador(es).',
                problemas: 'Todas as designações foram realizadas, sem nenhum problema.',
            },
            meta: { message: '4 designação(ões) criada(s) para 2 avaliador(es). Todas as designações foram realizadas, sem nenhum problema.' },
        });

        render(<AvaliacaoDesignacoes />);
        const dialogo = await abrirDesignar();

        // Sem nada marcado não há o que designar.
        expect(dialogo.getByRole('button', { name: 'Designar' })).toBeDisabled();

        fireEvent.click(dialogo.getByRole('checkbox', { name: /Robô seguidor/ }));
        fireEvent.click(dialogo.getByRole('checkbox', { name: /Horta vertical/ }));
        fireEvent.click(dialogo.getByRole('checkbox', { name: /Ana Souza/ }));
        fireEvent.click(dialogo.getByRole('checkbox', { name: /Bruno Lima/ }));

        // A conta do cruzamento fica à vista antes de confirmar.
        expect(dialogo.getByText(/2 projeto\(s\) × 2 avaliador\(es\) =/)).toBeInTheDocument();
        expect(dialogo.getByText('4 designação(ões)')).toBeInTheDocument();

        fireEvent.click(dialogo.getByRole('button', { name: 'Designar' }));

        await waitFor(() => expect(designarEmMassa).toHaveBeenCalledWith([1, 2], [5, 6]));
        expect(await screen.findByText('4 designação(ões) criada(s) para 2 avaliador(es).')).toBeInTheDocument();
        // O resultado lista o que foi para cada avaliador (os dois receberam os
        // mesmos dois projetos, daí as duas linhas iguais).
        expect(screen.getAllByText('Robô seguidor · Horta vertical')).toHaveLength(2);
        expect(screen.getByText(/Cada avaliador recebeu um e-mail/)).toBeInTheDocument();
    });

    it('mostra quem ficou de fora por já ter avaliado o projeto', async () => {
        designarEmMassa.mockResolvedValue({
            data: {
                designadas: 1, retomadas: 0,
                ignoradas: [{ projeto: 'Robô seguidor', avaliador: 'Ana Souza', motivo: 'já avaliou este projeto' }],
                avaliadores: [{ id: 6, nome: 'Bruno Lima', projetos: ['Robô seguidor'] }],
                resumo: '1 designação(ões) criada(s) para 1 avaliador(es).',
                problemas: '1 designação(ões) não foram feitas:',
            },
            meta: { message: 'ok' },
        });

        render(<AvaliacaoDesignacoes />);
        const dialogo = await abrirDesignar();

        fireEvent.click(dialogo.getByRole('checkbox', { name: /Robô seguidor/ }));
        fireEvent.click(dialogo.getByRole('checkbox', { name: /Ana Souza/ }));
        fireEvent.click(dialogo.getByRole('button', { name: 'Designar' }));

        expect(await screen.findByText(/1 designação\(ões\) não foram feitas/)).toBeInTheDocument();
        expect(screen.getByText(/Robô seguidor → Ana Souza: já avaliou este projeto/)).toBeInTheDocument();
    });

    it('busca projeto e avaliador no servidor', async () => {
        render(<AvaliacaoDesignacoes />);
        const dialogo = await abrirDesignar();

        fireEvent.change(dialogo.getByLabelText('Buscar projeto'), { target: { value: 'horta' } });

        await waitFor(() =>
            expect(getOpcoesDesignacao).toHaveBeenLastCalledWith({ projeto: 'horta' }));
    });

    it('mostra cada designação com o tempo que está com o avaliador', async () => {
        render(<AvaliacaoDesignacoes />);

        expect(await screen.findByText('Robô seguidor')).toBeInTheDocument();
        expect(screen.getByText('há 3 dias')).toBeInTheDocument();
        expect(screen.getByText('Ana Souza')).toBeInTheDocument();
        expect(screen.getByText('designação manual')).toBeInTheDocument();
    });

    it('não deixa marcar avaliação concluída', async () => {
        render(<AvaliacaoDesignacoes />);

        const concluida = await screen.findByLabelText('Retirar Ponte de palito de Carla Dias');
        expect(concluida).toBeDisabled();
        expect(screen.getByLabelText('Retirar Robô seguidor de Ana Souza')).toBeEnabled();
    });

    it('avisa que o rascunho será descartado ao retirar uma avaliação em andamento', async () => {
        render(<AvaliacaoDesignacoes />);

        fireEvent.click(await screen.findByLabelText('Retirar Horta vertical de Bruno Lima'));
        fireEvent.click(screen.getByRole('button', { name: /Retirar \(1\)/ }));

        expect(await screen.findByText(/descarta o rascunho/)).toBeInTheDocument();
    });

    it('retira as marcadas e recarrega a tabela', async () => {
        render(<AvaliacaoDesignacoes />);

        fireEvent.click(await screen.findByLabelText('Retirar Robô seguidor de Ana Souza'));
        fireEvent.click(screen.getByRole('button', { name: /Retirar \(1\)/ }));
        fireEvent.click(await screen.findByRole('button', { name: 'Retirar e redesignar' }));

        await waitFor(() => expect(retirarDesignacoes).toHaveBeenCalledWith([10]));
        expect(await screen.findByText(/1 redesignada\(s\) na hora/)).toBeInTheDocument();
    });

    it('filtra por situação', async () => {
        render(<AvaliacaoDesignacoes />);

        await screen.findByText('Robô seguidor');
        fireEvent.change(screen.getByLabelText('Filtrar por situação'), { target: { value: 'em_andamento' } });

        await waitFor(() => expect(getDesignacoes).toHaveBeenCalledWith(
            expect.objectContaining({ situacao: 'em_andamento' }),
        ));
    });

    // --- Ver notas (Sprint 125) ------------------------------------------

    it('só oferece "Ver notas" em avaliação concluída', async () => {
        render(<AvaliacaoDesignacoes />);

        // Designada e em avaliação ainda não têm nota: o botão fica desabilitado
        // dizendo o porquê, em vez de sumir e deixar a coluna irregular.
        expect(await screen.findByRole('button', { name: 'Ver notas de Robô seguidor por Ana Souza' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Ver notas de Horta vertical por Bruno Lima' })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Ver notas de Ponte de palito por Carla Dias' })).toBeEnabled();
    });

    it('abre a nota seção por seção, com a soma geral', async () => {
        render(<AvaliacaoDesignacoes />);

        fireEvent.click(await screen.findByRole('button', { name: 'Ver notas de Ponte de palito por Carla Dias' }));

        await waitFor(() => expect(verNotasDaDesignacao).toHaveBeenCalledWith(12));

        const dialogo = within(await screen.findByRole('dialog'));
        expect(dialogo.getByText('Notas da avaliação')).toBeInTheDocument();
        // A soma geral em destaque, em pt-BR.
        expect(dialogo.getByText('8,45')).toBeInTheDocument();
        expect(dialogo.getByText(/de 10,00 — soma de todas as seções/)).toBeInTheDocument();
        // Cada seção com o seu subtotal…
        expect(dialogo.getByText('Introdução')).toBeInTheDocument();
        expect(dialogo.getByText('0,86 / 1,08')).toBeInTheDocument();
        expect(dialogo.getByText('Vídeo')).toBeInTheDocument();
        // …e cada pergunta com a resposta em palavras.
        expect(dialogo.getByText('8 — Bom')).toBeInTheDocument();
        expect(dialogo.getByText('Sim')).toBeInTheDocument();
        // Pergunta em branco é dita, não vira zero silencioso.
        expect(dialogo.getByText('Não respondida')).toBeInTheDocument();
        // O parecer escrito acompanha o número.
        expect(dialogo.getByText(/Melhorar o áudio da narração/)).toBeInTheDocument();
    });

    it('mostra o motivo quando o servidor recusa abrir a nota', async () => {
        verNotasDaDesignacao.mockRejectedValue({
            message: '',
            response: { data: { errors: { avaliacao: ['Esta avaliação ainda não foi enviada.'] } } },
        });

        render(<AvaliacaoDesignacoes />);

        fireEvent.click(await screen.findByRole('button', { name: 'Ver notas de Ponte de palito por Carla Dias' }));

        expect(await screen.findByText('Esta avaliação ainda não foi enviada.')).toBeInTheDocument();
    });
});
