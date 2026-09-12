import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({
        message: e?.response?.data?.message ?? '',
        fields: e?.response?.data?.errors
            ? Object.fromEntries(Object.entries(e.response.data.errors).map(([k, v]) => [k, v[0]]))
            : {},
    }),
}));

const getDadosDemo = vi.fn();
const definirContaDemo = vi.fn();
const excluirContaDemo = vi.fn();
const excluirProjetoDemo = vi.fn();
const excluirListaDemo = vi.fn();
const excluirGuardaDemo = vi.fn();
const limparDadosDemo = vi.fn();
vi.mock('../lib/dadosDemo.js', () => ({
    getDadosDemo: (...a) => getDadosDemo(...a),
    definirContaDemo: (...a) => definirContaDemo(...a),
    excluirContaDemo: (...a) => excluirContaDemo(...a),
    excluirProjetoDemo: (...a) => excluirProjetoDemo(...a),
    excluirListaDemo: (...a) => excluirListaDemo(...a),
    excluirGuardaDemo: (...a) => excluirGuardaDemo(...a),
    limparDadosDemo: (...a) => limparDadosDemo(...a),
}));

import ParametrizacaoDemo from './ParametrizacaoDemo.jsx';

const grupo = (itens = []) => ({ total: itens.length, itens });

const panorama = (over = {}) => ({
    contas: grupo([
        { id: 7, name: 'Orientador Demo', email: 'orientador.demo@fetecms.test', role: 'orientador', papel: 'Orientador', is_active: true, is_demo: true, projetos: 2, criada_em: '2026-09-01T12:00:00-04:00' },
    ]),
    projetos: grupo([
        { id: 31, titulo: 'Bioplástico de mandioca', status: 'submetido', status_label: 'Submetido', categoria_label: 'FETECMS', area: 'Ciências Agrárias', edicao: 'XVI FETECMS', orientador: 'Orientador Demo', avaliacoes: 1, excluido: false, criado_em: '2026-09-01T12:00:00-04:00' },
    ]),
    avaliacoes: grupo([]),
    listas: grupo([]),
    credenciamentos: grupo([]),
    guardas: grupo([]),
    ajustes: grupo([]),
    registros: grupo([]),
    ...over,
});

/** Abre a seção recolhida cujo título foi passado. */
async function abrirSecao(titulo) {
    fireEvent.click(await screen.findByRole('button', { name: new RegExp(titulo) }));
}

describe('ParametrizacaoDemo', () => {
    beforeEach(() => {
        getDadosDemo.mockReset().mockResolvedValue({ data: panorama(), meta: {} });
        definirContaDemo.mockReset().mockResolvedValue({ data: {}, meta: {} });
        excluirContaDemo.mockReset();
        excluirProjetoDemo.mockReset();
        excluirListaDemo.mockReset();
        excluirGuardaDemo.mockReset();
        limparDadosDemo.mockReset();
    });

    it('resume o que existe de demonstração nos cards do topo', async () => {
        render(<ParametrizacaoDemo />);

        // Os quatro cards do topo, cada um com a contagem do seu grupo.
        expect(await screen.findAllByText('Contas')).not.toHaveLength(0);
        expect(screen.getAllByText('Avaliações').length).toBeGreaterThan(0);
        // Uma conta e um projeto de ensaio; avaliações e registros zerados.
        expect(screen.getAllByText('1').length).toBeGreaterThan(0);
        expect(screen.getAllByText('0').length).toBeGreaterThan(0);
    });

    it('diz claramente quando não há nada de ensaio no portal', async () => {
        getDadosDemo.mockResolvedValue({
            data: panorama({ contas: grupo([]), projetos: grupo([]) }),
            meta: {},
        });

        render(<ParametrizacaoDemo />);

        expect(await screen.findByText(/Não há nada de demonstração no portal/)).toBeInTheDocument();
    });

    it('lista a conta de ensaio e pede confirmação antes de excluí-la', async () => {
        excluirContaDemo.mockResolvedValue({ data: panorama({ contas: grupo([]) }), meta: { message: 'Conta de demonstração excluída com os dados dela.' } });

        render(<ParametrizacaoDemo />);
        await abrirSecao('Contas');

        expect(await screen.findByText('orientador.demo@fetecms.test')).toBeInTheDocument();

        fireEvent.click(screen.getAllByRole('button', { name: 'Excluir' })[0]);

        // A confirmação diz o tamanho do estrago antes de acontecer.
        expect(await screen.findByText(/2 projeto\(s\)/)).toBeInTheDocument();
        expect(excluirContaDemo).not.toHaveBeenCalled();

        fireEvent.click(screen.getByRole('button', { name: 'Excluir tudo' }));

        await waitFor(() => expect(excluirContaDemo).toHaveBeenCalledWith(7));
        expect(await screen.findByText("Conta de demonstração excluída com os dados dela.")).toBeInTheDocument();
    });

    it('avisa da consequência antes de tirar a marca de demonstração', async () => {
        render(<ParametrizacaoDemo />);
        await abrirSecao('Contas');

        const linha = (await screen.findByText('Orientador Demo')).closest('tr');
        fireEvent.click(within(linha).getByRole('switch'));

        expect(await screen.findByText(/voltam a contar no painel/)).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Tirar a marca' }));

        await waitFor(() => expect(definirContaDemo).toHaveBeenCalledWith(7, false));
    });

    it('mostra o motivo quando o servidor recusa apagar dado real', async () => {
        excluirProjetoDemo.mockRejectedValue({
            response: { data: { errors: { demo: ['Este projeto não é de uma conta de demonstração.'] } } },
        });

        render(<ParametrizacaoDemo />);
        await abrirSecao('Projetos');

        fireEvent.click(await screen.findByRole('button', { name: 'Excluir' }));
        fireEvent.click(await screen.findByRole('button', { name: 'Excluir projeto' }));

        expect(await screen.findByText('Este projeto não é de uma conta de demonstração.')).toBeInTheDocument();
    });

    it('apaga tudo de uma vez, dizendo antes quanto sai', async () => {
        limparDadosDemo.mockResolvedValue({
            data: panorama({ contas: grupo([]), projetos: grupo([]) }),
            meta: { message: 'Demonstração apagada: 1 conta(s), 1 projeto(s), 0 lista(s) e 0 guarda(s).' },
        });

        render(<ParametrizacaoDemo />);

        fireEvent.click(await screen.findByRole('button', { name: 'Apagar tudo' }));
        expect(await screen.findByText(/Serão apagadas 1 conta\(s\), 1 projeto\(s\)/)).toBeInTheDocument();

        fireEvent.click(screen.getAllByRole('button', { name: 'Apagar tudo' })[1]);

        await waitFor(() => expect(limparDadosDemo).toHaveBeenCalled());
        expect(await screen.findByText(/Demonstração apagada/)).toBeInTheDocument();
    });
});
