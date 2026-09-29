(()=>{
  const shells=[...document.querySelectorAll('[data-research-agent-shell]')];
  if(!shells.length)return;
  const key='annotated.researchAgent.last';
  const href=(tab,agent,conversation)=>{
    if(tab==='chat')return conversation?'/home.php?agent='+encodeURIComponent(conversation):'/research.php';
    if(tab==='knowledge')return '/research-agent-knowledge.php?agent='+encodeURIComponent(agent);
    if(tab==='research')return '/research-agent-research.php?agent='+encodeURIComponent(agent);
    if(tab==='reports')return '/research-reports.php?agent='+encodeURIComponent(agent);
    return '/research.php';
  };
  shells.forEach(shell=>{
    const selected=String(shell.dataset.agentId||'').trim();
    if(selected)try{localStorage.setItem(key,selected);}catch{}
    shell.querySelectorAll('[data-research-agent-tab]').forEach(link=>link.addEventListener('click',()=>{if(selected)try{localStorage.setItem(key,selected);}catch{}}));
    const picker=shell.querySelector('[data-research-agent-shell-switch]');
    picker?.addEventListener('change',()=>{
      const option=picker.selectedOptions?.[0];const agent=String(picker.value||'').trim();if(!agent)return;
      const conversation=String(option?.dataset.conversation||'').trim();const tab=String(picker.dataset.shellTab||shell.dataset.activeTab||'chat').trim();
      try{localStorage.setItem(key,agent);}catch{}
      location.href=href(tab,agent,conversation);
    });
  });
})();
