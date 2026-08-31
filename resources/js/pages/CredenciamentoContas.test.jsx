import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.response?.data?.message ?? '', fields: e?.response?.data?.errors ?? {} }),
}));

const getContasTemporarias = vi.fn();
const criarContaTemporaria = vi.fn();
const renovarContaTemporaria = vi.fn();
const desativarContaTemporaria = vi.fn();
vi.mock('../lib/credenciamento.js', () => ({
    getContasTemporarias: (...a) => getContasTemporarias(...a),
    criarContaTemporaria: (...a) => criarContaTemporaria(...a),
    renovarContaTemporaria: (...a) => renovarContaTemporaria(...a),
    desativarContaTemporaria: (...a) => desativarContaTemporaria(...a),
}));

import CredenciamentoContas from './CredenciamentoContas.jsx';

const ativa = {
    id: 1, user_id: 10, nome: 'Bruna Atendente', email: 'bruna@balcao.test',
    cpf: '529.982.247-25', curso: 'Ciência da Computação',
    expira_em_label: '05/09/2026 23:59', expira_em_input: '2026-09-05T23:59',
    valido_de: null, valido_de_label: null, valido_de_input: null,
    ativa: true, vencida: false, agendada: false, horas_restantes: 3,
    duracao_label: '3 horas', criada_por: 'Ana Admin',
};
const vencida = {
    ...ativa, id: 2, nome: 'Caio Vencido', email: 'caio@balcao.test',
    cpf: '111.444.777-35', curso: 'Enfermagem',
    ativa: false, vencida: true, horas_restantes: 0, duracao_label: 'encerrado',
};
// A conta preparada na véspera: existe, está ativa, mas ainda não abre.
const agendada = {
    ...ativa, id: 3, nome: 'Dora Agendada', email: 'dora@balcao.test',
    agendada: true, valido_de_label: '12/09/2026 08:00', duracao_label: '5 horas',
};

const lista = (contas) => ({ contas, horas_padrao: 5, horas_max: 8760 });

