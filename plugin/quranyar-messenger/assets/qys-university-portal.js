/* QuranYar university portal experience. Presentation/accessibility only. */
(() => {
  'use strict';
  const root=document.querySelector('.qys-tenant-portal');
  if(!root)return;
  const state=document.getElementById('qys-tenant-system-state');
  const stateText=document.getElementById('qys-tenant-system-text');
  const retry=document.getElementById('qys-tenant-system-retry');
  const forms=Array.from(root.querySelectorAll('form'));

  function showState(kind,message=''){
    if(!state)return;
    state.className=`qys-tenant-system-state is-${kind}`;
    state.hidden=kind==='ready';
    if(stateText)stateText.textContent=message;
    if(retry)retry.hidden=kind!=='offline';
  }
  function syncNetwork(){
    if(navigator.onLine===false)showState('offline','اینترنت قطع است؛ اطلاعات جدید تا زمان اتصال ارسال یا دریافت نمی‌شود.');
    else showState('ready','');
  }
  window.addEventListener('offline',syncNetwork);
  window.addEventListener('online',syncNetwork);
  retry?.addEventListener('click',()=>{ syncNetwork(); if(navigator.onLine!==false) location.reload(); });
  syncNetwork();

  function resetForm(form){
    form.removeAttribute('aria-busy');
    form.dataset.qysSubmitting='';
    form.querySelectorAll('[data-qys-original-label]').forEach(button=>{
      button.removeAttribute('aria-disabled');
      button.classList.remove('qys-is-submitting');
      button.textContent=button.dataset.qysOriginalLabel||button.textContent;
      delete button.dataset.qysOriginalLabel;
    });
  }
  forms.forEach(form=>{
    form.addEventListener('submit',event=>{
      if(form.dataset.qysSubmitting==='1'){
        event.preventDefault();
        return;
      }
      if(typeof form.checkValidity==='function'&&!form.checkValidity())return;
      form.dataset.qysSubmitting='1';
      form.setAttribute('aria-busy','true');
      const submitter=event.submitter || form.querySelector('button[type="submit"],input[type="submit"]');
      if(submitter && submitter.tagName==='BUTTON'){
        submitter.dataset.qysOriginalLabel=submitter.textContent;
        submitter.textContent='در حال پردازش…';
        submitter.setAttribute('aria-disabled','true');
        submitter.classList.add('qys-is-submitting');
      }
    });
  });
  window.addEventListener('pageshow',()=>forms.forEach(resetForm));

  const current=root.querySelector('.qys-tenant-bottomnav [aria-current="page"],.qys-tenant-topnav [aria-current="page"]');
  if(current && typeof current.scrollIntoView==='function'){
    try{current.scrollIntoView({inline:'center',block:'nearest',behavior:'auto'});}catch(_){current.scrollIntoView();}
  }

  root.querySelectorAll('.qys-tenant-state').forEach(el=>{
    if(!el.hasAttribute('role'))el.setAttribute('role','status');
  });
})();
