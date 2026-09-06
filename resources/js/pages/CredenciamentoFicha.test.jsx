import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
const navigate = vi.fn();
vi.mock('react-router-dom', () => ({
    Link: ({ children, to }) => <a href={to}>{children}</a>,
    useParams: () => ({ id: '7' }),
    useNavigate: () => navigate,
}));
vi.mock('../lib/auth.jsx', () => ({ extractErrors: () => ({ message: 'erro', fields: {} }) }));
vi.mock('../lib/modoTeste.js', () => ({ useModoTeste: () => [false, vi.fn()] }));

const SITUACOES = [
    { value: 'presente', label: 'Presente' },
    { value: 'ausente', label: 'Ausente' },
    { value: 'nao_necessario', label: 'Não necessário' },
];

const FICHA = {
    projeto: { id: 7, titulo: 'Bioplástico', categoria: 'FETECMS', area: 'Agrárias', escola: 'EE Alfa' },
    pessoas: [
        {
            tipo: 'aluno', tipo_label: 'Aluno', id: 30, nome: 'Ana Aluna',
            documentos: [
                { id: 1, nome: 'RG', situacao: null },
                { id: 2, nome: 'Autorização de menor', situacao: 'presente' },
            ],
        },
        {
            tipo: 'orientador', tipo_label: 'Orientador', id: 5, nome: 'Marta Orientadora',
            documentos: [{ id: 3, nome: 'Documento com foto', situacao: null }],
        },
    ],
    credenciamento: null,
    situacoes: SITUACOES,
    config: { aberto: true, itens: [], minutos_atendimento: 5 },
};

const getFichaCredenciamento = vi.fn(() => Promise.resolve(FICHA));
const credenciarProjeto = vi.fn(() => Promise.resolve({}));
const cancelarCredenciamento = vi.fn(() => Promise.resolve({}));
const registrarRetiradaKit = vi.fn(() => Promise.resolve({}));
const salvarRascunhoCredenciamento = vi.fn(() => Promise.resolve({}));
const assumirCredenciamento = vi.fn(() => Promise.resolve({}));
vi.mock('../lib/credenciamento.js', () => ({
    getFichaCredenciamento: (...a) => getFichaCredenciamento(...a),
    credenciarProjeto: (...a) => credenciarProjeto(...a),
    cancelarCredenciamento: (...a) => cancelarCredenciamento(...a),
    registrarRetiradaKit: (...a) => registrarRetiradaKit(...a),
    salvarRascunhoCredenciamento: (...a) => salvarRascunhoCredenciamento(...a),
    assumirCredenciamento: (...a) => assumirCredenciamento(...a),
}));

/** Ficha de um projeto já credenciado. */
const credenciado = (over = {}) => ({
    ...FICHA,
    credenciamento: {
        concluido: true,
        finalizado_em: '2026-09-01T12:00:00-04:00',
        credenciado_por: 'Ana Admin',
        credenciado_por_mim: false,
        pode_alterar: true,
        motivo_bloqueio: null,
        observacao: null,
        iniciado_em: '2026-09-01T11:55:00-04:00',
        ...over,
    },
});

import CredenciamentoFicha from './CredenciamentoFicha.jsx';

