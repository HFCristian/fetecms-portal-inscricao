import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({
    Link: ({ children, to }) => <a href={to}>{children}</a>,
    useParams: () => ({ id: '3' }),
}));
vi.mock('../lib/auth.jsx', () => ({ extractErrors: () => ({ message: 'erro', fields: {} }) }));

const DADOS = {
    lista: { id: 3, nome: 'Oficial 2026', tipo: 'final', vigente: true, rascunho: false, versao: 1, projetos: 1, origens: [] },
    itens: [{
        projeto_id: 10, codigo: 'FET.AGR-001', titulo: 'Bioplástico',
        categoria: 'FETECMS', area: 'Ciências Agrárias', escola: 'EE Alfa', manual: false,
    }],
    candidatos: [
        { id: 20, titulo: 'Robótica', categoria: 'FETEC Jr', area: 'Engenharias', media: 7.5 },
    ],
};

const RASCUNHO = {
    ...DADOS,
    lista: { ...DADOS.lista, vigente: false, rascunho: true, nome: 'Prévia 2026' },
};

const getListaFinal = vi.fn(() => Promise.resolve(DADOS));
const publicarListaFinal = vi.fn(() => Promise.resolve({
    data: { ...DADOS, lista: { ...DADOS.lista, vigente: true, rascunho: false } },
    meta: { message: 'Lista publicada — ela define os finalistas da feira.' },
}));
const adicionarNaListaFinal = vi.fn(() => Promise.resolve({ ...DADOS, lista: { ...DADOS.lista, versao: 2, projetos: 2 } }));
const removerDaListaFinal = vi.fn(() => Promise.resolve({ ...DADOS, itens: [], lista: { ...DADOS.lista, versao: 2, projetos: 0 } }));
const PAINEL_CODIGOS = {
    lista: { id: 3, nome: 'Oficial 2026', versao: 1, codigos_congelados_em: null, codigos_enviados_em: null, codigos_mala_id: null },
    pode_enviar: true,
    motivo: null,
    destinatarios: { lista: 'Oficial 2026 (v1)', pessoas: 4, sem_email: 1 },
    assunto: 'Código do seu projeto — XVI FETECMS',
    corpo: 'Olá, {{nome}}!\n\n{{projetos}}',
    formato: 'texto',
    projetos: [{ projeto_id: 10, codigo: 'FET.AGR-001', titulo: 'Bioplástico' }],
};
const getCodigosLista = vi.fn(() => Promise.resolve(PAINEL_CODIGOS));
const enviarCodigosLista = vi.fn(() => Promise.resolve({
    data: { ...PAINEL_CODIGOS, lista: { ...PAINEL_CODIGOS.lista, codigos_congelados_em: '2026-10-03T10:00:00-04:00', codigos_enviados_em: '2026-10-03T10:00:00-04:00', codigos_mala_id: 77 } },
    meta: { message: 'Envio iniciado: os e-mails saem pela fila.', mala_id: 77 },
}));
const OPCOES_EXPORTACAO = {
    niveis: [{ valor: 'pessoa', rotulo: 'Uma linha por pessoa' }, { valor: 'projeto', rotulo: 'Uma linha por projeto' }],
    colunas: {
        pessoa: [{ chave: 'nome', rotulo: 'Nome completo' }, { chave: 'funcao', rotulo: 'Função' }, { chave: 'cpf', rotulo: 'CPF' }],
        projeto: [{ chave: 'codigo_projeto', rotulo: 'Código do projeto' }, { chave: 'projeto', rotulo: 'Título' }],
    },
    modelos: [{ chave: 'nominal', rotulo: 'Lista nominal', descricao: 'Para crachás.', nivel: 'pessoa', colunas: ['nome', 'funcao'] }],
    formatos: ['csv', 'xlsx'],
};
const exportarListaFinal = vi.fn(() => Promise.resolve());
const reativarListaFinal = vi.fn();
vi.mock('../lib/admin.js', () => ({
    reativarListaFinal: (...a) => reativarListaFinal(...a),
    getOpcoesExportacaoLista: () => Promise.resolve(OPCOES_EXPORTACAO),
    exportarListaFinal: (...a) => exportarListaFinal(...a),
    getCodigosLista: (...a) => getCodigosLista(...a),
    congelarCodigosLista: vi.fn(),
    enviarCodigosLista: (...a) => enviarCodigosLista(...a),
    getListaFinal: (...a) => getListaFinal(...a),
    adicionarNaListaFinal: (...a) => adicionarNaListaFinal(...a),
    removerDaListaFinal: (...a) => removerDaListaFinal(...a),
    baixarListaOficial: vi.fn(),
    publicarListaFinal: (...a) => publicarListaFinal(...a),
    // A seção de identificação (Sprint 123) mora dentro desta tela e busca os
    // participantes sozinha; aqui ela responde vazia, e tem teste próprio.
    getIdentificacao: () => Promise.resolve({
        lista: { id: 1, nome: 'Lista oficial', versao: 1, demo: false },
        total: 0, por_papel: {}, participantes: [],
    }),
    baixarIdentificacao: vi.fn(),
    urlCodigo: (codigo, tipo) => `/api/v1/admin/avaliacao/identificacao/${tipo}/${codigo}.svg`,
}));

