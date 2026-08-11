<?php

declare(strict_types=1);

namespace App\Http\Controllers\DocumentVault;

use App\Platform\DocumentVault\Actions\DownloadDocument;
use App\Platform\DocumentVault\Exceptions\DocumentAccessDeniedException;
use App\Platform\IdentityAccess\ActorContext;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class DownloadDocumentController
{
    public function __construct(private DownloadDocument $download, private ActorContext $actor) {}

    public function __invoke(Request $request, string $document, string $token): Response
    {
        try {
            return $this->download->download($this->actor, $document, $token, $request->ip());
        } catch (DocumentAccessDeniedException) {
            abort(404);
        }
    }
}
