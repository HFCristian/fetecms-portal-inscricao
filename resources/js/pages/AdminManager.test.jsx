import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

// AppShell puxa router/auth/chat — troca por um passthrough simples.
vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('../lib/auth.jsx', () => ({
    useAuth: () => ({ user: { id: 1, role: 'admin' } }),
    extractErrors: () => ({ message: 'erro', fields: {} }),
}));
vi.mock('../lib/admin.js', () => ({
    criarAdmin: vi.fn(),
    getAdmins: vi.fn(() => Promise.resolve([
        { id: 1, name: 'Eu Admin', email: 'eu@x.test', is_active: true, role: 'admin' },
        { id: 2, name: 'Outro Admin', email: 'outro@x.test', is_active: false, role: 'admin' },
    ])),
    atualizarAdmin: vi.fn(() => Promise.resolve({})),
    definirStatusAdmin: vi.fn(() => Promise.resolve({})),
}));

import AdminManager from './AdminManager.jsx';
import { definirStatusAdmin } from '../lib/admin.js';

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
