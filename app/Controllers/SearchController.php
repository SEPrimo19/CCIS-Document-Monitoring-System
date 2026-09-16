<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Guard;
use App\Models\DocumentFile;
use App\Models\DocumentType;
use App\Models\Requirement;
use App\Models\Submission;
use App\Models\User;

/**
 * Role-aware search (FR-37), reached from the search field in the top bar on
 * every signed-in screen.
 *
 * "Role-aware" means each user searches everything they are allowed to see and
 * nothing else — not one index filtered down afterwards:
 *
 *   Secretary — faculty names, requirement titles, and document types.
 *   Faculty   — only their OWN requirements/submissions and their own uploaded
 *               files.
 *
 * The Faculty scoping is a security boundary, not a convenience filter. Each
 * Faculty finder binds the session's own user_id into its WHERE clause
 * (Submission::searchOwned(), DocumentFile::searchOwned()), so the rows never
 * leave the database in the first place; nothing is discarded in PHP here.
 * There is deliberately no user/faculty parameter on this route for a crafted
 * query string to aim at.
 *
 * GET and read-only, so no CSRF token — the same reasoning as the monitoring,
 * audit-log, and report filters. The route is extension-less because PHP's
 * built-in dev server serves any URI with a file extension straight from disk.
 */
final class SearchController extends Controller
{
    /**
     * Rows returned per result group. A one-character term matches most of the
     * database, so every group is capped rather than paginated: this screen is
     * a way in to an existing screen, not a browsing surface of its own, and
     * each group's rows link to the screen that owns them.
     */
    private const GROUP_LIMIT = 20;

    /**
     * Longest accepted search term. Anything past this cannot match a column
     * the search reads (the longest is document_files.file_name at 255), so a
     * megabyte-long `q` is truncated before it reaches a LIKE pattern.
     */
    private const MAX_TERM_LENGTH = 255;

    public function index(): void
    {
        // Any signed-in role may search; WHAT each one searches is decided
        // below, and by the model finders, not by the view.
        Guard::requireAuth();

        $user = Auth::user();
        $isSecretary = Auth::hasRole('Secretary');
        $term = $this->termFrom($_GET);

        $data = [
            'appName'       => $this->config()['app']['name'],
            'term'          => $term ?? '',
            'isSecretary'   => $isSecretary,
            'limit'         => self::GROUP_LIMIT,
            'faculty'       => [],
            'requirements'  => [],
            'docTypes'      => [],
            'mySubmissions' => [],
            'myFiles'       => [],
        ];

        if ($term === null) {
            $this->view('search/index', $data);
            return;
        }

        if ($isSecretary) {
            $data['faculty'] = User::searchFaculty($term, self::GROUP_LIMIT);
            $data['requirements'] = Requirement::search($term, self::GROUP_LIMIT);
            $data['docTypes'] = DocumentType::search($term, self::GROUP_LIMIT);
        } else {
            $facultyId = (int) ($user['user_id'] ?? 0);
            $data['mySubmissions'] = Submission::searchOwned($facultyId, $term, self::GROUP_LIMIT);
            $data['myFiles'] = DocumentFile::searchOwned($facultyId, $term, self::GROUP_LIMIT);
        }

        $this->view('search/index', $data);
    }

    /**
     * The `q` GET parameter, trimmed and length-capped. Null when absent or
     * blank — that is "no search yet", which renders the prompt rather than an
     * empty result list, so arriving at /search with no query does not read as
     * "nothing matched".
     *
     * No character filtering: the term is bound as a parameter and its LIKE
     * metacharacters are neutralised in the model, and the views escape it on
     * output, so there is nothing here a denylist would add.
     *
     * is_string() rather than the (string) cast the other GET filters use:
     * `?q[]=x` makes $_GET['q'] an array, and casting one raises an "Array to
     * string conversion" warning. The search box is rendered by the shared
     * header partial, so that warning would otherwise be reachable from every
     * screen in the app rather than just this one.
     *
     * @param array<string,mixed> $query
     */
    private function termFrom(array $query): ?string
    {
        $raw = $query['q'] ?? '';
        if (!is_string($raw)) {
            return null;
        }

        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        return mb_substr($raw, 0, self::MAX_TERM_LENGTH);
    }
}
