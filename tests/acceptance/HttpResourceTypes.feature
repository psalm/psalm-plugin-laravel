Feature: Http Resource types
  Illuminate\Http\Resources have type support

  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm totallyTyped="true">
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

      use Illuminate\Http\Resources\Json\JsonResource;
      use Tests\Psalm\LaravelPlugin\Models\User;


      /**
       * @property-read User $resource
       */
      class UserResource extends JsonResource
      {
          /**
           * Transform the resource into an array.
           *
           * @param  \Illuminate\Http\Request  $request
           * @return array
           */
          public function toArray($request)
          {
              return [
                  'id' => $this->resource->id,
              ];
          }
      }
      """

  Scenario: Resources can declare wrap
    Given I have the following code
    """

    class UserController
    {
        public function __construct()
        {
            UserResource::$wrap = 'items';
        }

        public function show(User $user): UserResource
        {
            return new UserResource($user);
        }
    }
    """
    When I run Psalm
    Then I see no errors
