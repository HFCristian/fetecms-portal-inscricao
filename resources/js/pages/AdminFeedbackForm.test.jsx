import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
const navigate = vi.fn();
vi.mock('react-router-dom', () => ({
    Link: ({ children, to }) => <a href={to}>{children}</a>,
    useNavigate: () => navigate,
}));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({
        message: e?.response?.data?.message ?? '',
        fields: e?.response?.data?.errors ?? {},
    }),
}));

const getOpcoesFeedback = vi.fn();
const criarFeedback = vi.fn();
vi.mock('../lib/feedback.js', () => ({
    getOpcoesFeedback: (...a) => getOpcoesFeedback(...a),
    criarFeedback: (...a) => criarFeedback(...a),
}));

import AdminFeedbackForm from './AdminFeedbackForm.jsx';

const opcoes = {
    publicos: [
        { value: 'orientadores', label: 'Todos os orientadores', descricao: 'Quem se cadastrou como orientador.' },
        { value: 'avaliadores', label: 'Todos os avaliadores', descricao: 'Quem se cadastrou como avaliador.' },
    ],
    modelos: [
        { value: 'satisfacao', label: 'Satisfação (5 pontos)', opcoes: ['Muito insatisfeito', 'Insatisfeito', 'Neutro', 'Satisfeito', 'Muito satisfeito'] },
        { value: 'sim_nao', label: 'Sim / Não', opcoes: ['Sim', 'Não'] },
    ],
    max_perguntas: 20,
    max_opcoes: 12,
};

