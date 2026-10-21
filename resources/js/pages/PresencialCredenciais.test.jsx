import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
const decidirRecomendacao = vi.fn();
vi.mock('../lib/recomendacoes.js', () => ({ decidirRecomendacao: (...a) => decidirRecomendacao(...a) }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.message ?? 'Erro.', fields: e?.fields ?? {} }),
}));

const getCredenciais = vi.fn();
const criarCredencial = vi.fn();
const atualizarCredencial = vi.fn();
const excluirCredencial = vi.fn();
const atribuirCredencial = vi.fn();
const retirarCredencial = vi.fn();
const baixarPremiacao = vi.fn();
vi.mock('../lib/presencial.js', () => ({
    getCredenciais: (...a) => getCredenciais(...a),
    criarCredencial: (...a) => criarCredencial(...a),
    atualizarCredencial: (...a) => atualizarCredencial(...a),
    excluirCredencial: (...a) => excluirCredencial(...a),
    atribuirCredencial: (...a) => atribuirCredencial(...a),
    retirarCredencial: (...a) => retirarCredencial(...a),
    baixarPremiacao: (...a) => baixarPremiacao(...a),
}));

import PresencialCredenciais from './PresencialCredenciais.jsx';

const CANDIDATOS = [
    { id: 7, titulo: 'Bioplástico', categoria: 'FETECMS', area: 'Agrárias', escola: 'EE Alfa', credenciais: [] },
];

// Os dois tipos vêm do servidor (`meta.tipos`): é ele que nomeia o que a tela
// oferece, então o mock os traz como a API traz.
const TIPOS = [
    { value: 'credencial', label: 'Credencial', plural: 'Credenciais', descricao: 'Vaga que o projeto leva.' },
    { value: 'premio', label: 'Prêmio', plural: 'Prêmios', descricao: 'Reconhecimento anunciado.' },
];

const COM_VAGAS = {
    id: 1, tipo: 'credencial', tipo_label: 'Credencial',
    nome: 'MOSTRATEC 2027', orgao: 'FUNDECT', descricao: null,
    vagas: 2, usadas: 0, disponiveis: 2, ativa: true, projetos: [],
};

const SEM_TETO = {
    id: 2, tipo: 'credencial', tipo_label: 'Credencial',
    nome: 'Menção honrosa', orgao: null, descricao: null,
    vagas: null, usadas: 3, disponiveis: null, ativa: true, projetos: [],
};

