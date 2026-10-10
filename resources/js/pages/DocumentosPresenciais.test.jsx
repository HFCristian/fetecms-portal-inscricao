import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.message ?? 'Erro.', fields: e?.fields ?? {} }),
}));

const getDocumentosPresenciais = vi.fn();
const enviarTermo = vi.fn();
const removerTermo = vi.fn();
const enviarTermoImagem = vi.fn();
const definirAlunosTermoImagem = vi.fn();
const removerTermoImagem = vi.fn();
vi.mock('../lib/documentosPresenciais.js', () => ({
    getDocumentosPresenciais: (...a) => getDocumentosPresenciais(...a),
    enviarTermo: (...a) => enviarTermo(...a),
    removerTermo: (...a) => removerTermo(...a),
    enviarTermoImagem: (...a) => enviarTermoImagem(...a),
    definirAlunosTermoImagem: (...a) => definirAlunosTermoImagem(...a),
    removerTermoImagem: (...a) => removerTermoImagem(...a),
}));

import DocumentosPresenciais from './DocumentosPresenciais.jsx';

const JANELA = { aberta: true, tem_lista: true, encerrada: false, is_demo: false, max_kb: 10240 };

const ALUNOS = [{ id: 11, nome: 'Ana' }, { id: 12, nome: 'Beto' }, { id: 13, nome: 'Caio' }];

const IMAGEM_PENDENTE = {
    situacao: 'nenhum', situacao_label: 'Pendente', cobertos: 0, total: 3,
    alunos: ALUNOS.map((a) => ({ ...a, coberto: false, arquivos: [] })),
    faltando: ['Ana', 'Beto', 'Caio'],
    arquivos: [],
};

const IMAGEM_PARCIAL = {
    situacao: 'parcial', situacao_label: 'Parcial', cobertos: 1, total: 3,
    alunos: [
        { id: 11, nome: 'Ana', coberto: true, arquivos: [21] },
        { id: 12, nome: 'Beto', coberto: false, arquivos: [] },
        { id: 13, nome: 'Caio', coberto: false, arquivos: [] },
    ],
    faltando: ['Beto', 'Caio'],
    arquivos: [{
        id: 21, nome_original: 'ana.jpg', tamanho_bytes: 204800, mime: 'image/jpeg', pdf: false,
        enviado_em: '2026-10-01T10:00:00-04:00', alunos: [{ id: 11, nome: 'Ana' }],
        assinatura: { valida: null, assinado: false, signatarios: [], motivo: 'Assinatura não conferida.' },
    }],
};

const SEM_TERMO = {
    id: 3, titulo: 'Bioplástico', area: 'Exatas', categoria: 'FETECMS', termo: null,
    alunos: ALUNOS, termo_imagem: IMAGEM_PENDENTE,
};

const COM_TERMO = {
    ...SEM_TERMO,
    termo: {
        id: 9, nome_original: 'termo.pdf', tamanho_bytes: 1048576,
        enviado_em: '2026-10-01T10:00:00-04:00',
        assinatura: {
            valida: true, assinado: true, icp_brasil: true, integro: true,
            motivo: 'Assinatura digital ICP-Brasil conferida.',
            signatarios: [{ nome: 'FULANO DE TAL', cpf: '***.456.789-**', emissor: 'AC ICP-Brasil' }],
        },
    },
};

const arquivo = () => new File(['%PDF'], 'termo.pdf', { type: 'application/pdf' });

