import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({
    Link: ({ children, to }) => <a href={to}>{children}</a>,
    useNavigate: () => vi.fn(),
}));
vi.mock('../lib/auth.jsx', () => ({ extractErrors: () => ({ message: 'erro', fields: {} }) }));
vi.mock('../lib/modoTeste.js', () => ({ useModoTeste: () => [false, vi.fn()] }));

const PESSOAS = [
    { tipo: 'aluno', tipo_label: 'Aluno', id: 30, nome: 'Ana Aluna' },
    { tipo: 'orientador', tipo_label: 'Orientador', id: 5, nome: 'Marta Orientadora' },
];

const registro = (over = {}) => ({
    id: 1,
    projeto: 'Bioplástico',
    escola: 'EE Alfa',
    responsavel: 'Ana Aluna',
    registrado_em: '2026-09-06T10:00:00-04:00',
    registrado_por: 'Ana Admin',
    itens_total: 2,
    itens_retirados: 0,
    itens_pendentes: 2,
    retirado_por: null,
    retirado_em: null,
    situacao: 'guardado',
    pessoas: PESSOAS,
    itens: [
        { id: 11, descricao: 'Maquete', retirado: false },
        { id: 12, descricao: 'Mochila', retirado: false },
    ],
    ...over,
});

const resposta = (over = {}) => ({
    data: [registro(over)],
    meta: {
        pagina_atual: 1,
        ultima_pagina: 1,
        total: 1,
        resumo: { registros: 1, guardados: 1, retirados: 0, itens_guardados: 2 },
        config: { aberto: true, lista: { nome: 'Oficial', demo: false } },
    },
});

const getAlmoxarifadoRegistros = vi.fn(() => Promise.resolve(resposta()));
const retirarDoAlmoxarifado = vi.fn(() => Promise.resolve({}));
const editarRegistroAlmoxarifado = vi.fn(() => Promise.resolve({}));
const excluirRegistroAlmoxarifado = vi.fn(() => Promise.resolve({}));
vi.mock('../lib/almoxarifado.js', () => ({
    getAlmoxarifadoRegistros: (...a) => getAlmoxarifadoRegistros(...a),
    retirarDoAlmoxarifado: (...a) => retirarDoAlmoxarifado(...a),
    editarRegistroAlmoxarifado: (...a) => editarRegistroAlmoxarifado(...a),
    excluirRegistroAlmoxarifado: (...a) => excluirRegistroAlmoxarifado(...a),
}));

import AlmoxarifadoRegistros from './AlmoxarifadoRegistros.jsx';

describe('AlmoxarifadoRegistros', () => {
    beforeEach(() => {
        retirarDoAlmoxarifado.mockClear();
        editarRegistroAlmoxarifado.mockClear();
        excluirRegistroAlmoxarifado.mockClear();
        getAlmoxarifadoRegistros.mockResolvedValue(resposta());
    });

    it('a retirada completa leva tudo o que está guardado, sem escolher item', async () => {
        render(<AlmoxarifadoRegistros />);
        fireEvent.click(await screen.findByLabelText(/Retirada completa/));

        fireEvent.change(screen.getByLabelText('Quem está retirando'), { target: { value: 'orientador:5' } });
        fireEvent.click(screen.getByRole('button', { name: 'Registrar retirada' }));

        await waitFor(() => expect(retirarDoAlmoxarifado).toHaveBeenCalledWith(
            1, { responsavel_tipo: 'orientador', responsavel_id: 5 }, false,
        ));
    });

    it('a retirada parcial manda só os itens marcados', async () => {
        render(<AlmoxarifadoRegistros />);
        fireEvent.click(await screen.findByLabelText(/Retirada parcial/));

        // Sem item marcado não dá para registrar.
        expect(screen.getByRole('button', { name: 'Registrar retirada' })).toBeDisabled();

        fireEvent.click(screen.getByLabelText('Maquete'));
        fireEvent.change(screen.getByLabelText('Quem está retirando'), { target: { value: 'aluno:30' } });
        fireEvent.click(screen.getByRole('button', { name: 'Registrar retirada' }));

        await waitFor(() => expect(retirarDoAlmoxarifado).toHaveBeenCalledWith(
            1, { responsavel_tipo: 'aluno', responsavel_id: 30, itens: [11] }, false,
        ));
    });

    it('a correção exige justificativa e não deixa mexer no que já saiu', async () => {
        getAlmoxarifadoRegistros.mockResolvedValue(resposta({
            itens_retirados: 1,
            itens_pendentes: 1,
            situacao: 'parcial',
            itens: [
                { id: 11, descricao: 'Maquete', retirado: true },
                { id: 12, descricao: 'Mochila', retirado: false },
            ],
        }));
        render(<AlmoxarifadoRegistros />);
        fireEvent.click(await screen.findByLabelText(/Editar/));

        // O item já retirado não é editável nem removível.
        expect(screen.getByLabelText('Item 1')).toBeDisabled();
        expect(screen.getByLabelText('Remover item 1')).toBeDisabled();

        fireEvent.change(screen.getByLabelText('Quem deixou o material'), { target: { value: 'orientador:5' } });
        expect(screen.getByRole('button', { name: 'Salvar correção' })).toBeDisabled();

        fireEvent.change(screen.getByLabelText('Item 2'), { target: { value: 'Mochila azul' } });
        fireEvent.change(screen.getByLabelText('Justificativa da correção'), {
            target: { value: 'Quem deixou foi a orientadora.' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Salvar correção' }));

        await waitFor(() => expect(editarRegistroAlmoxarifado).toHaveBeenCalledWith(1, {
            responsavel_tipo: 'orientador',
            responsavel_id: 5,
            itens: [
                { id: 11, descricao: 'Maquete' },
                { id: 12, descricao: 'Mochila azul' },
            ],
            justificativa: 'Quem deixou foi a orientadora.',
        }, false));
    });

    it('a exclusão exige justificativa', async () => {
        render(<AlmoxarifadoRegistros />);
        fireEvent.click(await screen.findByLabelText(/Excluir/));

        expect(screen.getByRole('button', { name: 'Excluir' })).toBeDisabled();

        fireEvent.change(screen.getByLabelText('Justificativa da exclusão'), {
            target: { value: 'Lançado em duplicidade.' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Excluir' }));

        await waitFor(() => expect(excluirRegistroAlmoxarifado).toHaveBeenCalledWith(
            1, 'Lançado em duplicidade.', false,
        ));
    });

    it('sem item no balcão as retiradas somem da linha', async () => {
        getAlmoxarifadoRegistros.mockResolvedValue(resposta({
            itens_retirados: 2,
            itens_pendentes: 0,
            situacao: 'retirado',
            retirado_por: 'Ana Aluna',
            retirado_em: '2026-09-06T15:00:00-04:00',
            itens: [
                { id: 11, descricao: 'Maquete', retirado: true },
                { id: 12, descricao: 'Mochila', retirado: true },
            ],
        }));
        render(<AlmoxarifadoRegistros />);
        await screen.findByText('Bioplástico');

        expect(screen.queryByLabelText(/Retirada completa/)).not.toBeInTheDocument();
        expect(screen.queryByLabelText(/Retirada parcial/)).not.toBeInTheDocument();
        expect(screen.getByLabelText(/Editar/)).toBeInTheDocument();
        expect(screen.getByText('Retirado')).toBeInTheDocument();
    });
});
