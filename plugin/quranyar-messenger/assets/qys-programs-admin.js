/* QuranYar Programs applicant bulk management — Phase E / E9 */
(() => {
  'use strict';

  const form=document.querySelector('form[data-qys-applicant-bulk]');
  if(!form)return;

  const selectAll=document.querySelector('[data-qys-app-select-all]');
  const boxes=Array.from(document.querySelectorAll('[data-qys-applicant-checkbox][form="qys-program-bulk-form"]'));
  const countNode=form.querySelector('[data-qys-selected-count]');
  const action=form.querySelector('select[name="bulk_action"]');
  const confirmed=form.querySelector('input[name="qys_destructive_confirmed"]');
  const maxSelected=Math.max(1,Number.parseInt(form.dataset.qysBulkMax||'100',10)||100);

  const faNumber=value=>String(value).replace(/\d/g,d=>'۰۱۲۳۴۵۶۷۸۹'[Number(d)]);
  const sync=()=>{
    const checked=boxes.filter(box=>box.checked).length;
    if(countNode)countNode.textContent=`${faNumber(checked)} انتخاب`;
    if(selectAll){
      selectAll.checked=boxes.length>0&&checked===boxes.length;
      selectAll.indeterminate=checked>0&&checked<boxes.length;
    }
    if(confirmed)confirmed.value='0';
  };

  if(selectAll){
    selectAll.addEventListener('click',event=>{
      event.preventDefault();
      const shouldCheck=boxes.every(box=>!box.checked);
      boxes.forEach((box,index)=>{box.checked=shouldCheck&&index<maxSelected;});
      sync();
    });
  }
  boxes.forEach(box=>box.addEventListener('change',sync));
  if(action)action.addEventListener('change',()=>{if(confirmed)confirmed.value='0';});

  form.addEventListener('submit',event=>{
    const checked=boxes.filter(box=>box.checked);
    if(!checked.length){
      event.preventDefault();
      window.alert('برای عملیات گروهی حداقل یک متقاضی را انتخاب کنید.');
      return;
    }
    if(checked.length>maxSelected){
      event.preventDefault();
      window.alert(`در هر عملیات گروهی حداکثر ${faNumber(maxSelected)} متقاضی قابل پردازش است.`);
      return;
    }
    if(!action||!action.value){
      event.preventDefault();
      window.alert('ابتدا عملیات گروهی را انتخاب کنید.');
      if(action)action.focus();
      return;
    }
    if(action.value==='rejected' && (!confirmed || confirmed.value!=='1')){
      const message=(form.dataset.qysRejectMessage||'رد درخواست‌های انتخاب‌شده قابل بازگشت نیست. ادامه می‌دهید؟').trim();
      if(!window.confirm(message)){
        event.preventDefault();
        return;
      }
      if(confirmed)confirmed.value='1';
    }
  });

  window.addEventListener('pageshow',()=>{
    if(confirmed)confirmed.value='0';
    sync();
  });
  sync();
})();
