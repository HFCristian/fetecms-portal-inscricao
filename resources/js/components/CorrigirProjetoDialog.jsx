import { useEffect, useState } from 'react';
import { Button, Alert } from './ui.jsx';
import BuscaCombobox from './BuscaCombobox.jsx';
import { loadSubareas } from '../lib/catalogos.js';
import { buscarOrientadores } from '../lib/admin.js';

const selectClass = 'w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20';
const campoClass = selectClass;

/**
 * Correção manual de um projeto já submetido (Projetos submetidos → Editar).
 *
 * O orientador não pode mais mexer depois de submeter, então isto é um escape
 * do edital: por isso a justificativa é obrigatória e cada campo alterado vira
 * um registro em Registros → Projetos.
 *
 * Logo abaixo da categoria vem a **série de cada aluno**, só para leitura: é ela
 * que diz se a categoria está certa (FETEC Jr é do fundamental, FETECMS e
 * FUNDECT do médio), e conferir uma sem a outra é o caminho para corrigir
 * errado. Editar aluno continua sendo do orientador — ou do admin, pela tela de
 * rascunho.
 *
 * O **orientador** pode ser trocado por outra conta de orientador (o projeto
 * muda de dono, então a busca é entre contas que já existem — o servidor
 * procura por nome ou e-mail). O **coorientador** não tem conta: é uma linha de
 * dados do projeto, e aqui ele pode ser editado, incluído ou removido.
 */
