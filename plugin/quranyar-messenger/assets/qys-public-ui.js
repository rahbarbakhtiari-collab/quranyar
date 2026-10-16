/* QuranYar member app experience. Presentation/accessibility only; API/security stay in feature modules. */
(() => {
  'use strict';
  const root = document.getElementById('qys-app');
  const form = document.getElementById('qys-enroll');
  if (!root || !form) return;

  const $ = id => document.getElementById(id);
  const app = window.Eitaa?.WebApp;
  const nativeBack = app?.BackButton;
  const steps = Array.from(form.querySelectorAll('.qys-form-step'));
  const edit = $('qys-edit-profile');
  const profileWrap = $('qys-profile-wrap');
  const views = Array.from(root.querySelectorAll('.qys-member-view[data-qys-view]'));
  const navButtons = Array.from(root.querySelectorAll('[data-qys-nav]'));
  const validViews = new Set(['home','profile','attendance','programs','services','more']);
  const systemState = $('qys-app-system-state');
  const systemText = $('qys-app-system-text');
  const retry = $('qys-app-retry');
  let activeStep = 0;
  let activeView = 'home';
  let registered = false;
  let latestState = {};
  const lazyLoaded = new Set();
  const lazyLoading = new Map();
  const homeProgramsWrap = $('qys-home-programs');
  const homeProgramsList = $('qys-home-program-list');
  let homeProgramsBusy = false;
  let homeProgramsLoaded = false;
  const programGate = $('qys-program-access-gate');
  const programGateTitle = $('qys-program-gate-title');
  const programGateReason = $('qys-program-gate-reason');
  const programGateCta = $('qys-program-gate-cta');
  const programContent = $('qys-program-content');
  const esc = value => String(value ?? '').replace(/[&<>'"]/g, ch => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[ch]));
  const currentProgramStatuses = new Set(['registration_open','registration_closed','confirmed','running']);

  function asyncStateMarkup(kind, title, message = '', actionLabel = '', actionAttr = '') {
    const role = kind === 'error' ? 'alert' : 'status';
    const spinner = kind === 'loading' ? '<span class="qys-async-spinner" aria-hidden="true"></span>' : '';
    const action = actionLabel && actionAttr ? `<button type="button" class="qys-async-action" ${actionAttr}>${esc(actionLabel)}</button>` : '';
    return `<div class="qys-async-state is-${esc(kind)}" role="${role}" aria-live="polite">${spinner}<div class="qys-async-copy"><strong>${esc(title)}</strong>${message?`<small>${esc(message)}</small>`:''}</div>${action}</div>`;
  }
  window.QYSAsyncState = Object.freeze({markup: asyncStateMarkup});

  function setHomeProgramsState(kind, title = '', message = '') {
    if (!homeProgramsWrap || !homeProgramsList) return;
    homeProgramsWrap.hidden = false;
    homeProgramsWrap.dataset.state = kind;
    homeProgramsList.setAttribute('aria-busy', kind === 'loading' ? 'true' : 'false');
    if (kind === 'loading') {
      homeProgramsList.innerHTML = asyncStateMarkup('loading', title || 'در حال آماده‌کردن برنامه‌های شما...', message || 'لطفاً چند لحظه صبر کنید.');
    } else if (kind === 'empty') {
      homeProgramsList.innerHTML = asyncStateMarkup('empty', title || 'فعلاً برنامه‌ای برای شما فعال نیست.', message || 'هر زمان برنامه تازه‌ای برای شما فعال شود، همین‌جا می‌بینید.');
    } else if (kind === 'error') {
      homeProgramsList.innerHTML = asyncStateMarkup('error', title || 'برنامه‌ها بارگذاری نشد.', message || 'اتصال اینترنت را بررسی کنید و دوباره تلاش کنید.', 'تلاش دوباره', 'data-qys-home-programs-retry');
    }
  }

  if (app?.initData) document.body.classList.add('qys-miniapp-mode');

  function setSystemState(kind, message = '') {
    if (!systemState) return;
    systemState.className = `qys-app-system-state is-${kind}`;
    if (systemText) systemText.textContent = message || (kind === 'loading' ? 'در حال آماده‌کردن قرآن‌یار...' : '');
    if (retry) retry.hidden = kind !== 'error';
    systemState.hidden = kind === 'ready';
    root.setAttribute('aria-busy', kind === 'loading' ? 'true' : 'false');
  }

  function syncNativeBack() {
    if (!app?.initData) return;
    const shouldShow = activeView !== 'home' || (activeView === 'profile' && activeStep > 0);
    try {
      if (shouldShow) nativeBack?.show?.();
      else nativeBack?.hide?.();
    } catch (_) {}
  }

  function focusViewHeading(view) {
    const section = views.find(v => v.dataset.qysView === view);
    const heading = section?.querySelector('h1,h2');
    if (!heading) return;
    heading.setAttribute('tabindex','-1');
    try { heading.focus({preventScroll:true}); } catch (_) { heading.focus?.(); }
  }

  async function postHomeSummary() {
    const endpoint=QYS?.homeSummaryEndpoint;
    if (!endpoint || !app?.initData) throw new Error('اطلاعات صفحه خانه در دسترس نیست.');
    let response;
    try{response=await fetch(endpoint,{method:'POST',headers:{'Content-Type':'application/json'},credentials:'omit',cache:'no-store',body:JSON.stringify({initData:app.initData})});}
    catch(_){throw new Error('ارتباط برقرار نشد. اینترنت را بررسی کنید و دوباره تلاش کنید.');}
    const data=await response.json().catch(()=>({}));
    if(!response.ok)throw new Error(data?.message||data?.data?.message||'برنامه‌ها بارگذاری نشد.');
    return data;
  }

  function homeProgramCard(program) {
    const personal=program.enrollment_status_label||program.application_status_label||'';
    const action=program.registration_open&&!program.application_id?'پیش‌ثبت‌نام':'مشاهده';
    return `<article class="qys-home-program-card"><div class="qys-home-program-card-top"><span>${esc(program.type_label||'برنامه')}</span><span class="qys-home-program-state">${esc(program.status_label||'جاری')}</span></div><h3>${esc(program.title)}</h3>${program.description?`<p>${esc(program.description)}</p>`:''}<div class="qys-home-program-card-bottom">${personal?`<small>${esc(personal)}</small>`:'<small>برای شما در دسترس است</small>'}<button type="button" data-qys-go="programs">${action}</button></div></article>`;
  }

  async function loadHomePrograms(force=false) {
    if(!homeProgramsWrap||!homeProgramsList||homeProgramsBusy||(homeProgramsLoaded&&!force))return;
    if(!(latestState.registered&&latestState.profile_complete&&latestState.verified)){
      homeProgramsWrap.hidden=true;homeProgramsList.innerHTML='';homeProgramsWrap.dataset.state='ineligible';homeProgramsLoaded=false;return;
    }
    homeProgramsBusy=true;
    setHomeProgramsState('loading');
    try{
      const data=await postHomeSummary();
      const rows=(data.programs||[]).filter(program=>currentProgramStatuses.has(String(program.status||''))).slice(0,3);
      if(!rows.length){setHomeProgramsState('empty');homeProgramsLoaded=true;return;}
      homeProgramsList.innerHTML=rows.map(homeProgramCard).join('');
      homeProgramsList.setAttribute('aria-busy','false');
      homeProgramsWrap.dataset.state='ready';
      homeProgramsWrap.hidden=false;
      homeProgramsLoaded=true;
    }catch(error){
      homeProgramsLoaded=false;
      setHomeProgramsState('error','برنامه‌ها بارگذاری نشد.',error?.message||'اتصال اینترنت را بررسی کنید و دوباره تلاش کنید.');
    } finally{homeProgramsBusy=false;}
  }

  function lazyStyle(url,key) {
    if(!url)return Promise.resolve();
    const existing=document.querySelector(`link[data-qys-lazy-style="${key}"]`);
    if(existing)return Promise.resolve();
    return new Promise((resolve,reject)=>{
      const link=document.createElement('link');link.rel='stylesheet';link.href=url;link.dataset.qysLazyStyle=key;
      link.addEventListener('load',()=>resolve(),{once:true});
      link.addEventListener('error',()=>{link.remove();reject(new Error('style_load_failed'));},{once:true});
      document.head.appendChild(link);
    });
  }

  function lazyScript(url,key) {
    if(!url)return Promise.reject(new Error('script_missing'));
    const existing=document.querySelector(`script[data-qys-lazy-script="${key}"]`);
    if(existing&&existing.dataset.qysReady==='1')return Promise.resolve();
    return new Promise((resolve,reject)=>{
      const script=existing||document.createElement('script');
      script.addEventListener('load',()=>{script.dataset.qysReady='1';resolve();},{once:true});
      script.addEventListener('error',()=>{script.remove();reject(new Error('script_load_failed'));},{once:true});
      if(!existing){script.src=url;script.defer=true;script.dataset.qysLazyScript=key;document.body.appendChild(script);}
    });
  }

  function featureStateTarget(view) {
    const section=views.find(node=>node.dataset.qysView===view);if(!section)return null;
    return view==='programs'?section.querySelector('#qys-program-list'):section.querySelector('#qys-attendance-slot');
  }

  function showFeatureLoading(view) {
    const target=featureStateTarget(view);if(!target)return;
    target.innerHTML=asyncStateMarkup('loading','در حال بازکردن این بخش...','لطفاً چند لحظه صبر کنید.');
    target.setAttribute('aria-busy','true');
  }

  function showFeatureLoadError(view) {
    const target=featureStateTarget(view);if(!target)return;
    target.innerHTML=asyncStateMarkup('error','این بخش باز نشد.','اتصال اینترنت را بررسی و دوباره تلاش کنید.','تلاش دوباره',`data-qys-feature-retry="${esc(view)}"`);
    target.setAttribute('aria-busy','false');
  }

  function ensureFeature(view,force=false) {
    const manifest=window.QYSLazyFeatures?.[view];
    if(!manifest)return Promise.resolve(false);
    if(force){lazyLoaded.delete(view);lazyLoading.delete(view);}
    if(lazyLoaded.has(view))return Promise.resolve(true);
    if(lazyLoading.has(view))return lazyLoading.get(view);
    if(manifest.global)window[manifest.global]=manifest.config||{};
    if(activeView===view)showFeatureLoading(view);
    const load=Promise.all([lazyStyle(manifest.style,view),lazyScript(manifest.script,view)]).then(()=>{
      lazyLoaded.add(view);
      const target=featureStateTarget(view);if(target)target.setAttribute('aria-busy','false');
      if(activeView===view)window.dispatchEvent(new CustomEvent('qys:viewchange',{detail:{view,state:latestState,lazyLoaded:true}}));
      return true;
    }).catch(()=>{showFeatureLoadError(view);return false;}).finally(()=>lazyLoading.delete(view));
    lazyLoading.set(view,load);return load;
  }

  function programsGateState(state = latestState) {
    if (!state || Object.keys(state).length === 0) return {blocked:true,code:'checking',title:'در حال بررسی پرونده شما',reason:'چند لحظه صبر کنید تا وضعیت پرونده شما مشخص شود.',cta:''};
    if (!state.registered) return {blocked:true,code:'membership_required',title:'ابتدا مجوز پیام و پرونده قرآن‌یار را فعال کنید',reason:'برای تشکیل پرونده و استفاده از برنامه‌ها، مجوز دریافت پیام قرآن‌یار الزامی است.',cta:'شروع تشکیل پرونده'};
    if (!state.enabled) return {blocked:true,code:'consent_required',title:'مجوز دریافت پیام لازم است',reason:'مجوز پیام این پرونده غیرفعال است؛ ابتدا آن را دوباره فعال کنید.',cta:'فعال‌سازی دریافت پیام'};
    if (!state.profile_complete) return {blocked:true,code:'profile_incomplete',title:'پرونده شما کامل نیست',reason:'اطلاعات ضروری پرونده را تکمیل کنید تا برنامه‌های متناسب با دانشگاه و عنوان عضویت شما نمایش داده شوند.',cta:'تکمیل پرونده'};
    if (!state.verified) return {blocked:true,code:'verification_pending',title:'تأیید هویت هنوز انجام نشده است',reason:'ثبت‌نام و مشاهده سوابق برنامه‌ها پس از تأیید هویت توسط مدیر دانشگاه فعال می‌شود.',cta:'مشاهده وضعیت پرونده'};
    return {blocked:false,code:'ready',title:'',reason:'',cta:''};
  }

  function syncProgramsGate(state = latestState) {
    if (!programGate || !programContent) return {blocked:false,code:'unavailable'};
    const gate = programsGateState(state);
    programGate.hidden = !gate.blocked;
    programContent.hidden = gate.blocked;
    if (gate.blocked) {
      if (programGateTitle) programGateTitle.textContent = gate.title;
      if (programGateReason) programGateReason.textContent = gate.reason;
      if (programGateCta) { programGateCta.hidden = !gate.cta; programGateCta.textContent = gate.cta || 'مشاهده پرونده'; }
      programGate.dataset.gate = gate.code;
    } else {
      programGate.dataset.gate = 'ready';
      if (programGateCta) programGateCta.hidden = true;
    }
    return gate;
  }

  function navigate(view, options = {}) {
    if (!validViews.has(view)) view = 'home';
    activeView = view;
    root.dataset.qysView = view;
    views.forEach(section => {
      const active = section.dataset.qysView === view;
      section.hidden = !active;
      section.setAttribute('aria-hidden', active ? 'false' : 'true');
    });
    navButtons.forEach(button => {
      if (button.dataset.qysNav === view) button.setAttribute('aria-current','page');
      else button.removeAttribute('aria-current');
    });
    const gate = view === 'programs' ? syncProgramsGate(latestState) : {blocked:false};
    window.dispatchEvent(new CustomEvent('qys:viewchange',{detail:{view,state:latestState,gate}}));
    if (!(view === 'programs' && gate.blocked)) ensureFeature(view);
    if(view==='home'&&latestState.registered&&latestState.profile_complete&&latestState.verified)loadHomePrograms();
    syncNativeBack();
    if (options.scroll !== false) root.scrollIntoView?.({behavior:options.instant?'auto':'smooth',block:'start'});
    if (options.focus !== false && !options.instant) focusViewHeading(view);
  }
  window.QYSMemberNavigate = navigate;

  function displayStep(index, focus = false) {
    activeStep = Math.max(0, Math.min(steps.length - 1, index));
    steps.forEach((step, i) => { step.hidden = i !== activeStep; });
    Array.from(form.querySelectorAll('.qys-form-dot')).forEach((dot, i) => {
      if (i === activeStep) dot.setAttribute('aria-current','step');
      else dot.removeAttribute('aria-current');
    });
    syncNativeBack();
    if (focus && !profileWrap.hidden) {
      const heading=steps[activeStep]?.querySelector('h4');
      heading?.setAttribute('tabindex','-1');
      try { heading?.focus({ preventScroll: true }); } catch (_) { heading?.focus?.(); }
      profileWrap.scrollIntoView?.({behavior:'smooth',block:'start'});
    }
  }

  function handleBack() {
    if (activeView === 'profile' && !profileWrap?.hidden && activeStep > 0) {
      displayStep(activeStep - 1, true);
      return;
    }
    if (activeView !== 'home') {
      navigate('home',{focus:true});
      return;
    }
    try { nativeBack?.hide?.(); } catch (_) {}
  }

  if (app?.initData) {
    try {
      if (typeof nativeBack?.onClick === 'function') nativeBack.onClick(handleBack);
      else if (typeof app.onEvent === 'function') app.onEvent('backButtonClicked',handleBack);
    } catch (_) {}
  }

  root.addEventListener('click', event => {
    const homeProgramsRetry=event.target.closest('[data-qys-home-programs-retry]');
    if(homeProgramsRetry&&root.contains(homeProgramsRetry)){event.preventDefault();loadHomePrograms(true);return;}
    const featureRetry=event.target.closest('[data-qys-feature-retry]');
    if(featureRetry&&root.contains(featureRetry)){event.preventDefault();ensureFeature(featureRetry.dataset.qysFeatureRetry,true);return;}
    const gateCta=event.target.closest('[data-qys-program-gate-cta]');
    if(gateCta&&root.contains(gateCta)){event.preventDefault();navigate('profile',{scroll:true,focus:true});if(steps.length===3)displayStep(0,true);return;}
    const nav = event.target.closest('[data-qys-nav]');
    if (nav && root.contains(nav)) { event.preventDefault(); navigate(nav.dataset.qysNav); return; }
    const go = event.target.closest('[data-qys-go]');
    if (go && root.contains(go)) { event.preventDefault(); navigate(go.dataset.qysGo); }
  });

  function checkStep(index) {
    for (const input of steps[index].querySelectorAll('input,select,textarea')) {
      if (!input.checkValidity()) { input.reportValidity(); input.focus(); return false; }
    }
    return true;
  }

  if (steps.length === 3) {
    const stepper = document.createElement('nav');
    stepper.className = 'qys-form-progress';
    stepper.setAttribute('aria-label','مراحل تکمیل پرونده');
    ['اطلاعات فردی','اطلاعات شناسایی','تولد و رضایت'].forEach((label,i) => {
      const dot = document.createElement('span');
      dot.className = 'qys-form-dot';
      const numeral = document.createElement('strong'); numeral.textContent = String(i+1);
      dot.append(numeral,document.createTextNode(label)); stepper.append(dot);
    });
    form.prepend(stepper);
    [0,1].forEach(i => {
      const controls = document.createElement('div'); controls.className = 'qys-form-controls';
      const next = document.createElement('button'); next.type = 'button';
      next.textContent = i===0?'ادامه؛ اطلاعات شناسایی':'ادامه؛ تاریخ تولد';
      next.addEventListener('click', () => { if (checkStep(i)) displayStep(i+1,true); });
      controls.appendChild(next);
      if (i > 0) {
        const back = document.createElement('button'); back.type='button'; back.className='qys-form-back';
        back.textContent='بازگشت'; back.addEventListener('click',()=>displayStep(i-1,true)); controls.appendChild(back);
      }
      steps[i].append(controls);
    });
    const finalControls = document.createElement('div'); finalControls.className='qys-form-controls';
    const back = document.createElement('button'); back.type='button'; back.className='qys-form-back'; back.textContent='بازگشت به اطلاعات شناسایی';
    back.addEventListener('click',()=>displayStep(1,true)); finalControls.append(back);
    const submitRow = $('qys-save')?.closest('p');
    if (submitRow) steps[2].insertBefore(finalControls,submitRow);
    form.addEventListener('invalid', event => {
      const i = steps.findIndex(step => step.contains(event.target));
      if (i !== -1 && i !== activeStep) { navigate('profile',{scroll:false}); displayStep(i); }
    },true);
    displayStep(0);
  }

  const setPill = (id, label, state) => {
    const node = $(id); if (!node) return;
    node.textContent=label;
    node.className='qys-pill '+(state==='success'?'qys-pill--success':state==='warning'?'qys-pill--warning':'qys-pill--neutral');
  };
  const setText = (id, text) => { const node=$(id); if(node)node.textContent=text; };
  const setServiceState = (id, label, kind = 'loading') => {
    const node=$(id); if (!node) return;
    node.textContent=label;
    node.className=`qys-service-state is-${kind}`;
  };
  const setServiceCard = (id, kind = '') => {
    const node=$(id); if (!node) return;
    node.classList.remove('is-active','is-attention');
    if (kind) node.classList.add(`is-${kind}`);
  };
  const universityLabel = code => QYS.universities?.[code] || code || 'ثبت نشده';
  const roleLabel = code => QYS.memberRoles?.[code] || code || 'ثبت نشده';

  window.addEventListener('qys:statusloading', () => setSystemState('loading','در حال بررسی پرونده شما...'));
  window.addEventListener('qys:statuserror', event => setSystemState('error',event.detail?.message || 'اطلاعات شما بارگذاری نشد.'));
  window.addEventListener('qys:status', event => {
    setSystemState('ready');
    const s = event.detail || {};
    latestState = s;
    registered = Boolean(s.registered);
    const currentProgramGate = syncProgramsGate(s);
    if (activeView === 'programs' && !currentProgramGate.blocked) ensureFeature('programs');
    if(registered&&s.profile_complete&&s.verified)loadHomePrograms();
    else if(homeProgramsWrap){homeProgramsWrap.hidden=true;if(homeProgramsList)homeProgramsList.innerHTML='';homeProgramsLoaded=false;}
    const profile = s.profile || {};
    setText('qys-display-name', registered && profile.first_name ? String(profile.first_name) : 'همراه قرآنی');
    const serverProgress = Number(s.profile_progress?.percent);
    const percent = Number.isFinite(serverProgress) ? Math.max(0,Math.min(100,Math.round(serverProgress))) : (s.profile_complete?100:0);
    setText('qys-progress-number',`${percent}٪`);
    if ($('qys-progress-fill')) $('qys-progress-fill').style.width = `${percent}%`;
    $('qys-progress-track')?.setAttribute('aria-valuenow',String(percent));
    if ($('qys-progress-wrap')) $('qys-progress-wrap').hidden = Boolean(registered && s.profile_complete);
    setPill('qys-badge-profile',!registered?'تشکیل پرونده':s.profile_complete?'پرونده کامل':'نیازمند تکمیل',s.profile_complete?'success':'warning');
    setPill('qys-badge-identity',s.verified?'تأیید دانشگاه: انجام شد':'تأیید دانشگاه: در انتظار',s.verified?'success':'warning');
    setPill('qys-badge-consent',s.enabled?'دریافت پیام فعال':'دریافت پیام غیرفعال',s.enabled?'success':'warning');
    setText('qys-more-university', registered ? (universityLabel(profile.university)+(profile.pending_university?' ← در انتظار انتقال به '+universityLabel(profile.pending_university):'')) : 'پس از تشکیل پرونده');
    setText('qys-more-role', registered ? roleLabel(profile.member_role) : 'پس از تشکیل پرونده');
    setText('qys-more-consent-state', !registered?'هنوز پرونده‌ای ندارید':s.enabled?'دریافت پیام فعال است':'دریافت پیام غیرفعال است');
    const accountName=registered?[profile.first_name,profile.last_name].filter(Boolean).join(' ').trim():'همراه قرآنی';
    setText('qys-more-account-name',accountName || 'همراه قرآنی');
    setText('qys-more-avatar-letter',registered && profile.first_name ? String(profile.first_name).trim().charAt(0) || 'ق' : 'ق');
    const accountReady=Boolean(registered && s.enabled && s.profile_complete && s.verified);
    setText('qys-more-account-state',!registered?'پرونده‌ای تشکیل نشده است':!s.profile_complete?'پرونده نیازمند تکمیل است':!s.verified?'پرونده در انتظار تأیید دانشگاه است':'همه‌چیز آماده است');
    setServiceState('qys-more-account-badge',!registered?'بدون پرونده':accountReady?'آماده':s.verified?'نیازمند تکمیل':'در انتظار',accountReady?'active':'attention');
    const messageState=!registered?'نیازمند پرونده':s.enabled?'دریافت فعال':'باید فعال شود';
    const birthdayState=!registered?'نیازمند پرونده':!profile.birthday_opt_in?'غیرفعال':s.enabled?'فعال':'نیاز به فعال‌سازی پیام';
    const attendanceReady=Boolean(registered && s.profile_complete && s.verified);
    setServiceState('qys-service-message-state',!registered?'نیازمند پرونده':s.enabled?'فعال':'باید فعال شود',!registered?'muted':s.enabled?'active':'attention');
    setServiceState('qys-service-attendance-state',!registered?'نیازمند پرونده':!s.profile_complete?'پرونده ناقص':s.verified?'آماده استفاده':'منتظر تأیید',attendanceReady?'active':registered?'attention':'muted');
    setServiceState('qys-service-program-state',!registered?'نیازمند پرونده':!s.profile_complete?'پرونده ناقص':s.verified?'آماده استفاده':'منتظر تأیید',attendanceReady?'active':registered?'attention':'muted');
    setServiceState('qys-service-birthday-state',!registered?'نیازمند پرونده':!profile.birthday_opt_in?'غیرفعال':s.enabled?'فعال':'نیاز به دریافت پیام',!registered?'muted':profile.birthday_opt_in&&s.enabled?'active':'attention');
    setServiceCard('qys-service-message-card',registered && s.enabled?'active':registered?'attention':'');
    setServiceCard('qys-service-attendance-card',attendanceReady?'active':registered?'attention':'');
    setServiceCard('qys-service-program-card',attendanceReady?'active':registered?'attention':'');
    setServiceCard('qys-service-birthday-card',registered && profile.birthday_opt_in && s.enabled?'active':registered?'attention':'');
    const activeServices=[Boolean(registered&&s.enabled),attendanceReady,attendanceReady,Boolean(registered&&s.enabled&&profile.birthday_opt_in)].filter(Boolean).length;
    const attentionServices=registered ? 4-activeServices : 0;
    setText('qys-services-active-count',registered?`${activeServices} از ۴`:'۰ از ۴');
    setText('qys-services-attention-count',registered?String(attentionServices):'—');
    const servicesNote=$('qys-services-note');
    if (servicesNote) {
      servicesNote.classList.toggle('is-attention',!accountReady || attentionServices>0);
      servicesNote.textContent=!registered?'ابتدا دریافت پیام را فعال کنید و پرونده خود را کامل کنید.':!s.enabled?'برای ادامه استفاده، دریافت پیام را دوباره فعال کنید.':!s.profile_complete?'تکمیل پرونده برای فعال‌شدن همه خدمات لازم است.':!s.verified?'پس از تأیید پرونده توسط دانشگاه، حضور و برنامه‌ها برای شما فعال می‌شود.':attentionServices>0?'بعضی خدمات اختیاری مانند تبریک تولد غیرفعال‌اند؛ از بخش پرونده می‌توانید آن‌ها را مدیریت کنید.':'خدمات شما آماده استفاده است.';
    }
    root.querySelector('[data-qys-nav="services"]')?.classList.toggle('is-attention',Boolean(registered && (!accountReady || attentionServices>0)));
    root.querySelector('[data-qys-nav="more"]')?.classList.toggle('is-attention',Boolean(registered && (!s.enabled || !s.profile_complete || !s.verified)));
    setText('qys-home-message-state',messageState);
    setText('qys-home-birthday-state',birthdayState);
    const homeAction=$('qys-home-primary-action');
    if (homeAction) {
      if (!registered) { homeAction.dataset.qysGo='profile'; homeAction.textContent='شروع تشکیل پرونده'; }
      else if (!s.enabled) { homeAction.dataset.qysGo='profile'; homeAction.textContent='فعال‌سازی دریافت پیام'; }
      else if (!s.profile_complete) { homeAction.dataset.qysGo='profile'; homeAction.textContent='تکمیل پرونده'; }
      else if (!s.verified) { homeAction.dataset.qysGo='profile'; homeAction.textContent='مشاهده وضعیت پرونده'; }
      else { homeAction.dataset.qysGo='attendance'; homeAction.textContent='مشاهده حضور'; }
    }
    if (!registered) {
      setText('qys-home-attendance-title','ابتدا پرونده قرآن‌یار را تشکیل دهید');
      setText('qys-home-attendance-meta','پس از تکمیل پرونده، جلسه‌های مجاز شما در این بخش دیده می‌شوند.');
    } else if (!s.enabled) {
      setText('qys-home-attendance-title','مجوز دریافت پیام لازم است');
      setText('qys-home-attendance-meta','برای فعال ماندن پرونده و خدمات قرآن‌یار، مجوز پیام را دوباره فعال کنید.');
    } else if (!s.profile_complete) {
      setText('qys-home-attendance-title','پرونده شما کامل نیست');
      setText('qys-home-attendance-meta','اطلاعات لازم را تکمیل کنید تا حضور و غیاب فعال شود.');
    } else if (!s.verified) {
      setText('qys-home-attendance-title','منتظر تأیید هویت');
      setText('qys-home-attendance-meta','پس از تأیید مدیر دانشگاه، ثبت حضور برای شما فعال می‌شود.');
    } else {
      setText('qys-home-attendance-title','حضور برای شما فعال است');
      setText('qys-home-attendance-meta','برای مشاهده جلسه‌های فعال و سوابق، وارد بخش حضور شوید.');
    }
    root.querySelector('[data-qys-nav="attendance"]')?.classList.toggle('is-pending',!(registered&&s.profile_complete&&s.verified));
    root.querySelector('[data-qys-nav="programs"]')?.classList.toggle('is-pending',!(registered&&s.profile_complete&&s.verified));
    if (steps.length===3 && !profileWrap.hidden) displayStep(0);
  });


  window.addEventListener('qys:attendancehome', event => {
    const detail=event.detail || {};
    if (!detail.eligible) return;
    if (detail.actionable) {
      setText('qys-home-attendance-title', detail.actionableTitle || 'جلسه آماده ثبت حضور است');
      setText('qys-home-attendance-meta', detail.actionableMeta || 'ورود یا خروج خود را از داخل بخش حضور ثبت کنید.');
      return;
    }
    if (detail.nextTitle) {
      setText('qys-home-attendance-title', detail.nextTitle);
      setText('qys-home-attendance-meta', detail.nextMeta || 'جلسه بعدی شما در بخش حضور آماده است.');
      return;
    }
    if (detail.lastTitle) {
      setText('qys-home-attendance-title','جلسه فعالی در پیش نیست');
      setText('qys-home-attendance-meta', detail.lastTitle);
      return;
    }
    setText('qys-home-attendance-title','جلسه فعالی برای شما نیست');
    setText('qys-home-attendance-meta','هر زمان جلسه‌ای برای دانشگاه شما فعال شود، اینجا نمایش داده می‌شود.');
  });

  const syncRuntimeIndicator = () => {
    const dot=$('qys-more-online-dot');
    if (dot) { dot.classList.toggle('is-online',navigator.onLine); dot.classList.toggle('is-offline',!navigator.onLine); }
    setText('qys-more-sync-state',navigator.onLine?'برای تازه‌کردن اطلاعات بزنید':'اینترنت قطع است؛ پس از اتصال دوباره امتحان کنید');
  };
  retry?.addEventListener('click', () => {
    setSystemState('loading','در حال تلاش دوباره...');
    homeProgramsLoaded=false;
    if(activeView==='home')loadHomePrograms(true);
    window.dispatchEvent(new CustomEvent('qys:retry'));
  });
  $('qys-more-refresh')?.addEventListener('click', () => {
    if (!navigator.onLine) { syncRuntimeIndicator(); setSystemState('offline','اینترنت قطع است؛ بعد از اتصال دوباره، اطلاعات تازه می‌شود.'); return; }
    setText('qys-more-sync-state','در حال تازه‌کردن اطلاعات...');
    setSystemState('loading','در حال تازه‌کردن اطلاعات...');
    homeProgramsLoaded=false;
    window.dispatchEvent(new CustomEvent('qys:retry'));
  });
  window.addEventListener('qys:status',()=>{setText('qys-more-sync-state','اطلاعات به‌روز شد');syncRuntimeIndicator();});
  window.addEventListener('offline',()=>{syncRuntimeIndicator();setSystemState('offline','اینترنت قطع است؛ بعد از اتصال دوباره، اطلاعات تازه می‌شود.');});
  window.addEventListener('online',()=>{syncRuntimeIndicator();setSystemState('loading','اینترنت وصل شد؛ در حال تازه‌کردن اطلاعات...');window.dispatchEvent(new CustomEvent('qys:retry'));});

  edit?.addEventListener('click', () => { navigate('profile',{scroll:false}); if (steps.length===3) displayStep(0); });
  $('qys-cancel-edit')?.addEventListener('click',()=>displayStep(0));

  navigate('home',{scroll:false,instant:true,focus:false});
  syncRuntimeIndicator();
  if (!navigator.onLine) setSystemState('offline','اینترنت قطع است؛ برای دیدن اطلاعات تازه دوباره متصل شوید.');
  else setSystemState('loading','در حال بررسی پرونده شما...');
})();