describe('AdminFeedbackForm', () => {
    beforeEach(() => {
        getOpcoesFeedback.mockReset().mockResolvedValue(opcoes);
        criarFeedback.mockReset().mockResolvedValue({ data: { id: 5 } });
        navigate.mockClear();
    });

    it('oferece os públicos combináveis e os modelos de alternativas', async () => {
        render(<AdminFeedbackForm />);

        expect(await screen.findByText('Todos os orientadores')).toBeInTheDocument();
        expect(screen.getByText('Todos os avaliadores')).toBeInTheDocument();
        expect(screen.getByLabelText('Modelo de alternativas da pergunta 1')).toBeInTheDocument();
        // O modelo escolhido mostra as opções que ele traz.
        expect(screen.getByText(/Muito insatisfeito · Insatisfeito/)).toBeInTheDocument();
    });

    it('mostra o motivo e deixa tentar de novo quando as opções não carregam', async () => {
        // Regressão: a falha era engolida e a tela desenhava a caixa "Quem você
        // quer ouvir" sem nenhuma opção, sem dizer o que havia acontecido.
        getOpcoesFeedback.mockRejectedValueOnce({
            response: { data: { message: 'Seu escopo de administrador não inclui esta área do portal.' } },
        });

        render(<AdminFeedbackForm />);

        expect(await screen.findByText('Seu escopo de administrador não inclui esta área do portal.')).toBeInTheDocument();
        expect(screen.queryByRole('group', { name: 'Públicos do feedback' })).not.toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /Tentar de novo/ }));

        expect(await screen.findByText('Todos os orientadores')).toBeInTheDocument();
    });

    it('avisa quando o servidor responde sem nenhum público', async () => {
        getOpcoesFeedback.mockResolvedValueOnce({ ...opcoes, publicos: [] });

        render(<AdminFeedbackForm />);

        expect(await screen.findByText(/Nenhum público chegou do servidor/)).toBeInTheDocument();
    });

    it('publica com um modelo pronto, mandando a chave e não as opções', async () => {
        render(<AdminFeedbackForm />);

        fireEvent.change(await screen.findByLabelText('Título do feedback'), {
            target: { value: 'Como foi a feira?' },
        });
        fireEvent.click(screen.getByLabelText(/Todos os orientadores/));
        fireEvent.change(screen.getByLabelText('Enunciado da pergunta 1'), {
            target: { value: 'Como você avalia a organização?' },
        });
        fireEvent.click(screen.getByText('Publicar e enviar convites'));

        await waitFor(() => expect(criarFeedback).toHaveBeenCalledWith(expect.objectContaining({
            titulo: 'Como foi a feira?',
            publicos: ['orientadores'],
            perguntas: [expect.objectContaining({
                tipo: 'alternativa',
                enunciado: 'Como você avalia a organização?',
                modelo: 'satisfacao',
                opcoes: [],
            })],
        })));

        // Vai direto para os resultados do pedido recém-criado.
        expect(navigate).toHaveBeenCalledWith('/admin/comunicacao/feedback/5');
    });

    it('deixa escrever as alternativas à mão', async () => {
        render(<AdminFeedbackForm />);

        fireEvent.change(await screen.findByLabelText('Modelo de alternativas da pergunta 1'), {
            target: { value: '' },
        });

        fireEvent.change(screen.getByLabelText('Alternativa 1 da pergunta 1'), { target: { value: 'Sim' } });
        fireEvent.change(screen.getByLabelText('Alternativa 2 da pergunta 1'), { target: { value: 'Não' } });
        fireEvent.click(screen.getByText('Adicionar alternativa'));
        fireEvent.change(screen.getByLabelText('Alternativa 3 da pergunta 1'), { target: { value: 'Talvez' } });

        fireEvent.change(screen.getByLabelText('Título do feedback'), { target: { value: 'X' } });
        fireEvent.click(screen.getByLabelText(/Todos os orientadores/));
        fireEvent.change(screen.getByLabelText('Enunciado da pergunta 1'), { target: { value: 'Vai voltar?' } });
        fireEvent.click(screen.getByText('Publicar e enviar convites'));

        await waitFor(() => expect(criarFeedback).toHaveBeenCalledWith(expect.objectContaining({
            perguntas: [expect.objectContaining({ modelo: null, opcoes: ['Sim', 'Não', 'Talvez'] })],
        })));
    });

    it('a dissertativa troca as alternativas pelo limite em palavras ou caracteres', async () => {
        render(<AdminFeedbackForm />);

        fireEvent.change(await screen.findByLabelText('Tipo da pergunta 1'), {
            target: { value: 'dissertativa' },
        });

        expect(screen.queryByLabelText('Modelo de alternativas da pergunta 1')).not.toBeInTheDocument();
        fireEvent.change(screen.getByLabelText('Unidade do limite da pergunta 1'), {
            target: { value: 'caracteres' },
        });
        fireEvent.change(screen.getByLabelText('Mínimo da pergunta 1'), { target: { value: '20' } });
        fireEvent.change(screen.getByLabelText('Máximo da pergunta 1'), { target: { value: '500' } });

        fireEvent.change(screen.getByLabelText('Título do feedback'), { target: { value: 'X' } });
        fireEvent.click(screen.getByLabelText(/Todos os orientadores/));
        fireEvent.change(screen.getByLabelText('Enunciado da pergunta 1'), { target: { value: 'Comente' } });
        fireEvent.click(screen.getByText('Publicar e enviar convites'));

        await waitFor(() => expect(criarFeedback).toHaveBeenCalledWith(expect.objectContaining({
            perguntas: [expect.objectContaining({
                tipo: 'dissertativa', unidade: 'caracteres', minimo: 20, maximo: 500,
            })],
        })));
    });

    it('adiciona e remove perguntas', async () => {
        render(<AdminFeedbackForm />);

        fireEvent.click(await screen.findByText('Adicionar pergunta'));
        expect(screen.getByText('Pergunta 2')).toBeInTheDocument();

        fireEvent.click(screen.getByLabelText('Remover a pergunta 2'));
        expect(screen.queryByText('Pergunta 2')).not.toBeInTheDocument();
        // A última não some: um questionário precisa de ao menos uma pergunta.
        expect(screen.queryByLabelText('Remover a pergunta 1')).not.toBeInTheDocument();
    });

    it('mostra os erros de validação do backend', async () => {
        criarFeedback.mockRejectedValue({
            response: {
                data: {
                    message: 'Confira os campos.',
                    errors: { titulo: 'O título é obrigatório.', publicos: 'Escolha ao menos um público.' },
                },
            },
        });
        render(<AdminFeedbackForm />);

        fireEvent.click(await screen.findByText('Publicar e enviar convites'));

        expect(await screen.findByText('Confira os campos.')).toBeInTheDocument();
        expect(screen.getByText('O título é obrigatório.')).toBeInTheDocument();
        expect(screen.getByText('Escolha ao menos um público.')).toBeInTheDocument();
    });
});
