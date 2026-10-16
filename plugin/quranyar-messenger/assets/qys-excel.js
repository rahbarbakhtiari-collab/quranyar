(() => {
  'use strict';
  const root = document.querySelector('#qys-excel-form');
  if (!root || !window.QYSExcel) return;
  const $ = (id) => document.getElementById(id);
  const fileInput = $('qys-excel-file');
  const fileDrop = $('qys-excel-file-drop');
  const status = $('qys-excel-status');
  const mapping = $('qys-excel-mapping');
  const compose = $('qys-excel-compose');
  const columns = $('qys-excel-columns');
  const idDrop = $('qys-excel-id-drop');
  const idSelect = $('qys-excel-id-select');
  const national = $('qys-excel-national');
  const digest = $('qys-excel-digest');
  const title = $('qys-excel-label');
  const template = $('qys-excel-template');
  const submit = $('qys-excel-submit');
  let headers = [];
  let readyFile = null;
  let cursor = 0;
  let previewCounter = 0;

  function show(message, error = false) {
    status.textContent = message;
    status.className = error ? 'qys-excel-error' : 'qys-excel-info';
  }
  function checkReady() {
    const nationalToken = national.value === '' ? '' : `{ستون${Number(national.value) + 1}}`;
    submit.disabled = !(readyFile && national.value !== '' && title.value.trim() && template.value.trim())
      || Boolean(nationalToken && template.value.includes(nationalToken));
  }
  function reset() {
    ++previewCounter;
    readyFile = null;
    headers = [];
    columns.replaceChildren();
    while (idSelect.options.length > 1) idSelect.remove(1);
    idSelect.value = '';
    national.value = '';
    digest.value = '';
    idDrop.textContent = 'ستون «کد ملی» را اینجا رها کنید';
    idDrop.classList.remove('has-column');
    mapping.hidden = true;
    compose.hidden = true;
    checkReady();
  }
  function setNational(index) {
    if (index === '' || !Number.isInteger(Number(index)) || Number(index) < 0 || Number(index) >= headers.length) return;
    national.value = String(index);
    idSelect.value = String(index);
    if (template.value.includes(`{ستون${Number(index) + 1}}`)) {
      show('این ستون در متن پیام استفاده شده است. برای حفاظت از کد ملی، ابتدا متغیر مربوط را از متن بردارید.', true);
    }
    idDrop.textContent = `ستون انتخاب‌شده: ${headers[Number(index)]}`;
    idDrop.classList.add('has-column');
    checkReady();
  }
  function insertToken(token) {
    if (!token) return;
    const start = Number.isInteger(template.selectionStart) ? template.selectionStart : cursor;
    const end = Number.isInteger(template.selectionEnd) ? template.selectionEnd : start;
    template.value = template.value.slice(0, start) + token + template.value.slice(end);
    template.focus();
    const next = start + token.length;
    template.setSelectionRange(next, next);
    cursor = next;
    template.dispatchEvent(new Event('input', { bubbles: true }));
  }
  function columnChip(name, index) {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'qys-excel-chip';
    button.draggable = true;
    button.dataset.column = String(index);
    button.textContent = name;
    button.title = `کشیدن به کد ملی یا متن پیام؛ کلیک برای افزودن ستون ${name} در متن پیام`;
    button.addEventListener('dragstart', (event) => {
      event.dataTransfer.setData('text/plain', `qys-col:${index}`);
      event.dataTransfer.effectAllowed = 'copy';
      button.classList.add('is-dragged');
    });
    button.addEventListener('dragend', () => button.classList.remove('is-dragged'));
    button.addEventListener('click', () => {
      if (index === Number(national.value) && national.value !== '') {
        show('ستون کد ملی فقط برای تطبیق عضو استفاده می‌شود؛ ستون دیگری را به متن پیام اضافه کنید.', true);
        return;
      }
      insertToken(`{ستون${index + 1}}`);
    });
    return button;
  }
  function extractColumn(event) {
    const value = event.dataTransfer?.getData('text/plain') || '';
    if (!/^qys-col:(?:0|[1-9]\d*)$/.test(value)) return null;
    const index = Number(value.split(':')[1]);
    return index >= 0 && index < headers.length ? index : null;
  }
  function enableDrop(target, handler) {
    ['dragenter', 'dragover'].forEach((name) => target.addEventListener(name, (event) => {
      if (!Array.from(event.dataTransfer?.types || []).includes('text/plain')) return;
      event.preventDefault();
      target.classList.add('is-drop-target');
    }));
    ['dragleave', 'dragend'].forEach((name) => target.addEventListener(name, () => target.classList.remove('is-drop-target')));
    target.addEventListener('drop', (event) => {
      target.classList.remove('is-drop-target');
      const index = extractColumn(event);
      if (index === null) return;
      event.preventDefault();
      handler(index);
    });
  }
  enableDrop(idDrop, setNational);
  enableDrop(template, (index) => {
    if (national.value !== '' && Number(national.value) === index) {
      show('برای جلوگیری از افشای تصادفی، ستون کد ملی در متن پیام قابل استفاده نیست.', true);
      return;
    }
    insertToken(`{ستون${index + 1}}`);
  });
  idSelect.addEventListener('change', () => {
    if (idSelect.value === '') {
      national.value = '';
      idDrop.textContent = 'ستون «کد ملی» را اینجا رها کنید';
      idDrop.classList.remove('has-column');
      checkReady();
      return;
    }
    setNational(idSelect.value);
  });
  [title, template].forEach((el) => el.addEventListener('input', checkReady));
  ['click', 'keyup', 'select', 'focus'].forEach((name) => template.addEventListener(name, () => {
    if (Number.isInteger(template.selectionStart)) cursor = template.selectionStart;
  }));
  root.querySelectorAll('[data-token]').forEach((button) => button.addEventListener('click', () => insertToken(button.dataset.token)));
  idDrop.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      idSelect.focus();
    }
  });
  const time = $('qys-excel-time');
  root.querySelectorAll('input[name="mode"]').forEach((radio) => radio.addEventListener('change', () => {
    time.hidden = root.querySelector('input[name="mode"]:checked').value !== 'scheduled';
  }));
  root.addEventListener('submit', (event) => {
    checkReady();
    if (submit.disabled) {
      event.preventDefault();
      show('ابتدا فایل را بارگذاری و ستون کد ملی، عنوان و متن پیام را کامل کنید.', true);
    }
  });

  async function inspectFile() {
    reset();
    const file = fileInput.files?.[0];
    if (!file) {show('');return;}
    if (!/\.(xlsx|csv)$/i.test(file.name) || file.size > QYSExcel.maxSize || file.size <= 0) {
      show('فایل CSV یا XLSX حداکثر ۵ مگابایت انتخاب کنید.', true);
      return;
    }
    const ticket = previewCounter;
    const data = new FormData();
    data.append('action', 'qys_excel_headers');
    data.append('nonce', QYSExcel.nonce);
    data.append('qys_portal_scope', '1');
    data.append('qys_portal_university', QYSExcel.university);
    data.append('dataset', file, file.name);
    show('در حال خواندن عنوان ستون‌ها…');
    try {
      const response = await fetch(QYSExcel.url, { method: 'POST', body: data, credentials: 'same-origin' });
      const parsed = await response.json();
      if (ticket !== previewCounter) return;
      if (!response.ok || !parsed.success || !Array.isArray(parsed.data?.headers)) {
        throw new Error(typeof parsed.data === 'string' ? parsed.data : 'خواندن فایل ناموفق بود.');
      }
      const info = parsed.data;
      headers = info.headers;
      readyFile = file;
      digest.value = info.digest;
      headers.forEach((name, i) => {
        columns.appendChild(columnChip(name, i));
        const option = document.createElement('option');
        option.value = String(i);
        option.textContent = name;
        idSelect.appendChild(option);
      });
      const guessed = headers.map((header, i) => ({
        normalized: String(header).toLowerCase().replace(/[\s_\-\u200c]/g, ''), index: i
      })).filter((col) => ['کدملی', 'nationalid', 'nationalcode'].includes(col.normalized));
      if (guessed.length === 1) setNational(guessed[0].index);
      mapping.hidden = false;
      compose.hidden = false;
      show(`${info.rows} ردیف و ${headers.length} ستون شناسایی شد. ${info.malformed ? `${info.malformed} ردیف CSV ساختار نامعتبر دارد. ` : ''}ستون کد ملی را مشخص و متن پیام را کامل کنید.`);
      checkReady();
    } catch (error) {
      if (ticket !== previewCounter) return;
      reset();
      show(error.message || 'ارتباط با سرور برقرار نشد.', true);
    }
  }
  fileInput.addEventListener('change', inspectFile);
  ['dragenter', 'dragover'].forEach((name) => fileDrop.addEventListener(name, (event) => {
    if (!Array.from(event.dataTransfer?.types || []).includes('Files')) return;
    event.preventDefault();
    fileDrop.classList.add('is-drop-target');
  }));
  ['dragleave', 'dragend'].forEach((name) => fileDrop.addEventListener(name, () => fileDrop.classList.remove('is-drop-target')));
  fileDrop.addEventListener('drop', (event) => {
    if (!event.dataTransfer?.files?.length) return;
    event.preventDefault();
    fileDrop.classList.remove('is-drop-target');
    fileInput.files = event.dataTransfer.files;
    inspectFile();
  });
  fileDrop.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      fileInput.click();
    }
  });
})();
