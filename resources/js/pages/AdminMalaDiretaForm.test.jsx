import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../lib/auth.jsx', () => ({ extractErrors: (e) => ({ message: e?.message ?? 'Erro', fields: {} }) }));

// O editor de verdade é ProseMirror (contenteditable), que o jsdom não digita:
// aqui ele vira um textarea com o mesmo contrato. O comportamento do editor em
// si é testado em EditorTexto.test.jsx.
vi.mock('../components/EditorTexto.jsx', () => ({
    default: ({ valor, onChange, onEditorPronto, placeholder }) => {
        // getHTML() precisa refletir o que insertContent acabou de escrever —
        // é assim que o editor de verdade se comporta.
        let conteudo = valor ?? '';
        const editorFalso = {
            chain: () => ({
                focus: () => ({
                    insertContent: (texto) => ({
                        run: () => { conteudo += texto; onChange(conteudo); },
                    }),
                }),
            }),
            getHTML: () => conteudo,
        };
        onEditorPronto?.(editorFalso);

        return (
            <textarea
                aria-label="Texto da mensagem"
                placeholder={placeholder}
                value={valor ?? ''}
                onChange={(e) => onChange(e.target.value)}
            />
        );
    },
}));

const subirArquivoMala = vi.fn();
const removerArquivoMala = vi.fn();
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
    variaveis: [
        { chave: 'nome', rotulo: 'Primeiro nome', descricao: 'De "Ana Souza", vira "Ana".' },
        { chave: 'nome_completo', rotulo: 'Nome completo', descricao: 'O nome como está no cadastro.' },
        { chave: 'email', rotulo: 'E-mail', descricao: 'O endereço de quem recebe.' },
    ],
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
const enviarTesteMala = vi.fn(() => Promise.resolve({ id: 9, teste: true }));

vi.mock('../lib/malaDireta.js', async (importOriginal) => {
    const real = await importOriginal();
    return {
        ...real, // mantém os parsers de CSV/e-mails colados
        getOpcoesMala: (...a) => getOpcoesMala(...a),
        getPreviaMala: (...a) => getPreviaMala(...a),
        dispararMala: (...a) => dispararMala(...a),
        exportarPreviaCsv: (...a) => exportarPreviaCsv(...a),
        enviarTesteMala: (...a) => enviarTesteMala(...a),
        subirArquivoMala: (...a) => subirArquivoMala(...a),
        removerArquivoMala: (...a) => removerArquivoMala(...a),
    };
});

import AdminMalaDiretaForm from './AdminMalaDiretaForm.jsx';

/** Preenche os campos obrigatórios da mensagem. */
function preencherMensagem() {
    fireEvent.change(screen.getByPlaceholderText('Ex.: Lembrete do prazo de submissão'), { target: { value: 'Prazo' } });
    fireEvent.change(screen.getByPlaceholderText('Por que este comunicado precisa ser enviado?'), { target: { value: 'O prazo fecha sexta.' } });
    fireEvent.change(screen.getByPlaceholderText('O que aparece na caixa de entrada'), { target: { value: 'Prazo de submissão' } });
    fireEvent.change(screen.getByLabelText('Texto da mensagem'), { target: { value: 'Olá, {{nome}}!' } });
}

