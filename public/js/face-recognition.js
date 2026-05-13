'use strict';

// ── Configuration ─────────────────────────────────────────────────────────
const FR_CDN       = 'https://cdn.jsdelivr.net/npm/face-api.js@0.22.2/dist/face-api.min.js';
const FR_MODELS    = 'https://justadudewhohacks.github.io/face-api.js/models';
const FR_ENROLL_N  = 5;   // frames averaged for enrollment
const FR_LOGIN_N   = 3;   // frames averaged for login

// ── Module state ──────────────────────────────────────────────────────────
let _scriptLoaded  = false;
let _modelsLoaded  = false;
let _stream        = null;

// ── Helpers ───────────────────────────────────────────────────────────────

function _showStatus(id, html, type) {
    const el = document.getElementById(id);
    if (!el) return;
    const palette = {
        info:    '#eff6ff|#2563eb|#1e40af',
        success: '#f0fdf4|#16a34a|#15803d',
        error:   '#fef2f2|#dc2626|#b91c1c',
        loading: '#fefce8|#ca8a04|#854d0e',
    }[type] || 'info';
    const [bg, bd, fg] = palette.split('|');
    el.style.cssText = `display:block;padding:10px 14px;border-radius:8px;font-size:13px;margin-top:10px;background:${bg};border:1px solid ${bd};color:${fg};`;
    el.innerHTML = html;
}

async function _loadScript() {
    if (_scriptLoaded || window.faceapi) { _scriptLoaded = true; return; }
    await new Promise((res, rej) => {
        const s = document.createElement('script');
        s.src = FR_CDN;
        s.onload  = () => { _scriptLoaded = true; res(); };
        s.onerror = () => rej(new Error('Impossible de charger face-api.js depuis le CDN.'));
        document.head.appendChild(s);
    });
}

async function _loadModels(onMsg) {
    if (_modelsLoaded) return;
    onMsg('Chargement modèle détection (1/3)…');
    await faceapi.nets.tinyFaceDetector.loadFromUri(FR_MODELS);
    onMsg('Chargement modèle repères (2/3)…');
    await faceapi.nets.faceLandmark68TinyNet.loadFromUri(FR_MODELS);
    onMsg('Chargement modèle reconnaissance (3/3)…');
    await faceapi.nets.faceRecognitionNet.loadFromUri(FR_MODELS);
    _modelsLoaded = true;
}

async function _openCamera(video) {
    _stopCamera();
    _stream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'user', width: { ideal: 320 }, height: { ideal: 240 } },
    });
    video.srcObject = _stream;
    await new Promise(r => { video.onloadedmetadata = r; });
    video.play();
}

function _stopCamera() {
    if (_stream) { _stream.getTracks().forEach(t => t.stop()); _stream = null; }
}

async function _captureDescriptor(video) {
    const opts   = new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.5 });
    const result = await faceapi.detectSingleFace(video, opts).withFaceLandmarks(true).withFaceDescriptor();
    if (!result) throw new Error('Aucun visage détecté — repositionnez-vous et réessayez.');
    return Array.from(result.descriptor);
}

async function _captureAverage(video, n, onMsg) {
    const descriptors = [];
    for (let i = 0; i < n; i++) {
        onMsg(`Capture ${i + 1}/${n}…`);
        descriptors.push(await _captureDescriptor(video));
        if (i < n - 1) await new Promise(r => setTimeout(r, 500));
    }
    const avg = new Array(128).fill(0);
    for (const d of descriptors) d.forEach((v, j) => { avg[j] += v; });
    return avg.map(v => v / n);
}

// ── Modal factory ─────────────────────────────────────────────────────────

function _buildModal(id, title) {
    const old = document.getElementById(id);
    if (old) old.remove();
    const m = document.createElement('div');
    m.id = id;
    m.style.cssText = 'display:none;position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:9500;align-items:center;justify-content:center;';
    m.innerHTML = `
      <div style="background:#fff;border-radius:16px;padding:28px;max-width:420px;width:92%;box-shadow:0 24px 64px rgba(0,0,0,.3);font-family:inherit;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
          <h3 style="margin:0;font-size:15px;font-weight:700;color:#0f172a;">
            <i class="fa-solid fa-camera" style="color:#2563eb;margin-right:8px;"></i>${title}
          </h3>
          <button onclick="_frCloseModal('${id}')" style="background:none;border:none;cursor:pointer;color:#94a3b8;font-size:20px;line-height:1;padding:0;">✕</button>
        </div>
        <p style="color:#64748b;font-size:13px;margin:0 0 14px;">
          <i class="fa-solid fa-circle-info" style="margin-right:5px;"></i>
          Aucune image n'est envoyée — seul un vecteur mathématique de 128 valeurs est transmis.
        </p>
        <div style="position:relative;width:100%;aspect-ratio:4/3;background:#0f172a;border-radius:10px;overflow:hidden;margin-bottom:12px;">
          <video id="${id}-vid" autoplay muted playsinline style="width:100%;height:100%;object-fit:cover;transform:scaleX(-1);display:block;"></video>
          <div id="${id}-ovl" style="position:absolute;bottom:10px;left:0;right:0;text-align:center;color:#fff;font-size:12px;font-weight:600;text-shadow:0 1px 4px rgba(0,0,0,.9);padding:0 12px;"></div>
        </div>
        <div id="${id}-status" style="display:none;"></div>
        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:14px;">
          <button id="${id}-cancel" onclick="_frCloseModal('${id}')" style="padding:9px 20px;border-radius:8px;border:1px solid #e2e8f0;background:#fff;cursor:pointer;font-size:13px;color:#374151;">Annuler</button>
          <button id="${id}-action" disabled style="padding:9px 20px;border-radius:8px;border:none;background:#2563eb;color:#fff;cursor:pointer;font-size:13px;font-weight:600;opacity:.6;">Patienter…</button>
        </div>
      </div>`;
    document.body.appendChild(m);
    return m;
}

