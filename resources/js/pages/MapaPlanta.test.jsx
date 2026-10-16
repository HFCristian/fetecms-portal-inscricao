import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
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

const getPlanta = vi.fn();
const salvarPlanta = vi.fn();
const restaurarPlanta = vi.fn();
const getSituacaoPlanta = vi.fn();
const getListaSituacao = vi.fn();
const baixarListaSituacao = vi.fn();
const getRemanejamento = vi.fn();
const alocarNoMapa = vi.fn();
vi.mock('../lib/mapaEvento.js', () => ({
    getPlanta: (...a) => getPlanta(...a),
    salvarPlanta: (...a) => salvarPlanta(...a),
    restaurarPlanta: (...a) => restaurarPlanta(...a),
    getSituacaoPlanta: (...a) => getSituacaoPlanta(...a),
    getListaSituacao: (...a) => getListaSituacao(...a),
    baixarListaSituacao: (...a) => baixarListaSituacao(...a),
    getRemanejamento: (...a) => getRemanejamento(...a),
    alocarNoMapa: (...a) => alocarNoMapa(...a),
}));

import MapaPlanta from './MapaPlanta.jsx';

const projeto = (titulo) => ({
    projeto_id: 1, numero: 42, estande: '042', titulo, categoria: 'FETECMS',
    area: 'Ciências Agrárias', escola: 'EE Maria Constança', cidade: 'Campo Grande',
    uf: 'MS', orientador: 'Marta', manual: false,
});

const painel = (over = {}) => ({
    edicao: { id: 1, nome: 'XVI FETECMS' },
    layout: {
        nome: 'Planta padrão',
        estandes: [
            { numero: 1, x: 0, y: 0 },
            { numero: 2, x: 1, y: 0 },
            { numero: 42, x: 3, y: 0 },
        ],
        marcacoes: [{ rotulo: 'Entrada', x: 1, y: 2, largura: 2, altura: 1 }],
    },
    versao: 3,
    versao_id: 9,
    salva: true,
    turnos: [
        { value: 'A', label: 'Turno A (matutino)', curto: 'Matutino' },
        { value: 'B', label: 'Turno B (vespertino)', curto: 'Vespertino' },
    ],
    ocupacao: {
        42: { numero: 42, A: projeto('Bioplástico de mandioca'), B: projeto('Sensor de nível') },
        1: { numero: 1, A: projeto('Horta na escola'), B: null },
    },
    ocupados: 2,
    versoes: [
        { id: 9, versao: 3, nome: 'Planta padrão', vigente: true, estandes: 3, autor: 'Pedro', criada_em: '2026-09-10T10:00:00-04:00' },
        { id: 8, versao: 2, nome: 'Planta anterior', vigente: false, estandes: 4, autor: 'Pedro', criada_em: '2026-09-01T10:00:00-04:00' },
    ],
    // Os corredores entre as ilhas: achados no desenho, nomeados pelo admin.
    ruas: [
        { chave: 'v:2.5', orientacao: 'v', posicao: 2.5, de: 0, ate: 1, nome: 'Rua das Agrárias' },
        { chave: 'h:8', orientacao: 'h', posicao: 8, de: 0, ate: 4, nome: null },
    ],
    ...over,
});

const FILTROS = {
    dias: [
        { value: '2026-10-01', label: '01/10', hoje: false },
        { value: '2026-10-02', label: '02/10 (hoje)', hoje: true },
    ],
    turnos: [
        { value: 'A', label: 'Turno A (matutino)', curto: 'Matutino' },
        { value: 'B', label: 'Turno B (vespertino)', curto: 'Vespertino' },
    ],
    legenda: [
        { value: 'livre', label: 'Sem projeto', cor: '#ffffff' },
        { value: 'aguardando', label: 'Aguardando credenciamento', cor: '#efe7fa' },
        { value: 'credenciado', label: 'Credenciado', cor: '#c9b6e8' },
        { value: 'checado', label: 'Pronto para avaliação', cor: '#9fd8ae' },
        { value: 'avaliado', label: 'Em avaliação', cor: '#2f8f4e' },
    ],
    criterios: [
        { value: 'credenciamento', label: 'Credenciamento', tipo: 'booleano' },
        { value: 'checagem', label: 'Checagem do estande', tipo: 'booleano' },
        { value: 'avaliacoes_realizadas', label: 'Avaliações realizadas', tipo: 'numero' },
        { value: 'avaliacoes_faltantes', label: 'Avaliações faltantes', tipo: 'numero' },
        {
            value: 'termo_imagem', label: 'Termo de uso de imagem', tipo: 'opcoes', padrao: 'incompleto',
            valores: [
                { value: 'incompleto', label: 'Incompleto (parcial ou pendente)' },
                { value: 'completo', label: 'Completo' },
                { value: 'parcial', label: 'Parcial' },
                { value: 'nenhum', label: 'Pendente' },
            ],
        },
    ],
    max_avaliacoes: 3,
    areas: [
        { id: 3, nome: 'Ciências Agrárias', sigla: 'AGR' },
        { id: 4, nome: 'Ciências Biológicas', sigla: 'BIO' },
    ],
};

