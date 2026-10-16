(() => {
 'use strict';

 const DRAFT_PREFIX='qys:program-draft:v1:';
 const DRAFT_TTL_MS=24*60*60*1000;

 const draftStore=(() => {
  const storage=()=>{
   try{
    const s=window.localStorage;
    const probe='__qys_draft_probe__';
    s.setItem(probe,'1');s.removeItem(probe);
    return s;
   }catch(_){return null;}
  };
  const hash=value=>{
   let h=2166136261;
   const s=String(value??'');
   for(let i=0;i<s.length;i++){h^=s.charCodeAt(i);h=Math.imul(h,16777619);}
   return (h>>>0).toString(36);
  };
  const memberScope=()=>{
   const id=window.Eitaa?.WebApp?.initDataUnsafe?.user?.id;
   return id===undefined||id===null||String(id)===''?'':hash(String(id));
  };
  const schemaFingerprint=fields=>hash(JSON.stringify((fields||[]).map(f=>[
   String(f?.key||''),String(f?.type||''),Boolean(f?.required),(f?.options||[]).map(String)
  ])));
  const key=(programId,fields)=>{
   const scope=memberScope();
   const pid=Number(programId||0);
   if(!scope||pid<=0)return '';
   return `${DRAFT_PREFIX}${scope}:${pid}:${schemaFingerprint(fields)}`;
  };
  const read=storageKey=>{
   if(!storageKey)return null;
   const s=storage();if(!s)return null;
   try{
    const raw=s.getItem(storageKey);if(!raw)return null;
    const data=JSON.parse(raw);
    const savedAt=Number(data?.savedAt||0);
    if(!savedAt||Date.now()-savedAt>DRAFT_TTL_MS||!data?.answers||typeof data.answers!=='object'){
     s.removeItem(storageKey);return null;
    }
    return data;
   }catch(_){try{s.removeItem(storageKey);}catch(__){}return null;}
  };
  const remove=storageKey=>{
   if(!storageKey)return false;
   const s=storage();if(!s)return false;
   try{s.removeItem(storageKey);return true;}catch(_){return false;}
  };
  const meaningful=answers=>Object.values(answers||{}).some(v=>Array.isArray(v)?v.length>0:String(v??'').trim()!=='');
  const collect=form=>{
   const answers={};
   if(!form?.elements)return answers;
   for(const el of form.elements){
    if(!el.name||el.type==='submit'||el.type==='button')continue;
    if(el.type==='checkbox'){
     if(!el.checked)continue;
     if(!Array.isArray(answers[el.name]))answers[el.name]=[];
     answers[el.name].push(el.value);
    }else{
     answers[el.name]=el.value;
    }
   }
   return answers;
  };
  const save=form=>{
   const storageKey=form?.dataset?.programDraftKey||'';
   if(!storageKey)return false;
   const s=storage();if(!s)return false;
   const answers=collect(form);
   if(!meaningful(answers)){remove(storageKey);return false;}
   try{
    s.setItem(storageKey,JSON.stringify({version:1,savedAt:Date.now(),answers}));
    return true;
   }catch(_){return false;}
  };
  const restore=form=>{
   const storageKey=form?.dataset?.programDraftKey||'';
   const data=read(storageKey);
   if(!data||!form?.elements)return false;
   const answers=data.answers||{};
   for(const el of form.elements){
    if(!el.name||el.type==='submit'||el.type==='button')continue;
    if(!Object.prototype.hasOwnProperty.call(answers,el.name))continue;
    const value=answers[el.name];
    if(el.type==='checkbox'){
     const values=Array.isArray(value)?value.map(String):[String(value)];
     el.checked=values.includes(String(el.value));
    }else{
     el.value=Array.isArray(value)?String(value[0]??''):String(value??'');
    }
   }
   return true;
  };
  const has=(programId,fields)=>Boolean(read(key(programId,fields)));
  const prune=()=>{
   const s=storage();if(!s)return 0;
   let removed=0;
   try{
    const keys=[];
    for(let i=0;i<s.length;i++){const k=s.key(i);if(k&&k.startsWith(DRAFT_PREFIX))keys.push(k);}
    for(const k of keys){if(!read(k))removed++;}
   }catch(_){}
   return removed;
  };
  return {
   prefix:DRAFT_PREFIX,
   ttlMs:DRAFT_TTL_MS,
   key,has,read,save,restore,remove,collect,prune,
   contract:()=>({storage:'local',scope:'member_program_form',ttlHours:24,restore:'automatic',clear:'successful_submit'})
  };
 })();

 window.QYSProgramDraft=draftStore;

 const root=document.getElementById('qys-app');if(!root||!window.QYSPrograms)return;
 const list=document.getElementById('qys-program-list'),record=document.getElementById('qys-program-record'),feedback=document.getElementById('qys-program-feedback');
 const app=window.Eitaa?.WebApp;let loaded=false,recordLoaded=false,busy=false;
 const draftTimers=new WeakMap();
 const initData=()=>app?.initData||'';
 const esc=s=>String(s??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
 const asyncMarkup=(kind,title,message='',actionLabel='',actionAttr='')=>window.QYSAsyncState?.markup
  ? window.QYSAsyncState.markup(kind,title,message,actionLabel,actionAttr)
  : `<div class="qys-async-state is-${esc(kind)}" role="${kind==='error'?'alert':'status'}"><div class="qys-async-copy"><strong>${esc(title)}</strong>${message?`<small>${esc(message)}</small>`:''}</div>${actionLabel&&actionAttr?`<button type="button" class="qys-async-action" ${actionAttr}>${esc(actionLabel)}</button>`:''}</div>`;

 async function post(url,data={}){
  let r;
  try{r=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'same-origin',body:JSON.stringify({initData:initData(),...data})});}
  catch(_){throw new Error('ارتباط برقرار نشد. اینترنت را بررسی کنید و دوباره تلاش کنید.');}
  const j=await r.json().catch(()=>({}));
  if(!r.ok){
   const e=new Error(j?.message||j?.data?.message||'اطلاعات بارگذاری نشد.');
   e.code=j?.code||j?.data?.code||'';
   e.action=j?.data?.action||j?.action||'';
   e.gate=j?.data?.gate||j?.gate||'';
   e.ctaLabel=j?.data?.cta_label||j?.cta_label||'';
   e.fieldKey=j?.data?.field_key||j?.field_key||'';
   throw e;
  }
  return j;
 }

 function shellGateBlocked(){const gate=document.getElementById('qys-program-access-gate');return Boolean(gate&&!gate.hidden&&gate.dataset.gate!=='ready');}
 function accessGateMarkup(e,retryTarget='list'){const profileAction=e.action==='complete_profile'||e.action==='profile_status';const label=e.ctaLabel||(e.action==='profile_status'?'مشاهده وضعیت پرونده':'تکمیل پرونده');if(profileAction||e.gate)return `<div class="qys-program-error-public qys-program-access-gate"><span class="qys-program-gate-kicker">دسترسی به برنامه‌ها</span><strong>${esc(e.message)}</strong><p>بعد از رفع این مورد، همین‌جا می‌توانید ادامه دهید.</p>${profileAction?`<button type="button" data-qys-go="profile">${esc(label)}</button>`:''}</div>`;return asyncMarkup('error','برنامه‌ها بارگذاری نشد.',e.message||'اتصال اینترنت را بررسی کنید و دوباره تلاش کنید.','تلاش دوباره',`data-qys-program-retry="${esc(retryTarget)}"`);}
 function setFeedback(msg,err=false){if(!feedback)return;feedback.className=err?'qys-program-error-public':'qys-program-notice-public';feedback.textContent=msg||'';feedback.hidden=!msg;}
 function fieldHtml(f,programId){const key=String(f.key||''),label=String(f.label||''),required=Boolean(f.required),req=required?' required':'',help=f.help?`<small>${esc(f.help)}</small>`:'',errId=`qys-program-field-error-${Number(programId||0)}-${esc(key)}`,wrap=(control,type=f.type)=>`<div class="qys-program-field" data-qys-field="${esc(key)}" data-field-type="${esc(type)}" data-field-label="${esc(label)}" data-required="${required?'1':'0'}">${control}<div id="${errId}" class="qys-program-field-error" data-qys-field-error role="alert" hidden></div></div>`;if(f.type==='notice')return `<div class="qys-program-notice-public">${esc(label)}${help}</div>`;if(f.type==='long_text')return wrap(`<label>${esc(label)}<textarea name="${esc(key)}" rows="4" maxlength="5000" aria-describedby="${errId}"${req}></textarea>${help}</label>`);if(f.type==='number')return wrap(`<label>${esc(label)}<input type="text" inputmode="decimal" name="${esc(key)}" aria-describedby="${errId}"${req}>${help}</label>`);if(f.type==='date')return wrap(`<label>${esc(label)}<input type="text" inputmode="numeric" placeholder="مثلاً ۱۴۰۵/۰۷/۱۵" name="${esc(key)}" aria-describedby="${errId}"${req}>${help}</label>`);if(f.type==='yes_no')return wrap(`<label>${esc(label)}<select name="${esc(key)}" aria-describedby="${errId}"${req}><option value="">انتخاب کنید</option><option value="1">بله</option><option value="0">خیر</option></select>${help}</label>`);if(f.type==='single_choice')return wrap(`<label>${esc(label)}<select name="${esc(key)}" aria-describedby="${errId}"${req}><option value="">انتخاب کنید</option>${(f.options||[]).map(o=>`<option value="${esc(o)}">${esc(o)}</option>`).join('')}</select>${help}</label>`);if(f.type==='multi_choice')return wrap(`<fieldset aria-describedby="${errId}"><legend>${esc(label)}${required?' *':''}</legend><div class="qys-program-options">${(f.options||[]).map(o=>`<label><input type="checkbox" name="${esc(key)}" value="${esc(o)}"> ${esc(o)}</label>`).join('')}</div>${help}</fieldset>`);return wrap(`<label>${esc(label)}<input name="${esc(key)}" maxlength="500" aria-describedby="${errId}"${req}>${help}</label>`);}
 function faDigits(value){const fa='۰۱۲۳۴۵۶۷۸۹',ar='٠١٢٣٤٥٦٧٨٩';return String(value??'').replace(/[۰-۹]/g,d=>String(fa.indexOf(d))).replace(/[٠-٩]/g,d=>String(ar.indexOf(d)));}
 function fieldControls(group){return [...(group?.querySelectorAll?.('input[name],textarea[name],select[name]')||[])];}
 function fieldLabel(group){return group?.dataset?.fieldLabel||'این فیلد';}
 function fieldValue(group){const controls=fieldControls(group);if((group?.dataset?.fieldType||'')==='multi_choice')return controls.filter(x=>x.checked).map(x=>x.value);const el=controls[0];return el?String(el.value??''):'';}
 function setFieldError(group,message){if(!group)return false;const error=group.querySelector('[data-qys-field-error]');group.classList.add('is-invalid');for(const el of fieldControls(group))el.setAttribute('aria-invalid','true');if(error){error.textContent=message;error.hidden=false;}return false;}
 function clearFieldError(group){if(!group)return;const error=group.querySelector('[data-qys-field-error]');group.classList.remove('is-invalid');for(const el of fieldControls(group))el.removeAttribute('aria-invalid');if(error){error.textContent='';error.hidden=true;}}
 function validateField(group){if(!group)return true;const type=group.dataset.fieldType||'',required=group.dataset.required==='1',label=fieldLabel(group),value=fieldValue(group);let message='';if(type==='multi_choice'){if(required&&(!Array.isArray(value)||value.length===0))message=`حداقل یک گزینه برای «${label}» انتخاب کنید.`;}else{const text=String(value??'').trim();if(required&&text==='')message=`تکمیل «${label}» الزامی است.`;else if(text!==''){if(type==='short_text'&&[...text].length>500)message=`پاسخ «${label}» بیش از حد طولانی است.`;else if(type==='long_text'&&[...text].length>5000)message=`پاسخ «${label}» بیش از حد طولانی است.`;else if(type==='number'&&!Number.isFinite(Number(faDigits(text))))message=`مقدار «${label}» باید عدد باشد.`;else if(type==='date'&&!/^\d{4}[\/-]\d{1,2}[\/-]\d{1,2}$/.test(faDigits(text)))message=`تاریخ «${label}» معتبر نیست.`;}}
  if(message)return setFieldError(group,message);clearFieldError(group);return true;}
 function focusInvalid(group){const target=fieldControls(group).find(el=>!el.disabled)||group;try{target.focus?.({preventScroll:true});}catch(_){target.focus?.();}group.scrollIntoView?.({behavior:'smooth',block:'center'});}
 function validateForm(form){let first=null;form.querySelectorAll('[data-qys-field]').forEach(group=>{if(!validateField(group)&&!first)first=group;});if(first){focusInvalid(first);return false;}return true;}
 function setServerFieldError(form,key,message){if(!form||!key)return false;const group=[...form.querySelectorAll('[data-qys-field]')].find(el=>el.dataset.qysField===String(key));if(!group)return false;setFieldError(group,message||`پاسخ «${fieldLabel(group)}» معتبر نیست.`);focusInvalid(group);return true;}
 function withdrawButton(programId,canWithdraw,kind=''){if(!canWithdraw)return '';const label=kind==='enrollment'?'انصراف از برنامه':'پس‌گرفتن درخواست';return `<button type="button" class="qys-program-withdraw" data-program-withdraw="${programId}" data-withdraw-kind="${esc(kind)}">${label}</button>`;}
 function reapplyButton(programId,canReapply){if(!canReapply)return '';return `<button type="button" class="qys-program-reapply" data-program-reapply="${programId}">ثبت‌نام مجدد</button>`;}
 function draftStateHtml(hasDraft,enabled){if(!enabled)return '';const state=hasDraft?'پاسخ‌های قبلی شما پیدا شد':'پاسخ‌های ناتمام شما حفظ می‌شود';return `<div class="qys-program-draft-note" role="status" aria-live="polite"><span data-program-draft-state>${state}</span><small>تا ۲۴ ساعت، پاسخ‌های ناتمام شما روی همین دستگاه نگه داشته می‌شود تا از بین نرود.</small></div>`;}
 function card(p){const canReapply=Boolean(p.can_reapply);const activeApplication=!canReapply&&['submitted','under_review','waitlisted','accepted'].includes(String(p.application_status||''));const state=(activeApplication?p.application_status_label:'')||p.enrollment_status_label||p.application_status_label||p.status_label;const canApply=p.registration_open&&!p.application_id;const withdrawn=canReapply||(!activeApplication&&(p.application_status==='withdrawn'||p.enrollment_status==='withdrawn'));const notice=withdrawn?(canReapply?'قبلاً از این برنامه انصراف داده‌اید. در صورت تمایل می‌توانید دوباره درخواست ثبت‌نام بدهید.':'انصراف شما ثبت شده است. ثبت‌نام مجدد در حال حاضر باز نیست.'):(p.application_id?'درخواست شما ثبت شده است.':'ثبت‌نام این برنامه در حال حاضر باز نیست.');const fields=p.fields||[];const draftKey=canApply?draftStore.key(p.id,fields):'';const hasDraft=canApply&&draftKey?Boolean(draftStore.read(draftKey)):false;const openLabel=hasDraft?'ادامه پیش‌ثبت‌نام':'پیش‌ثبت‌نام';return `<article class="qys-program-member-card" data-program="${p.id}"><div class="qys-program-member-meta"><span>${esc(p.type_label)}</span><span>${esc(state)}</span>${p.capacity?`<span>ظرفیت ${p.capacity}</span>`:''}</div><h3>${esc(p.title)}</h3><p>${esc(p.description||'')}</p>${canApply?`<button type="button" class="qys-program-open" data-program-open="${p.id}">${openLabel}</button>`:`<div class="qys-program-notice-public">${notice}</div>`}${reapplyButton(p.id,canReapply)}${withdrawButton(p.id,p.can_withdraw,p.withdraw_kind)}<form class="qys-program-application-form" data-program-form="${p.id}" data-program-draft-key="${esc(draftKey)}" novalidate hidden>${draftStateHtml(hasDraft,Boolean(draftKey))}${fields.map(f=>fieldHtml(f,p.id)).join('')}<button type="submit">ثبت پیش‌ثبت‌نام</button><button type="button" class="qys-form-back" data-program-cancel="${p.id}">بازگشت</button></form></article>`;}
 function draftState(form,text){const el=form?.querySelector?.('[data-program-draft-state]');if(el)el.textContent=text;}
 function persistDraft(form,immediate=false){if(!form?.dataset?.programDraftKey)return;const current=draftTimers.get(form);if(current)clearTimeout(current);const run=()=>{draftTimers.delete(form);const saved=draftStore.save(form);draftState(form,saved?'پاسخ‌های شما موقتاً ذخیره شد':'هنوز پاسخی وارد نشده است');};if(immediate)run();else draftTimers.set(form,setTimeout(run,220));}
 function restoreDraft(form){if(!form?.dataset?.programDraftKey)return false;const restored=draftStore.restore(form);if(restored)draftState(form,'پاسخ‌های قبلی شما بازگردانده شد');return restored;}
 function clearDraft(form){const current=draftTimers.get(form);if(current){clearTimeout(current);draftTimers.delete(form);}const key=form?.dataset?.programDraftKey||'';if(key)draftStore.remove(key);}
 function flushDrafts(){root.querySelectorAll?.('[data-program-form]').forEach(form=>persistDraft(form,true));}
 async function load(){if(busy||shellGateBlocked())return;flushDrafts();busy=true;try{list.innerHTML=asyncMarkup('loading','در حال آماده‌کردن برنامه‌ها...','لطفاً چند لحظه صبر کنید.');const j=await post(QYSPrograms.listEndpoint);draftStore.prune();list.innerHTML=(j.programs||[]).length?(j.programs||[]).map(card).join(''):asyncMarkup('empty','فعلاً برنامه‌ای برای ثبت‌نام یا پیگیری شما وجود ندارد.','هر زمان برنامه مناسبی فعال شود، در همین بخش نمایش داده می‌شود.');loaded=true;}catch(e){loaded=false;list.innerHTML=accessGateMarkup(e,'list');}finally{busy=false;}}
 function statusCard(row){const att=row.attendance||{};const mins=Number(att.minutes||0),h=Math.floor(mins/60),m=mins%60;const duration=mins?`${h?h+' ساعت ':''}${m?m+' دقیقه':''}`:'مدت ثبت نشده';return `<div class="qys-program-record-card"><div><strong>${esc(row.title)}</strong><br><span>${esc(row.type_label||'برنامه')} · ${esc(row.status_label||row.status)}</span></div><div class="qys-program-record-actions"><span>${att.sessions||0} جلسه · ${duration}</span>${reapplyButton(row.program_id,row.can_reapply)}${withdrawButton(row.program_id,row.can_withdraw,row.withdraw_kind)}</div></div>`;}
 async function loadRecord(){if(recordLoaded||shellGateBlocked())return;record.innerHTML=asyncMarkup('loading','در حال آماده‌کردن سوابق...','لطفاً چند لحظه صبر کنید.');try{const j=await post(QYSPrograms.recordEndpoint);const r=j.record||{};let html='';const groups=[['درخواست‌های من',r.applications||[]],['برنامه‌های جاری',r.current||[]],['سوابق فعالیت',r.history||[]],['پایان‌یافته بدون حضور',r.closed_without_activity||[]]];for(const [title,rows] of groups){html+=`<section class="qys-program-record-section"><h3>${title}</h3>${rows.length?rows.map(statusCard).join(''):'<div class="qys-program-member-empty">موردی وجود ندارد.</div>'}</section>`;}record.innerHTML=html;recordLoaded=true;}catch(e){recordLoaded=false;record.innerHTML=accessGateMarkup(e,'record');}}
 root.addEventListener('input',e=>{const form=e.target.closest?.('[data-program-form]');if(form){const group=e.target.closest?.('[data-qys-field]');if(group&&group.classList.contains('is-invalid'))validateField(group);persistDraft(form);}});
 root.addEventListener('change',e=>{const form=e.target.closest?.('[data-program-form]');if(form){const group=e.target.closest?.('[data-qys-field]');if(group)validateField(group);persistDraft(form);}});
 root.addEventListener('click',async e=>{const retry=e.target.closest('[data-qys-program-retry]');if(retry){e.preventDefault();const target=retry.dataset.qysProgramRetry||'list';if(target==='record'){recordLoaded=false;await loadRecord();}else{loaded=false;await load();}return;}const reapply=e.target.closest('[data-program-reapply]');if(reapply){if(busy)return;const msg='درخواست قبلی شما دوباره برای بررسی ارسال می‌شود و پذیرش یا ثبت‌نام قطعی قبلی خودکار برنمی‌گردد. ادامه می‌دهید؟';if(!window.confirm(msg))return;busy=true;setFeedback('در حال ثبت درخواست مجدد...');try{const j=await post(QYSPrograms.reapplyEndpoint,{program_id:Number(reapply.dataset.programReapply),confirmed:'1'});setFeedback(j.message||'درخواست ثبت‌نام مجدد ثبت شد.');loaded=false;recordLoaded=false;busy=false;await load();if(!record.hidden)await loadRecord();}catch(err){setFeedback(err.message,true);}finally{busy=false;}return;}const withdraw=e.target.closest('[data-program-withdraw]');if(withdraw){if(busy)return;const kind=withdraw.dataset.withdrawKind||'application';const msg=kind==='enrollment'?'با انصراف از برنامه، جایگاه فعال شما پایان می‌یابد اما سوابق قبلی حفظ می‌شود. ادامه می‌دهید؟':'با پس‌گرفتن درخواست، درخواست شما به وضعیت انصراف می‌رود و سوابق آن حفظ می‌شود. ادامه می‌دهید؟';if(!window.confirm(msg))return;busy=true;setFeedback('در حال ثبت انصراف...');try{const j=await post(QYSPrograms.withdrawEndpoint,{program_id:Number(withdraw.dataset.programWithdraw),confirmed:'1'});setFeedback(j.message||'انصراف ثبت شد.');loaded=false;recordLoaded=false;busy=false;await load();if(!record.hidden)await loadRecord();}catch(err){setFeedback(err.message,true);}finally{busy=false;}return;}const open=e.target.closest('[data-program-open]');if(open){const id=open.dataset.programOpen;const form=root.querySelector(`[data-program-form="${id}"]`);if(form){restoreDraft(form);form.hidden=false;open.hidden=true;form.scrollIntoView?.({behavior:'smooth',block:'nearest'});}return;}const cancel=e.target.closest('[data-program-cancel]');if(cancel){const id=cancel.dataset.programCancel;const form=root.querySelector(`[data-program-form="${id}"]`),btn=root.querySelector(`[data-program-open="${id}"]`);if(form){persistDraft(form,true);form.hidden=true;}if(btn){btn.hidden=false;if(form?.dataset?.programDraftKey&&draftStore.read(form.dataset.programDraftKey))btn.textContent='ادامه پیش‌ثبت‌نام';}return;}const tab=e.target.closest('[data-qys-program-tab]');if(tab){root.querySelectorAll('[data-qys-program-tab]').forEach(x=>x.classList.toggle('is-active',x===tab));const which=tab.dataset.qysProgramTab;list.hidden=which!=='available';record.hidden=which!=='record';if(which==='record')loadRecord();}});
 root.addEventListener('submit',async e=>{const form=e.target.closest('[data-program-form]');if(!form)return;e.preventDefault();if(busy)return;persistDraft(form,true);if(!validateForm(form)){setFeedback('لطفاً خطاهای مشخص‌شده در فرم را اصلاح کنید.',true);return;}const answers=draftStore.collect(form);busy=true;setFeedback('در حال ثبت درخواست...');try{const j=await post(QYSPrograms.applyEndpoint,{program_id:Number(form.dataset.programForm),answers});clearDraft(form);setFeedback(j.message||'درخواست ثبت شد.');loaded=false;recordLoaded=false;await load();}catch(err){persistDraft(form,true);if(err.fieldKey)setServerFieldError(form,err.fieldKey,err.message);setFeedback(err.message,true);}finally{busy=false;}});
 window.addEventListener('qys:viewchange',e=>{if(e.detail?.view!=='programs')flushDrafts();if(e.detail?.view==='programs'&&!e.detail?.gate?.blocked&&!loaded)load();});
 window.addEventListener('qys:retry',()=>{loaded=false;recordLoaded=false;if(root.dataset.qysView==='programs'&&!shellGateBlocked())load();});
 window.addEventListener('pagehide',flushDrafts);
 document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='hidden')flushDrafts();});
 draftStore.prune();
 if(root.dataset.qysView==='programs')load();
})();
