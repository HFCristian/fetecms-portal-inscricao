import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({
    useParams: () => ({ id: '7' }),
    useNavigate: () => vi.fn(),
    Link: ({ children, to }) => <a href={to}>{children}</a>,
}));
vi.mock('../components/PrazoInscricoes.jsx', () => ({ default: () => null }));
vi.mock('../components/SubmeterRascunhoDialog.jsx', () => ({ default: () => null }));

const getResumo = vi.fn();
vi.mock('../lib/submissao.js', () => ({
    getResumo: (...a) => getResumo(...a),
    submeterProjeto: vi.fn(),
}));
vi.mock('../lib/projetos.js', () => ({ cancelarSubmissao: vi.fn(), removerProjeto: vi.fn() }));

const baixarDocumentosZip = vi.fn(() => Promise.resolve());
vi.mock('../lib/documentos.js', () => ({ baixarDocumentosZip: (...a) => baixarDocumentosZip(...a) }));

import Resumo from './Resumo.jsx';

const resumo = (documentos) => ({
    projeto: { id: 7, titulo: 'Água limpa', status: 'submetido', submitted_at: '2026-09-01T10:00:00-04:00', nomes: {}, palavras_chave: [] },
    integrantes: { alunos: [], coorientador: null },
    documentos,
    pendencias: [],
    pode_submeter: false,
    pode_desfazer: false,
    inscricoes: null,
});

const plano = { id: 1, tipo_label: 'Projeto de Pesquisa', nome_original: 'plano.pdf', download_url: '/api/v1/documentos/1/download' };

describe('Resumo — documentos do projeto submetido', () => {
    beforeEach(() => {
        getResumo.mockReset();
        baixarDocumentosZip.mockClear();
    });

    it('cada documento tem o seu link de download', async () => {
        getResumo.mockResolvedValue(resumo([plano]));
        render(<Resumo />);

        const link = await screen.findByRole('link', { name: 'Baixar plano.pdf' });
        expect(link).toHaveAttribute('href', '/api/v1/documentos/1/download');
    });

    it('baixa todos de uma vez num ZIP', async () => {
        getResumo.mockResolvedValue(resumo([plano]));
        render(<Resumo />);

        fireEvent.click(await screen.findByRole('button', { name: /Baixar todos/ }));
        await waitFor(() => expect(baixarDocumentosZip).toHaveBeenCalledWith('7'));
    });

    it('mostra a recusa do servidor em vez de falhar calado', async () => {
        getResumo.mockResolvedValue(resumo([plano]));
        baixarDocumentosZip.mockRejectedValueOnce(new Error('Este projeto não tem documentos disponíveis para baixar.'));
        render(<Resumo />);

        fireEvent.click(await screen.findByRole('button', { name: /Baixar todos/ }));
        expect(await screen.findByText(/não tem documentos disponíveis/)).toBeInTheDocument();
    });

    it('sem documento não oferece o ZIP', async () => {
        getResumo.mockResolvedValue(resumo([]));
        render(<Resumo />);

        expect(await screen.findByText('Nenhum documento anexado.')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Baixar todos/ })).not.toBeInTheDocument();
    });
});