import AvaliacaoListaFinalDetalhe from './AvaliacaoListaFinalDetalhe.jsx';

describe('AvaliacaoListaFinalDetalhe', () => {
    beforeEach(() => {
        adicionarNaListaFinal.mockClear();
        removerDaListaFinal.mockClear();
    });

    it('mostra a composição atual com o código e a versão da lista', async () => {
        render(<AvaliacaoListaFinalDetalhe />);

        expect(await screen.findByText('Bioplástico')).toBeInTheDocument();
        expect(screen.getByText('FET.AGR-001')).toBeInTheDocument();
        expect(screen.getByText(/Versão 1 · 1 projeto/)).toBeInTheDocument();
        expect(screen.getByText('ativa')).toBeInTheDocument();
    });

    it('exige justificativa para retirar um projeto', async () => {
        render(<AvaliacaoListaFinalDetalhe />);
        await screen.findByText('Bioplástico');

        fireEvent.click(screen.getByRole('button', { name: /Retirar/ }));

        const confirmar = screen.getByRole('button', { name: 'Retirar' });
        expect(confirmar).toBeDisabled();

        fireEvent.change(screen.getByLabelText(/Justificativa/), { target: { value: 'Descumpriu o edital.' } });
        expect(confirmar).toBeEnabled();

        fireEvent.click(confirmar);
        await waitFor(() => expect(removerDaListaFinal).toHaveBeenCalledWith('3', 10, 'Descumpriu o edital.'));
    });

    it('inclui um projeto escolhido entre os candidatos, com justificativa', async () => {
        render(<AvaliacaoListaFinalDetalhe />);
        await screen.findByText('Bioplástico');

        // O botão só habilita depois de escolher o projeto no combobox.
        expect(screen.getByRole('button', { name: /Incluir/ })).toBeDisabled();

        fireEvent.change(screen.getByPlaceholderText(/Buscar entre os projetos submetidos/), { target: { value: 'Rob' } });
        // O combobox seleciona no mouseDown (antes de perder o foco).
        fireEvent.mouseDown(await screen.findByText('Robótica'));
        fireEvent.click(screen.getByRole('button', { name: /Incluir/ }));

        fireEvent.change(screen.getByLabelText(/Justificativa/), { target: { value: 'Recurso deferido.' } });
        fireEvent.click(screen.getByRole('button', { name: 'Incluir' }));

        await waitFor(() => expect(adicionarNaListaFinal).toHaveBeenCalledWith('3', 20, 'Recurso deferido.'));
    });
});

