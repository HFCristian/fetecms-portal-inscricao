import { describe, it, expect } from 'vitest';
import {
    chaveDoEstande, corDoEstande, legendaComite, resumoComite, filtrarAvaliadores, localDoProjeto, SEM_PROJETO,
    designacoesPendentes, enviadas, colunasDoAvaliador,
} from './comiteAcompanhamento.js';

const linha = (over = {}) => ({
    numero: 1, titulo: 'Projeto', situacao: 'pendente', concluidas: 0, em_avaliacao: 0, designadas: 0,
    pronto: true, area: 'Ciências Agrárias', area_sigla: 'AGR', ...over,
});

const avaliador = (over = {}) => ({
    id: 1, nome: 'Ana', area: 'Agrárias', situacao: 'livre', em_avaliacao: [], designadas: [], concluidas: [],
    designacoes_comite: [], ...over,
});

describe('cores do mapa do comitê', () => {
    it('o que está acontecendo manda na cor', () => {
        expect(corDoEstande(linha({ situacao: 'em_avaliacao', concluidas: 1 })).fill).toBe('#43157A');
        expect(corDoEstande(linha({ situacao: 'avaliado', concluidas: 1 })).fill).toBe('#0ca30c');
        expect(corDoEstande(linha({ situacao: 'designado' })).fill).toBe('#e9ddff');
        expect(corDoEstande(linha()).fill).toBe('#ffffff');
        expect(corDoEstande(null)).toBe(SEM_PROJETO);
    });

    it('com mais de uma vaga da comissão, avaliado em parte tem cor própria', () => {
        expect(chaveDoEstande(linha({ situacao: 'avaliado', concluidas: 1 }), 2)).toBe('parcial');
        expect(chaveDoEstande(linha({ situacao: 'avaliado', concluidas: 2 }), 2)).toBe('avaliado');
    });

    it('estande ainda não pronto fica tracejado', () => {
        expect(corDoEstande(linha({ pronto: false })).tracejado).toBe(true);
        expect(corDoEstande(linha()).tracejado).toBe(false);
    });

    it('a legenda conta os estandes e só mostra "parcial" com mais de uma vaga', () => {
        const linhas = [linha({ situacao: 'em_avaliacao' }), linha({ situacao: 'avaliado', concluidas: 1 }), linha(), linha()];

        expect(legendaComite(linhas, 1).map((l) => [l.chave, l.total])).toEqual([
            ['em_avaliacao', 1], ['avaliado', 1], ['designado', 0], ['pendente', 2],
        ]);
        expect(legendaComite(linhas, 2).map((l) => l.chave)).toContain('parcial');
    });
});

describe('resumo do dashboard do comitê', () => {
    const linhas = [
        linha({ situacao: 'em_avaliacao', concluidas: 1, em_avaliacao: 1 }),
        linha({ situacao: 'avaliado', concluidas: 3 }), // passa da meta: conta 2
        linha({ situacao: 'designado', designadas: 1, area: 'Ciências Biológicas', area_sigla: 'BIO' }),
        linha({ situacao: 'pendente' }),
        linha({ situacao: 'pendente', pronto: false }),
    ];

    it('conta os projetos pelo que o comitê fez, no geral e por área', () => {
        const r = resumoComite(linhas, [], 2);

        expect(r.total).toBe(5);
        expect(r.avaliados).toBe(2);
        expect(r.emAvaliacao).toBe(1);
        expect(r.designados).toBe(1);
        expect(r.pendentes).toBe(2);
        expect(r.porSituacao).toEqual({ em_avaliacao: 1, avaliado: 1, designado: 1, pendente: 2 });
        expect(r.areas.map((a) => [a.area, a.total, a.avaliados])).toEqual([
            ['Ciências Agrárias', 4, 2],
            ['Ciências Biológicas', 1, 0],
        ]);
        // Pronto e ninguém do comitê foi: só o primeiro pendente.
        expect(r.prontosSemComite).toBe(1);
    });

    it('a barra soma as avaliações até a meta de cada projeto', () => {
        // 1 + 2 + 0 + 0 + 0 = 3 de 5 × 2 = 10.
        expect(resumoComite(linhas, [], 2).progresso).toEqual({ feitas: 3, meta: 10, pct: 30 });
        expect(resumoComite([], [], 1).progresso).toEqual({ feitas: 0, meta: 0, pct: 0 });
    });

    it('conta os avaliadores da comissão e destaca quem mais enviou', () => {
        const avaliadores = [
            avaliador({ id: 1, nome: 'Ana', situacao: 'avaliando', concluidas: [{}, {}] }),
            avaliador({ id: 2, nome: 'Bruno', situacao: 'designado', concluidas: [{}] }),
            avaliador({ id: 3, nome: 'Carla' }),
        ];
        const r = resumoComite([], avaliadores, 1);

        expect(r.avaliadores).toEqual({ total: 3, avaliando: 1, designados: 1, livres: 1, concluidas: 3 });
        expect(r.destaques.map((d) => [d.nome, d.total])).toEqual([['Ana', 2], ['Bruno', 1]]);
    });
});

