<?php

namespace App\Enums;

/**
 * As abas do menu do admin — a unidade de permissão dos **escopos**
 * (Parametrização → Escopos de admin).
 *
 * Cada escopo é um conjunto destas abas; cada admin recebe um escopo **por
 * edição**, então a mesma pessoa pode cuidar da comunicação em 2026 e do
 * credenciamento em 2027. Sem escopo atribuído na edição em curso, o admin tem
 * **acesso total** — é o comportamento histórico, e evita que criar uma edição
 * nova tranque todo mundo para fora.
 *
 * O `value` é o que viaja para o front (menu) e para o middleware `aba:`.
 */
enum AbaAdmin: string
{
    case Projetos = 'projetos';
    case Avaliacao = 'avaliacao';
    case Credenciamento = 'credenciamento';
    case Comunicacao = 'comunicacao';
    case Suporte = 'suporte';
    case Parametrizacao = 'parametrizacao';
    case Administradores = 'administradores';
    case Registros = 'registros';

    public function label(): string
    {
        return match ($this) {
            self::Projetos => 'Projetos',
            self::Avaliacao => 'Avaliação online',
            self::Credenciamento => 'Credenciamento',
            self::Comunicacao => 'Comunicação',
            self::Suporte => 'Suporte',
            self::Parametrizacao => 'Parametrização',
            self::Administradores => 'Administradores',
            self::Registros => 'Registros',
        };
    }

    public function descricao(): string
    {
        return match ($this) {
            self::Projetos => 'Painel, projetos por área, localidade e os rascunhos.',
            self::Avaliacao => 'Distribuição, avaliadores, ranking e lista final.',
            self::Credenciamento => 'Credenciar os finalistas no dia do evento e conferir os documentos.',
            self::Comunicacao => 'Mala direta, avisos na tela e modelos de e-mail.',
            self::Suporte => 'Caixa de entrada do chat de orientadores e avaliadores.',
            self::Parametrizacao => 'Edições, datas, áreas, escolas e escopos.',
            self::Administradores => 'Criar e desativar contas de administrador.',
            self::Registros => 'A trilha de auditoria completa.',
        };
    }

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<int, array{value: string, label: string, descricao: string}> */
    public static function opcoes(): array
    {
        return array_map(fn (self $a) => [
            'value' => $a->value,
            'label' => $a->label(),
            'descricao' => $a->descricao(),
        ], self::cases());
    }
}
