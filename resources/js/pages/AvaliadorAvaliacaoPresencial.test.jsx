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

const SIM_NAO = [
    { valor: 'sim', rotulo: 'Sim', fracao: 1 },
    { valor: 'nao', rotulo: 'Não', fracao: 0 },
];

const RUBRICA = {
    nota_maxima: 10,
    blocos: [
        { chave: 'avaliacao', titulo: 'Avaliação', maximo: 8.5 },
        { chave: 'documentos', titulo: 'Documentos e uso de IA', maximo: 1.5 },
    ],
    secoes: [
        {
            chave: 'projeto_pesquisa', titulo: 'Projeto de Pesquisa', icone: 'description', maximo: 0.41,
            perguntas: [
                {
                    chave: 'projeto_impresso', numero: '2.1', texto: 'A equipe detém o projeto de pesquisa impresso?',
                    ajuda: null, tipo: 'sim_nao', peso: 0.283, bloco: 'documentos', opcoes: SIM_NAO,
                },
                {
                    chave: 'projeto_abnt', numero: '2.2', texto: 'O projeto de pesquisa está adequado conforme as normas da ABNT?',
                    ajuda: null, tipo: 'sim_nao', peso: 0.127, bloco: 'documentos', opcoes: SIM_NAO,
                    condicao: { pergunta: 'projeto_impresso', valor: 'sim', fracao_senao: 0 },
                },
            ],
        },
        {
            chave: 'diario_bordo', titulo: 'Diário de Bordo', icone: 'edit_note', maximo: 1.5,
            perguntas: [
                {
                    chave: 'diario_manuscrito', numero: '3.1', texto: 'A equipe detém o Diário de Bordo manuscrito?',
                    ajuda: 'Diário digitado = NÃO.', tipo: 'sim_nao', peso: 1.5, bloco: 'documentos', opcoes: SIM_NAO,
                },
                {
                    chave: 'diario_registro', numero: '3.2', texto: 'De que modo o Diário de Bordo demonstra organização?',
                    ajuda: null, tipo: 'escala', peso: 8.5, bloco: 'avaliacao',
                    condicao: { pergunta: 'diario_manuscrito', valor: 'sim', fracao_senao: 0 },
                    opcoes: [
                        { valor: 0, rotulo: 'Não demonstra', fracao: 0 },
                        { valor: 8, rotulo: 'Bom', fracao: 0.8 },
                        { valor: 10, rotulo: 'Muito bom', fracao: 1 },
                    ],
                },
            ],
        },
        { chave: 'final', titulo: 'Parecer', icone: 'rate_review', ajuda: '', componente: 'comentarios', perguntas: [] },
    ],
};

const ITENS = [{ id: 3, nome: 'Banner montado', descricao: null }];

