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

  Scenario: Model calls return generics of correct types
    Given I have the following code
    """
    <?php declare(strict_types=1);

    namespace App\Models;

    use App\Models\User;

    final class UserRepository
    {
        /** @return \Illuminate\Database\Eloquent\Collection<int, User> */
        public function getAll(): \Illuminate\Database\Eloquent\Collection
        {
          return User::all();
        }

        public function getFirst(): ?User
        {
          return $this->getAll()->first();
        }

        /** @return \Illuminate\Database\Eloquent\Builder<User> */
        public function getBuilder(array $attributes): \Illuminate\Database\Eloquent\Builder
        {
          return User::where($attributes);
        }

        /** @return \Illuminate\Database\Eloquent\Collection<int, User> */
        public function getWhere(array $attributes): \Illuminate\Database\Eloquent\Collection
        {
          return User::where($attributes)->get();
        }

        /** @return \Illuminate\Database\Eloquent\Collection<int, User> */
        public function getWhereUsingLessMagic(array $attributes): \Illuminate\Database\Eloquent\Collection
        {
          return User::query()->where($attributes)->get();
        }
    }
    """
    When I run Psalm
    Then I see no errors
