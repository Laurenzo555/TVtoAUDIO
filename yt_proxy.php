<?php
/**
 * TVtoAUDIO - YouTube Proxy (Version Finale Résiliente pour OVH)
 * Ce script extrait le lien de stream et sert de tunnel pour éviter les blocages.
 */

// --- 1. CONFIGURATION RÉSEAU ---
@set_time_limit(0);
@ini_set('max_execution_time', 0);
@ini_set('memory_limit', '256M');

// On masque les erreurs obsolètes de PHP 8.5 pour ne pas polluer le flux binaire
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', 0);

$videoId = $_GET['id'] ?? null;
$debug = isset($_GET['debug']);

if ($debug) {
    ini_set('display_errors', 1);
    header('Content-Type: text/plain; charset=utf-8');
    echo "=== TVtoAUDIO ULTIMATE DEBUG ===\n";
}

// --- 2. EXTRACTION DE L'ID YOUTUBE ---
if (str_contains($videoId, 'youtu.be/')) {
    $videoId = explode('youtu.be/', $videoId)[1];
    $videoId = explode('?', $videoId)[0];
} elseif (str_contains($videoId, 'v=')) {
    $videoId = explode('v=', $videoId)[1];
    $videoId = explode('&', $videoId)[0];
}

if (!$videoId || !preg_match('/^[a-zA-Z0-9_-]{11}$/', $videoId)) {
    die("Erreur : ID YouTube manquant ou invalide.");
}

// --- 3. RÉCUPÉRATION DU FLUX VIA INVIDIOUS (API) ---
$streamUrl = null;
// Instance Invidious choisie pour sa grande stabilité
$apiUrl = "https://invidious.sethforprivacy.com/api/v1/videos/" . $videoId;

if ($debug) echo "Connexion à l'API : $apiUrl\n";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 12);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Crucial pour OVH
curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4); // Force IPv4 pour éviter le bug DNS
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0.0.0');

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);

if ($httpCode == 200 && $response) {
    $data = json_decode($response, true);
    // On cherche un flux audio MP4 stable
    if (isset($data['adaptiveFormats'])) {
        foreach ($data['adaptiveFormats'] as $f) {
            if (str_contains($f['type'] ?? '', 'audio/mp4')) {
                $streamUrl = $f['url'];
                break;
            }
        }
    }
}

if ($debug) {
    echo "Statut API : HTTP $httpCode\n";
    if ($curlError) echo "Erreur Réseau : $curlError\n";
    echo "Flux trouvé : " . ($streamUrl ? "OUI" : "NON") . "\n";
    if (!$streamUrl && $response) echo "Réponse brute (tronquée) : " . substr($response, 0, 300) . "\n";
    exit;
}

// --- 4. TUNNELING DU FLUX (STREAMING TEMPS RÉEL) ---
if (!$streamUrl) {
    // Fallback : Redirection brute vers YouTube si l'extraction échoue
    header("Location: https://www.youtube.com/watch?v=" . $videoId);
    exit;
}

// Nettoyage des buffers pour la lecture immédiate
while (ob_get_level()) ob_end_clean();

// En-têtes pour le lecteur Android
header("Content-Type: audio/mp4");
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Connection: close");

// Lancement du tunnel cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $streamUrl);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, false); // On écrit directement vers la sortie
curl_setopt($ch, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

// Fonction qui transmet les données au fur et à mesure (Chunked Streaming)
curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($ch, $chunk) {
    echo $chunk;
    flush(); 
    return strlen($chunk);
});

curl_exec($ch);
exit;