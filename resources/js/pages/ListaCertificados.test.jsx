import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import { describe, it, expect, vi, beforeEach } from 'vitest';

const getLinhas = vi.fn();
const baixar = vi.fn(() => Promise.resolve());
vi.mock('../lib/certificados.js', () => ({
    getListaCertificados: () => Promise.resolve({
        id: 3, nome: 'Fase online', status: 'gerada', publicos: ['Estudantes'], gerada_em: '2026-10-24T14:00:00-04:00',
        gerada_por: 'Olga Organizadora', desatualizada: true,
    }),
    getLinhasCertificados: (...a) => getLinhas(...a),
    baixarListaCertificados: (...a) => baixar(...a),
}));

import ListaCertificados from './ListaCertificados.jsx';

const RESUMO = [
    { chave: 'estudantes_online', rotulo: 'Estudantes — fase online', carga_texto: '5h fixas', certificados: 2, pessoas: 2, horas: 10, sem_cpf: 1, sem_email: 0, teto_aplicado: 0, nomes_corrigidos: 1 },
];

describe('ListaCertificados', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        getLinhas.mockResolvedValue({
            data: [{
                id: 1, categoria: 'estudantes_online', nome: 'Zuleica N. Silva', nome_original: 'Zuleica Nunes', corrigido: true,
                cpf: null, email: 'z@x.test', telefone: null, funcao: 'Estudante', tipo_atividade: 'Fase online', titulo: 'Biofiltro',
                campo_generico: 'FETECMS', quantidade: null, minutos: 300, horas: 5, teto_aplicado: false, data_inicio: '01/08/2026', data_fim: '15/09/2026',
            }],
            meta: { pagina: 1, ultima_pagina: 1, total: 1, resumo: RESUMO },
        });
    });

    it('mostra o resumo, a linha com o nome corrigido e exporta no recorte da categoria', async () => {
        render(<MemoryRouter initialEntries={['/admin/certificados/listas/3']}><Routes><Route path="/admin/certificados/listas/:id" element={<ListaCertificados />} /></Routes></MemoryRouter>);

        expect(await screen.findByText('Zuleica N. Silva')).toBeInTheDocument();
        expect(screen.getByText('No cadastro: Zuleica Nunes')).toBeInTheDocument();
        expect(screen.getByText(/alterada depois de gerada/)).toBeInTheDocument();
        expect(screen.getByText(/Gerada em .* por Olga Organizadora/)).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /Estudantes — fase online/ }));
        await waitFor(() => expect(getLinhas).toHaveBeenLastCalledWith('3', { categoria: 'estudantes_online', q: '', page: 1 }));

        fireEvent.click(screen.getByRole('button', { name: /Excel \(SICERT\)/ }));
        await waitFor(() => expect(baixar).toHaveBeenCalledWith('3', 'xlsx', 'estudantes_online'));
        fireEvent.click(screen.getByRole('button', { name: /^.*TXT$/ }));
        await waitFor(() => expect(baixar).toHaveBeenCalledWith('3', 'txt', 'estudantes_online'));
    });
});
