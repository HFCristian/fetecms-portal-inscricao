import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../lib/auth.jsx', () => ({ extractErrors: (e) => ({ message: e?.message ?? 'Erro', fields: {} }) }));

const navigate = vi.fn();
vi.mock('react-router-dom', () => ({
    Link: ({ children, to }) => <a href={to}>{children}</a>,
    useNavigate: () => navigate,
}));

const OPCOES = {
    publicos: [
        { value: 'todos', label: 'Todos os usuários', descricao: 'Orientadores e avaliadores com conta ativa.' },
        { value: 'orientadores_rascunho', label: 'Orientadores com projeto em rascunho', descricao: 'Tem ao menos um projeto ainda em rascunho.' },
    ],
    situacoes: [],
    max_personalizados: 5000,
};

const previaPadrao = {
    data: [
        { email: 'ana@escola.test', nome: 'Ana Souza', origens: ['todos'], projetos_total: 2, status: 'pendente', erro: null },
    ],
    meta: { total: 1, validos: 1, invalidos: 0, por_publico: { todos: 1 }, pagina_atual: 1, por_pagina: 25, ultima_pagina: 1 },
};

const getOpcoesMala = vi.fn(() => Promise.resolve(OPCOES));
const getPreviaMala = vi.fn(() => Promise.resolve(previaPadrao));
const dispararMala = vi.fn(() => Promise.resolve({ id: 7 }));
const exportarPreviaCsv = vi.fn(() => Promise.resolve());

vi.mock('../lib/malaDireta.js', async (importOriginal) => {
    const real = await importOriginal();
    return {
        ...real, // mantém os parsers de CSV/e-mails colados
        getOpcoesMala: (...a) => getOpcoesMala(...a),
        getPreviaMala: (...a) => getPreviaMala(...a),
        dispararMala: (...a) => dispararMala(...a),
        exportarPreviaCsv: (...a) => exportarPreviaCsv(...a),
    };
});

import AdminMalaDiretaForm from './AdminMalaDiretaForm.jsx';

/** Preenche os campos obrigatórios da mensagem. */
function preencherMensagem() {
    fireEvent.change(screen.getByPlaceholderText('Ex.: Lembrete do prazo de submissão'), { target: { value: 'Prazo' } });
    fireEvent.change(screen.getByPlaceholderText('Por que este comunicado precisa ser enviado?'), { target: { value: 'O prazo fecha sexta.' } });
    fireEvent.change(screen.getByPlaceholderText('O que aparece na caixa de entrada'), { target: { value: 'Prazo de submissão' } });
    fireEvent.change(screen.getByPlaceholderText(/Escreva aqui o comunicado/), { target: { value: 'Olá, {{nome}}!' } });
}

