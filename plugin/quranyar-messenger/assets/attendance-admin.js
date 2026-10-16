(() => {
  'use strict';
  function renderPosters(){
    document.querySelectorAll('[data-qys-qr]').forEach(el => {
      if(el.dataset.rendered) return;
      const payload=el.getAttribute('data-qys-qr');
      if(!payload||!window.QYSQRCode){el.textContent='بارگذاری QR انجام نشد؛ صفحه را تازه‌سازی کنید.';return;}
      try{el.innerHTML=window.QYSQRCode.svg(payload);el.dataset.rendered='1';}
      catch(error){el.textContent='ساخت QR انجام نشد؛ از کد جایگزین استفاده کنید.';}
    });
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',renderPosters);
  else renderPosters();
})();
