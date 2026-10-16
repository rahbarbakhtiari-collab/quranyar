(()=>{'use strict';
 const app=window.Eitaa?.WebApp;
 const el=id=>document.getElementById(id);
 const root=el('qys-app'),inapp=el('qys-inapp'),notice=el('qys-browser-notice');
 if(!app||!app.initData){notice.hidden=false;return;}
 inapp.hidden=false;
 const summary=el('qys-member-status'),feedback=el('qys-feedback');
 const manage=el('qys-manage'),initial=el('qys-initial'),wrap=el('qys-profile-wrap');
 const form=el('qys-enroll'),save=el('qys-save'),permissionStatus=el('qys-permission-status');
 const roleField=el('qys-role'),universityField=el('qys-university'),familyList=el('qys-family-list'),familyAdd=el('qys-family-add'),familyRequired=el('qys-family-required');
 const mobileField=el('qys-mobile'),otpSend=el('qys-mobile-otp-send'),otpVerify=el('qys-mobile-otp-verify'),otpCode=el('qys-mobile-otp-code'),otpConfirm=el('qys-mobile-otp-confirm'),otpStatus=el('qys-mobile-otp-status'),otpHelp=el('qys-mobile-otp-help');
 const reactivate=el('qys-reactivate'),disable=el('qys-disable');
 const edit=el('qys-edit-profile'),cancel=el('qys-cancel-edit');
 const birthYear=el('qys-birth-year'),birthMonth=el('qys-birth-month'),birthDay=el('qys-birth-day');
 const leapYears=new Set(QYS.jalaliLeapYears||[]);
 const normalizeDigits=s=>String(s||'').replace(/[۰-۹]/g,c=>String(c.charCodeAt(0)-1776)).replace(/[٠-٩]/g,c=>String(c.charCodeAt(0)-1632));
 // All three fields are independent: the user may choose day before month or year.
 // Keep the chosen day visible even when a later month/year change makes it invalid.
 const birthHint=el('qys-birth-hint');
 function refreshBirthDays(selected){
  if(selected!==undefined)birthDay.value=String(selected);
  const mo=Number(birthMonth.value),day=Number(birthDay.value),year=Number(birthYear.value);
  const fullYear=/^[0-9]{4}$/.test(birthYear.value)&&year>=1300&&year<=1499;
  const max=mo===0?31:(mo<=6?31:mo<=11?30:(fullYear&&!leapYears.has(year)?29:30));
  // Other invalid options can be disabled, but the user's selection is never silently cleared.
  for(const option of birthDay.options){
   if(option.value)option.disabled=mo!==0&&Number(option.value)>max&&option.value!==birthDay.value;
  }
  const invalid=mo!==0&&day!==0&&day>max;
  const warning=invalid?(mo===12?'این سال، اسفند ۳۰ روز ندارد؛ روز، ماه یا سال را اصلاح کنید.':'روز انتخابی با ماه مطابقت ندارد؛ روز یا ماه را اصلاح کنید.'):
   (mo===12&&day===30&&!fullYear?'در صورت انتخاب ۳۰ اسفند، سال را وارد کنید تا کبیسه بودن بررسی شود.':'');
  birthDay.setCustomValidity(invalid?warning:'');
  birthHint.textContent=warning;
  return !invalid;
 }
 birthMonth.addEventListener('change',()=>refreshBirthDays());
 birthDay.addEventListener('change',()=>refreshBirthDays());
 birthYear.addEventListener('input',()=>{birthYear.value=normalizeDigits(birthYear.value).replace(/\D/g,'').slice(0,4);refreshBirthDays();});
 refreshBirthDays();
 let granted=false,busy=false,registered=false,latestEnabled=false,latestProfile=null,verifiedMobile='';
 app.ready();app.expand?.();
 async function post(endpoint,fields={}){
  let response;try{response=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json'},
   body:JSON.stringify({...fields,initData:app.initData}),credentials:'omit',cache:'no-store'});}catch(_){throw Error('ارتباط برقرار نشد. اینترنت را بررسی کنید و دوباره تلاش کنید.');}
  let data;try{data=await response.json();}catch(e){throw Error('ارتباط با قرآن‌یار کامل نشد. دوباره تلاش کنید.');}
  if(!response.ok){const err=Error(data.message||'این کار انجام نشد. دوباره تلاش کنید.');err.payload=data;throw err;}return data;
 }
 function setBusy(value){
  busy=value;
  for(const b of [el('qys-permission'),save,reactivate,disable,edit,cancel,familyAdd,otpSend,otpVerify]){
   if(b)b.disabled=value;
  }
  familyList?.querySelectorAll('button,input,select').forEach(node=>node.disabled=value);
 }

 function normalizeMobile(value){
  let d=normalizeDigits(value).replace(/\D/g,'');
  if(/^98[0-9]{10}$/.test(d))d='0'+d.slice(2);
  else if(/^9[0-9]{9}$/.test(d))d='0'+d;
  return /^09[0-9]{9}$/.test(d)?d:'';
 }
 function setOtpState(kind,message){
  if(!otpStatus)return;
  otpStatus.classList.remove('is-good','is-warn');
  if(kind==='verified')otpStatus.classList.add('is-good');
  else if(kind==='legacy'||kind==='required')otpStatus.classList.add('is-warn');
  otpStatus.textContent=kind==='verified'?'تأیید شده':kind==='legacy'?'شماره فعلی':'تأیید نشده';
  if(message&&otpHelp)otpHelp.textContent=message;
 }
 function syncOtpFromProfile(profile){
  const p=profile||{},mobile=normalizeMobile(p.mobile||'');
  verifiedMobile='';
  if(mobile&&p.mobile_verified===true){verifiedMobile=mobile;setOtpState('verified','شماره موبایل شما تأیید شده است.');}
  else if(mobile&&(p.mobile_otp_state==='legacy'||p.mobile_verification_required===false)){verifiedMobile=mobile;setOtpState('legacy','این شماره از قبل در پرونده شماست؛ برای تغییر آن باید کد تأیید پیامکی وارد کنید.');}
  else setOtpState('required','برای ثبت این شماره، کد یک‌بارمصرف پیامکی را تأیید کنید.');
  if(otpConfirm)otpConfirm.hidden=true;if(otpCode)otpCode.value='';
 }
 function mobileNeedsOtp(){const mobile=normalizeMobile(mobileField?.value||'');return !mobile||mobile!==verifiedMobile;}
 mobileField?.addEventListener('input',()=>{
  const mobile=normalizeMobile(mobileField.value);
  if(mobile&&mobile===verifiedMobile)setOtpState(latestProfile?.mobile_verified?'verified':'legacy');
  else setOtpState('required','شماره جدید باید پیش از ذخیره پرونده با کد پیامکی تأیید شود.');
  if(otpConfirm)otpConfirm.hidden=true;if(otpCode)otpCode.value='';
 });
 otpSend?.addEventListener('click',async()=>{
  if(busy)return;const mobile=normalizeMobile(mobileField?.value||'');
  if(!mobile){feedback.textContent='ابتدا شماره موبایل معتبر به صورت ۰۹xxxxxxxxx وارد کنید.';mobileField?.focus();return;}
  setBusy(true);feedback.textContent='در حال ارسال کد تأیید...';
  try{const r=await post(QYS.mobileOtpSendEndpoint,{mobile});if(otpConfirm)otpConfirm.hidden=false;if(otpCode){otpCode.value='';otpCode.focus();}otpHelp.textContent=r.message||'کد ارسال شد.';feedback.textContent='کد تأیید را وارد کنید.';}
  catch(e){feedback.textContent=e.message;}
  finally{setBusy(false);}
 });
 otpVerify?.addEventListener('click',async()=>{
  if(busy)return;const mobile=normalizeMobile(mobileField?.value||'');const code=normalizeDigits(otpCode?.value||'').replace(/\D/g,'').slice(0,6);
  if(!mobile||!/^[0-9]{6}$/.test(code)){feedback.textContent='کد ۶ رقمی را کامل وارد کنید.';otpCode?.focus();return;}
  setBusy(true);feedback.textContent='در حال بررسی کد...';
  try{const r=await post(QYS.mobileOtpVerifyEndpoint,{mobile,otp:code});verifiedMobile=mobile;setOtpState('verified','مالکیت این شماره تأیید شد.');if(otpConfirm)otpConfirm.hidden=true;feedback.textContent=r.message||'شماره تأیید شد.';}
  catch(e){feedback.textContent=e.message;otpCode?.focus();}
  finally{setBusy(false);}
 });
 function familyRow(item={}){
  const row=document.createElement('div');row.className='qys-family-row';
  const relation=String(item.relation_type||'');
  const staffId=Number(item.staff_member_id||0);
  const staffName=String(item.staff_name||'').trim();
  row.innerHTML='<label>نسبت شما با این همکار<select data-family-relation required><option value="">انتخاب کنید</option><option value="spouse">همسر</option><option value="child">فرزند</option></select></label>'
   +(staffId>0?'<div class="qys-family-existing"><span>همکار مرتبط</span><strong></strong><small>برای انتخاب فرد دیگری، این مورد را حذف و دوباره اضافه کنید.</small><input type="hidden" data-family-staff-id></div>':'<label>کد ملی همکار مرتبط<input type="text" inputmode="numeric" maxlength="10" data-family-national-id placeholder="۱۰ رقم" required><small>کد ملی همکار را وارد کنید. او باید در همین دانشگاه پرونده تأییدشده داشته باشد.</small></label>')
   +'<button type="button" class="qys-family-remove" aria-label="حذف این نسبت">حذف</button>';
  const select=row.querySelector('[data-family-relation]');select.value=relation;
  if(staffId>0){row.querySelector('[data-family-staff-id]').value=String(staffId);row.querySelector('.qys-family-existing strong').textContent=staffName||'همکار ثبت‌شده';}
  const nid=row.querySelector('[data-family-national-id]');if(nid)nid.addEventListener('input',()=>{nid.value=normalizeDigits(nid.value).replace(/\D/g,'').slice(0,10);});
  row.querySelector('.qys-family-remove').addEventListener('click',()=>{row.remove();syncFamilyRequirement();});
  return row;
 }
 function syncFamilyRequirement(){
  const required=roleField?.value==='staff_family';
  if(familyRequired){familyRequired.textContent=required?'الزامی':'اختیاری';familyRequired.classList.toggle('qys-pill--warning',required);}
  familyList?.setAttribute('data-required',required?'1':'0');
 }
 function fillFamily(profile){
  if(!familyList)return;familyList.innerHTML='';
  for(const item of (profile?.family_relations||[]))familyList.appendChild(familyRow(item));
  syncFamilyRequirement();
 }
 function collectFamilyRelations(){
  if(!familyList)return [];
  return [...familyList.querySelectorAll('.qys-family-row')].map(row=>{
   const relation_type=row.querySelector('[data-family-relation]')?.value||'';
   const staff_member_id=Number(row.querySelector('[data-family-staff-id]')?.value||0);
   const national_id=normalizeDigits(row.querySelector('[data-family-national-id]')?.value||'').replace(/\D/g,'').slice(0,10);
   return staff_member_id>0?{relation_type,staff_member_id}:{relation_type,national_id};
  });
 }
 familyAdd?.addEventListener('click',()=>{if(familyList.children.length>=4){feedback.textContent='حداکثر چهار نسبت خانوادگی می‌توانید ثبت کنید.';return;}familyList.appendChild(familyRow());syncFamilyRequirement();});
 roleField?.addEventListener('change',syncFamilyRequirement);
 universityField?.addEventListener('change',()=>{
  const scope=String(universityField.value||'');
  const scoped=latestProfile?.family_relations_by_scope?.[scope];
  if(Array.isArray(scoped))fillFamily({family_relations:scoped});
  else if(familyList){familyList.innerHTML='';if(latestProfile)feedback.textContent='اگر با یکی از همکاران این دانشگاه نسبت خانوادگی دارید، آن را ثبت کنید.';}
  syncFamilyRequirement();
 });

 function fill(profile){
  const p=profile||{};
  for(const key of ['university','member_role','first_name','last_name','national_id','mobile']){
   const field=form.elements.namedItem(key);
   if(field){const value=key==='university'?(p.pending_university||p.university||(field.options[0]?.value||'')):(p[key]||'');field.value=value;}
  }
  syncOtpFromProfile(p);
  const match=/^([0-9]{4})\/([0-9]{2})\/([0-9]{2})$/.exec(p.birth_jalali||'');
  birthYear.value=match?match[1]:'';
  birthMonth.value=match?String(Number(match[2])):'';
  refreshBirthDays(match?String(Number(match[3])):'');
  form.elements.namedItem('birthday_opt_in').checked=p.birthday_opt_in===true;
  fillFamily(p);
 }
 function render(s){
  registered=!!s.registered;
  initial.hidden=registered;manage.hidden=!registered;
  if(!registered){
   latestEnabled=false;
   summary.textContent=granted?'دریافت پیام فعال شد. حالا پرونده خود را تکمیل کنید.':'برای تشکیل پرونده، ابتدا مجوز دریافت پیام قرآن‌یار را فعال کنید.';
   wrap.hidden=!granted;cancel.hidden=true;el('qys-form-title').textContent='تشکیل پرونده قرآنی';
   if(!latestProfile){fillFamily({});syncOtpFromProfile({});}
   save.textContent='ثبت و تشکیل پرونده';save.disabled=busy||!granted;
   window.dispatchEvent(new CustomEvent('qys:status',{detail:{registered:false,enabled:false,messaging_consent:false,verified:false,profile_complete:false,profile_progress:null,profile:null}}));return;
  }
  latestEnabled=!!s.enabled;latestProfile=s.profile||{};
  wrap.hidden=true;
  fill(latestProfile);
  const completeness=s.profile_complete?'پرونده شما کامل است.':'پرونده شما نیازمند تکمیل اطلاعات است.';
  const enabled=s.enabled?'دریافت پیام فعال است.':'دریافت پیام غیرفعال است.';
  const transfer=latestProfile.pending_university?(' درخواست انتقال به «'+(QYS.universityLabels?.[latestProfile.pending_university]||latestProfile.pending_university)+'» در انتظار تأیید دانشگاه مقصد است.') : '';
  summary.textContent=completeness+' '+enabled+transfer;
  el('qys-identity-status').textContent=s.verified?'هویت شما تأیید شده است.':'پرونده شما در انتظار تأیید دانشگاه است.';
  reactivate.textContent='فعال‌سازی مجدد دریافت پیام';
  reactivate.hidden=!!s.enabled;
  disable.hidden=true;
  edit.disabled=busy||!s.enabled;
  save.disabled=busy||!s.enabled;
  if(!s.enabled)summary.textContent='مجوز دریافت پیام غیرفعال است؛ برای ادامه استفاده و تکمیل پرونده ابتدا آن را دوباره فعال کنید.'+transfer;
  window.dispatchEvent(new CustomEvent('qys:status',{detail:{registered:true,enabled:!!s.enabled,messaging_consent:!!s.messaging_consent,verified:!!s.verified,profile_complete:!!s.profile_complete,profile_progress:s.profile_progress||null,profile:latestProfile}}));
 }
 async function refresh(){
  summary.textContent='در حال بررسی پرونده...';
  window.dispatchEvent(new CustomEvent('qys:statusloading'));
  try{render(await post(QYS.statusEndpoint));}
  catch(e){summary.textContent=e.message;feedback.textContent='اگر مشکل ادامه داشت، قرآن‌یار را ببندید و دوباره باز کنید.';window.dispatchEvent(new CustomEvent('qys:statuserror',{detail:{message:e.message}}));}
 }
 function requestPermission(onAllow){
  if(typeof app.requestWriteAccess!=='function'){
   feedback.textContent='در این نسخه ایتا امکان فعال‌کردن دریافت پیام نیست. لطفاً ایتا را به‌روز کنید.';return;
  }
  // Native permission dialog must open from this user click.
  app.requestWriteAccess(async ok=>{
   granted=ok===true;
   permissionStatus.textContent=granted?'مجوز دریافت پیام فعال شد؛ اکنون می‌توانید پرونده را تکمیل کنید.':'مجوز پیام صادر نشد؛ بدون این مجوز امکان تشکیل پرونده وجود ندارد.';
   if(!registered){wrap.hidden=!granted;save.disabled=busy||!granted;if(granted)wrap.scrollIntoView?.({behavior:'smooth',block:'start'});}
   if(granted&&onAllow){
    try{setBusy(true);feedback.textContent='در حال فعال‌کردن دریافت پیام...';
     const result=await onAllow();feedback.textContent=result.message;await refresh();
    }catch(e){feedback.textContent=e.message;}
    finally{setBusy(false);}
   }
  });
 }
 el('qys-permission').addEventListener('click',()=>requestPermission());
 edit.addEventListener('click',()=>{
  if(busy)return;if(!latestEnabled){feedback.textContent='ابتدا مجوز دریافت پیام را فعال کنید.';return;}fill(latestProfile);wrap.hidden=false;cancel.hidden=false;
  el('qys-form-title').textContent='تکمیل یا ویرایش پرونده';save.textContent='ذخیره تغییرات پرونده';
  wrap.scrollIntoView?.({behavior:'smooth',block:'start'});
 });
 cancel.addEventListener('click',()=>{if(busy)return;wrap.hidden=true;fill(latestProfile);feedback.textContent='تغییری ذخیره نشد.';});
 form.addEventListener('submit',async event=>{
  event.preventDefault();if(busy)return;
  birthYear.value=normalizeDigits(birthYear.value).replace(/\D/g,'').slice(0,4);
  refreshBirthDays();
  if(!form.reportValidity())return;
  const family_relations=collectFamilyRelations();
  if(roleField?.value==='staff_family'&&family_relations.length===0){feedback.textContent='برای «خانواده کارکنان» حداقل همسر یا یکی از والدینِ شاغل را مشخص کنید.';familyAdd?.scrollIntoView?.({behavior:'smooth',block:'center'});return;}
  if((!registered&&!granted)||(registered&&!latestEnabled)){feedback.textContent='برای ذخیره پرونده، ابتدا مجوز دریافت پیام قرآن‌یار را فعال کنید.';return;}
  if(mobileNeedsOtp()){feedback.textContent='پیش از ذخیره پرونده، مالکیت شماره موبایل را با کد پیامکی تأیید کنید.';mobileField?.scrollIntoView?.({behavior:'smooth',block:'center'});mobileField?.focus();return;}
  const fields={...Object.fromEntries(new FormData(form)),
   birthday_opt_in:form.elements.namedItem('birthday_opt_in').checked===true,family_relations};
  if(!registered)fields.consent=true;
  setBusy(true);feedback.textContent=registered?'در حال ذخیره تغییرات...':'در حال ثبت پرونده...';
  try{
   const result=await post(registered?QYS.profileEndpoint:QYS.endpoint,fields);
   feedback.textContent=result.message;await refresh();
  }catch(e){feedback.textContent=e.message;}finally{setBusy(false);}
 });
 reactivate.addEventListener('click',()=>{
  if(busy)return;feedback.textContent='در پنجره ایتا، دریافت پیام را تأیید کنید.';
  requestPermission(()=>post(QYS.reactivateEndpoint,{consent:true}));
 });
 disable.addEventListener('click',async()=>{
  if(busy||!window.confirm('دریافت پیام‌ها متوقف و پیام‌های ارسال‌نشده قبلی لغو شوند؟'))return;
  setBusy(true);try{const result=await post(QYS.disableEndpoint);feedback.textContent=result.message;
   granted=false;await refresh();
  }catch(e){feedback.textContent=e.message;}finally{setBusy(false);}
 });
 window.addEventListener('qys:retry',()=>{if(!busy)refresh();});
 refresh();
})();
