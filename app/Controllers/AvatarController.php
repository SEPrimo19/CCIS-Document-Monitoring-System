<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Guard;
use App\Models\User;

/**
 * Serves profile photos (FR-40).
 *
 * Photos live OUTSIDE the web root in storage/avatars/ and reach a browser only
 * through this authenticated route, exactly as uploaded documents do via
 * DocumentController. Nothing user-supplied is ever written under public/.
 *
 * Route is extension-less on purpose: PHP's built-in dev server serves any URI
 * containing a file extension straight from disk and only falls back to
 * index.php for extension-less paths, so /avatars/3.png would 404 before ever
 * reaching this class.
 *
 * WHO MAY LOOK: any signed-in user may fetch any user's photo. That is a
 * deliberate decision, not an oversight. A profile photo is shown in the UI
 * next to a name — the Secretary sees faculty in user lists and compliance
 * tables — and it is not secret within the college. What is protected is
 * WRITING: only the owner can change their own photo, in ProfileController.
 * Anonymous requests are refused, so photos are not public to the internet.
 */
final class AvatarController extends Controller
{
    /**
     * The only image types this application will serve back, mapped from what
     * getimagesize() actually detected in the stored bytes.
     *
     * The Content-Type is derived from the FILE, never from its name or from
     * anything the uploader supplied — a stored name cannot talk this route
     * into announcing a type the bytes do not have.
     *
     * SVG is deliberately absent, here as well as in the upload validator: an
     * SVG is a document that can carry <script>, so serving a user-supplied one
     * from our own origin would be stored XSS. It must never be added.
     */
    private const SERVABLE = [
        IMAGETYPE_JPEG => 'image/jpeg',
        IMAGETYPE_PNG  => 'image/png',
        IMAGETYPE_WEBP => 'image/webp',
    ];

    public function show(string $userId): void
    {
        Guard::requireAuth();

        $id = (int) $userId;
        $storedName = $id > 0 ? User::avatarPathFor($id) : null;

        // One response for "no such user", "user has no photo" and "the row
        // points at a file that is gone": all three are simply "there is no
        // image here", and answering them differently would let a signed-in
        // user enumerate which accounts exist.
        if ($storedName === null) {
            $this->notFoundPage();
            return;
        }

        $absolutePath = self::directory() . DIRECTORY_SEPARATOR . $storedName;

        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            $this->notFoundPage();
            return;
        }

        // Re-inspect the bytes at serve time rather than trusting the stored
        // name. getimagesize() reads headers only (no GD required, and this
        // build has no GD), so this costs a stat and a few bytes.
        $info = @getimagesize($absolutePath);
        $type = is_array($info) ? ($info[2] ?? null) : null;

        if (!is_int($type) || !isset(self::SERVABLE[$type])) {
            $this->notFoundPage();
            return;
        }

        header('Content-Type: ' . self::SERVABLE[$type]);
        header('Content-Length: ' . (string) filesize($absolutePath));
        // inline, not attachment: this is rendered in an <img>, not downloaded.
        header('Content-Disposition: inline');
        // Private, because the response depends on the session. Short-lived so
        // a replaced photo is not stuck in the cache behind the old one.
        header('Cache-Control: private, max-age=300');

        readfile($absolutePath);
        exit;
    }

    private function notFoundPage(): void
    {
        http_response_code(404);
        $this->view('errors/404', ['appName' => $this->config()['app']['name']]);
    }

    /**
     * Absolute path of the avatar store. Outside the web root, beside
     * storage/uploads/, and carrying its own .gitignore so the directory
     * survives a clone while its contents are never committed.
     */
    public static function directory(): string
    {
        return BASE_PATH . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'avatars';
    }
}
