(() => {
  const root = document.getElementById('portalHomeV028');
  if (!root) return;

  const normalize = (value) => (value || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/\s+/g, ' ')
    .trim()
    .toLowerCase();

  function aliasesFor(title){
    const titleKey = normalize(title);
    const aliases = new Set([titleKey]);
    if(titleKey.includes('comunidade')){
      aliases.add('comunidades');
      aliases.add('nossas comunidades');
    }
    if(titleKey.includes('ultimas noticias')){
      aliases.add('ultimas noticias');
      aliases.add('noticias recentes');
    }
    if(titleKey.includes('paroquia')){
      aliases.add('paroquia');
      aliases.add('paroquia em destaque');
    }
    return aliases;
  }

  function legacyContainer(heading){
    return heading.closest('section,[data-home-section],.home-section,.section-block,.homepage-section');
  }

  function integrateWithLegacyHome(){
    const aliases = new Set();
    root.querySelectorAll('.home-block__head h2').forEach(h => {
      aliasesFor(h.textContent).forEach(value => aliases.add(value));
    });
    if(!aliases.size) return;

    const legacy = [];
    document.querySelectorAll('h1,h2,h3,h4').forEach(heading => {
      if(root.contains(heading)) return;
      if(!aliases.has(normalize(heading.textContent))) return;
      const container = legacyContainer(heading);
      if(!container || container.contains(root)) return;
      legacy.push(container);
    });

    // A integração v0.28.1 inclui a home modular antes do rodapé. Para manter
    // agenda/topo e, ao mesmo tempo, substituir os blocos antigos, movemos a
    // home modular para antes do primeiro bloco legado equivalente (normalmente
    // "Nossas comunidades").
    const firstLegacy = legacy.find(container =>
      container.compareDocumentPosition(root) & Node.DOCUMENT_POSITION_FOLLOWING
    );
    if(firstLegacy && firstLegacy.parentNode){
      firstLegacy.parentNode.insertBefore(root, firstLegacy);
      root.setAttribute('data-home-relocated', '1');
    }

    [...new Set(legacy)].forEach(container => {
      container.hidden = true;
      container.setAttribute('data-home-legacy-hidden', '1');
    });
  }

  function initCarousel(section){
    const rail = section.querySelector('[data-home-carousel]');
    if(!rail) return;
    const prev = section.querySelector('[data-home-prev]');
    const next = section.querySelector('[data-home-next]');

    const step = () => {
      const card = rail.querySelector('.home-card--carousel');
      if(!card) return Math.max(260, Math.round(rail.clientWidth * .78));
      const styles = getComputedStyle(rail);
      const gap = parseFloat(styles.columnGap || styles.gap || '20') || 20;
      return Math.round(card.getBoundingClientRect().width + gap);
    };

    const updateArrows = () => {
      const hasOverflow = rail.scrollWidth > rail.clientWidth + 4;
      if(prev) prev.hidden = !hasOverflow;
      if(next) next.hidden = !hasOverflow;
    };

    prev?.addEventListener('click', () => rail.scrollBy({left:-step(), behavior:'smooth'}));
    next?.addEventListener('click', () => rail.scrollBy({left:step(), behavior:'smooth'}));
    updateArrows();
    window.addEventListener('resize', updateArrows, {passive:true});

    if(section.dataset.homeAutoplay === '1'){
      let timer = null;
      const play = () => {
        if(timer) return;
        timer = setInterval(() => {
          const end = rail.scrollLeft + rail.clientWidth >= rail.scrollWidth - 8;
          rail.scrollTo({left:end ? 0 : rail.scrollLeft + step(), behavior:'smooth'});
        }, 5200);
      };
      const stop = () => { if(timer){ clearInterval(timer); timer = null; } };
      section.addEventListener('mouseenter', stop);
      section.addEventListener('mouseleave', play);
      section.addEventListener('focusin', stop);
      section.addEventListener('focusout', play);
      play();
    }
  }

  integrateWithLegacyHome();
  root.querySelectorAll('.home-block').forEach(initCarousel);
})();
