import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));

const getAcompanhamentoComite = vi.fn();
vi.mock('../lib/comite.js', () => ({
    getAcompanhamentoComite: (...a) => getAcompanhamentoComite(...a),
}));

import ComiteAcompanhamento from './ComiteAcompanhamento.jsx';

const projeto = (over = {}) => ({
    avaliacao_id: 'estande-1', origem: 'estande', projeto_id: 10, titulo: 'Abelhas nativas', codigo: 'FET.AGR-001', estande: 42,
    turno: 'A', turno_label: 'Turno A (matutino)', vaga_label: 'Comissão especial', situacao: 'em_avaliacao',
    iniciada_em: null, concluida_em: null, ...over,
});

const linha = (over = {}) => ({
    numero: 42, projeto_id: 10, titulo: 'Abelhas nativas', codigo: 'FET.AGR-001', area: 'Ciências Agrárias',
    area_sigla: 'AGR', categoria: 'FETECMS', pronto: true, concluidas: 0, em_avaliacao: 1, designadas: 0,
    situacao: 'em_avaliacao',
    avaliacoes: [
        { avaliacao_id: 'estande-1', origem: 'estande', avaliador: 'Ana Comissão', situacao: 'em_avaliacao', vaga_label: 'Comissão especial', iniciada_em: null, concluida_em: null },
        { avaliacao_id: 'online-9', origem: 'online', avaliador: 'Bruno Livre', situacao: 'designada', vaga_label: null, iniciada_em: null, concluida_em: null },
    ],
    ...over,
});

const DADOS = {
    turno: 'A',
    turno_label: 'Turno A (matutino)',
    turnos: [{ value: 'A', label: 'Turno A (matutino)' }, { value: 'B', label: 'Turno B (vespertino)' }],
    turno_em_andamento: 'A',
    vagas_comite: 1,
    avaliadores: [
        {
            id: 1, nome: 'Ana Comissão', area: 'Agrárias', situacao: 'avaliando', apenas_presencial: false,
            em_avaliacao: [projeto()], designadas: [], concluidas: [], expiradas: 0, ultima_concluida_em: null,
            designacoes_comite: [
                projeto({ avaliacao_id: 'online-7', origem: 'online', titulo: 'Solo vivo', codigo: 'FET.AGR-003', estande: 44, situacao: 'designada', vaga_label: null }),
                projeto({ avaliacao_id: 'online-8', origem: 'online', titulo: 'Cisterna', codigo: null, estande: null, turno_label: null, situacao: 'concluida', vaga_label: null }),
            ],
        },
        {
            id: 2, nome: 'Bruno Livre', area: 'Biológicas', situacao: 'livre', apenas_presencial: false,
            em_avaliacao: [], designadas: [], concluidas: [projeto({ avaliacao_id: 'estande-2', titulo: 'Horta', situacao: 'concluida' })],
            expiradas: 0, ultima_concluida_em: null, designacoes_comite: [],
        },
    ],
    mapa: {
        layout: [{ numero: 42, x: 0, y: 0 }, { numero: 43, x: 1, y: 0 }],
        estandes: {
            42: linha(),
            43: linha({ numero: 43, projeto_id: 11, titulo: 'Solo vivo', situacao: 'pendente', em_avaliacao: 0, avaliacoes: [] }),
        },
        fora_da_planta: [],
    },
};