function _frOpenModal(id)  { const m = document.getElementById(id); if (m) m.style.display = 'flex'; }
function _frCloseModal(id) { _stopCamera(); const m = document.getElementById(id); if (m) m.style.display = 'none'; }

// ── ENROLLMENT ────────────────────────────────────────────────────────────

async function enrollFaceRecognition() {
    const ID = 'fr-enroll-modal';
    _buildModal(ID, 'Configurer la reconnaissance faciale');
    _frOpenModal(ID);

    const vid     = document.getElementById(`${ID}-vid`);
    const ovl     = document.getElementById(`${ID}-ovl`);
    const actBtn  = document.getElementById(`${ID}-action`);
    const canBtn  = document.getElementById(`${ID}-cancel`);
    const setOvl  = t => { if (ovl) ovl.textContent = t; };
    const setMsg  = (h, t) => _showStatus(`${ID}-status`, h, t);
    const ready   = () => { actBtn.disabled = false; actBtn.style.opacity = '1'; };
    const busy    = () => { actBtn.disabled = true;  actBtn.style.opacity = '.6'; canBtn.disabled = true; };
    const unbusy  = () => { actBtn.disabled = false; actBtn.style.opacity = '1'; canBtn.disabled = false; };

    const runCapture = async () => {
        busy();
        actBtn.textContent = 'Analyse…';
        try {
            setMsg('Analyse du visage en cours…', 'loading');
            const descriptor = await _captureAverage(vid, FR_ENROLL_N, t => { setOvl(t); setMsg(t, 'loading'); });
            setMsg('Envoi sécurisé vers le serveur…', 'loading');
            setOvl('Vérification…');
            const r = await fetch('/face-auth/enroll', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ descriptor }),
            });
            const data = await r.json();
            if (!r.ok || !data.success) throw new Error(data.error || 'Erreur serveur.');
            setMsg('<i class="fa-solid fa-check"></i> Reconnaissance faciale configurée avec succès !', 'success');
            setOvl('✓ Enregistrement réussi');
            _stopCamera();
            unbusy();
            actBtn.textContent = 'Fermer';
            actBtn.onclick = () => { _frCloseModal(ID); location.reload(); };
        } catch (err) {
            setMsg('<i class="fa-solid fa-xmark"></i> ' + err.message, 'error');
            setOvl('');
            unbusy();
            actBtn.textContent = 'Réessayer';
            actBtn.onclick = runCapture;
        }
    };

    try {
        actBtn.textContent = 'Patienter…';
        setOvl('Chargement des modèles IA…');
        await _loadScript();
        await _loadModels(t => setOvl(t));
        setOvl('Ouverture de la caméra…');
        await _openCamera(vid);
        await new Promise(r => setTimeout(r, 800));
        setOvl('Positionnez votre visage dans le cadre');
        actBtn.textContent = 'Analyser mon visage';
        ready();
        actBtn.onclick = runCapture;
    } catch (err) {
        _stopCamera();
        setMsg('<i class="fa-solid fa-xmark"></i> ' + err.message, 'error');
        setOvl('Erreur de démarrage');
    }
}

// ── SUPPRESSION ───────────────────────────────────────────────────────────

async function removeFaceDescriptor() {
    if (!confirm('Supprimer la reconnaissance faciale de ce compte ?')) return;
    const btn = document.getElementById('btn-remove-face');
    if (btn) { btn.disabled = true; btn.textContent = '…'; }
    try {
        const r = await fetch('/face-auth/descriptor', { method: 'DELETE' });
        const d = await r.json();
        if (!r.ok) throw new Error(d.error || 'Erreur serveur.');
        location.reload();
    } catch (err) {
        alert('Erreur : ' + err.message);
        if (btn) { btn.disabled = false; btn.textContent = 'Supprimer'; }
    }
}

// ── STATUT PROFIL (chargé au DOMContentLoaded) ────────────────────────────

