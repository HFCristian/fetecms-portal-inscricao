/**
 * Pré-visualização de vídeo — YouTube, Vimeo e Google Drive
 * Valida link público via oEmbed quando disponível.
 */
(function () {
  var DEBOUNCE_MS = 600;

  function parseVideoUrl(raw) {
    var url;
    try {
      url = new URL(raw.trim());
    } catch (e) {
      return null;
    }

    var host = url.hostname.replace(/^www\./, '');
    var path = url.pathname;
    var id;

    if (host === 'youtu.be') {
      id = path.slice(1).split('/')[0];
      if (id) return { provider: 'youtube', id: id, canonical: 'https://www.youtube.com/watch?v=' + id };
    }

    if (host === 'youtube.com' || host === 'm.youtube.com' || host === 'music.youtube.com') {
      if (path.indexOf('/shorts/') === 0) {
        id = path.split('/')[2];
      } else if (path.indexOf('/embed/') === 0) {
        id = path.split('/')[2];
      } else {
        id = url.searchParams.get('v');
      }
      if (id) return { provider: 'youtube', id: id, canonical: 'https://www.youtube.com/watch?v=' + id };
    }

    if (host === 'vimeo.com' || host === 'player.vimeo.com') {
      var parts = path.split('/').filter(Boolean);
      id = parts[parts.length - 1];
      if (id && /^\d+$/.test(id)) {
        return { provider: 'vimeo', id: id, canonical: 'https://vimeo.com/' + id };
      }
    }

    if (host === 'drive.google.com') {
      var driveMatch = path.match(/\/file\/d\/([^/]+)/);
      if (driveMatch) {
        id = driveMatch[1];
        return { provider: 'drive', id: id, canonical: url.href };
      }
    }

    return null;
  }

  function embedUrl(parsed) {
    if (parsed.provider === 'youtube') {
      return 'https://www.youtube.com/embed/' + parsed.id + '?rel=0';
    }
    if (parsed.provider === 'vimeo') {
      return 'https://player.vimeo.com/video/' + parsed.id;
    }
    if (parsed.provider === 'drive') {
      return 'https://drive.google.com/file/d/' + parsed.id + '/preview';
    }
    return null;
  }

  function fetchOEmbed(parsed, canonical) {
    var endpoint;
    if (parsed.provider === 'youtube') {
      endpoint = 'https://www.youtube.com/oembed?format=json&url=' + encodeURIComponent(canonical);
    } else if (parsed.provider === 'vimeo') {
      endpoint = 'https://vimeo.com/api/oembed.json?url=' + encodeURIComponent(canonical);
    } else {
      return Promise.resolve(null);
    }
    return fetch(endpoint)
      .then(function (res) {
        if (!res.ok) throw new Error('oembed_failed');
        return res.json();
      });
  }

  function statusHtml(type, icon, title, message) {
    var cls = 'fetec-video-preview__banner fetec-video-preview__banner--' + type;
    return (
      '<div class="' + cls + '">' +
      '<span class="material-symbols-outlined fetec-video-preview__icon" style="font-variation-settings:\'FILL\' 1">' + icon + '</span>' +
      '<div><p class="fetec-video-preview__title">' + title + '</p>' +
      (message ? '<p class="fetec-video-preview__msg">' + message + '</p>' : '') +
      '</div></div>'
    );
  }

  function initVideoPreview() {
    var input = document.getElementById('link-video');
    var preview = document.getElementById('fetec-video-preview');
    var statusEl = document.getElementById('fetec-video-status');
    var playerWrap = document.getElementById('fetec-video-player-wrap');
    var iframe = document.getElementById('fetec-video-iframe');
    var inputWrap = document.querySelector('.fetec-video-input-wrap');

    if (!input || !preview || !statusEl || !playerWrap || !iframe) return;

    var timer = null;
    var requestId = 0;

    function hidePreview() {
      preview.classList.add('hidden');
      playerWrap.classList.add('hidden');
      iframe.removeAttribute('src');
      if (inputWrap) {
        inputWrap.classList.remove('fetec-video-input-wrap--ok', 'fetec-video-input-wrap--error');
      }
    }

    function showLoading() {
      preview.classList.remove('hidden');
      playerWrap.classList.add('hidden');
      iframe.removeAttribute('src');
      statusEl.innerHTML = statusHtml(
        'loading',
        'hourglass_top',
        'Verificando link…',
        'Aguarde enquanto buscamos a pré-visualização do vídeo.'
      );
      if (inputWrap) {
        inputWrap.classList.remove('fetec-video-input-wrap--ok', 'fetec-video-input-wrap--error');
      }
    }

    function showSuccess(parsed, meta) {
      var providerLabel = { youtube: 'YouTube', vimeo: 'Vimeo', drive: 'Google Drive' }[parsed.provider];
      var title = (meta && meta.title) ? meta.title : 'Vídeo encontrado';
      var msg = 'Vídeo público no ' + providerLabel + '. Confira a reprodução abaixo antes de submeter.';

      statusEl.innerHTML = statusHtml('ok', 'check_circle', title, msg);
      iframe.src = embedUrl(parsed);
      playerWrap.classList.remove('hidden');
      preview.classList.remove('hidden');

      if (inputWrap) {
        inputWrap.classList.add('fetec-video-input-wrap--ok');
        inputWrap.classList.remove('fetec-video-input-wrap--error');
      }
    }

    function showError(title, message) {
      preview.classList.remove('hidden');
      playerWrap.classList.add('hidden');
      iframe.removeAttribute('src');
      statusEl.innerHTML = statusHtml('error', 'error', title, message);
      if (inputWrap) {
        inputWrap.classList.add('fetec-video-input-wrap--error');
        inputWrap.classList.remove('fetec-video-input-wrap--ok');
      }
    }

    function validateUrl(value) {
      var currentRequest = ++requestId;
      var trimmed = value.trim();

      if (!trimmed) {
        hidePreview();
        return;
      }

      var parsed = parseVideoUrl(trimmed);
      if (!parsed) {
        showError(
          'Link não reconhecido',
          'Use um link público do YouTube, Vimeo ou Google Drive (compartilhamento: qualquer pessoa com o link).'
        );
        return;
      }

      showLoading();

      if (parsed.provider === 'drive') {
        if (currentRequest !== requestId) return;
        showSuccess(parsed, { title: 'Google Drive — pré-visualização' });
        return;
      }

      fetchOEmbed(parsed, parsed.canonical)
        .then(function (meta) {
          if (currentRequest !== requestId) return;
          showSuccess(parsed, meta);
        })
        .catch(function () {
          if (currentRequest !== requestId) return;
          showError(
            'Vídeo indisponível ou privado',
            'Não foi possível carregar o vídeo. Verifique se o link está correto, se o vídeo é público e se permite incorporação (embed).'
          );
        });
    }

    input.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(function () {
        validateUrl(input.value);
      }, DEBOUNCE_MS);
    });

    input.addEventListener('blur', function () {
      clearTimeout(timer);
      if (input.value.trim()) validateUrl(input.value);
    });

    if (input.value.trim()) validateUrl(input.value);
  }

  document.addEventListener('DOMContentLoaded', initVideoPreview);
})();
