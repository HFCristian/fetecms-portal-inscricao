import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.response?.data?.message ?? '', fields: {} }),
}));

const getIdentificacao = vi.fn();
const baixarIdentificacao = vi.fn();
vi.mock('../lib/admin.js', () => ({
    getIdentificacao: (...a) => getIdentificacao(...a),
    baixarIdentificacao: (...a) => baixarIdentificacao(...a),
    urlCodigo: (codigo, tipo) => `/api/v1/admin/avaliacao/identificacao/${tipo}/${codigo}.svg`,
}));

import IdentificacaoParticipantes from './IdentificacaoParticipantes.jsx';

const dados = (over = {}) => ({
    lista: { id: 3, nome: 'Lista oficial', versao: 2, demo: false },
    total: 3,
    por_papel: { A: 2, O: 1 },
    participantes: [
        {
            nome: 'Zuleica Nunes', papel: 'A', papel_label: 'Aluno(a)', participante_id: 45,
            codigo: '2026-31-111-A45', projeto_id: 31, projeto: 'Bioplástico de mandioca',
            categoria: 'FETECMS', escola: 'EE Maria Constança',
        },
        {
            nome: 'Ana Paula', papel: 'A', papel_label: 'Aluno(a)', participante_id: 46,
            codigo: '2026-31-222-A46', projeto_id: 31, projeto: 'Bioplástico de mandioca',
            categoria: 'FETECMS', escola: 'EE Maria Constança',
        },
        {
            nome: 'Marta Orientadora', papel: 'O', papel_label: 'Orientador(a)', participante_id: 9,
            codigo: '2026-31-529-O9', projeto_id: 31, projeto: 'Bioplástico de mandioca',
            categoria: 'FETECMS', escola: 'EE Maria Constança',
        },
    ],
    ...over,
});

describe('IdentificacaoParticipantes', () => {
    beforeEach(() => {
        getIdentificacao.mockReset().mockResolvedValue(dados());
        baixarIdentificacao.mockReset().mockResolvedValue();
    });

    it('resume quantas pessoas de cada papel a lista tem', async () => {
        render(<IdentificacaoParticipantes listaId={3} />);

        expect(await screen.findByText(/3 participante\(s\)/)).toBeInTheDocument();
        expect(screen.getByText(/2 aluno\(s\), 1 orientador\(es\), 0 coorientador\(es\)/)).toBeInTheDocument();
    });

    it('abre a lista e mostra o QR e o código de barras de cada um', async () => {
        render(<IdentificacaoParticipantes listaId={3} />);

        // Recolhida por padrão: quem entra nesta tela vem mexer na composição.
        expect(screen.queryByText('2026-31-111-A45')).not.toBeInTheDocument();

        fireEvent.click(await screen.findByRole('button', { name: /Ver os códigos um a um/ }));

        expect(await screen.findByText('2026-31-111-A45')).toBeInTheDocument();
        // Os dois alunos do mesmo projeto repetem a linha de papel + projeto.
        expect(screen.getAllByText('Aluno(a) · Bioplástico de mandioca')).toHaveLength(2);
        expect(screen.getByText('Orientador(a) · Bioplástico de mandioca')).toBeInTheDocument();

        const qr = screen.getByAltText('QR Code de Zuleica Nunes');
        expect(qr).toHaveAttribute('src', '/api/v1/admin/avaliacao/identificacao/qr/2026-31-111-A45.svg');
        expect(screen.getByAltText('Código de barras de Zuleica Nunes')).toHaveAttribute(
            'src',
            '/api/v1/admin/avaliacao/identificacao/barras/2026-31-111-A45.svg',
        );
    });

    it('baixa as etiquetas em PDF e as imagens em ZIP', async () => {
        render(<IdentificacaoParticipantes listaId={3} />);

        fireEvent.click(await screen.findByRole('button', { name: 'Etiquetas em PDF' }));
        await waitFor(() => expect(baixarIdentificacao).toHaveBeenCalledWith(3, 'pdf'));

        fireEvent.click(screen.getByRole('button', { name: 'Imagens em ZIP' }));
        await waitFor(() => expect(baixarIdentificacao).toHaveBeenCalledWith(3, 'zip'));
    });

    it('lista vazia explica que não há o que identificar', async () => {
        getIdentificacao.mockResolvedValue(dados({ total: 0, por_papel: {}, participantes: [] }));

        render(<IdentificacaoParticipantes listaId={3} />);

        expect(await screen.findByText(/sem participantes, não há o que identificar/)).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Ver os códigos/ })).not.toBeInTheDocument();
    });

    it('mostra o motivo quando a carga falha', async () => {
        getIdentificacao.mockRejectedValue({ response: { data: { message: 'Sem permissão.' } } });

        render(<IdentificacaoParticipantes listaId={3} />);

        expect(await screen.findByText('Sem permissão.')).toBeInTheDocument();
    });
});