export default function CorrigirProjetoDialog({ projeto, areas, categorias, salvando, erro, onSalvar, onFechar }) {
    const [categoria, setCategoria] = useState(projeto.categoria ?? '');
    const [area, setArea] = useState(
        projeto.area_id ? { id: projeto.area_id, nome: projeto.area } : null,
    );
    const [subareas, setSubareas] = useState([]);
    const [subarea, setSubarea] = useState(
        projeto.subarea_id ? { id: projeto.subarea_id, nome: projeto.subarea } : null,
    );
    const [linkVideo, setLinkVideo] = useState(projeto.link_video ?? '');
    const [justificativa, setJustificativa] = useState('');

    // Orientador: o dono atual, trocável por outra conta.
    const [orientador, setOrientador] = useState(projeto.orientador ?? null);
    const [buscaOrientador, setBuscaOrientador] = useState('');
    const [candidatos, setCandidatos] = useState([]);
    const [trocandoOrientador, setTrocandoOrientador] = useState(false);

    // Coorientador: `null` significa "sem coorientador" (inclui remover).
    const [coorientador, setCoorientador] = useState(projeto.coorientador ?? null);

    // Só leitura: a série vem pronta do backend (App\Support\ClassesEscolares).
    const alunos = projeto.alunos ?? [];

    // Subáreas seguem a área escolhida; trocar de área zera a subárea, porque a
    // antiga pertence a outra árvore.
    useEffect(() => {
        if (!area?.id) {
            setSubareas([]);
            return;
        }
        loadSubareas(area.id).then(setSubareas).catch(() => setSubareas([]));
    }, [area?.id]);

    // A base de orientadores é grande: quem filtra é o servidor.
    useEffect(() => {
        if (!trocandoOrientador) return undefined;

        const t = setTimeout(() => {
            buscarOrientadores(buscaOrientador.trim())
                .then(setCandidatos)
                .catch(() => setCandidatos([]));
        }, 300);

        return () => clearTimeout(t);
    }, [buscaOrientador, trocandoOrientador]);

    function mudarCoorientador(campo, valor) {
        setCoorientador((c) => ({ ...(c ?? { nome: '', email: '', cpf: '', telefone: '' }), [campo]: valor }));
    }

    function trocarArea(nova) {
        setArea(nova);
        if (nova?.id !== projeto.area_id) setSubarea(null);
    }

    const podeSalvar = justificativa.trim().length >= 5;

    function salvar() {
        // `coorientador: null` é a forma de REMOVER, então ele viaja sempre —
        // diferente dos demais campos, em que não mandar significa "não mexi".
        const co = coorientador === null || (coorientador.nome ?? '').trim() === ''
            ? null
            : {
                nome: coorientador.nome.trim(),
                email: (coorientador.email ?? '').trim(),
                cpf: (coorientador.cpf ?? '').trim(),
                telefone: (coorientador.telefone ?? '').trim() || null,
            };

        onSalvar({
            categoria: categoria === '' ? null : categoria,
            area_id: area?.id ?? null,
            subarea_id: subarea?.id ?? null,
            link_video: linkVideo.trim() === '' ? null : linkVideo.trim(),
            user_id: orientador?.id ?? null,
            coorientador: co,
            justificativa: justificativa.trim(),
        });
    }

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" role="dialog" aria-modal="true">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-md p-6 space-y-4 max-h-[90vh] overflow-y-auto">
                <div>
                    <h3 className="font-display text-lg font-semibold text-on-surface">Editar projeto</h3>
                    <p className="text-sm text-on-surface-variant truncate">{projeto.titulo}</p>
                </div>

                <Alert>{erro}</Alert>

                <div className="space-y-1">
                    <label className="text-sm font-semibold text-on-surface" htmlFor="corrigir-categoria">Categoria</label>
                    <select
                        id="corrigir-categoria"
                        className={selectClass}
                        value={categoria}
                        onChange={(e) => setCategoria(e.target.value)}
                    >
                        <option value="">Sem categoria</option>
                        {categorias.map((c) => (
                            <option key={c.value} value={c.value}>{c.label}</option>
                        ))}
                    </select>
                </div>

                <div className="space-y-1">
                    <p className="text-sm font-semibold text-on-surface">Equipe</p>
                    {alunos.length === 0 ? (
                        <p className="text-xs text-on-surface-variant">Nenhum aluno cadastrado neste projeto.</p>
                    ) : (
                        <ul className="rounded-lg border border-outline-variant/40 divide-y divide-outline-variant/30">
                            {alunos.map((a) => (
                                <li key={a.id} className="px-3 py-2 flex items-baseline justify-between gap-3">
                                    <span className="text-sm text-on-surface truncate">{a.nome}</span>
                                    <span className="text-xs text-on-surface-variant whitespace-nowrap">
                                        {a.serie ?? 'Série não informada'}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <div className="space-y-1">
                    <label className="text-sm font-semibold text-on-surface">Área do conhecimento</label>
                    <BuscaCombobox
                        options={areas.map((a) => ({ id: a.id, nome: a.nome }))}
                        value={area}
                        onChange={trocarArea}
                        placeholder="Digite o nome da área…"
                    />
                </div>

                <div className="space-y-1">
                    <label className="text-sm font-semibold text-on-surface">Subárea</label>
                    <BuscaCombobox
                        options={subareas.map((s) => ({ id: s.id, nome: s.nome }))}
                        value={subarea}
                        onChange={setSubarea}
                        placeholder={area ? 'Digite o nome da subárea…' : 'Escolha a área primeiro'}
                        disabled={!area}
                    />
                </div>

                <div className="space-y-1">
                    <p className="text-sm font-semibold text-on-surface">Orientador</p>
                    <div className="rounded-lg border border-outline-variant/40 px-3 py-2">
                        <p className="text-sm text-on-surface truncate">{orientador?.nome ?? 'Sem orientador'}</p>
                        {orientador?.email && <p className="text-xs text-on-surface-variant truncate">{orientador.email}</p>}
                    </div>
                    {trocandoOrientador ? (
                        <div className="space-y-2">
                            <input
                                type="text"
                                aria-label="Buscar orientador por nome ou e-mail"
                                className={campoClass}
                                value={buscaOrientador}
                                onChange={(e) => setBuscaOrientador(e.target.value)}
                                placeholder="Buscar por nome ou e-mail…"
                            />
                            <ul className="max-h-40 overflow-y-auto rounded-lg border border-outline-variant/40 divide-y divide-outline-variant/30">
                                {candidatos.length === 0 ? (
                                    <li className="px-3 py-2 text-xs text-on-surface-variant">Nenhum orientador encontrado.</li>
                                ) : candidatos.map((c) => (
                                    <li key={c.id}>
                                        <button
                                            type="button"
                                            onClick={() => { setOrientador(c); setTrocandoOrientador(false); }}
                                            className="w-full text-left px-3 py-2 hover:bg-surface-variant/40 transition-colors"
                                        >
                                            <span className="block text-sm text-on-surface truncate">{c.nome}</span>
                                            <span className="block text-xs text-on-surface-variant truncate">{c.email}</span>
                                        </button>
                                    </li>
                                ))}
                            </ul>
                            <button
                                type="button"
                                onClick={() => setTrocandoOrientador(false)}
                                className="text-xs text-on-surface-variant hover:text-primary"
                            >
                                Manter o orientador atual
                            </button>
                        </div>
                    ) : (
                        <button
                            type="button"
                            onClick={() => { setTrocandoOrientador(true); setBuscaOrientador(''); }}
                            className="text-xs text-primary-container hover:underline"
                        >
                            Trocar o orientador
                        </button>
                    )}
                    {orientador?.id !== projeto.orientador?.id && (
                        <p className="text-xs text-error">
                            O projeto muda de dono: o orientador anterior perde o acesso a ele.
                        </p>
                    )}
                </div>

                <div className="space-y-1">
                    <div className="flex items-center justify-between gap-2">
                        <p className="text-sm font-semibold text-on-surface">Coorientador</p>
                        {coorientador === null ? (
                            <button
                                type="button"
                                onClick={() => setCoorientador({ nome: '', email: '', cpf: '', telefone: '' })}
                                className="text-xs text-primary-container hover:underline"
                            >
                                Incluir coorientador
                            </button>
                        ) : (
                            <button
                                type="button"
                                onClick={() => setCoorientador(null)}
                                className="text-xs text-error hover:underline"
                            >
                                Remover coorientador
                            </button>
                        )}
                    </div>
                    {coorientador === null ? (
                        <p className="text-xs text-on-surface-variant">Este projeto não tem coorientador.</p>
                    ) : (
                        <div className="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <input
                                type="text" aria-label="Nome do coorientador" className={campoClass}
                                value={coorientador.nome ?? ''} placeholder="Nome"
                                onChange={(e) => mudarCoorientador('nome', e.target.value)}
                            />
                            <input
                                type="email" aria-label="E-mail do coorientador" className={campoClass}
                                value={coorientador.email ?? ''} placeholder="E-mail"
                                onChange={(e) => mudarCoorientador('email', e.target.value)}
                            />
                            <input
                                type="text" aria-label="CPF do coorientador" className={campoClass}
                                value={coorientador.cpf ?? ''} placeholder="CPF"
                                onChange={(e) => mudarCoorientador('cpf', e.target.value)}
                            />
                            <input
                                type="text" aria-label="Telefone do coorientador" className={campoClass}
                                value={coorientador.telefone ?? ''} placeholder="Telefone"
                                onChange={(e) => mudarCoorientador('telefone', e.target.value)}
                            />
                        </div>
                    )}
                </div>

                <div className="space-y-1">
                    <label className="text-sm font-semibold text-on-surface" htmlFor="corrigir-video">Link do vídeo</label>
                    <input
                        id="corrigir-video"
                        type="url"
                        className={campoClass}
                        value={linkVideo}
                        onChange={(e) => setLinkVideo(e.target.value)}
                        placeholder="https://youtu.be/…"
                    />
                </div>

                <div className="space-y-1">
                    <label className="text-sm font-semibold text-on-surface" htmlFor="corrigir-justificativa">
                        Justificativa <span className="text-error">*</span>
                    </label>
                    <textarea
                        id="corrigir-justificativa"
                        rows={3}
                        className={campoClass}
                        value={justificativa}
                        onChange={(e) => setJustificativa(e.target.value)}
                        placeholder="Por que este projeto está sendo alterado?"
                    />
                    <p className="text-xs text-on-surface-variant">
                        Fica registrada em Registros → Projetos, junto do que mudou e de quem mudou.
                    </p>
                </div>

                <div className="flex justify-end gap-2 pt-1">
                    <Button type="button" variant="outline" onClick={onFechar}>Cancelar</Button>
                    <Button type="button" loading={salvando} disabled={!podeSalvar} onClick={salvar}>
                        Salvar alterações
                    </Button>
                </div>
            </div>
        </div>
    );
}
