import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.message ?? 'Erro.', fields: e?.fields ?? {} }),
}));

const getDocumentosPresenciais = vi.fn();
const enviarArquivoItem = vi.fn();
const definirPessoasArquivo = vi.fn();
const removerArquivo = vi.fn();
vi.mock('../lib/documentosPresenciais.js', () => ({
    getDocumentosPresenciais: (...a) => getDocumentosPresenciais(...a),
    enviarArquivoItem: (...a) => enviarArquivoItem(...a),
    definirPessoasArquivo: (...a) => definirPessoasArquivo(...a),
    removerArquivo: (...a) => removerArquivo(...a),
}));

import DocumentosPresenciais from './DocumentosPresenciais.jsx';

const JANELA = { aberta: true, tem_lista: true, encerrada: false, is_demo: false, max_kb: 10240 };

const PESSOAS = [
    { chave: 'aluno:11', tipo: 'aluno', tipo_label: 'Aluno', id: 11, nome: 'Ana' },
    { chave: 'aluno:12', tipo: 'aluno', tipo_label: 'Aluno', id: 12, nome: 'Beto' },
    { chave: 'aluno:13', tipo: 'aluno', tipo_label: 'Aluno', id: 13, nome: 'Caio' },
];

const ASSINADO = {
    valida: true, assinado: true, icp_brasil: true, integro: true,
    motivo: 'Assinatura digital ICP-Brasil conferida.',
    signatarios: [{ nome: 'FULANO DE TAL', cpf: '***.456.789-**', emissor: 'AC ICP-Brasil' }],
};

const RESPONSABILIDADE = {
    id: 1, nome: 'Termo de responsabilidade', descricao: null, abrangencia: 'projeto', chave: 'termo_responsabilidade',
    pessoas: [], situacao: 'nenhum', situacao_label: 'Pendente', cobertos: 0, total: 1, faltando: [], arquivos: [],
};

const IMAGEM = {
    id: 2, nome: 'Termo de uso de imagem', descricao: 'Assinado pelos responsáveis.', abrangencia: 'pessoa',
    chave: 'termo_uso_imagem', pessoas: PESSOAS, situacao: 'parcial', situacao_label: 'Parcial',
    cobertos: 1, total: 3, faltando: ['Beto', 'Caio'],
    arquivos: [{
        id: 21, nome_original: 'ana.jpg', tamanho_bytes: 204800, mime: 'image/jpeg', pdf: false,
        enviado_em: '2026-10-01T10:00:00-04:00', pessoas: ['aluno:11'],
        assinatura: { valida: null, assinado: false, signatarios: [], motivo: 'Assinatura não conferida.' },
    }],
};

const projeto = (itens) => ({ id: 3, titulo: 'Bioplástico', area: 'Exatas', categoria: 'FETECMS', itens });

const arquivo = (nome = 'termo.pdf', type = 'application/pdf') => new File(['%PDF'], nome, { type });

