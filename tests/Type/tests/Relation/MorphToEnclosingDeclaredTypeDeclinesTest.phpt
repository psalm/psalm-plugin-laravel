--FILE--
<?php declare(strict_types=1);

// Dedicated sub-namespace: psalm-tester batches every .phpt into one analysis.
namespace Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1091
 *
 * Shapes where the enclosing-method narrowing of `$this->morphTo()` declines and the stub's
 * `MorphTo<Model, static>` stays. Every case is declined by at least one gate; some (the
 * native-only and void methods) by more than one.
 */
interface HasTitle
{
}

class Post extends Model
{
}

class Video extends Model
{
}

/**
 * @template TRelatedModel of Model
 * @template-covariant TDeclaringModel of Model
 * @template-extends MorphTo<TRelatedModel, TDeclaringModel>
 */
class CustomMorphTo extends MorphTo
{
}

function onlyPost(Post $_post): void
{
}

class Comment extends Model
{
    // Only morphTo() is answered: other HasRelationships factories keep their stub type.
    /** @return MorphTo<Post|Video, self> */
    public function otherFactory(): MorphTo
    {
        $_b = $this->belongsTo(Post::class);
        /** @psalm-check-type-exact $_b = BelongsTo<Post, Comment&static> */
        return $this->morphTo();
    }

    /** @return MorphTo<Post, self> */
    public function belongsToReturned(): MorphTo
    {
        return $this->belongsTo(Post::class);
    }

    // Only the `$this->morphTo()` call a return statement of the method itself yields (directly or
    // as the root of a relation-keeping chain) narrows. Any other morphTo() keeps the stub type, so
    // the coercion below stays reported: at runtime 'origin' may resolve to any morphable model.
    /** @return MorphTo<Post, self> */
    public function notReturned(): MorphTo
    {
        onlyPost($this->morphTo('origin')->getRelated());

        return $this->postRelation();
    }

    /** @return MorphTo<Post, self> */
    public function postRelation(): MorphTo
    {
        return $this->morphTo('post');
    }

    /** @return MorphTo<Post, self> */
    public function besideReturned(): MorphTo
    {
        onlyPost($this->morphTo('origin')->getRelated());

        return $this->morphTo('post');
    }

    /** @return MorphTo<Post|Video, self> */
    public function viaVariable(): MorphTo
    {
        $r = $this->morphTo();

        return $r;
    }

    // clone() returns the underlying query builder, not the relation, so the chain does not keep it.
    /** @return MorphTo<Post|Video, self> */
    public function viaClone(): MorphTo
    {
        return $this->morphTo()->clone();
    }

    // A closure or arrow function is its own function-like: the method's declaration does not apply.
    /** @return MorphTo<Post|Video, self> */
    public function inClosure(): MorphTo
    {
        $make = /** @return MorphTo<Post|Video, Comment> */ function (): MorphTo {
            return $this->morphTo();
        };

        return $make();
    }

    /** @return MorphTo<Post|Video, self> */
    public function inArrowFunction(): MorphTo
    {
        $make = /** @return MorphTo<Post|Video, Comment> */ fn (): MorphTo => $this->morphTo();

        return $make();
    }

    // Other receivers and `parent::morphTo()` decline. Psalm forwards `self::` / `static::morphTo()`
    // as a virtual `$this->morphTo()`, which is not the node the return statement holds, so they decline too.
    /** @return MorphTo<Post|Video, self> */
    public function onOtherInstance(self $other): MorphTo
    {
        return $other->morphTo();
    }

    /** @return MorphTo<Post|Video, self> */
    public function onNewInstance(): MorphTo
    {
        return (new self())->morphTo();
    }

    /** @return MorphTo<Post|Video, self> */
    public static function fromStaticMethod(self $comment): MorphTo
    {
        return $comment->morphTo();
    }

    /** @return MorphTo<Post|Video, self> */
    public function viaParent(): MorphTo
    {
        return parent::morphTo();
    }

    /** @return MorphTo<Post|Video, self> */
    public function viaSelf(): MorphTo
    {
        return self::morphTo();
    }

