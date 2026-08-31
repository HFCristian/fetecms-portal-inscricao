import { render, screen, fireEvent, waitFor, within } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

// AppShell puxa router/auth/chat — troca por um passthrough simples.
vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../lib/auth.jsx', () => ({
    useAuth: () => ({ user: { id: 1, role: 'admin' } }),
    extractErrors: () => ({ message: 'erro', fields: {} }),
}));
vi.mock('../lib/admin.js', () => ({
    criarAdmin: vi.fn(),
    getAdmins: vi.fn(() => Promise.resolve({
        data: [
            { id: 1, name: 'Eu Admin', email: 'eu@x.test', is_active: true, role: 'admin', is_demo: false },
            { id: 2, name: 'Outro Admin', email: 'outro@x.test', is_active: false, role: 'admin', is_demo: true },
        ],
        meta: {
            escopos: [
                { id: 7, nome: 'Comunicação', abas: ['comunicacao'], admins: 1, pode_excluir: false },
                { id: 9, nome: 'Credenciamento', abas: ['credenciamento'], admins: 0, pode_excluir: true },
            ],
            escopo_por_admin: {
                2: { escopo_ids: [7], escopos: ['Comunicação'], abas: ['comunicacao'] },
            },
        },
    })),
    atualizarAdmin: vi.fn(() => Promise.resolve({})),
    definirStatusAdmin: vi.fn(() => Promise.resolve({})),
    definirEscoposAdmin: vi.fn(() => Promise.resolve({})),
    definirDemoAdmin: vi.fn(() => Promise.resolve({ data: { is_demo: true } })),
    getContasDemo: vi.fn(() => Promise.resolve({
        data: [
            { id: 30, name: 'Marta Orientadora', email: 'marta@x.test', role: 'orientador', papel: 'Orientador', is_active: true, is_demo: true },
        ],
        meta: { papeis: [
            { value: 'orientador', label: 'Orientador' },
            { value: 'avaliador', label: 'Avaliador' },
        ] },
    })),
    definirDemoParticipante: vi.fn(() => Promise.resolve({
        data: { id: 30, is_demo: false }, meta: { message: 'Modo demo desativado.' },
    })),
}));

import AdminManager from './AdminManager.jsx';
import {
    definirStatusAdmin, definirEscoposAdmin, definirDemoAdmin,
    getContasDemo, definirDemoParticipante,
} from '../lib/admin.js';

describe('AdminManager — lista de administradores', () => {
    it('lista os admins com status e marca (você)', async () => {
        render(<AdminManager />);
        expect(await screen.findByText('Eu Admin')).toBeInTheDocument();
        expect(screen.getByText('Outro Admin')).toBeInTheDocument();
        expect(screen.getByText('(você)')).toBeInTheDocument();
        expect(screen.getByText('Ativo')).toBeInTheDocument();
        expect(screen.getByText('Inativo')).toBeInTheDocument();
    });

    it('reativa um admin inativo ao clicar em "Reativar"', async () => {
        render(<AdminManager />);
        const botao = await screen.findByTitle('Reativar');
        fireEvent.click(botao);
        await waitFor(() => expect(definirStatusAdmin).toHaveBeenCalledWith(2, true));
    });
});

