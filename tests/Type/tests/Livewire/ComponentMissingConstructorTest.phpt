--FILE--
<?php declare(strict_types=1);

/**
 * Livewire components are never constructed with arguments: typed properties are initialized in
 * mount() or restored from the snapshot, so MissingConstructor is a false positive for them.
 * livewire/livewire is not a dev dependency, so the parent classes are declared inline.
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/2161
 */

namespace Livewire {
    abstract class Component {}
}

namespace Livewire\Volt {
    abstract class Component extends \Livewire\Component {}
}

namespace App\Livewire {
    final class Show extends \Livewire\Component
    {
        public string $questionId;

        public function mount(string $questionId): void
        {
            $this->questionId = $questionId;
        }
    }

    final class VoltShow extends \Livewire\Volt\Component
    {
        public string $questionId;

        public function mount(string $questionId): void
        {
            $this->questionId = $questionId;
        }
    }

    /** The suppression is scoped to Livewire components: other classes keep the diagnostic. */
    final class NotAComponent
    {
        public string $questionId;
    }
}
?>
--EXPECTF--
MissingConstructor on line %d: App\Livewire\NotAComponent has an uninitialized property App\Livewire\NotAComponent::$questionId, but no constructor
