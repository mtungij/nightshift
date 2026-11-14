import {t} from './localization.js';
export async function downloadPdf(url, trigger) {
    if(trigger?.dataset.pdfBusy)return;
    if(trigger){trigger.dataset.pdfBusy='1';trigger.setAttribute('aria-busy','true');}
    try {
        const response=await fetch(url,{credentials:'same-origin',headers:{Accept:'application/json'},cache:'no-store'});
        if(!response.ok){const error=await response.json().catch(()=>({}));throw Error(Object.values(error.errors||{}).flat()[0]||error.message||t('PDF generation is unavailable. Please try again.'));}
        if(!response.headers.get('Content-Type')?.includes('application/pdf'))throw Error(t('PDF generation is unavailable. Please try again.'));
        const blob=await response.blob(),object=URL.createObjectURL(blob),link=document.createElement('a');
        link.href=object;link.download=(response.headers.get('Content-Disposition')?.match(/filename="([^"]+)"/)?.[1]||'nightshift-report.pdf').replace(/[\\/]/g,'-');
        document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(object),30000);
    }catch(error){
        window.dispatchEvent(new CustomEvent('nightshift:theme-error',{detail:error.message}));
        if(trigger){let status=document.querySelector('[data-pdf-status]');if(!status){status=document.createElement('p');status.dataset.pdfStatus='';status.setAttribute('role','alert');trigger.parentElement.after(status);}status.textContent=error.message;}
    }finally{if(trigger){delete trigger.dataset.pdfBusy;trigger.removeAttribute('aria-busy');}}
}
document.addEventListener('click',event=>{const link=event.target.closest('[data-report-pdf]');if(link){event.preventDefault();downloadPdf(link.href,link);}});
