// Disposable-grain checks for retained errors and cancellation of the wake lock.
const {chromium} = require('playwright');
const assert = require('node:assert/strict');
(async () => {
  const browser = await chromium.launch({executablePath:'/usr/bin/chromium',headless:true,
    args:['--no-sandbox','--host-resolver-rules=MAP *.local.sandstorm.io 127.0.0.1, MAP local.sandstorm.io 127.0.0.1']});
  try {
    const context = await browser.newContext({storageState:process.env.SANDSTORM_STORAGE_STATE});
    const page = await context.newPage(); page.setDefaultTimeout(30000);
    const grain = process.env.GRAIN_URL.replace(/\/$/,'');
    await page.goto(grain+'/sandstorm/imports');
    await page.frameLocator('iframe').first().locator('#content').waitFor();
    let frame=page.frames().find(f=>f.url().includes('ui-'));
    const status = () => frame.evaluate(async()=>{
      const html=await (await fetch('/sandstorm/imports')).text();
      const doc = new DOMParser().parseFromString(html,'text/html');
      const count = key => doc.querySelector(`[data-import-count="${key}"] strong`).textContent;
      return `${count('queued')} queued · ${count('processing')} in progress · ${count('failed')} need attention ` + doc.body.textContent;
    });
    const until = async check => {
      for(let i=0;i<60;i++) {const text=await status(); if(check(text))return text; await new Promise(r=>setTimeout(r,500));}
      throw Error('Import status did not settle: '+await status());
    };
    const initialFailed=Number((await status()).match(/(\d+) need attention/)[1]);
    const upload = entry => frame.evaluate(async entry=>{
      const html=await (await fetch('/import/wallabag-v2')).text();
      const doc=new DOMParser().parseFromString(html,'text/html');
      const input=doc.querySelector('input[type=file]'); const form=input.closest('form');
      const data=new FormData(form);
      data.set(input.name,new Blob([JSON.stringify([entry])],{type:'application/json'}),'test.json');
      const response=await fetch(form.getAttribute('action') || '/import/wallabag-v2',{method:'POST',body:data});
      if(!response.ok || !response.redirected)throw Error('Import upload rejected');
    },entry);
    const entry = {url:'https://example.org/control-'+Date.now(),title:'Import control test',
      content:['Malformed HTML input'],mimetype:'text/html',language:'en',is_archived:0,is_starred:0,tags:[]};
    await upload(entry);
    const failedText='0 queued · 0 in progress · '+(initialFailed+1)+' need attention';
    const text=await until(text=>text.includes(failedText));
    const before=Number(text.match(/(\d+) entries processed/)[1]);
    await frame.evaluate(async()=>{
      const html=await (await fetch('/sandstorm/imports')).text();
      const doc=new DOMParser().parseFromString(html,'text/html');
      const data=new URLSearchParams({action:'retry',_token:doc.querySelector('input[name=_token]').value});
      const response=await fetch('/sandstorm/imports',{method:'POST',body:data});
      if(!response.ok || !response.redirected)throw Error('Retry rejected');
    });
    await until(text=>text.includes(failedText) && Number(text.match(/(\d+) entries processed/)[1])>before);
    console.log('PASS: malformed imported content is retained and unsuccessful retries do not discard it.');
    entry.url+='-cancel'; entry.title='Cancellation test';
    entry.content='<p>Saved text.</p><img src="https://example.org/cancellation-test-image.png">';
    await upload(entry);
    await page.goto('http://local.sandstorm.io:6091/apps');
    await page.getByTitle('Notifications',{exact:true}).click();
    const item=page.locator('a.notification-item[href="'+new URL(grain).pathname+'"]')
      .filter({has:page.getByRole('button',{name:'Cancel',exact:true})});
    await item.getByRole('button',{name:'Cancel',exact:true}).click();
    await page.goto(grain+'/sandstorm/imports');
    await page.frameLocator('iframe').first().locator('#content').waitFor();
    frame=page.frames().find(f=>f.url().includes('ui-'));
    const cancelled=await until(text=>text.includes('Imports are paused') && text.includes('0 in progress'));
    assert.ok(cancelled.includes('1 queued · 0 in progress · '+(initialFailed+1)+' need attention'),cancelled);
    console.log('PASS: cancelling the Sandstorm notification pauses processing and preserves the interrupted job.');
  } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
