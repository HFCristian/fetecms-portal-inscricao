import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
// O wizard da rubrica tem vida própria; aqui só interessa que ele abra com a
// avaliação certa.
vi.mock('../components/AvaliacaoModal.jsx', () => ({
    default: ({ avaliacaoId }) => <div>Rubrica da avaliação {avaliacaoId}</div>,
}));
vi.mock('react-router-dom', () => ({
    Link: ({ children, to }) => <a href={to}>{children}</a>,
}));

const getVerificacoesDisparidade = vi.fn();
const gerarVerificacaoDisparidade = vi.fn();
const getVerificacaoDisparidade = vi.fn();
const getNotasDoProjeto = vi.fn();
const getPadroesDeAvaliacao = vi.fn();
const desconsiderarNota = vi.fn();
const reconsiderarNota = vi.fn();
const getOpcoesDesignacao = vi.fn();
vi.mock('../lib/admin.js', () => ({
    getVerificacoesDisparidade: (...a) => getVerificacoesDisparidade(...a),
    gerarVerificacaoDisparidade: (...a) => gerarVerificacaoDisparidade(...a),
    getVerificacaoDisparidade: (...a) => getVerificacaoDisparidade(...a),
    getNotasDoProjeto: (...a) => getNotasDoProjeto(...a),
    getPadroesDeAvaliacao: (...a) => getPadroesDeAvaliacao(...a),
    desconsiderarNota: (...a) => desconsiderarNota(...a),
    reconsiderarNota: (...a) => reconsiderarNota(...a),
    getOpcoesDesignacao: (...a) => getOpcoesDesignacao(...a),
    API_AVALIACAO_ORGANIZACAO: {},
}));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.message ?? '', fields: e?.fields ?? {} }),
}));

import AvaliacaoDisparidade from './AvaliacaoDisparidade.jsx';

const VERIFICACAO = {
    id: 7,
    diferenca: 2,
    total: 1,
    autor: 'Admin',
    criada_em: '2026-09-15T10:00:00-04:00',
    nota_maxima: 10,
    itens: [
        {
            projeto_id: 42,
            titulo: 'Secador solar',
            area: 'Exatas',
            categoria: 'FETECMS',
            avaliacoes: 3,
            nota_min: 4.5,
            nota_max: 9.5,
            amplitude: 5,
            media: 7,
        },
    ],
};

/** O que o botão "Ver notas" traz: uma coluna por avaliador. */
const NOTAS = {
    projeto: { id: 42, titulo: 'Secador solar', area: 'Exatas', categoria: 'FETECMS' },
    secoes: [
        { chave: 'titulo', titulo: 'Título', maximo: 0.15 },
        { chave: 'metodologia', titulo: 'Metodologia', maximo: 2 },
    ],
    avaliadores: [
        {
            avaliacao_id: 1, avaliador_id: 5, avaliador: 'Ana Souza', nota: 9.5,
            concluida_em_label: '10/09/2026 09:00',
            secoes: { titulo: 0.15, metodologia: 1.9 },
            recomendacao_video: null, recomendacao_projeto: 'Muito bom.',
        },
        {
            avaliacao_id: 2, avaliador_id: 6, avaliador: 'Bruno Lima', nota: 4.5,
            concluida_em_label: '11/09/2026 14:00',
            secoes: { titulo: 0.12, metodologia: 0.4 },
            recomendacao_video: null, recomendacao_projeto: null,
        },
    ],
    nota_maxima: 10,
    media: 7,
    amplitude: 5,
};

/** O mesmo projeto depois de a nota da Ana ser desconsiderada. */
const NOTAS_COM_DESCARTE = {
    ...NOTAS,
    avaliadores: [
        { ...NOTAS.avaliadores[1] },
        {
            ...NOTAS.avaliadores[0],
            desconsiderada: true,
            desconsiderada_por: 'Admin',
            desconsiderada_em_label: '17/09/2026 10:00',
            desconsiderada_motivo: 'Avaliou o projeto errado.',
        },
    ],
    media: 4.5,
    amplitude: null,
    consideradas: 1,
    desconsideradas: 1,
};

