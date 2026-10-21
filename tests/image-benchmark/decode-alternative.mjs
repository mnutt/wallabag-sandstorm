import {readFileSync} from 'node:fs';
import {pathToFileURL} from 'node:url';
import {performance} from 'node:perf_hooks';
const [root, candidate, file, count='30'] = process.argv.slice(2);
const bytes = readFileSync(file);
globalThis.ImageData = class ImageData {
  constructor(data, width, height) { this.data=data; this.width=width; this.height=height; }
};
const start=performance.now();
let decode;
if (candidate === 'icodec') {
  const {avif}=await import(pathToFileURL(root+'/node_modules/icodec/lib/node.js'));
  await avif.loadDecoder();
  decode=() => avif.decode(bytes);
} else if (candidate === 'dav1d') {
  const {optimizeImage}=await import(pathToFileURL(root+'/node_modules/wasm-image-optimization/dist/single.js'));
  decode=() => optimizeImage({image:bytes, format:'raw', animation:false});
} else { throw Error('Unknown candidate'); }
let image=await decode();
const first=performance.now()-start;
const {width,height}=image;
if (image.data.length !== width*height*4) throw Error('Invalid pixel length');
const samples=Array.from({length:16},(_,i) => {
  const offset=Math.floor((i+.5)*width*height/16)*4;
  return Array.from(image.data.slice(offset,offset+4));
});
image=null;
for(let i=0;i<5;i++) await decode();
const times=[];
for(let i=0;i<Number(count);i++) {
  const begin=performance.now();
  image=await decode();
  times.push(performance.now()-begin);
  if(image.width!==width || image.height!==height || image.data.length!==width*height*4) throw Error('Invalid output');
  image=null;
}
times.sort((a,b)=>a-b);
console.log(JSON.stringify({decoder:candidate,width,height,bytes:bytes.length,first_ms:first,median_ms:times[Math.floor(times.length/2)],p95_ms:times[Math.floor(times.length*.95)],rss_kib:process.resourceUsage().maxRSS,samples}));
