import { render, screen, fireEvent, waitFor } from '@testing-library/react';
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

const getPlanta = vi.fn();
const salvarPlanta = vi.fn();
const restaurarPlanta = vi.fn();
vi.mock('../lib/mapaEvento.js', () => ({
    getPlanta: (...a) => getPlanta(...a),
    salvarPlanta: (...a) => salvarPlanta(...a),
    restaurarPlanta: (...a) => restaurarPlanta(...a),
}));

import MapaPlanta from './MapaPlanta.jsx';

const projeto = (titulo) => ({
    projeto_id: 1, numero: 42, estande: '042', titulo, categoria: 'FETECMS',
    area: 'Ciências Agrárias', escola: 'EE Maria Constança', cidade: 'Campo Grande',
    uf: 'MS', orientador: 'Marta', manual: false,
});

const painel = (over = {}) => ({
    edicao: { id: 1, nome: 'XVI FETECMS' },
    layout: {
        nome: 'Planta padrão',
        estandes: [
            { numero: 1, x: 0, y: 0 },
            { numero: 2, x: 1, y: 0 },
            { numero: 42, x: 3, y: 0 },
        ],
        marcacoes: [{ rotulo: 'Entrada', x: 1, y: 2, largura: 2, altura: 1 }],
    },
    versao: 3,
    versao_id: 9,
    salva: true,
    turnos: [
        { value: 'A', label: 'Turno A (matutino)', curto: 'Matutino' },
        { value: 'B', label: 'Turno B (vespertino)', curto: 'Vespertino' },
    ],
    ocupacao: {
        42: { numero: 42, A: projeto('Bioplástico de mandioca'), B: projeto('Sensor de nível') },
        1: { numero: 1, A: projeto('Horta na escola'), B: null },
    },
    ocupados: 2,
    versoes: [
        { id: 9, versao: 3, nome: 'Planta padrão', vigente: true, estandes: 3, autor: 'Pedro', criada_em: '2026-09-10T10:00:00-04:00' },
        { id: 8, versao: 2, nome: 'Planta anterior', vigente: false, estandes: 4, autor: 'Pedro', criada_em: '2026-09-01T10:00:00-04:00' },
    ],
    ...over,
});

