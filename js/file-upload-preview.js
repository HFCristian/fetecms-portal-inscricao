/**
 * Pré-visualização de upload — PDF e DOCX (cadastro do projeto)
 */
(function () {
  var MAX_BYTES = 10 * 1024 * 1024;
  var ALLOWED_EXT = ['pdf', 'docx'];
  var ALLOWED_MIME = [
    'application/pdf',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
  ];

  function formatSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / (1024 * 1024)).toFixed(1) + ' MB';
  }

  function getExt(name) {
    var i = name.lastIndexOf('.');
    return i >= 0 ? name.slice(i + 1).toLowerCase() : '';
  }

  function isAllowed(file) {
    var ext = getExt(file.name);
    if (ALLOWED_EXT.indexOf(ext) >= 0) return true;
    return ALLOWED_MIME.indexOf(file.type) >= 0;
  }

  function statusHtml(type, icon, title, message) {
    var cls = 'fetec-file-preview__banner fetec-file-preview__banner--' + type;
    return (
      '<div class="' + cls + '">' +
      '<span class="material-symbols-outlined fetec-file-preview__icon" style="font-variation-settings:\'FILL\' 1">' + icon + '</span>' +
      '<div><p class="fetec-file-preview__title">' + title + '</p>' +
      (message ? '<p class="fetec-file-preview__msg">' + message + '</p>' : '') +
      '</div></div>'
    );
  }

  function initFileUploadPreview() {
    var fields = Array.prototype.slice.call(document.querySelectorAll('[data-fetec-file-field]'));

    if (!fields.length) return;

    function escapeHtml(s) {
      var d = document.createElement('div');
      d.textContent = s;
      return d.innerHTML;
    }

    fields.forEach(function (fieldRoot) {
      if (fieldRoot.closest('#etica-fields')) return;

      var input = fieldRoot.querySelector('input[type="file"]');
      var dropzone = fieldRoot.querySelector('.fetec-file-dropzone');
      var preview = fieldRoot.querySelector('.fetec-file-preview');
      var statusEl = fieldRoot.querySelector('.fetec-file-preview__status');
      var viewer = fieldRoot.querySelector('.fetec-file-preview__viewer');
      var listEl = fieldRoot.querySelector('#fetec-file-list');

      if (!input || !dropzone || !preview || !statusEl || !viewer || !listEl) return;

      var files = [];
      var activeId = null;

      function revokeAll() {
        files.forEach(function (f) {
          if (f.url) URL.revokeObjectURL(f.url);
        });
      }

      function setDropzoneState(state) {
        dropzone.classList.remove('fetec-file-dropzone--ok', 'fetec-file-dropzone--error', 'fetec-file-dropzone--drag');
        if (state) dropzone.classList.add('fetec-file-dropzone--' + state);
      }

      function renderViewer(file) {
        if (!file) {
          viewer.classList.add('hidden');
          viewer.innerHTML = '';
          return;
        }

        viewer.classList.remove('hidden');
        if (file.ext === 'pdf') {
          viewer.innerHTML =
            '<iframe class="fetec-file-preview__iframe" title="Pré-visualização de ' + escapeHtml(file.name) + '" src="' + file.url + '"></iframe>';
        } else {
          viewer.innerHTML =
            '<div class="fetec-file-preview__docx">' +
            '<span class="material-symbols-outlined fetec-file-preview__docx-icon">description</span>' +
            '<p class="fetec-file-preview__docx-name">' + escapeHtml(file.name) + '</p>' +
            '<p class="fetec-file-preview__docx-meta">' + formatSize(file.size) + ' · DOCX</p>' +
            '<p class="fetec-file-preview__docx-hint">Pré-visualização de páginas não disponível no navegador. Confira o nome e o tamanho, ou <a href="' + file.url + '" download="' + escapeHtml(file.name) + '" class="fetec-file-preview__link">baixe uma cópia</a> para revisar.</p>' +
            '</div>';
        }
      }

      function showActivePreview() {
        var file = files.find(function (f) { return f.id === activeId; });
        if (!file) {
          preview.classList.add('hidden');
          setDropzoneState(files.length ? 'ok' : null);
          return;
        }

        preview.classList.remove('hidden');
        statusEl.innerHTML = statusHtml(
          'ok',
          'check_circle',
          'Arquivo pronto: ' + file.name,
          file.ext === 'pdf'
            ? 'Confira a visualização do PDF abaixo. Você pode adicionar mais arquivos se necessário.'
            : 'Arquivo DOCX aceito. Verifique os dados antes de submeter.'
        );
        renderViewer(file);
        setDropzoneState('ok');
      }

      function renderList() {
        listEl.innerHTML = '';
        files.forEach(function (file) {
          var chip = document.createElement('div');
          chip.className = 'fetec-file-chip' + (file.id === activeId ? ' fetec-file-chip--active' : '');
          chip.innerHTML =
            '<button type="button" class="fetec-file-chip__btn" data-id="' + file.id + '">' +
            '<span class="material-symbols-outlined text-[16px]">' + (file.ext === 'pdf' ? 'picture_as_pdf' : 'description') + '</span>' +
            '<span class="fetec-file-chip__name">' + escapeHtml(file.name) + '</span>' +
            '<span class="fetec-file-chip__size">' + formatSize(file.size) + '</span>' +
            '</button>' +
            '<button type="button" class="fetec-file-chip__remove text-error" data-remove="' + file.id + '" aria-label="Remover ' + escapeHtml(file.name) + '">' +
            '<span class="material-symbols-outlined text-[16px]">close</span></button>';
          listEl.appendChild(chip);
        });

        listEl.querySelectorAll('.fetec-file-chip__btn').forEach(function (btn) {
          btn.addEventListener('click', function () {
            activeId = btn.getAttribute('data-id');
            renderList();
            showActivePreview();
          });
        });

        listEl.querySelectorAll('[data-remove]').forEach(function (btn) {
          btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var id = btn.getAttribute('data-remove');
            var idx = files.findIndex(function (f) { return f.id === id; });
            if (idx >= 0) {
              if (files[idx].url) URL.revokeObjectURL(files[idx].url);
              files.splice(idx, 1);
            }
            if (activeId === id) {
              activeId = files.length ? files[files.length - 1].id : null;
            }
            renderList();
            showActivePreview();
            if (!files.length) {
              preview.classList.add('hidden');
              setDropzoneState(null);
            }
          });
        });
      }

      function showError(title, message) {
        preview.classList.remove('hidden');
        viewer.classList.add('hidden');
        viewer.innerHTML = '';
        statusEl.innerHTML = statusHtml('error', 'error', title, message);
        setDropzoneState('error');
      }

      function addFiles(fileList) {
        var errors = [];
        Array.prototype.forEach.call(fileList, function (file) {
          if (!isAllowed(file)) {
            errors.push('"' + file.name + '" não é PDF nem DOCX.');
            return;
          }
          if (file.size > MAX_BYTES) {
            errors.push('"' + file.name + '" excede 10 MB (' + formatSize(file.size) + ').');
            return;
          }
          var id = 'f_' + Date.now() + '_' + Math.random().toString(36).slice(2, 8);
          var entry = {
            id: id,
            name: file.name,
            size: file.size,
            ext: getExt(file.name),
            url: URL.createObjectURL(file)
          };
          files.push(entry);
          activeId = id;
        });

        if (errors.length) {
          showError('Arquivo não aceito', errors.join(' '));
        }

        renderList();
        if (files.length) showActivePreview();
      }

      dropzone.addEventListener('click', function () {
        input.click();
      });

      dropzone.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          input.click();
        }
      });

      input.addEventListener('change', function () {
        if (input.files && input.files.length) addFiles(input.files);
        input.value = '';
      });

      dropzone.addEventListener('dragover', function (e) {
        e.preventDefault();
        setDropzoneState('drag');
      });

      dropzone.addEventListener('dragleave', function () {
        setDropzoneState(files.length ? 'ok' : null);
      });

      dropzone.addEventListener('drop', function (e) {
        e.preventDefault();
        if (e.dataTransfer.files && e.dataTransfer.files.length) {
          addFiles(e.dataTransfer.files);
        }
      });

      window.addEventListener('beforeunload', revokeAll);
    });
  }

  document.addEventListener('DOMContentLoaded', initFileUploadPreview);
})();
