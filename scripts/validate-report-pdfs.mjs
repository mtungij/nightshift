import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import path from 'node:path';
import {execFileSync} from 'node:child_process';
const directory=process.argv[2]||'/tmp/nightshift-report-pdf-browser';
const results=JSON.parse(await fs.readFile(path.join(directory,'result.json'),'utf8'));
let pages=0,words=0;
const attrs=text=>Object.fromEntries([...text.matchAll(/(\w+)="([^"]*)"/g)].map(m=>[m[1],Number(m[2])]));
for(const artifact of results.artifacts){
 const file=path.join(directory,artifact.key+'.pdf');
 const xml=execFileSync('pdftotext',['-bbox-layout',file,'-'],{encoding:'utf8'});
 const list=[...xml.matchAll(/<page\b([^>]*)>([\s\S]*?)<\/page>/g)];assert.ok(list.length>0,artifact.key+' has pages');
 for(const page of list){const {width,height}=attrs(page[1]);assert.ok((Math.abs(width-595)<2&&Math.abs(height-842)<2)||(Math.abs(width-842)<2&&Math.abs(height-595)<2),artifact.key+' A4');pages++;
  for(const word of page[2].matchAll(/<word\b([^>]*)>/g)){const b=attrs(word[1]);assert.ok(b.xMin>=0&&b.xMax<=width+1&&b.yMin>=0&&b.yMax<=height+1,artifact.key+' text inside page bounds '+word[1]);words++;}}
 const text=execFileSync('pdftotext',['-layout',file,'-'],{encoding:'utf8'});await fs.writeFile(path.join(directory,artifact.key+'.txt'),text);
 assert.ok(text.includes('NIGHTSHIFT'),artifact.key+' branding');assert.ok(text.includes(artifact.key.startsWith('sw-')?'Ukurasa':'Page'),artifact.key+' page numbers');
 assert.ok(!text.includes('SYNTHETIC_UI_PIN_SECRET')&&!text.includes('SYNTHETIC_UI_API_SECRET'),artifact.key+' redacted');
 if(artifact.key.endsWith('restaurant-filtered')){
  assert.ok(text.includes('2500.00')&&!text.includes('2000.00'),artifact.key+' unit totals');
  const screen=JSON.parse(await fs.readFile(path.join(directory,artifact.key.slice(0,2)+'-restaurant-screen.json'),'utf8'));
  const csv=await fs.readFile(path.join(directory,artifact.key.slice(0,2)+'-restaurant.csv'),'utf8');
  for(const row of screen)for(const column of [0,3,4,5]){assert.ok(text.includes(row[column]),artifact.key+' PDF screen match '+row[column]);assert.ok(csv.includes(row[column]),artifact.key+' CSV screen match '+row[column]);}
 }
}
const result={result:'PASS',files:results.artifacts.length,pages,boundedWords:words,screenCsvPdfReconciliation:true,redaction:true};
await fs.writeFile(path.join(directory,'pdf-validation.json'),JSON.stringify(result,null,2));console.log(JSON.stringify(result));
