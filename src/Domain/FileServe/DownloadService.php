<?php

declare(strict_types=1);

namespace Aiya\Core\Domain\FileServe;

use Aiya\Core\Domain\Credit\LedgerService;
use WP_Error;

/**
 * Handing a file over: resolve the row the viewer named, charge the list's
 * rate through the credit ledger, and answer the link — once the charge
 * stands.
 *
 * The ledger only bookkeeps (it knows amounts, never prices); this domain owns
 * the price, which is the group's own "credits per file". A free list comes
 * through here too, so one path serves every delivery and the download meter
 * sees all of them alike.
 *
 * A repeat claim for the same row inside a fixed 30-second window is read as
 * one purchase: the spend carries a key derived from the row and the time
 * window, the ledger answers "already recorded" for the repeat, and the link
 * is handed over again with the holder's current balance — no second charge.
 * It is a debouncing window, not a per-click identity: claims for the same row
 * that fall into two different windows are two purchases, and each pays.
 */
final class DownloadService
{
    /** Seconds in the fixed window inside which a repeat claim is not charged again. */
    private const DEDUPE_WINDOW = 30;

    public function __construct(
        private FileService $files,
        private LedgerService $ledger,
    ) {
    }

    /**
     * @return array{url: string, code: ?string, price: int, balance: ?int}|WP_Error
     */
    public function claim(int $postId, string $id, string $ref, int $viewerId): array|WP_Error
    {
        if ($this->files->readablePost($postId, $viewerId) === null) {
            return $this->notFound();
        }

        $group = $this->files->resolve($postId, $id);
        if ($group === null) {
            return $this->notFound();
        }

        // The row is whatever the group's own listing holds: the ref is
        // re-derived here, so it can only ever name a row that is really in it.
        $entry = null;
        foreach ($group['entries'] as $candidate) {
            if (!$candidate->isDir() && hash_equals(FileService::ref($candidate), $ref)) {
                $entry = $candidate;
                break;
            }
        }
        if ($entry === null || $entry->url === null) {
            return $this->notFound();
        }

        $price = $group['price'];
        $balance = null;
        // An editor taking their own file is not a delivery: no charge, and
        // nothing for the download meter to count either.
        $editor = current_user_can('edit_post', $postId);

        if ($price > 0 && !$editor) {
            $spend = $this->ledger->spend(
                $viewerId,
                $price,
                LedgerService::SOURCE_SPEND_DOWNLOAD,
                sprintf('%d:%s:%s', $postId, $id, $ref),
                sprintf('download:%d:%s:%s:%d', $postId, $id, $ref, intdiv(time(), self::DEDUPE_WINDOW))
            );
            if ($spend instanceof WP_Error) {
                if ($spend->get_error_code() !== 'aiya_credit_duplicate') {
                    return $spend;
                }
                // The window's repeat: the charge already stands, so answer
                // the balance the ledger actually holds, not a placeholder.
                $balance = $this->ledger->balance($viewerId);
            } else {
                $balance = (int) ($spend['balance'] ?? 0);
            }
        }

        // One metering point per delivery, charged or free: the statistics
        // domain listens here, and nothing else counts a download.
        if (!$editor) {
            do_action('aiya_core_download_served', $viewerId, $postId, $ref);
        }

        return [
            'url' => $entry->url,
            'code' => $entry->code,
            'price' => $price,
            'balance' => $balance,
        ];
    }

    /** A row this viewer may not have answers like a post that is not there. */
    private function notFound(): WP_Error
    {
        return new WP_Error('aiya_not_found', __('Content not found.', 'aiya-core'), ['status' => 404]);
    }
}
