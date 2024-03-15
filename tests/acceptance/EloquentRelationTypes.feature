Feature: Eloquent Relation types
  Illuminate\Database\Eloquent\Relations have type support

  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm errorLevel="1">
        <projectFiles>
          <directory name="."/>
          <ignoreFiles> <directory name="../../vendor"/> </ignoreFiles>
        </projectFiles>
        <plugins>
          <pluginClass class="Psalm\LaravelPlugin\Plugin"/>
        </plugins>
      </psalm>
      """
    And I have the following code preamble
      """
      <?php declare(strict_types=1);
      namespace Tests\Psalm\LaravelPlugin\Sandbox;

      use \Illuminate\Database\Eloquent\Builder;
      use \Illuminate\Database\Eloquent\Model;
      use \Illuminate\Database\Eloquent\Collection;
      use \Illuminate\Database\Eloquent\Relations\HasOne;
      use \Illuminate\Database\Eloquent\Relations\BelongsTo;
      use \Illuminate\Database\Eloquent\Relations\BelongsToMany;
      use \Illuminate\Database\Eloquent\Relations\HasMany;
      use \Illuminate\Database\Eloquent\Relations\HasManyThrough;
      use \Illuminate\Database\Eloquent\Relations\HasOneThrough;
      use \Illuminate\Database\Eloquent\Relations\MorphMany;
      use \Illuminate\Database\Eloquent\Relations\MorphTo;
      use \Illuminate\Database\Eloquent\Relations\MorphToMany;

      use Tests\Psalm\LaravelPlugin\Models\Comment;
      use Tests\Psalm\LaravelPlugin\Models\Image;
      use Tests\Psalm\LaravelPlugin\Models\Mechanic;
      use Tests\Psalm\LaravelPlugin\Models\Phone;
      use Tests\Psalm\LaravelPlugin\Models\Post;
      use Tests\Psalm\LaravelPlugin\Models\Role;
      use Tests\Psalm\LaravelPlugin\Models\Tag;
      use Tests\Psalm\LaravelPlugin\Models\User;
      use Tests\Psalm\LaravelPlugin\Models\Video;
      """

  Scenario: Models can declare one to one relationships
    Given I have the following code
    """
    final class Repository
    {
      /**
      * @psalm-return HasOne<Phone>
      */
      public function getPhoneRelationship(User $user): HasOne {
        return $user->phone();
      }

      /**
      * @psalm-return BelongsTo<User>
      */
      public function getUserRelationship(Phone $phone): BelongsTo {
        return $phone->user();
      }
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: Models can declare one to many relationships
    Given I have the following code
    """
    final class Repository
    {
      /**
      * @psalm-return BelongsTo<Post>
      */
      public function getPostRelationship(Comment $comment): BelongsTo {
        return $comment->post();
      }

      /**
      * @psalm-return HasMany<Comment>
      */
      public function getCommentsRelationship(Post $post): HasMany {
        return $post->comments();
      }
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: Models can declare many to many relationships
    Given I have the following code
    """
    final class Repository
    {
      /**
      * @psalm-return BelongsToMany<Role>
      */
      public function getRolesRelationship(User $user): BelongsToMany {
        return $user->roles();
      }

      /**
      * @psalm-return BelongsToMany<User>
      */
      public function getUserRelationship(Role $role): BelongsToMany {
        return $role->users();
      }
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: BelongsToMany relationship can return null when the first method is used
    Given I have the following code
    """
    function testFirstBelongsToManyCanNull(User $user): bool {
      return $user->roles()->first() === null;
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: Models can declare has through relationships
    Given I have the following code
    """
    final class Repository
    {
      /**
      * @psalm-return HasManyThrough<Mechanic>
      */
      public function getCarsAtMechanicRelationship(User $user): HasManyThrough {
        return $user->carsAtMechanic();
      }

      /**
      * @psalm-return HasOneThrough<User>
      */
      public function getCarsOwner(Mechanic $mechanic): HasOneThrough {
        return $mechanic->carOwner();
      }
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: Models can declare polymorphic relationships
    Given I have the following code
    """
    final class Repository
    {
      public function getPostsImageDynamicProperty(Post $post): Image {
        return $post->image;
      }

      /**
      * @todo: support for morphTo dynamic property
      * @psalm-return mixed
      */
      public function getImageableProperty(Image $image) {
        return $image->imageable;
      }

      /**
      * @todo: better support for morphTo relationships
      * @psalm-return MorphTo
      */
      public function getImageableRelationship(Image $image): MorphTo {
        return $image->imageable();
      }
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: Models can declare one to many polymorphic relationships
    Given I have the following code
    """
    final class Repository
    {
      /**
      * @psalm-return MorphMany<Comment>
      */
      public function getCommentsRelation(Video $video): MorphMany {
        return $video->comments();
      }

      /**
      * @psalm-return Collection<Comment>
      */
      public function getComments(Video $video): Collection {
        return $video->comments;
      }
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: Models can declare many to many polymorphic relationships
    Given I have the following code
    """
    final class Repository
    {
      /**
      * @psalm-return MorphToMany<Tag>
      */
      public function getTagsRelation(Post $post): MorphToMany {
        return $post->tags();
      }

      /**
      * @psalm-return Collection<Tag>
      */
      public function getTags(Post $post): Collection {
        return $post->tags;
      }
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: Polymorphic models can retrieve their inverse relation
    Given I have the following code
    """
    final class Repository
    {
      /**
      * todo: this should be a union of possible types...
      * @psalm-return mixed
      */
      public function getCommentable(Comment $comment) {
        return $comment->commentable;
      }
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: Models can declare inverse of many to many polymorphic relationships
    Given I have the following code
    """
    final class Repository
    {
      /**
      * @psalm-return MorphToMany<Post>
      */
      public function getPostsRelation(Tag $tag): MorphToMany {
        return $tag->posts();
      }

      /**
      * @psalm-return MorphToMany<Video>
      */
      public function getVideosRelation(Tag $tag): MorphToMany {
        return $tag->videos();
      }

      /**
      * @psalm-return Collection<Post>
      */
      public function getPosts(Tag $tag): Collection {
        return $tag->posts;
      }

      /**
      * @psalm-return Collection<Video>
      */
      public function getVideos(Tag $tag): Collection {
        return $tag->videos;
      }
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: Relationships can be accessed via a property
    Given I have the following code
    """
    function testGetPhone(User $user): Phone {
      return $user->phone;
    }

    function testGetUser(Phone $phone): User {
      return $phone->user;
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: Relationships can be filtered via dynamic property
    Given I have the following code
    """
    function testFilterRelationshipFromDynamicProperty(User $user): Phone {
      return $user->phone->where('active', 1)->firstOrFail();
    }
    """
    When I run Psalm
    Then I see no errors

  @skip
  Scenario: Relationships can be further constrained via method
    Given I have the following code
    """
    function testFilterRelationshipFromMethod(User $user): Phone {
      return $user->phone()->where('active', 1)->firstOrFail();
    }
    """
    When I run Psalm
    Then I see no errors

  @skip
  Scenario: Relationships return themselves when the underlying method returns a builder
    Given I have the following code
    """
    /**
    * @param HasOne<Phone> $relationship
    * @psalm-return HasOne<Phone>
    */
    function testRelationshipsReturnThemselvesInsteadOfBuilders(HasOne $relationship): HasOne {
      return $relationship->where('active', 1);
    }

    /**
    * @psalm-return BelongsTo<User>
    */
    function testAnother(Phone $phone): BelongsTo {
      return $phone->user()->where('active', 1);
    }
    """
    When I run Psalm
    Then I see no errors

  @skip
  Scenario: Relationships return themselves when the proxied method is a query builder method
    Given I have the following code
    """
    /**
    * @param HasOne<Phone> $relationship
    * @psalm-return HasOne<Phone>
    */
    function test(HasOne $relationship): HasOne {
      return $relationship->orderBy('id', 'ASC');
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: First() call on HasMany returns nullable type
    Given I have the following code
    """
    function test(User $user): ?Role {
      return $user->roles()->first();
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: cannot call firstOrNew and firstOrCreate without parameters in Laravel 6.x
    Given I have the "laravel/framework" package satisfying the "6.*"
    And I have the following code
    """
    function test_hasOne_firstOrCreate(User $user): Phone {
      return $user->phone()->firstOrCreate();
    }

    function test_hasOne_firstOrNew(User $user): Phone {
      return $user->phone()->firstOrNew();
    }

    function test_hasMany_firstOrCreate(Post $post): Comment {
      return $post->comments()->firstOrCreate();
    }

    function test_hasMany_firstOrNew(Post $post): Comment {
      return $post->comments()->firstOrNew();
    }
    """
    When I run Psalm
    Then I see these errors
      | Type  | Message |
      | TooFewArguments | Too few arguments for method Illuminate\Database\Eloquent\Relations\HasOneOrMany::firstorcreate saw 0 |
      | TooFewArguments | Too few arguments for method Illuminate\Database\Eloquent\Relations\HasOneOrMany::firstornew saw 0    |
      | TooFewArguments | Too few arguments for method Illuminate\Database\Eloquent\Relations\HasOneOrMany::firstorcreate saw 0 |
      | TooFewArguments | Too few arguments for method Illuminate\Database\Eloquent\Relations\HasOneOrMany::firstornew saw 0    |


  Scenario: can call firstOrNew and firstOrCreate without parameters in Laravel 8.x
    Given I have the "laravel/framework" package satisfying the ">= 8.0"
    And I have the following code
    """
    function test_hasOne_firstOrCreate(User $user): Phone {
      return $user->phone()->firstOrCreate();
    }

    function test_hasOne_firstOrNew(User $user): Phone {
      return $user->phone()->firstOrNew();
    }

    function test_hasMany_firstOrCreate(Post $post): Comment {
      return $post->comments()->firstOrCreate();
    }

    function test_hasMany_firstOrNew(Post $post): Comment {
      return $post->comments()->firstOrNew();
    }
    """
    When I run Psalm
    Then I see no errors

