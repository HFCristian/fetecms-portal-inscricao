/**
 * Pré-visualização do link de vídeo. Reconhece YouTube, Vimeo e Google Drive,
 * extrai o ID e embute o player para o orientador confirmar visualmente que o
 * vídeo é válido/público. URLs não reconhecidas recebem aviso.
 */
function parseVideo(url) {
    if (!url) return null;
    const u = url.trim();

    // YouTube: watch?v=, youtu.be/, shorts/, embed/
    const yt =
        u.match(/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([\w-]{11})/);
    if (yt) return { provider: 'YouTube', embed: `https://www.youtube.com/embed/${yt[1]}` };

    // Vimeo: vimeo.com/123456789
    const vimeo = u.match(/vimeo\.com\/(?:video\/)?(\d+)/);
    if (vimeo) return { provider: 'Vimeo', embed: `https://player.vimeo.com/video/${vimeo[1]}` };

    // Google Drive: /file/d/ID/
    const drive = u.match(/drive\.google\.com\/file\/d\/([\w-]+)/);
    if (drive) return { provider: 'Google Drive', embed: `https://drive.google.com/file/d/${drive[1]}/preview` };

    return { provider: null };
}

export default function VideoPreview({ url }) {
    if (!url || !url.trim()) return null;

    const info = parseVideo(url);

    if (!info?.embed) {
        return (
            <div className="mt-2 flex items-center gap-2 rounded-lg bg-error-container text-on-error-container p-3 text-sm">
                <span className="material-symbols-outlined text-[20px]">error</span>
                URL não reconhecida. Use um link do YouTube, Vimeo ou Google Drive (público).
            </div>
        );
    }

    return (
        <div className="mt-2">
            <div className="flex items-center gap-2 text-sm text-secondary mb-2">
                <span className="material-symbols-outlined text-[20px]" style={{ fontVariationSettings: "'FILL' 1" }}>
                    check_circle
                </span>
                {info.provider} reconhecido — confira a reprodução abaixo.
            </div>
            <div className="relative w-full overflow-hidden rounded-lg border border-outline-variant" style={{ aspectRatio: '16 / 9' }}>
                <iframe
                    src={info.embed}
                    title="Pré-visualização do vídeo"
                    className="absolute inset-0 w-full h-full"
                    allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                    allowFullScreen
                    loading="lazy"
                />
            </div>
        </div>
    );
}
