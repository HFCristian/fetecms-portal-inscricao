import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));

const getAcompanhamentoComite = vi.fn();
const retirarDesignacoesComite = vi.fn();
vi.mock('../lib/comite.js', () => ({
    getAcompanhamentoComite: (...a) => getAcompanhamentoComite(...a),
    retirarDesignacoesComite: (...a) => retirarDesignacoesComite(...a),
}));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.message ?? 'Erro.', fields: e?.fields ?? {} }),
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
            em_avaliacao: [projeto()],
            designadas: [projeto({ avaliacao_id: 'estande-3', projeto_id: 13, titulo: 'Robô seguidor', codigo: 'FET.ENG-002', estande: 45, situacao: 'designada' })],
            concluidas: [], expiradas: 0, ultima_concluida_em: null,
            designacoes_comite: [
                projeto({ avaliacao_id: 'online-7', origem: 'online', projeto_id: 11, titulo: 'Solo vivo', codigo: 'FET.AGR-003', estande: 44, situacao: 'designada', vaga_label: null }),
                projeto({ avaliacao_id: 'online-8', origem: 'online', projeto_id: 12, titulo: 'Cisterna', codigo: null, estande: null, turno_label: null, situacao: 'concluida', vaga_label: null }),
            ],
        },
        {
            id: 2, nome: 'Bruno Livre', area: 'Biológicas', situacao: 'livre', apenas_presencial: false,
            em_avaliacao: [], designadas: [], concluidas: [projeto({ avaliacao_id: 'estande-2', projeto_id: 14, titulo: 'Horta', situacao: 'concluida' })],
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

    it('o cartão mostra só o primeiro projeto de cada categoria, com a contagem', async () => {
        render(<ComiteAcompanhamento />);

        const cartao = (await screen.findByText('Ana Comissão')).closest('li');
        expect(within(cartao).getByText('Avaliando agora', { selector: 'span' })).toBeInTheDocument();

        // Avaliados: 1 (online); em avaliação: 1 (estande); designados: 2, só o primeiro aparece.
        expect(within(cartao).getByText('Cisterna')).toBeInTheDocument();
        expect(within(cartao).getByText('Abelhas nativas')).toBeInTheDocument();
        expect(within(cartao).getByText(/No estande · FET\.AGR-001 · Estande 042 · Turno A \(matutino\)/)).toBeInTheDocument();
        expect(within(cartao).getByText('Robô seguidor')).toBeInTheDocument();
        expect(within(cartao).queryByText('Solo vivo')).not.toBeInTheDocument();
        expect(within(cartao).getByText(/· \+1/)).toBeInTheDocument();
        expect(within(cartao).getAllByText('(1)')).toHaveLength(2);
        expect(within(cartao).getByText('(2)')).toBeInTheDocument();

        // Quem não tem nada na categoria ganha um traço.
        const bruno = screen.getByText('Bruno Livre').closest('li');
        expect(within(bruno).getAllByText('—')).toHaveLength(2);

        // O turno não foi escolhido: o servidor decide.
        expect(getAcompanhamentoComite).toHaveBeenCalledWith(undefined);
    });

    it('no "Ver detalhes", remove a designação dos projetos marcados sem pedir justificativa', async () => {
        retirarDesignacoesComite.mockResolvedValue({
            data: { retiradas: 1 }, meta: { message: 'Removido de Ana Comissão: 1 designação(ões).' },
        });
        render(<ComiteAcompanhamento />);
        await screen.findByText('Ana Comissão');

        fireEvent.click(screen.getByRole('button', { name: 'Ver detalhes de Ana Comissão' }));
        const dialogo = screen.getByRole('dialog', { name: 'Detalhes de Ana Comissão' });
        const designados = within(dialogo).getByRole('region', { name: 'Projetos designados' });

        expect(within(dialogo).getByRole('button', { name: /Remover selecionados/ })).toBeDisabled();
        fireEvent.click(within(designados).getByRole('checkbox', { name: 'Marcar Solo vivo' }));
        fireEvent.click(within(dialogo).getByRole('button', { name: 'Remover selecionados (1)' }));

        // Confirma antes de tirar, dizendo o efeito; designação não pede motivo.
        const confirmacao = within(dialogo).getByRole('group', { name: 'Confirmar remoção' });
        expect(confirmacao).toHaveTextContent('Remover 1 projeto(s) de Ana Comissão?');
        expect(confirmacao).toHaveTextContent('ninguém é designado no lugar');
        expect(within(confirmacao).queryByLabelText(/Por que remover/)).not.toBeInTheDocument();
        fireEvent.click(within(confirmacao).getByRole('button', { name: 'Remover' }));

        await waitFor(() => expect(retirarDesignacoesComite).toHaveBeenCalledWith(1, ['online-7'], null));
        expect(await within(dialogo).findByText('Removido de Ana Comissão: 1 designação(ões).')).toBeInTheDocument();
        // Recarrega o retrato depois de remover.
        expect(getAcompanhamentoComite).toHaveBeenCalledTimes(2);
    });

    it('remove avaliação em andamento e já realizada com justificativa', async () => {
        retirarDesignacoesComite.mockResolvedValue({ data: { retiradas: 2 }, meta: { message: 'Removido.' } });
        render(<ComiteAcompanhamento />);
        await screen.findByText('Ana Comissão');

        fireEvent.click(screen.getByRole('button', { name: 'Ver detalhes de Ana Comissão' }));
        const dialogo = screen.getByRole('dialog', { name: 'Detalhes de Ana Comissão' });
        fireEvent.click(within(within(dialogo).getByRole('region', { name: 'Projetos avaliados' })).getByRole('checkbox', { name: 'Marcar Cisterna' }));
        fireEvent.click(within(within(dialogo).getByRole('region', { name: 'Projetos em avaliação' })).getByRole('checkbox', { name: 'Marcar Abelhas nativas' }));
        fireEvent.click(within(dialogo).getByRole('button', { name: 'Remover selecionados (2)' }));

        const confirmacao = within(dialogo).getByRole('group', { name: 'Confirmar remoção' });
        expect(confirmacao).toHaveTextContent('a nota é desconsiderada');
        expect(confirmacao).toHaveTextContent('o rascunho é descartado');
        const remover = within(confirmacao).getByRole('button', { name: 'Remover' });
        expect(remover).toBeDisabled();
        fireEvent.change(within(confirmacao).getByLabelText('Por que remover estas avaliações?'), { target: { value: ' Avaliou o estande trocado. ' } });
        fireEvent.click(remover);

        await waitFor(() => expect(retirarDesignacoesComite).toHaveBeenCalledWith(1, ['online-8', 'estande-1'], 'Avaliou o estande trocado.'));
    });

    it('"Marcar todos" pega a coluna inteira; cancelar a confirmação não remove nada', async () => {
        render(<ComiteAcompanhamento />);
        await screen.findByText('Ana Comissão');

        fireEvent.click(screen.getByRole('button', { name: 'Ver detalhes de Ana Comissão' }));
        const dialogo = screen.getByRole('dialog', { name: 'Detalhes de Ana Comissão' });
        const designados = within(dialogo).getByRole('region', { name: 'Projetos designados' });

        fireEvent.click(within(designados).getByRole('button', { name: 'Marcar todos' }));
        fireEvent.click(within(dialogo).getByRole('button', { name: 'Remover selecionados (2)' }));
        fireEvent.click(within(dialogo).getByRole('button', { name: 'Cancelar' }));

        expect(retirarDesignacoesComite).not.toHaveBeenCalled();
        expect(within(designados).getByRole('button', { name: 'Desmarcar todos' })).toBeInTheDocument();
    });

    it('o erro da remoção aparece no diálogo', async () => {
        retirarDesignacoesComite.mockRejectedValue({ fields: { designacoes: ['Nada a remover.'] } });
        render(<ComiteAcompanhamento />);
        await screen.findByText('Ana Comissão');

        fireEvent.click(screen.getByRole('button', { name: 'Ver detalhes de Ana Comissão' }));
        const dialogo = screen.getByRole('dialog', { name: 'Detalhes de Ana Comissão' });
        fireEvent.click(within(dialogo).getByRole('checkbox', { name: 'Marcar Robô seguidor' }));
        fireEvent.click(within(dialogo).getByRole('button', { name: 'Remover selecionados (1)' }));
        fireEvent.click(within(dialogo).getByRole('button', { name: 'Remover' }));

        expect(await within(dialogo).findByText('Nada a remover.')).toBeInTheDocument();
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
