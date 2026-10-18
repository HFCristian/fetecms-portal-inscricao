<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 224 — a agenda da avaliação presencial **por dia × turno**.
 *
 * `edicoes.horarios_turnos` guardava um horário por turno, igual em todos os
 * dias do evento, e a avaliação ia do início do turno até 30 minutos depois do
 * fim. Agora `edicoes.agenda_presencial` guarda cada dia do evento com os seus
 * turnos, e cada turno com dois pares de horas: o **do turno** (quando a equipe
 * apresenta) e o **da avaliação** (quando o avaliador avalia):
 *
 *     {"2026-10-20": {"A": {"inicio": "08:00", "fim": "12:00",
 *                           "avaliacao_inicio": "08:00", "avaliacao_fim": "12:30"}}}
 *
 * O horário que existia é copiado para cada dia do evento, com a avaliação que
 * valia (início do turno → fim + 30 min), então nada muda para quem já tinha
 * configurado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->json('agenda_presencial')->nullable()->after('info_avaliacao_presencial');
        });

        foreach (DB::table('edicoes')->get(['id', 'horarios_turnos', 'evento_de', 'evento_ate']) as $edicao) {
            $horarios = json_decode((string) $edicao->horarios_turnos, true);

            if (! is_array($horarios) || $horarios === [] || $edicao->evento_de === null) {
                continue;
            }

            $agenda = [];
            $dia = CarbonImmutable::parse($edicao->evento_de)->startOfDay();
            $ultimo = CarbonImmutable::parse($edicao->evento_ate ?? $edicao->evento_de)->startOfDay();

            for ($n = 0; $dia->lessThanOrEqualTo($ultimo) && $n < 31; $dia = $dia->addDay(), $n++) {
                foreach ($horarios as $turno => $par) {
                    if (empty($par['inicio']) || empty($par['fim'])) {
                        continue;
                    }

                    $fimAvaliacao = CarbonImmutable::parse("{$dia->toDateString()} {$par['fim']}")->addMinutes(30);

                    $agenda[$dia->toDateString()][$turno] = [
                        'inicio' => $par['inicio'],
                        'fim' => $par['fim'],
                        'avaliacao_inicio' => $par['inicio'],
                        // Passar da meia-noite não cabe numa hora de parede: fica em 23:59.
                        'avaliacao_fim' => $fimAvaliacao->isSameDay($dia) ? $fimAvaliacao->format('H:i') : '23:59',
                    ];
                }
            }

            DB::table('edicoes')->where('id', $edicao->id)->update(['agenda_presencial' => json_encode($agenda)]);
        }

        Schema::table('edicoes', function (Blueprint $table) {
            $table->dropColumn('horarios_turnos');
        });
    }

    public function down(): void
    {
        Schema::table('edicoes', function (Blueprint $table) {
            $table->json('horarios_turnos')->nullable()->after('info_avaliacao_presencial');
        });

        // Volta o horário do primeiro dia, que era o único que existia.
        foreach (DB::table('edicoes')->get(['id', 'agenda_presencial']) as $edicao) {
            $agenda = json_decode((string) $edicao->agenda_presencial, true);

            if (! is_array($agenda) || $agenda === []) {
                continue;
            }

            ksort($agenda);
            $horarios = array_map(
                fn (array $t) => ['inicio' => $t['inicio'], 'fim' => $t['fim']],
                reset($agenda),
            );

            DB::table('edicoes')->where('id', $edicao->id)->update(['horarios_turnos' => json_encode($horarios)]);
        }

        Schema::table('edicoes', function (Blueprint $table) {
            $table->dropColumn('agenda_presencial');
        });
    }
};