describe('ComiteAcompanhamento', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        getAcompanhamentoComite.mockResolvedValue(DADOS);
    });

    it('lista cada avaliador da comissão com o projeto que está avaliando', async () => {
        render(<ComiteAcompanhamento />);

        expect(await screen.findByText('Ana Comissão')).toBeInTheDocument();
        expect(screen.getByText('Abelhas nativas')).toBeInTheDocument();
        expect(screen.getByText(/FET\.AGR-001 · Estande 042 · Turno A \(matutino\)/)).toBeInTheDocument();
        expect(screen.getByText('Avaliando agora', { selector: 'span' })).toBeInTheDocument();
        // O turno não foi escolhido: o servidor decide.
        expect(getAcompanhamentoComite).toHaveBeenCalledWith(undefined);
    });

    it('mostra o que cada um recebeu em Designações do comitê, com a situação', async () => {
        render(<ComiteAcompanhamento />);
        await screen.findByText('Ana Comissão');

        expect(screen.getByText('Designações do comitê · 1 de 2 avaliada(s)')).toBeInTheDocument();
        const solo = screen.getByText('Solo vivo').closest('li');
        expect(within(solo).getByText('Aguardando')).toBeInTheDocument();
        expect(within(solo).getByText(/FET\.AGR-003 · Estande 044/)).toBeInTheDocument();
        const cisterna = screen.getByText('Cisterna').closest('li');
        expect(within(cisterna).getByText('Avaliado')).toBeInTheDocument();
        expect(within(cisterna).getByText(/Sem estande · enviada/)).toBeInTheDocument();
    });

    it('"Ver detalhes" mostra avaliados, em avaliação e designados em três colunas', async () => {
        render(<ComiteAcompanhamento />);
        await screen.findByText('Ana Comissão');

        fireEvent.click(screen.getByRole('button', { name: 'Ver detalhes de Ana Comissão' }));
        const dialogo = screen.getByRole('dialog', { name: 'Detalhes de Ana Comissão' });

        const avaliados = within(dialogo).getByRole('region', { name: 'Projetos avaliados' });
        const emAvaliacao = within(dialogo).getByRole('region', { name: 'Projetos em avaliação' });
        const designados = within(dialogo).getByRole('region', { name: 'Projetos designados' });

        expect(within(avaliados).getByText('Cisterna')).toBeInTheDocument();
        expect(within(avaliados).getByText('Online')).toBeInTheDocument();
        expect(within(emAvaliacao).getByText('Abelhas nativas')).toBeInTheDocument();
        expect(within(emAvaliacao).getByText('No estande')).toBeInTheDocument();
        expect(within(designados).getByText('Solo vivo')).toBeInTheDocument();

        fireEvent.keyDown(document, { key: 'Escape' });
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('o avaliador sem nada ganha as três colunas vazias', async () => {
        render(<ComiteAcompanhamento />);
        await screen.findByText('Bruno Livre');

        fireEvent.click(screen.getByRole('button', { name: 'Ver detalhes de Bruno Livre' }));
        const dialogo = screen.getByRole('dialog', { name: 'Detalhes de Bruno Livre' });

        expect(within(dialogo).getByText('Horta')).toBeInTheDocument();
        expect(within(dialogo).getByText('Nenhum projeto em avaliação agora.')).toBeInTheDocument();
        expect(within(dialogo).getByText('Nenhum projeto designado aguardando.')).toBeInTheDocument();

        fireEvent.click(within(dialogo).getByRole('button', { name: 'Fechar os detalhes' }));
        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
    });

    it('filtra a lista pela busca e pela situação', async () => {
        render(<ComiteAcompanhamento />);
        await screen.findByText('Ana Comissão');

        fireEvent.change(screen.getByLabelText('Situação do avaliador'), { target: { value: 'livre' } });
        expect(screen.queryByText('Ana Comissão')).not.toBeInTheDocument();
        expect(screen.getByText('Bruno Livre')).toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Situação do avaliador'), { target: { value: '' } });
        fireEvent.change(screen.getByLabelText('Buscar por avaliador, projeto, código ou estande'), { target: { value: 'horta' } });
        expect(screen.queryByText('Ana Comissão')).not.toBeInTheDocument();
        expect(screen.getByText('Bruno Livre')).toBeInTheDocument();
    });

    it('o mapa pinta só as avaliações do comitê e abre o estande clicado', async () => {
        render(<ComiteAcompanhamento />);
        await screen.findByText('Ana Comissão');

        fireEvent.click(screen.getByRole('tab', { name: /Mapa/ }));

        const estande = screen.getByRole('button', { name: /Estande 042 · Abelhas nativas · Em avaliação agora/ });
        expect(screen.getByRole('button', { name: /Estande 043 · Solo vivo · Sem avaliação do comitê/ })).toBeInTheDocument();

        fireEvent.click(estande);
        const detalhe = screen.getByRole('region', { name: 'Estande 042' });
        expect(within(detalhe).getByText('Ana Comissão')).toBeInTheDocument();
        // A designação online do comitê aparece no estande, com a origem.
        expect(within(detalhe).getByText('Bruno Livre')).toBeInTheDocument();
        expect(within(detalhe).getByText(/Online · designada/)).toBeInTheDocument();
        expect(within(detalhe).getByText(/No estande · em avaliação/)).toBeInTheDocument();
    });

    it('o mapa tem dashboard e troca de turno no servidor', async () => {
        render(<ComiteAcompanhamento />);
        await screen.findByText('Ana Comissão');
        fireEvent.click(screen.getByRole('tab', { name: /Mapa/ }));

        const painel = screen.getByRole('complementary', { name: 'Dashboard do comitê' });
        expect(within(painel).getByText('Avaliações do comitê')).toBeInTheDocument();
        expect(within(painel).getByText('2 projeto(s) no Turno A (matutino) · meta de 1 por projeto')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Fechar o dashboard' }));
        expect(screen.queryByRole('complementary', { name: 'Dashboard do comitê' })).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('radio', { name: /Turno B/ }));
        await waitFor(() => expect(getAcompanhamentoComite).toHaveBeenLastCalledWith('B'));
    });

    it('sem ninguém na comissão, explica onde marcar', async () => {
        getAcompanhamentoComite.mockResolvedValue({ ...DADOS, avaliadores: [] });
        render(<ComiteAcompanhamento />);

        expect(await screen.findByText(/Ninguém está marcado como comissão especial/)).toBeInTheDocument();
    });

    it('falha na primeira carga vira aviso', async () => {
        getAcompanhamentoComite.mockRejectedValue(new Error('rede'));
        render(<ComiteAcompanhamento />);

        expect(await screen.findByText('Não foi possível carregar o acompanhamento.')).toBeInTheDocument();
    });
});
