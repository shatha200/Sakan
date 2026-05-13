<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class FileController extends AbstractController
{
    /**
     * Serves files from public/uploads/factures/.
     * Accepts:
     *   - ?path=filename.pdf           → simple filename
     *   - ?path=/absolute/path/to/file → absolute path (legacy Java uploads)
     *   - ?path=uploads/factures/file  → relative path
     */
    #[Route('/file/proxy', name: 'file_proxy', methods: ['GET'])]
    public function serve(Request $request): Response
    {
        $path = (string)$request->query->get('path', '');

        if (empty($path)) {
            throw $this->createNotFoundException('Aucun fichier spécifié.');
        }

        $projectDir = (is_string($dir = $this->getParameter('kernel.project_dir')) ? $dir : '');
        $uploadsDir = $projectDir . '/public/uploads/factures/';

        // Strategy 1: if the path is absolute and exists → serve directly (legacy Java uploads)
        if (str_starts_with($path, '/') && file_exists($path)) {
            $resolvedPath = $path;
        }
        // Strategy 2: extract just the filename and search in uploads dir
        else {
            $filename     = basename($path);
            $resolvedPath = $uploadsDir . $filename;
        }

        if (!file_exists($resolvedPath)) {
            throw $this->createNotFoundException('Fichier introuvable : ' . basename($resolvedPath));
        }

        // Security: prevent path traversal
        $realResolved = realpath($resolvedPath);
        $realUploads  = realpath($uploadsDir);

        // Allow if inside uploads dir OR if it's a legacy absolute path
        if ($realUploads && $realResolved && !str_starts_with($realResolved, $realUploads)) {
            // Check if it's in the legacy java uploads dir
            $javaUploadsBase = dirname($projectDir) . DIRECTORY_SEPARATOR . 'uploads';
            if (!str_starts_with($realResolved, realpath($javaUploadsBase) ?: '')) {
                throw $this->createAccessDeniedException('Accès non autorisé.');
            }
        }

        $response = new BinaryFileResponse($resolvedPath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE);

        return $response;
    }

    /**
     * Serves files directly from public/uploads/factures/{filename}
     * Clean URL alternative: /uploads/factures/filename.pdf (handled by Symfony static)
     */
    #[Route('/uploads/factures/{filename}', name: 'file_facture_direct', methods: ['GET'],
        requirements: ['filename' => '.+'])]
    public function serveFacture(string $filename): Response
    {
        $projectDir   = (is_string($dir = $this->getParameter('kernel.project_dir')) ? $dir : '');
        $uploadsDir   = $projectDir . '/public/uploads/factures/';
        $safeName     = basename($filename); // prevents path traversal
        $resolvedPath = $uploadsDir . $safeName;

        if (!file_exists($resolvedPath)) {
            throw $this->createNotFoundException('Fichier introuvable : ' . $safeName);
        }

        $response = new BinaryFileResponse($resolvedPath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE);

        return $response;
    }
}
