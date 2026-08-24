/* Portal IECLB Parobé v0.46.1 - layouts Carrossel, Grade e Destaque + 2 */
(() => {
  const LABELS = {
    heading: 'Título',
    text: 'Texto',
    image: 'Imagem',
    quote: 'Citação',
    button: 'Botão / Link',
    video: 'Vídeo',
    columns: 'Duas colunas',
    separator: 'Separador',
    portal_posts: 'Últimas Notícias',
    portal_events: 'Agenda / Eventos',
    portal_documents: 'Documentos',
    portal_galleries: 'Galerias',
    portal_communities: 'Comunidades'
  };

  function esc(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function clone(value) {
    return JSON.parse(JSON.stringify(value));
  }

  function defaultData(type) {
    switch (type) {
      case 'heading': return {text:'', level:'h2'};
      case 'text': return {text:''};
      case 'image': return {media_id:0, alt:'', caption:'', preview_url:'', preview_title:''};
      case 'quote': return {text:'', author:''};
      case 'button': return {text:'Saiba mais', url:'', style:'primary'};
      case 'video': return {url:'', caption:''};
      case 'columns': return {left:'', right:''};
      case 'portal_posts': return {title:'Últimas Notícias', limit:4, layout:'cards', category_id:0, show_excerpt:'0', show_date:'1'};
      case 'portal_events': return {title:'Agenda', limit:4, layout:'cards', community_id:0, show_date:'1'};
      case 'portal_documents': return {title:'Documentos', limit:5, layout:'list', category_id:0, show_date:'1'};
      case 'portal_galleries': return {title:'Galerias', limit:4, layout:'cards', show_date:'1'};
      case 'portal_communities': return {title:'Comunidades', limit:4, layout:'cards'};
      default: return {};
    }
  }

  function summary(type, data) {
    const raw = (() => {
      switch (type) {
        case 'heading': return data.text;
        case 'text': return data.text;
        case 'image': return data.preview_title || data.caption || data.alt;
        case 'quote': return data.text;
        case 'button': return data.text;
        case 'video': return data.caption || data.url;
        case 'columns': return data.left || data.right;
        case 'separator': return 'Linha de separação';
        case 'portal_posts':
        case 'portal_events':
        case 'portal_documents':
        case 'portal_galleries':
        case 'portal_communities':
          return data.title || LABELS[type];
        default: return '';
      }
    })();

    const text = String(raw || '').replace(/\s+/g, ' ').trim();
    return text.length > 72 ? text.substring(0, 69) + '…' : text;
  }

  function selectOptions(items, selected, emptyLabel) {
    const options = [`<option value="0">${esc(emptyLabel)}</option>`];
    (Array.isArray(items) ? items : []).forEach(item => {
      const id = Number(item?.id || 0);
      const name = String(item?.nome || '');
      options.push(
        `<option value="${id}" ${Number(selected || 0) === id ? 'selected' : ''}>${esc(name)}</option>`
      );
    });
    return options.join('');
  }

  function commonDynamicFields(data) {
    return `
      <div class="row g-2 mb-2">
        <div class="col-md-6">
          <label class="form-label small">Título da seção</label>
          <input class="form-control form-control-sm" data-field="title" value="${esc(data.title || '')}">
        </div>
        <div class="col-md-2">
          <label class="form-label small">Quantidade</label>
          <input class="form-control form-control-sm" type="number" min="1" max="12" data-field="limit" value="${Number(data.limit || 4)}">
        </div>
        <div class="col-md-4">
          <label class="form-label small">Layout</label>
          <select class="form-select form-select-sm" data-field="layout">
            <option value="cards" ${data.layout === 'cards' ? 'selected' : ''}>Cards</option>
            <option value="list" ${data.layout === 'list' ? 'selected' : ''}>Lista</option>
            <option value="carousel" ${data.layout === 'carousel' ? 'selected' : ''}>Carrossel</option>
            <option value="grid" ${data.layout === 'grid' ? 'selected' : ''}>Grade</option>
            <option value="featured2" ${data.layout === 'featured2' ? 'selected' : ''}>Destaque + 2</option>
          </select>
        </div>
      </div>`;
  }

  function yesNoSelect(field, value, label) {
    return `
      <div>
        <label class="form-label small">${esc(label)}</label>
        <select class="form-select form-select-sm" data-field="${esc(field)}">
          <option value="1" ${String(value) !== '0' ? 'selected' : ''}>Sim</option>
          <option value="0" ${String(value) === '0' ? 'selected' : ''}>Não</option>
        </select>
      </div>`;
  }

  function fields(type, data, dynamicOptions) {
    switch (type) {
      case 'heading':
        return `
          <div class="row g-2">
            <div class="col-md-3">
              <label class="form-label small">Nível</label>
              <select class="form-select form-select-sm" data-field="level">
                <option value="h2" ${data.level === 'h2' ? 'selected' : ''}>H2</option>
                <option value="h3" ${data.level === 'h3' ? 'selected' : ''}>H3</option>
                <option value="h4" ${data.level === 'h4' ? 'selected' : ''}>H4</option>
              </select>
            </div>
            <div class="col-md-9">
              <label class="form-label small">Título</label>
              <input class="form-control form-control-sm" data-field="text" value="${esc(data.text)}">
            </div>
          </div>`;

      case 'text':
        return `
          <label class="form-label small">Texto</label>
          <textarea class="form-control" rows="5" data-field="text" placeholder="Digite o texto deste bloco…">${esc(data.text)}</textarea>`;

      case 'image':
        return `
          <input type="hidden" data-field="media_id" value="${Number(data.media_id || 0)}">
          <div class="d-flex flex-wrap gap-3 align-items-start">
            <div class="content-block-image-preview border rounded bg-body-tertiary d-flex align-items-center justify-content-center overflow-hidden" style="width:180px;height:110px">
              ${data.preview_url ? `<img src="${esc(data.preview_url)}" alt="" class="w-100 h-100" style="object-fit:cover">` : '<span class="small text-secondary">Sem imagem</span>'}
            </div>
            <div class="flex-grow-1">
              <button class="btn btn-sm btn-outline-primary mb-2" type="button" data-block-media>Escolher na Biblioteca</button>
              <button class="btn btn-sm btn-link text-danger mb-2 ${data.media_id ? '' : 'd-none'}" type="button" data-block-media-remove>Remover</button>
              <div class="small text-secondary mb-2" data-block-media-title>${esc(data.preview_title || '')}</div>
              <div class="row g-2">
                <div class="col-md-6"><label class="form-label small">Texto alternativo</label><input class="form-control form-control-sm" data-field="alt" value="${esc(data.alt)}"></div>
                <div class="col-md-6"><label class="form-label small">Legenda</label><input class="form-control form-control-sm" data-field="caption" value="${esc(data.caption)}"></div>
              </div>
            </div>
          </div>`;

      case 'quote':
        return `
          <label class="form-label small">Citação</label>
          <textarea class="form-control mb-2" rows="4" data-field="text">${esc(data.text)}</textarea>
          <label class="form-label small">Autor / Fonte</label>
          <input class="form-control form-control-sm" data-field="author" value="${esc(data.author)}">`;

      case 'button':
        return `
          <div class="row g-2">
            <div class="col-md-4"><label class="form-label small">Texto do botão</label><input class="form-control form-control-sm" data-field="text" value="${esc(data.text)}"></div>
            <div class="col-md-5"><label class="form-label small">URL</label><input class="form-control form-control-sm" data-field="url" value="${esc(data.url)}" placeholder="/contato ou https://…"></div>
            <div class="col-md-3"><label class="form-label small">Estilo</label><select class="form-select form-select-sm" data-field="style"><option value="primary" ${data.style === 'primary' ? 'selected' : ''}>Destaque</option><option value="outline" ${data.style === 'outline' ? 'selected' : ''}>Contorno</option></select></div>
          </div>`;

      case 'video':
        return `
          <label class="form-label small">URL do YouTube ou Vimeo</label>
          <input class="form-control form-control-sm mb-2" data-field="url" value="${esc(data.url)}" placeholder="https://www.youtube.com/watch?v=…">
          <label class="form-label small">Legenda</label>
          <input class="form-control form-control-sm" data-field="caption" value="${esc(data.caption)}">`;

      case 'columns':
        return `
          <div class="row g-3">
            <div class="col-md-6"><label class="form-label small">Coluna esquerda</label><textarea class="form-control" rows="5" data-field="left">${esc(data.left)}</textarea></div>
            <div class="col-md-6"><label class="form-label small">Coluna direita</label><textarea class="form-control" rows="5" data-field="right">${esc(data.right)}</textarea></div>
          </div>`;

      case 'separator':
        return '<div class="text-secondary small py-2">Insere uma linha de separação no conteúdo.</div>';

      case 'portal_posts':
        return `
          <div class="alert alert-info py-2 small">Este bloco busca automaticamente as Notícias publicadas mais recentes.</div>
          ${commonDynamicFields(data)}
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label small">Categoria</label>
              <select class="form-select form-select-sm" data-field="category_id">
                ${selectOptions(dynamicOptions?.categorias, data.category_id, 'Todas as categorias')}
              </select>
            </div>
            <div class="col-md-3">${yesNoSelect('show_date', data.show_date, 'Mostrar data')}</div>
            <div class="col-md-3">${yesNoSelect('show_excerpt', data.show_excerpt, 'Mostrar resumo')}</div>
          </div>`;

      case 'portal_events':
        return `
          <div class="alert alert-info py-2 small">Mostra automaticamente os próximos eventos publicados.</div>
          ${commonDynamicFields(data)}
          <div class="row g-2">
            <div class="col-md-8">
              <label class="form-label small">Comunidade</label>
              <select class="form-select form-select-sm" data-field="community_id">
                ${selectOptions(dynamicOptions?.comunidades, data.community_id, 'Todas as comunidades')}
              </select>
            </div>
            <div class="col-md-4">${yesNoSelect('show_date', data.show_date, 'Mostrar data e hora')}</div>
          </div>`;

      case 'portal_documents':
        return `
          <div class="alert alert-info py-2 small">Mostra automaticamente os Documentos publicados.</div>
          ${commonDynamicFields(data)}
          <div class="row g-2">
            <div class="col-md-8">
              <label class="form-label small">Categoria de documento</label>
              <select class="form-select form-select-sm" data-field="category_id">
                ${selectOptions(dynamicOptions?.documento_categorias, data.category_id, 'Todas as categorias')}
              </select>
            </div>
            <div class="col-md-4">${yesNoSelect('show_date', data.show_date, 'Mostrar data')}</div>
          </div>`;

      case 'portal_galleries':
        return `
          <div class="alert alert-info py-2 small">Mostra automaticamente as Galerias publicadas mais recentes.</div>
          ${commonDynamicFields(data)}
          <div class="row g-2">
            <div class="col-md-4">${yesNoSelect('show_date', data.show_date, 'Mostrar data')}</div>
          </div>`;

      case 'portal_communities':
        return `
          <div class="alert alert-info py-2 small">Mostra automaticamente as Comunidades ativas cadastradas no Portal.</div>
          ${commonDynamicFields(data)}`;

      default:
        return '';
    }
  }

  function init(root) {
    if (!root || root.dataset.blockEditorReady === '1') return;
    root.dataset.blockEditorReady = '1';

    const list = root.querySelector('[data-block-list]');
    const empty = root.querySelector('[data-block-empty]');
    const hidden = root.querySelector('#contentBlocksJson');
    const initial = root.querySelector('#contentBlocksInitial');
    const patternInitial = root.querySelector('#contentPatternsInitial');
    const dynamicOptionsInitial = root.querySelector('#contentDynamicOptionsInitial');

    let blocks = [];
    let patterns = [];
    let dynamicOptions = {};

    try {
      blocks = JSON.parse(initial?.textContent || '[]');
      if (!Array.isArray(blocks)) blocks = [];
    } catch (_) {
      blocks = [];
    }

    try {
      patterns = JSON.parse(patternInitial?.textContent || '[]');
      if (!Array.isArray(patterns)) patterns = [];
    } catch (_) {
      patterns = [];
    }

    try {
      dynamicOptions = JSON.parse(dynamicOptionsInitial?.textContent || '{}');
      if (!dynamicOptions || typeof dynamicOptions !== 'object') dynamicOptions = {};
    } catch (_) {
      dynamicOptions = {};
    }

    blocks = blocks.slice(0, 50).map(block => ({
      type: String(block?.type || ''),
      data: Object.assign(defaultData(String(block?.type || '')), block?.data || {})
    })).filter(block => LABELS[block.type]);

    function collectCard(card, block) {
      const data = Object.assign({}, block.data);
      card.querySelectorAll('[data-field]').forEach(field => {
        const key = field.dataset.field;
        if (!key) return;
        if (key === 'media_id') data[key] = Number(field.value || 0);
        else data[key] = field.value;
      });
      delete data.preview_url;
      delete data.preview_title;
      return {type:block.type, data};
    }

    function sync() {
      const next = [];
      list.querySelectorAll('[data-block-index]').forEach(card => {
        const index = Number(card.dataset.blockIndex);
        const block = blocks[index];
        if (block) next.push(collectCard(card, block));
      });
      hidden.value = JSON.stringify(next);
    }

    function syncBlocksFromDom() {
      const next = [];
      list.querySelectorAll('[data-block-index]').forEach(card => {
        const index = Number(card.dataset.blockIndex);
        const block = blocks[index];
        if (block) next.push(collectCard(card, block));
      });
      blocks = next.map(item => ({
        type: item.type,
        data: Object.assign(defaultData(item.type), item.data)
      }));
    }

    function insertPattern(patternId) {
      syncBlocksFromDom();

      const pattern = patterns.find(item => Number(item.id) === Number(patternId));
      if (!pattern || !Array.isArray(pattern.blocks)) return;

      const available = Math.max(0, 50 - blocks.length);
      const incoming = pattern.blocks.slice(0, available).map(block => ({
        type: String(block?.type || ''),
        data: Object.assign(defaultData(String(block?.type || '')), clone(block?.data || {}))
      })).filter(block => LABELS[block.type]);

      blocks = blocks.concat(incoming);
      render();

      const modalElement = document.getElementById('contentPatternsModal');
      if (modalElement && window.bootstrap?.Modal) {
        const modal = window.bootstrap.Modal.getInstance(modalElement);
        modal?.hide();
      }

      list.lastElementChild?.scrollIntoView({behavior:'smooth', block:'center'});
    }

    function render() {
      list.innerHTML = '';

      blocks.forEach((block, index) => {
        const card = document.createElement('div');
        card.className = 'border rounded-3 bg-white overflow-hidden content-block-card';
        card.dataset.blockIndex = String(index);
        card.draggable = true;

        card.innerHTML = `
          <div class="d-flex align-items-center gap-2 border-bottom px-3 py-2 bg-body-tertiary" data-block-drag-handle>
            <i class="bi bi-grip-vertical text-secondary" title="Arraste para reordenar"></i>
            <div class="flex-grow-1 min-w-0">
              <strong class="small">${esc(LABELS[block.type])}</strong>
              ${summary(block.type, block.data) ? `<span class="small text-secondary ms-2">${esc(summary(block.type, block.data))}</span>` : ''}
            </div>
            <button class="btn btn-sm btn-light border" type="button" data-block-collapse title="Recolher/expandir"><i class="bi bi-chevron-up"></i></button>
            <button class="btn btn-sm btn-light border" type="button" data-block-duplicate title="Duplicar bloco"><i class="bi bi-copy"></i></button>
            <button class="btn btn-sm btn-light border" type="button" data-block-up title="Mover para cima"><i class="bi bi-arrow-up"></i></button>
            <button class="btn btn-sm btn-light border" type="button" data-block-down title="Mover para baixo"><i class="bi bi-arrow-down"></i></button>
            <button class="btn btn-sm btn-outline-danger" type="button" data-block-remove title="Remover"><i class="bi bi-trash"></i></button>
          </div>
          <div class="p-3" data-block-body>${fields(block.type, block.data, dynamicOptions)}</div>
        `;

        card.addEventListener('dragstart', event => {
          syncBlocksFromDom();
          event.dataTransfer.effectAllowed = 'move';
          event.dataTransfer.setData('text/plain', String(index));
          card.classList.add('opacity-50');
        });

        card.addEventListener('dragend', () => {
          card.classList.remove('opacity-50');
        });

        card.addEventListener('dragover', event => {
          event.preventDefault();
          event.dataTransfer.dropEffect = 'move';
        });

        card.addEventListener('drop', event => {
          event.preventDefault();
          const from = Number(event.dataTransfer.getData('text/plain'));
          const to = index;

          if (!Number.isInteger(from) || from < 0 || from >= blocks.length || from === to) {
            return;
          }

          const [moved] = blocks.splice(from, 1);
          blocks.splice(to, 0, moved);
          render();
        });

        card.querySelector('[data-block-collapse]')?.addEventListener('click', event => {
          const body = card.querySelector('[data-block-body]');
          const icon = event.currentTarget.querySelector('i');
          body?.classList.toggle('d-none');
          icon?.classList.toggle('bi-chevron-up');
          icon?.classList.toggle('bi-chevron-down');
        });

        card.querySelector('[data-block-duplicate]')?.addEventListener('click', () => {
          syncBlocksFromDom();
          if (blocks.length >= 50) {
            alert('O limite é de 50 blocos por conteúdo.');
            return;
          }

          const copy = clone(blocks[index]);
          blocks.splice(index + 1, 0, copy);
          render();
        });

        card.querySelector('[data-block-remove]')?.addEventListener('click', () => {
          syncBlocksFromDom();
          blocks.splice(index, 1);
          render();
        });

        card.querySelector('[data-block-up]')?.addEventListener('click', () => {
          syncBlocksFromDom();
          if (index > 0) {
            [blocks[index - 1], blocks[index]] = [blocks[index], blocks[index - 1]];
            render();
          }
        });

        card.querySelector('[data-block-down]')?.addEventListener('click', () => {
          syncBlocksFromDom();
          if (index < blocks.length - 1) {
            [blocks[index + 1], blocks[index]] = [blocks[index], blocks[index + 1]];
            render();
          }
        });

        card.querySelectorAll('input,textarea,select').forEach(el => {
          el.addEventListener('input', sync);
          el.addEventListener('change', sync);
        });

        const mediaButton = card.querySelector('[data-block-media]');
        if (mediaButton) {
          mediaButton.addEventListener('click', () => {
            const picker = window.PortalMediaPicker;
            if (!picker || typeof picker.openForFeatured !== 'function') {
              alert('A Biblioteca de Mídia ainda não foi carregada.');
              return;
            }

            const mediaField = card.querySelector('[data-field="media_id"]');
            picker.openForFeatured(media => {
              if (!media || !media.id) return;

              block.data.media_id = Number(media.id);
              block.data.preview_url = media.url || '';
              block.data.preview_title = media.titulo || media.nome_original || 'Imagem';

              render();
            }, Number(mediaField?.value || 0));
          });
        }

        card.querySelector('[data-block-media-remove]')?.addEventListener('click', () => {
          block.data.media_id = 0;
          block.data.preview_url = '';
          block.data.preview_title = '';
          render();
        });

        list.appendChild(card);
      });

      empty?.classList.toggle('d-none', blocks.length > 0);
      sync();
    }

    root.querySelectorAll('[data-block-add]').forEach(button => {
      button.addEventListener('click', () => {
        syncBlocksFromDom();

        if (blocks.length >= 50) {
          alert('O limite é de 50 blocos por conteúdo.');
          return;
        }

        const type = button.dataset.blockAdd;
        if (!LABELS[type]) return;

        blocks.push({type, data:defaultData(type)});
        render();

        list.lastElementChild?.scrollIntoView({behavior:'smooth', block:'center'});
      });
    });

    document.querySelectorAll('[data-pattern-insert]').forEach(button => {
      button.addEventListener('click', () => {
        insertPattern(button.dataset.patternInsert);
      });
    });

    root.closest('form')?.addEventListener('submit', sync);
    render();
  }

  window.ContentBlockEditor = {
    init() {
      document.querySelectorAll('[data-content-block-editor]').forEach(init);
    }
  };
})();
