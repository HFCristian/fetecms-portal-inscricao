import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({
    Link: ({ children, to }) => <a href={to}>{children}</a>,
    useParams: () => ({ id: '5' }),
}));
vi.mock('../lib/auth.jsx', () => ({ extractErrors: (e) => ({ message: e?.message ?? '', fields: {} }) }));

const item = (over) => ({
    projeto_id: 10, codigo: 'FET.AGR-001', titulo: 'Abelhas', categoria: 'FETECMS', area: 'Ciências Agrárias',
    escola: 'EE Alfa / Campo Grande - MS', alunos: ['Ana Lima'], orientador: 'Paula Reis', coorientador: null,
    media: 9, avaliacoes: 3, parcial: false, colocacao: 1, manual: false, ...over,
});

const DADOS = {
    lista: { id: 5, nome: 'Pódio por área', descricao: null, tipo: 'final', vigente: false, rascunho: true, demo: false, versao: 1, projetos: 3, origens: [] },
    itens: [
        item({}),
        item({ projeto_id: 11, codigo: 'FET.AGR-002', titulo: 'Bioinsumo', media: 8, colocacao: 2 }),
        item({ projeto_id: 12, codigo: 'FET.BIO-001', titulo: 'Enzimas', area: 'Ciências Biológicas', media: 7.5, colocacao: 1 }),
    ],
    candidatos: [],
};

const getListaPresencial = vi.fn(() => Promise.resolve(DADOS));
const gerarListaPresencialDefinitiva = vi.fn(() => Promise.resolve({
    data: { ...DADOS, lista: { ...DADOS.lista, rascunho: false, vigente: true } },
    meta: { message: 'Lista final gerada. Ela é agora a lista final presencial ativa.' },
}));
const reativarListaPresencial = vi.fn();
const rotularListaPresencial = vi.fn(() => Promise.resolve({
    data: { ...DADOS, lista: { ...DADOS.lista, nome: 'Premiação 2026', descricao: 'Oficial.' } },
    meta: { message: 'Apelido e descrição salvos.' },
}));
const exportarListaPresencial = vi.fn(() => Promise.resolve());
vi.mock('../lib/listasPresenciais.js', () => ({
    getListaPresencial: (...a) => getListaPresencial(...a),
    gerarListaPresencialDefinitiva: (...a) => gerarListaPresencialDefinitiva(...a),
    reativarListaPresencial: (...a) => reativarListaPresencial(...a),
    rotularListaPresencial: (...a) => rotularListaPresencial(...a),
    exportarListaPresencial: (...a) => exportarListaPresencial(...a),
}));

import PresencialListaDetalhe from './PresencialListaDetalhe.jsx';

describe('PresencialListaDetalhe', () => {
    beforeEach(() => vi.clearAllMocks());

    it('agrupa por categoria e área com a colocação de cada um', async () => {
        render(<PresencialListaDetalhe />);

        const agr = await screen.findByRole('region', { name: 'FETECMS · Ciências Agrárias' });
        expect(within(agr).getByLabelText('1º lugar')).toBeInTheDocument();
        expect(within(agr).getByLabelText('2º lugar')).toBeInTheDocument();
        expect(within(agr).getByText('FET.AGR-001')).toBeInTheDocument();
        expect(within(agr).getByText('9,000')).toBeInTheDocument();
        expect(within(agr).getAllByText(/Ana Lima, Paula Reis \(orientador\)/)).toHaveLength(2);
        const bio = screen.getByRole('region', { name: 'FETECMS · Ciências Biológicas' });
        expect(within(bio).getByText('Enzimas')).toBeInTheDocument();
    });

    it('gera a final depois de confirmar que ela vira a ativa', async () => {
        render(<PresencialListaDetalhe />);
        fireEvent.click(await screen.findByRole('button', { name: /Gerar lista final/ }));

        expect(await screen.findByText(/resultado oficial da premiação/)).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Gerar e ativar' }));

        await waitFor(() => expect(gerarListaPresencialDefinitiva).toHaveBeenCalledWith('5'));
        expect(await screen.findByText('Lista final gerada. Ela é agora a lista final presencial ativa.')).toBeInTheDocument();
        expect(screen.getByText('ativa')).toBeInTheDocument();
    });

    it('baixa os quatro formatos', async () => {
        render(<PresencialListaDetalhe />);
        await screen.findByText('Abelhas');

        for (const [rotulo, formato] of [['TXT', 'txt'], ['CSV', 'csv'], ['Excel', 'xlsx'], ['PDF', 'pdf']]) {
            fireEvent.click(screen.getByRole('button', { name: `Baixar ${rotulo}` }));
            await waitFor(() => expect(exportarListaPresencial).toHaveBeenLastCalledWith('5', formato));
        }
    });

    it('edita apelido e descrição', async () => {
        render(<PresencialListaDetalhe />);
        fireEvent.click(await screen.findByRole('button', { name: /Editar apelido/ }));
        fireEvent.change(screen.getByLabelText('Apelido da lista'), { target: { value: 'Premiação 2026' } });
        fireEvent.change(screen.getByLabelText('Descrição da lista'), { target: { value: 'Oficial.' } });
        fireEvent.click(screen.getByRole('button', { name: 'Salvar' }));

        await waitFor(() => expect(rotularListaPresencial).toHaveBeenCalledWith(5, { nome: 'Premiação 2026', descricao: 'Oficial.' }));
        expect(await screen.findByText('Oficial.')).toBeInTheDocument();
    });

    it('a final inativa volta a valer com justificativa', async () => {
        getListaPresencial.mockResolvedValueOnce({ ...DADOS, lista: { ...DADOS.lista, rascunho: false, vigente: false } });
        reativarListaPresencial.mockResolvedValue({
            data: { ...DADOS, lista: { ...DADOS.lista, rascunho: false, vigente: true } },
            meta: { message: 'Esta é agora a lista final presencial ativa.' },
        });
        render(<PresencialListaDetalhe />);

        fireEvent.click(await screen.findByRole('button', { name: /Tornar ativa/ }));
        const dialogo = screen.getByRole('dialog', { name: 'Tornar esta a lista final ativa' });
        fireEvent.change(within(dialogo).getByRole('textbox'), { target: { value: 'Voltou a valer.' } });
        fireEvent.click(within(dialogo).getByRole('button', { name: 'Tornar ativa' }));

        await waitFor(() => expect(reativarListaPresencial).toHaveBeenCalledWith('5', 'Voltou a valer.'));
    });
});
