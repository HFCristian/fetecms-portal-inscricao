import { useCallback, useEffect, useRef } from 'react';
import { useEditor, EditorContent } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import Image from '@tiptap/extension-image';

/** Um botão da barra de formatação. */
function Ferramenta({ icone, titulo, ativo, onClick, desabilitado }) {
    return (
        <button
            type="button"
            title={titulo}
            aria-label={titulo}
            aria-pressed={ativo || false}
            disabled={desabilitado}
            onClick={onClick}
            className={`w-9 h-9 rounded-lg inline-flex items-center justify-center transition-colors disabled:opacity-40 ${
                ativo
                    ? 'bg-primary-container text-on-primary'
                    : 'text-on-surface-variant hover:bg-surface-variant'
            }`}
        >
            <span className="material-symbols-outlined text-[20px]">{icone}</span>
        </button>
    );
}

/**
 * Editor de texto do comunicado: negrito, itálico, sublinhado, traçado, listas
 * e imagens no corpo (arrastáveis para reposicionar).
 *
 * O valor sai como **HTML** — é o que vai virar o e-mail —, e cada imagem
 * carrega o `data-arquivo-id` do arquivo no servidor: é por ele que o envio
 * troca o `src` pela imagem embutida na mensagem.
 */
export default function EditorTexto({
    valor, onChange, onInserirImagem, podeInserirImagem = true, placeholder, onEditorPronto,
}) {
    const arquivoRef = useRef(null);

    const editor = useEditor({
        extensions: [
            StarterKit.configure({
                // Título e bloco de código não fazem sentido num comunicado.
                heading: false,
                codeBlock: false,
            }),
            Image.configure({ inline: false, allowBase64: false }),
        ],
        content: valor || '',
        editorProps: {
            attributes: {
                class: 'fetec-editor min-h-52 px-3 py-2 outline-none',
                'aria-label': 'Texto da mensagem',
                'data-placeholder': placeholder ?? '',
            },
        },
        onUpdate: ({ editor: atual }) => onChange(atual.getHTML()),
    });

    // Só sincroniza quando o valor de fora realmente diverge (inserir variável,
    // por exemplo): reescrever a cada tecla jogaria o cursor para o fim.
    useEffect(() => {
        if (editor && valor !== undefined && valor !== editor.getHTML()) {
            editor.commands.setContent(valor || '', { emitUpdate: false });
        }
    }, [editor, valor]);

    // A página precisa da instância para inserir as variáveis na posição do
    // cursor (os botões ficam fora do editor).
    useEffect(() => {
        if (editor) onEditorPronto?.(editor);
    }, [editor, onEditorPronto]);

    const escolherImagem = useCallback(() => arquivoRef.current?.click(), []);

    async function aoEscolher(evento) {
        const arquivo = evento.target.files?.[0];
        evento.target.value = '';
        if (!arquivo || !editor) return;

        const imagem = await onInserirImagem(arquivo);
        if (!imagem) return;

        editor.chain().focus().setImage({ src: imagem.url, alt: imagem.nome }).run();
        // O id do arquivo viaja no HTML: é ele que o envio usa para embutir a
        // imagem no e-mail em vez de deixar um link para o portal.
        editor.commands.command(({ tr, state }) => {
            state.doc.descendants((node, pos) => {
                if (node.type.name === 'image' && node.attrs.src === imagem.url) {
                    tr.setNodeMarkup(pos, undefined, { ...node.attrs, 'data-arquivo-id': imagem.id });
                }
            });
            return true;
        });
        onChange(editor.getHTML());
    }

    if (!editor) return null;

    return (
        <div className="rounded-lg border border-outline-variant bg-surface-container-lowest focus-within:border-primary-container focus-within:ring-2 focus-within:ring-primary-container/20">
            <div className="flex flex-wrap items-center gap-1 border-b border-outline-variant/60 p-1">
                <Ferramenta
                    icone="format_bold" titulo="Negrito"
                    ativo={editor.isActive('bold')}
                    onClick={() => editor.chain().focus().toggleBold().run()}
                />
                <Ferramenta
                    icone="format_italic" titulo="Itálico"
                    ativo={editor.isActive('italic')}
                    onClick={() => editor.chain().focus().toggleItalic().run()}
                />
                <Ferramenta
                    icone="format_underlined" titulo="Sublinhado"
                    ativo={editor.isActive('underline')}
                    onClick={() => editor.chain().focus().toggleUnderline().run()}
                />
                <Ferramenta
                    icone="strikethrough_s" titulo="Traçado"
                    ativo={editor.isActive('strike')}
                    onClick={() => editor.chain().focus().toggleStrike().run()}
                />
                <span className="w-px h-6 bg-outline-variant/60 mx-1" />
                <Ferramenta
                    icone="format_list_bulleted" titulo="Lista"
                    ativo={editor.isActive('bulletList')}
                    onClick={() => editor.chain().focus().toggleBulletList().run()}
                />
                <Ferramenta
                    icone="format_list_numbered" titulo="Lista numerada"
                    ativo={editor.isActive('orderedList')}
                    onClick={() => editor.chain().focus().toggleOrderedList().run()}
                />
                <span className="w-px h-6 bg-outline-variant/60 mx-1" />
                <Ferramenta
                    icone="image" titulo="Inserir imagem"
                    desabilitado={!podeInserirImagem}
                    onClick={escolherImagem}
                />
            </div>

            <EditorContent editor={editor} />

            <input
                ref={arquivoRef}
                type="file"
                accept="image/png,image/jpeg,image/gif,image/webp"
                className="hidden"
                aria-hidden="true"
                tabIndex={-1}
                onChange={aoEscolher}
            />
        </div>
    );
}