async function loadFaceStatus() {
    const box = document.getElementById('face-status-display');
    if (!box) return;
    try {
        const r = await fetch('/face-auth/has-face');
        if (!r.ok) { box.innerHTML = '<span style="color:#9ca3af;font-size:13px;">—</span>'; return; }
        const d = await r.json();
        const btn = document.getElementById('btn-enroll-face');
        const rmBtn = document.getElementById('btn-remove-face');
        if (d.enrolled) {
            box.innerHTML = `
              <div style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;font-size:13px;color:#15803d;">
                <i class="fa-solid fa-circle-check"></i>
                <span>Descripteur facial enregistré le <strong>${d.enrolledAt}</strong></span>
              </div>`;
            if (btn)  { btn.textContent = 'Reconfigurer'; }
            if (rmBtn){ rmBtn.style.display = 'inline-flex'; }
        } else {
            box.innerHTML = '<p style="color:#6b7280;font-size:13px;margin:0;">Aucun descripteur facial enregistré.</p>';
            if (rmBtn) rmBtn.style.display = 'none';
        }
    } catch (_) {
        box.innerHTML = '<span style="color:#9ca3af;font-size:13px;">Statut indisponible.</span>';
    }
}

// ── LOGIN AVEC RECONNAISSANCE FACIALE ─────────────────────────────────────

async function loginWithFaceRecognition() {
    const wrapper = document.getElementById('fr-login-email-wrapper');
    const emailIn = document.getElementById('fr-login-email-input');

    // First click: show email field
    if (wrapper && wrapper.style.display === 'none') {
        wrapper.style.display = 'block';
        const pwdInput = document.querySelector('input[name="identifier"]') || document.querySelector('input[name="email"]');
        if (pwdInput && pwdInput.value.trim()) emailIn.value = pwdInput.value.trim();
        emailIn.focus();
        return;
    }

    const email = emailIn ? emailIn.value.trim() : '';
    if (!email) { _showStatus('fr-login-status', 'Saisissez votre adresse email.', 'error'); return; }

    const ID = 'fr-login-modal';
    _buildModal(ID, 'Connexion par reconnaissance faciale');
    _frOpenModal(ID);

    const vid    = document.getElementById(`${ID}-vid`);
    const ovl    = document.getElementById(`${ID}-ovl`);
    const actBtn = document.getElementById(`${ID}-action`);
    const canBtn = document.getElementById(`${ID}-cancel`);
    const setOvl = t => { if (ovl) ovl.textContent = t; };
    const setMsg = (h, t) => _showStatus(`${ID}-status`, h, t);
    const busy   = () => { actBtn.disabled = true; actBtn.style.opacity = '.6'; canBtn.disabled = true; };
    const unbusy = () => { actBtn.disabled = false; actBtn.style.opacity = '1'; canBtn.disabled = false; };

    const runVerify = async () => {
        busy();
        actBtn.textContent = 'Vérification…';
        try {
            setMsg('Analyse du visage en cours…', 'loading');
            const descriptor = await _captureAverage(vid, FR_LOGIN_N, t => { setOvl(t); setMsg(t, 'loading'); });
            setMsg('Vérification de l\'identité…', 'loading');
            const r = await fetch('/face-auth/login/verify', {
                method: 'POST', headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ descriptor }),
            });
            const data = await r.json();
            if (!r.ok || !data.success) throw new Error(data.error || 'Visage non reconnu.');
            setMsg('<i class="fa-solid fa-check"></i> Identité confirmée ! Redirection…', 'success');
            setOvl('✓ Connexion réussie');
            _stopCamera();
            setTimeout(() => { window.location.href = data.redirect; }, 800);
        } catch (err) {
            setMsg('<i class="fa-solid fa-xmark"></i> ' + err.message, 'error');
            setOvl('');
            unbusy();
            actBtn.textContent = 'Réessayer';
            actBtn.onclick = runVerify;
        }
    };

    try {
        actBtn.textContent = 'Patienter…';
        setOvl('Vérification du compte…');
        // Check if account has face enrolled
        const chk = await fetch('/face-auth/login/check', {
            method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ email }),
        });
        const chkData = await chk.json();
        if (!chk.ok || !chkData.ready) {
            _frCloseModal(ID);
            _showStatus('fr-login-status', '<i class="fa-solid fa-xmark"></i> ' + (chkData.error || 'Aucune reconnaissance faciale pour ce compte.'), 'error');
            return;
        }
        setOvl('Chargement des modèles IA…');
        await _loadScript();
        await _loadModels(t => setOvl(t));
        setOvl('Ouverture de la caméra…');
        await _openCamera(vid);
        await new Promise(r => setTimeout(r, 800));
        setOvl(`Bonjour ${chkData.displayName} — regardez la caméra`);
        actBtn.textContent = 'Vérifier mon identité';
        actBtn.disabled = false; actBtn.style.opacity = '1';
        actBtn.onclick = runVerify;
    } catch (err) {
        _stopCamera();
        _frCloseModal(ID);
        _showStatus('fr-login-status', '<i class="fa-solid fa-xmark"></i> ' + err.message, 'error');
    }
}

// ── Init ──────────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('face-status-display')) loadFaceStatus();
});