const SITUACAO = {
    dia: '2026-10-02',
    turno: 'A',
    turno_label: 'Turno A (matutino)',
    corte_label: '02/10/2026 14:00',
    estandes: {
        42: {
            numero: 42, estande: '042', projeto_id: 1, titulo: 'Bioplástico de mandioca',
            categoria: 'FETECMS', area: 'Ciências Agrárias', escola: 'EE Maria Constança',
            orientador: 'Marta', situacao: 'checado', situacao_label: 'Pronto para avaliação',
            area_id: 3, area_sigla: 'AGR', avaliacoes_comite: 0,
            cor: '#9fd8ae', credenciado: true, credenciado_em: '02/10/2026 09:10',
            checado: true, checado_em: '02/10/2026 10:30',
            avaliacoes: 0, avaliacoes_faltantes: 3, avaliacoes_maximo: 3,
            termo_imagem: 'parcial', termo_imagem_label: 'Parcial', termo_imagem_cobertos: 2,
            termo_imagem_total: 3, termo_imagem_online: 1, termo_imagem_balcao: 1,
            termo_imagem_faltando: ['Caio'], termo_imagem_arquivos: [{ id: 77, nome_original: 'ana.pdf' }],
        },
    },
    resumo: { livre: 0, aguardando: 1, credenciado: 0, checado: 1, avaliado: 0 },
    total: 2,
};

const resposta = (over = {}) => ({ data: painel(over), meta: { filtros: FILTROS } });

/** O painel de remanejamento: ocupação com ids distintos por projeto. */
const remanejamento = (over = {}) => ({
    tem_lista: true,
    turnos_gerados: true,
    estandes_gerados: true,
    capacidade: { A: 230, B: 230 },
    ocupacao_turnos: { A: 2, B: 1 },
    numeros: [1, 2, 42],
    ocupacao: {
        42: { numero: 42, A: { projeto_id: 1, titulo: 'Bioplástico de mandioca' }, B: { projeto_id: 2, titulo: 'Sensor de nível' } },
        1: { numero: 1, A: { projeto_id: 3, titulo: 'Horta na escola' }, B: null },
    },
    sem_turno: [],
    sem_estande: [],
    fora_da_lista: [],
    ...over,
});

