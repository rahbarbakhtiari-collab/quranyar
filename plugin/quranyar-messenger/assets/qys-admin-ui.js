/* QuranYar admin progressive enhancement. All existing WordPress forms continue working without JS. */
(() => {
  'use strict';
  const root = document.querySelector('.qys-admin');
  if (!root) return;
  const el = (tag, cls, text) => {
    const node=document.createElement(tag);if(cls)node.className=cls;if(text!==undefined)node.textContent=text;return node;
  };
  const button = (text,click,primary=false) => {
    const node=el('button',primary?'button button-primary':'button',text);
    node.type='button';node.addEventListener('click',click);return node;
  };
  function enhanceTables() {
    root.querySelectorAll('table.widefat').forEach(table => {
      const parent=table.parentElement;
      if (!parent) return;
      if(parent.classList.contains('qys-table-scroll'))return;
      if(parent.style.overflowX==='auto'||parent.style.overflow==='auto')parent.classList.add('qys-table-scroll');
      else {const wrap=el('div','qys-table-scroll');parent.insertBefore(wrap,table);wrap.append(table);}
    });
  }

  function mainDashboard() {
    // RC6 central administration is routed and grouped on the server.  Do not
    // infer sections from translated heading text in the browser.
    return;
  }


  function targetedWizard(){
    if(!root.classList.contains('qys-admin--targeted'))return;
    const form=document.getElementById('qys-targeted-create');
    if(!form)return;
    const title=form.querySelector('input[name=label]')?.closest('p');
    const audience=form.querySelector('#qys-target-audience')?.closest('p');
    const groups=document.getElementById('qys-target-groups');
    const chooser=document.getElementById('qys-target-chooser');
    const template=form.querySelector('textarea[name=template]')?.closest('p');
    const mode=form.querySelector('input[name=mode]')?.closest('fieldset');
    const schedule=document.getElementById('qys-target-schedule');
    const send=form.querySelector('button[type=submit]')?.closest('p');
    if([title,audience,groups,chooser,template,mode,schedule,send].some(node=>!node))return;
    const names=['گیرندگان','متن پیام','زمان ارسال','بازبینی و ثبت'];
    const header=el('div','qys-wizard-header');header.setAttribute('aria-label','مراحل ارسال هدفمند');
    names.forEach((name,i)=>{const item=el('span','qys-wizard-tab');item.append(el('strong','',String(i+1)),document.createTextNode(name));header.append(item);});
    const panes=names.map((name,i)=>{
      const pane=el('section','qys-wizard-panel');pane.id=`qys-wizard-step-${i}`;
      pane.append(el('h3','',name));return pane;
    });
    [title,audience,groups,chooser].forEach(node=>panes[0].append(node));
    panes[1].append(template);
    [mode,schedule].forEach(node=>panes[2].append(node));
    const summary=el('div','qys-wizard-review');summary.setAttribute('aria-live','polite');panes[3].append(summary);
    const caution=el('p','qys-final-confirm','در این مرحله فقط پیش‌نویس ساخته می‌شود. پیام تنها پس از بازبینی و انتخاب «تأیید و فعال‌سازی» از جدول پایین ارسال یا زمان‌بندی خواهد شد.');
    panes[3].append(caution,send);
    const controls=panes.map((pane,i)=>{
      const control=el('div','qys-wizard-controls');
      if(i>0)control.append(button('مرحله قبل',()=>show(i-1)));
      if(i<3)control.append(button('ادامه',()=>{if(validateStep(i))show(i+1);},true));
      pane.append(control);return control;
    });
    form.append(header,...panes);
    let current=0;
    const getAudience=()=>document.getElementById('qys-target-audience');
    const error=(msg,field)=>{window.alert(msg);field?.focus();return false;};
    function validateStep(step){
      if(step===0){
        const titleField=form.querySelector('input[name=label]');
        if(!titleField.checkValidity()){titleField.reportValidity();return false;}
        if(getAudience().value==='groups'&&!form.querySelector('input[name="role_groups[]"]:checked'))return error('حداقل یک گروه را انتخاب کنید.',groups);
        if(getAudience().value==='selected'&&!form.querySelector('input[name="selected_ids[]"]'))return error('حداقل یک مخاطب را انتخاب کنید.',document.getElementById('qys-target-search'));
      }
      if(step===1){const text=form.querySelector('textarea[name=template]');if(!text.checkValidity()){text.reportValidity();return false;}}
      if(step===2){
        const isScheduled=form.querySelector('input[name=mode]:checked')?.value==='scheduled';
        const date=document.getElementById('qys-target-day');date.required=isScheduled;
        if(isScheduled&&!date.checkValidity()){date.reportValidity();return false;}
      }
      return true;
    }
    function renderReview(){
      summary.replaceChildren();
      const audienceField=getAudience();
      const selectedAudience=audienceField.options[audienceField.selectedIndex]?.textContent||'نامشخص';
      let audienceText=selectedAudience;
      if(audienceField.value==='groups'){
        const selected=Array.from(form.querySelectorAll('input[name="role_groups[]"]:checked')).map(input=>input.closest('label')?.textContent.trim()||input.value);
        audienceText=selected.join('، ');
      } else if(audienceField.value==='selected') {
        audienceText=`${form.querySelectorAll('input[name="selected_ids[]"]').length} مخاطب انتخاب‌شده`;
      }
      const timing=form.querySelector('input[name=mode]:checked')?.value==='scheduled'
        ?`${document.getElementById('qys-target-day').value} - ${document.getElementById('qys-target-hour').value}:${document.getElementById('qys-target-minute').value} (وقت ایران)`
        :'فوری، پس از تأیید دوم';
      const fields=[['عنوان داخلی',form.querySelector('input[name=label]').value],['مخاطبان',audienceText],['زمان',timing]];
      fields.forEach(([label,value])=>{
        const row=el('div');row.append(el('strong','',`${label}: `),document.createTextNode(value));summary.append(row);
      });
      summary.append(el('strong','', 'متن پیام:'));
      summary.append(el('div','qys-message-preview',form.querySelector('textarea[name=template]').value));
      summary.append(el('small','', 'تعداد واقعی گیرندگان یکتا پس از ساخت پیش‌نویس محاسبه می‌شود؛ قبل از فعال‌سازی می‌توانید متن شخصی هر نفر را بررسی کنید.'));
    }
    function show(index,focus=true){
      current=index;
      panes.forEach((pane,i)=>{pane.hidden=i!==index;});
      Array.from(header.children).forEach((item,i)=>{
        if(i===index)item.setAttribute('aria-current','step');else item.removeAttribute('aria-current');
      });
      if(index===3)renderReview();
      if(focus){panes[index].querySelector('h3').tabIndex=-1;panes[index].querySelector('h3').focus({preventScroll:true});form.scrollIntoView?.({behavior:'smooth',block:'start'});}
    }
    form.addEventListener('invalid',event=>{
      const index=panes.findIndex(p=>p.contains(event.target));
      if(index!==-1&&index!==current)show(index,false);
    },true);
    form.addEventListener('submit',event=>{
      for(let i=0;i<3;i++)if(!validateStep(i)){event.preventDefault();show(i,false);return;}
      if(current!==3){event.preventDefault();show(3);}
    });
    show(0,false);
  }
  mainDashboard();targetedWizard();enhanceTables();
})();
