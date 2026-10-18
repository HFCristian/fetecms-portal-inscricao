import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
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
const pedirPermissaoPresencial = vi.fn();
vi.mock('../lib/avaliacaoPresencial.js', () => ({
    pedirPermissaoPresencial: (...a) => pedirPermissaoPresencial(...a),
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

const PAINEL = {
    aberto: true, motivo_fechado: null, aceitou: true, modo_teste: false, is_demo: false,
    turno: { chave: '2026-10-20|A', rotulo: '20/10 · Turno A (matutino) · 08:00–12:00', prazo_label: '12:30' },
    max_por_projeto: 3,
    rubrica: RUBRICA,
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
    respostas: {}, comentario: null, nota: null, nota_maxima: 10,
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

    it('wizard: uma seção por passo, sem os itens do estande, e envio só completo', async () => {
        render(<AvaliadorAvaliacaoPresencial />);
        await abrirDisponivel();

        await waitFor(() => expect(iniciarAvaliacaoPresencial).toHaveBeenCalledWith(7, false));
        // Os itens do estande ficaram na checagem: o primeiro passo já é a rubrica.
        expect(await screen.findByRole('heading', { name: 'Projeto de Pesquisa' })).toBeInTheDocument();
        expect(screen.getByText('Passo 1 de 3')).toBeInTheDocument();
        expect(screen.queryByText('Itens do estande')).not.toBeInTheDocument();
        // A leitura do projeto vem junto, com o documento para baixar.
        fireEvent.click(screen.getByRole('button', { name: /Mostrar/ }));
        expect(screen.getByRole('link', { name: /plano.pdf/ })).toHaveAttribute('href', '/api/v1/documentos/1/download');

        // Projeto de pesquisa: a 2.2 só aparece depois do "Sim" na 2.1.
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
                comentario: null,
            }, false,
        ));
        expect(await screen.findByText(/Avaliação concluída — nota/)).toBeInTheDocument();
    });

    it('com as perguntas respondidas o envio libera — nenhum item do estande é cobrado', async () => {
        render(<AvaliadorAvaliacaoPresencial />);
        await abrirDisponivel();

        fireEvent.click(await screen.findByRole('button', { name: 'Ir para Projeto de Pesquisa' }));
        fireEvent.click(screen.getByLabelText('Não'));
        fireEvent.click(screen.getByRole('button', { name: 'Ir para Diário de Bordo' }));
        fireEvent.click(screen.getByLabelText('Não'));
        fireEvent.click(screen.getByRole('button', { name: 'Ir para Parecer' }));

        expect(screen.getByRole('button', { name: /Enviar avaliação/ })).toBeEnabled();
    });

    // --- Rascunho automático ao sair de cada seção --------------------------

    it('sair de uma seção salva o rascunho sozinho, só quando algo mudou', async () => {
        render(<AvaliadorAvaliacaoPresencial />);
        await abrirDisponivel();
        await screen.findByRole('heading', { name: 'Projeto de Pesquisa' });

        // Passar por uma seção sem responder nada não chama o servidor.
        fireEvent.click(screen.getByRole('button', { name: 'Avançar' }));
        fireEvent.click(screen.getByRole('button', { name: 'Voltar' }));
        expect(salvarRascunhoPresencial).not.toHaveBeenCalled();

        fireEvent.click(screen.getByLabelText('Não'));
        fireEvent.click(screen.getByRole('button', { name: 'Avançar' }));

        await waitFor(() => expect(salvarRascunhoPresencial).toHaveBeenCalledWith(
            5, { respostas: { projeto_impresso: 'nao' }, comentario: null }, false,
        ));
        expect(await screen.findByText(/Rascunho salvo automaticamente às \d{2}:\d{2}/)).toBeInTheDocument();

        // Voltar sem mexer em nada não salva de novo o que já está gravado.
        fireEvent.click(screen.getByRole('button', { name: 'Voltar' }));
        await screen.findByRole('heading', { name: 'Projeto de Pesquisa' });
        expect(salvarRascunhoPresencial).toHaveBeenCalledTimes(1);
    });

    it('o atalho do topo e o fechar também salvam a seção que ficou para trás', async () => {
        render(<AvaliadorAvaliacaoPresencial />);
        await abrirDisponivel();
        await screen.findByRole('heading', { name: 'Projeto de Pesquisa' });

        fireEvent.click(screen.getByLabelText('Sim'));
        fireEvent.click(screen.getByRole('button', { name: /^Ir para Diário de Bordo/ }));
        await waitFor(() => expect(salvarRascunhoPresencial).toHaveBeenCalledTimes(1));

        fireEvent.click(screen.getByLabelText('Não'));
        fireEvent.click(screen.getByRole('button', { name: 'Fechar' }));
        await waitFor(() => expect(salvarRascunhoPresencial).toHaveBeenLastCalledWith(
            5, { respostas: { projeto_impresso: 'sim', diario_manuscrito: 'nao' }, comentario: null }, false,
        ));
    });

    it('se o rascunho automático falha, avisa e tenta de novo na próxima saída', async () => {
        salvarRascunhoPresencial.mockRejectedValueOnce({ response: { data: { message: 'O prazo desta avaliação acabou.' } } });
        render(<AvaliadorAvaliacaoPresencial />);
        await abrirDisponivel();
        await screen.findByRole('heading', { name: 'Projeto de Pesquisa' });

        fireEvent.click(screen.getByLabelText('Não'));
        fireEvent.click(screen.getByRole('button', { name: 'Avançar' }));

        expect(await screen.findByText(/não foi salvo automaticamente/)).toHaveTextContent('O prazo desta avaliação acabou.');
        // A tela não trava: a seção seguinte abriu.
        expect(screen.getByRole('heading', { name: 'Diário de Bordo' })).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Voltar' }));
        await waitFor(() => expect(salvarRascunhoPresencial).toHaveBeenCalledTimes(2));
        await waitFor(() => expect(screen.queryByText(/não foi salvo automaticamente/)).not.toBeInTheDocument());
    });

    it('o envio espera o rascunho automático que ainda está a caminho', async () => {
        let terminar;
        salvarRascunhoPresencial.mockImplementationOnce(() => new Promise((r) => { terminar = () => r({ data: AVALIACAO }); }));
        render(<AvaliadorAvaliacaoPresencial />);
        await abrirDisponivel();
        await screen.findByRole('heading', { name: 'Projeto de Pesquisa' });

        fireEvent.click(screen.getByLabelText('Não'));
        fireEvent.click(screen.getByRole('button', { name: /^Ir para Diário de Bordo/ }));
        fireEvent.click(screen.getByLabelText('Não'));
        fireEvent.click(screen.getByRole('button', { name: /^Ir para Parecer/ }));
        fireEvent.click(screen.getByRole('button', { name: /Enviar avaliação/ }));
        fireEvent.click(await screen.findByRole('button', { name: 'Enviar' }));

        await new Promise((r) => setTimeout(r, 20));
        expect(concluirAvaliacaoPresencial).not.toHaveBeenCalled();

        terminar();
        await waitFor(() => expect(concluirAvaliacaoPresencial).toHaveBeenCalled());
        // O segundo rascunho (saindo do Diário de Bordo) foi antes do envio.
        expect(salvarRascunhoPresencial).toHaveBeenCalledTimes(2);
    });

    it('ao trocar de seção, a completa fica verde e a que ficou pela metade, amarela', async () => {
        render(<AvaliadorAvaliacaoPresencial />);
        await abrirDisponivel();

        const atalho = (nome) => screen.getByRole('button', { name: new RegExp(`^Ir para ${nome}`) });
        await screen.findByRole('heading', { name: 'Projeto de Pesquisa' });
        // Antes de sair, a seção aberta tem o destaque de sempre e as outras ficam neutras.
        expect(atalho('Projeto de Pesquisa')).toHaveAttribute('data-situacao', 'atual');
        expect(atalho('Diário de Bordo')).toHaveAttribute('data-situacao', 'neutra');

        // Sai do Projeto de Pesquisa com a 2.2 em branco: amarela.
        fireEvent.click(screen.getByLabelText('Sim'));
        fireEvent.click(screen.getByRole('button', { name: 'Avançar' }));
        expect(atalho('Projeto de Pesquisa')).toHaveAttribute('data-situacao', 'pendente');
        expect(atalho('Projeto de Pesquisa')).toHaveClass('bg-yellow-100');
        expect(atalho('Projeto de Pesquisa')).toHaveAccessibleName('Ir para Projeto de Pesquisa (falta responder)');

        // Sai do Diário de Bordo completo: verde, com o ícone de concluída.
        fireEvent.click(screen.getByLabelText('Não'));
        fireEvent.click(screen.getByRole('button', { name: 'Avançar' }));
        expect(atalho('Diário de Bordo')).toHaveAttribute('data-situacao', 'completa');
        expect(atalho('Diário de Bordo')).toHaveClass('bg-secondary-container/60');
        expect(atalho('Diário de Bordo')).toHaveTextContent('check_circle');

        // Volta, completa a 2.2 e sai de novo: o amarelo vira verde.
        fireEvent.click(atalho('Projeto de Pesquisa'));
        fireEvent.click(screen.getAllByLabelText('Sim')[1]);
        fireEvent.click(atalho('Parecer'));
        expect(atalho('Projeto de Pesquisa')).toHaveAttribute('data-situacao', 'completa');
    });

    it('retomando um rascunho, a seção que já tem resposta conta como visitada', async () => {
        iniciarAvaliacaoPresencial.mockResolvedValue({ ...AVALIACAO, respostas: { diario_manuscrito: 'nao' } });
        render(<AvaliadorAvaliacaoPresencial />);
        await abrirDisponivel();

        await screen.findByRole('heading', { name: 'Projeto de Pesquisa' });
        expect(screen.getByRole('button', { name: /^Ir para Diário de Bordo/ })).toHaveAttribute('data-situacao', 'completa');
        expect(screen.getByRole('button', { name: /^Ir para Parecer/ })).toHaveAttribute('data-situacao', 'neutra');
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
            5, { respostas: { projeto_impresso: 'nao' }, comentario: null }, false,
        ));
    });

    // --- O mapa ----------------------------------------------------------

    it('no mapa: clicar no estande livre do turno inicia a avaliação', async () => {
        render(<AvaliadorAvaliacaoPresencial />);

        fireEvent.click(await screen.findByRole('button', { name: /Estande 042 · Bioplástico · Disponível/ }));
        expect(await screen.findByText(/Bioplástico — Estande 42 · Turno A \(matutino\)/)).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Iniciar' }));

        await waitFor(() => expect(iniciarAvaliacaoPresencial).toHaveBeenCalledWith(7, false));
        expect(await screen.findByRole('heading', { name: 'Projeto de Pesquisa' })).toBeInTheDocument();
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

    it('salva o rascunho sem checklist', async () => {
        render(<AvaliadorAvaliacaoPresencial />);
        await abrirDisponivel();

        fireEvent.click(await screen.findByRole('button', { name: 'Ir para Diário de Bordo' }));
        fireEvent.click(screen.getByLabelText('Sim'));
        fireEvent.click(await screen.findByLabelText('Bom (8)'));
        fireEvent.click(screen.getByRole('button', { name: /Salvar rascunho/ }));

        await waitFor(() => expect(salvarRascunhoPresencial).toHaveBeenCalledWith(
            5, { respostas: { diario_manuscrito: 'sim', diario_registro: 8 }, comentario: null }, false,
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
            5, { respostas: { diario_manuscrito: 'nao' }, comentario: null }, false,
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

        expect(await screen.findByText(/O prazo desta avaliação acabou com o horário de avaliação do turno/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Salvar rascunho/ })).not.toBeInTheDocument();
    });
    // --- Regra do comitê: avaliar apenas projetos designados (Sprint 234) ------

    const COMITE = {
        ...PAINEL,
        comissao: true,
        vagas: { comuns: 2, comite: 1, peso_comite: 2 },
        so_designados: true,
        pedidos: [{ id: 3, projeto_id: 12, titulo: 'Horta vertical', status: 'recusado', status_label: 'Recusado', resposta: 'Já tem avaliador.' }],
        disponiveis: [
            { id: 7, titulo: 'Bioplástico', area: 'Agrárias', local: { estande: 42, turno_label: 'Turno A (matutino)' }, avaliacoes: 1, permissao: 'necessaria' },
            { id: 12, titulo: 'Horta vertical', area: 'Agrárias', local: { estande: 44 }, avaliacoes: 0, permissao: 'recusada', permissao_resposta: 'Já tem avaliador.' },
            { id: 13, titulo: 'Solo vivo', area: 'Agrárias', local: { estande: 45 }, avaliacoes: 0, permissao: 'pendente' },
            { id: 14, titulo: 'Designado a mim', area: 'Agrárias', local: { estande: 46 }, avaliacoes: 0, permissao: null },
        ],
        mapa: {
            ...PAINEL.mapa,
            so_designados: true,
            estandes: {
                ...PAINEL.mapa.estandes,
                42: { A: { projeto_id: 7, titulo: 'Bioplástico', situacao: 'pedir_permissao', avaliacao_id: null, avaliacoes: 1, permissao_recusada: false } },
                43: { A: { projeto_id: 13, titulo: 'Solo vivo', situacao: 'permissao_pendente', avaliacao_id: null, avaliacoes: 0 } },
            },
        },
    };

    it('com a regra do comitê, avisa e troca o "Avaliar" pelo pedido de permissão', async () => {
        getPainelPresencial.mockResolvedValue(COMITE);
        render(<AvaliadorAvaliacaoPresencial />);

        expect(await screen.findByText(/só os projetos designados a\s+você/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Pedir permissão para Bioplástico' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Pedir permissão para Horta vertical' })).toBeInTheDocument();
        expect(screen.getByText('Permissão pedida')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Avaliar Designado a mim' })).toBeInTheDocument();
        // Os pedidos dele, com a resposta da organização.
        expect(screen.getByText('Seus pedidos de permissão')).toBeInTheDocument();
        expect(screen.getByText('Resposta: Já tem avaliador.')).toBeInTheDocument();
        // A legenda do mapa ganha as duas situações do comitê.
        expect(screen.getByText('Fora das suas designações — peça permissão')).toBeInTheDocument();
    });

    it('pede permissão pela lista, com justificativa, e recarrega', async () => {
        getPainelPresencial.mockResolvedValue(COMITE);
        pedirPermissaoPresencial.mockResolvedValue({ data: { status: 'pendente' }, meta: { message: 'Pedido enviado.' } });
        render(<AvaliadorAvaliacaoPresencial />);

        fireEvent.click(await screen.findByRole('button', { name: 'Pedir permissão para Horta vertical' }));
        const dialogo = screen.getByRole('dialog', { name: 'Pedir permissão para avaliar' });
        expect(dialogo).toHaveTextContent('O pedido anterior foi recusado: “Já tem avaliador.”');

        fireEvent.change(screen.getByLabelText(/Por que você quer avaliar/), { target: { value: 'Sou da área.' } });
        fireEvent.click(screen.getByRole('button', { name: 'Enviar pedido' }));

        await waitFor(() => expect(pedirPermissaoPresencial).toHaveBeenCalledWith(12, 'Sou da área.', false));
        expect(await screen.findByText('Pedido enviado.')).toBeInTheDocument();
        expect(getPainelPresencial).toHaveBeenCalledTimes(2);
        expect(screen.queryByRole('dialog', { name: 'Pedir permissão para avaliar' })).not.toBeInTheDocument();
    });

    it('no mapa, o estande fora das designações abre o pedido e o pendente só avisa', async () => {
        getPainelPresencial.mockResolvedValue(COMITE);
        render(<AvaliadorAvaliacaoPresencial />);

        fireEvent.click(await screen.findByRole('button', { name: /Estande 043 · Solo vivo/ }));
        expect(screen.getByText(/seu pedido de permissão para avaliar aguarda a organização/)).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /Estande 042 · Bioplástico/ }));
        expect(screen.getByRole('dialog', { name: 'Pedir permissão para avaliar' })).toHaveTextContent('Bioplástico');
        expect(iniciarAvaliacaoPresencial).not.toHaveBeenCalled();
    });

    it('o erro do pedido fica no diálogo', async () => {
        getPainelPresencial.mockResolvedValue(COMITE);
        pedirPermissaoPresencial.mockRejectedValue({ fields: { permissao: ['Você já pediu: o pedido aguarda a organização.'] } });
        render(<AvaliadorAvaliacaoPresencial />);

        fireEvent.click(await screen.findByRole('button', { name: 'Pedir permissão para Bioplástico' }));
        fireEvent.click(screen.getByRole('button', { name: 'Enviar pedido' }));

        expect(await screen.findByText('Você já pediu: o pedido aguarda a organização.')).toBeInTheDocument();
    });
    // --- Sprint 235: designados numa seção própria, com busca ------------------

    const COM_DESIGNADOS = {
        ...PAINEL,
        minhas: [
            { id: 5, projeto_id: 7, titulo: 'Bioplástico', status: 'designada', status_label: 'Designada', pode_escrever: true, escolha_livre: false, designacao_manual: true, local: { estande: 42 } },
            { id: 6, projeto_id: 9, titulo: 'Drone agrícola', status: 'em_andamento', status_label: 'Em andamento', pode_escrever: true, escolha_livre: true, local: { estande: 50 } },
        ],
        designados_comite: [
            { id: 12, titulo: 'Horta vertical', area: 'Agrárias', local: { estande: 7, turno_label: 'Turno A (matutino)' } },
        ],
        disponiveis: [
            { id: 12, titulo: 'Horta vertical', area: 'Agrárias', local: { estande: 7 }, avaliacoes: 0 },
            { id: 20, titulo: 'Abelhas nativas', area: 'Agrárias', local: { estande: 21 }, avaliacoes: 0 },
            { id: 21, titulo: 'Solo vivo', area: 'Agrárias', local: { estande: 22 }, avaliacoes: 0 },
        ],
    };

    it('os designados ficam numa seção própria, separados do que ele escolheu', async () => {
        getPainelPresencial.mockResolvedValue(COM_DESIGNADOS);
        render(<AvaliadorAvaliacaoPresencial />);

        const designados = await screen.findByRole('region', { name: 'Designados a você' });
        expect(within(designados).getByText('(2)')).toBeInTheDocument();
        expect(within(designados).getByText('Bioplástico')).toBeInTheDocument();
        expect(within(designados).getByText('Horta vertical')).toBeInTheDocument();
        expect(within(designados).getByText(/designado pelo comitê/)).toBeInTheDocument();
        expect(within(designados).queryByText('Drone agrícola')).not.toBeInTheDocument();

        const escolhidos = screen.getByRole('region', { name: 'Escolhidos por você' });
        expect(within(escolhidos).getByText('Drone agrícola')).toBeInTheDocument();

        // O designado pelo comitê não se repete nos disponíveis.
        const disponiveis = screen.getByRole('region', { name: 'Estandes disponíveis' });
        expect(within(disponiveis).queryByText('Horta vertical')).not.toBeInTheDocument();
        expect(within(disponiveis).getByText('Abelhas nativas')).toBeInTheDocument();
    });

    it('busca pelo nome ou pelo estande, nos designados e nos disponíveis', async () => {
        getPainelPresencial.mockResolvedValue(COM_DESIGNADOS);
        render(<AvaliadorAvaliacaoPresencial />);

        const designados = await screen.findByRole('region', { name: 'Designados a você' });
        fireEvent.change(screen.getByLabelText('Buscar entre os designados'), { target: { value: '007' } });
        expect(within(designados).getByText('Horta vertical')).toBeInTheDocument();
        expect(within(designados).queryByText('Bioplástico')).not.toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Buscar entre os designados'), { target: { value: 'nada disso' } });
        expect(within(designados).getByText('Nenhum designado encontrado.')).toBeInTheDocument();

        const disponiveis = screen.getByRole('region', { name: 'Estandes disponíveis' });
        fireEvent.change(screen.getByLabelText('Buscar entre os estandes disponíveis'), { target: { value: 'solo' } });
        expect(within(disponiveis).getByText('Solo vivo')).toBeInTheDocument();
        expect(within(disponiveis).queryByText('Abelhas nativas')).not.toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Buscar entre os estandes disponíveis'), { target: { value: 'estande 21' } });
        expect(within(disponiveis).getByText('Abelhas nativas')).toBeInTheDocument();
    });

    it('o designado pelo comitê inicia pelo mesmo caminho do corredor', async () => {
        getPainelPresencial.mockResolvedValue(COM_DESIGNADOS);
        render(<AvaliadorAvaliacaoPresencial />);

        const designados = await screen.findByRole('region', { name: 'Designados a você' });
        fireEvent.click(within(designados).getByRole('button', { name: 'Avaliar Horta vertical' }));
        fireEvent.click(await screen.findByRole('button', { name: 'Iniciar' }));

        await waitFor(() => expect(iniciarAvaliacaoPresencial).toHaveBeenCalledWith(12, false));
    });

    it('sem nenhum designado, a seção diz isso', async () => {
        render(<AvaliadorAvaliacaoPresencial />);

        const designados = await screen.findByRole('region', { name: 'Designados a você' });
        expect(within(designados).getByText(/Nenhum projeto designado a você/)).toBeInTheDocument();
    });

    // --- Comitê fora do horário (Sprint 238) -------------------------------

    it('fora do horário, o comitê vê que pode salvar rascunho e quando o envio abre', async () => {
        getPainelPresencial.mockResolvedValue({
            ...PAINEL, turno: null, comissao: true, vagas: { comuns: 2, comite: 1, peso_comite: 2 },
            comite: { envio_aberto: false, proximo_envio_label: '20/10 às 19:00', noite_label: '19:00–23:00' },
            minhas: [{ id: 5, projeto_id: 7, titulo: 'Bioplástico', status: 'em_andamento', status_label: 'Em andamento', pode_escrever: true, sem_prazo: true, escolha_livre: true, local: {} }],
        });
        render(<AvaliadorAvaliacaoPresencial />);

        expect(await screen.findByText(/Fora do horário de envio/)).toHaveTextContent('O envio abre em 20/10 às 19:00.');
        expect(screen.getByText(/Fora do horário de envio/)).toHaveTextContent('Lançamento noturno do comitê: 19:00–23:00.');
        const escolhidos = screen.getByRole('region', { name: 'Escolhidos por você' });
        expect(within(escolhidos).getByText('Bioplástico').closest('li')).toHaveTextContent('rascunho sem prazo');
        expect(screen.getByText(/Projetos de qualquer turno/)).toBeInTheDocument();
    });

    it('com o envio fechado, o wizard salva o rascunho e trava o envio dizendo por quê', async () => {
        iniciarAvaliacaoPresencial.mockResolvedValue({
            ...AVALIACAO, pode_enviar: false, sem_prazo: true, prazo_label: null,
            envio_fechado: 'Fora do horário de envio: salve como rascunho. O envio abre em 20/10 às 19:00.',
            respostas: { projeto_impresso: 'nao', diario_manuscrito: 'nao' },
        });
        render(<AvaliadorAvaliacaoPresencial />);
        await abrirDisponivel();

        expect(await screen.findByText(/O envio abre em 20\/10 às 19:00/)).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /^Ir para Parecer/ }));
        expect(screen.getByRole('button', { name: /Enviar avaliação/ })).toBeDisabled();

        fireEvent.click(screen.getByRole('button', { name: /Salvar rascunho/ }));
        await waitFor(() => expect(salvarRascunhoPresencial).toHaveBeenCalled());
    });
});