/** O que a aba de padrões devolve: um avaliador com dois sinais. */
const PADROES = {
    limiares: { media_alta: 9.5, desvio_abaixo: 2, minutos_relampago: 10, min_avaliacoes: 3 },
    padroes: [
        { chave: 'fora_da_curva', titulo: 'Notas muito abaixo dos colegas', descricao: 'Na maioria dos projetos…' },
        { chave: 'relampago', titulo: 'Avaliação relâmpago', descricao: 'Enviada poucos minutos depois…' },
    ],
    nota_maxima: 10,
    analisados: 12,
    total: 1,
    avaliadores: [
        {
            avaliador_id: 9, avaliador: 'Duro Lima', email: 'duro@ms.br', area: 'Exatas',
            concluidas: 4, media: 3.2, nota_minima: 2, nota_maxima_dada: 4.5,
            notas_maximas: 0, comparaveis: 4, desvio_medio: -4.2,
            uniformes: 0, duplicadas: 0, com_duracao: 4,
            padroes: [
                { chave: 'fora_da_curva', titulo: 'Notas muito abaixo dos colegas', detalhe: '2,00 ou mais abaixo dos colegas em 4 de 4 projetos.' },
                { chave: 'relampago', titulo: 'Avaliação relâmpago', detalhe: '3 avaliações enviadas em menos de 10 minutos.' },
            ],
            itens: [
                {
                    avaliacao_id: 31, projeto_id: 42, projeto: 'Secador solar',
                    nota: 2, media_outros: 9, desvio: -7, minutos: 3, uniforme: false,
                },
            ],
        },
    ],
};

