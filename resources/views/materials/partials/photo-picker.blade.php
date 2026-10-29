{{--
    Photos for a customer's material: take them with the camera (webcam on a computer, front or
    back camera on a phone/tablet) or choose pictures. Large pictures are made smaller before
    upload. Everything ends up in the form's photos[] field.

    @include('materials.partials.photo-picker', ['max' => 6, 'autoSubmit' => false])
--}}
@php($pid = 'pp' . substr(md5(uniqid('', true)), 0, 6))
<div class="fh-photos" id="{{ $pid }}" data-max="{{ $max }}" data-auto="{{ ! empty($autoSubmit) ? 1 : 0 }}">
    <input type="file" name="photos[]" accept="image/*" multiple class="d-none" data-role="field">
    <input type="file" accept="image/*" multiple class="d-none" data-role="choose">
    <input type="file" accept="image/*" capture="environment" class="d-none" data-role="native-camera">

    <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-primary btn-sm" data-role="take"><i class="ri-camera-3-line me-1"></i>Take photo</button>
        <button type="button" class="btn btn-light btn-sm" data-role="pick"><i class="ri-image-add-line me-1"></i>Choose pictures</button>
        <span class="small text-muted align-self-center" data-role="hint">Up to {{ $max }} — the whole item, the label or selvedge, and any marks.</span>
    </div>
    <div class="d-flex flex-wrap gap-2 mt-2" data-role="preview"></div>

    <div class="modal fade" tabindex="-1" aria-hidden="true" data-role="modal">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content bg-dark text-white border-0 overflow-hidden">
                <div class="position-relative" style="background:#000">
                    <video playsinline autoplay muted class="w-100 d-block" style="max-height:70vh;object-fit:contain" data-role="video"></video>
                    <div class="position-absolute top-0 start-0 end-0 p-2 d-flex justify-content-between">
                        <button type="button" class="btn btn-sm btn-dark bg-opacity-50 rounded-pill" data-bs-dismiss="modal"><i class="ri-close-line"></i></button>
                        <button type="button" class="btn btn-sm btn-dark bg-opacity-50 rounded-pill d-none" data-role="flip" title="Switch camera"><i class="ri-camera-switch-line"></i></button>
                    </div>
                    <div class="position-absolute top-50 start-50 translate-middle text-center small d-none" data-role="cam-error" style="max-width:80%"></div>
                    <div class="fh-flash" data-role="flash"></div>
                </div>
                <div class="d-flex align-items-center gap-3 p-3">
                    <div class="d-flex gap-2 flex-grow-1 overflow-auto" data-role="shots"></div>
                    <button type="button" class="fh-shutter" data-role="shutter" title="Take photo" aria-label="Take photo"></button>
                    <button type="button" class="btn btn-light btn-sm flex-shrink-0" data-role="done" disabled>Use photos</button>
                </div>
            </div>
        </div>
    </div>
</div>

