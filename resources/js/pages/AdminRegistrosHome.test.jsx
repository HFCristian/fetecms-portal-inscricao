import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));

import AdminRegistrosHome from './AdminRegistrosHome.jsx';

describe('AdminRegistrosHome', () => {
    it('oferece as duas seções da trilha', () => {
        render(<AdminRegistrosHome />);

        expect(screen.getByText('Inscrições').closest('a')).toHaveAttribute('href', '/admin/registros/inscricoes');
        expect(screen.getByText('Avaliação Online').closest('a')).toHaveAttribute('href', '/admin/registros/avaliacao');
    });
});