    /** @return MorphTo<Post|Video, self> */
    public function viaStatic(): MorphTo
    {
        return static::morphTo();
    }

    // The declaration must be exactly `MorphTo<X, …>`: not a parent, a subclass, nullable, native-only or void.
    /** @return BelongsTo<Post, self> */
    public function declaredBelongsTo(): BelongsTo
    {
        return $this->morphTo();
    }

    /** @return Relation<Post|Video, self, Post|Video|null> */
    public function declaredRelation(): Relation
    {
        return $this->morphTo();
    }

    /** @return CustomMorphTo<Post|Video, self> */
    public function declaredSubclass(): CustomMorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Post|Video, self>|null */
    public function declaredNullable(): ?MorphTo
    {
        return $this->morphTo();
    }

    public function nativeOnly(): MorphTo
    {
        $_r = $this->morphTo();
        /** @psalm-check-type-exact $_r = MorphTo<Model, Comment&static> */
        return $_r;
    }

    public function voidMethod(): void
    {
        $_r = $this->morphTo();
        /** @psalm-check-type-exact $_r = MorphTo<Model, Comment&static> */
    }

    // Every alternative of X must be a named class (or an intersection of named classes) that is, or
    // includes, a Model subclass, with no `static` and no template part.
    /** @return MorphTo<static, self> */
    public function staticRelated(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Comment&static, self> */
    public function staticIntersectionRelated(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @template T of Post
     * @param class-string<T> $_class
     * @return MorphTo<T, self>
     */
    public function templated(string $_class): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @template T of HasTitle
     * @param class-string<T> $_class
     * @return MorphTo<Model&T, self>
     */
    public function templateIntersection(string $_class): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<HasTitle, self> */
    public function nonModelRelated(): MorphTo
    {
        return $this->morphTo();
    }
}
?>
--EXPECTF--
MoreSpecificReturnType on line %d: The declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::belongsToReturned is more specific than the inferred return type 'Illuminate\Database\Eloquent\Relations\BelongsTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>'
LessSpecificReturnStatement on line %d: The type 'Illuminate\Database\Eloquent\Relations\BelongsTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>' is more general than the declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::belongsToReturned
ArgumentTypeCoercion on line %d: Argument 1 of Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\onlyPost expects Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post, but parent type Illuminate\Database\Eloquent\Model provided
ArgumentTypeCoercion on line %d: Argument 1 of Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\onlyPost expects Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post, but parent type Illuminate\Database\Eloquent\Model provided
MoreSpecificReturnType on line %d: The declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post|Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Video, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::viaVariable is more specific than the inferred return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>'
LessSpecificReturnStatement on line %d: The type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>' is more general than the declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post|Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Video, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::viaVariable
MoreSpecificReturnType on line %d: The declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post|Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Video, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::viaClone is more specific than the inferred return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>'
LessSpecificReturnStatement on line %d: The type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>' is more general than the declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post|Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Video, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::viaClone
MoreSpecificReturnType on line %d: The declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post|Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Video, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for %s:-:closure is more specific than the inferred return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>'
LessSpecificReturnStatement on line %d: The type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>' is more general than the declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post|Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Video, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for %s:-:closure
MoreSpecificReturnType on line %d: The declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post|Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Video, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for %s:-:closure is more specific than the inferred return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>'
LessSpecificReturnStatement on line %d: The type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>' is more general than the declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post|Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Video, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for %s:-:closure
MoreSpecificReturnType on line %d: The declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post|Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Video, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::onOtherInstance is more specific than the inferred return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>'
LessSpecificReturnStatement on line %d: The type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>' is more general than the declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post|Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Video, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::onOtherInstance
MoreSpecificReturnType on line %d: The declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post|Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Video, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::onNewInstance is more specific than the inferred return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>'
LessSpecificReturnStatement on line %d: The type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>' is more general than the declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post|Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Video, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::onNewInstance
MoreSpecificReturnType on line %d: The declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post|Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Video, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::fromStaticMethod is more specific than the inferred return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>'
LessSpecificReturnStatement on line %d: The type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>' is more general than the declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post|Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Video, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::fromStaticMethod
MoreSpecificReturnType on line %d: The declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post|Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Video, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::viaParent is more specific than the inferred return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>'
LessSpecificReturnStatement on line %d: The type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>' is more general than the declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post|Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Video, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::viaParent
MoreSpecificReturnType on line %d: The declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post|Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Video, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::viaSelf is more specific than the inferred return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>'
LessSpecificReturnStatement on line %d: The type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>' is more general than the declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post|Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Video, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::viaSelf
MoreSpecificReturnType on line %d: The declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post|Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Video, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::viaStatic is more specific than the inferred return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>'
LessSpecificReturnStatement on line %d: The type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>' is more general than the declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post|Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Video, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::viaStatic
MoreSpecificReturnType on line %d: The declared return type 'Illuminate\Database\Eloquent\Relations\BelongsTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::declaredBelongsTo is more specific than the inferred return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>'
LessSpecificReturnStatement on line %d: The type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>' is more general than the declared return type 'Illuminate\Database\Eloquent\Relations\BelongsTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::declaredBelongsTo
MoreSpecificReturnType on line %d: The declared return type 'Illuminate\Database\Eloquent\Relations\Relation<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post|Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Video, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post|Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Video|null>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::declaredRelation is more specific than the inferred return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>'
LessSpecificReturnStatement on line %d: The type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>' is more general than the declared return type 'Illuminate\Database\Eloquent\Relations\Relation<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post|Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Video, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post|Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Video|null>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::declaredRelation
MoreSpecificReturnType on line %d: The declared return type 'Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\CustomMorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post|Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Video, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::declaredSubclass is more specific than the inferred return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>'
LessSpecificReturnStatement on line %d: The type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>' is more general than the declared return type 'Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\CustomMorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post|Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Video, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::declaredSubclass
MoreSpecificReturnType on line %d: The declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post|Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Video, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>|null' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::declaredNullable is more specific than the inferred return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>'
LessSpecificReturnStatement on line %d: The type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>' is more general than the declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post|Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Video, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>|null' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::declaredNullable
MoreSpecificReturnType on line %d: The declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::staticRelated is more specific than the inferred return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>'
LessSpecificReturnStatement on line %d: The type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>' is more general than the declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::staticRelated
MoreSpecificReturnType on line %d: The declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::staticIntersectionRelated is more specific than the inferred return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>'
LessSpecificReturnStatement on line %d: The type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>' is more general than the declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::staticIntersectionRelated
MoreSpecificReturnType on line %d: The declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<T:fn-tests\psalm\laravelplugin\sandbox\morphtoenclosingdeclines\comment::templated as Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::templated is more specific than the inferred return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>'
LessSpecificReturnStatement on line %d: The type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>' is more general than the declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<T:fn-tests\psalm\laravelplugin\sandbox\morphtoenclosingdeclines\comment::templated as Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Post, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::templated
InvalidReturnType on line %d: The declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model&(T:fn-tests\psalm\laravelplugin\sandbox\morphtoenclosingdeclines\comment::templateintersection as Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\HasTitle), Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::templateIntersection is incorrect, got 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>'
InvalidReturnStatement on line %d: The inferred type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>' does not match the declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model&(T:fn-tests\psalm\laravelplugin\sandbox\morphtoenclosingdeclines\comment::templateintersection as Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\HasTitle), Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::templateIntersection
InvalidReturnType on line %d: The declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\HasTitle, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::nonModelRelated is incorrect, got 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>'
InvalidTemplateParam on line %d: Extended template param TRelatedModel of Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\HasTitle, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment> expects type Illuminate\Database\Eloquent\Model, type Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\HasTitle given
InvalidReturnStatement on line %d: The inferred type 'Illuminate\Database\Eloquent\Relations\MorphTo<Illuminate\Database\Eloquent\Model, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment&static>' does not match the declared return type 'Illuminate\Database\Eloquent\Relations\MorphTo<Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\HasTitle, Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment>' for Tests\Psalm\LaravelPlugin\Sandbox\MorphToEnclosingDeclines\Comment::nonModelRelated