@once
<style>
    .fh-photos .fh-thumb { position: relative; width: 84px; height: 84px; }
    .fh-photos .fh-thumb img { width: 84px; height: 84px; object-fit: cover; border-radius: 10px; animation: fhPop .25s ease-out; }
    .fh-photos .fh-thumb button { position: absolute; top: -6px; right: -6px; width: 22px; height: 22px; border-radius: 50%; border: 0; background: #111; color: #fff; font-size: 13px; line-height: 22px; padding: 0; }
    .fh-photos [data-role=shots] img { width: 54px; height: 54px; object-fit: cover; border-radius: 8px; flex: 0 0 auto; animation: fhPop .25s ease-out; }
    .fh-shutter { width: 64px; height: 64px; border-radius: 50%; border: 4px solid #fff; background: #fff; box-shadow: inset 0 0 0 3px #000; flex: 0 0 auto; transition: transform .1s; }
    .fh-shutter:active { transform: scale(.92); }
    .fh-shutter:disabled { opacity: .4; }
    .fh-flash { position: absolute; inset: 0; background: #fff; opacity: 0; pointer-events: none; transition: opacity .25s; }
    .fh-flash.on { opacity: .85; transition: none; }
    @keyframes fhPop { from { transform: scale(.7); opacity: 0; } to { transform: scale(1); opacity: 1; } }
</style>
<script>
window.FhPhotos = window.FhPhotos || function (root) {
    const $ = r => root.querySelector('[data-role=' + r + ']');
    const max = +root.dataset.max, auto = root.dataset.auto === '1';
    const field = $('field'), preview = $('preview'), shots = $('shots'), video = $('video'), done = $('done'), shutter = $('shutter');
    const modalEl = $('modal'); document.body.appendChild(modalEl);   // keep the modal out of the form's layout
    const modal = new bootstrap.Modal(modalEl);
    let files = [], taken = [], stream = null, facing = 'environment';

    function sync() {
        const dt = new DataTransfer(); files.forEach(f => dt.items.add(f)); field.files = dt.files;
        preview.innerHTML = '';
        files.forEach((f, i) => {
            const d = document.createElement('div'); d.className = 'fh-thumb';
            d.innerHTML = '<img alt=""><button type="button" title="Remove">×</button>';
            d.querySelector('img').src = URL.createObjectURL(f);
            d.querySelector('button').onclick = () => { files.splice(i, 1); sync(); };
            preview.appendChild(d);
        });
        $('take').disabled = $('pick').disabled = files.length >= max;
        if (auto && files.length) field.form.submit();
    }
    function add(list) { files = files.concat(list).slice(0, max); sync(); }

    /** Phones take 4000px pictures; 1600px is plenty and uploads in a moment. */
    function shrink(file) {
        return new Promise(resolve => {
            if (!/^image\/(jpeg|png|webp)$/.test(file.type) || file.size < 900 * 1024) return resolve(file);
            const img = new Image();
            img.onload = () => {
                const s = Math.min(1, 1600 / Math.max(img.width, img.height));
                const c = document.createElement('canvas'); c.width = Math.round(img.width * s); c.height = Math.round(img.height * s);
                c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
                c.toBlob(b => resolve(b ? new File([b], file.name.replace(/\.\w+$/, '') + '.jpg', { type: 'image/jpeg' }) : file), 'image/jpeg', 0.86);
            };
            img.onerror = () => resolve(file);   // e.g. HEIC on some browsers: upload as it is
            img.src = URL.createObjectURL(file);
        });
    }
    async function addFiles(list) { add(await Promise.all([...list].map(shrink))); }

    $('pick').onclick = () => $('choose').click();
    $('choose').onchange = e => { addFiles(e.target.files); e.target.value = ''; };
    $('native-camera').onchange = e => { addFiles(e.target.files); e.target.value = ''; };

    async function start() {
        stop();
        const err = $('cam-error'); err.classList.add('d-none');
        try {
            stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: facing }, width: { ideal: 1920 }, height: { ideal: 1080 } }, audio: false });
            video.srcObject = stream; shutter.disabled = false;
            const cams = (await navigator.mediaDevices.enumerateDevices()).filter(d => d.kind === 'videoinput');
            $('flip').classList.toggle('d-none', cams.length < 2);
        } catch (e) {
            shutter.disabled = true;
            err.textContent = e && e.name === 'NotAllowedError'
                ? 'Camera access was blocked. Allow the camera for this site in the browser (the camera icon in the address bar), then try again.'
                : 'No camera found. Use “Choose pictures” instead.';
            err.classList.remove('d-none');
        }
    }
    function stop() { if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; } }

    $('take').onclick = () => {
        // Older browsers, or a page not on https: the phone's own camera app instead.
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || !window.isSecureContext) return $('native-camera').click();
        taken = []; shots.innerHTML = ''; done.disabled = true; modal.show();
    };
    modalEl.addEventListener('shown.bs.modal', start);
    modalEl.addEventListener('hidden.bs.modal', stop);
    $('flip').onclick = () => { facing = facing === 'environment' ? 'user' : 'environment'; start(); };

    shutter.onclick = () => {
        if (!stream || files.length + taken.length >= max) return;
        const c = document.createElement('canvas'), s = Math.min(1, 1600 / Math.max(video.videoWidth, video.videoHeight));
        c.width = Math.round(video.videoWidth * s); c.height = Math.round(video.videoHeight * s);
        c.getContext('2d').drawImage(video, 0, 0, c.width, c.height);
        const flash = $('flash'); flash.classList.add('on'); requestAnimationFrame(() => requestAnimationFrame(() => flash.classList.remove('on')));
        c.toBlob(b => {
            const f = new File([b], 'photo-' + Date.now() + '.jpg', { type: 'image/jpeg' });
            taken.push(f);
            const img = document.createElement('img'); img.src = URL.createObjectURL(b); shots.appendChild(img);
            done.disabled = false; done.textContent = 'Use ' + taken.length + (taken.length === 1 ? ' photo' : ' photos');
            shutter.disabled = files.length + taken.length >= max;
        }, 'image/jpeg', 0.88);
    };
    done.onclick = () => { modal.hide(); add(taken); taken = []; };
};
document.addEventListener('DOMContentLoaded', () => document.querySelectorAll('.fh-photos').forEach(r => window.FhPhotos(r)));
</script>
@endonce
