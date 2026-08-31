import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const getModelosEmail = vi.fn();
const salvarModeloEmail = vi.fn();
const restaurarModeloEmail = vi.fn();

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));

// O editor de verdade é ProseMirror (contenteditable), que o jsdom não digita:
// aqui ele vira um textarea com o mesmo contrato, como no teste da mala direta.
// O comportamento do editor em si é testado em EditorTexto.test.jsx.
vi.mock('../components/EditorTexto.jsx', () => ({
    default: ({ valor, onChange, onEditorPronto, permitirImagens }) => {
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
                data-imagens={String(permitirImagens ?? true)}
                value={valor ?? ''}
                onChange={(e) => onChange(e.target.value)}
            />
        );
    },
}));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));
vi.mock('../lib/admin.js', () => ({
    getModelosEmail: (...a) => getModelosEmail(...a),
    salvarModeloEmail: (...a) => salvarModeloEmail(...a),
    restaurarModeloEmail: (...a) => restaurarModeloEmail(...a),
}));

import AdminModelosEmail from './AdminModelosEmail.jsx';

const MODELO = {
    chave: 'confirmacao_cadastro',
    label: 'Confirmação de cadastro',
    descricao: 'Vai para quem acabou de preencher o cadastro.',
    assunto: 'Confirme seu e-mail',
    corpo: 'Olá, {{nome}}!',
    personalizado: false,
    autor_nome: null,
    variaveis: [
        { chave: 'nome', descricao: 'Primeiro nome' },
        { chave: 'codigo', descricao: 'O código de 6 dígitos' },
    ],
};

describe('AdminModelosEmail', () => {
    beforeEach(() => {
        getModelosEmail.mockResolvedValue([MODELO]);
        salvarModeloEmail.mockReset();
        restaurarModeloEmail.mockReset();
    });

    it('lista os modelos e diz quando o texto ainda é o padrão', async () => {
        render(<AdminModelosEmail />);
        expect(await screen.findByText('Confirmação de cadastro')).toBeInTheDocument();
        expect(screen.getByText('Usando o texto padrão da FETECMS.')).toBeInTheDocument();
        // O editor só abre no clique.
        expect(screen.queryByText('Salvar modelo')).not.toBeInTheDocument();
    });

    it('abre o editor, insere variável no cursor e salva em HTML', async () => {
        salvarModeloEmail.mockResolvedValue({
            ...MODELO, personalizado: true, autor_nome: 'Ana', formato: 'html',
        });
        render(<AdminModelosEmail />);

        fireEvent.click(await screen.findByText('Editar mensagem'));

        const corpo = screen.getByLabelText('Texto da mensagem');
        fireEvent.change(corpo, { target: { value: '<p>Olá!</p>' } });
        fireEvent.click(screen.getByText('{{codigo}}'));

        await waitFor(() => expect(screen.getByDisplayValue('<p>Olá!</p>{{codigo}}')).toBeInTheDocument());

        fireEvent.click(screen.getByText('Salvar modelo'));

        await waitFor(() => expect(salvarModeloEmail).toHaveBeenCalledWith('confirmacao_cadastro', {
            assunto: 'Confirme seu e-mail',
            corpo: '<p>Olá!</p>{{codigo}}',
            formato: 'html',
        }));
        expect(await screen.findByText(/Texto personalizado por Ana/)).toBeInTheDocument();
    });

    /** O texto guardado antes da Sprint 91 abre já formatado, não como um bloco só. */
    it('converte o texto puro em parágrafos ao abrir o editor', async () => {
        getModelosEmail.mockResolvedValue([{
            ...MODELO,
            formato: 'texto',
            corpo: 'Olá, {{nome}}!\n\nSeu código:\n{{codigo}}',
        }]);
        render(<AdminModelosEmail />);

        fireEvent.click(await screen.findByText('Editar mensagem'));

        expect(screen.getByLabelText('Texto da mensagem')).toHaveValue(
            '<p>Olá, {{nome}}!</p><p>Seu código:<br>{{codigo}}</p>',
        );
    });

    /** Estes e-mails são transacionais e curtos: não há arquivo para subir. */
    it('não oferece imagens no corpo', async () => {
        render(<AdminModelosEmail />);
        fireEvent.click(await screen.findByText('Editar mensagem'));

        expect(screen.getByLabelText('Texto da mensagem')).toHaveAttribute('data-imagens', 'false');
    });

    /** Editor vazio é `<p></p>`, não string vazia — o botão precisa saber disso. */
    it('não deixa salvar com o corpo vazio', async () => {
        render(<AdminModelosEmail />);
        fireEvent.click(await screen.findByText('Editar mensagem'));

        fireEvent.change(screen.getByLabelText('Texto da mensagem'), { target: { value: '<p></p>' } });
        expect(screen.getByText('Salvar modelo')).toBeDisabled();
    });

    it('só oferece restaurar quando o texto foi personalizado', async () => {
        getModelosEmail.mockResolvedValue([{ ...MODELO, personalizado: true, autor_nome: 'Ana' }]);
        render(<AdminModelosEmail />);

        fireEvent.click(await screen.findByText('Editar mensagem'));
        expect(screen.getByText('Restaurar padrão')).toBeInTheDocument();
    });
});
