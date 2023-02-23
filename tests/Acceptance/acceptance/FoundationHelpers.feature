Feature: Foundation helpers
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

      use App\Models\User;
      """

  Scenario: abort_if() support
    Given I have the following code
    """
    function abortIfNullable(?string $nullable): string {
      abort_if(is_null($nullable), 422);

      return $nullable;
    }

    function test_abort_asserts_not_null(?string $nullable): string {
      if ($nullable === null) {
          abort(422);
      }

      return $nullable;
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: app() support: env can be pulled off the app
    Given I have the following code
    """
      if (app()->environment('production')) {
        // do something
      }
    """
    When I run Psalm
    Then I see no errors

  Scenario: auth() support
    Given I have the following code
    """
    function test_auth_call_without_args_should_return_Factory(): \Illuminate\Contracts\Auth\Factory
    {
        return auth();
    }

    function test_auth_call_with_null_as_arg_should_return_Factory(): \Illuminate\Contracts\Auth\Factory
    {
        return auth(null);
    }

    function test_auth_call_with_string_arg_should_return_Guard(): \Illuminate\Contracts\Auth\Guard|\Illuminate\Contracts\Auth\StatefulGuard
    {
      return auth('user');
    }

    function test_auth_check_call(): bool
    {
      return auth()->check();
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: cookie() support
    Given I have the following code
    """
    function two_args_to_set_cookie(): \Symfony\Component\HttpFoundation\Cookie
    {
        return cookie('some.key', 42);
    }

    function single_arg_to_get_cookie(): \Symfony\Component\HttpFoundation\Cookie
    {
        return cookie('some.key');
    }

    function no_args(): \Illuminate\Cookie\CookieJar
    {
      return cookie();
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: config() support
    Given I have the following code
    """
    function config_with_one_argument(): mixed
    {
        return config('app.name');
    }

    function config_with_first_null_argument_and_second_argument_provided(): mixed
    {
        return config('app.non-existent', false);
    }

    function config_setting_at_runtime(): null
    {
        return config(['app.non-existent' => false]);
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: logger() support
    Given I have the following code
    """
    function args(): null
    {
        return logger('this should return void');
    }

    function no_args(): \Illuminate\Log\LogManager
    {
      return logger();
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: logs() support
    Given I have the following code
    """
    function test_logs_call_without_args(): \Illuminate\Log\LogManager
    {
        return logs();
    }

    function test_logs_call_with_arg(): \Psr\Log\LoggerInterface
    {
        return logs('driver-name');
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: redirect() support
    Given I have the following code
    """
    function test_redirect_call_without_args_should_return_Redirector(): \Illuminate\Routing\Redirector {
      return redirect();
    }

    function test_redirect_call_with_single_arg_should_return_RedirectResponse(): Illuminate\Http\RedirectResponse {
      return redirect('foo');
    }

    function test_redirect_call_with_two_args_should_return_RedirectResponse(): Illuminate\Http\RedirectResponse {
      return redirect('foo', 301);
    }

    function test_redirect_call_with_three_args_should_return_RedirectResponse(): Illuminate\Http\RedirectResponse {
      return redirect('foo', 301, ['Accept' => 'text/html']);
    }

    function test_redirect_call_with_four_args_should_return_RedirectResponse(): Illuminate\Http\RedirectResponse {
      return redirect('foo', 301, ['Accept' => 'text/html'], true);
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: rescue() support
    Given I have the following code
    """
    function rescue_call_without_default(): ?int
    {
        return rescue(fn (): int => 0);
    }

    function rescue_call_with_default_scalar(): int
    {
        return rescue(fn (): int => 0, 42);
    }

    function rescue_call_with_default_callable(): int
    {
        return rescue(fn () => throw new \Exception(), fn () => 42);
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: response() support
    Given I have the following code
    """
    function response_without_args(): \Illuminate\Contracts\Routing\ResponseFactory
    {
        return response();
    }

    function response_with_single_empty_string_arg(): \Illuminate\Http\Response
    {
        return response('');
    }

    function response_with_single_view_arg(): \Illuminate\Http\Response
    {
        return response(view('home'));
    }

    // empty content response
    function response_with_single_null_arg(): \Illuminate\Http\Response
    {
        return response(null);
    }

    function response_with_single_array_arg(): \Illuminate\Http\Response
    {
        $jsonData = ['a' => 42];
        return response($jsonData);
    }

    function response_with_first_view_arg(): \Illuminate\Http\Response
    {
        return response(view('home'));
    }

    function response_with_two_args(): \Illuminate\Http\Response
    {
        return response('content', 200);
    }

    function response_with_three_args(): \Illuminate\Http\Response
    {
        return response('content', 200, ['Accept' => 'text/html']);
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: response() support
    Given I have the following code
    """
    function response_called_with_no_arguments_returns_an_instance_of_ResponseFactory(): \Illuminate\Contracts\Routing\ResponseFactory {
      return response();
    }

    function response_called_with_arguments_returns_an_instance_of_response(): \Illuminate\Http\Response {
      return response('ok');
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: session() support
    Given I have the following code
    """
    function scalar_arg_to_get_value_from_session(): mixed
    {
        return session('some-key');
    }

    function array_arg_to_set_session(): null
    {
        return session(['some-key' => 42]);
    }

    function no_args(): \Illuminate\Session\SessionManager
    {
      return session();
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: url() support
    Given I have the following code
    """
    function test_url_without_args_should_return_UrlGenerator(): \Illuminate\Contracts\Routing\UrlGenerator {
      return url();
    }

    function test_url_with_single_arg_should_return_string(): string {
      return url('example.com');
    }

    function test_url_with_two_args_should_return_string(): string {
      return url('example.com', ['a' => 42]);
    }

    function test_url_with_three_args_should_return_string(): string {
      return url('example.com', ['a' => 42], true);
    }
    """
    When I run Psalm
    Then I see no errors

  Scenario: view() support
    Given I have the following code
    """
    function view_without_args(): \Illuminate\Contracts\View\Factory
    {
        return view();
    }

    function view_with_one_arg(): \Illuminate\Contracts\View\View
    {
        return view('home');
    }

    function view_with_two_args(): \Illuminate\Contracts\View\View
    {
        return view('home', []);
    }

    function view_with_three_args(): \Illuminate\Contracts\View\View
    {
        return view('home', [], []);
    }

    function view_make_with_two_args(): \Illuminate\Contracts\View\View
    {
        return view()->make('home', []);
    }

    function view_make_with_three_args(): \Illuminate\Contracts\View\View
    {
        return view()->make('home', [], []);
    }
    """
    When I run Psalm
    Then I see no errors
