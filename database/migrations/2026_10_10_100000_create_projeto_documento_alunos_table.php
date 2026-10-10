<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 174 — o **termo de uso de imagem** dos finalistas.
 *
 * O arquivo é um `projeto_documentos` como os outros (tipo
 * `termo_uso_imagem`), mas ao contrário do termo de responsabilidade o projeto
 * pode mandar **vários** — um para a equipe toda, um por estudante, ou o que a
 * escola tiver em mãos. Esta tabela diz **para quais estudantes** cada arquivo
 * vale, e é dela que sai a cobertura que o credenciamento e o Mapa do Evento
 * leem.
 *
 * As duas chaves apagam em cascata: estudante que sai da equipe deixa de ser
 * coberto, e arquivo removido deixa de cobrir alguém.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projeto_documento_alunos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('projeto_documento_id')->constrained('projeto_documentos')->cascadeOnDelete();
            $table->foreignId('aluno_id')->constrained('alunos')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['projeto_documento_id', 'aluno_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projeto_documento_alunos');
    }
};