describe('MapaPlanta', () => {
    beforeEach(() => {
        getPlanta.mockReset().mockResolvedValue(painel());
        salvarPlanta.mockReset();
        restaurarPlanta.mockReset();
    });

    it('desenha um estande por número, com a entrada marcada', async () => {
        render(<MapaPlanta />);

        expect(await screen.findByRole('button', { name: 'Estande 001' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Estande 042' })).toBeInTheDocument();
        expect(screen.getByText('Entrada')).toBeInTheDocument();
        expect(screen.getByText(/3 estande\(s\) no desenho · 2 com projeto · versão 3 em vigor/)).toBeInTheDocument();
    });

    it('clicar num estande mostra quem apresenta nos dois turnos', async () => {
        render(<MapaPlanta />);

        fireEvent.click(await screen.findByRole('button', { name: 'Estande 042' }));

        expect(await screen.findByText('Estande 042')).toBeInTheDocument();
        expect(screen.getByText('Turno A (matutino)')).toBeInTheDocument();
        expect(screen.getByText('Bioplástico de mandioca')).toBeInTheDocument();
        expect(screen.getByText('Turno B (vespertino)')).toBeInTheDocument();
        expect(screen.getByText('Sensor de nível')).toBeInTheDocument();
    });

    it('estande com um turno só diz que o outro está vazio', async () => {
        render(<MapaPlanta />);

        fireEvent.click(await screen.findByRole('button', { name: 'Estande 001' }));

        expect(await screen.findByText('Horta na escola')).toBeInTheDocument();
        expect(screen.getByText('Sem projeto neste turno.')).toBeInTheDocument();
    });

    it('estande sem projeto nenhum abre o painel vazio, sem quebrar', async () => {
        render(<MapaPlanta />);

        fireEvent.click(await screen.findByRole('button', { name: 'Estande 002' }));

        expect(await screen.findByText('Estande 002')).toBeInTheDocument();
        expect(screen.getAllByText('Sem projeto neste turno.')).toHaveLength(2);
    });

    it('fora do modo de edição não há como mexer no desenho', async () => {
        render(<MapaPlanta />);

        expect(await screen.findByRole('button', { name: 'Editar planta' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Adicionar estande' })).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Salvar como nova versão' })).not.toBeInTheDocument();
    });

    it('acrescenta um estande e só então libera o salvamento', async () => {
        salvarPlanta.mockResolvedValue({ data: painel(), meta: { message: 'Planta salva como uma versão nova.' } });

        render(<MapaPlanta />);
        fireEvent.click(await screen.findByRole('button', { name: 'Editar planta' }));

        // Sem alteração, salvar fica desabilitado: não se cria versão à toa.
        expect(screen.getByRole('button', { name: 'Salvar como nova versão' })).toBeDisabled();

        fireEvent.click(screen.getByRole('button', { name: 'Adicionar estande' }));

        expect(await screen.findByRole('button', { name: 'Estande 043' })).toBeInTheDocument();

        const salvar = screen.getByRole('button', { name: 'Salvar como nova versão' });
        expect(salvar).toBeEnabled();
        fireEvent.click(salvar);

        await waitFor(() => expect(salvarPlanta).toHaveBeenCalled());
        expect(salvarPlanta.mock.calls[0][0].estandes).toHaveLength(4);
        expect(await screen.findByText('Planta salva como uma versão nova.')).toBeInTheDocument();
    });

    it('cancelar a edição devolve o desenho que estava salvo', async () => {
        render(<MapaPlanta />);
        fireEvent.click(await screen.findByRole('button', { name: 'Editar planta' }));
        fireEvent.click(screen.getByRole('button', { name: 'Adicionar estande' }));

        expect(await screen.findByRole('button', { name: 'Estande 043' })).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Cancelar' }));

        await waitFor(() =>
            expect(screen.queryByRole('button', { name: 'Estande 043' })).not.toBeInTheDocument());
        expect(salvarPlanta).not.toHaveBeenCalled();
    });

    it('recusa renumerar para um número que já existe', async () => {
        render(<MapaPlanta />);
        fireEvent.click(await screen.findByRole('button', { name: 'Editar planta' }));
        fireEvent.click(screen.getByRole('button', { name: 'Estande 001' }));

        fireEvent.change(await screen.findByLabelText('Número do estande'), { target: { value: '42' } });
        fireEvent.click(screen.getByRole('button', { name: 'Renumerar' }));

        expect(await screen.findByText('O estande 42 já existe na planta.')).toBeInTheDocument();
    });

    it('lista as versões e restaura uma anterior', async () => {
        restaurarPlanta.mockResolvedValue({
            data: painel(),
            meta: { message: 'Planta da versão 2 restaurada como versão nova.' },
        });

        render(<MapaPlanta />);

        expect(await screen.findByText(/v2 · 4 estandes/)).toBeInTheDocument();
        // A vigente não oferece "Restaurar" — não há para onde voltar.
        expect(screen.getAllByRole('button', { name: 'Restaurar' })).toHaveLength(1);

        fireEvent.click(screen.getByRole('button', { name: 'Restaurar' }));

        // O diálogo explica que restaurar também cria uma versão nova.
        expect(await screen.findByText(/gravado como uma versão nova/)).toBeInTheDocument();
        // O botão da lista e o do diálogo têm o mesmo nome: o do diálogo é o segundo.
        fireEvent.click(screen.getAllByRole('button', { name: 'Restaurar' })[1]);

        await waitFor(() => expect(restaurarPlanta).toHaveBeenCalledWith(8));
    });
});