describe('designações online do comitê', () => {
    const ana = avaliador({
        concluidas: [{}],
        designacoes_comite: [
            { titulo: 'Solo vivo', codigo: 'FET.AGR-009', estande: 9, situacao: 'designada' },
            { titulo: 'Horta', situacao: 'iniciada' },
            { titulo: 'Abelhas', situacao: 'concluida' },
        ],
    });

    it('separam o que falta do que já foi enviado', () => {
        expect(designacoesPendentes(ana).map((p) => p.titulo)).toEqual(['Solo vivo', 'Horta']);
        // 1 no estande + 1 online.
        expect(enviadas(ana)).toBe(2);
    });

    it('entram no total enviado, nos destaques e no resumo do turno', () => {
        const r = resumoComite(
            [linha({ situacao: 'designado', designadas: 1, designadas_online: 1 }), linha({ situacao: 'avaliado', concluidas: 1, concluidas_online: 1 })],
            [ana],
            1,
        );

        expect(r.avaliadores.concluidas).toBe(2);
        expect(r.destaques).toEqual([{ id: 1, nome: 'Ana', total: 2 }]);
        expect(r.online).toEqual({ designadas: 1, concluidas: 1 });
    });

    it('a busca alcança os projetos designados pelo comitê', () => {
        expect(filtrarAvaliadores([ana, avaliador({ id: 2, nome: 'Bruno' })], 'agr-009').map((a) => a.nome)).toEqual(['Ana']);
    });
});

describe('ver detalhes do avaliador', () => {
    it('separa os projetos em avaliados, em avaliação e designados, nas duas origens', () => {
        const a = avaliador({
            em_avaliacao: [{ titulo: 'Abelhas', origem: 'estande', situacao: 'em_avaliacao' }],
            designadas: [{ titulo: 'Solo', origem: 'estande', situacao: 'designada' }],
            concluidas: [{ titulo: 'Horta', origem: 'estande', situacao: 'concluida', concluida_em: '2026-10-18T10:00:00-04:00' }],
            designacoes_comite: [
                { titulo: 'Cisterna', origem: 'online', situacao: 'designada' },
                { titulo: 'Bioplástico', origem: 'online', situacao: 'iniciada' },
                { titulo: 'Robô', origem: 'online', situacao: 'concluida', concluida_em: '2026-10-18T11:00:00-04:00' },
            ],
        });
        const c = colunasDoAvaliador(a);

        // Avaliados: o mais recente primeiro.
        expect(c.avaliados.map((p) => p.titulo)).toEqual(['Robô', 'Horta']);
        expect(c.emAvaliacao.map((p) => p.titulo)).toEqual(['Abelhas', 'Bioplástico']);
        expect(c.designados.map((p) => p.titulo)).toEqual(['Cisterna', 'Solo']);
    });

    it('sem nada, as três colunas vêm vazias', () => {
        expect(colunasDoAvaliador(avaliador())).toEqual({ avaliados: [], emAvaliacao: [], designados: [] });
    });
});

describe('lista de avaliadores', () => {
    const avaliadores = [
        avaliador({ id: 1, nome: 'Carla' }),
        avaliador({ id: 2, nome: 'Bruno', situacao: 'designado', designadas: [{ titulo: 'Horta', codigo: 'FET.AGR-002', estande: 7 }] }),
        avaliador({ id: 3, nome: 'Ana', situacao: 'avaliando', em_avaliacao: [{ titulo: 'Abelhas nativas', codigo: 'FET.AGR-001', estande: 42 }] }),
    ];

    it('quem está avaliando vem primeiro', () => {
        expect(filtrarAvaliadores(avaliadores).map((a) => a.nome)).toEqual(['Ana', 'Bruno', 'Carla']);
    });

    it('a busca alcança o nome e os projetos (título, código, estande), sem acento', () => {
        expect(filtrarAvaliadores(avaliadores, 'abelhas').map((a) => a.nome)).toEqual(['Ana']);
        expect(filtrarAvaliadores(avaliadores, 'agr-002').map((a) => a.nome)).toEqual(['Bruno']);
        expect(filtrarAvaliadores(avaliadores, '042').map((a) => a.nome)).toEqual(['Ana']);
        expect(filtrarAvaliadores(avaliadores, 'CARLA').map((a) => a.nome)).toEqual(['Carla']);
    });

    it('filtra pela situação', () => {
        expect(filtrarAvaliadores(avaliadores, '', 'livre').map((a) => a.nome)).toEqual(['Carla']);
    });

    it('o local do projeto diz o que existir', () => {
        expect(localDoProjeto({ codigo: 'FET.AGR-001', estande: 42, turno_label: 'Turno A (matutino)' }))
            .toBe('FET.AGR-001 · Estande 042 · Turno A (matutino)');
        expect(localDoProjeto({ codigo: null, estande: null, turno_label: null })).toBe('');
    });
});
