import {readFileSync} from 'node:fs';
import {pathToFileURL} from 'node:url';
import {performance} from 'node:perf_hooks';
const [root, format, file, count='30'] = process.argv.slice(2);
const bytes = readFileSync(file);
const input = bytes.buffer.slice(bytes.byteOffset, bytes.byteOffset + bytes.byteLength);
// No browser APIs or privileged imports are provided to the decoder.
globalThis.ImageData = class ImageData {
  constructor(data, width, height) { this.data=data; this.width=width; this.height=height; }
};
const start = performance.now();
const dir = root + '/node_modules/@jsquash/' + format;
const {default:decode, init} = await import(pathToFileURL(dir + '/decode.js'));
const wasm = readFileSync(dir + '/codec/dec/' + (format === 'jpeg' ? 'mozjpeg' : 'avif') + '_dec.wasm');
await init({wasmBinary:wasm});
let image = await decode(input);
const first = performance.now() - start;
const {width, height} = image;
if (image.data.length !== width * height * 4) throw Error('Invalid pixels');
image = null;
for (let i=0;i<5;i++) await decode(input);
const times=[];
for (let i=0;i<Number(count);i++) {
  const start=performance.now();
  image=await decode(input);
  times.push(performance.now()-start);
  if (image.width !== width || image.height !== height) throw Error('Invalid dimensions');
  image=null;
}
times.sort((a,b)=>a-b);
console.log(JSON.stringify({decoder:'jSquash WASM',width,height,bytes:bytes.length,first_ms:first,median_ms:times[Math.floor(times.length/2)],p95_ms:times[Math.floor(times.length*.95)],rss_kib:process.resourceUsage().maxRSS}));