describe('CredenciamentoContas', () => {
    beforeEach(() => {
        getContasTemporarias.mockReset().mockResolvedValue(lista([ativa, vencida]));
        criarContaTemporaria.mockReset();
        renovarContaTemporaria.mockReset();
        desativarContaTemporaria.mockReset();
    });

    it('lista as contas com CPF, curso e a situação de cada uma', async () => {
        render(<CredenciamentoContas />);

        expect(await screen.findByText('Bruna Atendente')).toBeInTheDocument();
        expect(screen.getByText('Ativa · 3 horas')).toBeInTheDocument();
        expect(screen.getByText('Prazo vencido')).toBeInTheDocument();
        expect(screen.getByText(/529\.982\.247-25/)).toBeInTheDocument();
        expect(screen.getByText(/Ciência da Computação/)).toBeInTheDocument();
        expect(screen.getByText(/Enfermagem/)).toBeInTheDocument();
    });

    it('cria uma conta com CPF mascarado na tela e só dígitos na API', async () => {
        criarContaTemporaria.mockResolvedValue({
            data: lista([ativa]), meta: { message: 'Conta temporária criada.' },
        });
        render(<CredenciamentoContas />);

        fireEvent.click(await screen.findByText('Nova conta temporária'));
        fireEvent.change(screen.getByLabelText('Nome'), { target: { value: 'Bruna Atendente' } });
        fireEvent.change(screen.getByLabelText('E-mail'), { target: { value: 'bruna@balcao.test' } });

        const cpf = screen.getByLabelText('CPF');
        fireEvent.change(cpf, { target: { value: '52998224725' } });
        expect(cpf).toHaveValue('529.982.247-25');

        fireEvent.change(screen.getByLabelText('Nome do curso'), { target: { value: 'Ciência da Computação' } });
        fireEvent.change(screen.getByLabelText('Senha'), { target: { value: 'senha-do-balcao' } });
        fireEvent.change(screen.getByLabelText('Confirmar senha'), { target: { value: 'senha-do-balcao' } });
        fireEvent.change(screen.getByLabelText('Disponível por (horas)'), { target: { value: '3' } });
        fireEvent.click(screen.getByText('Criar conta'));

        await waitFor(() => expect(criarContaTemporaria).toHaveBeenCalledWith(expect.objectContaining({
            name: 'Bruna Atendente', email: 'bruna@balcao.test',
            cpf: '52998224725', curso: 'Ciência da Computação', horas: 3,
        })));
        expect(await screen.findByText('Conta temporária criada.')).toBeInTheDocument();
    });

    it('mostra os erros de validação campo a campo', async () => {
        criarContaTemporaria.mockRejectedValue({
            response: { data: { message: 'Confira os campos.', errors: { cpf: ['CPF inválido.'] } } },
        });
        render(<CredenciamentoContas />);

        fireEvent.click(await screen.findByText('Nova conta temporária'));
        fireEvent.click(screen.getByText('Criar conta'));

        expect(await screen.findByText('CPF inválido.')).toBeInTheDocument();
        expect(screen.getByText('Confira os campos.')).toBeInTheDocument();
    });

    it('renova o acesso de quem venceu, sem recadastrar', async () => {
        renovarContaTemporaria.mockResolvedValue({
            data: lista([ativa, { ...vencida, ativa: true, vencida: false, horas_restantes: 5 }]),
            meta: { message: 'Acesso renovado.' },
        });
        render(<CredenciamentoContas />);

        fireEvent.click(await screen.findByTitle('Renovar o acesso de Caio Vencido'));
        fireEvent.change(screen.getByLabelText('Horas de renovação de Caio Vencido'), { target: { value: '5' } });
        fireEvent.click(screen.getByText('Renovar'));

        await waitFor(() => expect(renovarContaTemporaria).toHaveBeenCalledWith(2, { horas: 5 }));
        expect(await screen.findByText('Acesso renovado.')).toBeInTheDocument();
    });

    it('encerra o acesso após confirmação', async () => {
        desativarContaTemporaria.mockResolvedValue({
            data: lista([{ ...ativa, ativa: false }, vencida]), meta: { message: 'Acesso encerrado.' },
        });
        render(<CredenciamentoContas />);

        fireEvent.click(await screen.findByTitle('Encerrar o acesso de Bruna Atendente'));
        fireEvent.click(await screen.findByText('Encerrar'));

        await waitFor(() => expect(desativarContaTemporaria).toHaveBeenCalledWith(1));
    });

    it('quem venceu não mostra o botão de encerrar — já está sem acesso', async () => {
        render(<CredenciamentoContas />);
        await screen.findByText('Caio Vencido');

        expect(screen.queryByTitle('Encerrar o acesso de Caio Vencido')).not.toBeInTheDocument();
        expect(screen.getByTitle('Renovar o acesso de Caio Vencido')).toBeInTheDocument();
    });

    it('marca a conta que ainda não começou como agendada, com a data', async () => {
        getContasTemporarias.mockResolvedValue(lista([agendada]));
        render(<CredenciamentoContas />);

        expect(await screen.findByText('Agendada · 12/09/2026 08:00')).toBeInTheDocument();
        // Agendada continua podendo ser encerrada: o acesso existe, só não abriu.
        expect(screen.getByTitle('Encerrar o acesso de Dora Agendada')).toBeInTheDocument();
    });

    it('agenda o início do acesso na criação', async () => {
        criarContaTemporaria.mockResolvedValue({
            data: lista([agendada]), meta: { message: 'Conta temporária criada.' },
        });
        render(<CredenciamentoContas />);

        fireEvent.click(await screen.findByText('Nova conta temporária'));
        fireEvent.change(screen.getByLabelText('Começa em'), { target: { value: '2026-09-12T08:00' } });
        fireEvent.click(screen.getByText('Criar conta'));

        await waitFor(() => expect(criarContaTemporaria).toHaveBeenCalledWith(expect.objectContaining({
            valido_de: '2026-09-12T08:00',
        })));
    });

    it('reagenda pelo mesmo caminho da renovação', async () => {
        renovarContaTemporaria.mockResolvedValue({
            data: lista([agendada]), meta: { message: 'Acesso renovado.' },
        });
        getContasTemporarias.mockResolvedValue(lista([agendada]));
        render(<CredenciamentoContas />);

        fireEvent.click(await screen.findByTitle('Renovar o acesso de Dora Agendada'));
        fireEvent.change(screen.getByLabelText('Início do acesso de Dora Agendada'), { target: { value: '2026-09-13T08:00' } });
        fireEvent.click(screen.getByText('Renovar'));

        // Campo em branco não viaja: o backend resolve pelo padrão.
        await waitFor(() => expect(renovarContaTemporaria).toHaveBeenCalledWith(3, { valido_de: '2026-09-13T08:00' }));
    });

    it('explica a lista vazia', async () => {
        getContasTemporarias.mockResolvedValue(lista([]));
        render(<CredenciamentoContas />);

        expect(await screen.findByText('Nenhuma conta temporária criada nesta edição.')).toBeInTheDocument();
    });
});
