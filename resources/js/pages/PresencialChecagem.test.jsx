import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children }) => <a>{children}</a> }));
vi.mock('../lib/auth.jsx', () => ({
    extractErrors: (e) => ({ message: e?.message ?? 'Erro.', fields: e?.fields ?? {} }),
}));

const getConfigPresencial = vi.fn();
const getChecagens = vi.fn();
const getFichaEstande = vi.fn();
const registrarChecagem = vi.fn();
vi.mock('../lib/presencial.js', () => ({
    getConfigPresencial: (...a) => getConfigPresencial(...a),
    getChecagens: (...a) => getChecagens(...a),
    getFichaEstande: (...a) => getFichaEstande(...a),
    registrarChecagem: (...a) => registrarChecagem(...a),
    urlTermo: (id) => `/api/v1/documentos/${id}/preview`,
}));

import PresencialChecagem from './PresencialChecagem.jsx';

const CONFIG = {
    aberto: true, motivo_fechado: null, pode_testar: false, modo_teste: false,
    situacoes: [
        { value: 'presente', label: 'Presente' },
        { value: 'ausente', label: 'Ausente' },
        { value: 'nao_necessario', label: 'Não necessário' },
    ],
    areas: [{ id: 1, nome: 'Exatas' }],
    categorias: [{ value: 'fetecms', label: 'FETECMS' }],
    itens: [{ id: 5, nome: 'Banner montado', ativo: true }],
};

const LINHA = {
    id: 3, titulo: 'Bioplástico', area: 'Exatas', escola: 'EE Alfa', orientador: 'Marta',
    local: { estande: 42, turno: 'a', turno_label: 'Matutino' },
    conferido: false, ausentes: 0, tem_termo: false,
};

const FICHA = {
    projeto: {
        id: 3, titulo: 'Bioplástico', categoria: 'FETECMS', area: 'Exatas',
        escola: 'EE Alfa', orientador: 'Marta', alunos: ['Ana', 'Beto'],
    },
    local: { estande: 42, turno: 'a', turno_label: 'Matutino' },
    itens: [{ id: 5, nome: 'Banner montado', descricao: null, situacao: null }],
    termo: null,
    checagem: null,
};

describe('PresencialChecagem', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        getConfigPresencial.mockResolvedValue(CONFIG);
        getChecagens.mockResolvedValue({
            data: [LINHA],
            meta: { resumo: { finalistas: 1, conferidos: 0, pendentes: 1 }, pagina_atual: 1, ultima_pagina: 1 },
        });
        getFichaEstande.mockResolvedValue(FICHA);
        registrarChecagem.mockResolvedValue({
            data: { ...FICHA, checagem: { verificado_em: '2026-10-20T09:00:00-04:00', verificado_por: 'Admin', observacao: null } },
            meta: { message: 'Checagem do estande registrada.' },
        });
    });

    it('lista os estandes com número, turno e situação', async () => {
        render(<PresencialChecagem />);

        expect(await screen.findByText('Bioplástico')).toBeInTheDocument();
        expect(screen.getByText('Estande 42 · Matutino')).toBeInTheDocument();
        expect(screen.getByText('Pendente')).toBeInTheDocument();
        // Projeto sem termo é sinalizado já na lista.
        expect(screen.getByText('sem termo')).toBeInTheDocument();
    });

    it('abre a ficha e registra a conferência item a item', async () => {
        render(<PresencialChecagem />);

        fireEvent.click(await screen.findByRole('button', { name: 'Conferir' }));
        await waitFor(() => expect(getFichaEstande).toHaveBeenCalledWith(3, false));

        fireEvent.change(await screen.findByLabelText('Situação de Banner montado'), {
            target: { value: 'ausente' },
        });
        fireEvent.click(screen.getByRole('button', { name: /Registrar checagem/i }));

        await waitFor(() => expect(registrarChecagem).toHaveBeenCalledWith(
            3,
            { itens: [{ item_id: 5, situacao: 'ausente' }], observacao: null },
            false,
        ));
        expect(await screen.findByText('Checagem do estande registrada.')).toBeInTheDocument();
    });

    it('a ficha mostra o termo do orientador com link para o PDF', async () => {
        getFichaEstande.mockResolvedValue({
            ...FICHA,
            termo: {
                id: 9, nome_original: 'termo.pdf', assinatura_valida: true,
                assinatura_motivo: 'Assinatura digital ICP-Brasil conferida.',
            },
        });
        render(<PresencialChecagem />);

        fireEvent.click(await screen.findByRole('button', { name: 'Conferir' }));

        expect(await screen.findByText('termo.pdf')).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /Ver PDF/i }).getAttribute('href'))
            .toBe('/api/v1/documentos/9/preview');
        expect(screen.getByText(/ICP-Brasil conferida/)).toBeInTheDocument();
    });

    it('fora da janela a tela explica e não deixa registrar', async () => {
        getConfigPresencial.mockResolvedValue({
            ...CONFIG, aberto: false, motivo_fechado: 'A checagem abre no início do evento.',
        });
        render(<PresencialChecagem />);

        expect(await screen.findByText('A checagem abre no início do evento.')).toBeInTheDocument();

        fireEvent.click(await screen.findByRole('button', { name: 'Ver' }));
        await screen.findByText(/Itens conferidos/i);

        expect(screen.queryByRole('button', { name: /Registrar checagem/i })).not.toBeInTheDocument();
        expect(screen.getByLabelText('Situação de Banner montado')).toBeDisabled();
    });
});
