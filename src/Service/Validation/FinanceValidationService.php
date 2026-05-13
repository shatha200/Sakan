<?php

namespace App\Service\Validation;

use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Service de validation des données financières côté serveur
 * 
 * Règles métier:
 * - Fichiers: PDF, JPG, PNG, WEBP uniquement, max 10MB
 * - Montants: > 0, max 999999.999, 3 décimales max
 * - Textes: pas de HTML, longueurs limitées
 * - Types: valeurs depuis liste autorisée
 */
class FinanceValidationService
{
    private readonly \Symfony\Component\Validator\Validator\ValidatorInterface $validator;

    public function __construct()
    {
        $this->validator = Validation::createValidator();
    }
    
    /**
     * Valide un fichier uploadé (preuve de paiement, facture)
     * @return array<string, mixed>
     */
    public function validateFile(?UploadedFile $file, bool $required = true): array
    {
        if ($file === null) {
            if ($required) {
                return ['valid' => false, 'errors' => ['Fichier requis']];
            }
            return ['valid' => true, 'errors' => []];
        }
        
        $errors = [];
        
        // Vérification erreur upload
        if ($file->getError() !== UPLOAD_ERR_OK) {
            switch ($file->getError()) {
                case UPLOAD_ERR_INI_SIZE:
                case UPLOAD_ERR_FORM_SIZE:
                    $errors[] = 'Fichier trop volumineux (max 10 Mo)';
                    break;
                case UPLOAD_ERR_PARTIAL:
                    $errors[] = 'Upload incomplet, veuillez réessayer';
                    break;
                default:
                    $errors[] = 'Erreur lors de l\'upload du fichier';
            }
            return ['valid' => false, 'errors' => $errors];
        }
        
        // Vérification taille
        if ($file->getSize() > 10 * 1024 * 1024) {
            $errors[] = 'Fichier trop volumineux. Maximum: 10 Mo';
        }
        
        // Vérification type MIME
        $allowedMimeTypes = [
            'application/pdf',
            'image/jpeg',
            'image/png',
            'image/webp'
        ];
        
        if (!in_array($file->getMimeType(), $allowedMimeTypes)) {
            // Vérification extension en fallback
            $extension = strtolower($file->getClientOriginalExtension());
            $allowedExtensions = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];
            
            if (!in_array($extension, $allowedExtensions)) {
                $errors[] = 'Format non valide. Acceptés: PDF, JPG, PNG, WEBP';
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Valide un montant
     * @return array<string, mixed>
     */
    public function validateAmount(mixed $amount, bool $required = true): array
    {
        $errors = [];
        
        if ($amount === null || $amount === '') {
            if ($required) {
                $errors[] = 'Le montant est obligatoire';
            }
            return ['valid' => empty($errors), 'errors' => $errors];
        }
        
        // Vérification numérique
        if (!is_numeric($amount)) {
            $errors[] = 'Format invalide. Ex: 850.500';
            return ['valid' => false, 'errors' => $errors];
        }
        
        $numAmount = (float) $amount;
        
        // Minimum > 0
        if ($numAmount <= 0) {
            $errors[] = 'Le montant doit être supérieur à 0';
        }
        
        // Maximum
        if ($numAmount > 999999.999) {
            $errors[] = 'Montant maximum dépassé';
        }
        
        // Décimales (max 3)
        $decimalPart = explode('.', (string) $amount)[1] ?? '';
        if (strlen($decimalPart) > 3) {
            $errors[] = 'Maximum 3 décimales autorisées';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Valide un texte (notes, descriptions)
     * @return array<string, mixed>
     */
    public function validateText(?string $text, bool $required = false, int $maxLength = 500): array
    {
        $errors = [];
        
        if (empty($text)) {
            if ($required) {
                $errors[] = 'Ce champ est obligatoire';
            }
            return ['valid' => empty($errors), 'errors' => $errors];
        }
        
        // Longueur
        if (strlen($text) > $maxLength) {
            $errors[] = sprintf('Maximum %d caractères autorisés (%d saisis)', $maxLength, strlen($text));
        }
        
        // Pas de HTML (XSS prevention)
        if ($text !== strip_tags($text)) {
            $errors[] = 'Les balises HTML ne sont pas autorisées';
        }
        
        // Caractères autorisés
        if (!preg_match('/^[\w\s\-\_\.\,\;\:\!\?\'\"\(\)\/\&\@\#\$\%\*\+\=<>€£¥TNDàâäéèêëîïôöùûüçÀÂÄÉÈÊËÎÏÔÖÙÛÜÇ]*$/u', $text)) {
            $errors[] = 'Caractères spéciaux non autorisés';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Valide un type de charge
     * @return array<string, mixed>
     */
    public function validateChargeType(string $type): array
    {
        $allowedTypes = [
            'ELECTRICITE', 'EAU', 'INTERNET', 'GAZ', 'CHAUFFAGE',
            'ORDURES', 'CHARGES_COPRO', 'ENTRETIEN', 'AUTRE'
        ];
        
        if (!in_array($type, $allowedTypes)) {
            return [
                'valid' => false,
                'errors' => ['Type de charge invalide']
            ];
        }
        
        return ['valid' => true, 'errors' => []];
    }
    
    /**
     * Valide une période (YYYY-MM)
     * @return array<string, mixed>
     */
    public function validatePeriod(string $period): array
    {
        $errors = [];
        
        // Format YYYY-MM ou YYYY-TN (trimestre)
        if (!preg_match('/^\d{4}-(?:0[1-9]|1[0-2]|T[1-4])$/', $period)) {
            $errors[] = 'Format de période invalide (YYYY-MM ou YYYY-TN)';
        }
        
        // Vérification que la période n'est pas trop ancienne (> 5 ans)
        $year = (int) substr($period, 0, 4);
        $currentYear = (int) date('Y');
        
        if ($year < ($currentYear - 5)) {
            $errors[] = 'Période trop ancienne (> 5 ans)';
        }
        
        if ($year > ($currentYear + 1)) {
            $errors[] = 'Période future non autorisée';
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Valide une demande de remboursement de caution
     * @return array<string, mixed>
     */
    public function validateRefundRequest(float $requestedAmount, float $availableAmount): array
    {
        $errors = [];
        
        if ($requestedAmount <= 0) {
            $errors[] = 'Le montant doit être supérieur à 0';
        }
        
        if ($requestedAmount > $availableAmount) {
            $errors[] = sprintf(
                'Montant demandé (%.3f TND) supérieur au solde disponible (%.3f TND)',
                $requestedAmount,
                $availableAmount
            );
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Valide les données de création d'une charge
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function validateChargeCreate(array $data): array
    {
        $errors = [];
        
        // Type
        $typeValidation = $this->validateChargeType($data['type_charge'] ?? '');
        if (!$typeValidation['valid']) {
            $errors['type_charge'] = $typeValidation['errors'];
        }
        
        // Montant
        $amountValidation = $this->validateAmount($data['montant'] ?? null, true);
        if (!$amountValidation['valid']) {
            $errors['montant'] = $amountValidation['errors'];
        }
        
        // Période
        $periodValidation = $this->validatePeriod($data['periode'] ?? '');
        if (!$periodValidation['valid']) {
            $errors['periode'] = $periodValidation['errors'];
        }
        
        // Description (optionnelle)
        if (!empty($data['description'])) {
            $descValidation = $this->validateText($data['description'], false, 1000);
            if (!$descValidation['valid']) {
                $errors['description'] = $descValidation['errors'];
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors
        ];
    }
    
    /**
     * Valide un upload de facture
     * @return array<string, mixed>
     */
    public function validateFactureUpload(UploadedFile $file): array
    {
        return $this->validateFile($file, true);
    }
    
    /**
     * Formate les erreurs pour affichage
     * @param array<string, mixed> $errors
     */
    public function formatErrors(array $errors): string
    {
        $messages = [];
        
        foreach ($errors as $field => $fieldErrors) {
            if (is_array($fieldErrors)) {
                foreach ($fieldErrors as $error) {
                    $messages[] = $error;
                }
            } else {
                $messages[] = $fieldErrors;
            }
        }
        
        return implode(' | ', $messages);
    }
}
