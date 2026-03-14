<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelRegistrationHandler;

#[CoversClass(ModelRegistrationHandler::class)]
final class ModelRegistrationHandlerTest extends TestCase
{
    private string $sourceCodeNoComments = '';

    protected function setUp(): void
    {
        $reflection = new \ReflectionClass(ModelRegistrationHandler::class);
        $fileName = $reflection->getFileName();
        self::assertIsString($fileName);

        $fileContents = \file_get_contents($fileName);
        self::assertIsString($fileContents);

        // Strip comments so assertions aren't tripped by explanatory prose
        $tokens = \token_get_all($fileContents);
        $codeOnly = '';
        foreach ($tokens as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            $codeOnly .= \is_array($token) ? $token[1] : $token;
        }
        $this->sourceCodeNoComments = $codeOnly;
    }

    // -----------------------------------------------------------------------
    // Discovery filtering
    // -----------------------------------------------------------------------

    #[Test]
    public function it_filters_out_abstract_classes_before_registration(): void
    {
        // Abstract models (e.g. a shared base model) must be skipped so we
        // do not register handlers for classes that cannot be instantiated.
        self::assertStringContainsString(
            '$storage->abstract',
            $this->sourceCodeNoComments,
            'ModelRegistrationHandler must skip abstract classes by checking $storage->abstract.',
        );
    }

    #[Test]
    public function it_filters_by_model_parent_class_ancestry(): void
    {
        // Only classes that inherit from Illuminate\Database\Eloquent\Model
        // (directly or transitively) should receive handler registration.
        self::assertStringContainsString(
            'parent_classes',
            $this->sourceCodeNoComments,
            'ModelRegistrationHandler must filter classes by checking parent_classes for Model ancestry.',
        );
    }

    // -----------------------------------------------------------------------
    // Autoload failure handling
    // -----------------------------------------------------------------------

    #[Test]
    public function it_verifies_class_can_be_autoloaded_before_registration(): void
    {
        // class_exists() with $autoload=true must be called so Composer's
        // autoloader is invoked before registering property handlers that
        // rely on runtime reflection (getTable(), getCasts(), etc.).
        self::assertStringContainsString(
            'class_exists(',
            $this->sourceCodeNoComments,
            'ModelRegistrationHandler must call class_exists() to verify the class can be autoloaded.',
        );
    }

    #[Test]
    public function it_catches_error_thrown_during_autoloading(): void
    {
        // PHP file-inclusion failures (ParseError, CompileError) throw \Error.
        // Catching it prevents a single broken model file from aborting the
        // entire plugin run for all other models.
        self::assertStringContainsString(
            'catch (\Error',
            $this->sourceCodeNoComments,
            'ModelRegistrationHandler must catch \Error thrown during class_exists() to handle autoload failures gracefully.',
        );
    }

    // -----------------------------------------------------------------------
    // Registration order
    // -----------------------------------------------------------------------

    #[Test]
    public function it_registers_relationship_handlers_before_accessor_handlers(): void
    {
        // First non-null result wins for property resolution.  Relationship
        // properties (e.g. $user->posts) must take priority over attribute
        // accessors so that typed relation return types are preserved.
        $relationshipPos = \strpos($this->sourceCodeNoComments, 'ModelRelationshipPropertyHandler');
        $accessorPos = \strpos($this->sourceCodeNoComments, 'ModelPropertyAccessorHandler');

        self::assertNotFalse($relationshipPos, 'ModelRelationshipPropertyHandler must be registered.');
        self::assertNotFalse($accessorPos, 'ModelPropertyAccessorHandler must be registered.');

        self::assertLessThan(
            $accessorPos,
            $relationshipPos,
            'ModelRelationshipPropertyHandler must be registered before ModelPropertyAccessorHandler — '
            . 'first non-null result wins for property resolution.',
        );
    }

    #[Test]
    public function it_registers_accessor_handlers_before_column_handlers(): void
    {
        // Attribute accessors (e.g. getFullNameAttribute) represent explicit
        // developer intent and must take precedence over migration-derived
        // column properties for the same property name.
        $accessorPos = \strpos($this->sourceCodeNoComments, 'ModelPropertyAccessorHandler');
        $columnPos = \strpos($this->sourceCodeNoComments, 'ModelPropertyHandler');

        self::assertNotFalse($accessorPos, 'ModelPropertyAccessorHandler must be registered.');
        self::assertNotFalse($columnPos, 'ModelPropertyHandler must be registered.');

        self::assertLessThan(
            $columnPos,
            $accessorPos,
            'ModelPropertyAccessorHandler must be registered before ModelPropertyHandler — '
            . 'accessor properties should take precedence over migration-derived columns.',
        );
    }

    #[Test]
    public function it_guards_column_handler_registration_behind_column_fallback_flag(): void
    {
        // Column fallback is an opt-in feature (<modelProperties columnFallback="migrations"/>).
        // ModelPropertyHandler registration must only occur when the flag is enabled to avoid
        // unnecessary property resolution overhead for projects that do not use it.
        self::assertStringContainsString(
            'columnFallbackEnabled',
            $this->sourceCodeNoComments,
            'ModelPropertyHandler registration must be guarded by the $columnFallbackEnabled flag.',
        );
    }
}
