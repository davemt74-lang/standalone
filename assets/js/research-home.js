(()=>{
  const root=document.querySelector('[data-research-home]');
  if(!root)return;
  const user=String(document.body?.dataset?.workspaceUser||'anonymous').trim()||'anonymous';
  const key='annotated.researchFavorites.v1.'+user;
  const recent=root.querySelector('[data-research-recent-grid]');
  const favorites=root.querySelector('[data-research-favorites-grid]');
  const favoriteSection=root.querySelector('[data-research-favorites-section]');
  const cards=[...root.querySelectorAll('[data-research-agent-card]')];

  const read=()=>{
    try{
      const value=JSON.parse(localStorage.getItem(key)||'[]');
      return new Set(Array.isArray(value)?value.map(String):[]);
    }catch{return new Set();}
  };
  const write=set=>{
    try{localStorage.setItem(key,JSON.stringify([...set]));}catch{}
  };
  const favoritesSet=read();

  const apply=()=>{
    for(const card of cards){
      const id=String(card.dataset.agentId||'');
      const button=card.querySelector('[data-research-favorite]');
      const favorite=favoritesSet.has(id);
      card.classList.toggle('is-favorite',favorite);
      if(button){
        button.setAttribute('aria-pressed',favorite?'true':'false');
        button.setAttribute('title',favorite?'Remove from favorites':'Add to favorites');
        button.textContent=favorite?'★':'☆';
      }
      const target=favorite?favorites:recent;
      if(target&&card.parentElement!==target)target.appendChild(card);
    }
    if(favoriteSection)favoriteSection.hidden=favoritesSet.size===0;
    const empty=root.querySelector('[data-research-no-agents]');
    if(empty)empty.hidden=cards.length!==0;
  };

  root.addEventListener('click',event=>{
    const button=event.target.closest('[data-research-favorite]');
    if(!button)return;
    const card=button.closest('[data-research-agent-card]');
    const id=String(card?.dataset?.agentId||'');if(!id)return;
    event.preventDefault();event.stopPropagation();
    if(favoritesSet.has(id))favoritesSet.delete(id);else favoritesSet.add(id);
    write(favoritesSet);apply();
  });

  apply();
})();
