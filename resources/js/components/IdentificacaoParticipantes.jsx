import { useEffect, useState } from 'react';
import { Alert, Button } from './ui.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getIdentificacao, urlCodigo, baixarIdentificacao } from '../lib/admin.js';

/**
 * A identificação dos participantes de uma lista final: o **QR Code** e o
 * **código de barras** de cada aluno, orientador e coorientador.
 *
 * Os dois formatos existem porque o evento tem dois leitores: o QR é lido pela
 * câmera de qualquer celular; o código de barras, pelo leitor USB do balcão, que
 * é o que trabalha rápido numa fila.
 *
 * Os desenhos **não vêm no JSON**: cada linha carrega os seus por `<img>`, na
 * rota que os gera. Numa lista de centenas de pessoas, mandar dois SVGs por
 * participante de uma vez seriam megabytes que ninguém olha juntos.
 *
 * A lista abre **recolhida** — quem entra nesta tela costuma vir mexer na
 * composição da lista, e a identificação é o passo seguinte.
 */
export default function IdentificacaoParticipantes({ listaId }) {
    const [dados, setDados] = useState(null);
    const [aberta, setAberta] = useState(false);
    const [baixando, setBaixando] = useState('');
    const [erro, setErro] = useState('');

    useEffect(() => {
        if (!listaId) return;

        getIdentificacao(listaId)
            .then(setDados)
            .catch((e) => setErro(extractErrors(e).message || 'Não foi possível carregar a identificação.'));
    }, [listaId]);

    async function baixar(formato) {
        setBaixando(formato);
        setErro('');
        try {
            await baixarIdentificacao(listaId, formato);
        } catch (e) {
            setErro(extractErrors(e).message || 'Não foi possível baixar os arquivos.');
        } finally {
            setBaixando('');
        }
    }

    const participantes = dados?.participantes ?? [];
    const porPapel = dados?.por_papel ?? {};

    return (
        <section className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5 mt-6">
            <div className="flex flex-wrap items-center gap-3">
                <div className="mr-auto">
                    <h2 className="font-display text-lg font-semibold text-on-surface">
                        Identificação dos participantes
                    </h2>
                    <p className="text-sm text-on-surface-variant">
                        Um QR Code e um código de barras por pessoa da lista, para identificar quem
                        chega ao evento. {dados ? `${dados.total} participante(s)` : 'Carregando…'}
                        {dados && (
                            <> · {porPapel.A ?? 0} aluno(s), {porPapel.O ?? 0} orientador(es), {porPapel.C ?? 0} coorientador(es)</>
                        )}
                    </p>
                </div>

                <Button variant="outline" loading={baixando === 'pdf'} onClick={() => baixar('pdf')}>
                    Etiquetas em PDF
                </Button>
                <Button variant="outline" loading={baixando === 'zip'} onClick={() => baixar('zip')}>
                    Imagens em ZIP
                </Button>
            </div>

            {erro && <div className="mt-3"><Alert>{erro}</Alert></div>}

            {dados && participantes.length > 0 && (
                <>
                    <button
                        type="button"
                        onClick={() => setAberta((a) => !a)}
                        aria-expanded={aberta}
                        className="mt-3 inline-flex items-center gap-1 text-sm text-primary hover:underline"
                    >
                        <span className="material-symbols-outlined text-[18px]">
                            {aberta ? 'expand_less' : 'expand_more'}
                        </span>
                        {aberta ? 'Ocultar os códigos' : 'Ver os códigos um a um'}
                    </button>

                    {aberta && (
                        <ul className="mt-3 divide-y divide-outline-variant/30">
                            {participantes.map((p) => (
                                <li key={p.codigo} className="py-3 flex flex-wrap items-center gap-4">
                                    <span className="min-w-[12rem] flex-1">
                                        <span className="block text-sm font-medium text-on-surface">{p.nome}</span>
                                        <span className="block text-xs text-on-surface-variant">
                                            {p.papel_label} · {p.projeto}
                                        </span>
                                        <code className="block text-xs text-primary-container mt-0.5">{p.codigo}</code>
                                    </span>
                                    <img
                                        src={urlCodigo(p.codigo, 'qr')}
                                        alt={`QR Code de ${p.nome}`}
                                        className="w-16 h-16 shrink-0"
                                        loading="lazy"
                                    />
                                    <img
                                        src={urlCodigo(p.codigo, 'barras')}
                                        alt={`Código de barras de ${p.nome}`}
                                        className="h-12 w-44 shrink-0"
                                        loading="lazy"
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                </>
            )}

            {dados && participantes.length === 0 && (
                <p className="mt-3 text-sm text-on-surface-variant">
                    A lista ainda não tem projetos — sem participantes, não há o que identificar.
                </p>
            )}
        </section>
    );
}
