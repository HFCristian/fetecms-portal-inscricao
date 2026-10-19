import { describe, it, expect } from 'vitest';
import {
    corDaArea, COR_AREA_OUTRAS, textoSobreArea, escalaAvaliacoes, corNoEscopo, legendaDoEscopo, resumoDoPainel,
    marcaPrimeiraVez,
} from './mapaTelaCheia.js';

const linha = (over = {}) => ({
    credenciado: false, checado: false, avaliacoes: 0, avaliacoes_comite: 0,
    area: 'Ciências Agrárias', area_sigla: 'AGR', ...over,
});

describe('cores da tela cheia do mapa', () => {
    it('a cor segue a área, e área desconhecida cai em "Outras"', () => {
        expect(corDaArea('AGR')).toBe('#008300');
        expect(corDaArea('BIO')).toBe('#1baf7a');
        expect(corDaArea('XYZ')).toBe(COR_AREA_OUTRAS);
        // Faixa clara pede texto escuro.
        expect(textoSobreArea(corDaArea('HUM'))).toBe('#0b0b0b');
        expect(textoSobreArea(corDaArea('AGR'))).toBe('#ffffff');
    });

    it('a escala de avaliações usa os passos validados até 3', () => {
        expect(escalaAvaliacoes(3)).toEqual(['#86b6ef', '#3987e5', '#184f95']);
        expect(escalaAvaliacoes(5)).toHaveLength(5);
        expect(escalaAvaliacoes(0)).toHaveLength(1);
    });

    it('cada visualização pinta pelo próprio fato', () => {
        const l = linha({ credenciado: true, avaliacoes: 2 });

        expect(corNoEscopo('credenciamento', l, 3).fill).toBe('#0ca30c');
        expect(corNoEscopo('checagem', l, 3).fill).toBe('#f5d000');
        expect(corNoEscopo('avaliacao', l, 3).fill).toBe('#3987e5');
        expect(corNoEscopo('avaliacao', linha({ avaliacoes: 7 }), 3).fill).toBe('#184f95');
        expect(corNoEscopo('avaliacao', linha(), 3).fill).toBe('#ffffff');
        // Estande sem projeto no turno.
        expect(corNoEscopo('avaliacao', null, 3).fill).toBe('#f3f1f6');
    });

    it('a partir do 2º dia, separa o credenciado em dia anterior do que chegou hoje', () => {
        const antes = linha({ credenciado: true, credenciamento: 'anterior' });
        const hoje = linha({ credenciado: true, credenciamento: 'hoje' });
        const linhas = [antes, hoje, linha()];

        expect(corNoEscopo('credenciamento', antes, 3).fill).toBe('#0b5d2a');
        expect(corNoEscopo('credenciamento', hoje, 3).fill).toBe('#0ca30c');

        expect(legendaDoEscopo('credenciamento', linhas, 3, { primeiroDia: false }).map((f) => [f.label, f.total]))
            .toEqual([['Credenciado em dia anterior', 1], ['Credenciado hoje (1ª vez)', 1], ['Pendente', 1]]);
        expect(legendaDoEscopo('credenciamento', linhas, 3, { primeiroDia: false, hoje: false })[1].label)
            .toBe('Credenciado neste dia (1ª vez)');
        // No primeiro dia não há "dia anterior": a legenda fica como sempre.
        expect(legendaDoEscopo('credenciamento', linhas, 3, { primeiroDia: true }).map((f) => f.label))
            .toEqual(['Credenciado', 'Pendente']);

        // O ponto de "1ª vez" só a partir do segundo dia, e nunca em quem saiu.
        expect(marcaPrimeiraVez(hoje, false)).toBe(true);
        expect(marcaPrimeiraVez(hoje, true)).toBe(false);
        expect(marcaPrimeiraVez(antes, false)).toBe(false);
        expect(marcaPrimeiraVez({ ...hoje, saida: { hora: '10:00' } }, false)).toBe(false);
    });

    it('a legenda conta os estandes de cada faixa', () => {
        const linhas = [linha({ avaliacoes: 0 }), linha({ avaliacoes: 1 }), linha({ avaliacoes: 3 }), linha({ avaliacoes: 5, credenciado: true })];

        expect(legendaDoEscopo('avaliacao', linhas, 3).map((f) => [f.label, f.total]))
            .toEqual([['Nenhuma', 1], ['1', 1], ['2', 0], ['3 ou mais', 2]]);
        expect(legendaDoEscopo('credenciamento', linhas, 3).map((f) => f.total)).toEqual([1, 3]);
    });

    it('na checagem, checado sem credenciamento fica laranja e credenciado sem checagem, amarelo', () => {
        const completo = linha({ credenciado: true, checado: true });
        const semBalcao = linha({ checado: true });
        const semChecagem = linha({ credenciado: true });

        expect(corNoEscopo('checagem', completo, 3).fill).toBe('#0ca30c');
        expect(corNoEscopo('checagem', semBalcao, 3)).toMatchObject({ fill: '#f5871f', texto: '#0b0b0b' });
        expect(corNoEscopo('checagem', semChecagem, 3)).toMatchObject({ fill: '#f5d000', texto: '#0b0b0b' });
        expect(corNoEscopo('checagem', linha(), 3).fill).toBe('#ffffff');
        // A visualização do credenciamento não muda: só olha o balcão.
        expect(corNoEscopo('credenciamento', semBalcao, 3).fill).toBe('#ffffff');
        expect(corNoEscopo('credenciamento', semChecagem, 3).fill).toBe('#0ca30c');

        expect(legendaDoEscopo('checagem', [completo, semBalcao, semBalcao, semChecagem, linha()], 3).map((f) => [f.label, f.total]))
            .toEqual([['Checado', 1], ['Credenciado, aguardando checagem', 1], ['Checado sem credenciamento', 2], ['Pendente', 1]]);
    });
});

