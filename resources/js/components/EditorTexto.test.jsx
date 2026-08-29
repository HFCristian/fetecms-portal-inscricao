import { render, screen, waitFor } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';

import EditorTexto from './EditorTexto.jsx';

describe('EditorTexto', () => {
    it('mostra a barra com negrito, itálico, sublinhado, traçado e imagem', async () => {
        render(<EditorTexto valor="<p>Olá</p>" onChange={vi.fn()} onInserirImagem={vi.fn()} />);

        expect(await screen.findByLabelText('Negrito')).toBeInTheDocument();
        expect(screen.getByLabelText('Itálico')).toBeInTheDocument();
        expect(screen.getByLabelText('Sublinhado')).toBeInTheDocument();
        expect(screen.getByLabelText('Traçado')).toBeInTheDocument();
        expect(screen.getByLabelText('Inserir imagem')).toBeInTheDocument();
    });

    it('desabilita a imagem quando o limite foi atingido', async () => {
        render(
            <EditorTexto valor="" onChange={vi.fn()} onInserirImagem={vi.fn()} podeInserirImagem={false} />,
        );

        expect(await screen.findByLabelText('Inserir imagem')).toBeDisabled();
    });

    it('só aceita imagem no seletor de arquivo do corpo', async () => {
        const { container } = render(
            <EditorTexto valor="" onChange={vi.fn()} onInserirImagem={vi.fn()} />,
        );

        await screen.findByLabelText('Inserir imagem');
        const seletor = container.querySelector('input[type="file"]');
        expect(seletor).toHaveAttribute('accept', 'image/png,image/jpeg,image/gif,image/webp');
    });

    it('entrega o conteúdo inicial ao editor', async () => {
        const onEditorPronto = vi.fn();
        render(
            <EditorTexto
                valor="<p>Olá mundo</p>"
                onChange={vi.fn()}
                onInserirImagem={vi.fn()}
                onEditorPronto={onEditorPronto}
            />,
        );

        await waitFor(() => expect(onEditorPronto).toHaveBeenCalled());
        expect(onEditorPronto.mock.calls[0][0].getHTML()).toContain('Olá mundo');
    });
});
