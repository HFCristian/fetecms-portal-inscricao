import { useState } from 'react';
import { Alert, Button } from './ui.jsx';
import AjudaBalao from './AjudaBalao.jsx';

const nota = (valor) =>
    valor === null || valor === undefined ? '—' : Number(valor).toFixed(2).replace('.', ',');

/**
 * A rubrica da avaliação **presencial**, respondida no estande.
 *
 * É um formulário só, e não o wizard da avaliação online: são 8 perguntas
 * respondidas de pé, em poucos minutos, com a equipe esperando — trocar de
 * passo cinco vezes no celular seria pior do que rolar a tela.
 *
 * A nota não aparece enquanto se responde: ela é calculada no servidor, no
 * envio. Mostrar um número parcial convidaria a ajustar as respostas até ele
 * ficar "certo".
 */
export default function AvaliacaoPresencialModal({
    avaliacao, rubrica, somenteLeitura, salvando, erro, onSalvar, onConcluir, onFechar,
}) {
    const [respostas, setRespostas] = useState(avaliacao.respostas ?? {});
    const [comentario, setComentario] = useState(avaliacao.comentario ?? '');

    const perguntas = rubrica.secoes.flatMap((s) => s.perguntas);
    const respondidas = perguntas.filter((p) => respostas[p.chave] !== undefined).length;
    const completo = respondidas === perguntas.length;

    const dados = () => ({ respostas, comentario: comentario.trim() || null });

    return (
        <div className="fixed inset-0 z-[60] flex items-start justify-center bg-black/50 p-4 overflow-y-auto" role="dialog" aria-modal="true">
            <div className="bg-surface-container-lowest rounded-2xl fetec-card-shadow w-full max-w-2xl my-4">
                <div className="p-5 border-b border-outline-variant/40">
                    <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                            <h3 className="font-display text-lg font-semibold text-on-surface">
                                {avaliacao.projeto.titulo}
                            </h3>
                            <p className="text-sm text-on-surface-variant">
                                {[avaliacao.projeto.categoria, avaliacao.projeto.area, avaliacao.projeto.escola]
                                    .filter(Boolean).join(' · ')}
                            </p>
                            {avaliacao.projeto.local?.estande && (
                                <p className="text-sm font-semibold text-primary-container">
                                    Estande {avaliacao.projeto.local.estande}
                                    {avaliacao.projeto.local.turno_label ? ` · ${avaliacao.projeto.local.turno_label}` : ''}
                                </p>
                            )}
                        </div>
                        <button
                            type="button"
                            onClick={onFechar}
                            aria-label="Fechar"
                            className="shrink-0 text-on-surface-variant hover:text-primary"
                        >
                            <span className="material-symbols-outlined">close</span>
                        </button>
                    </div>
                    {avaliacao.projeto.alunos?.length > 0 && (
                        <p className="text-xs text-on-surface-variant mt-1">
                            {avaliacao.projeto.alunos.join(', ')}
                            {avaliacao.projeto.orientador ? ` · ${avaliacao.projeto.orientador}` : ''}
                        </p>
                    )}
                </div>

                <div className="p-5 space-y-5">
                    {erro && <Alert>{erro}</Alert>}

                    {somenteLeitura && (
                        <Alert type="info">
                            Avaliação enviada{avaliacao.nota !== null ? ` · nota ${nota(avaliacao.nota)} de ${nota(avaliacao.nota_maxima)}` : ''}.
                        </Alert>
                    )}

                    {rubrica.secoes.filter((s) => s.perguntas.length > 0).map((secao) => (
                        <section key={secao.chave}>
                            <h4 className="font-display font-semibold text-on-surface flex items-center gap-1">
                                <span className="material-symbols-outlined text-[20px] text-primary-container">{secao.icone}</span>
                                {secao.titulo}
                            </h4>
                            <p className="text-xs text-on-surface-variant mb-2">{secao.ajuda}</p>

                            <div className="space-y-4">
                                {secao.perguntas.map((pergunta) => (
                                    <div key={pergunta.chave}>
                                        <p className="text-sm text-on-surface flex items-start gap-1">
                                            <span className="flex-1">{pergunta.texto}</span>
                                            {pergunta.ajuda && <AjudaBalao texto={pergunta.ajuda} />}
                                        </p>
                                        <div className="flex flex-wrap gap-2 mt-2">
                                            {rubrica.escala.map((opcao) => {
                                                const marcada = respostas[pergunta.chave] === opcao.valor;

                                                return (
                                                    <button
                                                        key={opcao.valor}
                                                        type="button"
                                                        disabled={somenteLeitura}
                                                        aria-pressed={marcada}
                                                        onClick={() => setRespostas((r) => ({ ...r, [pergunta.chave]: opcao.valor }))}
                                                        className={`text-xs font-semibold px-3 py-2 rounded-lg border transition-colors ${
                                                            marcada
                                                                ? 'bg-primary-container text-on-primary border-primary-container'
                                                                : 'border-outline-variant text-on-surface-variant hover:bg-surface-variant'
                                                        }`}
                                                    >
                                                        {opcao.rotulo}
                                                    </button>
                                                );
                                            })}
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </section>
                    ))}

                    <label className="block">
                        <span className="text-sm font-semibold text-on-surface">
                            Parecer para a equipe (opcional)
                        </span>
                        <textarea
                            rows={4}
                            maxLength={2000}
                            disabled={somenteLeitura}
                            value={comentario}
                            onChange={(e) => setComentario(e.target.value)}
                            className="mt-1 w-full rounded-lg border border-outline-variant bg-surface-container-lowest px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:outline-none focus:ring-2 focus:ring-primary-container/20"
                        />
                    </label>
                </div>

                <div className="p-5 border-t border-outline-variant/40 flex flex-wrap items-center justify-between gap-3">
                    <span className="text-xs text-on-surface-variant">
                        {respondidas} de {perguntas.length} perguntas respondidas
                    </span>
                    <div className="flex gap-2">
                        <Button type="button" variant="outline" onClick={onFechar}>Fechar</Button>
                        {!somenteLeitura && (
                            <>
                                <Button type="button" variant="outline" loading={salvando} onClick={() => onSalvar(dados())}>
                                    Salvar rascunho
                                </Button>
                                <Button type="button" loading={salvando} disabled={!completo} onClick={() => onConcluir(dados())}>
                                    Enviar avaliação
                                </Button>
                            </>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
