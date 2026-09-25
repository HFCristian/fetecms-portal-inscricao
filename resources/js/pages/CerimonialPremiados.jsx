import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import AppShell from '../components/AppShell.jsx';
import { Alert, Button } from '../components/ui.jsx';
import { IconePessoa } from './CerimonialFicha.jsx';
import { extractErrors } from '../lib/auth.jsx';
import { getPremiados } from '../lib/cerimonial.js';
import { useModoTeste } from '../lib/modoTeste.js';

/**
 * Um projeto premiado: os integrantes como bustos, verde para quem chegou.
 *
 * O ícone é o formato certo aqui porque a pergunta no palco é visual e
 * instantânea — "esta equipe está inteira?" —, e uma tabela de nomes obrigaria
 * a ler linha por linha enquanto o locutor espera.
 */
function CartaoProjeto({ projeto }) {
    return (
        <li className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-5">
            <div className="flex flex-wrap items-start justify-between gap-2">
                <div className="min-w-0">
                    <h2 className="font-display font-semibold text-on-surface">{projeto.titulo}</h2>
                    <p className="text-xs text-on-surface-variant">
                        {[projeto.categoria, projeto.area].filter(Boolean).join(' · ')}
                    </p>
                    <p className="text-xs text-on-surface-variant">{projeto.escola}</p>
                </div>
                <span
                    className={`rounded-full px-2 py-0.5 text-[11px] font-semibold shrink-0 ${
                        projeto.completo
                            ? 'bg-green-100 text-green-900'
                            : projeto.presentes > 0
                                ? 'bg-primary-fixed text-primary-container'
                                : 'bg-surface-variant text-on-surface-variant'
                    }`}
                >
                    {projeto.presentes} de {projeto.total}
                    {projeto.completo ? ' · completa' : ''}
                </span>
            </div>

            <div className="flex flex-wrap gap-1.5 mt-2">
                {projeto.premiacoes.map((p) => (
                    <span
                        key={p.id}
                        className="inline-flex items-center gap-1 rounded-full bg-primary-fixed text-primary-container px-2 py-0.5 text-[11px] font-semibold"
                    >
                        <span className="material-symbols-outlined text-[14px]">
                            {p.tipo === 'premio' ? 'emoji_events' : 'badge'}
                        </span>
                        {p.nome}
                    </span>
                ))}
            </div>

            <ul className="flex flex-wrap gap-4 mt-4">
                {projeto.pessoas.map((pessoa) => (
                    <li key={pessoa.chave} className="flex flex-col items-center w-24 text-center">
                        <IconePessoa presente={pessoa.presente} tamanho={44} />
                        <span
                            className={`text-xs mt-1 leading-tight ${
                                pessoa.presente ? 'text-on-surface font-semibold' : 'text-on-surface-variant'
                            }`}
                            title={pessoa.checkin_em ? `Check-in em ${pessoa.checkin_em}` : 'Ainda não chegou'}
                        >
                            {pessoa.nome}
                        </span>
                        <span className="text-[11px] text-on-surface-variant">{pessoa.papel_label}</span>
                    </li>
                ))}
            </ul>

            <Link
                to={`/admin/cerimonial/projetos/${projeto.id}`}
                className="inline-flex items-center gap-1 text-sm font-semibold text-primary hover:underline mt-3"
            >
                <span className="material-symbols-outlined text-[18px]">how_to_reg</span>
                Abrir a ficha
            </Link>
        </li>
    );
}

/**
 * Cerimonial → Visão Geral → **Premiados**.
 *
 * Um cartão por projeto premiado — o que recebeu credencial **ou** prêmio —,
 * com um busto por integrante: verde para quem já fez check-in, cinza para
 * quem falta. É a tela que responde, minutos antes de chamar a equipe ao
 * palco, se ela está inteira.
 */
export default function CerimonialPremiados() {
    const [teste] = useModoTeste();
    const [projetos, setProjetos] = useState(null);
    const [erro, setErro] = useState('');
    const [busca, setBusca] = useState('');
    const [soIncompletos, setSoIncompletos] = useState(false);

    const carregar = useCallback(() => getPremiados(teste)
        .then((dados) => { setProjetos(dados); setErro(''); })
        .catch((e) => {
            const { message } = extractErrors(e);
            setErro(message || 'Não foi possível carregar os premiados.');
            setProjetos([]);
        }), [teste]);

    useEffect(() => { carregar(); }, [carregar]);

    const lista = (projetos ?? []).filter((p) => {
        if (soIncompletos && p.completo) return false;
        const alvo = `${p.titulo} ${p.escola ?? ''} ${p.pessoas.map((x) => x.nome).join(' ')}`;
        return alvo.toLowerCase().includes(busca.trim().toLowerCase());
    });

    return (
        <AppShell>
            <Link to="/admin/cerimonial/visao-geral" className="inline-flex items-center gap-1 text-sm text-on-surface-variant hover:text-primary mb-3">
                <span className="material-symbols-outlined text-[18px]">arrow_back</span> Visão Geral
            </Link>

            <div className="flex flex-wrap items-start justify-between gap-3 mb-1">
                <h1 className="font-display text-2xl font-semibold text-primary">Projetos premiados</h1>
                <Button type="button" variant="outline" onClick={carregar}>
                    <span className="material-symbols-outlined text-[20px]">refresh</span>
                    Atualizar
                </Button>
            </div>
            <p className="text-on-surface-variant mb-4 max-w-3xl">
                Um cartão por projeto que recebeu <strong>credencial ou prêmio</strong>. O busto
                fica <span className="text-green-700 font-semibold">verde</span> quando a pessoa já
                fez check-in — é o jeito de ver, antes de chamar ao palco, se a equipe está inteira.
            </p>

            {erro && <div className="mb-4 max-w-3xl"><Alert>{erro}</Alert></div>}

            <div className="flex flex-wrap items-center gap-3 mb-4">
                <input
                    value={busca}
                    onChange={(e) => setBusca(e.target.value)}
                    placeholder="Filtrar por projeto, escola ou participante…"
                    aria-label="Filtrar premiados"
                    className="flex-1 min-w-[16rem] bg-surface border border-outline-variant rounded-lg px-3 py-2 text-sm text-on-surface focus:border-primary-container focus:ring-2 focus:ring-primary-container/20 outline-none"
                />
                <label className="flex items-center gap-2 text-sm text-on-surface cursor-pointer select-none">
                    <input
                        type="checkbox"
                        className="w-4 h-4 accent-[color:var(--color-primary-container,#43157a)]"
                        checked={soIncompletos}
                        onChange={(e) => setSoIncompletos(e.target.checked)}
                    />
                    Só equipes incompletas
                </label>
            </div>

            {projetos === null ? (
                <div className="text-center py-10 text-on-surface-variant">
                    <span className="inline-block w-8 h-8 rounded-full border-4 border-on-surface-variant/25 border-t-primary animate-spin align-[-0.2em]" role="status" aria-label="Carregando" />
                </div>
            ) : lista.length === 0 ? (
                <div className="bg-surface-container-lowest rounded-xl fetec-card-shadow p-6 text-center text-sm text-on-surface-variant max-w-3xl">
                    {projetos.length === 0
                        ? 'Nenhum projeto recebeu credencial ou prêmio nesta edição — cadastre-os em Avaliação presencial → Credenciais e Prêmios.'
                        : 'Nenhum projeto com esse filtro.'}
                </div>
            ) : (
                <ul className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    {lista.map((p) => <CartaoProjeto key={p.id} projeto={p} />)}
                </ul>
            )}
        </AppShell>
    );
}
