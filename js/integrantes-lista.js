/**
 * Listagem de integrantes por projeto (protótipo — dados mock)
 */
(function () {
  var PROJETOS = {
    '1': {
      titulo: 'Desenvolvimento de Bioplástico a partir de Casca de Mandioca',
      instituicao: 'EE Prof. João Mendes',
      alunos: [
        { nome: 'Maria Clara Ferreira', email: 'maria.ferreira@aluno.ms.gov.br', cpf: '123.456.789-00', camiseta: 'M' },
        { nome: 'Pedro Lucas Almeida', email: 'pedro.almeida@aluno.ms.gov.br', cpf: '987.654.321-00', camiseta: 'G' }
      ],
      coorientador: {
        nome: 'Ana Carolina Mendes',
        email: 'ana.mendes@escola.ms.gov.br',
        telefone: '(67) 98888-5566'
      }
    },
    '2': {
      titulo: 'Sistema de Irrigação Inteligente com Arduino',
      instituicao: 'IFMS Campus Três Lagoas',
      alunos: [],
      coorientador: null
    },
    '3': {
      titulo: 'Qualidade da Água do Rio Paraná — Análise Química',
      instituicao: 'Colégio Estadual Dom Aquino',
      alunos: [
        { nome: 'Lucas Henrique Souza', email: 'lucas.souza@aluno.ms.gov.br', cpf: '111.222.333-44', camiseta: 'M' },
        { nome: 'Beatriz Oliveira Costa', email: 'beatriz.costa@aluno.ms.gov.br', cpf: '555.666.777-88', camiseta: 'P' },
        { nome: 'Rafael Martins Lima', email: 'rafael.lima@aluno.ms.gov.br', cpf: '999.888.777-66', camiseta: 'GG' }
      ],
      coorientador: null
    }
  };

  function initials(name) {
    return name
      .split(' ')
      .filter(Boolean)
      .slice(0, 2)
      .map(function (w) { return w[0]; })
      .join('')
      .toUpperCase();
  }

  function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
  }

  function renderAlunos(alunos) {
    var tbody = document.getElementById('alunos-tbody');
    var cards = document.getElementById('alunos-cards');
    var vazio = document.getElementById('alunos-vazio');
    var contagem = document.getElementById('alunos-contagem');

    if (contagem) contagem.textContent = '(' + alunos.length + '/3)';

    if (!alunos.length) {
      if (tbody) {
        tbody.innerHTML = '<tr><td colspan="6" class="fetec-integrantes-empty">Nenhum aluno cadastrado.</td></tr>';
      }
      if (cards) cards.innerHTML = '';
      if (vazio) vazio.classList.remove('hidden');
      return;
    }

    if (vazio) vazio.classList.add('hidden');

    if (tbody) {
      tbody.innerHTML = alunos
        .map(function (a, i) {
          return (
            '<tr>' +
            '<td data-label="#">' + (i + 1) + '</td>' +
            '<td data-label="Nome"><strong>' + escapeHtml(a.nome) + '</strong></td>' +
            '<td data-label="E-mail">' + escapeHtml(a.email) + '</td>' +
            '<td data-label="CPF">' + escapeHtml(a.cpf) + '</td>' +
            '<td data-label="Camiseta">' + escapeHtml(a.camiseta) + '</td>' +
            '<td data-label="Ações" class="text-right">' +
            '<a class="fetec-member-edit" href="cadastro5.html">Editar</a>' +
            '</td></tr>'
          );
        })
        .join('');
    }

    if (cards) {
      cards.innerHTML = alunos
        .map(function (a, i) {
          return (
            '<article class="fetec-member-card">' +
            '<div class="fetec-member-avatar fetec-member-avatar--aluno">' + initials(a.nome) + '</div>' +
            '<div class="fetec-member-body">' +
            '<p class="fetec-member-role">Aluno ' + (i + 1) + '</p>' +
            '<p class="fetec-member-name">' + escapeHtml(a.nome) + '</p>' +
            '<p class="fetec-member-meta">' + escapeHtml(a.email) + ' · Camiseta ' + escapeHtml(a.camiseta) + '</p>' +
            '</div>' +
            '<a class="fetec-member-edit" href="cadastro5.html">Editar</a>' +
            '</article>'
          );
        })
        .join('');
    }
  }

  function renderCoorientador(co) {
    var slot = document.getElementById('coorientador-slot');
    if (!slot) return;

    if (!co) {
      slot.innerHTML =
        '<article class="fetec-member-card fetec-member-card--empty max-w-2xl">' +
        '<div class="fetec-member-avatar bg-surface-container-high text-on-surface-variant">' +
        '<span class="material-symbols-outlined text-[20px]">person_add</span></div>' +
        '<div class="fetec-member-body">' +
        '<p class="fetec-member-role">Sem coorientador</p>' +
        '<p class="fetec-member-name text-on-surface-variant">Nenhum coorientador cadastrado</p>' +
        '<p class="fetec-member-meta">Opcional — até 1 por projeto</p></div>' +
        '<a class="fetec-member-edit" href="cadastro6.html">Incluir</a></article>';
      return;
    }

    slot.innerHTML =
      '<article class="fetec-member-card max-w-2xl">' +
      '<div class="fetec-member-avatar fetec-member-avatar--coorientador">' + initials(co.nome) + '</div>' +
      '<div class="fetec-member-body">' +
      '<p class="fetec-member-role">Coorientador</p>' +
      '<p class="fetec-member-name">' + escapeHtml(co.nome) + '</p>' +
      '<p class="fetec-member-meta">' + escapeHtml(co.email) + ' · ' + escapeHtml(co.telefone) + '</p>' +
      '</div>' +
      '<a class="fetec-member-edit" href="cadastro6.html">Editar</a></article>';
  }

  function init() {
    var params = new URLSearchParams(window.location.search);
    var id = params.get('projeto') || '1';
    var projeto = PROJETOS[id] || PROJETOS['1'];

    var subtitulo = document.getElementById('page-subtitulo');
    var breadcrumb = document.getElementById('breadcrumb-titulo');

    if (subtitulo) subtitulo.textContent = projeto.titulo + ' · ' + projeto.instituicao;
    if (breadcrumb) {
      breadcrumb.textContent = projeto.titulo.length > 42 ? projeto.titulo.slice(0, 42) + '…' : projeto.titulo;
    }

    document.title = 'Integrantes — XVI FETECMS';

    renderAlunos(projeto.alunos);
    renderCoorientador(projeto.coorientador);

    var q = '?projeto=' + id;
    ['btn-add-aluno', 'link-add-aluno'].forEach(function (elId) {
      var el = document.getElementById(elId);
      if (el) el.href = 'cadastro5.html' + q;
    });
    var coBtn = document.getElementById('btn-coorientador');
    if (coBtn) coBtn.href = 'cadastro6.html' + q;
    var editProj = document.getElementById('link-editar-projeto');
    if (editProj) editProj.href = 'cadastro4.html' + q;
    var resumo = document.getElementById('link-resumo');
    if (resumo) resumo.href = 'cadastro7.html' + q;
  }

  document.addEventListener('DOMContentLoaded', init);
})();
