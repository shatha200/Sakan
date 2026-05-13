<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class TranslateController extends AbstractController
{
    /**
     * Chaîne de miroirs LibreTranslate publics gratuits (sans clé).
     * Essayés dans l'ordre, on prend le premier qui répond correctement.
     */
    private const LIBRETRANSLATE_MIRRORS = [
        'https://translate.argosopentech.com/translate',
        'https://translate.terraprint.co/translate',
        'https://libretranslate.de/translate',
    ];

    #[Route('/translate', name: 'app_translate', methods: ['POST'])]
    public function translate(Request $request, HttpClientInterface $httpClient): JsonResponse
    {
        $text   = trim((string) $request->request->get('q', ''));
        $source = trim((string) $request->request->get('source', 'fr'));
        $target = trim((string) $request->request->get('target', 'en'));

        if ($text === '') {
            return new JsonResponse(['translated' => '']);
        }

        // 1) Essayer chaque miroir LibreTranslate dans l'ordre
        foreach (self::LIBRETRANSLATE_MIRRORS as $url) {
            try {
                $response = $httpClient->request('POST', $url, [
                    'json' => [
                        'q'      => $text,
                        'source' => $source,
                        'target' => $target,
                        'format' => 'text',
                    ],
                    'timeout' => 10,
                ]);
                $data = $response->toArray(false);
                if (!empty($data['translatedText'])) {
                    return new JsonResponse([
                        'translated' => $data['translatedText'],
                        'provider'   => 'libretranslate',
                    ]);
                }
            } catch (\Throwable) {
                // Ce miroir est HS → on tente le suivant
                continue;
            }
        }

        // 2) Fallback : MyMemory (gratuit, sans clé, fiable depuis 2010)
        try {
            $response = $httpClient->request('GET', 'https://api.mymemory.translated.net/get', [
                'query' => [
                    'q'        => $text,
                    'langpair' => $source . '|' . $target,
                ],
                'timeout' => 10,
            ]);
            $data = $response->toArray(false);
            if (!empty($data['responseData']['translatedText'])) {
                return new JsonResponse([
                    'translated' => $data['responseData']['translatedText'],
                    'provider'   => 'mymemory',
                ]);
            }
        } catch (\Throwable) {
            // MyMemory aussi HS
        }

        return new JsonResponse([
            'translated' => '',
            'error'      => 'Aucun service de traduction disponible pour le moment.',
        ], 502);
    }
}
