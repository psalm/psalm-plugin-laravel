Feature: Http FormRequest types
  Illuminate\Http\FormRequest have type support

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

  Scenario: "artisan make:request ExampleRequest"
    Given I have the following code
      """
      <?php declare(strict_types=1);
      namespace App\Http\Requests;

      use Illuminate\Foundation\Http\FormRequest;

      class ExampleRequest extends FormRequest
      {
          /**
           * Determine if the user is authorized to make this request.
           *
           * @return bool
           */
          public function authorize()
          {
              return false;
          }

          /**
           * Get the validation rules that apply to the request.
           *
           * @return array
           */
          public function rules()
          {
              return [
                  //
              ];
          }
      }
      """
    When I run Psalm
    Then I see no errors