describe('saída antes do horário', () => {
    it('o estande de quem saiu fica cinza em qualquer visualização e conta à parte na legenda', () => {
        const saiu = linha({ credenciado: true, checado: true, avaliacoes: 2, saida: { id: 1, hora: '14:20' } });
        const ficou = linha({ credenciado: true });

        for (const escopo of ['credenciamento', 'checagem', 'avaliacao']) {
            expect(corNoEscopo(escopo, saiu, 3)).toMatchObject({ fill: '#5c5866', texto: '#ffffff' });
        }

        expect(legendaDoEscopo('credenciamento', [saiu, ficou, linha()], 3).map((f) => [f.label, f.total]))
            .toEqual([['Credenciado', 1], ['Pendente', 1], ['Saiu antes do horário', 1]]);
        // Sem ninguém que saiu, a legenda não ganha a faixa.
        expect(legendaDoEscopo('avaliacao', [ficou], 3).map((f) => f.label)).not.toContain('Saiu antes do horário');
    });
});

describe('resumo do painel', () => {
    const linhas = [
        linha({ avaliacoes: 0 }),
        linha({ avaliacoes: 2, avaliacoes_comite: 1 }),
        linha({ avaliacoes: 4 }), // passa da meta: conta 3 na barra
        linha({ area: 'Ciências Biológicas', area_sigla: 'BIO', avaliacoes: 1, avaliacoes_comite: 1 }),
    ];

    it('conta projetos por avaliações recebidas, no geral e por área', () => {
        const r = resumoDoPainel(linhas, 3);

        expect(r.total).toBe(4);
        expect(r.rotulos).toEqual(['0', '1', '2', '3+']);
        expect(r.geral).toEqual([1, 1, 1, 1]);
        expect(r.areas.map((a) => [a.area, a.total, a.faixas])).toEqual([
            ['Ciências Agrárias', 3, [1, 0, 1, 1]],
            ['Ciências Biológicas', 1, [0, 1, 0, 0]],
        ]);
    });

    it('conta projetos com avaliação da comissão especial por área', () => {
        const r = resumoDoPainel(linhas, 3);

        expect(r.areas.map((a) => a.comComite)).toEqual([1, 1]);
        expect(r.comComite).toBe(2);
    });

    it('a barra soma as avaliações até a meta de cada projeto', () => {
        // 0 + 2 + 3 + 1 = 6 de 4 × 3 = 12.
        expect(resumoDoPainel(linhas, 3).progresso).toEqual({ feitas: 6, meta: 12, pct: 50 });
        expect(resumoDoPainel([], 3).progresso).toEqual({ feitas: 0, meta: 0, pct: 0 });
    });
});
