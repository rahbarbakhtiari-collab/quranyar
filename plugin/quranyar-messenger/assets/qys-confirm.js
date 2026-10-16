/* QuranYar explicit confirmation guard for irreversible admin actions. */
(() => {
  'use strict';
  const forms=Array.from(document.querySelectorAll('form[data-qys-confirm]'));
  if(!forms.length)return;

  const reset=form=>{
    const field=form.querySelector('input[name="qys_destructive_confirmed"]');
    if(field)field.value='0';
  };

  forms.forEach(form=>{
    reset(form);
    form.addEventListener('submit',event=>{
      const message=(form.dataset.qysConfirm||'').trim();
      if(!message)return;
      const field=form.querySelector('input[name="qys_destructive_confirmed"]');
      if(field&&field.value==='1')return;
      if(!window.confirm(message)){
        event.preventDefault();
        if(typeof event.stopImmediatePropagation==='function')event.stopImmediatePropagation();
        return;
      }
      if(field)field.value='1';
    },{capture:true});
  });

  window.addEventListener('pageshow',()=>forms.forEach(reset));
})();