describe('AdminManager — escopos', () => {
    const chip = (adminNome, escopoNome) => within(
        screen.getByRole('group', { name: `Escopos de ${adminNome}` }),
    ).getByRole('button', { name: escopoNome });

    // O backend devolve o mapa inteiro depois de gravar; o mock faz o mesmo,
    // senão o estado da tela zeraria a cada clique.
    beforeEach(() => {
        const mapa = { 1: { escopo_ids: [] }, 2: { escopo_ids: [7], escopos: ['Comunicação'] } };
        definirEscoposAdmin.mockReset().mockImplementation((id, ids) => {
            mapa[id] = { escopo_ids: ids, escopos: [] };
            return Promise.resolve({ ...mapa });
        });
    });

    it('mostra "Acesso total" para quem não tem escopo e marca os de quem tem', async () => {
        render(<AdminManager />);

        // Eu Admin não tem nenhum escopo: acesso total.
        expect(await screen.findByText('Acesso total')).toBeInTheDocument();
        expect(chip('Eu Admin', 'Comunicação')).toHaveAttribute('aria-pressed', 'false');

        // Outro Admin tem "Comunicação" e não tem "Credenciamento".
        expect(chip('Outro Admin', 'Comunicação')).toHaveAttribute('aria-pressed', 'true');
        expect(chip('Outro Admin', 'Credenciamento')).toHaveAttribute('aria-pressed', 'false');
    });

    it('acumula escopos: marcar um segundo manda os dois ids', async () => {
        render(<AdminManager />);

        await screen.findByText('Eu Admin');

        // Eu Admin partia de zero, então vai só o id novo.
        fireEvent.click(chip('Eu Admin', 'Credenciamento'));
        await waitFor(() => expect(definirEscoposAdmin).toHaveBeenCalledWith(1, [9]));

        // Outro Admin já tem o 7: marcar o 9 manda os dois.
        fireEvent.click(chip('Outro Admin', 'Credenciamento'));
        await waitFor(() => expect(definirEscoposAdmin).toHaveBeenCalledWith(2, [7, 9]));
    });

    it('desmarcar o último escopo devolve o acesso total (lista vazia)', async () => {
        render(<AdminManager />);

        await screen.findByText('Eu Admin');

        fireEvent.click(chip('Eu Admin', 'Comunicação'));
        await waitFor(() => expect(definirEscoposAdmin).toHaveBeenCalledWith(1, [7]));

        fireEvent.click(chip('Outro Admin', 'Comunicação'));
        await waitFor(() => expect(definirEscoposAdmin).toHaveBeenCalledWith(2, []));
    });
});

describe('AdminManager — modo demo', () => {
    it('marca quem já está em modo demo', async () => {
        render(<AdminManager />);
        await screen.findByText('Outro Admin');

        // A etiqueta "Modo demo" também aparece na seção de contas demo, abaixo.
        expect(screen.getAllByText('Modo demo').length).toBeGreaterThan(0);
        expect(screen.getByTitle('Desativar o modo demo de Outro Admin'))
            .toHaveAttribute('aria-pressed', 'true');
        expect(screen.getByTitle('Liberar o modo demo para Eu Admin'))
            .toHaveAttribute('aria-pressed', 'false');
    });

    it('libera o modo demo de quem não tem e reflete na linha', async () => {
        render(<AdminManager />);

        fireEvent.click(await screen.findByTitle('Liberar o modo demo para Eu Admin'));

        await waitFor(() => expect(definirDemoAdmin).toHaveBeenCalledWith(1, true));
        expect(await screen.findByTitle('Desativar o modo demo de Eu Admin')).toBeInTheDocument();
    });

    it('desliga o modo demo de quem já tem', async () => {
        definirDemoAdmin.mockResolvedValueOnce({ data: { is_demo: false } });
        render(<AdminManager />);

        fireEvent.click(await screen.findByTitle('Desativar o modo demo de Outro Admin'));

        await waitFor(() => expect(definirDemoAdmin).toHaveBeenCalledWith(2, false));
    });
});

describe('AdminManager — contas demo', () => {
    beforeEach(() => {
        getContasDemo.mockClear();
        definirDemoParticipante.mockClear();
    });

    it('abre listando quem já é demo, sem busca', async () => {
        render(<AdminManager />);

        expect(await screen.findByText('Marta Orientadora')).toBeInTheDocument();
        // O papel aparece na etiqueta da linha (e também na opção do filtro).
        expect(screen.getAllByText('Orientador').length).toBeGreaterThan(0);
        expect(screen.getByTitle('Desativar o modo demo de Marta Orientadora')).toBeInTheDocument();
        // Sem termo, a API é chamada sem filtro: a lista é "os que já são demo".
        await waitFor(() => expect(getContasDemo).toHaveBeenCalledWith({ busca: undefined, papel: undefined }));
    });

    it('busca orientadores e avaliadores pelo nome', async () => {
        render(<AdminManager />);
        await screen.findByText('Marta Orientadora');

        fireEvent.change(screen.getByLabelText('Buscar orientador ou avaliador'), {
            target: { value: 'marta' },
        });

        await waitFor(() => expect(getContasDemo).toHaveBeenCalledWith({ busca: 'marta', papel: undefined }));
    });

    it('liga e desliga o modo demo de um participante', async () => {
        render(<AdminManager />);
        await screen.findByText('Marta Orientadora');

        fireEvent.click(screen.getByTitle('Desativar o modo demo de Marta Orientadora'));

        await waitFor(() => expect(definirDemoParticipante).toHaveBeenCalledWith(30, false));
        expect(await screen.findByText('Modo demo desativado.')).toBeInTheDocument();
    });
});
