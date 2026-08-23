import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ useNavigate: () => vi.fn() }));

// O diálogo de confirmação sempre confirma, para o teste chegar na chamada da API.
vi.mock('../components/ui.jsx', () => ({
    Alert: ({ children }) => (children ? <div role="alert">{children}</div> : null),
    useConfirm: () => [() => Promise.resolve(true), null],
}));

const listarProjetos = vi.fn();
const removerProjeto = vi.fn(() => Promise.resolve());
const cancelarSubmissao = vi.fn(() => Promise.resolve());

vi.mock('../lib/projetos.js', () => ({
    listarProjetos: (...a) => listarProjetos(...a),
    removerProjeto: (...a) => removerProjeto(...a),
    cancelarSubmissao: (...a) => cancelarSubmissao(...a),
}));

const getInscricoes = vi.fn(() => Promise.resolve({ abertas: true, encerradas: false, nao_iniciadas: false, prazo_input: null, prazo_label: null, minutos_restantes: null }));
vi.mock('../lib/inscricoes.js', () => ({ getInscricoes: (...a) => getInscricoes(...a) }));

import Projetos from './Projetos.jsx';

const submetido = (over = {}) => ({
    id: 7,
    titulo: 'Bioplástico de Mandioca',
    status: 'submetido',
    status_label: 'Submetido',
    categoria_label: 'FETECMS',
    pode_desfazer: true,
    updated_at: '2026-08-10T12:00:00-04:00',
    ...over,
});

describe('Projetos — desfazer a submissão', () => {
    beforeEach(() => {
        listarProjetos.mockReset();
        removerProjeto.mockClear();
        cancelarSubmissao.mockClear();
        getInscricoes.mockResolvedValue({ abertas: true, encerradas: false, nao_iniciadas: false, prazo_input: null, prazo_label: null, minutos_restantes: null });
    });

    it('oferece cancelar e excluir enquanto a submissão pode ser desfeita', async () => {
        listarProjetos.mockResolvedValue([submetido()]);
        render(<Projetos />);

        expect(await screen.findByRole('button', { name: /Cancelar submissão/ })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: /Excluir inscrição/ })).toBeInTheDocument();
    });

    it('esconde as ações quando a avaliação já começou', async () => {
        listarProjetos.mockResolvedValue([submetido({ pode_desfazer: false })]);
        render(<Projetos />);

        await screen.findByText('Bioplástico de Mandioca');
        expect(screen.queryByRole('button', { name: /Cancelar submissão/ })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Excluir inscrição/ })).not.toBeInTheDocument();
    });

    it('cancela a submissão e recarrega a lista', async () => {
        listarProjetos.mockResolvedValue([submetido()]);
        render(<Projetos />);

        fireEvent.click(await screen.findByRole('button', { name: /Cancelar submissão/ }));

        await waitFor(() => expect(cancelarSubmissao).toHaveBeenCalledWith(7));
        await waitFor(() => expect(listarProjetos).toHaveBeenCalledTimes(2));
    });

    it('mostra o motivo quando o servidor recusa a exclusão', async () => {
        listarProjetos.mockResolvedValue([submetido()]);
        removerProjeto.mockRejectedValueOnce({
            response: {
                status: 422,
                data: { motivos: [{ code: 'AVALIACAO_INICIADA', message: 'Este projeto já tem avaliação iniciada.' }] },
            },
        });
        render(<Projetos />);

        fireEvent.click(await screen.findByRole('button', { name: /Excluir inscrição/ }));

        expect(await screen.findByText('Este projeto já tem avaliação iniciada.')).toBeInTheDocument();
    });
});

describe('Projetos — prazo de submissão', () => {
    beforeEach(() => {
        listarProjetos.mockReset();
        listarProjetos.mockResolvedValue([submetido({ status: 'rascunho', status_label: 'Rascunho', pode_desfazer: false })]);
        getInscricoes.mockReset();
        getInscricoes.mockResolvedValue({ abertas: true, encerradas: false, nao_iniciadas: false, prazo_input: null, prazo_label: null, minutos_restantes: null });
    });

    it('lembra a data enquanto as inscrições estão abertas', async () => {
        getInscricoes.mockResolvedValue({ abertas: true, encerradas: false, nao_iniciadas: false, prazo_label: '30/09/2026 23:59', prazo_input: '2026-09-30T23:59', minutos_restantes: 120 });
        render(<Projetos />);

        expect(await screen.findByText(/Inscrições abertas até/)).toBeInTheDocument();
        expect(screen.getByText('30/09/2026 23:59')).toBeInTheDocument();
    });

    it('explica o encerramento e desabilita o que escreve', async () => {
        getInscricoes.mockResolvedValue({ abertas: false, encerradas: true, nao_iniciadas: false, prazo_label: '30/09/2026 23:59', prazo_input: '2026-09-30T23:59', minutos_restantes: null });
        render(<Projetos />);

        expect(await screen.findByRole('alert')).toHaveTextContent(/inscrições foram encerradas/i);
        expect(screen.getByText('NOVA INSCRIÇÃO').closest('button')).toBeDisabled();
        expect(screen.getByText('Continuar edição').closest('button')).toBeDisabled();
        expect(screen.getByText('Excluir').closest('button')).toBeDisabled();
    });

    it('explica a espera pela abertura e desabilita o que escreve', async () => {
        getInscricoes.mockResolvedValue({
            abertas: false, encerradas: false, nao_iniciadas: true,
            inicio_label: '01/09/2026 08:00', inicio_input: '2026-09-01T08:00',
            prazo_label: null, prazo_input: null, minutos_restantes: null,
        });
        render(<Projetos />);

        expect(await screen.findByRole('alert')).toHaveTextContent(/ainda não começaram/i);
        expect(screen.getByText('NOVA INSCRIÇÃO').closest('button')).toBeDisabled();
    });

    it('sem prazo definido, não mostra aviso nenhum', async () => {
        render(<Projetos />);

        await screen.findByText('Bioplástico de Mandioca');
        expect(screen.queryByText(/Inscrições abertas até/)).not.toBeInTheDocument();
        expect(screen.getByText('NOVA INSCRIÇÃO').closest('button')).not.toBeDisabled();
    });
});
