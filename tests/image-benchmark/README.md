# GD versus WASM decode benchmark

Measured sequentially inside the app's Lima VM: 4 virtual CPUs, AMD Ryzen 7 8745HS,
x86-64. No runtime dependencies were added to the grain.

Native: PHP 8.2.34, GD 2.3.3-9+deb12u1, libavif 0.11.1-1+deb12u1,
libdav1d 1.0.0-2+deb12u1, libjpeg-turbo 2.1.5.
WASM: temporary Node 22.23.3, @jsquash/jpeg 1.6.0, @jsquash/avif 2.1.1.

Identical compressed bytes go to each decoder. Each process performs one initial
decode, five warmups, then 30 measured decodes. Three fresh processes per case.
The table reports medians of the per-process medians. Dimensions and WASM RGBA
length are checked, not pixel-by-pixel equivalence.

First-decode times include JS module import and WASM compilation, but exclude
process startup and reading compressed input in both cases. Peak RSS covers the
whole batch and includes the PHP/Node runtime. Native image destruction is outside
timing; WASM's API includes returning an RGBA array and automatic GC. These are
different decoder builds and output APIs, not a controlled measurement of WASM
overhead. No network, resizing, encoding, or GD/WASM pixel transfer is timed.
This also does not test running a WASM runtime inside the grain.

| Input | Dimensions | GD ms | WASM ms | Ratio | GD first ms | WASM first ms | GD peak MiB | WASM peak MiB |
|---|---:|---:|---:|---:|---:|---:|---:|---:|
| article.jpg | 1088 × 726 | 3.12 | 5.43 | 1.7× | 6.11 | 17.48 | 42.3 | 107.8 |
| article.avif | 1088 × 726 | 5.45 | 53.04 | 9.7× | 9.97 | 122.40 | 54.0 | 161.4 |
| 2.2096w.jpg | 2096 × 1398 | 14.56 | 24.11 | 1.7× | 20.78 | 37.63 | 58.5 | 152.6 |
| 2.2096w.avif | 2096 × 1398 | 23.11 | 208.14 | 9.0× | 32.77 | 307.64 | 97.5 | 225.0 |
| 13.1088w.jpg | 1088 × 1632 | 8.33 | 14.70 | 1.8× | 9.08 | 26.46 | 49.8 | 117.7 |
| 13.1088w.avif | 1088 × 1632 | 9.78 | 104.32 | 10.7× | 17.14 | 170.50 | 73.0 | 176.7 |

Raw measurements: [results.json](results.json).

## Reproduce inside the VM

Create /tmp/wallabag-image-bench. Put a checksum-verified official Node 22.23.3
linux-x64 distribution there. In that directory, use its npm to install
@jsquash/jpeg@1.6.0 and @jsquash/avif@2.1.1 with --ignore-scripts.

Images come from https://unsung.aresluna.org/_media/good-type-against-all-odds/:
save 2.1088w.jpg and 2.1088w.avif as article.jpg and article.avif; retain original
names for 13.1088w.jpg, 13.1088w.avif and 2.2096w.avif. There is no 2096 JPEG at
the site, so generate 2.2096w.jpg once using GD imagecreatefromavif followed by
imagejpeg at quality 90. Both decoders receive that same generated JPEG.

Run from /opt/app:
python3 tests/image-benchmark/run.py /tmp/wallabag-image-bench

Downloaded photographs stay in /tmp and are not committed.
verify-article.php additionally expects the article HTML in
.sandstorm/caches/unsung-article.html. It checks all 12 extracted image URLs and
resizing/thumbnail creation from the actual downloaded AVIF.

The native libavif library reports dav1d as its preferred decoder, queried with
avifCodecName(AVIF_CODEC_CHOICE_AUTO, AVIF_CODEC_FLAG_CAN_DECODE).
jSquash documents limited Node support and derives its codecs from Squoosh:
https://github.com/jamsinclair/jSquash

## Alternative AVIF builds

The follow-up uses icodec 0.6.0 (libavif 1.1.1 + libaom 3.11.0-rc1,
requiring WASM SIMD) and wasm-image-optimization 2.0.11 (advertised dav1d
decoder through Skia, with raw RGBA output). Neither is added to the app.

Sources:
- https://github.com/Kaciras/icodec
- https://github.com/node-libraries/wasm-image-optimization

Install those exact npm versions under the same VM-local temporary root with
--ignore-scripts, then run:
python3 tests/image-benchmark/run-alternatives.py /tmp/wallabag-image-bench

The follow-up reruns GD and jSquash as controls. All four implementations run
sequentially, with three independent processes, five warmups and 30 measured
decodes each. The dav1d candidate uses format: raw, without width or height
options, so it does not resize or encode. Its wrapper and pixel-copy overhead
are included. This benchmark does not test transferring those pixels into GD.

Alternative outputs include 16 sampled RGBA pixels for independent comparison
against check-pixels.php. This is a sanity check, not full decoder conformance.

### Follow-up results

| Image | GD ms | Original jSquash ms | icodec SIMD ms | dav1d wrapper ms |
|---|---:|---:|---:|---:|
| article.avif | 5.41 | 53.08 | 27.87 | 31.37 |
| 2.2096w.avif | 23.20 | 209.98 | 108.35 | 119.20 |
| 13.1088w.avif | 9.83 | 103.88 | 55.47 | 64.51 |

icodec was 1.87–1.94 times faster than the original jSquash build, but still
4.67–5.64 times slower than native GD. The dav1d wrapper was 1.61–1.76 times
faster than jSquash. Decoder identity alone did not predict speed here.

On 16 sampled RGBA pixels per image, icodec matched GD exactly. The dav1d
wrapper differed by at most 4/255 in any channel. This is a limited sanity check,
not a claim of full-image equivalence. See pixel-checks.json.

Raw repeated measurements, first-decode timings and whole-process peak RSS are
in alternatives.json. As before, these figures exclude resizing, encoding and
transfer of raw pixels into GD. App processing remains on GD.
