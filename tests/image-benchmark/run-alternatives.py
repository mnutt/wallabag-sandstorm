#!/usr/bin/env python3
"""Compare alternative AVIF decoders sequentially in the same VM."""
import json,pathlib,statistics,subprocess,sys
root=pathlib.Path(sys.argv[1])
node=str(next(root.glob('node-*-linux-x64/bin/node')))
scripts=pathlib.Path(__file__).resolve().parent
results=[]
for sample in ['article.avif','2.2096w.avif','13.1088w.avif']:
    for runtime in ['GD','jSquash','icodec','dav1d']:
        if runtime=='GD':
            cmd=['php',str(scripts/'decode.php'),str(root/sample),'30']
        elif runtime=='jSquash':
            cmd=[node,str(scripts/'decode.mjs'),str(root),'avif',str(root/sample),'30']
        else:
            cmd=[node,str(scripts/'decode-alternative.mjs'),str(root),runtime,str(root/sample),'30']
        runs=[json.loads(subprocess.check_output(cmd,text=True,timeout=90)) for _ in range(3)]
        result={'sample':sample,'runtime':runtime,'width':runs[0]['width'],'height':runs[0]['height'],
                **{key:round(statistics.median(r[key] for r in runs),2) for key in ['first_ms','median_ms','p95_ms','rss_kib']},'runs':runs}
        results.append(result)
        print(json.dumps({k:v for k,v in result.items() if k!='runs'}),flush=True)
        (root/'alternatives.json').write_text(json.dumps(results,indent=2)+'\n')
