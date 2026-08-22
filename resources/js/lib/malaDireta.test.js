import { describe, it, expect } from 'vitest';
import {
    emailParecaValido,
    mesclarDestinatarios,
    parseCsvDestinatarios,
    parseEmailsColados,
} from './malaDireta.js';

describe('parseCsvDestinatarios', () => {
    it('lê as colunas email e nome pelo cabeçalho, em qualquer ordem', () => {
        const { destinatarios } = parseCsvDestinatarios('nome;email\nAna Souza;ana@escola.test\nBeto;beto@escola.test');

        expect(destinatarios).toEqual([
            { email: 'ana@escola.test', nome: 'Ana Souza' },
            { email: 'beto@escola.test', nome: 'Beto' },
        ]);
    });

    it('aceita vírgula como separador e cabeçalho acentuado', () => {
        const { destinatarios } = parseCsvDestinatarios('E-mail,Nome\nana@escola.test,Ana');

        expect(destinatarios).toEqual([{ email: 'ana@escola.test', nome: 'Ana' }]);
    });

    it('sem cabeçalho, assume a 1ª coluna como e-mail e a 2ª como nome', () => {
        const { destinatarios } = parseCsvDestinatarios('ana@escola.test;Ana\nbeto@escola.test;Beto');

        expect(destinatarios).toHaveLength(2);
        expect(destinatarios[0]).toEqual({ email: 'ana@escola.test', nome: 'Ana' });
    });

    it('respeita aspas e ignora colunas extras', () => {
        const { destinatarios } = parseCsvDestinatarios('email;nome;escola\nana@escola.test;"Souza, Ana";EM Central');

        expect(destinatarios).toEqual([{ email: 'ana@escola.test', nome: 'Souza, Ana' }]);
    });

    it('conta as linhas sem e-mail em vez de virar destinatário vazio', () => {
        const { destinatarios, ignoradas } = parseCsvDestinatarios('email;nome\n;Sem e-mail\nana@escola.test;Ana');

        expect(destinatarios).toEqual([{ email: 'ana@escola.test', nome: 'Ana' }]);
        expect(ignoradas).toBe(1);
    });

    it('devolve lista vazia para arquivo em branco', () => {
        expect(parseCsvDestinatarios('   ').destinatarios).toEqual([]);
    });
});

describe('parseEmailsColados', () => {
    it('quebra por linha, vírgula e ponto e vírgula', () => {
        expect(parseEmailsColados('a@x.test\nb@x.test, c@x.test; d@x.test')).toHaveLength(4);
    });

    it('entende o formato "Nome <email>"', () => {
        expect(parseEmailsColados('Beto Lima <beto@x.test>')).toEqual([
            { email: 'beto@x.test', nome: 'Beto Lima' },
        ]);
    });
});

describe('mesclarDestinatarios', () => {
    it('remove repetidos ignorando caixa e completa o nome que faltava', () => {
        const lista = mesclarDestinatarios(
            [{ email: 'ana@x.test', nome: '' }],
            [{ email: 'ANA@x.test', nome: 'Ana' }, { email: 'beto@x.test', nome: 'Beto' }],
        );

        expect(lista).toEqual([
            { email: 'ana@x.test', nome: 'Ana' },
            { email: 'beto@x.test', nome: 'Beto' },
        ]);
    });

    it('descarta entradas vazias', () => {
        expect(mesclarDestinatarios([], [{ email: '   ', nome: 'X' }])).toEqual([]);
    });
});

describe('emailParecaValido', () => {
    it('separa endereço plausível de texto solto', () => {
        expect(emailParecaValido('ana@escola.test')).toBe(true);
        expect(emailParecaValido('sem-arroba')).toBe(false);
        expect(emailParecaValido('')).toBe(false);
    });
});
