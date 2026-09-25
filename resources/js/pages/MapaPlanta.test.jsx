import { render, screen, fireEvent, waitFor } from '@testing-library/react';
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
vi.mock('../lib/mapaEvento.js', () => ({
    getPlanta: (...a) => getPlanta(...a),
    salvarPlanta: (...a) => salvarPlanta(...a),
    restaurarPlanta: (...a) => restaurarPlanta(...a),
    getSituacaoPlanta: (...a) => getSituacaoPlanta(...a),
    getListaSituacao: (...a) => getListaSituacao(...a),
    baixarListaSituacao: (...a) => baixarListaSituacao(...a),
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
    ],
    max_avaliacoes: 3,
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
            cor: '#9fd8ae', credenciado: true, credenciado_em: '02/10/2026 09:10',
            checado: true, checado_em: '02/10/2026 10:30',
            avaliacoes: 0, avaliacoes_faltantes: 3, avaliacoes_maximo: 3,
        },
    },
    resumo: { livre: 0, aguardando: 1, credenciado: 0, checado: 1, avaliado: 0 },
    total: 2,
};

const resposta = (over = {}) => ({ data: painel(over), meta: { filtros: FILTROS } });

describe('MapaPlanta', () => {
    beforeEach(() => {
        getPlanta.mockReset().mockResolvedValue(resposta());
        salvarPlanta.mockReset();
        restaurarPlanta.mockReset();
        getSituacaoPlanta.mockReset().mockResolvedValue(SITUACAO);
        getListaSituacao.mockReset();
        baixarListaSituacao.mockReset().mockResolvedValue(undefined);
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
});