describe('PresencialCredenciais', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        getCredenciais.mockResolvedValue({ data: [COM_VAGAS], meta: { candidatos: CANDIDATOS, tipos: TIPOS } });
        criarCredencial.mockResolvedValue([COM_VAGAS]);
        atribuirCredencial.mockResolvedValue({ data: [COM_VAGAS] });
        retirarCredencial.mockResolvedValue({ data: [COM_VAGAS] });
        atualizarCredencial.mockResolvedValue([COM_VAGAS]);
        excluirCredencial.mockResolvedValue([]);
    });

    it('mostra as vagas usadas e as disponíveis', async () => {
        render(<PresencialCredenciais />);

        expect(await screen.findByText(/MOSTRATEC 2027/)).toBeInTheDocument();
        expect(screen.getByText(/0 de 2 vaga\(s\)/)).toBeInTheDocument();
    });

    it('credencial sem teto não inventa número de vagas', async () => {
        getCredenciais.mockResolvedValue({ data: [SEM_TETO], meta: { candidatos: CANDIDATOS, tipos: TIPOS } });
        render(<PresencialCredenciais />);

        expect(await screen.findByText(/3 atribuída\(s\) · sem limite de vagas/)).toBeInTheDocument();
    });

    it('credencial esgotada é apontada', async () => {
        getCredenciais.mockResolvedValue({
            data: [{ ...COM_VAGAS, usadas: 2, disponiveis: 0 }],
            meta: { candidatos: CANDIDATOS, tipos: TIPOS },
        });
        render(<PresencialCredenciais />);

        expect(await screen.findByText(/2 de 2 vaga\(s\) · esgotada/)).toBeInTheDocument();
    });

    it('cadastra uma credencial nova', async () => {
        render(<PresencialCredenciais />);

        fireEvent.click(await screen.findByRole('button', { name: /Nova credencial/i }));
        fireEvent.change(screen.getByLabelText('Nome'), { target: { value: 'Feira Nacional' } });
        fireEvent.change(screen.getByLabelText('Vagas'), { target: { value: '5' } });
        fireEvent.click(screen.getByRole('button', { name: 'Cadastrar' }));

        await waitFor(() => expect(criarCredencial).toHaveBeenCalledWith({
            // Sem escolher nada, credencial: era o único tipo antes dos prêmios.
            tipo: 'credencial', nome: 'Feira Nacional', orgao: null, descricao: null, informacoes: null, vagas: 5,
        }));
    });

    it('cadastra um prêmio pelo seletor de tipo', async () => {
        render(<PresencialCredenciais />);

        fireEvent.click(await screen.findByRole('button', { name: /Nova credencial/i }));
        fireEvent.change(screen.getByLabelText('Tipo'), { target: { value: 'premio' } });
        fireEvent.change(screen.getByLabelText('Nome'), { target: { value: 'Destaque da feira' } });
        fireEvent.click(screen.getByRole('button', { name: 'Cadastrar' }));

        await waitFor(() => expect(criarCredencial).toHaveBeenCalledWith({
            tipo: 'premio', nome: 'Destaque da feira', orgao: null, descricao: null, informacoes: null, vagas: null,
        }));
    });

    // Sprint 192: o texto longo da organização, no cadastro e editável no cartão.
    it('cadastra com informações adicionais', async () => {
        render(<PresencialCredenciais />);

        fireEvent.click(await screen.findByRole('button', { name: /Nova credencial/i }));
        fireEvent.change(screen.getByLabelText('Nome'), { target: { value: 'MOSTRATEC' } });
        fireEvent.change(screen.getByLabelText('Informações adicionais'), { target: { value: '  Só ensino médio.  ' } });
        fireEvent.click(screen.getByRole('button', { name: 'Cadastrar' }));

        await waitFor(() => expect(criarCredencial).toHaveBeenCalledWith(expect.objectContaining({
            informacoes: 'Só ensino médio.',
        })));
    });

    it('nome repetido no cadastro aparece no campo e o formulário continua aberto', async () => {
        const aviso = 'Já existe uma credencial chamada “MOSTRATEC 2027” nesta edição. Escolha outro nome ou edite o item existente.';
        criarCredencial.mockRejectedValue({ fields: { nome: aviso } });
        render(<PresencialCredenciais />);

        fireEvent.click(await screen.findByRole('button', { name: /Nova credencial/i }));
        fireEvent.change(screen.getByLabelText('Nome'), { target: { value: 'MOSTRATEC 2027' } });
        fireEvent.click(screen.getByRole('button', { name: 'Cadastrar' }));

        expect((await screen.findAllByText(aviso)).length).toBeGreaterThan(0);
        expect(screen.getByRole('button', { name: 'Cadastrar' })).toBeInTheDocument();
        expect(screen.queryByText(/Erro interno/)).not.toBeInTheDocument();
    });

    // Editar abre o mesmo formulário do cadastro, já preenchido.
    it('edita o item inteiro pelo cartão', async () => {
        getCredenciais.mockResolvedValue({
            data: [{ ...COM_VAGAS, informacoes: 'Texto antigo' }],
            meta: { candidatos: CANDIDATOS, tipos: TIPOS },
        });
        render(<PresencialCredenciais />);

        expect(await screen.findByText('Texto antigo')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Editar' }));

        expect(screen.getByLabelText('Nome')).toHaveValue('MOSTRATEC 2027');
        expect(screen.getByLabelText('Vagas')).toHaveValue(2);
        fireEvent.change(screen.getByLabelText('Nome'), { target: { value: 'MOSTRATEC 2028' } });
        fireEvent.change(screen.getByLabelText('Órgão'), { target: { value: 'CNPq' } });
        fireEvent.change(screen.getByLabelText('Vagas'), { target: { value: '4' } });
        fireEvent.change(screen.getByLabelText('Descrição'), { target: { value: 'Indicação nacional' } });
        fireEvent.change(screen.getByLabelText('Informações adicionais'), { target: { value: 'Texto novo' } });
        fireEvent.click(screen.getByRole('button', { name: 'Salvar alterações' }));

        await waitFor(() => expect(atualizarCredencial).toHaveBeenCalledWith(1, {
            tipo: 'credencial', nome: 'MOSTRATEC 2028', orgao: 'CNPq', descricao: 'Indicação nacional',
            informacoes: 'Texto novo', vagas: 4,
        }));
        await waitFor(() => expect(screen.queryByRole('button', { name: 'Salvar alterações' })).not.toBeInTheDocument());
        expect(screen.getByText('Credencial atualizada.')).toBeInTheDocument();
    });

    it('vagas em branco na edição viram sem limite', async () => {
        render(<PresencialCredenciais />);

        fireEvent.click(await screen.findByRole('button', { name: 'Editar' }));
        fireEvent.change(screen.getByLabelText('Vagas'), { target: { value: '' } });
        fireEvent.click(screen.getByRole('button', { name: 'Salvar alterações' }));

        await waitFor(() => expect(atualizarCredencial).toHaveBeenCalledWith(1, expect.objectContaining({ vagas: null })));
    });

    it('não deixa as vagas ficarem abaixo dos projetos já premiados', async () => {
        getCredenciais.mockResolvedValue({
            data: [{ ...COM_VAGAS, vagas: 3, usadas: 2, disponiveis: 1 }],
            meta: { candidatos: CANDIDATOS, tipos: TIPOS },
        });
        render(<PresencialCredenciais />);

        fireEvent.click(await screen.findByRole('button', { name: 'Editar' }));
        expect(screen.getByText(/Mínimo de 2: os projetos que já receberam/)).toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Vagas'), { target: { value: '1' } });

        expect(screen.getByText(/não podem ser menos que 2/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Salvar alterações' })).toBeDisabled();

        // Igual ao número de premiados pode: a credencial só fica esgotada.
        fireEvent.change(screen.getByLabelText('Vagas'), { target: { value: '2' } });
        fireEvent.click(screen.getByRole('button', { name: 'Salvar alterações' }));

        await waitFor(() => expect(atualizarCredencial).toHaveBeenCalledWith(1, expect.objectContaining({ vagas: 2 })));
    });

    it('avisa ao trocar o tipo de um item já premiado', async () => {
        getCredenciais.mockResolvedValue({
            data: [{ ...COM_VAGAS, usadas: 1, disponiveis: 1 }],
            meta: { candidatos: CANDIDATOS, tipos: TIPOS },
        });
        render(<PresencialCredenciais />);

        fireEvent.click(await screen.findByRole('button', { name: 'Editar' }));
        expect(screen.queryByText(/sai do card de credenciais a separar/)).not.toBeInTheDocument();

        fireEvent.change(screen.getByLabelText('Tipo'), { target: { value: 'premio' } });

        expect(screen.getByText(/Este item já foi dado a 1 projeto\(s\)/)).toBeInTheDocument();
        expect(screen.getByText(/sai do card de credenciais a separar/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Salvar alterações' })).toBeEnabled();
    });

    it('recusa do servidor na edição mantém o formulário aberto com o erro no campo', async () => {
        const aviso = 'Já existe um prêmio chamado “Destaque” nesta edição. Escolha outro nome ou edite o item existente.';
        atualizarCredencial.mockRejectedValue({ fields: { nome: aviso } });
        render(<PresencialCredenciais />);

        fireEvent.click(await screen.findByRole('button', { name: 'Editar' }));
        fireEvent.change(screen.getByLabelText('Nome'), { target: { value: 'Destaque' } });
        fireEvent.click(screen.getByRole('button', { name: 'Salvar alterações' }));

        expect((await screen.findAllByText(aviso)).length).toBe(2);
        expect(screen.getByRole('button', { name: 'Salvar alterações' })).toBeInTheDocument();
        expect(screen.getByLabelText('Nome')).toHaveValue('Destaque');
    });

    it('credencial já dada não oferece excluir', async () => {
        getCredenciais.mockResolvedValue({
            data: [{
                ...COM_VAGAS, usadas: 1, disponiveis: 1,
                projetos: [{ id: 7, titulo: 'Bioplástico', categoria: 'FETECMS', area: 'Agrárias', observacao: null }],
            }],
            meta: { candidatos: CANDIDATOS, tipos: TIPOS },
        });
        render(<PresencialCredenciais />);

        expect(await screen.findByRole('button', { name: 'Retirar' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Excluir' })).not.toBeInTheDocument();
    });

    it('baixa a lista de premiação', async () => {
        render(<PresencialCredenciais />);

        fireEvent.click(await screen.findByRole('button', { name: /Lista de premiação/i }));

        expect(baixarPremiacao).toHaveBeenCalled();
    });
});
