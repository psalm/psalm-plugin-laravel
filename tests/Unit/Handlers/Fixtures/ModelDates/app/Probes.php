<?php

declare(strict_types=1);

namespace ModelDatesFixture;

use Illuminate\Notifications\DatabaseNotification;
use ModelDatesFixture\Models\Draft;
use ModelDatesFixture\Models\Entry;
use ModelDatesFixture\Models\Invoice;
use ModelDatesFixture\Models\Journal;
use ModelDatesFixture\Models\Ledger;
use ModelDatesFixture\Models\Memo;
use ModelDatesFixture\Models\Moody;
use ModelDatesFixture\Models\Post;
use ModelDatesFixture\Models\Setting;
use ModelDatesFixture\Models\Snapshot;

function probes(
    Post $post,
    Snapshot $snapshot,
    Entry $entry,
    Memo $memo,
    Invoice $invoice,
    DatabaseNotification $notification,
): void {
    $_post_created = $post->created_at;
    /** @psalm-trace $_post_created */

    $_post_updated = $post->updated_at;
    /** @psalm-trace $_post_updated */

    $_post_published = $post->published_at;
    /** @psalm-trace $_post_published */

    $_snapshot_created = $snapshot->created_at;
    /** @psalm-trace $_snapshot_created */

    $_snapshot_updated = $snapshot->updated_at;
    /** @psalm-trace $_snapshot_updated */

    $_entry_created = $entry->created;
    /** @psalm-trace $_entry_created */

    $_entry_updated = $entry->updated_at;
    /** @psalm-trace $_entry_updated */

    $_memo_created = $memo->created_at;
    /** @psalm-trace $_memo_created */

    $_memo_updated = $memo->updated_at;
    /** @psalm-trace $_memo_updated */

    $_invoice_paid = $invoice->paid_at;
    /** @psalm-trace $_invoice_paid */

    $_invoice_created = $invoice->created_at;
    /** @psalm-trace $_invoice_created */

    $_notification_created = $notification->created_at;
    /** @psalm-trace $_notification_created */

    $_notification_updated = $notification->updated_at;
    /** @psalm-trace $_notification_updated */

    $_notification_read = $notification->read_at;
    /** @psalm-trace $_notification_read */

    // An accessor wins over the implicit date cast, on a property read and on both pluck call paths.
    $_journal_created = Journal::query()->first()?->created_at;
    /** @psalm-trace $_journal_created */

    $_pluck_builder = Journal::query()->pluck('created_at');
    /** @psalm-trace $_pluck_builder */

    $_pluck_collection = Journal::query()->get()->pluck('created_at');
    /** @psalm-trace $_pluck_collection */

    $_pluck_updated = Journal::query()->pluck('updated_at');
    /** @psalm-trace $_pluck_updated */

    $_pluck_updated_collection = Journal::query()->get()->pluck('updated_at');
    /** @psalm-trace $_pluck_updated_collection */

    $_max_collection = Journal::all()->max('created_at');
    /** @psalm-trace $_max_collection */

    $_max_builder = Journal::query()->max('created_at');
    /** @psalm-trace $_max_builder */

    // Builder::pluck() hydrates only the value column: the key is the raw driver value, whatever getDates() says.
    $_pluck_key_builder = Ledger::query()->pluck('id', 'logged_at');
    /** @psalm-trace $_pluck_key_builder */

    $_pluck_key_collection = Ledger::all()->pluck('id', 'logged_at');
    /** @psalm-trace $_pluck_key_collection */

    // A throwing `Attribute` factory on `created_at` must not withhold the model's other casts.
    $_moody_active = Moody::query()->first()?->active;
    /** @psalm-trace $_moody_active */

    // Keys: only a date object is stringified; a cast that changes the PHP key type keeps the stub's array-key.
    $_key_flag = Setting::query()->pluck('id', 'flag');
    /** @psalm-trace $_key_flag */

    $_key_level = Setting::query()->pluck('id', 'level');
    /** @psalm-trace $_key_level */

    $_key_sku = Setting::query()->pluck('id', 'sku');
    /** @psalm-trace $_key_sku */

    $_key_sku_collection = Setting::all()->pluck('id', 'sku');
    /** @psalm-trace $_key_sku_collection */

    // A set-only `Attribute` leaves the implicit date cast in place.
    $_draft_created = Draft::query()->first()?->created_at;
    /** @psalm-trace $_draft_created */
}
