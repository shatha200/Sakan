/**
 * ═══════════════════════════════════════════════════════════════════════════
 * VALIDATION FINANCES LOCATAIRE — Contrôles de saisie logiques et UX
 * ═══════════════════════════════════════════════════════════════════════════
 * 
 * Règles de validation:
 * 1. Validation en temps réel (onBlur + onInput avec debounce)
 * 2. Feedback visuel immédiat (bordures + messages)
 * 3. Messages contextuels et multilingues
 * 4. Validation avant soumission de formulaire
 * 5. Accessibilité (ARIA labels pour erreurs)
 */

// ═══════════════════════════════════════════════════════════════════════════
// CONFIGURATION DES RÈGLES
// ═══════════════════════════════════════════════════════════════════════════

const VALIDATION_RULES = {
    // Validation des fichiers (preuves de paiement, factures)
    file: {
        allowedTypes: ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'],
        allowedExtensions: ['.pdf', '.jpg', '.jpeg', '.png', '.webp'],
        maxSize: 10 * 1024 * 1024, // 10MB
        messages: {
            required: '⚠️ Une preuve de paiement est obligatoire.',
            type: '❌ Format non valide. Acceptés: PDF, JPG, PNG, WEBP',
            size: '❌ Fichier trop volumineux. Maximum: 10 Mo',
            empty: '❌ Le fichier est vide ou corrompu.'
        }
    },
    
    // Validation textes (notes, descriptions)
    text: {
        maxLength: 500,
        pattern: /^[\w\s\-\_\.\,\;\:\!\?\'\"\(\)\/\&\@\#\$\%\*\+\=<>€£¥TND]*$/,
        messages: {
            maxlength: '❌ Maximum {max} caractères autorisés.',
            pattern: '❌ Caractères spéciaux non autorisés.',
            empty: '' // Optionnel = pas d'erreur si vide
        }
    },
    
    // Validation montants (input number)
    amount: {
        min: 0.001,
        max: 999999.999,
        decimals: 3,
        messages: {
            min: '❌ Le montant doit être supérieur à 0',
            max: '❌ Montant maximum dépassé',
            format: '❌ Format invalide. Ex: 850.500',
            required: '⚠️ Le montant est obligatoire'
        }
    },
    
    // Validation recherche/filtres
    search: {
        maxLength: 100,
        pattern: /^[\w\s\-\_\.]*$/,
        messages: {
            maxlength: '❌ Recherche trop longue (max 100 caractères)',
            pattern: '❌ Caractères spéciaux non autorisés dans la recherche'
        }
    }
};

// ═══════════════════════════════════════════════════════════════════════════
// UTILITAIRES
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Crée ou met à jour le message d'erreur pour un champ
 */
function setFieldError(input, message, isError = true) {
    const formGroup = input.closest('.form-group') || input.parentElement;
    let errorDiv = formGroup.querySelector('.field-error-message');
    
    // Créer le conteneur d'erreur s'il n'existe pas
    if (!errorDiv) {
        errorDiv = document.createElement('div');
        errorDiv.className = 'field-error-message';
        errorDiv.style.cssText = `
            font-size: 12px;
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 4px;
            transition: all 0.2s ease;
        `;
        formGroup.appendChild(errorDiv);
    }
    
    if (isError && message) {
        errorDiv.innerHTML = message;
        errorDiv.style.color = '#dc2626';
        input.style.borderColor = '#dc2626';
        input.style.backgroundColor = '#fef2f2';
        input.setAttribute('aria-invalid', 'true');
    } else {
        errorDiv.innerHTML = message || '';
        errorDiv.style.color = '#16a34a';
        input.style.borderColor = '#16a34a';
        input.style.backgroundColor = '#f0fdf4';
        input.setAttribute('aria-invalid', 'false');
    }
}

/**
 * Réinitialise la validation d'un champ
 */
function resetFieldValidation(input) {
    const formGroup = input.closest('.form-group') || input.parentElement;
    const errorDiv = formGroup.querySelector('.field-error-message');
    
    if (errorDiv) {
        errorDiv.innerHTML = '';
    }
    
    input.style.borderColor = '';
    input.style.backgroundColor = '';
    input.removeAttribute('aria-invalid');
}

/**
 * Debounce pour validation pendant la saisie
 */
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// ═══════════════════════════════════════════════════════════════════════════
// VALIDATEURS SPÉCIFIQUES
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Validation d'un fichier uploadé
 */
function validateFile(file, required = true) {
    const rules = VALIDATION_RULES.file;
    
    if (!file || file.size === 0) {
        if (required) {
            return { valid: false, message: rules.messages.required };
        }
        return { valid: true, message: '' };
    }
    
    // Vérification taille
    if (file.size > rules.maxSize) {
        return { valid: false, message: rules.messages.size };
    }
    
    // Vérification type MIME
    if (!rules.allowedTypes.includes(file.type)) {
        // Vérification extension fallback
        const extension = '.' + file.name.split('.').pop().toLowerCase();
        if (!rules.allowedExtensions.includes(extension)) {
            return { valid: false, message: rules.messages.type };
        }
    }
    
    return { valid: true, message: '✅ Fichier valide' };
}

/**
 * Validation d'un champ texte
 */
function validateText(value, required = false, maxLength = 500) {
    const rules = VALIDATION_RULES.text;
    
    if (!value || value.trim() === '') {
        if (required) {
            return { valid: false, message: '⚠️ Ce champ est obligatoire' };
        }
        return { valid: true, message: '' };
    }
    
    if (value.length > maxLength) {
        return { 
            valid: false, 
            message: rules.messages.maxlength.replace('{max}', maxLength) + ` (${value.length}/${maxLength})`
        };
    }
    
    if (!rules.pattern.test(value)) {
        return { valid: false, message: rules.messages.pattern };
    }
    
    return { valid: true, message: `✅ ${value.length}/${maxLength} caractères` };
}

/**
 * Validation d'un montant
 */
function validateAmount(value, required = true) {
    const rules = VALIDATION_RULES.amount;
    
    if (value === '' || value === null || value === undefined) {
        if (required) {
            return { valid: false, message: rules.messages.required };
        }
        return { valid: true, message: '' };
    }
    
    const numValue = parseFloat(value);
    
    if (isNaN(numValue)) {
        return { valid: false, message: rules.messages.format };
    }
    
    if (numValue < rules.min) {
        return { valid: false, message: rules.messages.min };
    }
    
    if (numValue > rules.max) {
        return { valid: false, message: rules.messages.max };
    }
    
    // Vérification décimales
    const decimalPart = value.toString().split('.')[1];
    if (decimalPart && decimalPart.length > rules.decimals) {
        return { valid: false, message: `❌ Maximum ${rules.decimals} décimales` };
    }
    
    return { valid: true, message: '✅ Montant valide' };
}

/**
 * Validation recherche/filtre
 */
function validateSearch(value) {
    const rules = VALIDATION_RULES.search;
    
    if (!value || value.trim() === '') {
        return { valid: true, message: '' };
    }
    
    if (value.length > rules.maxLength) {
        return { valid: false, message: rules.messages.maxlength };
    }
    
    if (!rules.pattern.test(value)) {
        return { valid: false, message: rules.messages.pattern };
    }
    
    return { valid: true, message: '' };
}

// ═══════════════════════════════════════════════════════════════════════════
// ATTACHEMENT DES VALIDATEURS AUX CHAMPS
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Initialise la validation sur tous les champs du formulaire
 */
function initFormValidation(formId) {
    const form = document.getElementById(formId);
    if (!form) return;
    
    // Validation fichiers
    form.querySelectorAll('input[type="file"]').forEach(input => {
        input.addEventListener('change', function() {
            const file = this.files[0];
            const isRequired = this.hasAttribute('required') || this.dataset.required === 'true';
            const result = validateFile(file, isRequired);
            
            if (!result.valid) {
                setFieldError(this, result.message, true);
                this.value = ''; // Reset si invalide
            } else if (result.message) {
                setFieldError(this, result.message, false);
            } else {
                resetFieldValidation(this);
            }
            
            // Mise à jour du label de dropzone si présent
            const dropzone = document.getElementById(this.dataset.dropzone);
            if (dropzone && file && result.valid) {
                const label = dropzone.querySelector('[id$="-label"]');
                if (label) {
                    label.textContent = `✅ ${file.name} (${(file.size / 1024 / 1024).toFixed(2)} Mo)`;
                    label.style.color = '#16a34a';
                }
                dropzone.style.borderColor = '#16a34a';
                dropzone.style.backgroundColor = '#f0fdf4';
            }
        });
    });
    
    // Validation textes (avec debounce)
    form.querySelectorAll('input[type="text"], textarea').forEach(input => {
        const validate = debounce(function() {
            const isRequired = input.hasAttribute('required');
            const maxLength = parseInt(input.dataset.maxlength) || 500;
            const result = validateText(input.value, isRequired, maxLength);
            
            if (!result.valid) {
                setFieldError(input, result.message, true);
            } else if (result.message && input.value) {
                setFieldError(input, result.message, false);
            } else {
                resetFieldValidation(input);
            }
        }, 300);
        
        input.addEventListener('blur', validate);
        input.addEventListener('input', validate);
    });
    
    // Validation montants
    form.querySelectorAll('input[type="number"], input[data-type="amount"]').forEach(input => {
        const validate = function() {
            const isRequired = input.hasAttribute('required');
            const result = validateAmount(input.value, isRequired);
            
            if (!result.valid) {
                setFieldError(input, result.message, true);
            } else if (result.message && input.value) {
                setFieldError(input, result.message, false);
            } else {
                resetFieldValidation(input);
            }
        };
        
        input.addEventListener('blur', validate);
        input.addEventListener('input', debounce(validate, 200));
    });
    
    // Validation avant soumission
    form.addEventListener('submit', function(e) {
        let isValid = true;
        
        // Valider tous les champs requis
        form.querySelectorAll('input, textarea, select').forEach(input => {
            if (input.type === 'file') {
                const result = validateFile(input.files[0], input.hasAttribute('required'));
                if (!result.valid) {
                    setFieldError(input, result.message, true);
                    isValid = false;
                }
            } else if (input.type === 'number' || input.dataset.type === 'amount') {
                const result = validateAmount(input.value, input.hasAttribute('required'));
                if (!result.valid) {
                    setFieldError(input, result.message, true);
                    isValid = false;
                }
            } else if (input.type === 'text' || input.tagName === 'TEXTAREA') {
                const maxLength = parseInt(input.dataset.maxlength) || 500;
                const result = validateText(input.value, input.hasAttribute('required'), maxLength);
                if (!result.valid) {
                    setFieldError(input, result.message, true);
                    isValid = false;
                }
            }
        });
        
        if (!isValid) {
            e.preventDefault();
            e.stopPropagation();
            
            // Scroll vers le premier champ en erreur
            const firstError = form.querySelector('[aria-invalid="true"]');
            if (firstError) {
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                firstError.focus();
            }
            
            // Toast notification
            showValidationToast('❌ Veuillez corriger les erreurs avant de continuer', 'error');
        }
    });
}

// ═══════════════════════════════════════════════════════════════════════════
// VALIDATION SPÉCIFIQUE MODAL PAIEMENT
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Validation du modal de déclaration de paiement
 */
function validatePaymentModal() {
    const fileInput = document.getElementById('pay-file');
    const notesInput = document.getElementById('pay-notes');
    
    // Le fichier est obligatoire
    if (fileInput) {
        const fileResult = validateFile(fileInput.files[0], true);
        if (!fileResult.valid) {
            setFieldError(fileInput, fileResult.message, true);
            return false;
        }
    }
    
    // Les notes sont optionnelles mais validées si présentes
    if (notesInput && notesInput.value.trim()) {
        const notesResult = validateText(notesInput.value, false, 500);
        if (!notesResult.valid) {
            setFieldError(notesInput, notesResult.message, true);
            return false;
        }
    }
    
    return true;
}

/**
 * Validation du modal d'édition de charge
 */
function validateEditModal() {
    const fileInput = document.getElementById('edit-file');
    
    // Fichier optionnel mais validé si présent
    if (fileInput && fileInput.files[0]) {
        const fileResult = validateFile(fileInput.files[0], false);
        if (!fileResult.valid) {
            setFieldError(fileInput, fileResult.message, true);
            return false;
        }
    }
    
    return true;
}

// ═══════════════════════════════════════════════════════════════════════════
// FEEDBACK UTILISATEUR
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Affiche un toast de notification
 */
function showValidationToast(message, type = 'info') {
    // Créer le toast s'il n'existe pas
    let toast = document.getElementById('validation-toast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'validation-toast';
        toast.style.cssText = `
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 16px 24px;
            border-radius: 12px;
            font-weight: 600;
            font-size: 14px;
            z-index: 9999;
            transform: translateY(100px);
            opacity: 0;
            transition: all 0.3s ease;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        `;
        document.body.appendChild(toast);
    }
    
    // Style selon le type
    const colors = {
        error: { bg: '#fef2f2', border: '#dc2626', text: '#dc2626', icon: '❌' },
        success: { bg: '#f0fdf4', border: '#16a34a', text: '#16a34a', icon: '✅' },
        warning: { bg: '#fffbeb', border: '#f59e0b', text: '#f59e0b', icon: '⚠️' },
        info: { bg: '#eff6ff', border: '#3b82f6', text: '#3b82f6', icon: 'ℹ️' }
    };
    
    const color = colors[type] || colors.info;
    
    toast.style.backgroundColor = color.bg;
    toast.style.border = `2px solid ${color.border}`;
    toast.style.color = color.text;
    toast.innerHTML = `${color.icon} ${message}`;
    
    // Animation d'entrée
    requestAnimationFrame(() => {
        toast.style.transform = 'translateY(0)';
        toast.style.opacity = '1';
    });
    
    // Disparition auto
    setTimeout(() => {
        toast.style.transform = 'translateY(100px)';
        toast.style.opacity = '0';
    }, 4000);
}

// ═══════════════════════════════════════════════════════════════════════════
// INITIALISATION AU CHARGEMENT
// ═══════════════════════════════════════════════════════════════════════════

document.addEventListener('DOMContentLoaded', function() {
    // Initialiser validation sur les formulaires existants
    initFormValidation('modal-pay');
    initFormValidation('modal-edit');
    initFormValidation('modal-create');
    
    // Validation des filtres historique
    const filterForm = document.getElementById('js-filter-form');
    if (filterForm) {
        initFormValidation('js-filter-form');
    }
    
    console.log('[Validation Finances] Initialisée avec succès');
});

// Export pour utilisation externe
window.FinanceValidation = {
    validateFile,
    validateText,
    validateAmount,
    validateSearch,
    validatePaymentModal,
    validateEditModal,
    showValidationToast,
    setFieldError,
    resetFieldValidation
};
