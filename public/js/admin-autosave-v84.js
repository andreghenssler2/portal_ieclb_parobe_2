/* Portal IECLB Parobé v0.84.0 - autosave e recuperação de rascunhos */
(() => {
  'use strict';

  const form =
    document.querySelector(
      '#postEditorForm, #pageEditorForm'
    );

  if (!form) return;

  const isPost =
    form.id === 'postEditorForm';

  const type =
    isPost
      ? 'post'
      : 'pagina';

  const contentId =
    Math.max(
      0,
      Number(
        new URLSearchParams(
          window.location.search
        ).get('id')
        || 0
      )
    );

  const csrf =
    form.querySelector(
      'input[name="_token"]'
    );

  if (!csrf || !csrf.value) return;

  const endpoint =
    new URL(
      '../autosave.php',
      window.location.href
    ).toString();

  let lastSavedSerialized = '';
  let pendingRecovery = false;
  let saving = false;
  let submitting = false;
  let debounceTimer = null;

  function statusContainer() {
    let node =
      document.getElementById(
        'portalAutosaveStatus'
      );

    if (node) return node;

    node =
      document.createElement(
        'span'
      );

    node.id =
      'portalAutosaveStatus';

    node.className =
      'small text-secondary d-inline-flex align-items-center gap-1';

    node.innerHTML =
      '<i class="bi bi-cloud-check"></i><span>Autosave ativo</span>';

    const toolbarActions =
      document.querySelector(
        '.wp-editor-toolbar .d-flex.flex-wrap'
      );

    if (toolbarActions) {
      toolbarActions.prepend(node);
    } else {
      form.prepend(node);
    }

    return node;
  }

  function setStatus(
    text,
    mode = 'secondary',
    icon = 'bi-cloud-check'
  ) {
    const node =
      statusContainer();

    node.className =
      'small d-inline-flex align-items-center gap-1 text-'
      + mode;

    node.innerHTML =
      '<i class="bi '
      + icon
      + '"></i><span></span>';

    const label =
      node.querySelector(
        'span'
      );

    if (label) {
      label.textContent =
        text;
    }
  }

  function normalizedName(
    name
  ) {
    return String(
      name || ''
    );
  }

  function collect() {
    if (
      typeof tinymce !== 'undefined'
    ) {
      try {
        tinymce.triggerSave();
      } catch (_) {
      }
    }

    const values = {};
    const checks = {};
    const radios = {};
    const multiple = {};

    Array.from(
      form.elements
    ).forEach((field) => {
      if (
        !field
        || !field.name
        || field.disabled
      ) {
        return;
      }

      const name =
        normalizedName(
          field.name
        );

      const inputType =
        String(
          field.type || ''
        ).toLowerCase();

      if (
        name === '_token'
        || inputType === 'file'
        || inputType === 'submit'
        || inputType === 'button'
        || inputType === 'reset'
      ) {
        return;
      }

      /*
       * A mídia destacada e uploads binários ficam fora do autosave para
       * evitar restaurar um preview visual diferente do ID oculto.
       */
      if (
        name === 'imagem_capa_id'
        || name === 'imagem_capa_upload'
      ) {
        return;
      }

      if (inputType === 'checkbox') {
        if (!checks[name]) {
          checks[name] = [];
        }

        if (field.checked) {
          checks[name].push(
            String(field.value)
          );
        }

        return;
      }

      if (inputType === 'radio') {
        if (field.checked) {
          radios[name] =
            String(field.value);
        } else if (
          !(name in radios)
        ) {
          radios[name] = null;
        }

        return;
      }

      if (
        field.tagName === 'SELECT'
        && field.multiple
      ) {
        multiple[name] =
          Array.from(
            field.selectedOptions
          ).map(
            (option) =>
              String(
                option.value
              )
          );

        return;
      }

      values[name] =
        String(
          field.value ?? ''
        );
    });

    return {
      version: 1,
      type,
      content_id: contentId,
      values,
      checks,
      radios,
      multiple
    };
  }

  function serialize(
    snapshot
  ) {
    return JSON.stringify(
      snapshot
    );
  }

  function meaningful(
    snapshot
  ) {
    const values =
      snapshot.values || {};

    const candidates = [
      values.titulo,
      values.conteudo,
      values.resumo,
      values.content_blocks_json,
      values.seo_titulo,
      values.seo_descricao
    ];

    return candidates.some(
      (value) => {
        const text =
          String(
            value || ''
          ).trim();

        return (
          text !== ''
          && text !== '[]'
        );
      }
    );
  }

  async function request(
    action,
    extra = {}
  ) {
    const body =
      new URLSearchParams();

    body.set(
      '_token',
      csrf.value
    );

    body.set(
      'action',
      action
    );

    body.set(
      'tipo',
      type
    );

    body.set(
      'content_id',
      String(contentId)
    );

    Object.entries(
      extra
    ).forEach(
      ([key, value]) => {
        body.set(
          key,
          String(value)
        );
      }
    );

    const response =
      await fetch(
        endpoint,
        {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type':
              'application/x-www-form-urlencoded;charset=UTF-8',
            'X-Requested-With':
              'XMLHttpRequest'
          },
          body: body.toString()
        }
      );

    let data = null;

    try {
      data =
        await response.json();
    } catch (_) {
      throw new Error(
        'Resposta inválida do autosave.'
      );
    }

    if (!response.ok || !data?.ok) {
      if (
        data?.login_required
      ) {
        throw new Error(
          'Sua sessão expirou.'
        );
      }

      throw new Error(
        data?.error
        || 'Falha no autosave.'
      );
    }

    return data;
  }

  async function saveNow() {
    if (
      saving
      || submitting
      || pendingRecovery
    ) {
      return;
    }

    const snapshot =
      collect();

    const serialized =
      serialize(
        snapshot
      );

    if (
      serialized
      === lastSavedSerialized
    ) {
      return;
    }

    if (
      contentId === 0
      && !meaningful(snapshot)
    ) {
      lastSavedSerialized =
        serialized;

      return;
    }

    saving = true;

    setStatus(
      'Salvando rascunho...',
      'secondary',
      'bi-cloud-arrow-up'
    );

    try {
      const result =
        await request(
          'save',
          {
            payload:
              serialized
          }
        );

      lastSavedSerialized =
        serialized;

      const now =
        new Date();

      setStatus(
        'Salvo automaticamente às '
        + now.toLocaleTimeString(
          'pt-BR',
          {
            hour: '2-digit',
            minute: '2-digit'
          }
        ),
        'success',
        'bi-cloud-check'
      );
    } catch (error) {
      setStatus(
        error?.message
        || 'Falha no autosave',
        'danger',
        'bi-cloud-slash'
      );
    } finally {
      saving = false;
    }
  }

  function scheduleSave() {
    if (
      pendingRecovery
      || submitting
    ) {
      return;
    }

    window.clearTimeout(
      debounceTimer
    );

    debounceTimer =
      window.setTimeout(
        saveNow,
        8000
      );

    setStatus(
      'Alterações não salvas',
      'warning',
      'bi-cloud'
    );
  }

  function fieldsByName(
    name
  ) {
    return Array.from(
      form.elements
    ).filter(
      (field) =>
        field
        && field.name === name
    );
  }

  function applySnapshot(
    snapshot
  ) {
    const values =
      snapshot?.values
      || {};

    const checks =
      snapshot?.checks
      || {};

    const radios =
      snapshot?.radios
      || {};

    const multiple =
      snapshot?.multiple
      || {};

    Object.entries(
      values
    ).forEach(
      ([name, value]) => {
        fieldsByName(
          name
        ).forEach(
          (field) => {
            const inputType =
              String(
                field.type || ''
              ).toLowerCase();

            if (
              inputType === 'file'
              || name === 'imagem_capa_id'
              || name === 'imagem_capa_upload'
            ) {
              return;
            }

            field.value =
              String(
                value ?? ''
              );

            field.dispatchEvent(
              new Event(
                'input',
                {
                  bubbles: true
                }
              )
            );

            field.dispatchEvent(
              new Event(
                'change',
                {
                  bubbles: true
                }
              )
            );
          }
        );
      }
    );

    Object.entries(
      checks
    ).forEach(
      ([name, selected]) => {
        const valuesSelected =
          Array.isArray(selected)
            ? selected.map(String)
            : [];

        fieldsByName(
          name
        ).forEach(
          (field) => {
            if (
              String(
                field.type || ''
              ).toLowerCase()
              !== 'checkbox'
            ) {
              return;
            }

            field.checked =
              valuesSelected.includes(
                String(
                  field.value
                )
              );

            field.dispatchEvent(
              new Event(
                'change',
                {
                  bubbles: true
                }
              )
            );
          }
        );
      }
    );

    Object.entries(
      radios
    ).forEach(
      ([name, selected]) => {
        fieldsByName(
          name
        ).forEach(
          (field) => {
            if (
              String(
                field.type || ''
              ).toLowerCase()
              !== 'radio'
            ) {
              return;
            }

            field.checked =
              selected !== null
              && String(
                field.value
              ) === String(
                selected
              );

            field.dispatchEvent(
              new Event(
                'change',
                {
                  bubbles: true
                }
              )
            );
          }
        );
      }
    );

    Object.entries(
      multiple
    ).forEach(
      ([name, selected]) => {
        const valuesSelected =
          Array.isArray(selected)
            ? selected.map(String)
            : [];

        fieldsByName(
          name
        ).forEach(
          (field) => {
            if (
              field.tagName !== 'SELECT'
              || !field.multiple
            ) {
              return;
            }

            Array.from(
              field.options
            ).forEach(
              (option) => {
                option.selected =
                  valuesSelected.includes(
                    String(
                      option.value
                    )
                  );
              }
            );

            field.dispatchEvent(
              new Event(
                'change',
                {
                  bubbles: true
                }
              )
            );
          }
        );
      }
    );

    if (
      'conteudo'
      in values
      && typeof tinymce !== 'undefined'
    ) {
      try {
        const editor =
          tinymce.get(
            'conteudo'
          );

        if (editor) {
          editor.setContent(
            String(
              values.conteudo
              || ''
            )
          );

          editor.save();
        }
      } catch (_) {
      }
    }

    if (
      values.content_blocks_json
      && window.ContentBlockEditor
      && typeof window.ContentBlockEditor.restoreAll
        === 'function'
    ) {
      try {
        const blocks =
          JSON.parse(
            values.content_blocks_json
          );

        if (
          Array.isArray(blocks)
        ) {
          window.ContentBlockEditor.restoreAll(
            blocks
          );
        }
      } catch (_) {
      }
    }

    const resumo =
      String(
        values.resumo
        || ''
      ).trim();

    if (resumo !== '') {
      document.getElementById(
        'summaryEditor'
      )?.classList.remove(
        'd-none'
      );
    }

    const title =
      document.getElementById(
        isPost
          ? 'postTitulo'
          : 'pageTitulo'
      );

    if (title) {
      title.dispatchEvent(
        new Event(
          'input',
          {
            bubbles: true
          }
        )
      );
    }
  }

  function formatDraftDate(
    value
  ) {
    if (!value) {
      return 'horário não informado';
    }

    const normalized =
      String(value).replace(
        ' ',
        'T'
      );

    const date =
      new Date(
        normalized
      );

    if (
      Number.isNaN(
        date.getTime()
      )
    ) {
      return String(value);
    }

    return date.toLocaleString(
      'pt-BR'
    );
  }

  function removeRecoveryBanner() {
    document.getElementById(
      'portalAutosaveRecovery'
    )?.remove();
  }

  function showRecoveryBanner(
    draft
  ) {
    pendingRecovery = true;

    const page =
      document.querySelector(
        '.wp-post-editor-page'
      );

    if (!page) {
      return;
    }

    const alert =
      document.createElement(
        'div'
      );

    alert.id =
      'portalAutosaveRecovery';

    alert.className =
      'alert alert-warning shadow-sm d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3';

    const text =
      document.createElement(
        'div'
      );

    text.innerHTML =
      '<div class="fw-semibold"><i class="bi bi-clock-history me-1"></i>Rascunho automático encontrado</div>'
      + '<div class="small mt-1">Há alterações não salvas de '
      + '<strong></strong>'
      + '. Você pode recuperá-las ou descartá-las.</div>';

    const strong =
      text.querySelector(
        'strong'
      );

    if (strong) {
      strong.textContent =
        formatDraftDate(
          draft.updated_at
        );
    }

    const actions =
      document.createElement(
        'div'
      );

    actions.className =
      'd-flex flex-wrap gap-2';

    const recover =
      document.createElement(
        'button'
      );

    recover.type =
      'button';

    recover.className =
      'btn btn-sm btn-warning';

    recover.innerHTML =
      '<i class="bi bi-arrow-counterclockwise me-1"></i>Recuperar rascunho';

    const discard =
      document.createElement(
        'button'
      );

    discard.type =
      'button';

    discard.className =
      'btn btn-sm btn-outline-secondary';

    discard.textContent =
      'Descartar';

    recover.addEventListener(
      'click',
      () => {
        applySnapshot(
          draft.payload
        );

        pendingRecovery =
          false;

        lastSavedSerialized =
          serialize(
            collect()
          );

        removeRecoveryBanner();

        setStatus(
          'Rascunho recuperado — clique em Salvar/Atualizar para confirmar',
          'warning',
          'bi-clock-history'
        );
      }
    );

    discard.addEventListener(
      'click',
      async () => {
        discard.disabled =
          true;

        try {
          await request(
            'delete'
          );

          pendingRecovery =
            false;

          lastSavedSerialized =
            serialize(
              collect()
            );

          removeRecoveryBanner();

          setStatus(
            'Rascunho automático descartado',
            'secondary',
            'bi-trash'
          );
        } catch (error) {
          discard.disabled =
            false;

          setStatus(
            error?.message
            || 'Não foi possível descartar',
            'danger',
            'bi-cloud-slash'
          );
        }
      }
    );

    actions.append(
      recover,
      discard
    );

    alert.append(
      text,
      actions
    );

    const toolbar =
      page.querySelector(
        '.wp-editor-toolbar'
      );

    if (toolbar) {
      toolbar.insertAdjacentElement(
        'afterend',
        alert
      );
    } else {
      page.prepend(alert);
    }

    setStatus(
      'Autosave pausado até escolher recuperar ou descartar',
      'warning',
      'bi-pause-circle'
    );
  }

  async function initialize() {
    statusContainer();

    const current =
      collect();

    const currentSerialized =
      serialize(
        current
      );

    try {
      const response =
        await request(
          'load'
        );

      const draft =
        response?.draft;

      if (
        draft
        && draft.payload
      ) {
        const draftSerialized =
          serialize(
            draft.payload
          );

        if (
          draftSerialized
          !== currentSerialized
        ) {
          showRecoveryBanner(
            draft
          );

          return;
        }

        lastSavedSerialized =
          draftSerialized;
      } else {
        lastSavedSerialized =
          currentSerialized;
      }

      setStatus(
        'Autosave ativo',
        'secondary',
        'bi-cloud-check'
      );
    } catch (error) {
      lastSavedSerialized =
        currentSerialized;

      setStatus(
        error?.message
        || 'Autosave indisponível',
        'danger',
        'bi-cloud-slash'
      );
    }
  }

  form.addEventListener(
    'input',
    scheduleSave
  );

  form.addEventListener(
    'change',
    scheduleSave
  );

  form.addEventListener(
    'submit',
    () => {
      submitting = true;

      window.clearTimeout(
        debounceTimer
      );

      setStatus(
        'Salvando conteúdo...',
        'secondary',
        'bi-arrow-repeat'
      );
    }
  );

  window.setInterval(
    saveNow,
    30000
  );

  initialize();
})();
