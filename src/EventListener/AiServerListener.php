<?php

namespace App\EventListener;

use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Process\Process;

/**
 * AiServerListener — Lance automatiquement le serveur FastAPI local (ai_model/server.py)
 * à la première requête Symfony, de manière totalement transparente.
 *
 * - Port 8001 (le port 8000 est réservé au serveur Symfony dev)
 * - Vérifie d'abord si le port est déjà occupé (évite les doublons)
 * - Compatible Windows : essaie "py" puis "python" comme commande Python
 * - Propriété statique → un seul démarrage par lifecycle de processus PHP
 * - Fallback silencieux total si Python absent, script manquant, ou erreur
 * - N'interfère JAMAIS avec les autres modules (réservation, visite, etc.)
 */
class AiServerListener
{
    private const AI_PORT = 8001;

    /**
     * Référence statique au processus pour n'en démarrer qu'un seul.
     */
    private static ?Process $process = null;

    /**
     * Drapeau statique : déjà vérifié dans ce lifecycle PHP → ne plus vérifier.
     */
    private static bool $checked = false;

    private string $projectDir;

    public function __construct(string $projectDir)
    {
        $this->projectDir = $projectDir;
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        // Ne traiter que la requête principale (pas les sous-requêtes Twig)
        if (!$event->isMainRequest()) {
            return;
        }

        // Déjà vérifié dans ce cycle PHP → on ne répète pas
        if (self::$checked) {
            return;
        }
        self::$checked = true;

        // Le script server.py existe ?
        $serverScript = $this->projectDir . '/ai_model/server.py';
        if (!file_exists($serverScript)) {
            return; // Module IA non intégré → silence total
        }

        // Le modèle sakan_best.pt existe ?
        $modelPath = $this->projectDir . '/ai_model/model/sakan_best.pt';
        if (!file_exists($modelPath)) {
            return; // Modèle absent → inutile de démarrer le serveur
        }

        // Le serveur tourne déjà sur le port 8001 ? (ex: lancé manuellement ou requête précédente)
        if ($this->isPortOpen(self::AI_PORT)) {
            return; // Serveur déjà actif → rien à faire
        }

        try {
            $aiModelDir = $this->projectDir . '/ai_model';

            // Déterminer la commande Python (Windows : "py", Linux/Mac : "python3" ou "python")
            $pythonCmd = $this->findPythonCommand();
            if ($pythonCmd === null) {
                error_log('[AiServerListener] Python introuvable sur le système.');
                return;
            }

            // Lancer le serveur FastAPI en arrière-plan (sans bloquer Symfony)
            self::$process = new Process(
                [$pythonCmd, 'server.py'],
                $aiModelDir,
                null,  // hérite des variables d'environnement
                null,  // pas d'entrée stdin
                null   // pas de timeout (processus long)
            );

            self::$process->start();

            error_log('[AiServerListener] Serveur IA démarré sur le port ' . self::AI_PORT
                . ' (PID: ' . self::$process->getPid() . ', Python: ' . $pythonCmd . ')');

        } catch (\Throwable $e) {
            self::$process = null;
            error_log('[AiServerListener] Impossible de démarrer le serveur IA: ' . $e->getMessage());
        }
    }

    /**
     * Vérifie si un port TCP local est déjà en écoute.
     * Timeout très court (1 seconde) pour ne pas bloquer les requêtes.
     */
    private function isPortOpen(int $port): bool
    {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 1);
        if ($socket !== false) {
            fclose($socket);
            return true;
        }
        return false;
    }

    /**
     * Cherche la commande Python disponible sur le système.
     * Ordre de priorité adapté à Windows et Unix.
     *
     * @return string|null Commande utilisable, ou null si introuvable
     */
    private function findPythonCommand(): ?string
    {
        // Sur Windows, "py" est le Python Launcher (recommandé)
        $candidates = PHP_OS_FAMILY === 'Windows'
            ? ['py', 'python', 'python3']
            : ['python3', 'python', 'py'];

        foreach ($candidates as $cmd) {
            try {
                $probe = new Process([$cmd, '--version']);
                $probe->run();
                if ($probe->isSuccessful()) {
                    return $cmd;
                }
            } catch (\Throwable) {
                // Cette commande n'existe pas → essayer la suivante
            }
        }

        return null;
    }
}