describe('DocumentosPresenciais', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        getDocumentosPresenciais.mockResolvedValue({ janela: JANELA, projetos: [SEM_TERMO] });
        enviarTermo.mockResolvedValue({ meta: { message: 'Termo de responsabilidade enviado.' } });
        removerTermo.mockResolvedValue({});
        enviarTermoImagem.mockResolvedValue({ meta: { message: 'Termo de uso de imagem enviado.' } });
        definirAlunosTermoImagem.mockResolvedValue({ meta: { message: 'Estudantes do termo atualizados.' } });
        removerTermoImagem.mockResolvedValue({ meta: { message: 'Arquivo removido.' } });
    });

    it('sem lista final publicada, explica que não há o que enviar', async () => {
        getDocumentosPresenciais.mockResolvedValue({
            janela: { ...JANELA, aberta: false, tem_lista: false },
            projetos: [],
        });
        render(<DocumentosPresenciais />);

        expect(await screen.findByText(/A lista final ainda não saiu/i)).toBeInTheDocument();
    });

    it('projeto finalista sem termo oferece o envio', async () => {
        render(<DocumentosPresenciais />);

        expect(await screen.findByText('Bioplástico')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Anexar termo$/i })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Anexar termo de imagem/i })).toBeInTheDocument();
        expect(screen.getByText(/Ainda sem termo/)).toBeInTheDocument();
    });

    it('envia o PDF e mostra a confirmação', async () => {
        render(<DocumentosPresenciais />);

        const input = await screen.findByLabelText(/Termo de responsabilidade de Bioplástico/i);
        fireEvent.change(input, { target: { files: [arquivo()] } });

        await waitFor(() => expect(enviarTermo).toHaveBeenCalledWith(3, expect.any(File), false));
        expect(await screen.findByText(/Termo de responsabilidade enviado/)).toBeInTheDocument();
    });

    it('mostra quem assinou, sem o CPF inteiro', async () => {
        getDocumentosPresenciais.mockResolvedValue({ janela: JANELA, projetos: [COM_TERMO] });
        render(<DocumentosPresenciais />);

        expect(await screen.findByText(/Assinatura digital ICP-Brasil conferida/)).toBeInTheDocument();
        expect(screen.getByText(/FULANO DE TAL/)).toBeInTheDocument();
        expect(screen.getByText(/\*\*\*\.456\.789-\*\*/)).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Substituir/i })).toBeInTheDocument();
    });

    it('termo sem assinatura digital é aceito, com aviso', async () => {
        getDocumentosPresenciais.mockResolvedValue({
            janela: JANELA,
            projetos: [{
                ...COM_TERMO,
                termo: {
                    ...COM_TERMO.termo,
                    assinatura: {
                        valida: false, assinado: false, icp_brasil: false, integro: null,
                        motivo: 'O arquivo não traz assinatura digital.', signatarios: [],
                    },
                },
            }],
        });
        render(<DocumentosPresenciais />);

        expect(await screen.findByText(/não traz assinatura digital/)).toBeInTheDocument();
        expect(screen.getByText(/apresente o original impresso na checagem do estande/i)).toBeInTheDocument();
    });

    it('remove o termo', async () => {
        getDocumentosPresenciais.mockResolvedValue({ janela: JANELA, projetos: [COM_TERMO] });
        render(<DocumentosPresenciais />);

        fireEvent.click(await screen.findByRole('button', { name: /Remover/i }));

        await waitFor(() => expect(removerTermo).toHaveBeenCalledWith(3, false));
    });

    it('mostra o erro do servidor', async () => {
        enviarTermo.mockRejectedValue({ fields: { file: 'O termo deve ser um arquivo PDF.' } });
        render(<DocumentosPresenciais />);

        const input = await screen.findByLabelText(/Termo de responsabilidade de Bioplástico/i);
        fireEvent.change(input, { target: { files: [arquivo()] } });

        expect(await screen.findByText('O termo deve ser um arquivo PDF.')).toBeInTheDocument();
    });

    it('convida a anexar antes, deixando claro que não enviar não prejudica', async () => {
        render(<DocumentosPresenciais />);

        expect(await screen.findByText('Agilize o processo!')).toBeInTheDocument();
        expect(screen.getByText(/Não conseguiu enviar\? Sem problema/)).toBeInTheDocument();
        expect(screen.getByText(/autorização de uso de imagem no credenciamento/)).toBeInTheDocument();
    });

    it('envia o termo de imagem marcando, por padrão, quem ainda falta', async () => {
        getDocumentosPresenciais.mockResolvedValue({
            janela: JANELA, projetos: [{ ...SEM_TERMO, termo_imagem: IMAGEM_PARCIAL }],
        });
        render(<DocumentosPresenciais />);

        expect(await screen.findByText(/Falta: Beto, Caio/)).toBeInTheDocument();
        expect(screen.getByText('Parcial · 1 de 3')).toBeInTheDocument();
        expect(screen.getByText('Vale para: Ana')).toBeInTheDocument();

        const input = screen.getByLabelText(/Termo de uso de imagem de Bioplástico/i);
        fireEvent.change(input, { target: { files: [new File(['x'], 'turma.png', { type: 'image/png' })] } });

        // Beto e Caio já vêm marcados; desmarca o Caio.
        expect(screen.getByRole('checkbox', { name: 'Beto' })).toBeChecked();
        expect(screen.getByRole('checkbox', { name: 'Ana' })).not.toBeChecked();
        fireEvent.click(screen.getByRole('checkbox', { name: 'Caio' }));
        fireEvent.click(screen.getByRole('button', { name: /Enviar termo/i }));

        await waitFor(() => expect(enviarTermoImagem).toHaveBeenCalledWith(3, expect.any(File), [12], false));
        expect(await screen.findByText('Termo de uso de imagem enviado.')).toBeInTheDocument();
    });

    it('não envia o termo de imagem sem estudante marcado', async () => {
        getDocumentosPresenciais.mockResolvedValue({
            janela: JANELA,
            projetos: [{ ...SEM_TERMO, termo_imagem: { ...IMAGEM_PENDENTE, alunos: IMAGEM_PENDENTE.alunos.map((a) => ({ ...a, coberto: true })) } }],
        });
        render(<DocumentosPresenciais />);

        const input = await screen.findByLabelText(/Termo de uso de imagem de Bioplástico/i);
        fireEvent.change(input, { target: { files: [new File(['x'], 'a.jpg', { type: 'image/jpeg' })] } });

        expect(screen.getByText('Marque ao menos um estudante.')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Enviar termo/i })).toBeDisabled();
    });

    it('corrige os estudantes de um arquivo e remove outro', async () => {
        getDocumentosPresenciais.mockResolvedValue({
            janela: JANELA, projetos: [{ ...SEM_TERMO, termo_imagem: IMAGEM_PARCIAL }],
        });
        render(<DocumentosPresenciais />);

        fireEvent.click(await screen.findByRole('button', { name: 'Estudantes de ana.jpg' }));
        fireEvent.click(screen.getByRole('checkbox', { name: 'Beto' }));
        fireEvent.click(screen.getByRole('button', { name: 'Salvar estudantes' }));
        await waitFor(() => expect(definirAlunosTermoImagem).toHaveBeenCalledWith(21, [11, 12], false));

        fireEvent.click(screen.getByRole('button', { name: 'Remover ana.jpg' }));
        await waitFor(() => expect(removerTermoImagem).toHaveBeenCalledWith(21, false));
    });
});
