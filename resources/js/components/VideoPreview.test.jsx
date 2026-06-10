import { render, screen } from '@testing-library/react';
import { describe, it, expect } from 'vitest';
import VideoPreview from './VideoPreview.jsx';

describe('VideoPreview', () => {
    it('mostra o player para um link do YouTube', () => {
        render(<VideoPreview url="https://youtu.be/abcdefghijk" />);
        expect(screen.getByTitle('Pré-visualização do vídeo')).toBeInTheDocument();
    });

    it('avisa quando a URL não é reconhecida', () => {
        render(<VideoPreview url="https://exemplo.com/foo" />);
        expect(screen.getByText(/URL não reconhecida/i)).toBeInTheDocument();
    });

    it('não renderiza nada sem URL', () => {
        const { container } = render(<VideoPreview url="" />);
        expect(container).toBeEmptyDOMElement();
    });
});
