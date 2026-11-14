import { spawn } from 'node:child_process';
import fs from 'node:fs/promises';
import path from 'node:path';
const [directory, binary, noSandbox, locale, seconds = '90'] = process.argv.slice(2);
if (!directory || !binary) throw Error('Private input directory and Chrome binary are required.');
const args = ['--headless=new','--disable-gpu','--no-first-run','--no-default-browser-check','--disable-background-networking','--disable-component-update','--disable-extensions','--disable-sync','--disable-dev-shm-usage','--remote-debugging-pipe',`--user-data-dir=${path.join(directory,'chrome')}`];
if (noSandbox === '1') args.push('--no-sandbox');
const chrome = spawn(binary,args,{stdio:['ignore','ignore','pipe','pipe','pipe']});
let id=0,buffer=Buffer.alloc(0);const pending=new Map();
chrome.stderr.resume();
// Terminate the browser before the PHP process deadline, including on worker shutdown.
const terminate=()=>{chrome.kill('SIGTERM');setTimeout(()=>{chrome.kill('SIGKILL');process.exit(1);},1000).unref();};
const deadline=setTimeout(terminate,Math.max(1000,Number(seconds)*1000-2000));deadline.unref();
process.once('SIGTERM',terminate);process.once('SIGINT',terminate);

function fail(error){for(const p of pending.values()){clearTimeout(p.timer);p.reject(error);}pending.clear();}
chrome.on('error',fail);chrome.on('exit',code=>fail(Error('PDF browser exited: '+code)));
chrome.stdio[4].on('data',chunk=>{
 buffer=Buffer.concat([buffer,chunk]);let end;
 while((end=buffer.indexOf(0))>=0){const line=buffer.subarray(0,end).toString();buffer=buffer.subarray(end+1);if(!line)continue;const m=JSON.parse(line);const p=pending.get(m.id);if(p){pending.delete(m.id);clearTimeout(p.timer);m.error?p.reject(Error(m.error.message)):p.resolve(m.result);}}
});
function send(method,params={},sessionId){return new Promise((resolve,reject)=>{const seq=++id;const timer=setTimeout(()=>{pending.delete(seq);reject(Error('PDF browser timed out'));},60000);pending.set(seq,{resolve,reject,timer});chrome.stdio[3].write(JSON.stringify({id:seq,method,params,...(sessionId?{sessionId}:{})})+'\0');});}
try{
 await send('Browser.getVersion');const {targetId}=await send('Target.createTarget',{url:'about:blank'});const {sessionId}=await send('Target.attachToTarget',{targetId,flatten:true});
 const c=(method,params={})=>send(method,params,sessionId);
 await c('Page.enable');await c('Network.enable');await c('Network.setBlockedURLs',{urls:['http://*','https://*','file://*','ftp://*']});
 await c('Emulation.setScriptExecutionDisabled',{value:true});
 const {frameTree}=await c('Page.getFrameTree');const html=await fs.readFile(path.join(directory,'input.html'),'utf8');
 await c('Page.setDocumentContent',{frameId:frameTree.frame.id,html});
 // Print rendering waits for inline fonts/images; no network resources or user scripts are permitted.
 await new Promise(r=>setTimeout(r,200));
 const label=locale==='sw'?'Ukurasa':'Page';
 const {data}=await c('Page.printToPDF',{printBackground:true,preferCSSPageSize:true,displayHeaderFooter:true,headerTemplate:'<span></span>',footerTemplate:`<div style="font:8px Arial;color:#555;text-align:center;width:100%">${label} <span class="pageNumber"></span> / <span class="totalPages"></span></div>`,marginTop:0.5,marginBottom:0.6,marginLeft:0.4,marginRight:0.4,generateTaggedPDF:true});
 await fs.writeFile(path.join(directory,'output.pdf'),Buffer.from(data,'base64'));
}finally{clearTimeout(deadline);chrome.kill('SIGTERM');await new Promise(resolve=>{if(chrome.exitCode!==null)return resolve();const t=setTimeout(()=>{chrome.kill('SIGKILL');resolve();},1500);chrome.once('exit',()=>{clearTimeout(t);resolve();});});}