describe('AvaliacaoListaFinalDetalhe — prévia', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        getListaFinal.mockResolvedValue(RASCUNHO);
        publicarListaFinal.mockResolvedValue({
            data: { ...DADOS, lista: { ...DADOS.lista, vigente: true, rascunho: false } },
            meta: { message: 'Lista publicada — ela define os finalistas da feira.' },
        });
    });

    it('marca o rascunho e explica o que acontece ao gerar', async () => {
        render(<AvaliacaoListaFinalDetalhe />);

        expect(await screen.findByText('rascunho')).toBeInTheDocument();
        expect(screen.getByText(/inclua e retire à vontade, sem justificativa/i)).toBeInTheDocument();
        expect(screen.getByText(/vira a lista final ativa/i)).toBeInTheDocument();
    });

    it('no rascunho retira e inclui na hora, sem justificativa', async () => {
        removerDaListaFinal.mockResolvedValue({ ...RASCUNHO, itens: [] });
        adicionarNaListaFinal.mockResolvedValue(RASCUNHO);
        render(<AvaliacaoListaFinalDetalhe />);
        await screen.findByText('Bioplástico');

        fireEvent.click(screen.getByRole('button', { name: /Retirar/ }));
        await waitFor(() => expect(removerDaListaFinal).toHaveBeenCalledWith('3', 10));
        expect(screen.queryByLabelText(/Justificativa/)).not.toBeInTheDocument();

        fireEvent.change(screen.getByPlaceholderText(/Buscar entre os projetos submetidos/), { target: { value: 'Rob' } });
        fireEvent.mouseDown(await screen.findByText('Robótica'));
        fireEvent.click(screen.getByRole('button', { name: /Incluir/ }));
        await waitFor(() => expect(adicionarNaListaFinal).toHaveBeenCalledWith('3', 20));
    });

    it('gera a lista final depois de confirmar que ela vira a ativa', async () => {
        render(<AvaliacaoListaFinalDetalhe />);

        fireEvent.click(await screen.findByRole('button', { name: /Gerar lista final/i }));
        fireEvent.click(await screen.findByRole('button', { name: 'Gerar e ativar' }));

        await waitFor(() => expect(publicarListaFinal).toHaveBeenCalledWith('3'));
        expect(await screen.findByText(/Lista publicada/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Gerar lista final/i })).not.toBeInTheDocument();
    });

    it('preliminar gera direto e não vira ativa', async () => {
        getListaFinal.mockResolvedValue({ ...RASCUNHO, lista: { ...RASCUNHO.lista, tipo: 'preliminar' } });
        publicarListaFinal.mockResolvedValue({
            data: { ...RASCUNHO, lista: { ...RASCUNHO.lista, tipo: 'preliminar', rascunho: false } },
            meta: { message: 'Lista preliminar gerada.' },
        });
        render(<AvaliacaoListaFinalDetalhe />);

        expect(await screen.findByText('preliminar')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /Gerar lista preliminar/i }));

        await waitFor(() => expect(publicarListaFinal).toHaveBeenCalledWith('3'));
        expect(await screen.findByText('Lista preliminar gerada.')).toBeInTheDocument();
    });

    it('final inativa volta a ser a ativa com justificativa', async () => {
        getListaFinal.mockResolvedValue({ ...DADOS, lista: { ...DADOS.lista, vigente: false } });
        reativarListaFinal.mockResolvedValue({ data: DADOS, meta: { message: 'Esta lista final voltou a ser a ativa.' } });
        render(<AvaliacaoListaFinalDetalhe />);

        expect(await screen.findByText('inativa')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /Tornar ativa/ }));
        fireEvent.change(screen.getByLabelText(/Justificativa/), { target: { value: 'Recurso deferido.' } });
        fireEvent.click(screen.getAllByRole('button', { name: 'Tornar ativa' }).at(-1));

        await waitFor(() => expect(reativarListaFinal).toHaveBeenCalledWith('3', 'Recurso deferido.'));
        expect(await screen.findByText(/voltou a ser a ativa/)).toBeInTheDocument();
    });

    it('lista já publicada não oferece publicar de novo', async () => {
        getListaFinal.mockResolvedValue(DADOS);
        render(<AvaliacaoListaFinalDetalhe />);

        expect(await screen.findByText('Bioplástico')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Gerar lista/i })).not.toBeInTheDocument();
    });
});

describe('AvaliacaoListaFinalDetalhe — código do projeto', () => {
    beforeEach(() => {
        getListaFinal.mockResolvedValue(DADOS);
        enviarCodigosLista.mockClear();
    });

    it('envia o código aos finalistas depois de confirmar o texto', async () => {
        render(<AvaliacaoListaFinalDetalhe />);

        expect(await screen.findByText(/4 pessoas recebem · 1 sem e-mail/)).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /Enviar código aos finalistas/ }));

        expect(screen.getByText('Código do seu projeto — XVI FETECMS')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Enviar agora' }));

        await waitFor(() => expect(enviarCodigosLista).toHaveBeenCalledWith(3));
        expect(await screen.findByText(/Envio iniciado/)).toBeInTheDocument();
        expect(screen.getByText('acompanhar o relatório')).toHaveAttribute('href', '/admin/mala-direta/77');
    });

    it('baixa a lista nominal e monta um recorte por projeto', async () => {
        render(<AvaliacaoListaFinalDetalhe />);

        expect(await screen.findByText('Lista nominal')).toBeInTheDocument();
        fireEvent.click(screen.getAllByRole('button', { name: /Excel/ })[0]);
        await waitFor(() => expect(exportarListaFinal).toHaveBeenCalledWith(3, {
            nivel: 'pessoa', colunas: ['nome', 'funcao'], formato: 'xlsx', modelo: 'nominal',
        }));

        fireEvent.click(screen.getByLabelText('Uma linha por projeto'));
        fireEvent.click(screen.getByRole('button', { name: /Baixar recorte/ }));
        await waitFor(() => expect(exportarListaFinal).toHaveBeenLastCalledWith(3, {
            nivel: 'projeto', colunas: ['codigo_projeto', 'projeto'], formato: 'xlsx',
        }));
    });
});
