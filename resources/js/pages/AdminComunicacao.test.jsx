import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

vi.mock('../components/AppShell.jsx', () => ({ default: ({ children }) => <div>{children}</div> }));
vi.mock('react-router-dom', () => ({ Link: ({ children, to }) => <a href={to}>{children}</a> }));

import AdminComunicacao from './AdminComunicacao.jsx';

describe('AdminComunicacao', () => {
    it('leva à mala direta e aos avisos', () => {
        render(<AdminComunicacao />);

        expect(screen.getByText('Mala direta').closest('a')).toHaveAttribute('href', '/admin/mala-direta');
        expect(screen.getByText('Avisos').closest('a')).toHaveAttribute('href', '/admin/comunicacao/avisos');
    });
});