describe('MapaPlanta', () => {
    beforeEach(() => {
        getPlanta.mockReset().mockResolvedValue(resposta());
        salvarPlanta.mockReset();
        restaurarPlanta.mockReset();
        getSituacaoPlanta.mockReset().mockResolvedValue(SITUACAO);
        getListaSituacao.mockReset();
        baixarListaSituacao.mockReset().mockResolvedValue(undefined);
        getRemanejamento.mockReset().mockResolvedValue(remanejamento());
        alocarNoMapa.mockReset().mockResolvedValue({
            data: { remanejamento: remanejamento(), turnos: {}, movimentos: [], avisos: [] },
            meta: { message: 'Projeto remanejado.' },
        });
    });

    describe('remanejar sem refazer', () => {
        it('trocar: escolhe o destino clicando noutro estande e decide o que acontece com o ocupante', async () => {
            render(<MapaPlanta />);
            await waitFor(() => expect(getRemanejamento).toHaveBeenCalled());

            fireEvent.click(await screen.findByRole('button', { name: 'Estande 042' }));
            fireEvent.click(await screen.findByRole('button', { name: 'Trocar Bioplástico de mandioca de lugar' }));
            expect(screen.getByText(/Clique no estande de destino/)).toBeInTheDocument();

            fireEvent.click(screen.getByRole('button', { name: 'Estande 001' }));

            const dialogo = await screen.findByRole('dialog', { name: 'Trocar de lugar' });
            expect(within(dialogo).getByLabelText('Número do estande')).toHaveValue(1);
            expect(within(dialogo).getByText(/já é de/)).toBeInTheDocument();

            fireEvent.click(within(dialogo).getByLabelText(/Empurrar a fila/));
            fireEvent.click(within(dialogo).getByRole('button', { name: 'Confirmar' }));

            await waitFor(() => expect(alocarNoMapa).toHaveBeenCalledWith({
                projetoId: 1, turno: 'A', numero: 1, modo: 'empurrar',
            }));
            expect(await screen.findByText('Projeto remanejado.')).toBeInTheDocument();
        });

        it('estande vazio aloca um finalista que ainda não tem lugar', async () => {
            getRemanejamento.mockResolvedValue(remanejamento({
                sem_turno: [{ projeto_id: 9, titulo: 'Chegou depois', codigo: 'FET.AGR-009', turno: null, numero: null }],
            }));

            render(<MapaPlanta />);

            expect(await screen.findByText(/1 finalista\(s\) ainda sem lugar no mapa/)).toBeInTheDocument();

            fireEvent.click(await screen.findByRole('button', { name: 'Estande 002' }));
            fireEvent.click(await screen.findByRole('button', { name: 'Alocar projeto no estande 2 turno B' }));

            const dialogo = await screen.findByRole('dialog', { name: 'Alocar projeto no estande' });
            expect(within(dialogo).getByText('FET.AGR-009 · Chegou depois')).toBeInTheDocument();
            fireEvent.click(within(dialogo).getByRole('button', { name: 'Alocar' }));

            await waitFor(() => expect(alocarNoMapa).toHaveBeenCalledWith({ projetoId: 9, turno: 'B', numero: 2 }));
        });

        it('sem estandes distribuídos não oferece trocar nem alocar', async () => {
            getRemanejamento.mockResolvedValue(remanejamento({ estandes_gerados: false, ocupacao: {} }));

            render(<MapaPlanta />);
            await waitFor(() => expect(getRemanejamento).toHaveBeenCalled());

            fireEvent.click(await screen.findByRole('button', { name: 'Estande 042' }));
            await screen.findByText('Sensor de nível');

            expect(screen.queryByRole('button', { name: /Trocar/ })).not.toBeInTheDocument();
        });
    });

    it('desenha um estande por número, com a entrada marcada', async () => {
        render(<MapaPlanta />);

        expect(await screen.findByRole('button', { name: 'Estande 001' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Estande 042' })).toBeInTheDocument();
        expect(screen.getByText('Entrada')).toBeInTheDocument();
        expect(screen.getByText(/3 estande\(s\) no desenho · 2 com projeto · versão 3 em vigor/)).toBeInTheDocument();
    });

    it('clicar num estande mostra quem apresenta nos dois turnos', async () => {
        render(<MapaPlanta />);

        fireEvent.click(await screen.findByRole('button', { name: 'Estande 042' }));

        expect(await screen.findByText('Estande 042')).toBeInTheDocument();
        // O rótulo do turno aparece duas vezes: no seletor do mapa e no cabeçalho
        // de cada bloco da ficha.
        expect(screen.getAllByText('Turno A (matutino)').length).toBeGreaterThan(1);
        expect(screen.getByText('Bioplástico de mandioca')).toBeInTheDocument();
        expect(screen.getAllByText('Turno B (vespertino)').length).toBeGreaterThan(0);
        expect(screen.getByText('Sensor de nível')).toBeInTheDocument();
    });

    it('estande com um turno só diz que o outro está vazio', async () => {
        render(<MapaPlanta />);

        fireEvent.click(await screen.findByRole('button', { name: 'Estande 001' }));

        expect(await screen.findByText('Horta na escola')).toBeInTheDocument();
        expect(screen.getByText('Sem projeto neste turno.')).toBeInTheDocument();
    });

    it('estande sem projeto nenhum abre o painel vazio, sem quebrar', async () => {
        render(<MapaPlanta />);

        fireEvent.click(await screen.findByRole('button', { name: 'Estande 002' }));

        expect(await screen.findByText('Estande 002')).toBeInTheDocument();
        expect(screen.getAllByText('Sem projeto neste turno.')).toHaveLength(2);
    });

    it('fora do modo de edição não há como mexer no desenho', async () => {
        render(<MapaPlanta />);

        expect(await screen.findByRole('button', { name: 'Editar planta' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Adicionar estande' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Salvar como nova versão' })).not.toBeInTheDocument();
    });

    it('acrescenta um estande e só então libera o salvamento', async () => {
        salvarPlanta.mockResolvedValue({ data: painel(), meta: { message: 'Planta salva como uma versão nova.' } });

        render(<MapaPlanta />);
        fireEvent.click(await screen.findByRole('button', { name: 'Editar planta' }));

        // Sem alteração, salvar fica desabilitado: não se cria versão à toa.
        expect(screen.getByRole('button', { name: 'Salvar como nova versão' })).toBeDisabled();

        fireEvent.click(screen.getByRole('button', { name: 'Adicionar estande' }));

        expect(await screen.findByRole('button', { name: 'Estande 043' })).toBeInTheDocument();

        const salvar = screen.getByRole('button', { name: 'Salvar como nova versão' });
        expect(salvar).toBeEnabled();
        fireEvent.click(salvar);

        await waitFor(() => expect(salvarPlanta).toHaveBeenCalled());
        expect(salvarPlanta.mock.calls[0][0].estandes).toHaveLength(4);
        expect(await screen.findByText('Planta salva como uma versão nova.')).toBeInTheDocument();
    });

    it('cancelar a edição devolve o desenho que estava salvo', async () => {
        render(<MapaPlanta />);
        fireEvent.click(await screen.findByRole('button', { name: 'Editar planta' }));
        fireEvent.click(screen.getByRole('button', { name: 'Adicionar estande' }));

        expect(await screen.findByRole('button', { name: 'Estande 043' })).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Cancelar' }));

        await waitFor(() =>
            expect(screen.queryByRole('button', { name: 'Estande 043' })).not.toBeInTheDocument());
        expect(salvarPlanta).not.toHaveBeenCalled();
    });

    it('recusa renumerar para um número que já existe', async () => {
        render(<MapaPlanta />);
        fireEvent.click(await screen.findByRole('button', { name: 'Editar planta' }));
        fireEvent.click(screen.getByRole('button', { name: 'Estande 001' }));

        fireEvent.change(await screen.findByLabelText('Número do estande'), { target: { value: '42' } });
        fireEvent.click(screen.getByRole('button', { name: 'Renumerar' }));

        expect(await screen.findByText('O estande 42 já existe na planta.')).toBeInTheDocument();
    });

    it('lista as versões e restaura uma anterior', async () => {
        restaurarPlanta.mockResolvedValue({
            data: painel(),
            meta: { message: 'Planta da versão 2 restaurada como versão nova.' },
        });

        render(<MapaPlanta />);

        expect(await screen.findByText(/v2 · 4 estandes/)).toBeInTheDocument();
        // A vigente não oferece "Restaurar" — não há para onde voltar.
        expect(screen.getAllByRole('button', { name: 'Restaurar' })).toHaveLength(1);

        fireEvent.click(screen.getByRole('button', { name: 'Restaurar' }));

        // O diálogo explica que restaurar também cria uma versão nova.
        expect(await screen.findByText(/gravado como uma versão nova/)).toBeInTheDocument();
        // O botão da lista e o do diálogo têm o mesmo nome: o do diálogo é o segundo.
        fireEvent.click(screen.getAllByRole('button', { name: 'Restaurar' })[1]);

        await waitFor(() => expect(restaurarPlanta).toHaveBeenCalledWith(8));
    });
    // ------------------------------------------------------------------ //
    // A planta que muda de cor durante o evento                           //
    // ------------------------------------------------------------------ //

    it('pinta o estande pela situação e mostra a legenda com as contagens', async () => {
        render(<MapaPlanta />);

        await waitFor(() => expect(getSituacaoPlanta).toHaveBeenCalledWith({ dia: '2026-10-02', turno: 'A' }));

        // A legenda sai do servidor, então tela, desenho e PDF não divergem.
        expect(screen.getByText(/Pronto para avaliação \(1\)/)).toBeInTheDocument();
        expect(screen.getByText(/Aguardando credenciamento \(1\)/)).toBeInTheDocument();
    });

    it('a ficha do estande conta por onde o projeto já passou', async () => {
        render(<MapaPlanta />);

        fireEvent.click(await screen.findByRole('button', { name: 'Estande 042' }));

        expect(await screen.findByText('Pronto para avaliação')).toBeInTheDocument();
        expect(screen.getByText('Credenciado em 02/10/2026 09:10')).toBeInTheDocument();
        expect(screen.getByText('Estande checado em 02/10/2026 10:30')).toBeInTheDocument();
        expect(screen.getByText(/0 de 3 avaliação\(ões\) · faltam 3/)).toBeInTheDocument();
        // Termo de uso de imagem: quantos, de onde veio e quem falta.
        expect(screen.getByText('Termo de imagem: Parcial · 2 de 3 (1 online · 1 no balcão)')).toBeInTheDocument();
        expect(screen.getByText('Falta: Caio')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'ana.pdf' })).toHaveAttribute('href', '/api/v1/documentos/77/preview');
    });

    /** O dia é um corte no tempo: escolher um anterior volta o mapa. */
    it('trocar o dia e o turno recarrega a situação', async () => {
        render(<MapaPlanta />);
        await waitFor(() => expect(getSituacaoPlanta).toHaveBeenCalled());

        fireEvent.change(screen.getByLabelText('Dia do evento'), { target: { value: '2026-10-01' } });
        await waitFor(() => expect(getSituacaoPlanta).toHaveBeenLastCalledWith({ dia: '2026-10-01', turno: 'A' }));

        fireEvent.change(screen.getByLabelText('Turno'), { target: { value: 'B' } });
        await waitFor(() => expect(getSituacaoPlanta).toHaveBeenLastCalledWith({ dia: '2026-10-01', turno: 'B' }));
    });

    it('o modo ocupação volta à leitura dos dois turnos, sem consultar a situação', async () => {
        render(<MapaPlanta />);
        await waitFor(() => expect(getSituacaoPlanta).toHaveBeenCalledTimes(1));

        fireEvent.change(screen.getByLabelText('Cor do mapa'), { target: { value: 'ocupacao' } });

        expect(await screen.findByText('Ocupado nos dois turnos')).toBeInTheDocument();
        expect(screen.queryByLabelText('Dia do evento')).not.toBeInTheDocument();
        expect(getSituacaoPlanta).toHaveBeenCalledTimes(1);
    });

    // ------------------------------------------------------------------ //
    // Ruas                                                                //
    // ------------------------------------------------------------------ //

    it('desenha o nome das ruas batizadas e ignora as sem nome', async () => {
        render(<MapaPlanta />);

        expect(await screen.findByText('Rua das Agrárias')).toBeInTheDocument();
        // O corredor sem nome não vira rótulo solto no desenho.
        expect(screen.queryByText('Corredor horizontal')).not.toBeInTheDocument();
    });

    it('no modo de edição o admin batiza os corredores e só os nomes são salvos', async () => {
        salvarPlanta.mockResolvedValue({ data: painel(), meta: { message: 'Planta salva.' } });
        render(<MapaPlanta />);

        fireEvent.click(await screen.findByRole('button', { name: 'Editar planta' }));
        fireEvent.change(screen.getByLabelText('Nome do corredor h:8'), {
            target: { value: 'Corredor Central' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Salvar como nova versão' }));

        await waitFor(() => expect(salvarPlanta).toHaveBeenCalled());
        // Vai o nome, não a posição: o corredor é recalculado do desenho.
        expect(salvarPlanta.mock.calls[0][0].ruas).toEqual({
            'v:2.5': 'Rua das Agrárias',
            'h:8': 'Corredor Central',
        });
    });

    // ------------------------------------------------------------------ //
    // Lista e exportação                                                  //
    // ------------------------------------------------------------------ //

    it('consulta a lista pelo filtro escolhido', async () => {
        getListaSituacao.mockResolvedValue({
            criterio: 'credenciamento', criterio_label: 'Credenciamento', valor: 'nao',
            valor_label: 'ainda não passou', turno_label: 'Turno A (matutino)',
            total: 1,
            linhas: [{
                projeto_id: 5, estande: '007', titulo: 'Horta na escola',
                situacao_label: 'Aguardando credenciamento', avaliacoes: 0, avaliacoes_maximo: 3,
            }],
        });
        render(<MapaPlanta />);

        fireEvent.change(await screen.findByLabelText('Valor do filtro'), { target: { value: 'nao' } });
        fireEvent.click(screen.getByRole('button', { name: 'Ver lista' }));

        await waitFor(() => expect(getListaSituacao).toHaveBeenCalledWith({
            criterio: 'credenciamento', valor: 'nao', dia: '2026-10-02', turno: 'A',
        }));
        expect(await screen.findByText(/Horta na escola/)).toBeInTheDocument();
    });

    it('o critério de contagem troca o campo de valor para números', async () => {
        render(<MapaPlanta />);

        fireEvent.change(await screen.findByLabelText('Filtrar por'), {
            target: { value: 'avaliacoes_faltantes' },
        });

        // De 0 a 3: o teto de avaliações presenciais por projeto.
        const opcoes = [...screen.getByLabelText('Valor do filtro').options].map((o) => o.value);
        expect(opcoes).toEqual(['0', '1', '2', '3']);
    });

    it('o critério do termo de imagem oferece as situações e começa em "incompleto"', async () => {
        getListaSituacao.mockResolvedValue({
            criterio: 'termo_imagem', criterio_label: 'Termo de uso de imagem', valor: 'incompleto',
            valor_label: 'incompleto (parcial ou pendente)', turno_label: 'Turno A (matutino)', total: 1,
            linhas: [{
                projeto_id: 5, estande: '007', titulo: 'Horta na escola', situacao_label: 'Credenciado',
                avaliacoes: 0, avaliacoes_maximo: 3, termo_imagem: 'parcial', termo_imagem_label: 'Parcial',
                termo_imagem_cobertos: 1, termo_imagem_total: 2, termo_imagem_faltando: ['Zeca'],
            }],
        });
        render(<MapaPlanta />);

        fireEvent.change(await screen.findByLabelText('Filtrar por'), { target: { value: 'termo_imagem' } });

        const valor = screen.getByLabelText('Valor do filtro');
        expect(valor.value).toBe('incompleto');
        expect([...valor.options].map((o) => o.value)).toEqual(['incompleto', 'completo', 'parcial', 'nenhum']);

        fireEvent.click(screen.getByRole('button', { name: 'Ver lista' }));
        await waitFor(() => expect(getListaSituacao).toHaveBeenCalledWith(expect.objectContaining({
            criterio: 'termo_imagem', valor: 'incompleto',
        })));
        expect(await screen.findByText('Termo de imagem: Parcial · 1 de 2 · falta: Zeca')).toBeInTheDocument();
    });

    it('exporta o mesmo recorte nos três formatos', async () => {
        render(<MapaPlanta />);
        await screen.findByRole('button', { name: 'CSV' });

        fireEvent.click(screen.getByRole('button', { name: 'CSV' }));
        await waitFor(() => expect(baixarListaSituacao).toHaveBeenCalledWith('csv', {
            criterio: 'credenciamento', valor: 'sim', dia: '2026-10-02', turno: 'A',
        }));

        fireEvent.click(screen.getByRole('button', { name: 'PDF' }));
        await waitFor(() => expect(baixarListaSituacao).toHaveBeenLastCalledWith('pdf', expect.anything()));
    });

    it('oferece a tela cheia', async () => {
        render(<MapaPlanta />);

        expect(await screen.findByRole('button', { name: /Tela cheia/ })).toBeInTheDocument();
    });

    // --- Sprint 197: a rua da borda esquerda --------------------------------
    it('abre espaço à esquerda para a rua da borda e a oferece para nomear', async () => {
        getPlanta.mockResolvedValue(resposta({
            ruas: [{ chave: 'v:-0.5', orientacao: 'v', posicao: -0.5, de: 0, ate: 1, nome: 'Rua da Entrada', borda: true }],
        }));
        const { container } = render(<MapaPlanta />);
        await screen.findByText('Rua da Entrada');

        expect(container.querySelector('svg[role="img"]').getAttribute('viewBox')).toMatch(/^-10 0 /);

        fireEvent.click(screen.getByRole('button', { name: 'Editar planta' }));
        expect(screen.getByPlaceholderText('Rua da borda esquerda')).toHaveValue('Rua da Entrada');
    });

    // --- Sprints 198–200: tela cheia ----------------------------------------
    describe('em tela cheia', () => {
        beforeEach(() => {
            // O documento é o mesmo entre os testes: começa fora da tela cheia.
            Object.defineProperty(document, 'fullscreenElement', { configurable: true, value: null });
            HTMLElement.prototype.requestFullscreen = vi.fn(function entrar() {
                Object.defineProperty(document, 'fullscreenElement', { configurable: true, value: this });
                document.dispatchEvent(new Event('fullscreenchange'));
                return Promise.resolve();
            });
            document.exitFullscreen = vi.fn(() => {
                Object.defineProperty(document, 'fullscreenElement', { configurable: true, value: null });
                document.dispatchEvent(new Event('fullscreenchange'));
                return Promise.resolve();
            });
        });

        async function entrar() {
            const utils = render(<MapaPlanta />);
            fireEvent.click(await screen.findByRole('button', { name: /Tela cheia/ }));
            await screen.findByRole('button', { name: /Dashboard/ });
            return utils;
        }

        it('grifa o estande com a faixa da área e a sigla, e destaca uma área', async () => {
            const { container } = await entrar();

            const estande = await screen.findByRole('button', { name: 'Estande 042 · Ciências Agrárias' });
            expect(estande.textContent).toContain('AGR');
            // A faixa tem a cor fixa de Agrárias.
            expect(estande.querySelector('rect[fill="#008300"]')).not.toBeNull();
            // Só as áreas do turno vão para a legenda.
            const legenda = screen.getByRole('group', { name: 'Áreas do conhecimento' });
            expect(legenda.textContent).toContain('Ciências Agrárias');
            expect(legenda.textContent).not.toContain('Ciências Biológicas');

            fireEvent.click(within(legenda).getByRole('button', { name: /Ciências Agrárias/ }));
            expect(within(legenda).getByRole('button', { name: /Ciências Agrárias/ })).toHaveAttribute('aria-pressed', 'true');
            // O estande sem projeto (outra "área") fica apagado.
            expect(container.querySelector('[aria-label="Estande 001"]').getAttribute('opacity')).toBe('0.18');
        });

        it('se atualiza a cada 5 segundos e diz isso no canto', async () => {
            const intervalo = vi.spyOn(window, 'setInterval');
            await entrar();

            await waitFor(() => expect(intervalo).toHaveBeenCalledWith(expect.any(Function), 5000));
            expect(await screen.findByText(/Ao vivo · atualizado às .* · atualiza a cada 5 segundos/)).toBeInTheDocument();
            intervalo.mockRestore();
        });

        it('troca a visualização no canto inferior esquerdo', async () => {
            await entrar();
            const escopos = screen.getByRole('group', { name: 'Visualização do mapa' });

            expect(screen.getByText('Credenciado').parentElement).toHaveTextContent('(1)');

            fireEvent.click(within(escopos).getByRole('button', { name: 'Avaliação' }));
            expect(screen.getByText('Nenhuma').parentElement).toHaveTextContent('(1)');
            expect(screen.getByText('3 ou mais avaliação(ões)')).toBeInTheDocument();
            // Nenhuma avaliação: o estande fica branco.
            expect(screen.getByRole('button', { name: 'Estande 042 · Ciências Agrárias' }).querySelector('rect').getAttribute('fill')).toBe('#ffffff');
        });

        it('abre o dashboard ao lado do botão de sair', async () => {
            await entrar();

            fireEvent.click(screen.getByRole('button', { name: /Dashboard/ }));

            const painel = screen.getByRole('complementary', { name: 'Dashboard das avaliações' });
            expect(within(painel).getByText('0 de 3 avaliações previstas')).toBeInTheDocument();
            expect(within(painel).getByText('Avaliados pela comissão especial')).toBeInTheDocument();
            // Geral e por área: 0 de 1 projeto avaliado pela comissão.
            expect(within(within(painel).getByRole('region', { name: 'Comissão especial' })).getAllByText('0 de 1')).toHaveLength(2);

            fireEvent.click(within(painel).getByRole('button', { name: 'Fechar o dashboard' }));
            expect(screen.queryByRole('complementary', { name: 'Dashboard das avaliações' })).not.toBeInTheDocument();
        });
    });
});
