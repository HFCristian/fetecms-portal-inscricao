import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
const navigate = vi.fn();
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a>, useNavigate: () => navigate }));
vi.mock('../components/ListaPresencialDialog.jsx', () => ({
    default: ({ open, onClose }) => (open ? <button type="button" onClick={() => onClose(77)}>Simular rascunho</button> : null),
}));
const auth = { user: { id: 1, role: 'admin', conta_temporaria: false } };
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.message ?? 'Erro.', fields: e?.fields ?? {} }),
    useAuth: () => auth,
}));

const RANKING = {
    lista: { id: 1, nome: 'Final', demo: false },
    vagas: { comuns: 2, comite: 1, peso_comite: 2 },
    modo_teste: false,
    is_demo: false,
    opcoes: {
        areas: [{ id: 3, nome: 'Ciências Agrárias' }],
        categorias: [{ value: 'fetecms', label: 'FETECMS' }],
    },
    projetos: [
        {
            posicao: 1, projeto_id: 10, codigo: 'FET.AGR-001', titulo: 'Abelhas', area: 'Ciências Agrárias', categoria: 'FETECMS',
            estande: 12, turno_label: 'Turno A (matutino)', media: 8, avaliacoes: 3, comuns: 2, comite: 1, parcial: false,
            notas: [{ nota: 8.5, vaga: 'comum', peso: 1 }, { nota: 5.5, vaga: 'comum', peso: 1 }, { nota: 9, vaga: 'comite', peso: 2 }],
        },
        {
            posicao: 2, projeto_id: 11, codigo: null, titulo: 'Compostagem', area: 'Ciências Agrárias', categoria: 'FETECMS',
            estande: null, turno_label: null, media: 7.25, avaliacoes: 1, comuns: 1, comite: 0, parcial: true,
            notas: [{ nota: 7.25, vaga: 'comum', peso: 1 }],
        },
    ],
};

const AVALIACOES = {
    projeto: { id: 10, titulo: 'Abelhas', codigo: 'FET.AGR-001', area: 'Ciências Agrárias', categoria: 'FETECMS', estande: 12, turno_label: 'Turno A (matutino)' },
    media: 8,
    nota_maxima: 10,
    secoes: [
        { chave: 'apresentacao', titulo: 'Apresentação', maximo: 1.137 },
        { chave: 'metodologia', titulo: 'Metodologia', maximo: 1.2 },
    ],
    avaliacoes: [
        {
            avaliacao_id: 21, avaliador: 'Ana Souza', status: 'concluida', status_label: 'Concluída', vaga: 'comum', vaga_label: 'Vaga comum', peso: 1,
            nota: 8.5, ocorrencia_label: '20/10 · Turno A (matutino)', origem_label: 'Escolhido no estande',
            concluida_em_label: '20/10/2026 10:15', secoes: { apresentacao: 1.1, metodologia: 0.96 }, comentario: 'Boa equipe.',
        },
        {
            avaliacao_id: 22, avaliador: 'Bruno Lima', status: 'concluida', status_label: 'Concluída', vaga: 'comite', vaga_label: 'Comissão especial', peso: 2,
            nota: 9, ocorrencia_label: '20/10 · Turno A (matutino)', origem_label: 'Designado',
            concluida_em_label: '20/10/2026 11:00', secoes: { apresentacao: 1.137, metodologia: 1.2 }, comentario: null,
        },
        {
            avaliacao_id: 23, avaliador: 'Carla Dias', status: 'em_andamento', status_label: 'Em andamento', vaga: 'comum', vaga_label: 'Vaga comum', peso: 1,
            nota: null, ocorrencia_label: '20/10 · Turno A (matutino)', origem_label: 'Escolhido no estande',
            iniciada_em_label: '20/10/2026 11:30', secoes: null, comentario: null,
        },
    ],
};

const getRankingPresencial = vi.fn(() => Promise.resolve(RANKING));
const getAvaliacoesRankingPresencial = vi.fn(() => Promise.resolve(AVALIACOES));
const excluirAvaliacaoPresencial = vi.fn();
const getDestinosAvaliacaoPresencial = vi.fn();
const transferirAvaliacaoPresencial = vi.fn();
const exportarRankingPresencial = vi.fn(() => Promise.resolve());
vi.mock('../lib/presencial.js', () => ({
    exportarRankingPresencial: (...a) => exportarRankingPresencial(...a),
    getRankingPresencial: (...a) => getRankingPresencial(...a),
    getAvaliacoesRankingPresencial: (...a) => getAvaliacoesRankingPresencial(...a),
    excluirAvaliacaoPresencial: (...a) => excluirAvaliacaoPresencial(...a),
    getDestinosAvaliacaoPresencial: (...a) => getDestinosAvaliacaoPresencial(...a),
    transferirAvaliacaoPresencial: (...a) => transferirAvaliacaoPresencial(...a),
}));

