(() => {
  const root = document.getElementById('portalHomeV028');

  const normalize = (value) => (value || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/\s+/g, ' ')
    .trim()
    .toLowerCase();

  function hideLegacySections(){
    if(!root) return;
    const titles = new Set([...root.querySelectorAll('.home-block__head h2')].map(h => normalize(h.textContent)).filter(Boolean));
    if(!titles.size) return;

    document.querySelectorAll('h1,h2,h3,h4').forEach(heading => {
      if(root.contains(heading)) return;
      if(!titles.has(normalize(heading.textContent))) return;
      // Só substitui conteúdo que aparece antes da nova home modular.
      if(!(heading.compareDocumentPosition(root) & Node.DOCUMENT_POSITION_FOLLOWING)) return;
      const section = heading.closest('section');
      if(section && !section.contains(root)) {
        section.hidden = true;
        section.setAttribute('data-home-legacy-hidden', '1');
      }
    });
  }

  function init(section){
    const rail=section.querySelector('[data-home-carousel]');
    if(!rail) return;
    const prev=section.querySelector('[data-home-prev]');
    const next=section.querySelector('[data-home-next]');
    const step=()=>Math.max(260,Math.round(rail.clientWidth*.78));
    prev?.addEventListener('click',()=>rail.scrollBy({left:-step(),behavior:'smooth'}));
    next?.addEventListener('click',()=>rail.scrollBy({left:step(),behavior:'smooth'}));
    if(section.dataset.homeAutoplay==='1'){
      let timer=setInterval(()=>{
        const end=rail.scrollLeft+rail.clientWidth>=rail.scrollWidth-8;
        rail.scrollTo({left:end?0:rail.scrollLeft+step(),behavior:'smooth'});
      },5200);
      section.addEventListener('mouseenter',()=>{if(timer){clearInterval(timer);timer=null;}});
      section.addEventListener('mouseleave',()=>{if(!timer) timer=setInterval(()=>{const end=rail.scrollLeft+rail.clientWidth>=rail.scrollWidth-8;rail.scrollTo({left:end?0:rail.scrollLeft+step(),behavior:'smooth'});},5200);});
    }
  }

  hideLegacySections();
  document.querySelectorAll('.home-block').forEach(init);
})();
