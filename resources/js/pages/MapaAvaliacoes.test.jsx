import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
const setParams = vi.fn();
let busca = new URLSearchParams('turno=A');
vi.mock('react-router-dom', () => ({
    Link: ({ children, to }) => <a href={to}>{children}</a>,
    useSearchParams: () => [busca, setParams],
}));
const auth = { user: { id: 1, role: 'admin', conta_temporaria: false } };
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.message ?? '', fields: {} }),
    useAuth: () => auth,
}));

const getAvaliacoesMapa = vi.fn();
const baixarAvaliacoesMapa = vi.fn();
vi.mock('../lib/mapaEvento.js', async () => {
    const real = await vi.importActual('../lib/mapaEvento.js');
    return {
        filtrarAvaliacoes: real.filtrarAvaliacoes,
        getAvaliacoesMapa: (...a) => getAvaliacoesMapa(...a),
        ordenarAvaliacoes: real.ordenarAvaliacoes,
        baixarAvaliacoesMapa: (...a) => baixarAvaliacoesMapa(...a),
    };
});

const getAvaliacoesRankingPresencial = vi.fn();
const getDestinosAvaliacaoPresencial = vi.fn();
const transferirAvaliacaoPresencial = vi.fn();
vi.mock('../lib/presencial.js', () => ({
    getAvaliacoesRankingPresencial: (...a) => getAvaliacoesRankingPresencial(...a),
    excluirAvaliacaoPresencial: vi.fn(),
    getDestinosAvaliacaoPresencial: (...a) => getDestinosAvaliacaoPresencial(...a),
    transferirAvaliacaoPresencial: (...a) => transferirAvaliacaoPresencial(...a),
}));

import MapaAvaliacoes from './MapaAvaliacoes.jsx';

const categoria = (chave, label, cor, total) => ({ chave, label, cor, borda: '#000000', texto: '#0b0b0b', total });

const vaga = (enviadas, vagas, emAndamento = 0) => ({ enviadas, em_andamento: emAndamento, vagas, faltam: Math.max(0, vagas - enviadas) });

const linha = (over = {}) => ({
    numero: 1, estande: '001', projeto_id: 1, titulo: 'Bioplástico', codigo: 'FET.AGR-001',
    categoria: 'FETECMS', area: 'Ciências Agrárias', area_sigla: 'AGR',
    comum: vaga(0, 2), comite: vaga(0, 1), enviadas: 0, em_andamento: 0,
    situacao: 'comum2-comite1', situacao_label: 'Faltam 3 avaliações',
    credenciado: true, checado: true, pronto: true, saida: null, na_planta: true,
    ...over,
});

const painel = () => ({
    edicao: 'XVI FETECMS', dia: '2026-10-20', turno: 'A', turno_label: 'Turno A (matutino)', corte_label: '20/10/2026 10:00',
    vagas: { comuns: 2, comite: 1, total: 3 },
    categorias: [
        categoria('comum2-comite1', 'Faltam 3 avaliações', '#ffffff', 1),
        categoria('comum1-comite1', 'Faltam 2 avaliações (1 comitê)', '#eda100', 1),
        categoria('comum2-comite0', 'Faltam 2 avaliações (comum)', '#d55181', 0),
        categoria('comum0-comite1', 'Falta somente comitê', '#5b3fd1', 0),
        categoria('comum1-comite0', 'Falta somente comum', '#3987e5', 0),
        categoria('comum0-comite0', 'Avaliação fechada', '#0b6b2b', 1),
    ],
    marcas: { em_andamento: 1, nao_pronto: 1, saiu: 0 },
    planta: {
        estandes: [{ numero: 1, x: 0, y: 0 }, { numero: 2, x: 1, y: 0 }, { numero: 3, x: 2, y: 0 }, { numero: 4, x: 3, y: 0 }],
        marcacoes: [{ rotulo: 'Entrada', x: 0, y: 2, largura: 2, altura: 0.8 }],
    },
    linhas: [
        linha(),
        linha({
            numero: 2, estande: '002', projeto_id: 2, titulo: 'Horta vertical', codigo: 'FET.AGR-002',
            comum: vaga(1, 2, 1), situacao: 'comum1-comite1', situacao_label: 'Faltam 2 avaliações (1 comitê)',
            enviadas: 1, em_andamento: 1, checado: false, pronto: false,
        }),
        linha({
            numero: 3, estande: '003', projeto_id: 3, titulo: 'Drone agrícola', codigo: 'FET.AGR-003',
            comum: vaga(2, 2), comite: vaga(1, 1), enviadas: 3, situacao: 'comum0-comite0', situacao_label: 'Avaliação fechada',
        }),
    ],
    total: 3,
});

