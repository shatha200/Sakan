/**
 * charges-locataire.js
 * SAKAN — Mes Charges (Locataire) — JavaScript complet
 * Fichier externe: Twig ne parse jamais ce fichier.
 * Les valeurs Twig sont lues depuis #twig-data-bridge (data-attributes).
 */

(function () {

    /* ── 1. Lire les données injectées par Twig ── */
    var bridge        = document.getElementById('twig-data-bridge');
    var ROUTE_CREATE  = bridge ? bridge.getAttribute('data-route-create') : '';
    var ROUTE_GEMINI  = bridge ? bridge.getAttribute('data-route-gemini') : '';
    var CHART_DATA    = bridge ? JSON.parse(bridge.getAttribute('data-chart') || '{}') : {};

    var ROUTES = {
        create : ROUTE_CREATE,

        payer  : function(id) { return '/locataire/charges/' + id + '/payer'; },
        edit   : function(id) { return '/locataire/charges/' + id + '/modifier'; },
        del    : function(id) { return '/locataire/charges/' + id + '/supprimer'; },
        gemini : ROUTE_GEMINI
    };

    /* ── 2. Toast System ── */
    function toast(msg, type) {
        type = type || 'info';
        var c = document.getElementById('toast-container');
        if (!c) return;
        var t = document.createElement('div');
        t.className = 'toast ' + type;
        var icons = { success: 'check-circle', error: 'circle-exclamation', info: 'info-circle' };
        t.innerHTML = '<i class="fa-solid fa-' + (icons[type] || 'info-circle') + '"></i> ' + msg;
        c.appendChild(t);
        setTimeout(function () {
            t.style.opacity = '0';
            t.style.transition = 'opacity 0.4s';
            setTimeout(function () { t.remove(); }, 400);
        }, 4000);
    }

    /* ── 3. Modal helpers ── */
    function openModal(id)  { var m = document.getElementById(id); if (m) { m.classList.add('open'); document.body.style.overflow = 'hidden'; } }
    function closeModal(id) { var m = document.getElementById(id); if (m) { m.classList.remove('open'); document.body.style.overflow = ''; } }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-overlay.open').forEach(function (m) { m.classList.remove('open'); });
        }
    });
    document.querySelectorAll('.modal-overlay').forEach(function (m) {
        m.addEventListener('click', function (e) { if (e.target === m) closeModal(m.id); });
    });

    /* ── 4. Type selector ── */
    function selectType(type, prefix) {
        document.querySelectorAll('#' + prefix + '-type-grid .type-btn').forEach(function (b) { b.classList.remove('selected'); });
        var btn = document.querySelector('#' + prefix + '-type-grid .type-btn[data-type="' + type + '"]');
        if (btn) btn.classList.add('selected');
        var input = document.getElementById(prefix + '-type');
        if (input) input.value = type;
    }

    /* ── 5. Open Create Modal ── */
    function openCreateModal(type) {
        type = type || 'AUTRE';
        var fields = ['create-montant', 'create-desc', 'create-file'];
        fields.forEach(function (id) { var el = document.getElementById(id); if (el) el.value = ''; });
        var lbl = document.getElementById('create-file-label');
        if (lbl) lbl.textContent = 'Cliquer ou glisser un PDF / Image';
        var dz = document.getElementById('create-dropzone');
        if (dz) dz.className = 'drop-zone-modal';
        var now = new Date();
        var periodeEl = document.getElementById('create-periode');
        if (periodeEl) periodeEl.value = now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0');
        var statutEl = document.getElementById('create-statut');
        if (statutEl) statutEl.value = 'NON_PAYE';
        selectType(type, 'create');
        openModal('modal-create');
    }

    /* ── 6. Open Edit Modal ── */
    function openEditModal(charge) {
        var flds = {
            'edit-id': charge.id,
            'edit-montant': charge.montant,
            'edit-desc': charge.description || ''
        };
        Object.keys(flds).forEach(function (id) {
            var el = document.getElementById(id);
            if (el) el.value = flds[id];
        });
        var fileEl = document.getElementById('edit-file');
        if (fileEl) fileEl.value = '';
        var lbl = document.getElementById('edit-file-label');
        if (lbl) lbl.textContent = 'Cliquer ou glisser un PDF / Image (optionnel)';
        var dz = document.getElementById('edit-dropzone');
        if (dz) dz.className = 'drop-zone-modal';
        var periodeVal = charge.periode ? charge.periode.slice(0, 7) : new Date().toISOString().slice(0, 7);
        var periodeEl = document.getElementById('edit-periode');
        if (periodeEl) periodeEl.value = periodeVal;
        selectType(charge.type_charge || 'AUTRE', 'edit');
        openModal('modal-edit');
    }

    /* ── 7. Open Pay Modal ── */
    function openPayModal(chargeId, label, montant, periode) {
        var idEl = document.getElementById('pay-id');
        if (idEl) idEl.value = chargeId;
        var amtEl = document.getElementById('pay-amount-display');
        if (amtEl) amtEl.textContent = parseFloat(montant).toFixed(3) + ' TND';
        var infoEl = document.getElementById('pay-info-display');
        if (infoEl) infoEl.textContent = label + ' — ' + periode;
        var fileEl = document.getElementById('pay-file');
        if (fileEl) fileEl.value = '';
        var lbl = document.getElementById('pay-file-label');
        if (lbl) lbl.textContent = 'Deposer le recu ici (Photo ou PDF)';
        var dz = document.getElementById('pay-dropzone');
        if (dz) { dz.className = 'drop-zone-modal'; dz.style.borderColor = ''; }
        var notes = document.getElementById('pay-notes');
        if (notes) notes.value = '';
        openModal('modal-pay');
    }

    /* ── 8. Open Delete Modal ── */
    function openDeleteModal(chargeId, label) {
        var idEl = document.getElementById('delete-id');
        if (idEl) idEl.value = chargeId;
        var lblEl = document.getElementById('delete-label');
        if (lblEl) lblEl.textContent = label;
        openModal('modal-delete');
    }

    /* ── 9. File preview ── */
    function previewFile(input, dropzoneId, labelId) {
        var f = input.files[0];
        if (!f) return;
        var dz = document.getElementById(dropzoneId);
        if (dz) dz.classList.add('has-file');
        var lbl = document.getElementById(labelId);
        if (lbl) lbl.innerHTML = '<i class="fa-solid fa-check" style="color:#16a34a;"></i> <strong>' + f.name + '</strong> (' + Math.round(f.size / 1024) + ' KB)';
    }

    /* ── 10. Drag & Drop on upload cards ── */
    function handleDragOver(e) { e.preventDefault(); e.currentTarget.classList.add('drag-over'); }
    function handleDragLeave(e) { e.currentTarget.classList.remove('drag-over'); }
    function handleDrop(e, type) {
        e.preventDefault();
        e.currentTarget.classList.remove('drag-over');
        var f = e.dataTransfer.files[0];
        if (!f) return;
        openCreateModal(type);
        setTimeout(function () {
            var dt = new DataTransfer();
            dt.items.add(f);
            var input = document.getElementById('create-file');
            if (input) { input.files = dt.files; previewFile(input, 'create-dropzone', 'create-file-label'); }
        }, 150);
    }

    /* ── 11. Drag & Drop in modals ── */
    function modalDragOver(e, dzId) { e.preventDefault(); var dz = document.getElementById(dzId); if (dz) dz.classList.add('drag-over'); }
    function modalDragLeave(dzId) { var dz = document.getElementById(dzId); if (dz) dz.classList.remove('drag-over'); }
    function modalDrop(e, inputId) {
        e.preventDefault();
        var dzId = e.currentTarget.id;
        var dz = document.getElementById(dzId);
        if (dz) dz.classList.remove('drag-over');
        var f = e.dataTransfer.files[0];
        if (!f) return;
        var input = document.getElementById(inputId);
        if (!input) return;
        var dt = new DataTransfer();
        dt.items.add(f);
        input.files = dt.files;
        previewFile(input, inputId.replace('-file', '-dropzone'), inputId.replace('-file', '-file-label'));
    }

    /* ── 12. Fetch wrapper ── */
    function apiPost(url, formData) {
        return fetch(url, { method: 'POST', body: formData })
            .then(function (resp) {
                if (!resp.ok) throw new Error('HTTP ' + resp.status);
                return resp.json();
            });
    }

    /* ── 13. Submit: Creer Charge ── */
    function submitCreate() {
        var btn     = document.getElementById('create-submit-btn');
        var type    = document.getElementById('create-type').value;
        var montant = document.getElementById('create-montant').value;
        var periode = document.getElementById('create-periode').value + '-01';
        var contrat = document.getElementById('create-contrat').value;
        var statut  = document.getElementById('create-statut').value;

        if (!contrat) { toast('Aucun contrat disponible', 'error'); return; }
        if (!montant || parseFloat(montant) <= 0) { toast('Montant invalide', 'error'); return; }

        var fd = new FormData();
        fd.append('contrat_id', contrat);
        fd.append('type_charge', type);
        fd.append('montant', montant);
        fd.append('periode', periode);
        fd.append('statut_paiement', statut);
        fd.append('description', document.getElementById('create-desc').value);
        var fInput = document.getElementById('create-file');
        if (!fInput || !fInput.files[0]) {
            toast('La facture/justificatif est obligatoire', 'error');
            return;
        }
        fd.append('facture', fInput.files[0]);

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner"></span> Enregistrement...';

        apiPost(ROUTES.create, fd)
            .then(function (data) {
                if (data.success) {
                    toast('Charge ajoutee avec succes !', 'success');
                    closeModal('modal-create');
                    setTimeout(function () { location.reload(); }, 800);
                } else {
                    toast(data.error || "Erreur lors de l'ajout", 'error');
                }
            })
            .catch(function (e) { toast('Erreur: ' + e.message, 'error'); })
            .finally(function () {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-plus"></i> Ajouter la charge';
            });
    }

    /* ── 14. Submit: Modifier Charge ── */
    function submitEdit() {
        var btn     = document.getElementById('edit-submit-btn');
        var id      = document.getElementById('edit-id').value;
        var type    = document.getElementById('edit-type').value;
        var montant = document.getElementById('edit-montant').value;
        var periode = document.getElementById('edit-periode').value + '-01';

        if (!montant || parseFloat(montant) <= 0) { toast('Montant invalide', 'error'); return; }

        var fd = new FormData();
        fd.append('type_charge', type);
        fd.append('montant', montant);
        fd.append('periode', periode);
        fd.append('description', document.getElementById('edit-desc').value);
        var fInput = document.getElementById('edit-file');
        if (!fInput || !fInput.files[0]) {
            toast('La facture/justificatif est obligatoire', 'error');
            return;
        }
        fd.append('facture', fInput.files[0]);

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner"></span> Enregistrement...';

        apiPost(ROUTES.edit(id), fd)
            .then(function (data) {
                if (data.success) {
                    toast('Charge modifiee avec succes !', 'success');
                    closeModal('modal-edit');
                    setTimeout(function () { location.reload(); }, 800);
                } else {
                    toast(data.message || 'Erreur modification', 'error');
                }
            })
            .catch(function (e) { toast('Erreur: ' + e.message, 'error'); })
            .finally(function () {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-save"></i> Enregistrer';
            });
    }

    /* ── 15. Submit: Payer (preuve obligatoire) ── */
    function submitPay() {
        var btn   = document.getElementById('pay-submit-btn');
        var id    = document.getElementById('pay-id').value;
        var file  = document.getElementById('pay-file').files[0];
        var notes = document.getElementById('pay-notes').value;

        if (!file) {
            toast('La preuve de paiement est obligatoire', 'error');
            var dz = document.getElementById('pay-dropzone');
            if (dz) dz.style.borderColor = '#dc2626';
            return;
        }
        var dz = document.getElementById('pay-dropzone');
        if (dz) dz.style.borderColor = '';

        var fd = new FormData();
        fd.append('preuve', file);
        fd.append('notes', notes);

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner"></span> Enregistrement...';

        apiPost(ROUTES.payer(id), fd)
            .then(function (data) {
                if (data.success) {
                    toast('Paiement declare ! Email envoye au proprietaire.', 'success');
                    closeModal('modal-pay');
                    setTimeout(function () { location.reload(); }, 1000);
                } else {
                    toast(data.error || data.message || 'Erreur paiement', 'error');
                }
            })
            .catch(function (e) { toast('Erreur: ' + e.message, 'error'); })
            .finally(function () {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Confirmer le paiement';
            });
    }

    /* ── 16. Submit: Supprimer ── */
    function submitDelete() {
        var btn = document.getElementById('delete-submit-btn');
        var id  = document.getElementById('delete-id').value;

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner" style="border-top-color:#dc2626;border-color:rgba(220,38,38,0.2);"></span> Suppression...';

        apiPost(ROUTES.del(id), new FormData())
            .then(function (data) {
                if (data.success) {
                    toast('Charge supprimee.', 'success');
                    closeModal('modal-delete');
                    setTimeout(function () { location.reload(); }, 800);
                } else {
                    toast('Erreur lors de la suppression', 'error');
                }
            })
            .catch(function (e) { toast('Erreur: ' + e.message, 'error'); })
            .finally(function () {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa-solid fa-trash"></i> Supprimer definitivement';
            });
    }



    /* ── 18. Gemini AI Analysis ── */
    function analyzeWithGemini(input) {
        var file = input.files[0];
        if (!file) return;

        var spinner = document.getElementById('gemini-spinner');
        if (spinner) spinner.style.display = 'flex';

        var fd = new FormData();
        fd.append('facture', file);

        apiPost(ROUTES.gemini, fd)
            .then(function (data) {
                if (data.success && data.data) {
                    applyGeminiData(data.data, file);
                    toast('Analyse IA terminée. Données pré-remplies !', 'success');
                } else {
                    toast(data.error || 'Analyse Gemini échouée', 'error');
                }
            })
            .catch(function (e) { toast('Gemini indisponible: ' + e.message, 'error'); })
            .finally(function () {
                if (spinner) spinner.style.display = 'none';
                input.value = '';
            });
    }

    function applyGeminiData(d, originalFile) {
        openCreateModal(d.type_charge || 'AUTRE');
        setTimeout(function () {
            if (d.montant) {
                var el = document.getElementById('create-montant');
                if (el) el.value = parseFloat(d.montant).toFixed(3);
            }
            if (d.periode) {
                var el2 = document.getElementById('create-periode');
                if (el2) el2.value = d.periode.slice(0, 7);
            }
            if (d.statut_paiement) {
                var el3 = document.getElementById('create-statut');
                if (el3) el3.value = d.statut_paiement;
            }
            if (d.description) {
                var el4 = document.getElementById('create-desc');
                if (el4) el4.value = d.description;
            }
            selectType(d.type_charge || 'AUTRE', 'create');

            // Placer le fichier automatiquement dans l'input file du modal Create
            var dt = new DataTransfer();
            dt.items.add(originalFile);
            var fileInput = document.getElementById('create-file');
            if (fileInput) { 
                fileInput.files = dt.files; 
                previewFile(fileInput, 'create-dropzone', 'create-file-label'); 
            }
        }, 150);
    }


    /* ── 19. Chart.js Evolution ── */
    var chartCanvas = document.getElementById('evolutionChart');
    if (chartCanvas && typeof Chart !== 'undefined') {
        var ctx = chartCanvas.getContext('2d');
        var chartData = CHART_DATA;

        if (chartData && chartData.labels && chartData.labels.length > 0) {
            new Chart(ctx, {
                type: 'line',
                data: chartData,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'top',
                            labels: { usePointStyle: true, boxWidth: 8, padding: 20, font: { size: 12, weight: '600' } }
                        },
                        tooltip: {
                            backgroundColor: '#1f2937',
                            titleColor: '#f9fafb',
                            bodyColor: '#d1d5db',
                            padding: 12,
                            cornerRadius: 10,
                            callbacks: {
                                label: function (c) { return c.dataset.label + ': ' + c.raw.toFixed(3) + ' TND'; }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            grid: { color: 'rgba(0,0,0,0.04)' },
                            ticks: { callback: function (v) { return v > 0 ? v.toFixed(0) + ' TND' : '0'; }, font: { size: 11 } }
                        },
                        x: { grid: { display: false }, ticks: { font: { size: 11 } } }
                    },
                    interaction: { intersect: false, mode: 'index' },
                    elements: { line: { tension: 0.4 }, point: { radius: 4, hoverRadius: 7 } }
                }
            });
        } else {
            var parent = chartCanvas.closest('div');
            if (parent) {
                parent.innerHTML = '<div style="text-align:center;padding:60px 20px;color:#9ca3af;">'
                    + '<i class="fa-solid fa-chart-line" style="font-size:36px;margin-bottom:12px;display:block;opacity:0.3;"></i>'
                    + '<p style="margin:0;font-size:14px;">Ajoutez vos premieres charges pour voir l\'evolution</p>'
                    + '</div>';
            }
        }
    }

    /* ── 20. Exposer fonctions globalement (appelees depuis le HTML inline) ── */
    window.openCreateModal = openCreateModal;
    window.openEditModal   = openEditModal;
    window.openPayModal    = openPayModal;
    window.openDeleteModal = openDeleteModal;
    window.closeModal      = closeModal;
    window.selectType      = selectType;
    window.previewFile     = previewFile;
    window.handleDragOver  = handleDragOver;
    window.handleDragLeave = handleDragLeave;
    window.handleDrop      = handleDrop;
    window.modalDragOver   = modalDragOver;
    window.modalDragLeave  = modalDragLeave;
    window.modalDrop       = modalDrop;
    window.submitCreate    = submitCreate;
    window.submitEdit      = submitEdit;
    window.submitPay       = submitPay;
    window.submitDelete    = submitDelete;

    window.analyzeWithGemini = analyzeWithGemini;


})(); // IIFE - exécution immédiate
