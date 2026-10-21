#!/usr/bin/env python3
"""Run sequentially in the VM, with packages and fixtures under the supplied temp root."""
import json, pathlib, statistics, subprocess, sys, time
root=pathlib.Path(sys.argv[1])
node=str(next(root.glob('node-*-linux-x64/bin/node')))
scripts=pathlib.Path(__file__).resolve().parent
samples=['article.jpg','article.avif','2.2096w.jpg','2.2096w.avif','13.1088w.jpg','13.1088w.avif']
results=[]
for sample in samples:
    path=root/sample
    for runtime in ['GD','WASM']:
        cmd=['php',str(scripts/'decode.php'),str(path),'30'] if runtime=='GD' else [node,str(scripts/'decode.mjs'),str(root),'avif' if sample.endswith('avif') else 'jpeg',str(path),'30']
        runs=[]
        for _ in range(3):
            begin=time.perf_counter()
            run=json.loads(subprocess.check_output(cmd,text=True))
            run['process_ms']=(time.perf_counter()-begin)*1000
            runs.append(run)
        result={'sample':sample,'runtime':runtime,'width':runs[0]['width'],'height':runs[0]['height'],
                **{key:round(statistics.median(r[key] for r in runs),2) for key in ['first_ms','median_ms','p95_ms','rss_kib']},'runs':runs}
        results.append(result)
        print(json.dumps({k:v for k,v in result.items() if k!='runs'}),flush=True)
(root/'results.json').write_text(json.dumps(results,indent=2)+'\n')
