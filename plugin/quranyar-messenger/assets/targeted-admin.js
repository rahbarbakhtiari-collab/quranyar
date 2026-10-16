/* QuranYar admin-only recipient picker. Never sends raw identifiers in URLs. */
(() => {
  'use strict';
  const form = document.getElementById('qys-targeted-create');
  if (!form || !window.QYSTarget) return;
  const audience = document.getElementById('qys-target-audience');
  const chooser = document.getElementById('qys-target-chooser');
  const groupsBox = document.getElementById('qys-target-groups');
  const groupChecks = Array.from(form.querySelectorAll('input[name="role_groups[]"]'));
  const search = document.getElementById('qys-target-search');
  const results = document.getElementById('qys-target-search-results');
  const selectedBox = document.getElementById('qys-target-selected');
  const schedule = document.getElementById('qys-target-schedule');
  const date = document.getElementById('qys-target-day');
  const hour = document.getElementById('qys-target-hour');
  const minute = document.getElementById('qys-target-minute');
  const template = document.getElementById('qys-target-template');
  const charCount = document.getElementById('qys-target-char-count');
  const modes = Array.from(form.querySelectorAll('input[name="mode"]'));
  const selected = new Map();
  let controller = null;
  let debounce = null;

  function syncAudience() {
    chooser.style.display = audience.value === 'selected' ? 'block' : 'none';
    if (groupsBox) groupsBox.style.display = audience.value === 'groups' ? 'block' : 'none';
  }
  function syncSchedule() {
    const scheduled = form.querySelector('input[name="mode"]:checked')?.value === 'scheduled';
    // Progressive enhancement: controls remain present without JS, but in the
    // enhanced UI they are clearly inactive for immediate delivery.
    schedule.style.display = 'block';
    schedule.classList.toggle('is-scheduled', scheduled);
    schedule.classList.toggle('is-immediate', !scheduled);
    if (date) { date.required = scheduled; date.disabled = !scheduled; }
    if (hour) hour.disabled = !scheduled;
    if (minute) minute.disabled = !scheduled;
    const hint = document.getElementById('qys-target-time-hint');
    if (hint) hint.textContent = scheduled
      ? 'زمان ارسال را به وقت ایران انتخاب کنید؛ تاریخ و ساعت باید در آینده باشد.'
      : 'ارسال فوری انتخاب شده است؛ تاریخ و ساعت فقط با انتخاب «زمان‌بندی‌شده» فعال می‌شوند.';
  }
  function syncCharCount() {
    if (!template || !charCount) return;
    const count = Array.from(template.value || '').length;
    charCount.textContent = `${count.toLocaleString('fa-IR')} / ۳۵۰۰`;
  }
  function renderSelected() {
    selectedBox.replaceChildren();
    const heading = document.createElement('p');
    heading.textContent = `افراد انتخاب‌شده: ${selected.size}`;
    selectedBox.appendChild(heading);
    for (const [id, label] of selected) {
      const row = document.createElement('div');
      row.style.marginBottom = '5px';
      const field = document.createElement('input');
      field.type = 'hidden'; field.name = 'selected_ids[]'; field.value = String(id);
      const button = document.createElement('button');
      button.type = 'button'; button.className = 'button button-small';
      button.textContent = `حذف ${label}`;
      button.addEventListener('click', () => { selected.delete(id); renderSelected(); });
      row.append(field, button);
      selectedBox.appendChild(row);
    }
  }
  async function findMembers() {
    const term = search.value.trim();
    if (controller) controller.abort();
    results.replaceChildren();
    if ([...term].length < 2) return;
    controller = new AbortController();
    const body = new URLSearchParams({action:'qys_target_member_search',nonce:QYSTarget.nonce,search:term});
    if (QYSTarget.portal && QYSTarget.university) { body.append('qys_portal_scope','1'); body.append('qys_portal_university',String(QYSTarget.university)); }
    const uniMode=form.querySelector('input[name="university_mode"]:checked')?.value || 'all';
    body.append('university_mode',uniMode);
    if(uniMode==='selected')form.querySelectorAll('input[name="university_codes[]"]:checked').forEach(el=>body.append('university_codes[]',el.value));
    const info = document.createElement('p'); info.textContent = 'در حال جست‌وجو…'; results.appendChild(info);
    try {
      const response = await fetch(QYSTarget.url, {
        method:'POST',body,signal:controller.signal,credentials:'same-origin',
        headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},cache:'no-store'
      });
      if (!response.ok) throw new Error('search-failed');
      const json = await response.json();
      if (!json.success || !Array.isArray(json.data)) throw new Error('invalid-response');
      results.replaceChildren();
      if (!json.data.length) {const msg=document.createElement('p');msg.textContent='مخاطب واجد شرایطی یافت نشد.';results.appendChild(msg);return;}
      for (const entry of json.data) {
        const id = Number(entry.id);
        if (!Number.isSafeInteger(id) || id < 1) continue;
        const button = document.createElement('button');
        button.type = 'button';button.className='button';
        button.style.margin = '0 0 5px 5px';
        button.textContent = `افزودن: ${String(entry.label)}`;
        button.addEventListener('click', () => {
          if (selected.size >= 500 && !selected.has(id)) { window.alert('حداکثر ۵۰۰ نفر را به‌صورت دستی انتخاب کنید.'); return; }
          selected.set(id,String(entry.label)); renderSelected();
        });
        results.appendChild(button);
      }
    } catch (e) {
      if (e.name === 'AbortError') return;
      results.replaceChildren();
      const error=document.createElement('p');error.textContent='جست‌وجو انجام نشد؛ اتصال را بررسی کنید.';results.appendChild(error);
    }
  }
  audience.addEventListener('change',syncAudience);
  modes.forEach(mode=>mode.addEventListener('change',syncSchedule));
  form.querySelectorAll('input[name="university_mode"],input[name="university_codes[]"]').forEach(el=>el.addEventListener('change',()=>{selected.clear();renderSelected();if(search.value.trim().length>=2)findMembers();}));
  search.addEventListener('input', () => {clearTimeout(debounce);debounce=setTimeout(findMembers,280);});
  if (template) template.addEventListener('input', syncCharCount);
  form.addEventListener('submit',e => {
    if (audience.value==='selected' && selected.size===0) {
      e.preventDefault();window.alert('حداقل یک مخاطب را انتخاب کنید.');
    } else if (audience.value==='groups' && !groupChecks.some(item=>item.checked)) {
      e.preventDefault();window.alert('حداقل یک گروه را انتخاب کنید.');
    }
  });
  syncAudience();syncSchedule();syncCharCount();renderSelected();
})();
