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
    candidatos: [
        { id: 20, titulo: 'Drone na lavoura', codigo: 'FET.AGR-004', categoria: 'FETECMS', area: 'Ciências Agrárias', media: 6 },
    ],
    premiacoes: [
        {
            id: 70, credencial_id: 4, tipo: 'credencial', tipo_label: 'Credencial', nome: 'MOSTRATEC', orgao: 'Fundação Liberato',
            titulo: 'MOSTRATEC — Fundação Liberato',
            itens: [item({ projeto_id: 20, codigo: 'FET.AGR-004', titulo: 'Drone na lavoura', media: 6, colocacao: null })],
        },
        { id: 71, credencial_id: 5, tipo: 'premio', tipo_label: 'Prêmio', nome: 'Destaque', orgao: null, titulo: 'Destaque', itens: [] },
    ],
    slides: {
        premiados: 5, slides: 12,
        categorias: [
            { value: 'fetecms', label: 'FETECMS', premiados: 4, slides: 9 },
            { value: 'fetecms_fundect', label: 'FETEC FUNDECT', premiados: 1, slides: 3 },
        ],
        sem_colocacao: 1,
        sem_foto: [{ projeto_id: 11, codigo: 'FET.AGR-002', titulo: 'Bioinsumo', motivo: 'Sem fotografia registrada' }],
    },
    finalistas: [
        { id: 10, titulo: 'Abelhas', codigo: 'FET.AGR-001', categoria: 'FETECMS', area: 'Ciências Agrárias' },
        { id: 20, titulo: 'Drone na lavoura', codigo: 'FET.AGR-004', categoria: 'FETECMS', area: 'Ciências Agrárias' },
    ],
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
const exportarSlidesPresencial = vi.fn(() => Promise.resolve());
const adicionarNaListaPresencial = vi.fn(() => Promise.resolve({
    ...DADOS, lista: { ...DADOS.lista, projetos: 4 },
    itens: [...DADOS.itens, item({ projeto_id: 20, codigo: 'FET.AGR-004', titulo: 'Drone na lavoura', media: 6, colocacao: 3, manual: true })],
}));
const removerDaListaPresencial = vi.fn(() => Promise.resolve({ ...DADOS, itens: DADOS.itens.slice(1) }));
const adicionarNaPremiacaoPresencial = vi.fn(() => Promise.resolve(DADOS));
const removerDaPremiacaoPresencial = vi.fn(() => Promise.resolve(DADOS));
const recarregarPremiacoesPresencial = vi.fn(() => Promise.resolve(DADOS));
vi.mock('../components/BuscaCombobox.jsx', () => ({
    default: ({ options, onChange, placeholder }) => (
        <select aria-label={placeholder} onChange={(e) => onChange(options.find((o) => String(o.id) === e.target.value) ?? null)}>
            <option value="">—</option>
            {options.map((o) => <option key={o.id} value={o.id}>{o.nome}</option>)}
        </select>
    ),
}));
vi.mock('../lib/listasPresenciais.js', () => ({
    exportarSlidesPresencial: (...a) => exportarSlidesPresencial(...a),
    adicionarNaPremiacaoPresencial: (...a) => adicionarNaPremiacaoPresencial(...a),
    removerDaPremiacaoPresencial: (...a) => removerDaPremiacaoPresencial(...a),
    recarregarPremiacoesPresencial: (...a) => recarregarPremiacoesPresencial(...a),
    adicionarNaListaPresencial: (...a) => adicionarNaListaPresencial(...a),
    removerDaListaPresencial: (...a) => removerDaListaPresencial(...a),
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
    it('no rascunho inclui e retira na hora', async () => {
        render(<PresencialListaDetalhe />);
        await screen.findByText('Abelhas');

        const busca = screen.getByLabelText('Buscar entre os finalistas fora da lista…');
        fireEvent.change(busca, { target: { value: '20' } });
        expect(within(busca).getByRole('option', { name: 'FET.AGR-004 — Drone na lavoura' })).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Incluir na lista' }));
        await waitFor(() => expect(adicionarNaListaPresencial).toHaveBeenCalledWith('5', 20, null));
        expect(await screen.findByText('incluído à mão')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Retirar Abelhas' }));
        await waitFor(() => expect(removerDaListaPresencial).toHaveBeenCalledWith('5', 10, null));
    });

    it('na lista gerada, retirar pede justificativa', async () => {
        getListaPresencial.mockResolvedValueOnce({ ...DADOS, lista: { ...DADOS.lista, rascunho: false, vigente: true } });
        render(<PresencialListaDetalhe />);

        fireEvent.click(await screen.findByRole('button', { name: 'Retirar Abelhas' }));
        expect(removerDaListaPresencial).not.toHaveBeenCalled();
        const dialogo = screen.getByRole('dialog', { name: 'Retirar da lista' });
        fireEvent.change(within(dialogo).getByRole('textbox'), { target: { value: 'Desclassificado.' } });
        fireEvent.click(within(dialogo).getByRole('button', { name: 'Retirar' }));

        await waitFor(() => expect(removerDaListaPresencial).toHaveBeenCalledWith('5', 10, 'Desclassificado.'));
    });
    it('mostra as premiações e inclui, retira e recarrega', async () => {
        render(<PresencialListaDetalhe />);

        const mostratec = await screen.findByRole('region', { name: 'MOSTRATEC — Fundação Liberato' });
        expect(within(mostratec).getByText('Drone na lavoura')).toBeInTheDocument();
        expect(within(mostratec).queryByLabelText(/lugar|Sem colocação/)).not.toBeInTheDocument();
        expect(within(screen.getByRole('region', { name: 'Destaque' })).getByText('Nenhum projeto contemplado.')).toBeInTheDocument();

        // Quem já está na credencial não aparece para incluir de novo.
        const busca = within(mostratec).getByLabelText('Incluir finalista em MOSTRATEC…');
        expect(within(busca).queryByRole('option', { name: /Drone/ })).not.toBeInTheDocument();
        fireEvent.change(busca, { target: { value: '10' } });
        fireEvent.click(within(mostratec).getByRole('button', { name: 'Incluir em MOSTRATEC' }));
        await waitFor(() => expect(adicionarNaPremiacaoPresencial).toHaveBeenCalledWith('5', 70, 10, null));

        fireEvent.click(within(mostratec).getByRole('button', { name: 'Retirar Drone na lavoura de MOSTRATEC' }));
        await waitFor(() => expect(removerDaPremiacaoPresencial).toHaveBeenCalledWith('5', 70, 20, null));

        fireEvent.click(screen.getByRole('button', { name: /Recarregar de Credenciais e Prêmios/ }));
        fireEvent.click(await screen.findByRole('button', { name: 'Recarregar' }));
        await waitFor(() => expect(recarregarPremiacoesPresencial).toHaveBeenCalledWith('5', null));
    });

    it('na lista gerada, mexer na premiação pede justificativa', async () => {
        getListaPresencial.mockResolvedValueOnce({ ...DADOS, lista: { ...DADOS.lista, rascunho: false, vigente: true } });
        render(<PresencialListaDetalhe />);

        const mostratec = await screen.findByRole('region', { name: 'MOSTRATEC — Fundação Liberato' });
        fireEvent.click(within(mostratec).getByRole('button', { name: 'Retirar Drone na lavoura de MOSTRATEC' }));
        const dialogo = screen.getByRole('dialog', { name: 'Retirar de MOSTRATEC' });
        fireEvent.change(within(dialogo).getByRole('textbox'), { target: { value: 'Credencial recusada.' } });
        fireEvent.click(within(dialogo).getByRole('button', { name: 'Retirar' }));

        await waitFor(() => expect(removerDaPremiacaoPresencial).toHaveBeenCalledWith('5', 70, 20, 'Credencial recusada.'));
    });
    it('slides só na lista gerada, avisando quem sai sem foto', async () => {
        render(<PresencialListaDetalhe />);
        expect(await screen.findByText('Gere a lista para baixar os slides.')).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: /Baixar slides/ })).not.toBeInTheDocument();
        expect(screen.queryByText(/fora dos slides/)).not.toBeInTheDocument();
    });

    it('baixa os slides da lista gerada', async () => {
        getListaPresencial.mockResolvedValueOnce({ ...DADOS, lista: { ...DADOS.lista, rascunho: false, vigente: true } });
        render(<PresencialListaDetalhe />);

        expect(await screen.findByText(/1 projeto vai sair sem foto/)).toBeInTheDocument();
        expect(screen.getByText('FET.AGR-002 — Bioinsumo: Sem fotografia registrada')).toBeInTheDocument();
        expect(screen.getByText(/1 projeto incluído sem colocação fica/)).toBeInTheDocument();

        // Um botão por categoria (cada cerimônia baixa a sua) e "Todas".
        fireEvent.click(screen.getByRole('button', { name: 'Baixar slides FETEC FUNDECT' }));
        await waitFor(() => expect(exportarSlidesPresencial).toHaveBeenLastCalledWith('5', 'fetecms_fundect'));
        expect(screen.getByRole('button', { name: 'Baixar slides FETECMS' })).toHaveTextContent('FETECMS (9 slides)');
        fireEvent.click(screen.getByRole('button', { name: 'Baixar slides Todas' }));
        await waitFor(() => expect(exportarSlidesPresencial).toHaveBeenLastCalledWith('5', null));
    });
});