describe('DocumentosPresenciais', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        getDocumentosPresenciais.mockResolvedValue({ janela: JANELA, projetos: [projeto([RESPONSABILIDADE, IMAGEM])] });
        enviarArquivoItem.mockResolvedValue({ meta: { message: 'Termo de responsabilidade: arquivo enviado.' } });
        definirPessoasArquivo.mockResolvedValue({ meta: { message: 'Pessoas do arquivo atualizadas.' } });
        removerArquivo.mockResolvedValue({ meta: { message: 'Arquivo removido.' } });
    });

    it('sem lista final publicada, explica que não há o que enviar', async () => {
        getDocumentosPresenciais.mockResolvedValue({ janela: { ...JANELA, aberta: false, tem_lista: false }, projetos: [] });
        render(<DocumentosPresenciais />);

        expect(await screen.findByText(/A lista final ainda não saiu/i)).toBeInTheDocument();
    });

    it('convida a anexar antes, deixando claro que não enviar não prejudica', async () => {
        render(<DocumentosPresenciais />);

        expect(await screen.findByText('Agilize o processo!')).toBeInTheDocument();
        expect(screen.getByText(/Não conseguiu enviar\? Sem problema/)).toBeInTheDocument();
    });

    it('mostra uma seção por item pedido pela organização, com a situação de cada um', async () => {
        render(<DocumentosPresenciais />);

        const responsabilidade = await screen.findByRole('region', { name: 'Termo de responsabilidade — Bioplástico' });
        expect(within(responsabilidade).getByText('Pendente')).toBeInTheDocument();

        const imagem = screen.getByRole('region', { name: 'Termo de uso de imagem — Bioplástico' });
        expect(within(imagem).getByText('Assinado pelos responsáveis.')).toBeInTheDocument();
        expect(within(imagem).getByText('Parcial · 1 de 3')).toBeInTheDocument();
        expect(within(imagem).getByText('Falta: Beto, Caio')).toBeInTheDocument();
        expect(within(imagem).getByText('Vale para: Ana')).toBeInTheDocument();
    });

    it('envia o arquivo do item do projeto sem pedir pessoas', async () => {
        render(<DocumentosPresenciais />);

        const input = await screen.findByLabelText('Termo de responsabilidade de Bioplástico');
        fireEvent.change(input, { target: { files: [arquivo()] } });
        fireEvent.click(screen.getByRole('button', { name: /Enviar arquivo/i }));

        await waitFor(() => expect(enviarArquivoItem).toHaveBeenCalledWith(3, 1, expect.any(File), [], false));
        expect(await screen.findByText('Termo de responsabilidade: arquivo enviado.')).toBeInTheDocument();
    });

    it('no item de pessoa, quem falta já vem marcado e dá para desmarcar', async () => {
        render(<DocumentosPresenciais />);

        const input = await screen.findByLabelText('Termo de uso de imagem de Bioplástico');
        fireEvent.change(input, { target: { files: [arquivo('turma.png', 'image/png')] } });

        const imagem = screen.getByRole('region', { name: 'Termo de uso de imagem — Bioplástico' });
        expect(within(imagem).getByRole('checkbox', { name: 'Beto' })).toBeChecked();
        expect(within(imagem).getByRole('checkbox', { name: 'Ana' })).not.toBeChecked();
        fireEvent.click(within(imagem).getByRole('checkbox', { name: 'Caio' }));
        fireEvent.click(within(imagem).getByRole('button', { name: /Enviar arquivo/i }));

        await waitFor(() => expect(enviarArquivoItem).toHaveBeenCalledWith(3, 2, expect.any(File), ['aluno:12'], false));
    });

    it('item de pessoa não envia sem ninguém marcado', async () => {
        getDocumentosPresenciais.mockResolvedValue({
            janela: JANELA, projetos: [projeto([{ ...IMAGEM, faltando: [] }])],
        });
        render(<DocumentosPresenciais />);

        const input = await screen.findByLabelText('Termo de uso de imagem de Bioplástico');
        fireEvent.change(input, { target: { files: [arquivo('a.jpg', 'image/jpeg')] } });

        expect(screen.getByText('Marque ao menos uma pessoa.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Enviar arquivo/i })).toBeDisabled();
    });

    it('corrige as pessoas de um arquivo e remove outro', async () => {
        render(<DocumentosPresenciais />);

        fireEvent.click(await screen.findByRole('button', { name: 'Pessoas de ana.jpg' }));
        fireEvent.click(screen.getByRole('checkbox', { name: 'Beto' }));
        fireEvent.click(screen.getByRole('button', { name: 'Salvar pessoas' }));
        await waitFor(() => expect(definirPessoasArquivo).toHaveBeenCalledWith(21, ['aluno:11', 'aluno:12'], false));

        fireEvent.click(screen.getByRole('button', { name: 'Remover ana.jpg' }));
        await waitFor(() => expect(removerArquivo).toHaveBeenCalledWith(21, false));
    });

    it('no termo de responsabilidade em PDF, o laudo da assinatura aparece', async () => {
        getDocumentosPresenciais.mockResolvedValue({
            janela: JANELA,
            projetos: [projeto([{
                ...RESPONSABILIDADE, situacao: 'completo', situacao_label: 'Enviado', cobertos: 1,
                arquivos: [{ id: 9, nome_original: 'termo.pdf', tamanho_bytes: 1048576, pdf: true, enviado_em: '2026-10-01T10:00:00-04:00', pessoas: [], assinatura: ASSINADO }],
            }])],
        });
        render(<DocumentosPresenciais />);

        expect(await screen.findByText(/Assinatura digital ICP-Brasil conferida/)).toBeInTheDocument();
        expect(screen.getByText(/FULANO DE TAL/)).toBeInTheDocument();
        expect(screen.getByText(/\*\*\*\.456\.789-\*\*/)).toBeInTheDocument();
    });

    it('mostra o erro do servidor', async () => {
        enviarArquivoItem.mockRejectedValue({ fields: { file: 'Envie o arquivo em PDF, JPG ou PNG.' } });
        render(<DocumentosPresenciais />);

        const input = await screen.findByLabelText('Termo de responsabilidade de Bioplástico');
        fireEvent.change(input, { target: { files: [arquivo('termo.docx', 'application/msword')] } });
        fireEvent.click(screen.getByRole('button', { name: /Enviar arquivo/i }));

        expect(await screen.findByText('Envie o arquivo em PDF, JPG ou PNG.')).toBeInTheDocument();
    });
});
