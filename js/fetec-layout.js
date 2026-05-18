/**
 * Layout compartilhado XVI FETECMS
 */
(function () {
  var LOGO = '../img/logo2022.png';
  var LOGO_ALT = 'Logo XVI FETECMS';

  function wizardAsideHtml() {
    return (
      '<div class="absolute -top-32 -left-32 w-96 h-96 bg-primary-fixed rounded-full mix-blend-multiply filter blur-[80px] opacity-70"></div>' +
      '<div class="absolute -bottom-32 -right-32 w-96 h-96 bg-secondary-fixed rounded-full mix-blend-multiply filter blur-[80px] opacity-50"></div>' +
      '<div class="relative z-10 flex flex-col gap-base">' +
      '<span class="font-label-md text-label-md text-primary-container tracking-wider uppercase">XVI FETECMS</span>' +
      '<h1 class="font-headline-xl text-headline-xl text-on-surface font-orbitron-override leading-tight">Portal do<br/>Orientador</h1>' +
      '</div>' +
      '<div class="relative z-10 flex-grow flex items-center justify-center w-full my-lg">' +
      '<img alt="' + LOGO_ALT + '" class="w-full max-w-[240px] h-auto object-contain drop-shadow-md" src="' + LOGO + '"/>' +
      '</div>' +
      '<div class="relative z-10 mt-auto">' +
      '<p class="font-headline-sm text-headline-sm text-primary-container font-orbitron-override border-l-4 border-secondary pl-md py-xs">A CIÊNCIA É A PONTE PARA O FUTURO.</p>' +
      '<p class="font-body-sm text-body-sm text-on-surface-variant mt-sm max-w-[90%]">Junte-se à maior feira de ciência e tecnologia do estado e guie a próxima geração de inovadores.</p>' +
      '</div>'
    );
  }

  function wizardMobileHeaderHtml() {
    return (
      '<div class="flex items-center gap-sm">' +
      '<img alt="' + LOGO_ALT + '" class="h-10 w-auto object-contain" src="' + LOGO + '"/>' +
      '<span class="font-label-sm text-label-sm text-primary-container tracking-wider uppercase">XVI FETECMS</span>' +
      '</div>' +
      '<h1 class="font-headline-lg-mobile text-headline-lg-mobile text-on-surface font-orbitron-override">Cadastro do Orientador</h1>' +
      '<p class="font-label-md text-label-md text-on-secondary-container">A CIÊNCIA É A PONTE PARA O FUTURO.</p>'
    );
  }

  function buildWizardProgress(current) {
    var progress = current === 1 ? '15%' : current === 2 ? '50%' : '100%';
    var steps = [
      { n: 1, label: 'Dados Básicos', href: 'cadastro1.html' },
      { n: 2, label: 'Info. Acadêmicas', href: 'cadastro2.html' },
      { n: 3, label: 'Endereço', href: 'cadastro3.html' }
    ];
    var html = '<div class="flex items-center justify-between relative w-full">';
    html += '<div class="absolute top-1/2 left-0 w-full h-[2px] bg-surface-variant -z-10 -translate-y-1/2"></div>';
    html += '<div class="absolute top-1/2 left-0 h-[2px] bg-primary-container -z-10 -translate-y-1/2 transition-all duration-500" style="width:' + progress + '"></div>';
    steps.forEach(function (s) {
      var done = s.n < current;
      var active = s.n === current;
      if (done) {
        html += '<a href="' + s.href + '" class="fetec-wizard-step flex flex-col items-center gap-xs bg-surface-container-lowest px-sm relative z-10">';
        html += '<div class="w-8 h-8 rounded-full bg-secondary text-on-secondary flex items-center justify-center shadow-sm">';
        html += '<span class="material-symbols-outlined text-[16px]" style="font-variation-settings:\'FILL\' 1">check</span></div>';
        html += '<span class="font-label-sm text-label-sm text-secondary hidden sm:block">' + s.label + '</span></a>';
      } else {
        html += '<a href="' + s.href + '" class="fetec-wizard-step flex flex-col items-center gap-xs bg-surface-container-lowest px-sm relative z-10">';
        html += '<div class="flex flex-col items-center gap-xs bg-surface-container-lowest px-sm relative z-10">';
        html += '<div class="w-8 h-8 rounded-full ' + (active ? 'bg-primary-container text-on-primary shadow-md ring-4 ring-surface-container-lowest' : 'bg-surface-variant text-on-surface-variant ring-4 ring-surface-container-lowest') + ' flex items-center justify-center font-label-md">' + s.n + '</div>';
        html += '<span class="font-label-sm text-label-sm ' + (active ? 'text-primary-container font-bold' : 'text-on-surface-variant') + ' hidden sm:block">' + s.label + '</span></div>';
      }
    });
    html += '</div>';
    return html;
  }

  function initWizard() {
    var step = parseInt(document.body.getAttribute('data-fetec-wizard'), 10);
    if (!step) return;
    var aside = document.getElementById('fetec-wizard-aside');
    if (aside && !aside.dataset.filled) {
      aside.className = 'hidden lg:flex lg:w-5/12 bg-surface-container-low flex-col justify-between p-lg relative overflow-hidden border-r border-outline-variant/30';
      aside.innerHTML = wizardAsideHtml();
      aside.dataset.filled = '1';
    }
    var mobile = document.getElementById('fetec-wizard-mobile-header');
    if (mobile && !mobile.dataset.filled) {
      mobile.className = 'lg:hidden w-full p-gutter bg-surface-container-low border-b border-outline-variant/30 flex flex-col gap-xs';
      mobile.innerHTML = wizardMobileHeaderHtml();
      mobile.dataset.filled = '1';
    }
    var progressSlot = document.getElementById('fetec-wizard-progress');
    if (progressSlot) {
      progressSlot.innerHTML = buildWizardProgress(step);
    }
  }

  function authShellHtml(active) {
    active = active || 'projetos';
    var on = 'flex items-center gap-base bg-primary-container text-on-primary-container rounded-lg px-4 py-3 font-label-md text-label-md';
    var off = 'flex items-center gap-base text-on-surface-variant px-4 py-3 hover:bg-surface-variant rounded-lg font-label-md text-label-md transition-colors';
    return [
      '<header class="md:hidden fixed top-0 left-0 w-full bg-surface border-b border-outline-variant/30 shadow-sm z-50 px-4 h-16 flex items-center justify-between">',
      '<div class="flex items-center gap-2 min-w-0"><img alt="' + LOGO_ALT + '" class="h-9 w-auto" src="' + LOGO + '"/>',
      '<span class="font-headline-sm text-headline-sm text-primary truncate">Portal do Orientador</span></div>',
      '<a href="login.html" class="p-2 text-on-surface-variant"><span class="material-symbols-outlined">logout</span></a></header>',
      '<nav class="hidden md:flex fixed left-0 top-0 h-full w-64 z-40 p-2 flex-col bg-surface-container-low border-r border-outline-variant/30">',
      '<div class="mb-lg pb-md border-b border-outline-variant/30"><img alt="' + LOGO_ALT + '" class="h-14 w-auto mb-sm" src="' + LOGO + '"/>',
      '<h1 class="font-headline-sm text-headline-sm text-primary">Portal do Orientador</h1>',
      '<p class="font-body-sm text-body-sm text-on-surface-variant">XVI FETECMS</p></div>',
      '<div class="flex-1 flex flex-col gap-xs">',
      '<a class="' + (active === 'projetos' ? on : off) + '" href="projetos.html"><span class="material-symbols-outlined" style="font-variation-settings:\'FILL\' 1">folder_shared</span>Meus Projetos</a>',
      '<a class="' + (active === 'perfil' ? on : off) + '" href="perfil.html"><span class="material-symbols-outlined">account_circle</span>Perfil</a>',
      '<a class="' + off + ' mt-auto" href="login.html"><span class="material-symbols-outlined">logout</span>Sair</a></div>',
      '<a href="cadastro4.html" class="fetec-btn fetec-btn-primary w-full justify-center mt-md"><span class="material-symbols-outlined text-[18px]">add</span>Nova Inscrição</a></nav>',
      '<nav class="md:hidden fixed bottom-0 w-full bg-surface-container-low border-t border-outline-variant z-40 flex justify-around fetec-bottom-nav">',
      '<a class="flex flex-col items-center p-2 ' + (active === 'projetos' ? 'text-primary-container' : 'text-on-surface-variant') + '" href="projetos.html">',
      '<span class="material-symbols-outlined" style="font-variation-settings:\'FILL\' 1">folder_shared</span><span class="text-[10px] font-semibold">Projetos</span></a>',
      '<a class="flex flex-col items-center p-2 text-on-surface-variant" href="perfil.html">',
      '<span class="material-symbols-outlined">account_circle</span><span class="text-[10px]">Perfil</span></a></nav>'
    ].join('');
  }

  function initAuth() {
    if (!document.body.hasAttribute('data-fetec-auth')) return;
    var active = document.body.getAttribute('data-fetec-auth-active') || 'projetos';
    var slot = document.getElementById('fetec-auth-shell');
    if (slot) slot.innerHTML = authShellHtml(active);
    document.body.classList.add('fetec-auth-shell', 'fetec-has-bottom-nav');
  }

  document.addEventListener('DOMContentLoaded', function () {
    initWizard();
    initAuth();
  });
})();
