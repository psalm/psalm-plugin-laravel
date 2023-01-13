Feature: Collection types
  Illuminate\Support\Collection has type support

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
    And I have the following code preamble
      """
      <?php declare(strict_types=1);

      use Illuminate\Support\Collection;
      """

  Scenario: Collection has TKey, TValue
    Given I have the following code
    """
    final class CollectionTypes
    {
        /**
         * @return Collection<int, string>
         */
        public function getCollection(): Collection
        {
            return new Collection(['hi']);
        }

        public function popTest(): ?string
        {
            return $this->getCollection()->pop();
        }

        public function firstTest(): ?string
        {
            return $this->getCollection()->first(function (string $item) {
              return strlen($item) > 3;
            });
        }

        /**
        * @return Collection<string, int>
        */
        public function testFlip(): Collection
        {
          return $this->getCollection()->flip();
        }

        public function lastTest(): ?string
        {
            return $this->getCollection()->last(function (string $item) {
              return strlen($item) > 3;
            });
        }

        public function getTest(): ?string
        {
            return $this->getCollection()->get(function (string $item) {
              return strlen($item) > 3;
            });
        }

        public function pullTest(): ?string
        {
            return $this->getCollection()->pull(function (string $item) {
              return strlen($item) > 3;
            });
        }

        /**
        * @return int|false
        */
        public function searchTest()
        {
            return $this->getCollection()->search(function (string $item) {
              return strlen($item) > 3;
            });
        }

        public function shiftTest(): ?string
        {
            return $this->getCollection()->shift();
        }

        /**
        * @return array<int, string>
        */
        public function allTest(): array
        {
          return $this->getCollection()->all();
        }

        /**
        * @return Collection<int, string>
        */
        public function putTest(): Collection
        {
          return $this->getCollection()->put(5, 'five');
        }
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: Dict like Collection can iterate with TKey, TValue
    Given I have the following code
    """
    /** @var Collection<string, string> */
    $collection = new Collection(["key" => "value"]);

    foreach ($collection as $key => $value) {
        /** @psalm-suppress UnusedFunctionCall we need type-check only */
        substr($key, 0);

        /** @psalm-suppress UnusedFunctionCall we need type-check only */
        substr($value, 0);
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: Array like Collection can iterate with TKey, TValue
    Given I have the following code
    """
    /** @var Collection<int, string> */
    $collection = new Collection(["data"]);

    foreach ($collection as $key => $value) {
        /** @psalm-suppress UnusedFunctionCall we need type-check only */
        substr($value, $key);
    }
    """
    When I run Psalm
    Then I see no errors
