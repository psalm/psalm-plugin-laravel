--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-with-optin-custom-issues.xml
--FILE--
<?php declare(strict_types=1);

namespace App\QueuedJobs;

use App\Models\Customer;
use App\Models\Invoice;
use Illuminate\Bus\Queueable as BusQueueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Queue\Queueable as FoundationQueueable;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * The SerializedQueuedModel rule flags a queued class holding an Eloquent model without
 * reaching Illuminate\Queue\SerializesModels, which would serialize the whole model into
 * the payload instead of a ModelIdentifier. Registered by the config this test runs under
 * (see --ARGS-- above).
 *
 * The negative cases matter as much as the positives: the trait is nearly always inherited,
 * so a direct-`use` check would report every one of them.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1380
 */

/** A hand-written job with only Bus\Queueable: the trait is not reachable, so it is flagged. */
class SendCustomerReport implements ShouldQueue
{
    use BusQueueable;
    use InteractsWithQueue;

    public function __construct(public Customer $customer) {}
}

/** A promoted constructor property is a property like any other, and is flagged too. */
class ArchiveCustomer implements ShouldQueue
{
    use BusQueueable;

    public function __construct(protected Customer $customer) {}
}

/** An Eloquent collection carries whole models as well, so it is flagged. */
class NotifyCustomers implements ShouldQueue
{
    use BusQueueable;

    /** @param EloquentCollection<int, Customer> $customers */
    public function __construct(public EloquentCollection $customers) {}
}

/**
 * A `Support\Collection` of models is NOT flagged: it is not a `QueueableCollection`, so
 * `getSerializedPropertyValue()` passes it through untouched and adding the trait would not
 * fix anything. Only `Eloquent\Collection` is converted.
 */
class ExportCustomers implements ShouldQueue
{
    use BusQueueable;

    /** @param Collection<int, Customer> $customers */
    public function __construct(public Collection $customers) {}
}

/** A class that hand-writes its own serialization already decides what enters the payload. */
class ArchiveCustomerById implements ShouldQueue
{
    use BusQueueable;

    public function __construct(private Customer $customer) {}

    /** @return array{customer: array-key} */
    public function __serialize(): array
    {
        return ['customer' => $this->customer->getKey()];
    }

    /** @param array{customer: array-key} $values */
    public function __unserialize(array $values): void
    {
        $this->customer = Customer::query()->findOrFail($values['customer']);
    }
}

/**
 * Several model properties on one class: the fix is a single `use SerializesModels;`, so this
 * is ONE report naming every offending property, not one report per property.
 */
class SettleInvoice implements ShouldQueue
{
    use BusQueueable;

    public function __construct(
        public Customer $payer,
        public Customer $payee,
        protected Invoice $invoice,
    ) {}
}

/** Beyond three properties the message truncates the list rather than growing without bound. */
class ReconcileLedger implements ShouldQueue
{
    use BusQueueable;

    public function __construct(
        public Customer $customer,
        public Invoice $opening,
        public Invoice $closing,
        public Invoice $correction,
    ) {}
}

/**
 * `Illuminate\Foundation\Queue\Queueable` — what `make:job` scaffolds since Laravel 11 — is
 * `use Dispatchable, InteractsWithQueue, QueueableByBus, SerializesModels;`, so the trait is
 * reachable and this is not flagged. This is the single largest false-positive source.
 */
class ScaffoldedJob implements ShouldQueue
{
    use FoundationQueueable;

    public function __construct(public Customer $customer) {}
}

/** `Illuminate\Notifications\Notification` uses SerializesModels directly — not flagged. */
class CustomerNotification extends Notification implements ShouldQueue
{
    use BusQueueable;

    public function __construct(public Customer $customer) {}
}

/** The trait reached through the PARENT class, not the class itself — not flagged. */
abstract class SerializingJob implements ShouldQueue
{
    use SerializesModels;
}

class ChildOfSerializingJob extends SerializingJob
{
    public function __construct(public Customer $customer) {}
}

/** The trait reached through a trait that uses it (trait-of-trait closure) — not flagged. */
trait TeamQueueable
{
    use BusQueueable;
    use SerializesModels;
}

class TeamJob implements ShouldQueue
{
    use TeamQueueable;

    public function __construct(public Customer $customer) {}
}

/** A queued class holding no model at all — nothing to serialize wrongly, not flagged. */
class PruneCache implements ShouldQueue
{
    use BusQueueable;

    public function __construct(public string $prefix, public int $keepDays) {}
}

/**
 * The report lands on the class, so a deliberate detached model is silenced by a class-level
 * suppression — a property-level one would not be seen.
 *
 * @psalm-suppress SerializedQueuedModel
 */
class KeepDetachedCustomer implements ShouldQueue
{
    use BusQueueable;

    public function __construct(public Customer $customer) {}
}
/** A model-typed property on a class that is not queued at all — not flagged. */
class CustomerPresenter
{
    public function __construct(public Customer $customer) {}
}

/** Static properties are skipped by SerializesModels::__serialize() itself — not flagged. */
class StaticHolderJob implements ShouldQueue
{
    use BusQueueable;

    public static ?Customer $lastCustomer = null;
}

/**
 * An abstract queued base may legitimately leave the trait to its children, so it is not
 * flagged; the concrete child above (ChildOfSerializingJob) is where the answer lives.
 */
abstract class AbstractCustomerJob implements ShouldQueue
{
    use BusQueueable;

    public function __construct(public Customer $customer) {}
}

/** An inherited property belongs to the parent that declares it — not re-reported here. */
class ConcreteCustomerJob extends AbstractCustomerJob {}
?>
--EXPECTF--
SerializedQueuedModel on line %d: App\QueuedJobs\SendCustomerReport implements ShouldQueue without Illuminate\Queue\SerializesModels, so $customer will be serialized whole into the queue payload
SerializedQueuedModel on line %d: App\QueuedJobs\ArchiveCustomer implements ShouldQueue without Illuminate\Queue\SerializesModels, so $customer will be serialized whole into the queue payload
SerializedQueuedModel on line %d: App\QueuedJobs\NotifyCustomers implements ShouldQueue without Illuminate\Queue\SerializesModels, so $customers will be serialized whole into the queue payload
SerializedQueuedModel on line %d: App\QueuedJobs\SettleInvoice implements ShouldQueue without Illuminate\Queue\SerializesModels, so $payer, $payee, $invoice will be serialized whole into the queue payload
SerializedQueuedModel on line %d: App\QueuedJobs\ReconcileLedger implements ShouldQueue without Illuminate\Queue\SerializesModels, so $customer, $opening, $closing and 1 more will be serialized whole into the queue payload