describe('AvaliacaoDisparidade', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        getVerificacoesDisparidade.mockResolvedValue([]);
        gerarVerificacaoDisparidade.mockResolvedValue(VERIFICACAO);
        getVerificacaoDisparidade.mockResolvedValue(VERIFICACAO);
        getNotasDoProjeto.mockResolvedValue(NOTAS);
        getPadroesDeAvaliacao.mockResolvedValue(PADROES);
        desconsiderarNota.mockResolvedValue(NOTAS_COM_DESCARTE);
        getOpcoesDesignacao.mockResolvedValue({
            avaliadores: [{ id: 6, nome: 'Bruno Lima', area: 'Exatas', na_fila: 2 }],
        });
    });

    it('gera a lista com a diferença digitada, em vírgula, e mostra as notas', async () => {
        render(<AvaliacaoDisparidade />);

        fireEvent.change(screen.getByLabelText(/Diferença entre as notas/i), { target: { value: '2,50' } });
        fireEvent.click(screen.getByRole('button', { name: /Gerar lista/i }));

        // O admin digita com vírgula; a API recebe número.
        await waitFor(() => expect(gerarVerificacaoDisparidade).toHaveBeenCalledWith(2.5));

        expect(await screen.findByText('Secador solar')).toBeInTheDocument();
        expect(screen.getByText('4,50 — 9,50')).toBeInTheDocument();
        expect(screen.getByText('5,00')).toBeInTheDocument();
    });

    it('cada projeto leva ao diálogo de designação já com ele marcado', async () => {
        render(<AvaliacaoDisparidade />);

        fireEvent.click(screen.getByRole('button', { name: /Gerar lista/i }));

        const link = await screen.findByRole('link', { name: /Designar/i });
        expect(link.getAttribute('href')).toBe(
            '/admin/avaliacao/designacoes?projeto=42&q=Secador%20solar',
        );
    });

    // Sprint 139 — a nota final diz que a distância existe; a comparação por
    // seção diz onde ela nasceu, e o nome diz com quem falar sobre isso.
    it('mostra as notas de cada avaliador do projeto, com o nome ao lado', async () => {
        render(<AvaliacaoDisparidade />);

        fireEvent.click(screen.getByRole('button', { name: /Gerar lista/i }));
        fireEvent.click(await screen.findByRole('button', { name: /Ver notas/i }));

        await waitFor(() => expect(getNotasDoProjeto).toHaveBeenCalledWith(42));

        expect(await screen.findByRole('columnheader', { name: /Ana Souza/ })).toBeInTheDocument();
        expect(screen.getByRole('columnheader', { name: /Bruno Lima/ })).toBeInTheDocument();
        expect(screen.getByRole('rowheader', { name: /Metodologia/ })).toBeInTheDocument();
        // A seção em que eles mais discordaram fica dita com todas as letras.
        expect(screen.getByText(/maior discordância/)).toBeInTheDocument();
        expect(screen.getByText('Muito bom.')).toBeInTheDocument();
        expect(screen.getByText(/Não escreveu recomendações/)).toBeInTheDocument();
    });

    it('lista vazia diz que ninguém passou do corte', async () => {
        gerarVerificacaoDisparidade.mockResolvedValue({ ...VERIFICACAO, total: 0, itens: [] });
        render(<AvaliacaoDisparidade />);

        fireEvent.click(screen.getByRole('button', { name: /Gerar lista/i }));

        expect(await screen.findByText(/Nenhum projeto com essa diferença/i)).toBeInTheDocument();
    });

    it('abre uma verificação anterior do histórico', async () => {
        getVerificacoesDisparidade.mockResolvedValue([
            { id: 7, diferenca: 2, total: 1, autor: 'Admin', criada_em: '2026-09-15T10:00:00-04:00' },
        ]);
        render(<AvaliacaoDisparidade />);

        fireEvent.click(await screen.findByRole('button', { name: /Abrir/i }));

        await waitFor(() => expect(getVerificacaoDisparidade).toHaveBeenCalledWith(7));
        expect(await screen.findByText('Secador solar')).toBeInTheDocument();
    });

    // Sprint 140 — a segunda aba olha para o avaliador, e não para o projeto.
    it('a aba de padrões lista quem avaliou fora do comum, com os números', async () => {
        render(<AvaliacaoDisparidade />);

        fireEvent.click(screen.getByRole('tab', { name: /Identificação de padrões/i }));

        expect(await screen.findByText('Duro Lima')).toBeInTheDocument();
        expect(screen.getByText(/2,00 ou mais abaixo dos colegas em 4 de 4 projetos/)).toBeInTheDocument();
        expect(screen.getByText(/3 avaliações enviadas em menos de 10 minutos/)).toBeInTheDocument();
        // A lista de projetos fica na outra aba.
        expect(screen.queryByText('Secador solar')).not.toBeInTheDocument();
    });

    it('reanalisa com os limiares que o admin digitou', async () => {
        render(<AvaliacaoDisparidade />);

        fireEvent.click(screen.getByRole('tab', { name: /Identificação de padrões/i }));
        await screen.findByText('Duro Lima');

        fireEvent.change(screen.getByLabelText('Abaixo dos colegas em'), { target: { value: '3,50' } });
        fireEvent.click(screen.getByRole('button', { name: /Analisar/i }));

        await waitFor(() => expect(getPadroesDeAvaliacao).toHaveBeenLastCalledWith({
            media_alta: 9.5, desvio_abaixo: 3.5, minutos_relampago: 10, min_avaliacoes: 3,
        }));
    });

    it('das avaliações de um avaliador suspeito dá para abrir as notas do projeto', async () => {
        render(<AvaliacaoDisparidade />);

        fireEvent.click(screen.getByRole('tab', { name: /Identificação de padrões/i }));
        fireEvent.click(await screen.findByRole('button', { name: /Ver avaliações/i }));
        fireEvent.click(await screen.findByRole('button', { name: /^Ver notas$/i }));

        await waitFor(() => expect(getNotasDoProjeto).toHaveBeenCalledWith(42));
    });

    // Sprint 141 — a nota descartada continua na tela, marcada, e o motivo
    // aparece junto: apagá-la destruiria a prova de que ela existiu.
    it('desconsidera uma nota com justificativa e a mantém visível, separada', async () => {
        render(<AvaliacaoDisparidade />);

        fireEvent.click(screen.getByRole('button', { name: /Gerar lista/i }));
        fireEvent.click(await screen.findByRole('button', { name: /Ver notas/i }));
        await screen.findByRole('columnheader', { name: /Ana Souza/ });

        const cartao = screen.getByText('Muito bom.').closest('div');
        fireEvent.click(within(cartao).getByRole('button', { name: /Desconsiderar esta nota/i }));
        fireEvent.change(screen.getByPlaceholderText(/trilha de Registros/i), {
            target: { value: 'Avaliou o projeto errado.' },
        });
        fireEvent.click(screen.getByRole('button', { name: /^Desconsiderar$/ }));

        // Sem escolher substituto, a chamada vai com "nenhuma": desconsiderar
        // sem repor é decisão legítima.
        await waitFor(() => expect(desconsiderarNota).toHaveBeenCalledWith(
            1,
            'Avaliou o projeto errado.',
            { tipo: 'nenhuma' },
        ));

        expect(await screen.findByText(/não conta para a classificação/)).toBeInTheDocument();
        expect(screen.getByText(/Avaliou o projeto errado\./)).toBeInTheDocument();
        // A nota não some: ela continua na tabela, riscada.
        expect(screen.getByRole('columnheader', { name: /Ana Souza/ })).toBeInTheDocument();
        expect(screen.getByText(/1 nota desconsiderada/)).toBeInTheDocument();
        // E a ação inversa fica à mão.
        expect(screen.getByRole('button', { name: /Voltar a considerar esta nota/i })).toBeInTheDocument();
    });

    // Sprint 142 — tirar a nota abre um buraco na cobertura; a mesma tela
    // pergunta o que entra no lugar.
    it('desconsidera designando outro avaliador no lugar', async () => {
        render(<AvaliacaoDisparidade />);

        fireEvent.click(screen.getByRole('button', { name: /Gerar lista/i }));
        fireEvent.click(await screen.findByRole('button', { name: /Ver notas/i }));
        await screen.findByRole('columnheader', { name: /Ana Souza/ });

        const cartao = screen.getByText('Muito bom.').closest('div');
        fireEvent.click(within(cartao).getByRole('button', { name: /Desconsiderar esta nota/i }));
        fireEvent.change(screen.getByPlaceholderText(/trilha de Registros/i), {
            target: { value: 'Avaliou o projeto errado.' },
        });
        fireEvent.click(screen.getByRole('radio', { name: /Designar outro avaliador/i }));

        // Sem escolher quem, não dá para confirmar.
        expect(screen.getByRole('button', { name: /^Desconsiderar$/ })).toBeDisabled();

        fireEvent.focus(await screen.findByPlaceholderText(/Procure o avaliador/i));
        // A lista escolhe no mouseDown, para o clique não fechar antes.
        fireEvent.mouseDown(await screen.findByRole('button', { name: /Bruno Lima/ }));
        fireEvent.click(screen.getByRole('button', { name: /^Desconsiderar$/ }));

        await waitFor(() => expect(desconsiderarNota).toHaveBeenCalledWith(
            1,
            'Avaliou o projeto errado.',
            { tipo: 'avaliador', avaliador_id: 6 },
        ));
    });

    it('desconsidera e abre a rubrica quando o admin decide avaliar na hora', async () => {
        desconsiderarNota.mockResolvedValue({
            ...NOTAS_COM_DESCARTE,
            substituicao: { tipo: 'admin', avaliacao_id: 77, mensagem: 'Preencha a rubrica agora.' },
        });
        render(<AvaliacaoDisparidade />);

        fireEvent.click(screen.getByRole('button', { name: /Gerar lista/i }));
        fireEvent.click(await screen.findByRole('button', { name: /Ver notas/i }));
        await screen.findByRole('columnheader', { name: /Ana Souza/ });

        const cartao = screen.getByText('Muito bom.').closest('div');
        fireEvent.click(within(cartao).getByRole('button', { name: /Desconsiderar esta nota/i }));
        fireEvent.change(screen.getByPlaceholderText(/trilha de Registros/i), {
            target: { value: 'Nota incompatível com o trabalho.' },
        });
        fireEvent.click(screen.getByRole('radio', { name: /Eu mesmo avalio agora/i }));
        fireEvent.click(screen.getByRole('button', { name: /^Desconsiderar$/ }));

        await waitFor(() => expect(desconsiderarNota).toHaveBeenCalledWith(
            1,
            'Nota incompatível com o trabalho.',
            { tipo: 'admin' },
        ));
        expect(await screen.findByText('Rubrica da avaliação 77')).toBeInTheDocument();
    });

    it('mostra o erro do servidor sem apagar o formulário', async () => {
        gerarVerificacaoDisparidade.mockRejectedValue({ fields: { diferenca: 'A diferença é obrigatória.' } });
        render(<AvaliacaoDisparidade />);

        fireEvent.click(screen.getByRole('button', { name: /Gerar lista/i }));

        expect(await screen.findByText('A diferença é obrigatória.')).toBeInTheDocument();
        expect(screen.getByLabelText(/Diferença entre as notas/i)).toBeInTheDocument();
    });
});
