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
const marcarForaPrazo = vi.fn();
vi.mock('../lib/credenciamento.js', () => ({
    marcarForaPrazo: (...a) => marcarForaPrazo(...a),
    removerForaPrazo: vi.fn(),
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

    it('termo de imagem enviado online já vem marcado, com o arquivo à mão, e pode ser mudado', async () => {
        getFichaCredenciamento.mockResolvedValue({
            ...FICHA,
            pessoas: [{
                ...FICHA.pessoas[0],
                documentos: [{
                    id: 9, nome: 'Termo de uso de imagem', chave: 'termo_uso_imagem', situacao: 'presente',
                    pre_marcado: true, online: [{ id: 44, nome_original: 'ana.jpg', enviado_em: '2026-10-01T10:00:00-04:00' }],
                }],
            }],
        });
        render(<CredenciamentoFicha />);
        await screen.findAllByText('Ana Aluna');

        expect(screen.getByText('Enviado online')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'ana.jpg' })).toHaveAttribute('href', '/api/v1/documentos/44/preview');
        const grupo = screen.getByRole('group', { name: 'Termo de uso de imagem de Ana Aluna' });
        expect(within(grupo).getByRole('button', { name: 'Presente' })).toHaveAttribute('aria-pressed', 'true');

        // O atendente achou o arquivo ilegível.
        fireEvent.click(within(grupo).getByRole('button', { name: 'Ausente' }));
        expect(screen.getByText(/confira antes de mudar a marcação/)).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /Concluir credenciamento/ }));
        await waitFor(() => expect(credenciarProjeto).toHaveBeenCalled());
        expect(credenciarProjeto.mock.calls[0][1].marcacoes).toEqual(expect.arrayContaining([
            { documento_id: 9, pessoa_tipo: 'aluno', pessoa_id: 30, situacao: 'ausente' },
        ]));
    });

    it('item do projeto aparece num bloco próprio e vai marcado com o projeto (Sprint 182)', async () => {
        getFichaCredenciamento.mockResolvedValue({
            ...FICHA,
            itens_projeto: [{
                id: 40, nome: 'Relatório impresso', pessoa_tipo: 'projeto', pessoa_id: 7,
                situacao: null, pre_marcado: false, online: [],
            }],
        });
        render(<CredenciamentoFicha />);
        await screen.findAllByText('Ana Aluna');

        expect(screen.getByText('Do projeto')).toBeInTheDocument();
        const grupo = screen.getByRole('group', { name: 'Relatório impresso de Bioplástico' });
        fireEvent.click(within(grupo).getByRole('button', { name: 'Presente' }));
        fireEvent.click(screen.getByRole('button', { name: /Concluir credenciamento/ }));

        await waitFor(() => expect(credenciarProjeto).toHaveBeenCalled());
        expect(credenciarProjeto.mock.calls[0][1].marcacoes).toEqual(expect.arrayContaining([
            { documento_id: 40, pessoa_tipo: 'projeto', pessoa_id: 7, situacao: 'presente' },
        ]));
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

    it('mostra código, turno e estande no cabeçalho (Sprint 217)', async () => {
        getFichaCredenciamento.mockResolvedValue({
            ...FICHA,
            projeto: { ...FICHA.projeto, codigo: 'FET.AGR-001', turno: 'A', turno_label: 'Turno A (matutino)', estande: 42 },
        });
        render(<CredenciamentoFicha />);

        expect(await screen.findByText('FET.AGR-001')).toBeInTheDocument();
        expect(screen.getByText('Turno A (matutino)')).toBeInTheDocument();
        expect(screen.getByText('Estande 42')).toBeInTheDocument();
    });

    it('"Tudo presente" marca a pessoa como compareceu e todos os documentos dela (Sprint 217)', async () => {
        render(<CredenciamentoFicha />);
        await screen.findAllByText('Ana Aluna');

        // Mesmo depois de marcada como ausente, o atalho a traz de volta.
        fireEvent.click(within(screen.getByRole('group', { name: 'Presença de Ana Aluna' }))
            .getByRole('button', { name: 'Faltou' }));
        fireEvent.click(screen.getByRole('button', { name: 'Marcar Ana Aluna como presente em todos os itens' }));

        const rg = screen.getByRole('group', { name: 'RG de Ana Aluna' });
        expect(within(rg).getByRole('button', { name: 'Presente' })).toHaveAttribute('aria-pressed', 'true');
        // O orientador não é tocado.
        const foto = screen.getByRole('group', { name: 'Documento com foto de Marta Orientadora' });
        expect(within(foto).getByRole('button', { name: 'Presente' })).toHaveAttribute('aria-pressed', 'false');

        fireEvent.click(screen.getByRole('button', { name: /Concluir credenciamento/ }));
        await waitFor(() => expect(credenciarProjeto).toHaveBeenCalled());
        const [, payload] = credenciarProjeto.mock.calls[0];
        expect(payload.pessoas[0]).toEqual({ pessoa_tipo: 'aluno', pessoa_id: 30, presente: true });
        expect(payload.marcacoes).toEqual(expect.arrayContaining([
            { documento_id: 1, pessoa_tipo: 'aluno', pessoa_id: 30, situacao: 'presente' },
            { documento_id: 2, pessoa_tipo: 'aluno', pessoa_id: 30, situacao: 'presente' },
        ]));
        expect(payload.marcacoes).not.toEqual(expect.arrayContaining([
            expect.objectContaining({ documento_id: 3 }),
        ]));
    });

    it('"Todos compareceram" marca a equipe inteira e os itens do projeto (Sprint 217)', async () => {
        getFichaCredenciamento.mockResolvedValue({
            ...FICHA,
            itens_projeto: [{
                id: 40, nome: 'Relatório impresso', pessoa_tipo: 'projeto', pessoa_id: 7,
                situacao: null, pre_marcado: false, online: [],
            }],
        });
        render(<CredenciamentoFicha />);
        await screen.findAllByText('Ana Aluna');

        fireEvent.click(within(screen.getByRole('group', { name: 'Presença de Marta Orientadora' }))
            .getByRole('button', { name: 'Faltou' }));
        fireEvent.click(screen.getByRole('button', { name: /Todos compareceram/ }));
        fireEvent.click(screen.getByRole('button', { name: /Concluir credenciamento/ }));

        await waitFor(() => expect(credenciarProjeto).toHaveBeenCalled());
        const [, payload] = credenciarProjeto.mock.calls[0];
        expect(payload.pessoas).toEqual([
            { pessoa_tipo: 'aluno', pessoa_id: 30, presente: true },
            { pessoa_tipo: 'orientador', pessoa_id: 5, presente: true },
        ]);
        expect(payload.marcacoes).toHaveLength(4);
        expect(payload.marcacoes.every((m) => m.situacao === 'presente')).toBe(true);
        expect(payload.marcacoes).toEqual(expect.arrayContaining([
            { documento_id: 40, pessoa_tipo: 'projeto', pessoa_id: 7, situacao: 'presente' },
            { documento_id: 3, pessoa_tipo: 'orientador', pessoa_id: 5, situacao: 'presente' },
        ]));
    });

    it('"Todos os kits" seleciona os pendentes de uma vez, e o responsável ainda é pedido (Sprint 217)', async () => {
        getFichaCredenciamento.mockResolvedValue({
            ...credenciado(),
            pessoas: [
                {
                    ...FICHA.pessoas[0],
                    kit: { retirado: true, em: '2026-09-01T12:00:00-04:00', por_nome: 'Ana Aluna' },
                },
                FICHA.pessoas[1],
                { tipo: 'coorientador', tipo_label: 'Coorientador', id: 8, nome: 'Caio Coorientador', documentos: [] },
            ],
        });
        render(<CredenciamentoFicha />);
        await screen.findAllByText('Ana Aluna');

        fireEvent.click(screen.getByRole('button', { name: /Todos os kits/ }));
        expect(screen.getByLabelText('Kit de Marta Orientadora')).toBeChecked();
        expect(screen.getByLabelText('Kit de Caio Coorientador')).toBeChecked();
        // Sem responsável, nada é gravado.
        expect(screen.getByRole('button', { name: /Registrar retirada/ })).toBeDisabled();

        fireEvent.change(screen.getByLabelText('Quem está retirando os kits'), {
            target: { value: 'orientador:5' },
        });
        fireEvent.click(screen.getByRole('button', { name: /Registrar retirada/ }));

        await waitFor(() => expect(registrarRetiradaKit).toHaveBeenCalledWith('7', {
            responsavel_tipo: 'orientador',
            responsavel_id: 5,
            pessoas: [
                { pessoa_tipo: 'orientador', pessoa_id: 5 },
                { pessoa_tipo: 'coorientador', pessoa_id: 8 },
            ],
        }, false));
    });

    it('"Desmarcar todos" desfaz a seleção dos kits (Sprint 217)', async () => {
        render(<CredenciamentoFicha />);
        await screen.findAllByText('Ana Aluna');

        fireEvent.click(screen.getByRole('button', { name: /Todos os kits/ }));
        expect(screen.getByLabelText('Kit de Ana Aluna')).toBeChecked();

        fireEvent.click(screen.getByRole('button', { name: /Desmarcar todos/ }));
        expect(screen.getByLabelText('Kit de Ana Aluna')).not.toBeChecked();
        expect(screen.getByLabelText('Kit de Marta Orientadora')).not.toBeChecked();
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

describe('CredenciamentoFicha — fora do prazo e suporte (Sprints 161–162)', () => {
    const COM_SINAIS = {
        ...FICHA,
        pode_aprovar_excecao: true,
        sinalizacoes: {
            fora_prazo: null,
            suportes: [{ id: 1, tipo: 'acompanhante', tipo_label: 'Acompanhante', resumo: 'Acompanhante: Maria (mãe) — de Ana Aluna', documento: 'RG 123' }],
        },
        acompanhantes: [{ id: 1, nome: 'Maria', documento: 'RG 123', vinculo: 'Mãe', aluno: 'Ana Aluna', codigo: '2026-7-000-S1' }],
    };

    beforeEach(() => {
        getFichaCredenciamento.mockResolvedValue(COM_SINAIS);
        marcarForaPrazo.mockReset().mockResolvedValue({
            data: { ...COM_SINAIS, sinalizacoes: { ...COM_SINAIS.sinalizacoes, fora_prazo: { previsto_label: '05/10/2026 14:00', observacao: 'Chega segunda.' } } },
        });
    });

    it('mostra o suporte aprovado e o acompanhante a conferir', async () => {
        render(<CredenciamentoFicha />);

        expect(await screen.findByText('Acompanhante: Maria (mãe) — de Ana Aluna')).toBeInTheDocument();
        expect(screen.getByText('2026-7-000-S1')).toBeInTheDocument();
    });

    it('admin permanente marca o credenciamento fora do prazo com a data prevista', async () => {
        render(<CredenciamentoFicha />);

        fireEvent.click(await screen.findByRole('button', { name: /Marcar credenciamento fora do prazo/ }));
        fireEvent.change(screen.getByLabelText('Chegada prevista'), { target: { value: '2026-10-05T14:00' } });
        fireEvent.change(screen.getByLabelText('Observação do fora do prazo'), { target: { value: 'Chega segunda.' } });
        fireEvent.click(screen.getByRole('button', { name: 'Salvar' }));

        await waitFor(() => expect(marcarForaPrazo).toHaveBeenCalledWith('7', { previsto_em: '2026-10-05T14:00', observacao: 'Chega segunda.' }, false));
        expect(await screen.findByText('05/10/2026 14:00')).toBeInTheDocument();
    });

    it('conta temporária vê o selo mas não marca', async () => {
        getFichaCredenciamento.mockResolvedValue({ ...COM_SINAIS, pode_aprovar_excecao: false });
        render(<CredenciamentoFicha />);

        await screen.findByText('Acompanhante: Maria (mãe) — de Ana Aluna');
        expect(screen.queryByRole('button', { name: /fora do prazo/ })).not.toBeInTheDocument();
    });
});