const destino = (over = {}) => ({
    projeto_id: 30, titulo: 'Horta vertical', codigo: 'FET.AGR-002', area: 'Ciências Agrárias', categoria: 'FETECMS',
    estande: '013', turno: 'A', turno_label: 'Turno A (matutino)', ocupadas: { comum: 1, comite: 0 }, vagas: { comuns: 2, comite: 1 },
    vaga: 'comum', vaga_label: 'Vaga comum', acima_do_teto: false, bloqueio: null, ...over,
});

import PresencialRanking from './PresencialRanking.jsx';

describe('PresencialRanking', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        auth.user = { id: 1, role: 'admin', conta_temporaria: false };
    });

    it('mostra a média ponderada, as notas com a da comissão em destaque e o parcial', async () => {
        render(<PresencialRanking />);

        expect(await screen.findByText('Abelhas')).toBeInTheDocument();
        expect(screen.getByText('FET.AGR-001')).toBeInTheDocument();
        expect(screen.getByText('8,000')).toBeInTheDocument();
        expect(screen.getByText('9,000 (comissão ×2)')).toBeInTheDocument();
        expect(screen.getByText(/Estande 012 · Turno A/)).toBeInTheDocument();
        expect(screen.getByText(/a nota da comissão pesa/)).toHaveTextContent('pesa 2');
        expect(screen.getByText(/1 projeto ainda não teve todas as/)).toBeInTheDocument();
        expect(screen.getByText('Compostagem').closest('li')).toHaveTextContent('parcial');
        expect(screen.getByText('Abelhas').closest('li')).not.toHaveTextContent('parcial');
    });

    it('filtra por área e categoria no servidor', async () => {
        render(<PresencialRanking />);
        await screen.findByText('Abelhas');

        fireEvent.change(screen.getByLabelText('Área do conhecimento'), { target: { value: '3' } });
        await waitFor(() => expect(getRankingPresencial).toHaveBeenLastCalledWith({ area_id: '3', categoria: '' }, false));

        fireEvent.change(screen.getByLabelText('Categoria'), { target: { value: 'fetecms' } });
        await waitFor(() => expect(getRankingPresencial).toHaveBeenLastCalledWith({ area_id: '3', categoria: 'fetecms' }, false));
    });

    it('Ver avaliações abre quem avaliou, a vaga e os pontos de cada seção', async () => {
        render(<PresencialRanking />);

        fireEvent.click(await screen.findByRole('button', { name: 'Ver avaliações de Abelhas' }));
        await waitFor(() => expect(getAvaliacoesRankingPresencial).toHaveBeenCalledWith(10, false));

        const dialogo = await screen.findByRole('dialog', { name: 'Avaliações presenciais' });
        const tabela = within(dialogo).getByRole('table');
        // Na tabela, só quem enviou: a em andamento não tem nota para comparar.
        expect(within(tabela).getAllByRole('columnheader')).toHaveLength(3);
        expect(within(tabela).getByRole('columnheader', { name: /Bruno Lima/ })).toHaveTextContent('Comissão especial · peso 2');
        expect(within(tabela).getByRole('row', { name: /Metodologia/ })).toHaveTextContent('0,960');
        expect(within(dialogo).getByText('Boa equipe.')).toBeInTheDocument();
        expect(within(dialogo).getByText('Carla Dias').closest('li')).toHaveTextContent('iniciada em 20/10/2026 11:30');
        expect(within(dialogo).getByText(/média ponderada 8,000/)).toBeInTheDocument();
    });

    it('exclui uma avaliação com justificativa e recarrega o ranking', async () => {
        excluirAvaliacaoPresencial.mockResolvedValue({
            data: { ...AVALIACOES, avaliacoes: AVALIACOES.avaliacoes.filter((a) => a.avaliacao_id !== 21) },
            meta: { message: 'Avaliação excluída. A vaga voltou a abrir no estande.' },
        });
        render(<PresencialRanking />);

        fireEvent.click(await screen.findByRole('button', { name: 'Ver avaliações de Abelhas' }));
        const dialogo = await screen.findByRole('dialog', { name: 'Avaliações presenciais' });
        fireEvent.click(within(dialogo).getByRole('button', { name: 'Excluir a avaliação de Ana Souza' }));

        const confirmar = within(dialogo).getByRole('button', { name: 'Excluir avaliação' });
        // Sem justificativa não exclui.
        expect(confirmar).toBeDisabled();
        fireEvent.change(within(dialogo).getByLabelText('Por que excluir esta avaliação?'), {
            target: { value: '  Avaliou o estande errado.  ' },
        });
        fireEvent.click(confirmar);

        await waitFor(() => expect(excluirAvaliacaoPresencial).toHaveBeenCalledWith(21, 'Avaliou o estande errado.', false));
        await waitFor(() => expect(within(dialogo).queryByText('Ana Souza')).not.toBeInTheDocument());
        expect(screen.getByText(/A vaga voltou a abrir no estande/)).toBeInTheDocument();
        expect(getRankingPresencial).toHaveBeenCalledTimes(2);
    });

    it('o erro da exclusão fica no cartão', async () => {
        excluirAvaliacaoPresencial.mockRejectedValue({ message: 'Esta avaliação ainda não foi aberta.' });
        render(<PresencialRanking />);

        fireEvent.click(await screen.findByRole('button', { name: 'Ver avaliações de Abelhas' }));
        const dialogo = await screen.findByRole('dialog', { name: 'Avaliações presenciais' });
        fireEvent.click(within(dialogo).getByRole('button', { name: 'Excluir a avaliação de Carla Dias' }));
        fireEvent.change(within(dialogo).getByLabelText('Por que excluir esta avaliação?'), { target: { value: 'Desistiu do estande.' } });
        fireEvent.click(within(dialogo).getByRole('button', { name: 'Excluir avaliação' }));

        expect(await within(dialogo).findByText('Esta avaliação ainda não foi aberta.')).toBeInTheDocument();
        expect(within(dialogo).getByText('Carla Dias')).toBeInTheDocument();
    });

    it('transfere a avaliação para o projeto certo, buscando por estande', async () => {
        getDestinosAvaliacaoPresencial.mockImplementation((id, q) => Promise.resolve({
            destinos: q === '13'
                ? [destino()]
                : [
                    destino(),
                    destino({ projeto_id: 31, titulo: 'Drone', estande: '014', acima_do_teto: true, vaga_label: 'Vaga comum' }),
                    destino({ projeto_id: 32, titulo: 'Compostagem', estande: '015', bloqueio: 'já enviou uma avaliação deste projeto' }),
                ],
            total: q === '13' ? 1 : 40,
            limite: 30,
        }));
        transferirAvaliacaoPresencial.mockResolvedValue({
            data: { ...AVALIACOES, avaliacoes: AVALIACOES.avaliacoes.filter((a) => a.avaliacao_id !== 21) },
            meta: { message: 'Avaliação de Ana Souza transferida para estande 013 · Turno A · FET.AGR-002 · Horta vertical (Vaga comum).', aviso: null },
        });
        render(<PresencialRanking />);

        fireEvent.click(await screen.findByRole('button', { name: 'Ver avaliações de Abelhas' }));
        const dialogo = await screen.findByRole('dialog', { name: 'Avaliações presenciais' });
        fireEvent.click(within(dialogo).getByRole('button', { name: 'Transferir a avaliação de Ana Souza para outro projeto' }));

        const painel = within(dialogo).getByRole('group', { name: 'Transferir a avaliação de Ana Souza' });
        await waitFor(() => expect(getDestinosAvaliacaoPresencial).toHaveBeenCalledWith(21, '', false));
        // Cada destino diz a vaga; o que passa do teto avisa e o bloqueado não se escolhe.
        expect(await within(painel).findByRole('radio', { name: /Estande 013/ })).toHaveTextContent('comum 1/2 · comitê 0/1 · entra como Vaga comum');
        expect(within(painel).getByRole('radio', { name: /Estande 014/ })).toHaveTextContent('sem vaga: entra acima do teto');
        expect(within(painel).getByRole('radio', { name: /Estande 015/ })).toBeDisabled();
        expect(within(painel).getByRole('radio', { name: /Estande 015/ })).toHaveTextContent('Ana Souza já enviou uma avaliação deste projeto.');
        expect(within(painel).getByText('Mostrando 3 de 40. Refine a busca para achar os outros.')).toBeInTheDocument();

        fireEvent.change(within(painel).getByPlaceholderText('Estande, código ou título'), { target: { value: '13' } });
        await waitFor(() => expect(getDestinosAvaliacaoPresencial).toHaveBeenLastCalledWith(21, '13', false));
        await waitFor(() => expect(within(painel).getAllByRole('radio')).toHaveLength(1));

        const confirmar = within(painel).getByRole('button', { name: 'Transferir avaliação' });
        expect(confirmar).toBeDisabled();
        fireEvent.click(within(painel).getByRole('radio', { name: /Estande 013/ }));
        fireEvent.change(within(painel).getByLabelText('Por que transferir esta avaliação?'), { target: { value: 'Avaliou o estande do lado.' } });
        fireEvent.click(confirmar);

        await waitFor(() => expect(transferirAvaliacaoPresencial).toHaveBeenCalledWith(21, 30, 'Avaliou o estande do lado.', false));
        await waitFor(() => expect(within(dialogo).queryByText('Ana Souza')).not.toBeInTheDocument());
        expect(within(dialogo).getByText(/transferida para estande 013/)).toBeInTheDocument();
        expect(getRankingPresencial).toHaveBeenCalledTimes(2);
    });

    it('o erro da transferência fica no painel', async () => {
        getDestinosAvaliacaoPresencial.mockResolvedValue({ destinos: [destino()], total: 1, limite: 30 });
        transferirAvaliacaoPresencial.mockRejectedValue({ fields: { projeto_id: 'O projeto de destino ainda não tem estande no Mapa do Evento.' } });
        render(<PresencialRanking />);

        fireEvent.click(await screen.findByRole('button', { name: 'Ver avaliações de Abelhas' }));
        const dialogo = await screen.findByRole('dialog', { name: 'Avaliações presenciais' });
        fireEvent.click(within(dialogo).getByRole('button', { name: 'Transferir a avaliação de Bruno Lima para outro projeto' }));
        const painel = within(dialogo).getByRole('group', { name: 'Transferir a avaliação de Bruno Lima' });
        fireEvent.click(await within(painel).findByRole('radio', { name: /Estande 013/ }));
        fireEvent.change(within(painel).getByLabelText('Por que transferir esta avaliação?'), { target: { value: 'Estande trocado.' } });
        fireEvent.click(within(painel).getByRole('button', { name: 'Transferir avaliação' }));

        expect(await within(painel).findByText('O projeto de destino ainda não tem estande no Mapa do Evento.')).toBeInTheDocument();
        expect(within(dialogo).getByText('Bruno Lima', { selector: 'p' })).toBeInTheDocument();
    });

    it('baixa o ranking no recorte dos filtros da tela', async () => {
        render(<PresencialRanking />);
        await screen.findByText('Abelhas');

        fireEvent.change(screen.getByLabelText('Área do conhecimento'), { target: { value: '3' } });
        await waitFor(() => expect(getRankingPresencial).toHaveBeenLastCalledWith({ area_id: '3', categoria: '' }, false));

        for (const [rotulo, formato] of [['CSV', 'csv'], ['Excel', 'xlsx'], ['PDF', 'pdf'], ['TXT', 'txt']]) {
            fireEvent.click(screen.getByRole('button', { name: `Baixar ${rotulo}` }));
            await waitFor(() => expect(exportarRankingPresencial).toHaveBeenLastCalledWith(formato, { area_id: '3', categoria: '' }, false));
        }
    });

    it('Gerar lista abre o assistente e leva ao rascunho', async () => {
        render(<PresencialRanking />);
        await screen.findByText('Abelhas');

        expect(screen.getByRole('link', { name: /Listas preliminares e finais/ })).toHaveAttribute('href', '/admin/presencial/listas');
        fireEvent.click(screen.getByRole('button', { name: /Gerar lista/ }));
        fireEvent.click(screen.getByRole('button', { name: 'Simular rascunho' }));
        expect(navigate).toHaveBeenCalledWith('/admin/presencial/listas/77');
    });

    it('a falha do download vira aviso', async () => {
        exportarRankingPresencial.mockRejectedValueOnce(new Error('x'));
        render(<PresencialRanking />);
        await screen.findByText('Abelhas');

        fireEvent.click(screen.getByRole('button', { name: 'Baixar PDF' }));
        expect(await screen.findByText('Não foi possível gerar o arquivo.')).toBeInTheDocument();
    });

    it('a conta temporária do voluntário não vê os botões', async () => {
        auth.user = { id: 2, role: 'admin', conta_temporaria: true };
        render(<PresencialRanking />);

        await screen.findByText('Abelhas');
        expect(screen.queryByRole('button', { name: /Ver avaliações/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Baixar/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Gerar lista/ })).not.toBeInTheDocument();
    });

    it('sem avaliação enviada, diz que não há ranking ainda', async () => {
        getRankingPresencial.mockResolvedValueOnce({ ...RANKING, projetos: [] });
        render(<PresencialRanking />);

        expect(await screen.findByText('Nenhum projeto com avaliação presencial enviada.')).toBeInTheDocument();
    });
});
