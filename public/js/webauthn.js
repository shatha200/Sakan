'use strict';

function bufferToBase64Url(buffer) {
  const bytes = buffer instanceof Uint8Array ? buffer : new Uint8Array(buffer);
  let binary = '';
  for (const byte of bytes) binary += String.fromCharCode(byte);
  return btoa(binary).replace(/\+/g,'-').replace(/\//g,'_').replace(/=/g,'');
}

function base64UrlToBuffer(base64url) {
  const base64 = base64url.replace(/-/g,'+').replace(/_/g,'/');
  const padded = base64.padEnd(base64.length + (4-(base64.length%4))%4, '=');
  const binary = atob(padded);
  const bytes  = new Uint8Array(binary.length);
  for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
  return bytes.buffer;
}

function prepareCreationOptions(opts) {
  opts.challenge = base64UrlToBuffer(opts.challenge);
  opts.user.id   = base64UrlToBuffer(opts.user.id);
  if (opts.excludeCredentials)
    opts.excludeCredentials = opts.excludeCredentials.map(c => ({...c, id: base64UrlToBuffer(c.id)}));
  return opts;
}

function prepareRequestOptions(opts) {
  opts.challenge = base64UrlToBuffer(opts.challenge);
  if (opts.allowCredentials)
    opts.allowCredentials = opts.allowCredentials.map(c => ({...c, id: base64UrlToBuffer(c.id)}));
  return opts;
}

function showWebAuthnStatus(id, msg, type='info') {
  const el = document.getElementById(id);
  if (!el) return;
  const c = {info:'#eff6ff/#2563eb/#1e40af',success:'#f0fdf4/#16a34a/#15803d',error:'#fef2f2/#dc2626/#b91c1c',loading:'#fefce8/#ca8a04/#854d0e'}[type].split('/');
  el.style.cssText = `display:block;padding:12px 16px;border-radius:8px;font-size:14px;margin-top:12px;background:${c[0]};border:1px solid ${c[1]};color:${c[2]};`;
  el.textContent = msg;
}

async function registerWebAuthn() {
  const sid = 'webauthn-register-status', btn = document.getElementById('btn-register-webauthn');
  if (!window.PublicKeyCredential) { showWebAuthnStatus(sid,'WebAuthn non supporté.','error'); return; }
  try {
    if (btn) btn.disabled = true;
    showWebAuthnStatus(sid,'Préparation…','loading');
    const optResp = await fetch('/webauthn/register/options',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({})});
    if (!optResp.ok) { const e=await optResp.json().catch(()=>({})); throw new Error(e.error||'Erreur serveur.'); }
    const opts = prepareCreationOptions(await optResp.json());
    showWebAuthnStatus(sid,'Authentifiez-vous sur votre appareil…','loading');
    let cred;
    try { cred = await navigator.credentials.create({publicKey: opts}); }
    catch(e) { throw new Error(e.name==='NotAllowedError'?'Annulé ou délai dépassé.':'Erreur biométrie : '+e.message); }
    showWebAuthnStatus(sid,'Vérification…','loading');
    const vResp = await fetch('/webauthn/register/verify',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({
      id:cred.id, type:cred.type,
      response:{
        clientDataJSON:bufferToBase64Url(cred.response.clientDataJSON),
        attestationObject:bufferToBase64Url(cred.response.attestationObject),
        transports:cred.response.getTransports?cred.response.getTransports():[],
      }
    })});
    const r = await vResp.json();
    if (!vResp.ok || !r.success) throw new Error(r.error||'Échec.');
    showWebAuthnStatus(sid,'✓ '+(r.device||'Appareil')+' enregistré !','success');
    await loadWebAuthnCredentials();
  } catch(err) {
    showWebAuthnStatus(sid,'✗ '+err.message,'error');
  } finally { if (btn) btn.disabled=false; }
}

async function authenticateWebAuthn(email) {
  const sid = 'webauthn-login-status', btn = document.getElementById('btn-webauthn-login');
  email = (email||'').trim();
  if (!window.PublicKeyCredential) { showWebAuthnStatus(sid,'WebAuthn non supporté.','error'); return; }
  if (!email) { showWebAuthnStatus(sid,'Saisissez votre email.','error'); return; }
  try {
    if (btn) btn.disabled=true;
    showWebAuthnStatus(sid,'Récupération des options…','loading');
    const optResp = await fetch('/webauthn/auth/options',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({email})});
    if (!optResp.ok) { const e=await optResp.json().catch(()=>({})); throw new Error(e.error||'Aucun appareil enregistré.'); }
    const opts = prepareRequestOptions(await optResp.json());
    showWebAuthnStatus(sid,'Authentifiez-vous sur votre appareil…','loading');
    let assertion;
    try { assertion = await navigator.credentials.get({publicKey: opts}); }
    catch(e) { throw new Error(e.name==='NotAllowedError'?'Annulé.':'Erreur : '+e.message); }
    showWebAuthnStatus(sid,'Vérification…','loading');
    const vResp = await fetch('/webauthn/auth/verify',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({
      id:assertion.id, type:assertion.type,
      response:{
        clientDataJSON:bufferToBase64Url(assertion.response.clientDataJSON),
        authenticatorData:bufferToBase64Url(assertion.response.authenticatorData),
        signature:bufferToBase64Url(assertion.response.signature),
        userHandle:assertion.response.userHandle?bufferToBase64Url(assertion.response.userHandle):null,
      }
    })});
    const r = await vResp.json();
    if (!vResp.ok || !r.success) throw new Error(r.error||'Refusé.');
    showWebAuthnStatus(sid,'✓ Connecté ! Redirection…','success');
    setTimeout(()=>{window.location.href=r.redirect;},800);
  } catch(err) {
    showWebAuthnStatus(sid,'✗ '+err.message,'error');
    if (btn) btn.disabled=false;
  }
}

async function loadWebAuthnCredentials() {
  const c = document.getElementById('webauthn-credentials-list');
  if (!c) return;
  try {
    const r = await fetch('/webauthn/credentials');
    if (!r.ok) return;
    const creds = await r.json();
    if (!creds.length) { c.innerHTML='<p style="color:#6b7280;font-size:13px;">Aucun appareil enregistré.</p>'; return; }
    c.innerHTML = creds.map(cr=>`
      <div id="cred-${cr.id}" style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;border-radius:8px;margin-bottom:8px;background:#f8fafc;border:1px solid #e2e8f0;">
        <div><i class="fa-solid fa-fingerprint" style="color:#2563eb;margin-right:8px;"></i>
        <strong>${cr.device.replace(/</g,'&lt;')}</strong>
        <span style="color:#9ca3af;font-size:12px;margin-left:8px;">Ajouté le ${cr.createdAt}</span></div>
        <button onclick="deleteWebAuthnCredential(${cr.id})" style="background:none;border:1px solid #dc2626;color:#dc2626;border-radius:6px;padding:4px 10px;cursor:pointer;font-size:12px;">
          <i class="fa-solid fa-trash"></i></button>
      </div>`).join('');
  } catch(_) {}
}

async function deleteWebAuthnCredential(id) {
  if (!confirm('Supprimer cet appareil ?')) return;
  const r = await fetch('/webauthn/credentials/'+id,{method:'DELETE'});
  if (r.ok) await loadWebAuthnCredentials();
  else alert('Erreur suppression.');
}

document.addEventListener('DOMContentLoaded', ()=>{
  if (document.getElementById('webauthn-credentials-list')) loadWebAuthnCredentials();
});
