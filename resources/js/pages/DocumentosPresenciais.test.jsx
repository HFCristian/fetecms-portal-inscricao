import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.message ?? 'Erro.', fields: e?.fields ?? {} }),
}));

const getDocumentosPresenciais = vi.fn();
const enviarTermo = vi.fn();
const removerTermo = vi.fn();
vi.mock('../lib/documentosPresenciais.js', () => ({
    getDocumentosPresenciais: (...a) => getDocumentosPresenciais(...a),
    enviarTermo: (...a) => enviarTermo(...a),
    removerTermo: (...a) => removerTermo(...a),
}));

import DocumentosPresenciais from './DocumentosPresenciais.jsx';

const JANELA = { aberta: true, tem_lista: true, encerrada: false, is_demo: false, max_kb: 10240 };

const SEM_TERMO = { id: 3, titulo: 'Bioplástico', area: 'Exatas', categoria: 'FETECMS', termo: null };

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
        expect(screen.getByRole('button', { name: /Anexar termo/i })).toBeInTheDocument();
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
        expect(screen.getByText(/leve o original impresso no credenciamento/i)).toBeInTheDocument();
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
});
