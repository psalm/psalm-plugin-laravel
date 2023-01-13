Feature: Eloquent Collection types
  Illuminate\Database\Eloquent\Collection has type support

  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm errorLevel="1" findUnusedCode="false">
        <projectFiles>
          <directory name="."/>
          <ignoreFiles> <directory name="../../vendor"/> </ignoreFiles>
        </projectFiles>
        <plugins>
          <pluginClass class="Psalm\LaravelPlugin\Plugin"/>
        </plugins>
      </psalm>
      """

  Scenario: Unknown Scenario
    Given I have the following code
    """
    <?php declare(strict_types=1);

    namespace Tests\Psalm\LaravelPlugin\Models;

    use Tests\Psalm\LaravelPlugin\Models\User;

    final class UserRepository
    {
        /**
        * @psalm-return \Illuminate\Database\Eloquent\Collection<User>
        */
        public function getAll(): \Illuminate\Database\Eloquent\Collection
        {
          return User::all();
        }

        public function getFirst(): ?User
        {
          return $this->getAll()->first();
        }

        /**
        * @return \Illuminate\Database\Eloquent\Builder<User>
        */
        public function getBuilder(array $attributes): \Illuminate\Database\Eloquent\Builder
        {
          return User::where($attributes);
        }

        /**
        * @psalm-return \Illuminate\Database\Eloquent\Collection<User>
        */
        public function getWhere(array $attributes): \Illuminate\Database\Eloquent\Collection
        {
          return User::where($attributes)->get();
        }
    }
    """
    When I run Psalm
    Then I see no errors