const META = {
    dias: [{ value: '2026-10-19', label: '19/10', hoje: false }, { value: '2026-10-20', label: '20/10 (hoje)', hoje: true }],
    turnos: [{ value: 'A', label: 'Turno A (matutino)' }, { value: 'B', label: 'Turno B (vespertino)' }],
};

function linhasDaTabela() {
    const tabela = screen.getByRole('region', { name: 'Lista de projetos' });
    return within(tabela).getAllByRole('row').slice(1).map((r) => r.textContent);
}

describe('MapaAvaliacoes', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        auth.user = { id: 1, role: 'admin', conta_temporaria: false };
        busca = new URLSearchParams('turno=A');
        getAvaliacoesMapa.mockResolvedValue({ data: painel(), meta: META });
        baixarAvaliacoesMapa.mockResolvedValue();
    });

    it('mostra cada projeto com código, estande, turno, vagas e situação', async () => {
        render(<MapaAvaliacoes />);

        expect(await screen.findByText('Horta vertical')).toBeInTheDocument();
        const linhas = linhasDaTabela();
        expect(linhas).toHaveLength(3);
        expect(linhas[1]).toContain('002');
        expect(linhas[1]).toContain('FET.AGR-002');
        expect(linhas[1]).toContain('1 de 2');
        expect(linhas[1]).toContain('1 em andamento');
        expect(linhas[1]).toContain('Aguardando checagem');
        expect(linhas[1]).toContain('Faltam 2 avaliações (1 comitê)');

        // Sem dia no endereço, abre no dia de hoje.
        await waitFor(() => expect(getAvaliacoesMapa).toHaveBeenLastCalledWith({ turno: 'A', dia: '2026-10-20' }));
        expect(screen.getByRole('combobox', { name: 'Dia' })).toHaveValue('2026-10-20');
    });

    it('pinta o estande com a cor da categoria e marca o que está em andamento', async () => {
        render(<MapaAvaliacoes />);

        const estande = await screen.findByRole('button', { name: /^Estande 002 · FET.AGR-002/ });
        expect(estande.querySelector('rect').getAttribute('fill')).toBe('#eda100');
        expect(estande.querySelector('rect').getAttribute('stroke-dasharray')).toBe('1.6 1');
        expect(estande.querySelector('circle')).not.toBeNull();
        expect(screen.getByRole('button', { name: /^Estande 001/ }).querySelector('circle')).toBeNull();
        // O estande sem projeto no turno não é clicável.
        expect(screen.getByLabelText('Estande 004 · sem projeto neste turno')).not.toHaveAttribute('role');
    });

    it('filtra o mapa e a lista juntos pelas categorias e pelo em andamento', async () => {
        const { container } = render(<MapaAvaliacoes />);
        await screen.findByText('Horta vertical');

        const filtros = screen.getByLabelText('Filtros de situação');
        fireEvent.click(within(filtros).getByRole('button', { name: /Avaliação fechada/ }));

        expect(within(filtros).getByRole('button', { name: /Avaliação fechada/ })).toHaveAttribute('aria-pressed', 'true');
        expect(linhasDaTabela()).toHaveLength(1);
        expect(linhasDaTabela()[0]).toContain('Drone agrícola');
        expect(screen.getByText('(1 de 3)')).toBeInTheDocument();
        // Fora do filtro, o estande fica apagado e deixa de ser botão.
        expect(container.querySelector('[aria-label^="Estande 001"]').getAttribute('opacity')).toBe('0.18');
        expect(container.querySelector('[aria-label^="Estande 003"]').getAttribute('opacity')).toBe('1');

        // Duas categorias somam; o "em andamento" recorta por cima.
        fireEvent.click(within(filtros).getByRole('button', { name: /Faltam 2 avaliações \(1 comitê\)/ }));
        expect(linhasDaTabela()).toHaveLength(2);
        fireEvent.click(within(filtros).getByRole('button', { name: /Com avaliação em andamento/ }));
        expect(linhasDaTabela()).toHaveLength(1);
        expect(linhasDaTabela()[0]).toContain('Horta vertical');

        fireEvent.click(screen.getByRole('button', { name: 'Limpar filtros' }));
        expect(linhasDaTabela()).toHaveLength(3);
    });

    it('exporta a lista e o mapa em PDF, cada um com o recorte dos filtros', async () => {
        render(<MapaAvaliacoes />);
        await screen.findByText('Horta vertical');
        await waitFor(() => expect(screen.getByRole('combobox', { name: 'Dia' })).toHaveValue('2026-10-20'));

        fireEvent.click(within(screen.getByLabelText('Filtros de situação')).getByRole('button', { name: /Faltam 3 avaliações/ }));
        fireEvent.click(screen.getByRole('button', { name: /Exportar lista \(PDF\)/ }));
        await waitFor(() => expect(baixarAvaliacoesMapa).toHaveBeenCalledWith('lista', {
            dia: '2026-10-20', turno: 'A', categorias: ['comum2-comite1'], emAndamento: false, ordem: 'estande', direcao: 'asc',
        }));

        fireEvent.click(screen.getByRole('button', { name: /Exportar mapa \(PDF\)/ }));
        await waitFor(() => expect(baixarAvaliacoesMapa).toHaveBeenCalledWith('mapa', expect.objectContaining({ categorias: ['comum2-comite1'] })));
    });

    it('ordena por estande ou por situação, e o PDF sai na mesma ordem', async () => {
        render(<MapaAvaliacoes />);
        await screen.findByText('Horta vertical');
        const estandes = () => linhasDaTabela().map((t) => t.slice(0, 3));

        expect(estandes()).toEqual(['001', '002', '003']);

        fireEvent.click(screen.getByRole('button', { name: 'Ordenar por Estande' }));
        expect(estandes()).toEqual(['003', '002', '001']);
        expect(screen.getByRole('button', { name: 'Ordenar por Estande' }).closest('th')).toHaveAttribute('aria-sort', 'descending');

        // Situação: da que mais falta à fechada.
        fireEvent.click(screen.getByRole('button', { name: 'Ordenar por Situação' }));
        expect(estandes()).toEqual(['001', '002', '003']);
        fireEvent.click(screen.getByRole('button', { name: 'Ordenar por Situação' }));
        expect(estandes()).toEqual(['003', '002', '001']);

        fireEvent.click(screen.getByRole('button', { name: /Exportar lista \(PDF\)/ }));
        await waitFor(() => expect(baixarAvaliacoesMapa).toHaveBeenCalledWith('lista', expect.objectContaining({ ordem: 'situacao', direcao: 'desc' })));
    });

    it('Ver avaliações abre o diálogo do projeto e a transferência recarrega a tela', async () => {
        getAvaliacoesRankingPresencial.mockResolvedValue({
            projeto: { id: 2, titulo: 'Horta vertical', codigo: 'FET.AGR-002', area: 'Ciências Agrárias', categoria: 'FETECMS', estande: 2, turno_label: 'Turno A (matutino)' },
            media: 7, nota_maxima: 10, secoes: [],
            avaliacoes: [{
                avaliacao_id: 50, avaliador: 'Ana Souza', status: 'concluida', status_label: 'Concluída', vaga: 'comum', vaga_label: 'Vaga comum',
                peso: 1, nota: 7, ocorrencia_label: null, origem_label: 'Escolhido no estande', concluida_em_label: '20/10/2026 10:00', secoes: null, comentario: null,
            }],
        });
        getDestinosAvaliacaoPresencial.mockResolvedValue({
            destinos: [{
                projeto_id: 3, titulo: 'Drone agrícola', codigo: 'FET.AGR-003', area: 'Ciências Agrárias', categoria: 'FETECMS', estande: '003', turno: 'A',
                turno_label: 'Turno A (matutino)', ocupadas: { comum: 2, comite: 1 }, vagas: { comuns: 2, comite: 1 },
                vaga: 'comum', vaga_label: 'Vaga comum', acima_do_teto: true, bloqueio: null,
            }],
            total: 1, limite: 30,
        });
        transferirAvaliacaoPresencial.mockResolvedValue({
            data: { projeto: { id: 2, titulo: 'Horta vertical' }, media: null, nota_maxima: 10, secoes: [], avaliacoes: [] },
            meta: { message: 'Avaliação de Ana Souza transferida.', aviso: 'O projeto de destino já não tinha vaga.' },
        });
        render(<MapaAvaliacoes />);
        await screen.findByText('Horta vertical');

        fireEvent.click(screen.getByRole('button', { name: 'Ver avaliações do estande 002' }));
        await waitFor(() => expect(getAvaliacoesRankingPresencial).toHaveBeenCalledWith(2));
        const dialogo = await screen.findByRole('dialog', { name: 'Avaliações presenciais' });

        fireEvent.click(within(dialogo).getByRole('button', { name: 'Transferir a avaliação de Ana Souza para outro projeto' }));
        fireEvent.click(await within(dialogo).findByRole('radio', { name: /Estande 003/ }));
        fireEvent.change(within(dialogo).getByLabelText('Por que transferir esta avaliação?'), { target: { value: 'Estande trocado.' } });
        fireEvent.click(within(dialogo).getByRole('button', { name: 'Transferir avaliação' }));

        await waitFor(() => expect(transferirAvaliacaoPresencial).toHaveBeenCalledWith(50, 3, 'Estande trocado.', false));
        expect(await screen.findAllByText('Avaliação de Ana Souza transferida. O projeto de destino já não tinha vaga.')).toHaveLength(2);
        // A tela recarrega: a situação dos dois projetos muda no mapa e na lista.
        await waitFor(() => expect(getAvaliacoesMapa.mock.calls.length).toBeGreaterThanOrEqual(3));
    });

    it('a conta temporária não vê Ver avaliações', async () => {
        auth.user = { id: 2, role: 'admin', conta_temporaria: true };
        render(<MapaAvaliacoes />);
        await screen.findByText('Horta vertical');

        expect(screen.queryByRole('button', { name: /Ver avaliações/ })).not.toBeInTheDocument();
    });

    it('troca o turno e guarda o recorte no endereço', async () => {
        busca = new URLSearchParams('turno=B&dia=2026-10-19');
        render(<MapaAvaliacoes />);
        await screen.findByText('Horta vertical');

        expect(getAvaliacoesMapa).toHaveBeenCalledWith({ turno: 'B', dia: '2026-10-19' });
        expect(screen.getByText(/Dia encerrado/)).toBeInTheDocument();

        fireEvent.click(screen.getByRole('radio', { name: 'Turno A (matutino)' }));
        await waitFor(() => expect(getAvaliacoesMapa).toHaveBeenLastCalledWith({ turno: 'A', dia: '2026-10-19' }));
        expect(setParams).toHaveBeenLastCalledWith({ turno: 'A', dia: '2026-10-19' }, { replace: true });
    });

    it('diz quando o carregamento falha', async () => {
        getAvaliacoesMapa.mockRejectedValue(new Error(''));
        render(<MapaAvaliacoes />);

        expect(await screen.findByText('Não foi possível carregar as avaliações.')).toBeInTheDocument();
    });
});