const PAINEL = {
    aberto: true, motivo_fechado: null, aceitou: true, modo_teste: false, is_demo: false,
    turno: { chave: '2026-10-20|A', rotulo: '20/10 · Turno A (matutino) · 08:00–12:00', prazo_label: '12:30' },
    margem_minutos: 30, max_por_projeto: 3,
    rubrica: RUBRICA, itens: ITENS,
    minhas: [],
    disponiveis: [{ id: 7, titulo: 'Bioplástico', area: 'Agrárias', local: { estande: 42, turno_label: 'Turno A (matutino)' }, avaliacoes: 1 }],
    mapa: {
        layout: [{ numero: 41, x: 0, y: 0 }, { numero: 42, x: 1, y: 0 }, { numero: 43, x: 2, y: 0 }],
        turno_trabalho: 'A',
        max_por_projeto: 3,
        estandes: {
            41: { A: { projeto_id: 6, titulo: 'Compostagem', situacao: 'comigo', avaliacao_id: 9, avaliacoes: 1 } },
            42: {
                A: { projeto_id: 7, titulo: 'Bioplástico', situacao: 'disponivel', avaliacao_id: null, avaliacoes: 1 },
                B: { projeto_id: 8, titulo: 'Drone da tarde', situacao: 'fora_do_turno', avaliacao_id: null, avaliacoes: 0 },
            },
            43: { A: { projeto_id: 10, titulo: 'Ainda montando', situacao: 'nao_pronto', avaliacao_id: null, avaliacoes: 0 } },
        },
        fora_da_planta: [{ numero: 901, turno: 'A', projeto_id: 11, titulo: 'Estande do ensaio', situacao: 'disponivel', avaliacao_id: null, avaliacoes: 0 }],
    },
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

    it('quem é da comissão especial é avisado da vaga e do peso', async () => {
        getPainelPresencial.mockResolvedValue({
            ...PAINEL, comissao: true, vagas: { comuns: 2, comite: 1, peso_comite: 2 },
            minhas: [{ id: 5, projeto_id: 7, titulo: 'Bioplástico', status: 'em_andamento', status_label: 'Em andamento', pode_escrever: true, vaga: 'comite', local: {} }],
        });
        render(<AvaliadorAvaliacaoPresencial />);

        expect(await screen.findByText(/Você é da/)).toHaveTextContent('sua avaliação nela pesa 2 na média');
        // O aviso e a linha da avaliação, marcada com a vaga que ela ocupa.
        expect(screen.getByText(/Em andamento/).closest('li')).toHaveTextContent('vaga da comissão');
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

    it('wizard: o checklist dos itens é o primeiro passo, depois uma seção por passo, e envio só completo', async () => {
        render(<AvaliadorAvaliacaoPresencial />);
        await abrirDisponivel();

        await waitFor(() => expect(iniciarAvaliacaoPresencial).toHaveBeenCalledWith(7, false));
        expect(await screen.findByRole('heading', { name: 'Itens do estande' })).toBeInTheDocument();
        expect(screen.getByText('Passo 1 de 4')).toBeInTheDocument();
        // A leitura do projeto vem junto, com o documento para baixar.
        fireEvent.click(screen.getByRole('button', { name: /Mostrar/ }));
        expect(screen.getByRole('link', { name: /plano.pdf/ })).toHaveAttribute('href', '/api/v1/documentos/1/download');

        fireEvent.click(screen.getByLabelText('Banner montado: Presente'));
        fireEvent.click(screen.getByRole('button', { name: 'Avançar' }));

        // Projeto de pesquisa: a 2.2 só aparece depois do "Sim" na 2.1.
        expect(await screen.findByRole('heading', { name: 'Projeto de Pesquisa' })).toBeInTheDocument();
        expect(screen.queryByText(/normas da ABNT/)).not.toBeInTheDocument();
        fireEvent.click(screen.getByLabelText('Sim'));
        expect(await screen.findByText(/normas da ABNT/)).toBeInTheDocument();
        fireEvent.click(screen.getAllByLabelText('Sim')[1]);
        fireEvent.click(screen.getByRole('button', { name: 'Avançar' }));

        // A 3.2 só aparece depois do "Sim" na 3.1.
        expect(await screen.findByText('A equipe detém o Diário de Bordo manuscrito?', { exact: false })).toBeInTheDocument();
        expect(screen.queryByText(/demonstra organização/)).not.toBeInTheDocument();
        fireEvent.click(screen.getByLabelText('Sim'));
        fireEvent.click(await screen.findByLabelText('Muito bom (10)'));
        fireEvent.click(screen.getByRole('button', { name: 'Avançar' }));

        fireEvent.click(await screen.findByRole('button', { name: /Enviar avaliação/ }));
        fireEvent.click(await screen.findByRole('button', { name: 'Enviar' }));

        await waitFor(() => expect(concluirAvaliacaoPresencial).toHaveBeenCalledWith(
            5, {
                respostas: { projeto_impresso: 'sim', projeto_abnt: 'sim', diario_manuscrito: 'sim', diario_registro: 10 },
                itens: { 3: 'presente' },
                comentario: null,
            }, false,
        ));
        expect(await screen.findByText(/Avaliação concluída — nota/)).toBeInTheDocument();
    });

    it('sem o checklist dos itens o envio fica travado', async () => {
        render(<AvaliadorAvaliacaoPresencial />);
        await abrirDisponivel();

        fireEvent.click(await screen.findByRole('button', { name: 'Ir para Projeto de Pesquisa' }));
        fireEvent.click(screen.getByLabelText('Não'));
        fireEvent.click(screen.getByRole('button', { name: 'Ir para Diário de Bordo' }));
        fireEvent.click(screen.getByLabelText('Não'));
        fireEvent.click(screen.getByRole('button', { name: 'Ir para Parecer' }));

        expect(screen.getByRole('button', { name: /Enviar avaliação/ })).toBeDisabled();
    });

    it('sem o projeto impresso a 2.2 some e vale 0', async () => {
        render(<AvaliadorAvaliacaoPresencial />);
        await abrirDisponivel();

        fireEvent.click(await screen.findByRole('button', { name: 'Ir para Projeto de Pesquisa' }));
        fireEvent.click(screen.getByLabelText('Sim'));
        fireEvent.click(screen.getAllByLabelText('Sim')[1]);
        expect(screen.getByText(/Nota parcial/)).toHaveTextContent('Nota parcial 0,410');

        fireEvent.click(screen.getAllByLabelText('Não')[0]);
        expect(await screen.findByText(/não se aplica/)).toHaveTextContent('2.2 não se aplica — vale 0.');
        expect(screen.queryByText(/normas da ABNT/)).not.toBeInTheDocument();
        expect(screen.getByText(/Nota parcial/)).toHaveTextContent('Nota parcial 0,000');

        fireEvent.click(screen.getByRole('button', { name: /Salvar rascunho/ }));
        await waitFor(() => expect(salvarRascunhoPresencial).toHaveBeenCalledWith(
            5, { respostas: { projeto_impresso: 'nao' }, itens: {}, comentario: null }, false,
        ));
    });

    // --- O mapa ----------------------------------------------------------

    it('no mapa: clicar no estande livre do turno inicia a avaliação', async () => {
        render(<AvaliadorAvaliacaoPresencial />);

        fireEvent.click(await screen.findByRole('button', { name: /Estande 042 · Bioplástico · Disponível/ }));
        expect(await screen.findByText(/Bioplástico — Estande 42 · Turno A \(matutino\)/)).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Iniciar' }));

        await waitFor(() => expect(iniciarAvaliacaoPresencial).toHaveBeenCalledWith(7, false));
        expect(await screen.findByRole('heading', { name: 'Itens do estande' })).toBeInTheDocument();
    });

    it('no mapa: o estande que já está com ele abre a avaliação; o que não está pronto explica', async () => {
        getAvaliacaoPresencial.mockResolvedValue({ ...AVALIACAO, id: 9 });
        render(<AvaliadorAvaliacaoPresencial />);

        fireEvent.click(await screen.findByRole('button', { name: /Estande 043 · Ainda montando/ }));
        expect(await screen.findByText(/ainda não foi credenciado e checado/)).toBeInTheDocument();
        expect(iniciarAvaliacaoPresencial).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: /Estande 041 · Compostagem · Com você/ }));
        await waitFor(() => expect(getAvaliacaoPresencial).toHaveBeenCalledWith(9, false));
    });

    it('no mapa: trocar o turno mostra os projetos daquele turno', async () => {
        render(<AvaliadorAvaliacaoPresencial />);

        fireEvent.click(await screen.findByRole('radio', { name: /Turno B/ }));
        fireEvent.click(screen.getByRole('button', { name: /Estande 042 · Drone da tarde/ }));

        expect(await screen.findByText(/apresenta fora do turno em andamento/)).toBeInTheDocument();
        expect(screen.queryByText('Estande do ensaio')).not.toBeInTheDocument();
    });

    it('sem liberação: a aba explica e o mapa diz por que não dá para iniciar', async () => {
        getPainelPresencial.mockResolvedValue({
            ...PAINEL, aberto: false, liberado: false, turno: null, disponiveis: [],
            motivo_fechado: 'Sua participação na avaliação presencial ainda não foi liberada pela organização.',
            mapa: {
                ...PAINEL.mapa,
                estandes: { 42: { A: { projeto_id: 7, titulo: 'Bioplástico', situacao: 'nao_liberado', avaliacao_id: null, avaliacoes: 0 } } },
                fora_da_planta: [],
            },
        });
        render(<AvaliadorAvaliacaoPresencial />);

        expect(await screen.findByText(/ainda não foi liberada pela organização/)).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /Estande 042 · Bioplástico/ }));
        expect(await screen.findByText(/aguarda a sua liberação/)).toBeInTheDocument();
        expect(iniciarAvaliacaoPresencial).not.toHaveBeenCalled();
    });

    it('no mapa: o estande fora da planta continua alcançável', async () => {
        render(<AvaliadorAvaliacaoPresencial />);

        fireEvent.click(await screen.findByRole('button', { name: /Estande 901 · Estande do ensaio/ }));
        fireEvent.click(await screen.findByRole('button', { name: 'Iniciar' }));

        await waitFor(() => expect(iniciarAvaliacaoPresencial).toHaveBeenCalledWith(11, false));
    });

    it('salva o rascunho com o checklist', async () => {
        render(<AvaliadorAvaliacaoPresencial />);
        await abrirDisponivel();

        fireEvent.click(await screen.findByLabelText('Banner montado: Presente'));
        fireEvent.click(screen.getByRole('button', { name: 'Ir para Diário de Bordo' }));
        fireEvent.click(screen.getByLabelText('Sim'));
        fireEvent.click(await screen.findByLabelText('Bom (8)'));
        fireEvent.click(screen.getByRole('button', { name: /Salvar rascunho/ }));

        await waitFor(() => expect(salvarRascunhoPresencial).toHaveBeenCalledWith(
            5, { respostas: { diario_manuscrito: 'sim', diario_registro: 8 }, itens: { 3: 'presente' }, comentario: null }, false,
        ));
    });

    it('sem diário manuscrito a 3.2 some, vale 0 e a resposta dela não vai junto', async () => {
        render(<AvaliadorAvaliacaoPresencial />);
        await abrirDisponivel();

        fireEvent.click(await screen.findByRole('button', { name: 'Ir para Diário de Bordo' }));
        fireEvent.click(screen.getByLabelText('Sim'));
        fireEvent.click(await screen.findByLabelText('Muito bom (10)'));
        expect(screen.getByText(/Nota parcial/)).toHaveTextContent('Nota parcial 10,000');

        fireEvent.click(screen.getByLabelText('Não'));
        expect(await screen.findByText(/não se aplica/)).toHaveTextContent('3.2 não se aplica — vale 0.');
        expect(screen.queryByLabelText('Muito bom (10)')).not.toBeInTheDocument();
        expect(screen.getByText(/Nota parcial/)).toHaveTextContent('Nota parcial 0,000');

        fireEvent.click(screen.getByRole('button', { name: /Salvar rascunho/ }));
        await waitFor(() => expect(salvarRascunhoPresencial).toHaveBeenCalledWith(
            5, { respostas: { diario_manuscrito: 'nao' }, itens: {}, comentario: null }, false,
        ));
    });

    it('enviada: mostra a nota por bloco e as respostas de cada pergunta', async () => {
        getPainelPresencial.mockResolvedValue({
            ...PAINEL,
            minhas: [{ id: 5, projeto_id: 7, titulo: 'Bioplástico', status: 'concluida', status_label: 'Concluída', pode_escrever: false, nota: 9.2, local: {} }],
        });
        getAvaliacaoPresencial.mockResolvedValue({
            ...AVALIACAO, status: 'concluida', status_label: 'Concluída', pode_escrever: false, nota: 9.2,
            respostas: { diario_manuscrito: 'sim', diario_registro: 8, projeto_impresso: 'nao' },
        });
        render(<AvaliadorAvaliacaoPresencial />);

        fireEvent.click(await screen.findByRole('button', { name: 'Ver Bioplástico' }));

        expect(await screen.findByText('Avaliação enviada')).toBeInTheDocument();
        expect(screen.getByText('Avaliação').nextSibling).toHaveTextContent('6,800 de 8,500');
        expect(screen.getByText(/3.2 De que modo/)).toHaveTextContent('Bom');
        expect(screen.getByText(/Avaliação concluída — nota/)).toHaveTextContent('9,200 de 10,000');
    });

    it('enviada com a rubrica anterior: avisa em vez de mostrar respostas vazias', async () => {
        getPainelPresencial.mockResolvedValue({
            ...PAINEL,
            minhas: [{ id: 5, projeto_id: 7, titulo: 'Bioplástico', status: 'concluida', status_label: 'Concluída', pode_escrever: false, nota: 8, local: {} }],
        });
        getAvaliacaoPresencial.mockResolvedValue({
            ...AVALIACAO, status: 'concluida', status_label: 'Concluída', pode_escrever: false, nota: 8,
            respostas: { clareza: 8 }, rubrica_anterior: true,
        });
        render(<AvaliadorAvaliacaoPresencial />);

        fireEvent.click(await screen.findByRole('button', { name: 'Ver Bioplástico' }));

        expect(await screen.findByText(/enviada com a rubrica anterior/)).toBeInTheDocument();
        expect(screen.queryByText(/3.2 De que modo/)).not.toBeInTheDocument();
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
