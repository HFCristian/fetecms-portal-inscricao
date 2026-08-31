import { useEffect, useState } from 'react';
import { Button, Alert } from './ui.jsx';
import BuscaCombobox from './BuscaCombobox.jsx';
import { loadSubareas } from '../lib/catalogos.js';

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

    function trocarArea(nova) {
        setArea(nova);
        if (nova?.id !== projeto.area_id) setSubarea(null);
    }

    const podeSalvar = justificativa.trim().length >= 5;

    function salvar() {
        onSalvar({
            categoria: categoria === '' ? null : categoria,
            area_id: area?.id ?? null,
            subarea_id: subarea?.id ?? null,
            link_video: linkVideo.trim() === '' ? null : linkVideo.trim(),
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