describe('AdminMalaDiretaForm', () => {
    beforeEach(() => {
        navigate.mockClear();
        getPreviaMala.mockClear();
        dispararMala.mockClear();
        getPreviaMala.mockResolvedValue(previaPadrao);
    });

    it('só pede a prévia depois que um público é marcado', async () => {
        render(<AdminMalaDiretaForm />);
        await waitFor(() => expect(screen.getByText('Todos os usuários')).toBeInTheDocument());

        expect(screen.getByText(/Escolha ao menos um público/)).toBeInTheDocument();
        expect(getPreviaMala).not.toHaveBeenCalled();

        fireEvent.click(screen.getByText('Todos os usuários'));

        await waitFor(() => expect(screen.getByText('e-mail será enviado')).toBeInTheDocument());
        expect(getPreviaMala).toHaveBeenCalledWith(expect.objectContaining({ publicos: ['todos'] }));
    });

    it('mostra a contagem de cada público no próprio cartão', async () => {
        render(<AdminMalaDiretaForm />);
        await waitFor(() => expect(screen.getByText('Todos os usuários')).toBeInTheDocument());
        fireEvent.click(screen.getByText('Todos os usuários'));

        await waitFor(() => expect(screen.getByText('1 pessoa')).toBeInTheDocument());
    });

    it('adiciona à lista os e-mails digitados, aceitando "Nome <email>"', async () => {
        render(<AdminMalaDiretaForm />);
        await waitFor(() => expect(screen.getByText('Todos os usuários')).toBeInTheDocument());

        fireEvent.change(screen.getByPlaceholderText(/ana@escola.test/), {
            target: { value: 'ana@escola.test\nBeto Lima <beto@escola.test>' },
        });
        fireEvent.click(screen.getByText('Adicionar à lista'));

        await waitFor(() => expect(screen.getByText('2 na lista personalizada')).toBeInTheDocument());
        expect(screen.getByText(/Beto Lima · beto@escola.test/)).toBeInTheDocument();
        await waitFor(() => expect(getPreviaMala).toHaveBeenCalledWith(expect.objectContaining({
            destinatarios: [
                { email: 'ana@escola.test', nome: '' },
                { email: 'beto@escola.test', nome: 'Beto Lima' },
            ],
        })));
    });

    it('avisa quantos e-mails são inválidos e não os conta no envio', async () => {
        getPreviaMala.mockResolvedValue({
            data: [{ email: 'sem-arroba', nome: null, origens: ['personalizado'], projetos_total: 0, status: 'invalido', erro: 'Endereço de e-mail inválido.' }],
            meta: { total: 2, validos: 1, invalidos: 1, por_publico: {}, pagina_atual: 1, por_pagina: 25, ultima_pagina: 1 },
        });
        render(<AdminMalaDiretaForm />);
        await waitFor(() => expect(screen.getByText('Todos os usuários')).toBeInTheDocument());
        fireEvent.click(screen.getByText('Todos os usuários'));

        await waitFor(() => expect(screen.getByText(/inválido\(s\) — não serão enviados/)).toBeInTheDocument());
        expect(screen.getByText('e-mail será enviado')).toBeInTheDocument();
    });

    it('pede confirmação da mensagem antes de disparar', async () => {
        render(<AdminMalaDiretaForm />);
        await waitFor(() => expect(screen.getByText('Todos os usuários')).toBeInTheDocument());
        fireEvent.click(screen.getByText('Todos os usuários'));
        await waitFor(() => expect(screen.getByText('e-mail será enviado')).toBeInTheDocument());
        preencherMensagem();

        fireEvent.click(screen.getByText('Enviar mensagem'));

        // A caixa mostra o que será enviado e para quantas pessoas.
        await waitFor(() => expect(screen.getByText('Confirmar o envio')).toBeInTheDocument());
        expect(screen.getByText(/Assunto: Prazo de submissão/)).toBeInTheDocument();
        expect(screen.getByText(/Destinatários: 1/)).toBeInTheDocument();
        expect(dispararMala).not.toHaveBeenCalled();

        fireEvent.click(screen.getByText('Enviar agora'));

        await waitFor(() => expect(dispararMala).toHaveBeenCalledWith(expect.objectContaining({
            nome: 'Prazo',
            assunto: 'Prazo de submissão',
            corpo: 'Olá, {{nome}}!',
            publicos: ['todos'],
        })));
        // Vai direto para a tela de progresso da mala criada.
        expect(navigate).toHaveBeenCalledWith('/admin/mala-direta/7');
    });

    it('não dispara nada se a confirmação for cancelada', async () => {
        render(<AdminMalaDiretaForm />);
        await waitFor(() => expect(screen.getByText('Todos os usuários')).toBeInTheDocument());
        fireEvent.click(screen.getByText('Todos os usuários'));
        await waitFor(() => expect(screen.getByText('e-mail será enviado')).toBeInTheDocument());
        preencherMensagem();

        fireEvent.click(screen.getByText('Enviar mensagem'));
        await waitFor(() => expect(screen.getByText('Confirmar o envio')).toBeInTheDocument());
        // Há dois "Cancelar" na tela (o do formulário e o da caixa): usa o da caixa.
        fireEvent.click(within(screen.getByRole('dialog')).getByText('Cancelar'));

        await waitFor(() => expect(screen.queryByText('Confirmar o envio')).not.toBeInTheDocument());
        expect(dispararMala).not.toHaveBeenCalled();
        expect(navigate).not.toHaveBeenCalled();
    });

    it('lista os destinatários da prévia sob demanda', async () => {
        render(<AdminMalaDiretaForm />);
        await waitFor(() => expect(screen.getByText('Todos os usuários')).toBeInTheDocument());
        fireEvent.click(screen.getByText('Todos os usuários'));
        await waitFor(() => expect(screen.getByText('e-mail será enviado')).toBeInTheDocument());

        fireEvent.click(screen.getByText('Listar e-mails'));

        expect(screen.getByText('ana@escola.test')).toBeInTheDocument();
        expect(screen.getByText('Ana Souza')).toBeInTheDocument();
    });
});