describe('AdminMalaDiretaForm', () => {
    beforeEach(() => {
        navigate.mockClear();
        getPreviaMala.mockClear();
        dispararMala.mockClear();
        enviarTesteMala.mockClear();
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

    it('insere a variável no ponto do cursor do editor', async () => {
        render(<AdminMalaDiretaForm />);
        await waitFor(() => expect(screen.getByText('{{nome}}')).toBeInTheDocument());

        const corpo = screen.getByLabelText('Texto da mensagem');
        fireEvent.change(corpo, { target: { value: 'Olá, ' } });

        fireEvent.click(screen.getByText('{{nome}}'));

        await waitFor(() => expect(corpo.value).toBe('Olá, {{nome}}'));
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

describe('AdminMalaDiretaForm — anexos', () => {
    beforeEach(() => {
        subirArquivoMala.mockReset();
        removerArquivoMala.mockReset();
        dispararMala.mockClear();
        navigate.mockClear();
        getPreviaMala.mockResolvedValue(previaPadrao);
        dispararMala.mockResolvedValue({ id: 7 });
        removerArquivoMala.mockResolvedValue({});
    });

    it('sobe o anexo, lista o arquivo e manda o id no disparo', async () => {
        subirArquivoMala.mockResolvedValue({ id: 42, tipo: 'anexo', nome: 'edital.pdf', tamanho_bytes: 2_500_000 });

        render(<AdminMalaDiretaForm />);
        await waitFor(() => expect(screen.getByText('Todos os usuários')).toBeInTheDocument());
        fireEvent.click(screen.getByText('Todos os usuários'));
        await waitFor(() => expect(screen.getByText('e-mail será enviado')).toBeInTheDocument());
        preencherMensagem();

        const arquivo = new File(['x'], 'edital.pdf', { type: 'application/pdf' });
        fireEvent.change(screen.getByLabelText('Escolher anexo'), { target: { files: [arquivo] } });

        await waitFor(() => expect(subirArquivoMala).toHaveBeenCalledWith(arquivo, 'anexo'));
        expect(await screen.findByText('edital.pdf')).toBeInTheDocument();
        expect(screen.getByText('2,4 MB')).toBeInTheDocument();

        fireEvent.click(screen.getByText('Enviar mensagem'));
        await waitFor(() => expect(screen.getByText('Confirmar o envio')).toBeInTheDocument());
        fireEvent.click(screen.getByText('Enviar agora'));

        await waitFor(() => expect(dispararMala).toHaveBeenCalledWith(expect.objectContaining({
            formato: 'html',
            anexos: [42],
            imagens: [],
        })));
    });

    it('remove o anexo da lista', async () => {
        subirArquivoMala.mockResolvedValue({ id: 42, tipo: 'anexo', nome: 'edital.pdf', tamanho_bytes: 1000 });

        render(<AdminMalaDiretaForm />);
        await waitFor(() => expect(screen.getByText('Todos os usuários')).toBeInTheDocument());

        fireEvent.change(screen.getByLabelText('Escolher anexo'), {
            target: { files: [new File(['x'], 'edital.pdf', { type: 'application/pdf' })] },
        });
        expect(await screen.findByText('edital.pdf')).toBeInTheDocument();

        fireEvent.click(screen.getByLabelText('Remover edital.pdf'));

        await waitFor(() => expect(removerArquivoMala).toHaveBeenCalledWith(42));
        expect(screen.queryByText('edital.pdf')).not.toBeInTheDocument();
    });

    it('manda o teste só para os endereços digitados, sem público nenhum', async () => {
        render(<AdminMalaDiretaForm />);
        await waitFor(() => expect(screen.getByText('Todos os usuários')).toBeInTheDocument());
        preencherMensagem();

        fireEvent.click(screen.getByLabelText(/Enviar e-mail de teste para/i, { selector: 'input' }));
        fireEvent.change(screen.getByLabelText('E-mails que recebem o teste'), {
            target: { value: 'ana@fetecms.test, pedro@fetecms.test' },
        });

        expect(screen.getByText(/2 de 10 endereços/)).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /Enviar teste/i }));

        await waitFor(() => expect(enviarTesteMala).toHaveBeenCalledTimes(1));
        expect(enviarTesteMala).toHaveBeenCalledWith(expect.objectContaining({
            assunto: 'Prazo de submissão',
            formato: 'html',
            destinatarios: [
                { email: 'ana@fetecms.test', nome: '' },
                { email: 'pedro@fetecms.test', nome: '' },
            ],
        }));
        // O teste não é o disparo: a mala de verdade continua por fazer.
        expect(dispararMala).not.toHaveBeenCalled();
        expect(navigate).not.toHaveBeenCalled();
        await waitFor(() => expect(screen.getByText(/Teste na fila para 2 endereço/)).toBeInTheDocument());
    });
});