describe('CredenciamentoFicha', () => {
    beforeEach(() => {
        credenciarProjeto.mockClear();
        registrarRetiradaKit.mockClear();
        salvarRascunhoCredenciamento.mockClear();
        assumirCredenciamento.mockClear();
        navigate.mockClear();
        getFichaCredenciamento.mockResolvedValue(FICHA);
    });

    it('cancela o credenciamento com justificativa obrigatória', async () => {
        getFichaCredenciamento.mockResolvedValue(credenciado());
        render(<CredenciamentoFicha />);

        fireEvent.click(await screen.findByRole('button', { name: /Cancelar credenciamento/ }));

        const confirmar = screen.getAllByRole('button', { name: 'Cancelar credenciamento' }).at(-1);
        expect(confirmar).toBeDisabled();

        fireEvent.change(screen.getByLabelText('Justificativa do cancelamento'), {
            target: { value: 'Credenciado por engano.' },
        });
        fireEvent.click(confirmar);

        await waitFor(() => expect(cancelarCredenciamento).toHaveBeenCalledWith(
            '7', 'Credenciado por engano.', false,
        ));
    });

    it('credenciamento de outra conta abre em leitura, com o motivo', async () => {
        getFichaCredenciamento.mockResolvedValue(credenciado({
            pode_alterar: false,
            motivo_bloqueio: 'Este credenciamento foi feito por Ana Admin. Só um administrador com conta permanente pode alterá-lo ou cancelá-lo.',
        }));
        render(<CredenciamentoFicha />);

        expect(await screen.findByText(/conta permanente pode alterá-lo/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Cancelar credenciamento/ })).toBeDisabled();
        expect(screen.getByRole('button', { name: /Regravar credenciamento/ })).toBeDisabled();
    });

    it('mostra cada pessoa com os documentos do papel dela', async () => {
        render(<CredenciamentoFicha />);

        // O nome aparece duas vezes: no cartão da pessoa e na lista de kits.
        expect(await screen.findAllByText('Ana Aluna')).not.toHaveLength(0);
        expect(screen.getAllByText('Marta Orientadora')).not.toHaveLength(0);
        expect(screen.getByText('RG')).toBeInTheDocument();
        expect(screen.getByText('Documento com foto')).toBeInTheDocument();
        // Três botões de situação por documento (3 documentos).
        expect(screen.getAllByText('Presente')).toHaveLength(3);
    });

    it('já vem com o que foi conferido antes marcado', async () => {
        render(<CredenciamentoFicha />);
        await screen.findAllByText('Ana Aluna');

        const grupo = screen.getByRole('group', { name: 'Autorização de menor de Ana Aluna' });
        const presente = within(grupo).getByRole('button', { name: 'Presente' });
        expect(presente).toHaveAttribute('aria-pressed', 'true');
    });

    it('envia as marcações escolhidas ao concluir', async () => {
        render(<CredenciamentoFicha />);
        await screen.findAllByText('Ana Aluna');

        const rg = screen.getByRole('group', { name: 'RG de Ana Aluna' });
        fireEvent.click(within(rg).getByRole('button', { name: 'Presente' }));

        const foto = screen.getByRole('group', { name: 'Documento com foto de Marta Orientadora' });
        fireEvent.click(within(foto).getByRole('button', { name: 'Ausente' }));

        fireEvent.click(screen.getByRole('button', { name: /Concluir credenciamento/ }));

        await waitFor(() => expect(credenciarProjeto).toHaveBeenCalled());
        const [, payload] = credenciarProjeto.mock.calls[0];
        expect(payload.marcacoes).toEqual(expect.arrayContaining([
            { documento_id: 2, pessoa_tipo: 'aluno', pessoa_id: 30, situacao: 'presente' },
            { documento_id: 1, pessoa_tipo: 'aluno', pessoa_id: 30, situacao: 'presente' },
            { documento_id: 3, pessoa_tipo: 'orientador', pessoa_id: 5, situacao: 'ausente' },
        ]));
        expect(navigate).toHaveBeenCalledWith('/admin/credenciamento/credenciados', { replace: true });
    });

    it('fora da janela do evento a ficha fica só de leitura', async () => {
        getFichaCredenciamento.mockResolvedValue({ ...FICHA, config: { aberto: false } });
        render(<CredenciamentoFicha />);
        await screen.findAllByText('Ana Aluna');

        expect(screen.getByText(/O credenciamento está fechado agora/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Concluir credenciamento/ })).toBeDisabled();
        const rg = screen.getByRole('group', { name: 'RG de Ana Aluna' });
        expect(within(rg).getByRole('button', { name: 'Presente' })).toBeDisabled();
    });

    it('manda o início só quando o horário sugerido é alterado', async () => {
        render(<CredenciamentoFicha />);
        await screen.findAllByText('Ana Aluna');

        // Sem mexer no campo, o início não viaja: o fim é o instante da conclusão.
        fireEvent.click(screen.getByRole('button', { name: /Concluir credenciamento/ }));
        await waitFor(() => expect(credenciarProjeto).toHaveBeenCalled());
        expect(credenciarProjeto.mock.calls[0][1].iniciado_em).toBeNull();

        credenciarProjeto.mockClear();
        render(<CredenciamentoFicha />);
        await screen.findAllByText('Ana Aluna');

        fireEvent.change(screen.getAllByLabelText('Início do atendimento')[1], {
            target: { value: '2026-10-01T09:00' },
        });
        fireEvent.click(screen.getAllByRole('button', { name: /Concluir credenciamento/ })[1]);

        await waitFor(() => expect(credenciarProjeto).toHaveBeenCalled());
        expect(credenciarProjeto.mock.calls[0][1].iniciado_em).toBe('2026-10-01T09:00');
    });

    it('lembra os itens a entregar antes de sair da ficha', async () => {
        getFichaCredenciamento.mockResolvedValue({
            ...FICHA,
            config: { aberto: true, itens: ['Camiseta', 'Crachá'], minutos_atendimento: 5 },
        });
        render(<CredenciamentoFicha />);
        await screen.findAllByText('Ana Aluna');

        fireEvent.click(screen.getByRole('button', { name: /Concluir credenciamento/ }));

        expect(await screen.findByText('Credenciamento concluído')).toBeInTheDocument();
        expect(screen.getByText('Camiseta')).toBeInTheDocument();
        expect(screen.getByText('Crachá')).toBeInTheDocument();
        // Só sai da ficha depois do lembrete.
        expect(navigate).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Entendi' }));
        expect(navigate).toHaveBeenCalledWith('/admin/credenciamento/credenciados', { replace: true });
    });

    it('marcar que a pessoa faltou esconde os documentos dela e viaja na conclusão', async () => {
        render(<CredenciamentoFicha />);
        await screen.findAllByText('Ana Aluna');

        // Antes: a aluna tem documentos para conferir.
        expect(screen.getByRole('group', { name: 'RG de Ana Aluna' })).toBeInTheDocument();

        fireEvent.click(screen.getByRole('group', { name: 'Presença de Ana Aluna' })
            .querySelector('button:last-child'));

        expect(screen.queryByRole('group', { name: 'RG de Ana Aluna' })).not.toBeInTheDocument();
        expect(screen.getByText(/Faltou ao credenciamento/)).toBeInTheDocument();
        // O orientador continua conferível.
        expect(screen.getByRole('group', { name: 'Documento com foto de Marta Orientadora' })).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /Concluir credenciamento/ }));

        await waitFor(() => expect(credenciarProjeto).toHaveBeenCalled());
        expect(credenciarProjeto.mock.calls[0][1].pessoas).toEqual([
            { pessoa_tipo: 'aluno', pessoa_id: 30, presente: false },
            { pessoa_tipo: 'orientador', pessoa_id: 5, presente: true },
        ]);
    });

    it('os kits escolhidos saem junto da conclusão, no nome de quem está retirando', async () => {
        render(<CredenciamentoFicha />);
        await screen.findAllByText('Ana Aluna');

        fireEvent.click(screen.getByLabelText('Kit de Ana Aluna'));
        fireEvent.click(screen.getByLabelText('Kit de Marta Orientadora'));
        fireEvent.change(screen.getByLabelText('Quem está retirando os kits'), {
            target: { value: 'aluno:30' },
        });
        fireEvent.click(screen.getByRole('button', { name: /Concluir credenciamento/ }));

        await waitFor(() => expect(credenciarProjeto).toHaveBeenCalled());
        expect(credenciarProjeto.mock.calls[0][1].kits).toEqual({
            responsavel_tipo: 'aluno',
            responsavel_id: 30,
            pessoas: [
                { pessoa_tipo: 'aluno', pessoa_id: 30 },
                { pessoa_tipo: 'orientador', pessoa_id: 5 },
            ],
        });
    });

    it('com o credenciamento concluído, a retirada do kit tem botão próprio', async () => {
        getFichaCredenciamento.mockResolvedValue({
            ...credenciado(),
            pessoas: [
                {
                    ...FICHA.pessoas[0],
                    kit: { retirado: true, em: '2026-09-01T12:00:00-04:00', por_nome: 'Ana Aluna' },
                },
                FICHA.pessoas[1],
            ],
        });
        render(<CredenciamentoFicha />);
        await screen.findAllByText('Ana Aluna');

        // O kit que já saiu não volta a ser marcado, e diz quem levou.
        expect(screen.getByLabelText('Kit de Ana Aluna')).toBeDisabled();
        expect(screen.getByText(/Retirado por Ana Aluna/)).toBeInTheDocument();

        fireEvent.click(screen.getByLabelText('Kit de Marta Orientadora'));
        fireEvent.change(screen.getByLabelText('Quem está retirando os kits'), {
            target: { value: 'orientador:5' },
        });
        fireEvent.click(screen.getByRole('button', { name: /Registrar retirada/ }));

        await waitFor(() => expect(registrarRetiradaKit).toHaveBeenCalledWith('7', {
            responsavel_tipo: 'orientador',
            responsavel_id: 5,
            pessoas: [{ pessoa_tipo: 'orientador', pessoa_id: 5 }],
        }, false));
        // Retirar kit não é regravar a conferência.
        expect(credenciarProjeto).not.toHaveBeenCalled();
    });

    it('salva o atendimento como rascunho, com a conferência já feita', async () => {
        render(<CredenciamentoFicha />);
        await screen.findAllByText('Ana Aluna');

        fireEvent.click(within(screen.getByRole('group', { name: 'RG de Ana Aluna' }))
            .getByRole('button', { name: 'Presente' }));
        fireEvent.click(screen.getByRole('button', { name: /Salvar rascunho/ }));

        await waitFor(() => expect(salvarRascunhoCredenciamento).toHaveBeenCalled());
        expect(salvarRascunhoCredenciamento.mock.calls[0][1].marcacoes).toContainEqual({
            documento_id: 1, pessoa_tipo: 'aluno', pessoa_id: 30, situacao: 'presente',
        });
        // Rascunho volta para a fila de credenciar, não para credenciados.
        expect(navigate).toHaveBeenCalledWith('/admin/credenciamento/credenciar', { replace: true });
    });

    it('rascunho de outro admin permanente exige assumir com justificativa', async () => {
        getFichaCredenciamento.mockResolvedValue({
            ...FICHA,
            credenciamento: {
                concluido: false, em_rascunho: true, iniciado_por: 'Ana Admin',
                meu_rascunho: false, exige_justificativa: true, pode_alterar: true,
                motivo_bloqueio: null, observacao: null, iniciado_em: '2026-09-01T11:55:00-04:00',
                finalizado_em: null, credenciado_por: null, credenciado_por_mim: false,
            },
        });
        render(<CredenciamentoFicha />);

        // A ficha abre travada: nem conferir, nem concluir, nem salvar rascunho.
        expect(await screen.findByText(/assuma o atendimento informando o motivo/i)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Concluir credenciamento/ })).toBeDisabled();
        expect(screen.getByRole('button', { name: /Salvar rascunho/ })).toBeDisabled();

        fireEvent.click(screen.getByRole('button', { name: /Assumir atendimento/ }));
        const confirmar = screen.getByRole('button', { name: 'Assumir' });
        expect(confirmar).toBeDisabled();

        fireEvent.change(screen.getByLabelText('Justificativa para assumir o atendimento'), {
            target: { value: 'A Ana saiu do evento.' },
        });
        fireEvent.click(confirmar);

        await waitFor(() => expect(assumirCredenciamento).toHaveBeenCalledWith(
            '7', 'A Ana saiu do evento.', false,
        ));
    });

    it('rascunho de conta temporária é continuado direto, com aviso', async () => {
        getFichaCredenciamento.mockResolvedValue({
            ...FICHA,
            credenciamento: {
                concluido: false, em_rascunho: true, iniciado_por: 'Bruna Atendente',
                meu_rascunho: false, exige_justificativa: false, pode_alterar: true,
                motivo_bloqueio: null, observacao: null, iniciado_em: '2026-09-01T11:55:00-04:00',
                finalizado_em: null, credenciado_por: null, credenciado_por_mim: false,
            },
        });
        render(<CredenciamentoFicha />);

        expect(await screen.findByText(/ele passa para você/i)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Concluir credenciamento/ })).toBeEnabled();
        expect(screen.queryByRole('button', { name: /Assumir atendimento/ })).not.toBeInTheDocument();
    });
});
