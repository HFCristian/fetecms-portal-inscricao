<?php

namespace App\Enums;

/**
 * As abas do menu do admin — a unidade de permissão dos **escopos**
 * (Parametrização → Escopos de admin).
 *
 * No RBAC do portal, uma aba é a **rule**; o escopo é o **role** (um nome + o
 * conjunto de abas que ele abre). Cada admin recebe **um ou mais** escopos
 * **por edição** e abre a **união** das abas deles, então a mesma pessoa pode
 * cuidar da comunicação em 2026 e do credenciamento em 2027 — ou das duas
 * coisas ao mesmo tempo. Sem escopo algum na edição em curso, o admin tem
 * **acesso total** — é o comportamento histórico, e evita que criar uma edição
 * nova tranque todo mundo para fora.
 *
 * O `value` é o que viaja para o front (menu) e para o middleware `aba:`.
 */
enum AbaAdmin: string
{
    case Projetos = 'projetos';
    case Dashboards = 'dashboards';
    case Avaliacao = 'avaliacao';
    case Credenciamento = 'credenciamento';
    case Almoxarifado = 'almoxarifado';
    case Comite = 'comite';
    case Comunicacao = 'comunicacao';
    case Suporte = 'suporte';
    case Parametrizacao = 'parametrizacao';
    case Administradores = 'administradores';
    case Registros = 'registros';

    public function label(): string
    {
        return match ($this) {
            self::Projetos => 'Projetos',
            self::Dashboards => 'Dashboards',
            self::Avaliacao => 'Avaliação online',
            self::Credenciamento => 'Credenciamento',
            self::Almoxarifado => 'Almoxarifado',
            self::Comite => 'Comitê especial',
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
            self::Projetos => 'Projetos por área, por localidade e os rascunhos.',
            self::Dashboards => 'Os números da feira: projetos, pessoas, camisetas e localidades.',
            self::Avaliacao => 'Distribuição, avaliadores, ranking e lista final.',
            self::Credenciamento => 'Credenciar os finalistas no dia do evento e conferir os documentos.',
            self::Almoxarifado => 'Guardar e devolver o material dos finalistas durante o evento.',
            self::Comite => 'Transporte do comitê e o mapa de quem está a caminho.',
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

    /**
     * Reordena um conjunto de abas pela ordem escolhida na edição
     * (Parametrização → Ordem do menu).
     *
     * `$ordem` nula ou vazia devolve a **ordem canônica do enum** — o
     * comportamento de sempre. Aba que a ordem não cita entra no **fim**, ainda
     * na ordem do enum: uma aba nova no código nunca some do menu de quem já
     * salvou uma ordem, e um valor inválido guardado no banco é ignorado em vez
     * de quebrar o menu.
     *
     * @param  list<string>  $abas  as abas que a pessoa enxerga
     * @param  list<string>|null  $ordem  a ordem salva na edição
     * @return list<string>
     */
    public static function ordenar(array $abas, ?array $ordem): array
    {
        $canonica = self::valores();
        $abas = array_values(array_intersect($canonica, $abas));

        if (empty($ordem)) {
            return $abas;
        }

        // Só o que a pessoa enxerga, na ordem pedida...
        $escolhidas = array_values(array_filter(
            array_unique(array_map('strval', $ordem)),
            fn (string $aba) => in_array($aba, $abas, true),
        ));

        // ...e o que a ordem não citou, atrás, na ordem do enum.
        return array_merge(
            $escolhidas,
            array_values(array_diff($abas, $escolhidas)),
        );
    }

    /**
     * Limpa uma ordem vinda da tela: só valores conhecidos, sem repetição, e
     * **completa** com o que faltar. O que se guarda é sempre a lista inteira,
     * então ler de volta não depende de nenhum preenchimento.
     *
     * @param  list<string>  $ordem
     * @return list<string>
     */
    public static function sanitizarOrdem(array $ordem): array
    {
        return self::ordenar(self::valores(), $ordem);
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
